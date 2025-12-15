<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TikTokAuthService
{
    protected string $clientKey;
    protected string $clientSecret;
    protected string $redirectUri;
    protected string $apiBase;

    public function __construct()
    {
        $this->clientKey = config('tiktok.client_key');
        $this->clientSecret = config('tiktok.client_secret');
        $this->redirectUri = config('tiktok.redirect_uri');
        $this->apiBase = config('tiktok.api_base');
    }

    /**
     * Exchange authorization code for access token
     */
    // public function getAccessToken(string $authCode): ?array
    // {
    //     $endpoint = "{$this->apiBase}/v2/oauth/token";

    //     $response = Http::asForm()->post($endpoint, [
    //         'app_key'      => $this->clientKey,
    //         'app_secret'   => $this->clientSecret,
    //         'auth_code'    => $authCode,
    //         'grant_type'   => 'authorized_code',
    //     ]);

    //     if ($response->failed()) {
    //         Log::error('TikTok OAuth Error', [
    //             'body' => $response->body(),
    //             'status' => $response->status(),
    //         ]);
    //         return null;
    //     }

    //     return $response->json();
    // }
    public function getAccessToken(int $storeId): ?string
    {
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();

        if (!$integration || !$integration->refresh_token) {
            return null;
        }
        if (
            !empty($integration->access_token) &&
            $integration->expires_at &&
            Carbon::parse($integration->expires_at)->gt(now())
        ) {
            return $integration->access_token;
        }
        try {
            $endpoint = 'https://open.tiktokapis.com/v2/oauth/token/';

            $response = Http::asForm()->post($endpoint, [
                'client_key'    => $this->clientKey,
                'client_secret' => $this->clientSecret,
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
            ]);

            if ($response->failed()) {
                Log::error("TikTok token refresh failed for store {$storeId}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();

            Log::info('TikTok token refresh response', [
                'store_id' => $storeId,
                'response' => $data,
            ]);

            if (!empty($data['access_token'])) {
                $expiry = isset($data['expires_in'])
                    ? now()->addSeconds($data['expires_in'])
                    : now()->addDays(7);

                DB::table('integrations')
                    ->where('store_id', $storeId)
                    ->update([
                        'access_token' => $data['access_token'],
                        'expires_at'   => $expiry,
                        'updated_at'   => now(),
                    ]);

                return $data['access_token'];
            }

            Log::warning("TikTok token refresh missing access_token for store {$storeId}", [
                'response' => $data,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error("TikTok token refresh error for store {$storeId}", [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    protected function getShopId(int $storeId): string
    {
         return config('tiktok.shop_id');
    }
public function fetchOrders(int $storeId, string $startTime = null, string $endTime = null): ?array
{
    $accessToken = $this->getAccessToken($storeId);
    if (!$accessToken) {
        Log::error("TikTok fetchOrders: Missing valid access token for store {$storeId}");
        return null;
    }

    // TikTok App credentials
    $appKey = config('services.tiktok.app_key', env('TIKTOK_APP_KEY', ''));
    $appSecret = config('services.tiktok.app_secret', env('TIKTOK_APP_SECRET', ''));
    $shopId = $this->getShopId($storeId);
    $version = '202212';
    $path = '/api/orders/search';
    $timestamp = time();

    // Default to last 2 days if not provided
    $startTime = $startTime ?? now()->subDays(2)->toIso8601String();
    $endTime = $endTime ?? now()->toIso8601String();

    // --- 1️⃣ Prepare query params (sorted by key in ASCII order)
    $params = [
        'app_key'   => $appKey,
        'shop_id'   => $shopId,
        'timestamp' => $timestamp,
        'version'   => $version,
    ];
    ksort($params);

    // --- 2️⃣ Prepare POST body as per TikTok API
    $body = [
        'create_time_ge' => $startTime,
        'create_time_le' => $endTime,
        'page_size'      => 50,
        'sort_field'     => 'create_time',
        'sort_order'     => 'DESC',
    ];
    $bodyJson = json_encode($body, JSON_UNESCAPED_UNICODE);

    // --- 3️⃣ Build sign string (include path + sorted query + body + secret)
    $signString = $appSecret . $path;
    foreach ($params as $k => $v) {
        $signString .= $k . $v;
    }
    $signString .= $bodyJson;
    $signString .= $appSecret;

    // --- 4️⃣ Generate sign (uppercase SHA256)
    $sign = strtoupper(hash('sha256', $signString));

    // --- 5️⃣ Build full query with access_token and sign
    $query = http_build_query(array_merge($params, [
        'access_token' => $accessToken,
        'sign' => $sign,
    ]));

    $endpoint = "https://open-api.tiktokglobalshop.com{$path}?{$query}";

    try {
        $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($endpoint, $body);

        if ($response->failed()) {
            Log::error("TikTok order fetch failed for store {$storeId}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();

        Log::info("TikTok orders fetched successfully for store {$storeId}", [
            'count' => $data['data']['total'] ?? 0,
        ]);

        return $data;
    } catch (\Exception $e) {
        Log::error("TikTok order fetch error for store {$storeId}", [
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}


}
