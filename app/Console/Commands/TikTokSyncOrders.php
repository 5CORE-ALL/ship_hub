<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Services\TikTokAuthService;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Exception;

class TikTokSyncOrders extends Command
{
    protected $signature = 'tiktok:sync-order';
    protected $description = 'Fetch and sync TikTok orders with full order details';

    public function handle()
    {
        $this->info('🔄 Syncing TikTok orders with full details...');
        Log::info('Starting TikTok order sync');

        try {
            $storeId = 10; // hardcoded
            $tiktok = new TikTokAuthService();

            // 1️⃣ Get access token
            $accessToken = $tiktok->getAccessToken($storeId);
            if (!$accessToken) {
                $integration = \App\Models\Integration::where('store_id', $storeId)->first();
                if (!$integration) {
                    $this->error("❌ No integration found for TikTok store ID {$storeId}. Please set up the integration first.");
                    Log::error("TikTok sync failed: No integration for store_id {$storeId}");
                } elseif (!$integration->refresh_token) {
                    $this->error("❌ TikTok integration for store ID {$storeId} is missing refresh_token. Please re-authenticate.");
                    Log::error("TikTok sync failed: Missing refresh_token for store_id {$storeId}");
                } else {
                    $this->error("❌ Failed to refresh TikTok access token for store ID {$storeId}. Token may be expired or invalid.");
                    Log::error("TikTok sync failed: Token refresh failed for store_id {$storeId}");
                }
                return Command::FAILURE;
            }

            // 2️⃣ Get shop cipher
            $shopCipher = $tiktok->getAuthorizedShopCipher($storeId, $accessToken);
            if (!$shopCipher) {
                $this->error('❌ Failed to get TikTok shop cipher.');
                return Command::FAILURE;
            }

            // 3️⃣ Fetch orders list
            $response = $tiktok->fetchOrders($accessToken, $shopCipher);
            $orders = $response['orders'] ?? [];

            Log::info('TikTok Orders:', [
                'orders' => json_encode($orders, JSON_PRETTY_PRINT)
            ]);

            if (empty($orders)) {
                $this->warn('⚠️ No TikTok orders found.');
                Log::warning('TikTok order sync: no orders found');
                return 0;
            }

            $count = 0;
            foreach ($orders as $orderData) {
                try {
                    $orderId = $orderData['id'] ?? null;
                    if (!$orderId) continue;

                    // Check if order already exists and is shipped
                    $existingOrder = Order::where('marketplace_order_id', $orderId)
                        ->where('marketplace', 'tiktok')
                        ->first();

                    if ($existingOrder && in_array(strtolower($existingOrder->order_status), ['shipped'])) {
                        $this->info("🚫 Skipping order {$orderId} — already shipped in DB.");
                        continue; // skip the entire order and its items
                    }

                    DB::beginTransaction();

                    // Extract core order data
                    $recipient = $orderData['recipient_address'] ?? [];
                    $payment = $orderData['payment'] ?? [];
                    $items = $orderData['line_items'] ?? [];

                    // Map TikTok status to your system
                    $statusMap = [
                        'AWAITING_COLLECTION' => 'AWAITING_COLLECTION',
                        'DELIVERED' => 'Delivered',
                        'COMPLETED' => 'Delivered',
                        'CANCELLED' => 'Cancelled',
                        'SHIPPED' => 'Shipped',
                        'IN_TRANSIT' => 'Shipped',
                        'AWAITING_SHIPMENT' => 'Unshipped',
                    ];
                    $shippingProviderId = $orderData['shipping_provider_id'] ?? '7117858858072016686';
                    $status = $statusMap[$orderData['status'] ?? ''] ?? 'Unknown';

                    // Create or update order
                    $orderModel = Order::updateOrCreate(
                        [
                            'marketplace_order_id' => $orderId,
                            'marketplace' => 'tiktok',
                        ],
                        [
                            'store_id' => $storeId,
                            'order_number' => $orderId,
                            'external_order_id' => $orderId,
                            'order_date' => isset($orderData['create_time'])
                                ? Carbon::createFromTimestamp($orderData['create_time'])
                                : null,
                            'order_total' => $payment['total_amount'] ?? 0,
                            'quantity' => count($items),
                            'shipping_cost' => $payment['shipping_fee'] ?? 0,
                            'order_status' => $status,
                            'fulfillment_status' => $status,
                            'recipient_name' => trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')),
                            'recipient_email' => $orderData['buyer_email'] ?? null,
                            'recipient_phone' => $recipient['phone_number'] ?? null,
                            'ship_address1' => $recipient['address_line1'] ?? null,
                            'ship_address2' => $recipient['address_line2'] ?? null,
                            'ship_city' => $this->extractDistrict($recipient, 'City'),
                            'ship_state' => $this->extractDistrict($recipient, 'State'),
                            'ship_country' => $this->extractDistrict($recipient, 'Country'),
                            'ship_postal_code' => $recipient['postal_code'] ?? null,
                            'shipping_service' => $orderData['delivery_option_name'] ?? null,
                            'shipping_carrier' => $orderData['shipping_provider'] ?? null,
                            'shipping_provider_id' => $shippingProviderId,
                            'raw_data' => json_encode($orderData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ]
                    );

                    // Insert / update order items only if the order is not shipped
                    if (!$existingOrder) {
                        foreach ($items as $item) {
                            $qty = 1;
                            $dimensionData = getDimensionsBySku($item['seller_sku'] ?? '', $qty);
                            OrderItem::updateOrCreate(
                                [
                                    'order_id' => $orderModel->id,
                                    'sku' => $item['seller_sku'] ?? null,
                                ],
                                [
                                    'item_name' => $item['product_name'] ?? null,
                                    'quantity_ordered' => $qty,
                                    'price' => $item['sale_price'] ?? 0,
                                    'total' => ($item['sale_price'] ?? 0) * $qty,
                                    'shipping_cost' => 0,
                                    'weight' => $dimensionData['weight'] ?? 20,
                                    'length' => $dimensionData['length'] ?? 8,
                                    'width' => $dimensionData['width'] ?? 6,
                                    'height' => $dimensionData['height'] ?? 2,
                                    'raw_data' => json_encode($item, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                                ]
                            );
                        }
                    }

                    DB::commit();
                    $count++;
                    Log::info('✅ TikTok order synced', ['order_id' => $orderId]);

                } catch (Exception $ex) {
                    DB::rollBack();
                    Log::error('Error syncing TikTok order', [
                        'order_id' => $orderData['id'] ?? null,
                        'error' => $ex->getMessage(),
                    ]);
                }
            }

            $this->info("✅ Synced {$count} TikTok orders successfully!");
            Log::info('TikTok order sync completed', ['count' => $count]);

        } catch (Exception $e) {
            $this->error('❌ TikTok order sync failed: ' . $e->getMessage());
            Log::error('TikTok order sync failed', ['error' => $e->getMessage()]);
        }

        return 0;
    }

    private function extractDistrict(array $recipient, string $level): ?string
    {
        foreach ($recipient['district_info'] ?? [] as $info) {
            if (($info['address_level_name'] ?? '') === $level) {
                return $info['address_name'] ?? null;
            }
        }
        return null;
    }
}
