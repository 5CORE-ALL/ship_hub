<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EbayNotificationSync extends Command
{
    protected $signature = 'ebay:sync-notifications';
    protected $description = 'Fetch and process eBay notifications (messages, updates) for all eBay stores';

    public function handle()
    {
        $this->info('🔹 Starting eBay notification sync for all stores...');

        $stores = DB::table('stores as s')
            ->join('sales_channels as sc', 's.sales_channel_id', '=', 'sc.id')
            ->join('marketplaces as m', 's.marketplace_id', '=', 'm.id')
            ->leftJoin('integrations as i', 's.id', '=', 'i.store_id')
            ->where('sc.platform', 'ebay')
            ->select(
                's.id as store_id',
                's.name as store_name',
                'i.access_token',
                'i.refresh_token',
                'i.app_id',
                'i.app_secret',
                'i.expires_at'
            )
            ->get();

        if ($stores->isEmpty()) {
            $this->error('⚠️ No eBay stores found.');
            return 1;
        }

        foreach ($stores as $store) {
            $this->info("Processing store: {$store->store_name} (ID: {$store->store_id})");

            if (!$store->access_token || !$store->refresh_token || !$store->app_id || !$store->app_secret) {
                $this->warn("⚠️ Missing integration data for store {$store->store_name}. Skipping.");
                continue;
            }

            // Refresh token if expired
            if ($store->expires_at && Carbon::parse($store->expires_at)->lt(now())) {
                $this->info("🔄 Refreshing access token for store {$store->store_name}");
                $response = Http::asForm()->withBasicAuth($store->app_id, $store->app_secret)
                    ->post('https://api.ebay.com/identity/v1/oauth2/token', [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $store->refresh_token,
                        'scope' => 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    DB::table('integrations')->where('store_id', $store->store_id)->update([
                        'access_token' => $data['access_token'],
                        'expires_at' => now()->addSeconds($data['expires_in']),
                    ]);
                    $store->access_token = $data['access_token'];
                    $this->info("✅ Access token refreshed for store {$store->store_name}");
                } else {
                    $this->warn("❌ Failed to refresh token for store {$store->store_name}: " . $response->body());
                    Log::warning('eBay token refresh failed', [
                        'store_id' => $store->store_id,
                        'response_status' => $response->status(),
                        'response_body' => $response->body(),
                    ]);
                    continue;
                }
            }

            // --- Fetch notifications from eBay REST Notification API ---
            $endpoint = "https://api.ebay.com/sell/notification/v1/subscription"; // example
            $this->info("Fetching notifications for store {$store->store_name}...");

            // Example: get list of notifications (you can also fetch messages via MyMessages API)
            $response = Http::withToken($store->access_token)
                ->get("https://api.ebay.com/sell/notification/v1/event", [
                    'limit' => 50,
                ]);

            if ($response->failed()) {
                $this->error("Failed to fetch notifications for store {$store->store_name}: " . $response->body());
                Log::error('eBay notifications fetch failed', [
                    'store_id' => $store->store_id,
                    'response_status' => $response->status(),
                    'response_body' => $response->body(),
                ]);
                continue;
            }

            $notifications = $response->json()['notifications'] ?? [];

            foreach ($notifications as $notification) {
                $this->info("Processing notification: " . ($notification['eventType'] ?? 'unknown'));

                // Example: fetch full message if notification is about a new message
                if (isset($notification['payload']['messageId'])) {
                    $messageId = $notification['payload']['messageId'];

                    $messageResponse = Http::withToken($store->access_token)
                        ->get("https://api.ebay.com/ws/api.dll", [
                            'callname' => 'GetMyMessages',
                            'messageId' => $messageId
                        ]);

                    if ($messageResponse->successful()) {
                        $message = $messageResponse->json();
                        Log::info('Fetched full eBay message', [
                            'store_id' => $store->store_id,
                            'message_id' => $messageId,
                            'message' => $message
                        ]);
                    }
                }
            }

            $this->info("✅ Completed processing notifications for store {$store->store_name}");
        }

        $this->info('✅ eBay notification sync completed for all stores!');
        return 0;
    }
}
