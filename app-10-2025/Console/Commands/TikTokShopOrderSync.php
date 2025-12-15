<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class TikTokShopOrderSync extends Command
{
    protected $signature = 'tiktok:sync-orders';
    protected $description = 'Fetch and sync TikTok Shop orders using GetOrderList (API version 202309)';

    public function handle()
    {
        $this->info('🔹 Starting TikTok Shop order sync (Get Order List 202309)');

        $stores = DB::table('stores as s')
            ->join('sales_channels as sc', 's.sales_channel_id', '=', 'sc.id')
            ->join('marketplaces as m', 's.marketplace_id', '=', 'm.id')
            ->leftJoin('integrations as i', 's.id', '=', 'i.store_id')
            ->where('sc.platform', 'tiktok')
            ->select(
                's.id as store_id',
                's.name as store_name',
                'i.access_token',
                'i.refresh_token',
                'i.app_key',
                'i.app_secret',
                'i.expires_at'
            )
            ->get();

        if ($stores->isEmpty()) {
            $this->error('⚠️ No TikTok stores found.');
            return 1;
        }

        foreach ($stores as $store) {
            $this->info("Processing store: {$store->store_name} (ID: {$store->store_id})");

            if (!$store->access_token || !$store->app_key || !$store->app_secret) {
                $this->warn("⚠️ Missing credentials for store {$store->store_name}. Skipping.");
                continue;
            }

            // If token expiry logic is required, refresh access_token here...

            // Prepare request
            $endpoint = 'https://open-api.tiktokglobalshop.com/api/orders/list';  // adjust endpoint per doc
            $version = '202309';
            $timestamp = time();
            $appKey = $store->app_key;
            $accessToken = $store->access_token;

            $body = [
                'page_size' => 50,
                // optionally filters
                'start_update_time' => Carbon::now()->subDays(30)->getTimestamp(),  
                'end_update_time'   => Carbon::now()->getTimestamp(),
                // maybe other filters (order_status etc)
            ];

            // Build sign
            $sign = $this->generateSign(
                $endpoint,
                [
                    'app_key'       => $appKey,
                    'access_token'  => $accessToken,
                    'timestamp'     => $timestamp,
                    'version'       => $version,
                ],
                $store->app_secret,
                $body
            );

            // Make the API call
            $response = Http::post($endpoint, array_merge($body, [
                'app_key'       => $appKey,
                'access_token'  => $accessToken,
                'timestamp'     => $timestamp,
                'version'       => $version,
                'sign'          => $sign,
            ]));

            if ($response->failed()) {
                $this->error("❌ Failed to fetch TikTok orders for store {$store->store_name}: " . $response->body());
                Log::error('TikTok GetOrderList failed', [
                    'store_id' => $store->store_id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                continue;
            }

            $respJson = $response->json();
            $orders = $respJson['data']['orders'] ?? [];

            if (empty($orders)) {
                $this->info("No orders in this timeframe for store {$store->store_name}.");
                continue;
            }

            foreach ($orders as $order) {
                // process same as your ebay logic:
                try {
                    // map fields from TikTok order to your Order & OrderItem model
                    // e.g., order_id, status, line_items, shipping info etc.

                    // Example:
                    $orderModel = Order::updateOrCreate(
                        [
                            'marketplace' => 'tiktok',
                            'order_number' => $order['order_id'],
                        ],
                        [
                            'store_id'       => $store->store_id,
                            'order_date'     => !empty($order['create_time']) ? Carbon::createFromTimestamp($order['create_time']) : null,
                            'order_total'    => $order['total_amount'] ?? 0,
                            'order_status'   => $order['order_status'] ?? null,
                            'shipping_cost'  => $order['shipping_fee'] ?? 0,
                            // etc...
                        ]
                    );

                    foreach ($order['line_items'] ?? [] as $item) {
                        OrderItem::updateOrCreate(
                            [
                                'order_id'      => $orderModel->id,
                                'order_item_id' => $item['item_id'],
                            ],
                            [
                                'sku'            => $item['sku'] ?? null,
                                'quantity_ordered' => $item['quantity'],
                                'unit_price'     => $item['price'] ?? 0,
                                // etc...
                            ]
                        );
                    }

                    $this->info("✅ Order {$order['order_id']} synced for store {$store->store_name}");
                } catch (\Exception $e) {
                    $this->error("Error saving order {$order['order_id']} : " . $e->getMessage());
                    Log::error('TikTok order save error', [
                        'store_id' => $store->store_id,
                        'order_id' => $order['order_id'] ?? null,
                        'exception' => $e->getMessage(),
                        'order'     => $order,
                    ]);
                }
            }
        }

        $this->info('✅ TikTok Shop order sync (Get Order List) completed for all stores!');
        return 0;
    }

    /**
     * Generate signature for TikTok Shop API (GetOrderList v202309)
     *
     * @param string $pathOrUrl  The API path or URL
     * @param array  $queryParams  Query params without sign
     * @param string $appSecret
     * @param array  $bodyParams   Body params
     * @return string
     */
    protected function generateSign($pathOrUrl, array $queryParams, string $appSecret, array $bodyParams = []): string
    {
        // 1. Sort query params by key in alphabetical order
        ksort($queryParams);

        // 2. Build the string: app_secret + path + sorted(query key + value) + sorted(body key + value) + app_secret
        //    (depending on doc; sometimes body params are appended if content_type not multipart) :contentReference[oaicite:8]{index=8}

        $str = $appSecret;

        // if you pass full URL, extract path; else user can pass path
        $parsed = parse_url($pathOrUrl);
        $path = $parsed['path'] ?? $pathOrUrl;

        $str .= $path;

        foreach ($queryParams as $key => $val) {
            $str .= $key . $val;
        }

        if (!empty($bodyParams)) {
            ksort($bodyParams);
            foreach ($bodyParams as $key => $val) {
                // if value is array/object, convert to JSON or string appropriately
                $str .= $key . (is_array($val) ? json_encode($val) : $val);
            }
        }

        $str .= $appSecret;

        // then SHA256, then base64, then make URL safe if required
        $hash = hash_hmac('sha256', $str, $appSecret, true);
        $base64 = base64_encode($hash);

        // sometimes TikTok replaces +/ in base64 with -_ etc & remove trailing = for URL safe
        $urlSafe = rtrim(strtr($base64, '+/', '-_'), '=');

        return $urlSafe;
    }
}
