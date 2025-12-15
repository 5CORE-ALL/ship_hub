<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;
use Carbon\Carbon;

class BusinessFiveCoreShopifySyncOrders extends Command
{
    protected $signature = 'fivecore:shopify-sync-orders-business';
    protected $description = 'Sync orders from Shopify API (FiveCore Business)';

    public function handle()
    {
        $shopUrl  = config('services.shopify_5core.domain');
        $apiKey   = config('services.shopify_5core.api_key');
        $password = config('services.shopify_5core.password');
        $version  = "2025-07";
        $since = now()->subDays(30)->utc()->toIso8601String();

        // $url = "https://{$apiKey}:{$password}@{$shopUrl}/admin/api/{$version}/orders.json?status=any&financial_status=any&created_at_min={$since}&limit=250";
         $since = now()->subDays(30)->toIso8601String();
         $url = "https://{$apiKey}:{$password}@{$shopUrl}/admin/api/{$version}/orders.json?status=any&fulfillment_status=unfulfilled&created_at_min={$since}&limit=250";

        $response = Http::get($url);

        if ($response->failed()) {
            $this->error("❌ Shopify API Error: " . $response->body());
            Log::error("Shopify API Error: " . $response->body());
            return;
        }

        $orders = $response->json()['orders'] ?? [];
        foreach ($orders as $o) {
            $orderNumber = $o['name'] ?? $o['id'];
            $orderNumber1 = $o['order_number'] ?? $o['id'];
            $shippingCarrier = $o['shipping_lines'][0]['title'] ?? 'Standard';
            $shippingService = $o['shipping_lines'][0]['code'] ?? 'Standard';
            $shippingCost    = $o['shipping_lines'][0]['price'] ?? 0;
            $trackingNumber = '';
            $trackingCompany = '';
            $shipDate = null;
            if (!empty($o['fulfillments'])) {
                foreach ($o['fulfillments'] as $f) {
                    if ($f['status'] !== 'cancelled' && !empty($f['tracking_number']) && !empty($f['tracking_company'])) {
                        $trackingNumber = $f['tracking_number'];
                        $trackingCompany = $f['tracking_company'];
                        $shipDate = isset($f['created_at']) ? Carbon::parse($f['created_at']) : null;
                        break; 
                    }
                }
                if (empty($trackingNumber) && !empty($o['fulfillments'][0]['tracking_number'])) {
                    $f = $o['fulfillments'][0];
                    $trackingNumber = $f['tracking_number'] ?? '';
                    $trackingCompany = $f['tracking_company'] ?? '';
                    $shipDate = isset($f['created_at']) ? Carbon::parse($f['created_at']) : null;
                }

                if (!empty($trackingCompany)) {
                    $shippingCarrier = $trackingCompany;
                }
            }
            $fulfillmentStatus = $o['fulfillment_status'] ?? null;
            $itemNames = array_filter(array_map(function ($item) {
                return $item['name'] ?? '';
            }, $o['line_items'] ?? []));
            $itemNameForOrder = !empty($itemNames) ? implode(', ', $itemNames) : '';
            $status = 'awaiting_shipment';

            if (!empty($o['cancelled_at'])) {
                $status = 'cancelled';
            } elseif (($o['financial_status'] ?? '') === 'pending') {
                $status = 'awaiting_payment';
            } elseif (($o['financial_status'] ?? '') === 'refunded') {
                $status = 'refunded';
            } elseif (($o['fulfillment_status'] ?? '') === 'fulfilled') {
                $status = 'shipped';
            } elseif (($o['fulfillment_status'] ?? '') === 'partial') {
                $status = 'partially_shipped';
            } elseif (($o['financial_status'] ?? '') === 'paid' && (($o['fulfillment_status'] ?? '') === 'unfulfilled' || is_null($o['fulfillment_status']))) {
                $status = 'awaiting_shipment';
            }

            $order = Order::updateOrCreate(
                [
                    'marketplace'  => 'Business 5core',
                    'order_number' => $orderNumber1,
                ],
                [
                    'order_date'      => Carbon::parse($o['created_at']),
                    'order_age'       => Carbon::parse($o['created_at'])->diffInDays(Carbon::now()),
                    'marketplace_order_id' => $o['id'],
                    'notes'           => $o['note'] ?? '',
                    'is_gift'         => $o['buyer_accepts_marketing'] ?? 0,
                    'order_total'     => $o['total_price'] ?? 0,
                    'recipient_name' => trim(
                        $o['shipping_address']['name'] ??
                        (isset($o['shipping_address']['first_name']) || isset($o['shipping_address']['last_name'])
                            ? ($o['shipping_address']['first_name'] ?? '') . ' ' . ($o['shipping_address']['last_name'] ?? '')
                            : ($o['customer']['first_name'] ?? '') . ' ' . ($o['customer']['last_name'] ?? '')
                        )
                    ),
                    'recipient_email' => $o['email'] 
                        ?? ($o['contact_email'] ?? ($o['customer']['email'] ?? '')),
                    'recipient_phone' => $o['phone'] 
                        ?? ($o['billing_address']['phone'] ?? ($o['customer']['phone'] ?? '')),
                    'fulfillment_status' => $fulfillmentStatus,
                    'ship_address1'   => $o['shipping_address']['address1'] ?? '',
                    'ship_address2'   => $o['shipping_address']['address2'] ?? '',
                    'ship_city'       => $o['shipping_address']['city'] ?? '',
                    'ship_state'      => $o['shipping_address']['province'] ?? '',
                    'ship_postal_code'=> $o['shipping_address']['zip'] ?? '',
                    'ship_country'    => $o['shipping_address']['country'] ?? '',
                    'payment_status'  => $o['financial_status'] ?? 'pending',
                    'order_status'    => $status,
                    'shipping_carrier'=> $shippingCarrier,
                    'shipping_service'=> $shippingService,
                    'shipping_cost'   => $shippingCost,
                    'tracking_number' => $trackingNumber,
                    'ship_date'       => $shipDate,
                    'item_name'       => $itemNameForOrder, 
                    'item_sku'        => $item['sku'] ?? '',
                ]
            );

            $trackingNumbers = [];
            if (!empty($o['fulfillments'])) {
                foreach ($o['fulfillments'] as $f) {
                    $trackingNumber = $f['tracking_number'] ?? '';
                    if (empty($trackingNumber)) continue; 

                    $trackingNumbers[] = $trackingNumber;
                    $fulfillmentStatusValue = $f['status'] ?? 'success';
                    $labelStatus = ($fulfillmentStatusValue === 'success') ? 'active' : ($fulfillmentStatusValue === 'cancelled' ? 'cancelled' : 'pending');
                    $voidStatus = ($fulfillmentStatusValue === 'cancelled' ? 'cancelled' : 'active');
                    Shipment::updateOrCreate(
                        [
                            'order_id' => $order->id,
                            'tracking_number' => $trackingNumber,
                        ],
                        [
                            'carrier' => $f['tracking_company'] ?? $shippingCarrier,
                            'service_type' => $shippingService,
                            'package_weight' => null,
                            'package_dimensions' => null,
                            'tracking_url' => $f['tracking_url'] ?? '',
                            'label_status' => $labelStatus,
                            'void_status' => $voidStatus,
                            'label_url' => $f['tracking_url'] ?? '',
                            'shipment_status' => $fulfillmentStatusValue,
                            'label_data' => json_encode($f),
                            'cost' => $shippingCost,
                        ]
                    );
                }
            }

            foreach ($o['line_items'] as $item) {
                $qty = $item['quantity'] ?? 1;
                $dimensionData = getDimensionsBySku($item['sku'] ?? '', $qty); 
                OrderItem::updateOrCreate(
                    [
                        'order_id' => $order->id,
                        'sku'      => $item['sku'] ?? '',
                    ],
                    [
                        'product_name'    => $item['name'] ?? '',
                        'sku'        => $item['sku'] ?? '',
                        'quantity_ordered'=> $item['quantity'] ?? 1,
                        'price'           => $item['price'] ?? 0,
                        'total'           => ($item['quantity'] ?? 1) * ($item['price'] ?? 0),
                        'weight'          => $dimensionData['weight'] ?? 0,
                        'length'          => $dimensionData['length'] ?? 0,
                        'width'           => $dimensionData['width'] ?? 0,
                        'height'          => $dimensionData['height'] ?? 0,
                    ]
                );
            }
        }

        $this->info("✅ Shopify orders synced successfully!");
    }
}
