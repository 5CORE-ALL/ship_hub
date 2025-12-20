<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SetupTikTokStore extends Command
{
    protected $signature = 'tiktok:setup-store {store_id=10 : The store ID to setup}';
    protected $description = 'Setup TikTok store, sales channel, marketplace, and integration';

    public function handle()
    {
        $storeId = $this->argument('store_id');
        
        try {
            // 1. Create or get TikTok Sales Channel
            $salesChannel = DB::table('sales_channels')->where('platform', 'tiktok')->first();
            if (!$salesChannel) {
                $maxId = DB::table('sales_channels')->max('id') ?? 0;
                DB::table('sales_channels')->insert([
                    'id' => $maxId + 1,
                    'name' => 'TikTok Shop',
                    'platform' => 'tiktok',
                    'status' => 'active',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $salesChannel = DB::table('sales_channels')->where('platform', 'tiktok')->first();
                $this->info("✅ Created TikTok sales channel with ID: {$salesChannel->id}");
            } else {
                $this->info("✅ TikTok sales channel already exists with ID: {$salesChannel->id}");
            }

            // 2. Create or get TikTok Marketplace
            $marketplace = DB::table('marketplaces')->where('name', 'like', '%TikTok%')->first();
            if (!$marketplace) {
                $maxMpId = DB::table('marketplaces')->max('id') ?? 0;
                DB::table('marketplaces')->insert([
                    'id' => $maxMpId + 1,
                    'name' => 'TikTok Shop',
                    'country_code' => 'US',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $marketplace = DB::table('marketplaces')->where('name', 'like', '%TikTok%')->first();
                $this->info("✅ Created TikTok marketplace with ID: {$marketplace->id}");
            } else {
                $this->info("✅ TikTok marketplace already exists with ID: {$marketplace->id}");
            }

            // 3. Create or update Store
            $store = DB::table('stores')->where('id', $storeId)->first();
            if (!$store) {
                DB::table('stores')->insert([
                    'id' => $storeId,
                    'name' => 'TikTok Shop Store',
                    'sales_channel_id' => $salesChannel->id,
                    'marketplace_id' => $marketplace->id,
                    'status' => 'active',
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $this->info("✅ Created TikTok store with ID: {$storeId}");
            } else {
                DB::table('stores')->where('id', $storeId)->update([
                    'sales_channel_id' => $salesChannel->id,
                    'marketplace_id' => $marketplace->id,
                    'updated_at' => Carbon::now(),
                ]);
                $this->info("✅ Updated store {$storeId} to link to TikTok sales channel and marketplace");
            }

            // 4. Update Integration with credentials from environment or use defaults
            $appId = env('TIKTOK_APP_ID', '6hnbadk41irbv');
            $appSecret = env('TIKTOK_APP_SECRET', '768a4552d64cb6daeacec321d8f374d57aeb5779');
            $accessToken = env('TIKTOK_ACCESS_TOKEN', 'TTP_EgdIjQAAAACCL-3LvBUhTci1O-p9PJTYB9rm5MMiRsXvdCv47hcsbcMqoH4kRZKyLSor5BTQKOrRexH9VMn9tfmNHc-bv3c64dtyJJFKZgo3Xj3BfFDySkPERqVLWrVnDbiHCg54rzk0sbThzHWYw44cMxhWDq7-e8tWDNhja2ln0nkeDdXBfQ');
            $refreshToken = env('TIKTOK_REFRESH_TOKEN', 'TTP__LZkCwAAAABJSIKm1cPJ44uIbGSRifnE4D3_GWpwT7YDP2_IArCFYqAHEH_kSRcjUuWRyLALiyk');
            
            $integration = DB::table('integrations')->where('store_id', $storeId)->first();
            if ($integration) {
                DB::table('integrations')->where('store_id', $storeId)->update([
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => Carbon::now()->addDays(7),
                    'updated_at' => Carbon::now(),
                ]);
                $this->info("✅ Updated integration for store {$storeId} with TikTok credentials");
            } else {
                DB::table('integrations')->insert([
                    'store_id' => $storeId,
                    'app_id' => $appId,
                    'app_secret' => $appSecret,
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken,
                    'expires_at' => Carbon::now()->addDays(7),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
                $this->info("✅ Created integration for store {$storeId} with TikTok credentials");
            }

            $this->info("\n✅ TikTok setup completed successfully!");
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $this->error("❌ Error: " . $e->getMessage());
            $this->error("Trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

