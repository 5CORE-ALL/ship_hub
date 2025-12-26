<?php

namespace App\Services;

use EcomPHP\TiktokShop\Client;
use Illuminate\Support\Facades\Log;
use Exception;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class TikTokAuthService
{
    protected string $clientKey;
    protected string $clientSecret;
    protected string $redirectUri;
    protected ?Client $client = null;

    public function __construct(?int $storeId = null)
    {
        // Try to load credentials from the specified store, or default to store_id 10 for backward compatibility
        $storeId = $storeId ?? 10;
        $integration = \App\Models\Integration::where('store_id', $storeId)->first();

        if ($integration && $integration->app_id && $integration->app_secret) {
            $this->clientKey = $integration->app_id;
            $this->clientSecret = $integration->app_secret;
            $this->redirectUri = $integration->redirect_uri ?? '';
        } else {
            // Fallback to config if not found
            $this->clientKey = config('tiktok.client_key') ?? '';
            $this->clientSecret = config('tiktok.client_secret') ?? '';
            $this->redirectUri = config('tiktok.redirect_uri') ?? '';
        }

        $this->client = new \EcomPHP\TiktokShop\Client($this->clientKey, $this->clientSecret);
    }
    public function getAuthUrl(): string
    {
        $state = bin2hex(random_bytes(16)); // secure random string
        session(['tiktok_state' => $state]);

        $auth = $this->client->auth();
        return $auth->createAuthRequest($state, true); // returns URL
    }
    public function exchangeAuthCode(string $code): ?array
    {
        try {
            $auth = $this->client->auth();
            $token = $auth->getToken($code);

            // You can save tokens in DB linked to store/user
            Log::info('TikTok Access Token Retrieved', $token);

            return $token;
        } catch (Exception $e) {
            Log::error('TikTok Token Exchange Failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    public function refreshToken(string $refreshToken): ?array
    {
        try {
            $auth = $this->client->auth();
            $newToken = $auth->refreshNewToken($refreshToken);

            Log::info('TikTok Token Refreshed', $newToken);

            return $newToken;
        } catch (Exception $e) {
            Log::error('TikTok Token Refresh Failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
    public function getAccessToken(int $storeId): ?string
    {
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();

        if (!$integration || !$integration->refresh_token) {
            return null; 
        }
        if (!empty($integration->access_token) && $integration->expires_at && Carbon::parse($integration->expires_at)->gt(now())) {
            return $integration->access_token;
        }
        $newTokenData = $this->refreshToken($integration->refresh_token);

        if (!empty($newTokenData['access_token'])) {
            DB::table('integrations')->where('store_id', $storeId)->update([
                'access_token' => $newTokenData['access_token'],
                'refresh_token'=> $newTokenData['refresh_token'] ?? $integration->refresh_token,
                'expires_at'   => now()->addDays(7), 
                'updated_at'   => now(),
            ]);

            return $newTokenData['access_token'];
        }
        Log::warning("TikTok token refresh failed for store {$storeId}", [
            'response' => $newTokenData
        ]);

        return null;
    }
    // public function getAuthorizedShops(string $accessToken): ?array
    // {
    //     try {
    //         $this->client->setAccessToken($accessToken);
    //         $response = $this->client->Authorization->getAuthorizedShop();

    //         Log::info('TikTok Authorized Shops Fetched', [
    //             'shops' => $response,
    //         ]);

    //         return $response;
    //     } catch (Exception $e) {
    //         Log::error('TikTok Get Authorized Shops Failed', [
    //             'error' => $e->getMessage(),
    //         ]);
    //         return null;
    //     }
    // }
public function fulfillOrder(
    string $accessToken,
    string $shopCipher,
    string $orderId,
    string $trackingNumber,
    string $shippingProviderId
): array {
    try {
        $this->client->setAccessToken($accessToken);
        $this->client->setShopCipher($shopCipher);

        $response = $this->client->Fulfillment->markPackageAsShipped(
            $orderId,
            $trackingNumber,
            $shippingProviderId
        );

        Log::info('TikTok Fulfill Order Response', [
            'order_id' => $orderId,
            'response' => $response,
        ]);

        return [
            'success' => true,
            'message' => 'Order fulfilled successfully!',
            'data'    => $response,
        ];

    } catch (Exception $e) {
        Log::error('TikTok Fulfill Order Failed', [
            'order_id' => $orderId,
            'error'    => $e->getMessage(),
        ]);

        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}

public function getAuthorizedShopCipher(int $storeId, string $accessToken = null): ?string
{
    $integration = Integration::where('store_id', $storeId)->first();

    if ($integration && !empty($integration->shop_cipher)) {
        Log::info('TikTok Shop Cipher Retrieved from Database', [
            'store_id' => $storeId,
            'shop_cipher' => $integration->shop_cipher,
        ]);
        return $integration->shop_cipher;
    }

    if (!$accessToken) {
        $accessToken = $this->getAccessToken($storeId);
        if (!$accessToken) {
            Log::error('TikTok Get Shop Cipher: No access token available', [
                'store_id' => $storeId,
            ]);
            return null;
        }
    }

    try {
        $this->client->setAccessToken($accessToken);
        $response = $this->client->Authorization->getAuthorizedShop();

        Log::info('TikTok Authorized Shops API Response', [
            'store_id' => $storeId,
            'response' => $response,
            'response_type' => gettype($response),
            'response_keys' => is_array($response) ? array_keys($response) : 'not_array',
            'response_json' => json_encode($response, JSON_PRETTY_PRINT),
        ]);

        // The TikTok client library returns $json['data'] from the API response
        // So response should be: { "shops": [...] } or directly [...]
        $shops = null;
        
        if (is_array($response)) {
            // Check if response has 'shops' key (most common structure)
            if (isset($response['shops']) && is_array($response['shops'])) {
                $shops = $response['shops'];
            }
            // Check if response is directly an array of shops
            elseif (isset($response[0]) && is_array($response[0])) {
                $shops = $response;
            }
            // Check nested data structure (just in case)
            elseif (isset($response['data'])) {
                if (is_array($response['data']['shops'] ?? null)) {
                    $shops = $response['data']['shops'];
                } elseif (is_array($response['data']) && isset($response['data'][0])) {
                    $shops = $response['data'];
                }
            }
            // If response is a single shop object (not an array)
            elseif (isset($response['cipher']) || isset($response['shop_cipher']) || isset($response['shop_id'])) {
                $shops = [$response];
            }
        }

        if (is_array($shops) && !empty($shops)) {
            // Get the first shop from the array
            $shop = $shops[0];
            
            // Try multiple possible field names for shop cipher
            $shopCipher = $shop['cipher'] 
                ?? $shop['shop_cipher'] 
                ?? $shop['shop_id'] 
                ?? $shop['id']
                ?? null;

            if ($shopCipher) {
                // Save to database for future use
                Integration::where('store_id', $storeId)
                    ->update(['shop_cipher' => $shopCipher]);

                Log::info('TikTok Shop Cipher Successfully Retrieved and Saved', [
                    'store_id' => $storeId,
                    'shop_cipher' => $shopCipher,
                    'shop_data' => $shop,
                ]);

                return $shopCipher;
            } else {
                Log::warning('TikTok Shop Cipher Field Not Found in Shop Data', [
                    'store_id' => $storeId,
                    'shop_data' => $shop,
                    'shop_keys' => is_array($shop) ? array_keys($shop) : 'not_array',
                    'full_response' => $response,
                ]);
            }
        } else {
            Log::warning('TikTok No Shops Found in API Response', [
                'store_id' => $storeId,
                'response' => $response,
                'response_type' => gettype($response),
                'shops_extracted' => $shops,
                'shops_type' => gettype($shops),
            ]);
        }

        return null;
    } catch (\EcomPHP\TiktokShop\Errors\TokenException $e) {
        $errorMessage = $e->getMessage();
        
        // Check for IP allowlist error - rethrow so command can handle it
        if (strpos($errorMessage, 'IP address is not in the IP allow list') !== false || 
            strpos($errorMessage, 'Access denied') !== false) {
            Log::error('TikTok IP Allowlist Error', [
                'store_id' => $storeId,
                'error' => $errorMessage,
                'error_code' => $e->getCode(),
                'solution' => 'Add your server IP address to the TikTok Shop app IP allowlist in the developer portal',
            ]);
            // Re-throw so the command can display a helpful message
            throw $e;
        } else {
            Log::error('TikTok Token/Auth Error', [
                'store_id' => $storeId,
                'error' => $errorMessage,
                'error_code' => $e->getCode(),
            ]);
            // Re-throw so command can display the error
            throw $e;
        }
    } catch (\EcomPHP\TiktokShop\Errors\ResponseException $e) {
        $errorMessage = $e->getMessage();
        
        // Check for IP allowlist error - rethrow so command can handle it
        if (strpos($errorMessage, 'IP address is not in the IP allow list') !== false || 
            strpos($errorMessage, 'Access denied') !== false) {
            Log::error('TikTok IP Allowlist Error', [
                'store_id' => $storeId,
                'error' => $errorMessage,
                'error_code' => $e->getCode(),
                'solution' => 'Add your server IP address to the TikTok Shop app IP allowlist in the developer portal',
            ]);
            // Re-throw so the command can display a helpful message
            throw $e;
        } else {
            Log::error('TikTok API Response Error', [
                'store_id' => $storeId,
                'error' => $errorMessage,
                'error_code' => $e->getCode(),
            ]);
            // Re-throw so command can display the error
            throw $e;
        }
    } catch (\Exception $e) {
        Log::error('TikTok Get Authorized Shop Cipher Exception', [
            'store_id' => $storeId,
            'error'    => $e->getMessage(),
            'error_code' => $e->getCode(),
            'trace'     => $e->getTraceAsString(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
        // Re-throw so command can display the error
        throw $e;
    }
}


    /**
     * Step 5: Fetch Orders
     */
//   public function fetchOrders(string $accessToken, string $shopCipher, string $startTime = null, string $endTime = null): ?array
//  {
//     try {
//         $this->client->setAccessToken($accessToken);
//         $this->client->setShopCipher($shopCipher);

//         // TikTok expects timestamps (int)
//         $startTime = $startTime ? strtotime($startTime) : now()->subDays(1)->timestamp;
//         $endTime   = $endTime ? strtotime($endTime) : now()->timestamp;

//         $orders = $this->client->Order->getOrderList([
//             'create_time_ge' => $startTime,
//             'create_time_le' => $endTime,
//             'page_size'      => 10,
//             'sort_field'     => 'create_time',
//             'sort_order'     => 'DESC',
//         ]);

//         // Log::info('TikTok Orders Fetched', [
//         //     'count' => $orders,
//         // ]);

//         return $orders;
//     } catch (Exception $e) {
//         Log::error('TikTok Fetch Orders Failed', [
//             'error' => $e->getMessage(),
//         ]);
//         return null;
//     }
// }
public function fetchOrders(string $accessToken, string $shopCipher, string $startTime = null, string $endTime = null): ?array
{
    try {
        $this->client->setAccessToken($accessToken);
        $this->client->setShopCipher($shopCipher);

        // TikTok expects timestamps (int)
        $startTime = $startTime ? strtotime($startTime) : now()->subDays(4)->timestamp;
        $endTime   = $endTime ? strtotime($endTime) : now()->timestamp;

        $allOrders = [];
        $nextToken = null;
        $page = 1;

        do {
            $params = [
                'create_time_ge' => $startTime,
                'create_time_le' => $endTime,
                'page_size'      => 100,
                'sort_field'     => 'create_time',
                'sort_order'     => 'DESC',
            ];

            if ($nextToken) {
                $params['page_token'] = $nextToken;
            }

            $response = $this->client->Order->getOrderList($params);

            // $data = $response['data'] ?? [];
            $orders = $response['orders'] ?? [];
            $nextToken = $response['next_page_token'] ?? null;


            Log::info("📦 TikTok Orders Page {$page} Fetched", [
                'page' => $page,
                'orders_count' => count($orders),
                'next_token' => $nextToken,
            ]);

            $allOrders = array_merge($allOrders, $orders);
            $page++;

        } while (!empty($nextToken));

        Log::info('✅ TikTok Orders Fetch Completed', [
            'total_orders' => count($allOrders),
        ]);

        return [
            'orders' => $allOrders,
            'total'  => count($allOrders),
        ];

    } catch (Exception $e) {
        Log::error('TikTok Fetch Orders Failed', [
            'error' => $e->getMessage(),
        ]);
        return null;
    }
}

}
