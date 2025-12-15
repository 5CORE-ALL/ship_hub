<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EbayMessageSync extends Command
{
    protected $signature = 'ebay:sync-messages';
    protected $description = 'Fetch eBay messages for all stores (polling, no webhooks)';

    public function handle()
    {
        $this->info('🔹 Starting eBay message sync...');

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
                $this->warn("⚠️ Missing integration data. Skipping store {$store->store_name}.");
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
                    $this->info("✅ Access token refreshed");
                } else {
                    $this->warn("❌ Failed to refresh token: " . $response->body());
                    Log::warning('eBay token refresh failed', [
                        'store_id' => $store->store_id,
                        'response_status' => $response->status(),
                        'response_body' => $response->body(),
                    ]);
                    continue;
                }
            }

            // Poll messages via Trading API
            $endpoint = "https://api.ebay.com/ws/api.dll";
            $page = 1;
            $limit = 50;
            $hasMore = true;

            while ($hasMore) {
                $this->info("Fetching page {$page} of messages for store {$store->store_name}...");

                $body = '<?xml version="1.0" encoding="utf-8"?>' .
                    '<GetMyMessagesRequest xmlns="urn:ebay:apis:eBLBaseComponents">' .
                    '<DetailLevel>ReturnMessages</DetailLevel>' .
                    '<RequesterCredentials>' .
                    '<eBayAuthToken>' . $store->access_token . '</eBayAuthToken>' .
                    '</RequesterCredentials>' .
                    '<Pagination>' .
                    "<EntriesPerPage>{$limit}</EntriesPerPage>" .
                    "<PageNumber>{$page}</PageNumber>" .
                    '</Pagination>' .
                    '<StartTimeFrom>' . Carbon::now()->subDays(7)->toIso8601String() . '</StartTimeFrom>' .
                    '</GetMyMessagesRequest>';

                $response = Http::withHeaders([
                    'X-EBAY-API-SITEID' => '0',
                    'X-EBAY-API-CALL-NAME' => 'GetMyMessages',
                    'X-EBAY-API-COMPATIBILITY-LEVEL' => '1237',
                    'Content-Type' => 'text/xml',
                ])->post($endpoint, $body);
                Log::info('eBay GetMyMessages response', [
    'store_id' => $store->store_id,
    'page' => $page,
    'response_status' => $response->status(),
    'response_body' => $response->body(),
]);

                if ($response->failed()) {
                    $this->error("Failed to fetch messages: " . $response->body());
                    Log::error('eBay message fetch failed', [
                        'store_id' => $store->store_id,
                        'response_status' => $response->status(),
                        'response_body' => $response->body(),
                    ]);
                    break;
                }

                $xml = simplexml_load_string($response->body());
                $messages = $xml->Messages->Message ?? [];

                if (empty($messages)) {
                    $this->info("No new messages on page {$page}");
                    break;
                }

                foreach ($messages as $msg) {
                    $messageId = (string)$msg->MessageID;
                    $subject = (string)$msg->Subject;
                    $body = (string)$msg->Text;
                    $sender = (string)$msg->Sender;
                    $recipient = (string)$msg->RecipientUserID;
                    $creationDate = Carbon::parse((string)$msg->ReceiveDate)->setTimezone('America/New_York');

                    // Save to DB (example table: ebay_messages)
                    DB::table('ebay_messages')->updateOrInsert(
                        ['store_id' => $store->store_id, 'message_id' => $messageId],
                        [
                            'store_id' => $store->store_id,
                            'message_id' => $messageId,
                            'sender' => $sender,
                            'recipient' => $recipient,
                            'subject' => $subject,
                            'body' => $body,
                            'received_at' => $creationDate,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                $page++;
                $hasMore = count($messages) == $limit;
                sleep(1); // polite delay to avoid hitting API limits
            }

            $this->info("✅ Completed message sync for store {$store->store_name}");
        }

        $this->info('✅ eBay message sync completed for all stores!');
        return 0;
    }
}
