<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\TikTokAuthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TikTokDiagnose extends Command
{
    protected $signature = 'tiktok:diagnose {--store=10 : Store ID}';
    protected $description = 'Diagnose TikTok API connection and shop cipher retrieval';

    public function handle()
    {
        $storeId = (int) $this->option('store');
        
        $this->info("🔍 Diagnosing TikTok Integration (Store ID: {$storeId})");
        $this->newLine();
        
        // Check database
        $this->info("1. Checking database integration...");
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();
        
        if (!$integration) {
            $this->error("   ❌ No integration found for store_id {$storeId}");
            return Command::FAILURE;
        }
        
        $this->info("   ✅ Integration found");
        $this->line("      - app_id: " . ($integration->app_id ? substr($integration->app_id, 0, 15) . '...' : 'NOT SET'));
        $this->line("      - app_secret: " . ($integration->app_secret ? 'SET (' . strlen($integration->app_secret) . ' chars)' : 'NOT SET'));
        $this->line("      - access_token: " . ($integration->access_token ? 'SET (' . strlen($integration->access_token) . ' chars)' : 'NOT SET'));
        $this->line("      - refresh_token: " . ($integration->refresh_token ? 'SET (' . strlen($integration->refresh_token) . ' chars)' : 'NOT SET'));
        $this->line("      - shop_cipher: " . ($integration->shop_cipher ?? 'NOT SET'));
        $this->newLine();
        
        // Test service
        $this->info("2. Testing TikTokAuthService...");
        try {
            $service = new TikTokAuthService();
            $this->info("   ✅ Service initialized");
        } catch (\Exception $e) {
            $this->error("   ❌ Failed to initialize service: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        // Get access token
        $this->info("3. Getting access token...");
        $accessToken = $service->getAccessToken($storeId);
        
        if (!$accessToken) {
            $this->error("   ❌ Failed to get access token");
            if (!$integration->refresh_token) {
                $this->warn("   💡 Missing refresh_token - need to authenticate");
            } else {
                $this->warn("   💡 Token refresh may have failed - check logs");
            }
            return Command::FAILURE;
        }
        
        $this->info("   ✅ Access token retrieved: " . substr($accessToken, 0, 30) . "...");
        $this->newLine();
        
        // Get shop cipher
        $this->info("4. Getting shop cipher...");
        $this->line("   (This will make an API call to TikTok)");
        
        try {
            $shopCipher = $service->getAuthorizedShopCipher($storeId, $accessToken);
            
            if ($shopCipher) {
                $this->info("   ✅ Shop cipher retrieved: {$shopCipher}");
                $this->newLine();
                $this->info("🎉 All checks passed! TikTok integration is working.");
                return Command::SUCCESS;
            } else {
                $this->error("   ❌ Failed to get shop cipher");
                $this->warn("   💡 Check logs for detailed API response");
                $this->warn("   💡 The API response structure might be unexpected");
                $this->newLine();
                $this->line("   To view recent logs:");
                $this->comment("   tail -f storage/logs/laravel.log | grep -i tiktok");
                return Command::FAILURE;
            }
        } catch (\Exception $e) {
            $this->error("   ❌ Exception: " . $e->getMessage());
            $this->line("   File: " . $e->getFile() . ":" . $e->getLine());
            return Command::FAILURE;
        }
    }
}

