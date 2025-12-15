<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class TopDawgSyncOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Run with: php artisan topdawg:sync-orders
     */
    protected $signature = 'topdawg:sync-orders';

    /**
     * The console command description.
     */
    protected $description = 'Fetch and sync TopDawg orders into orders and order_items tables';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔄 Syncing TopDawg orders...');

        $token = config('services.topdawg.token'); // defined in config/services.php
        if (!$token) {
            $this->error('❌ TopDawg API token missing. Add it in config/services.php or .env');
            return Command::FAILURE;
        }

        $url = 'https://api.topdawg.com/v1/orders';
        $page = 1;
        $perPage = 50;
        $hasMore = true;

        while ($hasMore) {
            $response = Http::withToken($token)->get($url, [
                'page' => $page,
                'limit' => $perPage,
            ]);

            if ($response->failed()) {
                $this->error('❌ API call failed: ' . $response->body());
                Log::error('TopDawg fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return Command::FAILURE;
            }

            $data = $response->json();
            $orders = $data['data'] ?? [];

            if (empty($orders)) {
                $this->info('ℹ️ No more orders found.');
                break;
            }

            foreach ($orders as $order) {
                try {
                    $orderId = $order['id'] ?? null;
                    $orderNumber = $order['order_number'] ?? $orderId;

                    $orderModel = Order::updateOrCreate(
                        [
                            'marketplace' => 'topdawg',
                            'marketplace_order_id' => $orderId,
                        ],
                        [
                            'store_id' => 1,
                            'marketplace' => 'topdawg',
                            'marketplace_order_id' => $orderId,
                            'order_number' => $orderNumber,
                            'order_date' => isset($order['created_at']) ? Carbon::parse($order['created_at']) : null,
                            'order_total' => $order['total_amount'] ?? 0,
                            'amount_shipping' => $order['shipping_amount'] ?? 0,
                            'amount_tax' => $order['tax_amount'] ?? 0,
                            'recipient_name' => $order['shipping']['name'] ?? null,
                            'recipient_email' => $order['customer']['email'] ?? null,
                            'recipient_phone' => $order['shipping']['phone'] ?? null,
                            'ship_address1' => $order['shipping']['address1'] ?? null,
                            'ship_address2' => $order['shipping']['address2'] ?? null,
                            'ship_city' => $order['shipping']['city'] ?? null,
                            'ship_state' => $order['shipping']['state'] ?? null,
                            'ship_postal_code' => $order['shipping']['postal_code'] ?? null,
                            'ship_country' => $order['shipping']['country'] ?? null,
                            'order_status' => $order['status'] ?? null,
                            'payment_status' => $order['payment_status'] ?? null,
                            'fulfillment_status' => $order['fulfillment_status'] ?? null,
                            'raw_data' => json_encode($order),
                        ]
                    );

                    $this->info("✅ Order {$orderNumber} synced");

                    foreach ($order['items'] ?? [] as $item) {
                        OrderItem::updateOrCreate(
                            [
                                'order_id' => $orderModel->id,
                                'order_item_id' => $item['id'] ?? null,
                                'marketplace' => 'topdawg',
                            ],
                            [
                                'order_id' => $orderModel->id,
                                'order_number' => $orderNumber,
                                'item_sku' => $item['sku'] ?? null,
                                'item_name' => $item['name'] ?? null,
                                'product_name' => $item['name'] ?? null,
                                'quantity_ordered' => $item['quantity'] ?? 1,
                                'unit_price' => $item['price'] ?? 0,
                                'item_tax' => $item['tax'] ?? 0,
                                'promotion_discount' => $item['discount'] ?? 0,
                                'currency' => $order['currency'] ?? 'USD',
                                'marketplace' => 'topdawg',
                                'raw_data' => json_encode($item),
                            ]
                        );
                    }

                    $this->info("   ↳ Items for Order {$orderNumber} synced");

                } catch (\Exception $e) {
                    $this->error("⚠️ Error saving order {$orderId}: " . $e->getMessage());
                    Log::error('TopDawg order save error', [
                        'order_id' => $orderId,
                        'exception' => $e->getMessage(),
                        'order' => $order,
                    ]);
                }
            }

            $page++;
            $hasMore = count($orders) >= $perPage;
        }

        $this->info('🎉 TopDawg order sync completed!');
        return Command::SUCCESS;
    }
}
