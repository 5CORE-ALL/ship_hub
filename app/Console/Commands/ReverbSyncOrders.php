<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class ReverbSyncOrders extends Command
{
    protected $signature = 'reverb:sync-orders';
    protected $description = 'Fetch and sync Reverb orders and items into orders and order_items tables (ShipStation style)';

    public function handle()
    {
        $this->info('🔄 Syncing Reverb orders and items...');

        $token = config('services.reverb.token');
        if (!$token) {
            $this->error('❌ Reverb API token is missing in configuration.');
            Log::error('Reverb API token missing');
            return;
        }

        $url = 'https://api.reverb.com/api/my/orders/selling/all';

        $startOfDay = Carbon::now()->subDays(2)
            ->startOfDay()
            ->setTimezone('America/New_York')
            ->toIso8601String();

        $endOfDay = Carbon::now()
            ->setTimezone('America/New_York')
            ->endOfDay()
            ->toIso8601String();

        $page = 1;
        $perPage = 50;
        $hasMore = true;

        $this->info("📅 Fetching orders from {$startOfDay} to {$endOfDay}");

        while ($hasMore) {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/hal+json',
                'Accept-Version' => '3.0',
            ])->get($url, [
                'updated_start_date' => $startOfDay,
                'updated_end_date' => $endOfDay,
                'per_page' => $perPage,
                'page' => $page,
            ]);

            if ($response->failed()) {
                $this->error('❌ Failed to fetch orders: ' . $response->body());
                Log::error('Reverb API fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return;
            }

            $data = $response->json();
            $orders = $data['orders'] ?? [];

            if (empty($orders)) {
                $this->info('ℹ️ No more orders found.');
                break;
            }

            foreach ($orders as $order) {
                try {
                  
                        // Log::info("📦 First order fetched:\n" . json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                 
                    $orderUuid = $order['uuid'] ?? ($order['id'] ?? 'unknown');
                    $orderNumber = $order['order_number'] ?? null;
                    $createdAt = $order['created_at'] ?? null;
                    $buyerEmail = $order['buyer_email'] ?? null;
                    $shippingAddress = $order['shipping_address'] ?? [];

                    $recipientName = $shippingAddress['name'] ?? $order['buyer_name'] ?? null;

                    // --- Order Data ---
                    $dataToSave = [
                        'store_id' => 1,
                        'marketplace' => 'reverb',
                        'marketplace_order_id' => $orderUuid,
                        'order_number' => $orderNumber,
                        'order_date' => $createdAt ? Carbon::parse($createdAt) : null,
                        'order_total' => $order['total']['amount'] ?? 0,
                        'amount_product' => $order['amount_product']['amount'] ?? 0,
                        'amount_shipping' => $order['shipping']['amount'] ?? 0,
                        'amount_tax' => $order['amount_tax']['amount'] ?? 0,
                        'recipient_name' => $recipientName,
                        'recipient_company' => $shippingAddress['company'] ?? null,
                        'recipient_email' => $buyerEmail,
                        'recipient_phone' => $shippingAddress['phone'] ?? null,
                        'ship_address1' => $shippingAddress['street_address'] ?? null,
                        'ship_address2' => $shippingAddress['extended_address'] ?? null,
                        'ship_city' => $shippingAddress['locality'] ?? null,
                        'ship_state' => $shippingAddress['region'] ?? null,
                        'ship_postal_code' => $shippingAddress['postal_code'] ?? null,
                        'ship_country' => $shippingAddress['country_code'] ?? null,
                        'shipping_carrier' => $order['shipping_provider'] ?? null,
                        'tracking_number' => $order['shipping_code'] ?? null,
                        'shipping_cost' => $order['shipping']['amount'] ?? 0,
                        'order_status' => $order['status'] ?? null,
                        'payment_status' => $order['payment_method'] ?? null,
                        'fulfillment_status' => $order['shipment_status'] ?? null,
                        'item_sku' => $order['sku'] ?? null,       // <-- updated
                        'item_name' => $order['title'] ?? null,    // <-- updated
                        'quantity' => $order['quantity'] ?? 1,
                        'raw_data' => json_encode($order),
                    ];

                    $existingOrder = Order::where('marketplace', 'reverb')
                        ->where('order_number', $orderNumber)
                        ->first();

                    if ($existingOrder && $existingOrder->marketplace_order_id !== $orderUuid) {
                        $existingOrder->update($dataToSave);
                        $orderModel = $existingOrder;
                        $this->info("✅ Order {$orderNumber} updated");
                    } else {
                        $orderModel = Order::updateOrCreate(
                            [
                                'marketplace' => 'reverb',
                                'marketplace_order_id' => $orderUuid,
                                'order_number' => $orderNumber,
                            ],
                            $dataToSave
                        );
                        $this->info("✅ Order {$orderNumber} synced");
                    }

                    // --- Order Item Data ---
                    $qty = $order['quantity'] ?? 1;
                    $dimensionData = getDimensionsBySku($order['sku'] ?? '', $qty);
                    $itemData = [
                        'order_id' => $orderModel->id,
                        'order_number' => $orderNumber,
                        'order_item_id' => $order['product_id'] ?? $orderUuid,
                        'sku' => $order['sku'] ?? null,      
                        'item_name' => $order['title'] ?? null,  
                        'product_name' => $order['title'] ?? null,
                        'quantity_ordered' => $order['quantity'] ?? 1,
                        'quantity_shipped' => in_array($order['shipment_status'] ?? '', ['shipped','delivered']) ? ($order['quantity'] ?? 1) : 0,
                        'unit_price' => $order['amount_product']['amount'] ?? 0,
                        'item_tax' => $order['amount_tax']['amount'] ?? 0,
                        'promotion_discount' => 0,
                        'currency' => $order['total']['currency'] ?? 'USD',
                        'is_gift' => false,
                        'weight_unit' => null,
                        'dimensions' => null,
                        'marketplace' => 'reverb',
                        'weight' => $dimensionData['weight'] ?? 20,
                        'length'             => $dimensionData['length'] ?? 8,
                        'width'              => $dimensionData['width'] ?? 6,
                        'height'             => $dimensionData['height'] ?? 2
                        // 'raw_data' => json_encode($order),
                    ];

                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $itemData['order_item_id'],
                            'marketplace' => 'reverb',
                        ],
                        $itemData
                    );

                    $this->info("✅ Order Item for {$orderNumber} synced");

                } catch (\Exception $e) {
                    $this->error("⚠️ Error saving order {$orderUuid}: " . $e->getMessage());
                    Log::error('Reverb order save error', [
                        'order_number' => $orderNumber ?? 'unknown',
                        'uuid' => $orderUuid,
                        'exception' => $e->getMessage(),
                        'order' => $order,
                    ]);
                }
            }

            $page++;
            $hasMore = isset($data['_links']['next']['href']);
            $this->info("📄 Processed page {$page}");
        }

        $this->info('🎉 Reverb order and item sync completed!');
    }
}
