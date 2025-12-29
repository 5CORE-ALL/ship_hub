<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class WalmartSyncOrders extends Command
{
    protected $signature = 'walmart:sync-orders';
    protected $description = 'Fetch and sync Walmart orders and items into orders and order_items tables (Reverb-style)';

    public function handle()
    {
        // Check if Walmart sync is disabled via environment variable
        if (config('services.walmart.sync_enabled', true) === false) {
            $this->info('⏸️  Walmart order sync is disabled. Set WALMART_SYNC_ENABLED=true to enable.');
            return;
        }

        $this->info('🔄 Starting Walmart orders sync...');
        $tokenData = $this->refreshAccessToken();
        if (!$tokenData || !isset($tokenData['accessToken'])) {
            $this->error('❌ Failed to retrieve Walmart access token.');
            return;
        }
        $token = $tokenData['accessToken'];
        $this->info('✅ Got Walmart access token.');

        $startDate = Carbon::now()->subDays(2)->startOfDay()->toIso8601String();
        $endDate   = Carbon::now()->endOfDay()->toIso8601String();

        $orders = $this->fetchOrders($token, $startDate, $endDate);
        if (!$orders) {
            $this->error('❌ Failed to fetch Walmart orders.');
            return;
        }
         // log::info('📦 Orders fetched:',[$orders]);
        $this->info('📦 Orders fetched: ' . count($orders));
        foreach ($orders as $order) {
            try {
                $orderId = $order['purchaseOrderId'] ?? null;
                $orderNumber = $order['customerOrderId'] ?? $orderId;

                if (!$orderNumber) {
                    $this->warn("⚠️ Skipping order without order number");
                    continue;
                }

                // Recipient details
                $shipping = $order['shippingInfo']['postalAddress'] ?? [];
                $totalAmount = 0;
                $totalTax = 0;
                $totalShipping = 0;

                foreach ($order['orderLines']['orderLine'] ?? [] as $line) {
                    foreach ($line['charges']['charge'] ?? [] as $charge) {
                        $amount = $charge['chargeAmount']['amount'] ?? 0;

                        $totalAmount += $amount;
                        $totalTax   += $charge['tax']['taxAmount']['amount'] ?? 0;

                        if (($charge['chargeType'] ?? '') === 'SHIPPING') {
                            $totalShipping += $amount;
                        }
                    }
                }


                $orderData = [
                    'store_id' => 1,
                    'marketplace' => 'walmart',
                    'marketplace_order_id' => $orderId,
                    'order_number' => $orderId,
                    'item_name' => $item['item']['productName'] ?? null,
                    'item_sku' => $item['item']['sku'] ?? null,
                    'order_date' => isset($order['orderDate']) ? Carbon::createFromTimestampMs($order['orderDate']) : null,
                    'order_total' => $totalAmount,
                    'amount_product' => $totalAmount - $totalTax,
                    'amount_shipping' => $totalShipping,
                    'amount_tax' => $totalTax,
                    'recipient_name' => $shipping['name'] ?? null,
                    'recipient_company' => null,
                    'shipping_cost'     => $totalShipping,
                    'recipient_email' => $order['customerEmailId'] ?? null,
                    'recipient_phone' => $order['shippingInfo']['phone'] ?? null,
                    'ship_address1' => $shipping['address1'] ?? null,
                    'ship_address2' => $shipping['address2'] ?? null,
                    'ship_city' => $shipping['city'] ?? null,
                    'ship_state' => $shipping['state'] ?? null,
                    'ship_postal_code' => $shipping['postalCode'] ?? null,
                    'ship_country' => $shipping['country'] ?? null,
                    'shipping_carrier' => $order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['trackingInfo']['carrierName']['carrier'] ?? null,
                    'shipping_service'  => $order['shippingInfo']['carrierMethodName'] 
                            ?? ($order['shippingInfo']['methodCode'] ?? null),
                    'tracking_number' => $order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['trackingInfo']['trackingNumber'] ?? null,
                    'ship_date' => isset($order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['trackingInfo']['shipDateTime'])
        ? Carbon::createFromTimestampMs($order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['trackingInfo']['shipDateTime'])
        : (isset($order['shippingInfo']['estimatedShipDate'])
            ? Carbon::createFromTimestampMs($order['shippingInfo']['estimatedShipDate'])
            : null),
                    'order_status' => $order['orderLines']['orderLine'][0]['orderLineStatuses']['orderLineStatus'][0]['status'] ?? null,
                    'payment_status' => null, 
                    'fulfillment_status' => null,
                    'raw_data' => json_encode($order),
                ];

                $orderModel = Order::updateOrCreate(
                    [
                        'marketplace' => 'walmart',
                        'order_number' => $orderId,
                    ],
                    $orderData
                );

                foreach ($order['orderLines']['orderLine'] ?? [] as $item) {
                    $itemId = $item['lineNumber'] ?? uniqid('wm_item_');
                    $charge = $item['charges']['charge'][0] ?? [];
                    $tax = $charge['tax']['taxAmount']['amount'] ?? 0;
                    $qty = $item['orderLineQuantity']['amount'] ?? 1;
                    $dimensionData = getDimensionsBySku($item['item']['sku'] ?? '', $qty); 


                    $itemData = [
                        'order_id' => $orderModel->id,
                        'order_number' => $orderId,
                        'order_item_id' => $itemId,
                        'sku' => $item['item']['sku'] ?? null,
                        'item_name' => $item['item']['productName'] ?? null,
                        'product_name' => $item['item']['productName'] ?? null,
                        'quantity_ordered' => $item['orderLineQuantity']['amount'] ?? 1,
                        'quantity_shipped' => $item['orderLineStatuses']['orderLineStatus'][0]['statusQuantity']['amount'] ?? 0,
                        'unit_price' => $charge['chargeAmount']['amount'] ?? 0,
                        'item_tax' => $tax,
                        'promotion_discount' => 0,
                        'currency' => $charge['chargeAmount']['currency'] ?? 'USD',
                        'is_gift' => false,
                        'marketplace' => 'walmart',
                        'weight' => $dimensionData['weight'] ?? 0,
                        'length' => $dimensionData['length'] ?? 0,
                        'width'  => $dimensionData['width'] ?? 0,
                        'height' => $dimensionData['height'] ?? 0,
                        'raw_data' => json_encode($item),
                    ];

                    OrderItem::updateOrCreate(
                        [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $itemId,
                            'marketplace' => 'walmart',
                        ],
                        $itemData
                    );
                }

                $this->info("✅ Order {$orderNumber} synced.");

            } catch (\Exception $e) {
                $this->error("⚠️ Error saving order: " . $e->getMessage());
                Log::error('Walmart order save error', [
                    'exception' => $e->getMessage(),
                    'order' => $order,
                ]);
            }
        }

        $this->info('🎉 Walmart orders sync completed!');
    }

    private function refreshAccessToken()
    {
        $clientId = config('services.walmart.client_id');
        $clientSecret = config('services.walmart.client_secret');

        $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

        $response = Http::asForm()->withHeaders([
            'Authorization' => $authHeader,
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
        ])->post('https://marketplace.walmartapis.com/v3/token', [
            'grant_type' => 'client_credentials',
        ]);

        if ($response->ok()) {
            $xml = simplexml_load_string($response->body());
            $json = json_encode($xml);
            return json_decode($json, true);
        }

        return null;
    }

    private function fetchOrders($token, $startDate, $endDate)
    {
        $allOrders = [];
        $nextCursor = null;

        do {
            $query = [
                'limit' => 200,
                'createdStartDate' => $startDate,
                'createdEndDate' => $endDate,
            ];
            if ($nextCursor) $query['nextCursor'] = $nextCursor;

            $response = Http::withHeaders([
                'WM_SEC.ACCESS_TOKEN' => $token,
                'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
                'WM_SVC.NAME' => 'Walmart Marketplace',
                'Accept' => 'application/json',
            ])->get('https://marketplace.walmartapis.com/v3/orders', $query);

            if (!$response->ok()) {
                Log::error('Failed to fetch Walmart orders', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            $orders = $data['list']['elements']['order'] ?? [];
            $allOrders = array_merge($allOrders, $orders);

            $nextCursor = $data['list']['paging']['nextCursor'] ?? null;

        } while ($nextCursor);

        return $allOrders;
    }
}
