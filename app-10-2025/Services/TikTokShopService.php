<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Str;

class TikTokShopService
{
    protected $clientKey;
    protected $clientSecret;
    protected $apiBase;
    protected $authBase;
    protected $shopId;
    protected $logChannel;

    public function __construct()
    {
        $this->clientKey    = config('tiktok.client_key');
        $this->clientSecret = config('tiktok.client_secret');
        $this->apiBase      = rtrim(config('tiktok.api_base'), '/');
        $this->authBase     = rtrim(config('tiktok.auth_base'), '/');
        $this->shopId       = config('tiktok.shop_id');
        $this->logChannel   = config('tiktok.log_channel', Log::getDefaultDriver());
    }

    /**
     * Retrieve access token (cached). If missing, throw — you must obtain initial tokens
     * via OAuth/partner flow or store them in DB/env.
     */
    public function getAccessToken(): string
    {
        $cacheKey = "tiktok:access_token:{$this->shopId}";
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Fallback: attempt refresh token flow if refresh token cached
        $refreshKey = "tiktok:refresh_token:{$this->shopId}";
        if (Cache::has($refreshKey)) {
            $this->refreshAccessToken(Cache::get($refreshKey));
            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }
        }

        throw new Exception('TikTok access token not found. Complete OAuth flow to obtain tokens.');
    }

    /**
     * Use refresh token to get a new access token. Store in cache (or in DB in production).
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $endpoint = $this->authBase . '/api/v2/token/get'; // partner docs indicate similar path
        $params = [
            'app_key'      => $this->clientKey,
            'app_secret'   => $this->clientSecret,
            'grant_type'   => 'refresh_token',
            'refresh_token'=> $refreshToken,
        ];

        $resp = Http::asForm()->post($endpoint, $params);

        if (!$resp->successful()) {
            Log::channel($this->logChannel)->error('TikTok refresh token failed', [
                'status' => $resp->status(), 'body' => $resp->body()
            ]);
            throw new Exception('Failed refreshing TikTok token: '.$resp->body());
        }

        $data = $resp->json();

        // Example response shape will contain access_token, refresh_token, expire_time etc.
        if (isset($data['data']['access_token'])) {
            $accessToken = $data['data']['access_token'];
            $refreshToken = $data['data']['refresh_token'] ?? $refreshToken;
            $expiresIn = $data['data']['expire_in'] ?? (config('tiktok.token_expire_days', 7) * 86400);

            $cacheKey = "tiktok:access_token:{$this->shopId}";
            Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 60));

            $refreshKey = "tiktok:refresh_token:{$this->shopId}";
            Cache::put($refreshKey, $refreshToken, now()->addDays(30));

            return $data;
        }

        Log::channel($this->logChannel)->error('TikTok refresh token response missing access token', ['response' => $data]);
        throw new Exception('Invalid token response from TikTok.');
    }

    /**
     * Exchange an auth code for tokens. Call once when user completes OAuth.
     */
    public function exchangeAuthCode(string $authCode): array
    {
        // OAuth token v2 endpoint (open.tiktokapis.com style) or partner endpoint depending on flow
        $endpoint = $this->authBase . '/api/v2/token/get';
        $params = [
            'app_key'     => $this->clientKey,
            'app_secret'  => $this->clientSecret,
            'grant_type'  => 'authorization_code',
            'auth_code'   => $authCode,
            'redirect_uri'=> config('tiktok.redirect_uri'),
        ];

        $resp = Http::asForm()->post($endpoint, $params);
        if (!$resp->successful()) {
            Log::channel($this->logChannel)->error('TikTok exchangeAuthCode failed', ['body' => $resp->body()]);
            throw new Exception('Failed to exchange auth code: '.$resp->body());
        }

        $data = $resp->json();
        // save access + refresh tokens similar to refreshAccessToken
        if (isset($data['data']['access_token'])) {
            $accessToken = $data['data']['access_token'];
            $refreshToken = $data['data']['refresh_token'] ?? null;
            $expiresIn = $data['data']['expire_in'] ?? (config('tiktok.token_expire_days', 7) * 86400);

            $cacheKey = "tiktok:access_token:{$this->shopId}";
            Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn - 60));
            if ($refreshToken) {
                $refreshKey = "tiktok:refresh_token:{$this->shopId}";
                Cache::put($refreshKey, $refreshToken, now()->addDays(30));
            }

            return $data;
        }

        throw new Exception('Unexpected token response: '.json_encode($data));
    }

    /**
     * Get order list (paginated). Use POST if partner docs require POST for order endpoints.
     * Request shape is adjustable; refer to TikTok docs for exact param names.
     */
    public function getOrderList(array $params = []): array
    {
        $accessToken = $this->getAccessToken();
        $endpoint = $this->apiBase . '/order/getOrderList'; // adjust path as per your API version
        $payload = array_merge([
            'shop_id' => $this->shopId,
            'page_size' => 50,
            'cursor' => 0,
            'start_time' => null,
            'end_time' => null,
        ], $params);

        return $this->callWithRetry('post', $endpoint, $payload, $accessToken);
    }

    /**
     * Get order details.
     */
    public function getOrderDetail(string $orderId): array
    {
        $accessToken = $this->getAccessToken();
        $endpoint = $this->apiBase . '/order/getOrderDetail';
        $payload = [
            'shop_id' => $this->shopId,
            'order_id' => $orderId,
        ];

        return $this->callWithRetry('post', $endpoint, $payload, $accessToken);
    }

    /**
     * Generic call wrapper with basic retry/rate-limit handling.
     */
    protected function callWithRetry(string $method, string $endpoint, array $payload, string $accessToken, int $tries = 3)
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $tries) {
            $attempt++;

            $resp = Http::withHeaders([
                'x-tts-access-token' => $accessToken, // some TikTok Shop docs require custom header
                'Content-Type' => 'application/json',
            ])->timeout(30)->$method($endpoint, $payload);

            // handle token expiry / need to refresh
            if ($resp->status() == 401 || $resp->status() == 403) {
                // try refreshing once
                try {
                    $refreshKey = "tiktok:refresh_token:{$this->shopId}";
                    if (Cache::has($refreshKey)) {
                        $this->refreshAccessToken(Cache::get($refreshKey));
                        $accessToken = $this->getAccessToken();
                        continue;
                    }
                } catch (Exception $e) {
                    Log::channel($this->logChannel)->error('TikTok token refresh failed inside callWithRetry', ['err' => $e->getMessage()]);
                    throw $e;
                }
            }

            // 2xx success
            if ($resp->successful()) {
                return $resp->json();
            }

            // Rate limit handling (429) or server error: wait and retry
            if ($resp->status() == 429 || $resp->serverError()) {
                $wait = pow(2, $attempt);
                sleep($wait);
                continue;
            }

            // For other client errors, log and break
            Log::channel($this->logChannel)->error('TikTok API error', [
                'endpoint' => $endpoint,
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);

            $lastException = new Exception("TikTok API error: {$resp->status()}");
        }

        throw $lastException ?? new Exception('TikTok API unknown error');
    }

    /**
     * Verify webhook signature (if using webhooks). Basic example — check docs for exact header name & algorithm.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        // Many TikTok docs suggest HMAC/SHA256 using your app secret or webhook secret.
        $expected = hash_hmac('sha256', $payload, $this->clientSecret);
        return hash_equals($expected, $signatureHeader);
    }
}
