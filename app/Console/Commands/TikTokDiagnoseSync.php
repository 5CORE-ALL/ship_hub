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

class TikTokDiagnoseSync extends Command
{
    protected $signature = 'tiktok:diagnose {--store= : Diagnose a specific store ID only} {--days=30 : Number of days to check back}';
    protected $description = 'Diagnose TikTok sync issues - check for missing orders, verify integrations, and compare API vs database';

    public function handle()
    {
        $storeId = $this->option('store');
        $days = (int) $this->option('days');
        
        $this->info('🔍 TikTok Sync Diagnostic Tool');
        $this->info('================================');
        $this->newLine();

        // Query TikTok stores
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
            return Command::FAILURE;
        }

        foreach ($stores as $store) {
            $this->info("📦 Store: {$store->store_name} (ID: {$store->store_id})");
            $this->info(str_repeat('-', 60));
            
            // 1. Check Integration Status
            $this->checkIntegration($store);
            
            // 2. Test API Connection
            $apiOrders = $this->testApiConnection($store, $days);
            
            if ($apiOrders === null) {
                $this->warn("⚠️ Skipping database comparison due to API connection failure.");
                $this->newLine();
                continue;
            }
            
            // 3. Compare with Database
            $this->compareWithDatabase($store, $apiOrders, $days);
            
            // 4. Check for Orders with Missing Items
            $this->checkMissingItems($store);
            
            $this->newLine();
        }

        $this->info('✅ Diagnostic completed!');
        return Command::SUCCESS;
    }

    private function checkIntegration($store)
    {
        $this->info('1️⃣ Integration Status:');
        
        $checks = [
            'Refresh Token' => !empty($store->refresh_token),
            'App ID' => !empty($store->app_id),
            'App Secret' => !empty($store->app_secret),
            'Access Token' => !empty($store->access_token),
            'Shop Cipher' => !empty($store->shop_cipher),
        ];
        
        $allGood = true;
        foreach ($checks as $check => $status) {
            $icon = $status ? '✅' : '❌';
            $this->line("   {$icon} {$check}: " . ($status ? 'Present' : 'Missing'));
            if (!$status) $allGood = false;
        }
        
        if ($store->expires_at) {
            $expiresAt = Carbon::parse($store->expires_at);
            $isExpired = $expiresAt->isPast();
            $icon = $isExpired ? '⚠️' : '✅';
            $this->line("   {$icon} Token Expires: {$expiresAt->format('Y-m-d H:i:s')} " . ($isExpired ? '(EXPIRED)' : '(Valid)'));
            if ($isExpired) $allGood = false;
        }
        
        if (!$allGood) {
            $this->warn('   ⚠️ Integration has missing or expired credentials!');
        }
        
        $this->newLine();
    }

    private function testApiConnection($store, $days)
    {
        $this->info('2️⃣ Testing API Connection:');
        
        try {
            if (!$store->refresh_token || !$store->app_id || !$store->app_secret) {
                $this->error('   ❌ Missing required credentials (refresh_token, app_id, app_secret)');
                return null;
            }

            $tiktok = new TikTokAuthService($store->store_id);
            
            // Get access token
            $forceRefresh = false;
            if ($store->expires_at && Carbon::parse($store->expires_at)->isPast()) {
                $this->warn('   🔄 Token expired, attempting refresh...');
                $forceRefresh = true;
            }
            
            $accessToken = $tiktok->getAccessToken($store->store_id, $forceRefresh);
            if (!$accessToken) {
                $this->error('   ❌ Failed to get access token');
                return null;
            }
            $this->info('   ✅ Access token retrieved');
            
            // Get shop cipher
            $shopCipher = $store->shop_cipher;
            if (!$shopCipher) {
                $this->warn('   ⚠️ Shop cipher not in database, attempting to fetch...');
                try {
                    $shopCipher = $tiktok->getAuthorizedShopCipher($store->store_id, $accessToken);
                    if ($shopCipher) {
                        $this->info("   ✅ Shop cipher retrieved: {$shopCipher}");
                    } else {
                        $this->error('   ❌ Failed to get shop cipher');
                        return null;
                    }
                } catch (Exception $e) {
                    $this->error('   ❌ Error getting shop cipher: ' . $e->getMessage());
                    return null;
                }
            } else {
                $this->info("   ✅ Shop cipher found: {$shopCipher}");
            }
            
            // Fetch orders
            $this->info("   📥 Fetching orders from last {$days} days...");
            $response = $tiktok->fetchOrders($accessToken, $shopCipher);
            $orders = $response['orders'] ?? [];
            
            $this->info("   ✅ API Connection successful!");
            $totalOrders = $response['total'] ?? count($orders);
            $this->info("   📊 Found {$totalOrders} orders in API");
            
            $this->newLine();
            return $orders;
            
        } catch (TokenException $e) {
            $this->error('   ❌ Token Error: ' . $e->getMessage());
            return null;
        } catch (ResponseException $e) {
            $this->error('   ❌ API Response Error: ' . $e->getMessage());
            return null;
        } catch (Exception $e) {
            $this->error('   ❌ Unexpected Error: ' . $e->getMessage());
            Log::error('TikTok diagnostic API test failed', [
                'store_id' => $store->store_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    private function compareWithDatabase($store, $apiOrders, $days)
    {
        $this->info('3️⃣ Comparing API vs Database:');
        
        // Get order IDs from API
        $apiOrderIds = [];
        foreach ($apiOrders as $order) {
            if (isset($order['id'])) {
                $apiOrderIds[] = $order['id'];
            }
        }
        
        $this->info("   📊 API Orders: " . count($apiOrderIds));
        
        // Get orders from database for this store
        $dbOrders = Order::where('marketplace', 'tiktok')
            ->where('store_id', $store->store_id)
            ->where('order_date', '>=', now()->subDays($days))
            ->pluck('marketplace_order_id')
            ->toArray();
        
        $this->info("   💾 Database Orders: " . count($dbOrders));
        
        // Find missing orders
        $missingInDb = array_diff($apiOrderIds, $dbOrders);
        $extraInDb = array_diff($dbOrders, $apiOrderIds);
        
        if (empty($missingInDb) && empty($extraInDb)) {
            $this->info('   ✅ All orders are synced correctly!');
        } else {
            if (!empty($missingInDb)) {
                $this->error("   ❌ Missing in Database: " . count($missingInDb) . " orders");
                $this->warn('   Missing Order IDs:');
                $displayCount = min(10, count($missingInDb));
                foreach (array_slice($missingInDb, 0, $displayCount) as $orderId) {
                    $this->line("      - {$orderId}");
                }
                if (count($missingInDb) > $displayCount) {
                    $this->line("      ... and " . (count($missingInDb) - $displayCount) . " more");
                }
                
                // Get details of missing orders from API
                $this->newLine();
                $this->info('   📋 Details of missing orders:');
                $missingDetails = [];
                foreach ($apiOrders as $order) {
                    if (in_array($order['id'] ?? null, $missingInDb)) {
                        $status = $order['status'] ?? 'Unknown';
                        $createTime = isset($order['create_time']) 
                            ? Carbon::createFromTimestamp($order['create_time'])->format('Y-m-d H:i:s')
                            : 'Unknown';
                        $missingDetails[] = [
                            'id' => $order['id'],
                            'status' => $status,
                            'created' => $createTime,
                        ];
                    }
                }
                
                $table = [];
                foreach (array_slice($missingDetails, 0, 10) as $detail) {
                    $table[] = [
                        'Order ID' => $detail['id'],
                        'Status' => $detail['status'],
                        'Created' => $detail['created'],
                    ];
                }
                
                if (!empty($table)) {
                    $this->table(['Order ID', 'Status', 'Created'], $table);
                }
            }
            
            if (!empty($extraInDb)) {
                $this->warn("   ⚠️ Extra in Database (not in API): " . count($extraInDb) . " orders");
                $this->warn('   These orders exist in DB but not in API response (may be older than ' . $days . ' days)');
            }
        }
        
        $this->newLine();
    }

    private function checkMissingItems($store)
    {
        $this->info('4️⃣ Checking Orders with Missing Items:');
        
        // Find orders without items
        $ordersWithoutItems = DB::table('orders')
            ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.marketplace', 'tiktok')
            ->where('orders.store_id', $store->store_id)
            ->where('orders.order_date', '>=', now()->subDays(30))
            ->whereNull('order_items.id')
            ->select('orders.id', 'orders.order_number', 'orders.marketplace_order_id', 'orders.order_status', 'orders.order_date')
            ->get();
        
        if ($ordersWithoutItems->isEmpty()) {
            $this->info('   ✅ All orders have items');
        } else {
            $this->error("   ❌ Found " . $ordersWithoutItems->count() . " orders without items:");
            
            $table = [];
            foreach ($ordersWithoutItems->take(20) as $order) {
                $table[] = [
                    'Order ID' => $order->marketplace_order_id ?? $order->order_number,
                    'Status' => $order->order_status,
                    'Date' => $order->order_date ? Carbon::parse($order->order_date)->format('Y-m-d') : 'N/A',
                ];
            }
            
            $this->table(['Order ID', 'Status', 'Date'], $table);
            
            if ($ordersWithoutItems->count() > 20) {
                $this->warn("   ... and " . ($ordersWithoutItems->count() - 20) . " more orders without items");
            }
        }
        
        // Find orders with incomplete item data
        $ordersWithIncompleteItems = DB::table('orders')
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.marketplace', 'tiktok')
            ->where('orders.store_id', $store->store_id)
            ->where('orders.order_date', '>=', now()->subDays(30))
            ->where(function($query) {
                $query->whereNull('order_items.sku')
                      ->orWhere('order_items.sku', '=', '')
                      ->orWhereNull('order_items.item_name')
                      ->orWhere('order_items.quantity_ordered', '<=', 0);
            })
            ->select('orders.id', 'orders.order_number', 'orders.marketplace_order_id', 'order_items.sku', 'order_items.quantity_ordered')
            ->distinct()
            ->get();
        
        if (!$ordersWithIncompleteItems->isEmpty()) {
            $this->warn("   ⚠️ Found " . $ordersWithIncompleteItems->count() . " orders with incomplete item data");
        }
        
        $this->newLine();
    }
}
