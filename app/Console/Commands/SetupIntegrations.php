<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SetupIntegrations extends Command
{
    protected $signature = 'integrations:setup {--ebay-store-id=3} {--tiktok-store-id=10}';
    protected $description = 'Setup TikTok and eBay integrations from environment variables';

    public function handle()
    {
        $this->info('🔧 Setting up integrations...');

        // Setup eBay1 (store_id 3)
        $ebayStoreId = $this->option('ebay-store-id');
        $this->setupEbay($ebayStoreId);

        // Setup TikTok (store_id 10)
        $tiktokStoreId = $this->option('tiktok-store-id');
        $this->setupTikTok($tiktokStoreId);

        $this->info('✅ Integration setup completed!');
        return 0;
    }

    private function setupEbay(int $storeId)
    {
        $this->info("Setting up eBay integration for store ID {$storeId}...");

        // Get credentials from environment variables - try multiple possible names
        $appId = env('EBAY_APP_ID') 
            ?? env('EBAY1_APP_ID') 
            ?? env('EBAY_CLIENT_ID')
            ?? env('EBAY1_CLIENT_ID');
            
        $appSecret = env('EBAY_APP_SECRET') 
            ?? env('EBAY1_APP_SECRET')
            ?? env('EBAY_CLIENT_SECRET')
            ?? env('EBAY1_CLIENT_SECRET');
            
        $refreshToken = env('EBAY_REFRESH_TOKEN') 
            ?? env('EBAY1_REFRESH_TOKEN');

        if (!$appId || !$appSecret || !$refreshToken) {
            $this->error("❌ Missing eBay credentials. Required environment variables:");
            $this->warn("   - EBAY_APP_ID / EBAY1_APP_ID / EBAY_CLIENT_ID");
            $this->warn("   - EBAY_APP_SECRET / EBAY1_APP_SECRET / EBAY_CLIENT_SECRET");
            $this->warn("   - EBAY_REFRESH_TOKEN / EBAY1_REFRESH_TOKEN");
            $this->newLine();
            $this->info("💡 You can also set these directly in the database using:");
            $this->line("   php artisan tinker");
            $this->line("   DB::table('integrations')->updateOrInsert(['store_id' => {$storeId}], ['app_id' => 'YOUR_APP_ID', 'app_secret' => 'YOUR_APP_SECRET', 'refresh_token' => 'YOUR_REFRESH_TOKEN']);");
            return;
        }

        // Use updateOrInsert to handle both create and update
        DB::table('integrations')->updateOrInsert(
            ['store_id' => $storeId],
            [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'refresh_token' => $refreshToken,
                'access_token' => '', // Will be set when token is refreshed
                'updated_at' => now(),
            ]
        );
        
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();
        if ($integration && $integration->created_at) {
            $this->info("✅ Updated eBay integration for store ID {$storeId}");
        } else {
            DB::table('integrations')->where('store_id', $storeId)->update(['created_at' => now()]);
            $this->info("✅ Created eBay integration for store ID {$storeId}");
        }

        Log::info('eBay integration setup', [
            'store_id' => $storeId,
            'app_id_set' => !empty($appId),
            'app_secret_set' => !empty($appSecret),
            'refresh_token_set' => !empty($refreshToken),
        ]);
    }

    private function setupTikTok(int $storeId)
    {
        $this->info("Setting up TikTok integration for store ID {$storeId}...");

        // Get credentials from environment variables - try multiple possible names
        $appId = env('TIKTOK_APP_ID') 
            ?? env('TIKTOK_CLIENT_KEY') 
            ?? env('TIKTOK_CLIENT_ID')
            ?? env('TIKTOK_APP_KEY');
            
        $appSecret = env('TIKTOK_APP_SECRET') 
            ?? env('TIKTOK_CLIENT_SECRET');
            
        $refreshToken = env('TIKTOK_REFRESH_TOKEN');
        $redirectUri = env('TIKTOK_REDIRECT_URI');

        if (!$appId || !$appSecret) {
            $this->error("❌ Missing TikTok credentials. Required environment variables:");
            $this->warn("   - TIKTOK_APP_ID / TIKTOK_CLIENT_KEY / TIKTOK_CLIENT_ID / TIKTOK_APP_KEY");
            $this->warn("   - TIKTOK_APP_SECRET / TIKTOK_CLIENT_SECRET");
            if (!$refreshToken) {
                $this->warn("   - TIKTOK_REFRESH_TOKEN (required for syncing orders)");
            }
            $this->newLine();
            $this->info("💡 You can also set these directly in the database using:");
            $this->line("   php artisan tinker");
            $this->line("   DB::table('integrations')->updateOrInsert(['store_id' => {$storeId}], ['app_id' => 'YOUR_APP_ID', 'app_secret' => 'YOUR_APP_SECRET', 'refresh_token' => 'YOUR_REFRESH_TOKEN']);");
            return;
        }

        // Prepare update data
        $updateData = [
            'app_id' => $appId,
            'app_secret' => $appSecret,
            'updated_at' => now(),
        ];

        if ($refreshToken) {
            $updateData['refresh_token'] = $refreshToken;
        } else {
            $updateData['refresh_token'] = ''; // Empty string if not provided
        }

        if ($redirectUri) {
            // Check if redirect_uri column exists
            $columns = DB::select("SHOW COLUMNS FROM integrations LIKE 'redirect_uri'");
            if (!empty($columns)) {
                $updateData['redirect_uri'] = $redirectUri;
            }
        }

        // Use updateOrInsert to handle both create and update
        DB::table('integrations')->updateOrInsert(
            ['store_id' => $storeId],
            array_merge($updateData, [
                'access_token' => '', // Will be set when token is refreshed
            ])
        );
        
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();
        if ($integration && $integration->created_at) {
            $this->info("✅ Updated TikTok integration for store ID {$storeId}");
        } else {
            DB::table('integrations')->where('store_id', $storeId)->update(['created_at' => now()]);
            $this->info("✅ Created TikTok integration for store ID {$storeId}");
        }

        if (!$refreshToken) {
            $this->warn("⚠️  TikTok refresh_token not provided. You may need to authenticate to get one.");
        }

        Log::info('TikTok integration setup', [
            'store_id' => $storeId,
            'app_id_set' => !empty($appId),
            'app_secret_set' => !empty($appSecret),
            'refresh_token_set' => !empty($refreshToken),
        ]);
    }
}

