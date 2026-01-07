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
use EcomPHP\TiktokShop\Errors\TokenException;
use EcomPHP\TiktokShop\Errors\ResponseException;

class TikTokSyncOrders extends Command
{
    protected $signature = 'tiktok:sync-order {--store= : Sync orders for a specific store ID only}';
    protected $description = 'Fetch and sync TikTok orders with full order details for all TikTok stores (or a specific store if --store is provided)';

    public function handle()
    {
        $storeId = $this->option('store');
        
        if ($storeId) {
            $this->info("🔹 Starting TikTok order sync for store ID: {$storeId}...");
        } else {
            $this->info('🔹 Starting TikTok order sync for all stores...');
        }
        
        Log::info('Starting TikTok order sync', ['store_id' => $storeId]);

        // Query TikTok stores from database
        $stores = DB::table('stores as s')
            ->join('sales_channels as sc', 's.sales_channel_id', '=', 'sc.id')
            ->join('marketplaces as m', 's.marketplace_id', '=', 'm.id')
            ->leftJoin('integrations as i', 's.id', '=', 'i.store_id')
            ->where('sc.platform', 'tiktok')
            ->when($storeId, function ($query) use ($storeId) {
                return $query->where('s.id', $storeId);
            })
            ->select(
                's.id as store_id',
                's.name as store_name',
                'sc.name as sales_channel_name',
                'm.name as marketplace_name',
                'i.access_token',
                'i.refresh_token',
                'i.app_id',
                'i.app_secret',
                'i.expires_at',
                'i.shop_cipher'
            )
            ->distinct()
            ->get();

        if ($stores->isEmpty()) {
            $this->error('⚠️ No TikTok stores found.');
            Log::warning('TikTok sync: No stores found');
            return Command::FAILURE;
        }

        $totalCount = 0;

        foreach ($stores as $store) {
            $this->info("Processing store: {$store->store_name} (ID: {$store->store_id})");
            
            try {
                // Check if integration exists and has required data
                if (!$store->refresh_token || !$store->app_id || !$store->app_secret) {
                    $this->warn("⚠️ Missing integration data for store {$store->store_name} (ID: {$store->store_id}). Required: refresh_token, app_id, app_secret. Skipping.");
                    Log::warning('TikTok sync: Missing integration data', [
                        'store_id' => $store->store_id,
                        'store_name' => $store->store_name,
                        'has_refresh_token' => !empty($store->refresh_token),
                        'has_app_id' => !empty($store->app_id),
                        'has_app_secret' => !empty($store->app_secret),
                    ]);
                    continue;
                }

                $tiktok = new TikTokAuthService($store->store_id);

                // 1️⃣ Get access token (force refresh if expired_at is in the past)
                $forceRefresh = false;
                if ($store->expires_at && Carbon::parse($store->expires_at)->isPast()) {
                    $this->info("🔄 Access token expired. Refreshing...");
                    $forceRefresh = true;
                }
                
                $accessToken = $tiktok->getAccessToken($store->store_id, $forceRefresh);
                if (!$accessToken) {
                    $this->error("❌ Failed to get/refresh TikTok access token for store {$store->store_name} (ID: {$store->store_id}). Please check integration setup.");
                    $this->warn("💡 Make sure your refresh_token is valid in the integrations table.");
                    Log::error("TikTok sync failed: Token refresh failed for store_id {$store->store_id}");
                    continue;
                }
                
                $this->info("✅ Access token retrieved successfully");

                // 2️⃣ Get shop cipher (use stored one if available, otherwise fetch from API)
                $shopCipher = $store->shop_cipher;
                
                if (!$shopCipher) {
                    $this->warn("⚠️ Shop cipher not found in database. Attempting to fetch from TikTok API...");
                    $this->warn("💡 If you know your shop_cipher, you can manually set it in the database:");
                    $this->warn("   DB::table('integrations')->where('store_id', {$store->store_id})->update(['shop_cipher' => 'YOUR_SHOP_CIPHER']);");
                    $this->newLine();
                    
                    $maxRetries = 2;
                    $retryCount = 0;
                    
                    while ($retryCount < $maxRetries && !$shopCipher) {
                        try {
                            $shopCipher = $tiktok->getAuthorizedShopCipher($store->store_id, $accessToken);
                            if ($shopCipher) {
                                break; // Success, exit retry loop
                            }
                            
                            // If we get here, shopCipher is null but no exception was thrown
                            $this->warn("⚠️ API call succeeded but shop_cipher not found in response. Retrying...");
                            $retryCount++;
                            if ($retryCount >= $maxRetries) {
                                $this->error("❌ Failed to get TikTok shop cipher for store {$store->store_name} (ID: {$store->store_id}).");
                                $this->warn('');
                                $this->warn('💡 Possible reasons:');
                                $this->warn('   - IP address not whitelisted in TikTok Developer Portal');
                                $this->warn('   - API returned empty response');
                                $this->warn('   - Shop cipher field not found in API response');
                                $this->warn('');
                                $this->warn('🔧 WORKAROUND: If you know your shop_cipher, you can set it manually:');
                                $this->warn("   php artisan tinker");
                                $this->warn("   DB::table('integrations')->where('store_id', {$store->store_id})->update(['shop_cipher' => 'YOUR_SHOP_CIPHER']);");
                                $this->warn('');
                                $this->warn('📋 Check Laravel logs for detailed error information:');
                                $this->warn("   tail -f storage/logs/laravel.log | grep -i tiktok");
                                Log::error('TikTok sync: Shop cipher retrieval failed', [
                                    'store_id' => $store->store_id,
                                    'access_token_present' => !empty($accessToken),
                                ]);
                                break; // Exit retry loop
                            }
                        } catch (\EcomPHP\TiktokShop\Errors\TokenException $e) {
                        $errorMessage = $e->getMessage();
                        
                        // Check if it's an expired token error
                        $isExpiredToken = strpos($errorMessage, 'expired') !== false || 
                                         strpos($errorMessage, 'Expired credentials') !== false;
                        
                        if ($isExpiredToken && $retryCount < $maxRetries - 1) {
                            $this->warn("🔄 Token expired. Refreshing and retrying... (Attempt " . ($retryCount + 2) . "/{$maxRetries})");
                            // Force refresh the token
                            $accessToken = $tiktok->getAccessToken($store->store_id, true);
                            if ($accessToken) {
                                $retryCount++;
                                continue; // Retry with new token
                            }
                        }
                        
                        $this->error("❌ Failed to get TikTok shop cipher for store {$store->store_name}.");
                        $this->error('');
                        $this->error('📋 ACTUAL ERROR MESSAGE:');
                        $this->error($errorMessage);
                        $this->error('');
                        
                        // Check if it's actually an IP allowlist error
                        $isIpError = strpos($errorMessage, 'IP address is not in the IP allow list') !== false || 
                                    strpos($errorMessage, 'Access denied') !== false ||
                                    strpos($errorMessage, 'IP allowlist') !== false ||
                                    strpos($errorMessage, 'IP whitelist') !== false ||
                                    strpos($errorMessage, 'not whitelisted') !== false;
                        
                        if ($isIpError) {
                            $this->error('🔒 IP ALLOWLIST ERROR DETECTED');
                            $this->error('Your server IP address is not whitelisted in TikTok Shop.');
                            $this->warn('');
                            $this->warn('To fix this:');
                            $this->warn('1. Go to TikTok Shop Developer Portal');
                            $this->warn('2. Navigate to your app settings');
                            $this->warn('3. Add your server IP address to the IP allowlist');
                            $this->warn('4. Save and wait a few minutes for changes to take effect');
                            $this->warn('');
                            $this->info('💡 For more details: https://m.tiktok.shop/s/AIu6dbFhs2XW');
                        } else {
                            $this->warn('⚠️ This might not be an IP allowlist issue. Check the error message above.');
                            $this->warn('💡 Check logs for detailed error information.');
                        }
                        
                        Log::error('TikTok sync: Shop cipher retrieval failed with exception', [
                            'store_id' => $store->store_id,
                            'error' => $errorMessage,
                            'error_code' => $e->getCode(),
                            'exception_class' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        break; // Exit retry loop
                    } catch (\EcomPHP\TiktokShop\Errors\ResponseException $e) {
                        $errorMessage = $e->getMessage();
                        
                        // Check if it's an expired token error
                        $isExpiredToken = strpos($errorMessage, 'expired') !== false || 
                                         strpos($errorMessage, 'Expired credentials') !== false;
                        
                        if ($isExpiredToken && $retryCount < $maxRetries - 1) {
                            $this->warn("🔄 Token expired. Refreshing and retrying... (Attempt " . ($retryCount + 2) . "/{$maxRetries})");
                            // Force refresh the token
                            $accessToken = $tiktok->getAccessToken($store->store_id, true);
                            if ($accessToken) {
                                $retryCount++;
                                continue; // Retry with new token
                            }
                        }
                        
                        $this->error("❌ Failed to get TikTok shop cipher for store {$store->store_name}.");
                        $this->error('');
                        $this->error('📋 ACTUAL ERROR MESSAGE:');
                        $this->error($errorMessage);
                        $this->error('');
                        
                        // Check if it's actually an IP allowlist error
                        $isIpError = strpos($errorMessage, 'IP address is not in the IP allow list') !== false || 
                                    strpos($errorMessage, 'Access denied') !== false ||
                                    strpos($errorMessage, 'IP allowlist') !== false ||
                                    strpos($errorMessage, 'IP whitelist') !== false ||
                                    strpos($errorMessage, 'not whitelisted') !== false;
                        
                        if ($isIpError) {
                            $this->error('🔒 IP ALLOWLIST ERROR DETECTED');
                            $this->error('Your server IP address is not whitelisted in TikTok Shop.');
                            $this->warn('');
                            $this->warn('To fix this:');
                            $this->warn('1. Go to TikTok Shop Developer Portal');
                            $this->warn('2. Navigate to your app settings');
                            $this->warn('3. Add your server IP address to the IP allowlist');
                            $this->warn('4. Save and wait a few minutes for changes to take effect');
                            $this->warn('');
                            $this->info('💡 For more details: https://m.tiktok.shop/s/AIu6dbFhs2XW');
                        } else {
                            $this->warn('⚠️ This might not be an IP allowlist issue. Check the error message above.');
                            $this->warn('💡 Check logs for detailed error information.');
                        }
                        
                        Log::error('TikTok sync: Shop cipher retrieval failed with exception', [
                            'store_id' => $store->store_id,
                            'error' => $errorMessage,
                            'error_code' => $e->getCode(),
                            'exception_class' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                        ]);
                        break; // Exit retry loop
                    } catch (\Exception $e) {
                        $errorMessage = $e->getMessage();
                        $this->error("❌ Unexpected error getting TikTok shop cipher for store {$store->store_name}.");
                        $this->error('');
                        $this->error('📋 ACTUAL ERROR MESSAGE:');
                        $this->error($errorMessage);
                        $this->error('');
                        $this->error('📋 Exception Type: ' . get_class($e));
                        $this->warn('');
                        $this->warn('🔧 WORKAROUND: If you know your shop_cipher, you can set it manually:');
                        $this->warn("   php artisan tinker");
                        $this->warn("   DB::table('integrations')->where('store_id', {$store->store_id})->update(['shop_cipher' => 'YOUR_SHOP_CIPHER']);");
                        
                        Log::error('TikTok sync: Shop cipher retrieval failed with unexpected exception', [
                            'store_id' => $store->store_id,
                            'error' => $errorMessage,
                            'error_code' => $e->getCode(),
                            'exception_class' => get_class($e),
                            'trace' => $e->getTraceAsString(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ]);
                        break; // Exit retry loop
                    }
                    } // End while retry loop
                    
                    if (!$shopCipher) {
                        $this->error("❌ Failed to get shop cipher after {$maxRetries} attempts.");
                        continue; // Skip to next store
                    }
                }
                
                $this->info("✅ Shop cipher retrieved: {$shopCipher}");

                // 3️⃣ Fetch orders list
                $response = $tiktok->fetchOrders($accessToken, $shopCipher);
                $orders = $response['orders'] ?? [];

                Log::info('TikTok Orders Fetched', [
                    'store_id' => $store->store_id,
                    'orders_count' => count($orders ?? []),
                    'time_range_days' => 30
                ]);

                if (empty($orders)) {
                    $this->info("⚠️ No TikTok orders found for store {$store->store_name} in the last 30 days.");
                    Log::info("TikTok order sync: no orders found for store_id {$store->store_id}", [
                        'time_range_days' => 30
                    ]);
                    continue;
                }

                $this->info("📦 Found " . count($orders) . " TikTok orders to sync for store {$store->store_name}");

                $storeCount = 0;
                foreach ($orders as $orderData) {
                try {
                    $orderId = $orderData['id'] ?? null;
                    if (!$orderId) continue;

                    // Check if order already exists
                    $existingOrder = Order::where('marketplace_order_id', $orderId)
                        ->where('marketplace', 'tiktok')
                        ->first();

                    // Only skip if order is already shipped AND we're not updating it
                    // This allows us to still sync order details and items for shipped orders if needed
                    $isShipped = $existingOrder && in_array(strtolower($existingOrder->order_status), ['shipped', 'delivered']);
                    
                    if ($isShipped) {
                        $this->info("ℹ️ Order {$orderId} already shipped - updating metadata only (skipping item sync).");
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
                            'store_id' => $store->store_id,
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

                    // Always sync order items for non-shipped orders to ensure they're up to date
                    // This fixes the issue where existing orders might be missing items
                    if (!$isShipped) {
                        $itemsSynced = 0;
                        foreach ($items as $item) {
                            $qty = $item['quantity'] ?? 1; // Use actual quantity from API instead of hardcoded 1
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
                            $itemsSynced++;
                        }
                        
                        // Log if we're adding items to an existing order (helps identify missing items issue)
                        if ($existingOrder && $itemsSynced > 0) {
                            $existingItemsCount = OrderItem::where('order_id', $orderModel->id)->count();
                            if ($existingItemsCount == $itemsSynced) {
                                Log::info('TikTok order items synced (existing order)', [
                                    'order_id' => $orderId,
                                    'store_id' => $store->store_id,
                                    'items_synced' => $itemsSynced
                                ]);
                            }
                        }
                    } else {
                        // For shipped orders, log that items are being skipped
                        Log::info('TikTok order items skipped (order already shipped)', [
                            'order_id' => $orderId,
                            'store_id' => $store->store_id,
                            'items_count' => count($items)
                        ]);
                    }

                    DB::commit();
                    $storeCount++;
                    Log::info('✅ TikTok order synced', [
                        'order_id' => $orderId,
                        'store_id' => $store->store_id
                    ]);

                } catch (Exception $ex) {
                    DB::rollBack();
                    $this->error("❌ Error syncing TikTok order {$orderId}: " . $ex->getMessage());
                    Log::error('Error syncing TikTok order', [
                        'order_id' => $orderData['id'] ?? null,
                        'store_id' => $store->store_id,
                        'error' => $ex->getMessage(),
                        'trace' => $ex->getTraceAsString(),
                        'order_data' => $orderData, // Include order data for debugging
                    ]);
                    // Continue processing other orders instead of stopping
                }
                }

                $totalCount += $storeCount;
                $this->info("✅ Synced {$storeCount} TikTok orders for store {$store->store_name}!");
                Log::info('TikTok order sync completed for store', [
                    'store_id' => $store->store_id,
                    'store_name' => $store->store_name,
                    'count' => $storeCount
                ]);

            } catch (Exception $e) {
                $this->error("❌ TikTok order sync failed for store {$store->store_name}: " . $e->getMessage());
                Log::error('TikTok order sync failed for store', [
                    'store_id' => $store->store_id,
                    'store_name' => $store->store_name,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                continue;
            }
        }

        if ($totalCount > 0) {
            $this->info("✅ Synced {$totalCount} TikTok orders successfully across all stores!");
        } else {
            $this->warn('⚠️ No TikTok orders were synced.');
        }
        
        Log::info('TikTok order sync completed', ['total_count' => $totalCount]);

        return Command::SUCCESS;
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
