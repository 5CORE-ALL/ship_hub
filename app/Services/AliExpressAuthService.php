<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
class AliExpressAuthService
{
    protected $url;
    protected $url1;
    protected $appKey;
    protected $appSecret;
    protected $refreshToken;
    protected $accessToken;

    public function __construct()
    {
        // Define SDK constants if not already defined
        if (!defined('IOP_SDK_WORK_DIR')) {
            define('IOP_SDK_WORK_DIR', app_path('Libraries/AliExpressSDK/iop'));
        }
        if (!defined('IOP_AUTOLOADER_PATH')) {
            define('IOP_AUTOLOADER_PATH', app_path('Libraries/AliExpressSDK'));
        }

        // Load SDK entry
        if (!class_exists('Autoloader')) {
            require_once app_path('Libraries/AliExpressSDK/IopSdk.php');
        }

        $this->url = 'https://api-sg.aliexpress.com/rest';
        $this->url1 = 'https://api-sg.aliexpress.com/sync';
        $this->appKey = config('services.aliexpress.app_key', env('ALIEXPRESS_APP_KEY', ''));
        $this->appSecret = config('services.aliexpress.app_secret', env('ALIEXPRESS_APP_SECRET', ''));
        // Note: refreshToken and accessToken should be stored in database per store, not hardcoded here
        $this->refreshToken = null;
        $this->accessToken = null;
    }

    /**
     * Get refresh token from AliExpress using auth code
     */
    public function getRefreshToken(string $authCode)
    {
        try {
            $client = new \IopClient($this->url, $this->appKey, $this->appSecret);
            $request = new \IopRequest('/auth/token/create');
            $request->addApiParam('code', $authCode);

            $response = $client->execute($request);
            $data = json_decode($response, true);

            Log::info('AliExpress Refresh Token Response', [
                'authCode' => $authCode,
                'response' => $data,
            ]);

            return $data;

        } catch (Exception $e) {
            Log::error('AliExpress Refresh Token Error', [
                'authCode' => $authCode,
                'error' => $e->getMessage(),
            ]);

            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Get access token using refresh token from env
     */
    // public function getAccessToken()
    // {
    //     try {
    //         if (!$this->refreshToken) {
    //             throw new Exception("Refresh token not set in .env");
    //         }

    //         $client = new \IopClient($this->url, $this->appKey, $this->appSecret);
    //         $request = new \IopRequest('/auth/token/refresh');
    //         $request->addApiParam('grant_type', 'refresh_token');
    //         $request->addApiParam('refresh_token', $this->refreshToken);

    //         $response = $client->execute($request);
    //         $data = json_decode($response, true);

    //         Log::info('AliExpress Access Token Response', [
    //             'response' => $data,
    //         ]);

    //         return $data;

    //     } catch (Exception $e) {
    //         Log::error('AliExpress Access Token Error', [
    //             'error' => $e->getMessage(),
    //         ]);

    //         return ['error' => $e->getMessage()];
    //     }
    // }
    /**
     * Create IopClient with proper timeout settings
     */
    protected function createIopClient($url = null)
    {
        $url = $url ?? $this->url1;
        $client = new \IopClient($url, $this->appKey, $this->appSecret);
        // Set timeouts to prevent cURL timeout errors (60 seconds connect, 120 seconds read)
        // Increased timeouts for slow API responses
        $client->connectTimeout = 60;
        $client->readTimeout = 120;
        return $client;
    }

    public function getAccessToken(int $storeId): ?string
{

    $integration = DB::table('integrations')->where('store_id', $storeId)->first();

    // Return null if no integration or refresh token
    if (!$integration || !$integration->refresh_token) {
        return null;
    }

    // If token exists and not expired, return it
    // Note: We still check expiration, but if the token is invalid, the API will return IllegalAccessToken
    // which will trigger a forced refresh in getOrders() and other methods
    if (!empty($integration->access_token) && $integration->expires_at && Carbon::parse($integration->expires_at)->gt(now())) {
        return $integration->access_token;
    }

    try {
        $client = $this->createIopClient($this->url);
        $request = new \IopRequest('/auth/token/refresh');
        $request->addApiParam('grant_type', 'refresh_token');
        $request->addApiParam('refresh_token', $integration->refresh_token);

        $response = $client->execute($request);
        $data = json_decode($response, true);


        Log::info('AliExpress token refresh response', [
            'store_id' => $storeId,
            'response' => $data,
        ]);

        // Check for error in response
        if (isset($data['error_response'])) {
            $errorCode = $data['error_response']['code'] ?? 'Unknown';
            $errorMsg = $data['error_response']['msg'] ?? $data['error_response']['error_message'] ?? 'Unknown error';
            
            Log::error("AliExpress token refresh failed for store {$storeId}", [
                'error_code' => $errorCode,
                'error_message' => $errorMsg,
                'response' => $data
            ]);
            return null;
        }

        if (!empty($data['access_token'])) {
            // Save token with 6 months expiry
            DB::table('integrations')->where('store_id', $storeId)->update([
                'access_token' => $data['access_token'],
                'expires_at'   => now()->addMonths(6), 
                'updated_at'   => now(),
            ]);

            return $data['access_token'];
        }

        Log::warning("AliExpress token refresh failed for store {$storeId} - no access_token in response", [
            'response' => $data
        ]);
        return null;

    } catch (\Exception $e) {
        Log::error("AliExpress token refresh error for store {$storeId}", [
            'error' => $e->getMessage()
        ]);
        return null;
    }
}

// public function getOrders()
// {
//     try {
//         // Get a fresh access token dynamically
//         $tokenData = $this->getAccessToken();
//         if (!isset($tokenData['access_token'])) {
//             throw new \Exception("Failed to get access token");
//         }
//         $accessToken = $tokenData['access_token'];
//         $url='https://api-sg.aliexpress.com';

//         // Initialize SDK client
//         $client = new \IopClient($url, $this->appKey, $this->appSecret);

//         // Initialize request
//         $request = new \IopRequest('aliexpress.trade.seller.orderlist.get');

//         // Prepare parameters (DO NOT include access_token here)
//         $params = [
//             "create_date_start"   => date('Y-m-d H:i:s', strtotime('-7 days')),
//             "create_date_end"     => date('Y-m-d H:i:s'),
//             "modified_date_start" => date('Y-m-d H:i:s', strtotime('-7 days')),
//             "modified_date_end"   => date('Y-m-d H:i:s'),
//             "order_status_list"   => ["SELLER_PART_SEND_GOODS"],
//             "page_size"           => 20,
//             "current_page"        => 1,
//         ];

//         $request->addApiParam('param_aeop_order_query', json_encode($params));

//         // Execute the request with the dynamic access token
//         $response = $client->execute($request, $accessToken);

//         // Decode JSON response
//         $data = json_decode($response, true);

//         \Log::info('AliExpress Orders Response', [
//             'params'   => $params,
//             'response' => $data,
//         ]);

//         return $data;

//     } catch (\Exception $e) {
//         \Log::error('AliExpress Orders Error', [
//             'error' => $e->getMessage(),
//         ]);

//         return ['error' => $e->getMessage()];
//     }
// }
public function getOrders($days = 5, $currentPage = 1, $pageSize = 50, $storeId = 9, $forceRefresh = false)
{
    try {
        // 1️⃣ Get a fresh access token dynamically
        // If forceRefresh is true, clear the existing token to force refresh
        if ($forceRefresh) {
            DB::table('integrations')->where('store_id', $storeId)->update([
                'access_token' => null,
                'expires_at' => null,
            ]);
        }
        
        $tokenData = $this->getAccessToken($storeId);
        if (!isset($tokenData)) {
            throw new \Exception("Failed to get access token");
        }
        $accessToken = $tokenData;

        // 2️⃣ Initialize SDK client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Initialize request
        $request = new \IopRequest('aliexpress.trade.seller.orderlist.get', 'POST');

        // 4️⃣ Prepare parameters (DO NOT include access_token inside JSON)
        $params = [
            "create_date_start"   => date('Y-m-d H:i:s', strtotime("-{$days} days")),
            "create_date_end"     => date('Y-m-d H:i:s'),
            "modified_date_start" => date('Y-m-d H:i:s', strtotime("-{$days} days")),
            "modified_date_end"   => date('Y-m-d H:i:s'),
            // "order_status_list"   => ["SELLER_PART_SEND_GOODS"],
            "page_size"           => $pageSize,
            "current_page"        => $currentPage
        ];

        // 5️⃣ Add parameters as JSON string
        $request->addApiParam('param_aeop_order_query', json_encode($params));

        // 6️⃣ Execute the request - pass access token as second parameter (not as session param)
        $response = $client->execute($request, $accessToken);

        // 7️⃣ Decode JSON response
        $data = json_decode($response, true);

        // 8️⃣ Check for IllegalAccessToken error and retry with forced refresh
        if (isset($data['aliexpress_trade_seller_orderlist_get_response']['error_response'])) {
            $errorResponse = $data['aliexpress_trade_seller_orderlist_get_response']['error_response'];
            $errorCode = $errorResponse['code'] ?? '';
            
            if ($errorCode === 'IllegalAccessToken' && !$forceRefresh) {
                \Log::warning('AliExpress IllegalAccessToken detected, forcing token refresh and retrying', [
                    'store_id' => $storeId,
                    'error_response' => $errorResponse
                ]);
                
                // Force refresh token and retry once
                return $this->getOrders($days, $currentPage, $pageSize, $storeId, true);
            }
        }
        
        // Also check for direct error_response
        if (isset($data['error_response'])) {
            $errorResponse = $data['error_response'];
            $errorCode = $errorResponse['code'] ?? '';
            
            if ($errorCode === 'IllegalAccessToken' && !$forceRefresh) {
                \Log::warning('AliExpress IllegalAccessToken detected (direct), forcing token refresh and retrying', [
                    'store_id' => $storeId,
                    'error_response' => $errorResponse
                ]);
                
                // Force refresh token and retry once
                return $this->getOrders($days, $currentPage, $pageSize, $storeId, true);
            }
        }

        // 9️⃣ Log for debugging
        \Log::info('AliExpress Orders Response', [
            'params'   => $params,
            'response' => $data,
        ]);

        return $data;

    } catch (\Exception $e) {
        $errorCode = $e->getCode();
        $errorMessage = $e->getMessage();
        
        // Provide more helpful error messages
        if ($errorCode == 28) {
            $errorMessage = "Connection timeout - The AliExpress API request timed out. This may be due to network issues or API slowness.";
        }
        
        \Log::error('AliExpress Orders Error', [
            'error_code' => $errorCode,
            'error' => $errorMessage,
            'trace' => $e->getTraceAsString(),
        ]);

        return ['error' => $errorMessage];
    }
}
public function getOrderDetail($orderId)
{
    try {
        // 1️⃣ Get fresh access token
        $tokenData = $this->getAccessToken(9);
        if (!isset($tokenData)) {
            throw new \Exception("Failed to get access token");
        }
        $accessToken = $tokenData;

        // 2️⃣ Init client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Init request for order detail
        $request = new \IopRequest('aliexpress.trade.new.redefining.findorderbyid');

        // 4️⃣ Add params
        $params = [
        "show_id" => "1",
        "need_local_address" => true,
        "ext_info_bit_flag" => "111111",
        "field_list" => "1",
         "order_id" => (string) $orderId    // camelCase for this API
];

        $request->addApiParam('param1', json_encode($params));
        // Note: Don't add session as param - pass it to execute() instead

        // 5️⃣ Execute request - pass access token as second parameter
        $response = $client->execute($request, $accessToken);

        // 6️⃣ Decode response
        $data = json_decode($response, true);

        \Log::info('AliExpress Order Detail Response', [
            'order_id' => $orderId,
            'response' => $data
        ]);

        return $data;

    } catch (\Exception $e) {
        \Log::error('AliExpress Order Detail Error', [
            'order_id' => $orderId,
            'error' => $e->getMessage()
        ]);

        return ['error' => $e->getMessage()];
    }
}

public function getOrderDetaildecrypt($orderId, $oaid = null)
{
    try {
        // 1️⃣ Get fresh access token
        $accessToken = $this->getAccessToken(9);
        if (!$accessToken) {
            throw new \Exception("Failed to get access token");
        }

        // 2️⃣ Init client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Init request for decrypt API
        $request = new \IopRequest('aliexpress.trade.seller.order.decrypt');

        // 4️⃣ Required params
        $request->addApiParam('orderId', (string)$orderId);

        if (!empty($oaid)) {
            // URL-decode the OAID in case it has %2B or similar
            $request->addApiParam('oaid', $oaid);
        } else {
            throw new \Exception("Missing OAID for order ID {$orderId}");
        }

        // 5️⃣ Execute request - pass access token as second parameter
        $response = $client->execute($request, $accessToken);

        // 6️⃣ Decode and log response
        $data = json_decode($response, true);

        \Log::info('AliExpress Decrypt Order Response', [
            'order_id' => $orderId,
            'oaid' => $oaid,
            'response' => $data
        ]);

        // 7️⃣ Return the decoded data
        return $data ?? ['error' => 'Empty API response'];

    } catch (\Exception $e) {
        \Log::error('AliExpress Order Detail Error', [
            'order_id' => $orderId,
            'error' => $e->getMessage()
        ]);

        return ['error' => $e->getMessage()];
    }
}
public function listLogisticsServices()
{
    try {
        // 1️⃣ Get fresh access token for your store (store_id = 9 in your case)
        $accessToken = $this->getAccessToken(9);
        if (!$accessToken) {
            throw new \Exception("Failed to get access token");
        }

        // 2️⃣ Initialize client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Initialize request
        $request = new \IopRequest('aliexpress.logistics.redefining.listlogisticsservice', 'POST');

        // 4️⃣ Execute request - pass access token as second parameter (not as session param)
        $response = $client->execute($request, $accessToken);

        // 6️⃣ Decode and log response
        $data = json_decode($response, true);

        \Log::info('AliExpress Logistics Services Response', [
            'response' => $data
        ]);

        return $data;

    } catch (\Exception $e) {
        \Log::error('AliExpress Logistics Services Error', [
            'error' => $e->getMessage()
        ]);
        return ['error' => $e->getMessage()];
    }
}

public function fulfillOrder($orderId, $trackingNumber, $carrierName = 'Other', $trackingWebsite = null)
{
    try {
        $accessToken = $this->getAccessToken(9);
        if (!$accessToken) {
            throw new \Exception("Failed to get access token");
        }
        $client = $this->createIopClient();
        $request = new \IopRequest('aliexpress.solution.order.fulfill');
        $request->addApiParam('out_ref', (string) $orderId);
        $request->addApiParam('send_type', 'all'); // 'all' = ship all items, 'part' = partial shipment
        $request->addApiParam('service_name', $carrierName); 
        $request->addApiParam('logistics_no', $trackingNumber); 
        if ($trackingWebsite) {
            $request->addApiParam('tracking_website', $trackingWebsite);
        }
        $response = $client->execute($request, $accessToken);
        $data = json_decode($response, true);
        \Log::info('AliExpress Fulfillment API Response', [
            'order_id' => $orderId,
            'tracking_no' => $trackingNumber,
            'response' => $data
        ]);
        if (!empty($data['aliexpress_solution_order_fulfill_response']['result']['result_success'])) {
            return ['success' => true, 'message' => 'Order marked as shipped successfully.'];
        }
        return [
            'success' => false,
            'message' => 'Fulfillment failed',
            'response' => $data
        ];

    } catch (\Exception $e) {
        \Log::error('AliExpress Fulfillment Error', [
            'order_id' => $orderId,
            'error' => $e->getMessage()
        ]);
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
public function getProductList($page = 1, $pageSize = 20)
{
    try {
        // 1️⃣ Get fresh access token
        $tokenData = $this->getAccessToken(9);
        if (!isset($tokenData)) {
            throw new \Exception("Failed to get access token");
        }
        $accessToken = $tokenData;

        // 2️⃣ Initialize SDK client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Initialize request
        $request = new \IopRequest('aliexpress.solution.product.list.get', 'POST');

        // 4️⃣ Prepare parameters
        $params = [
            "gmt_modified_start" => date('Y-m-d H:i:s', strtotime('-7 days')),
            "gmt_modified_end"   => date('Y-m-d H:i:s'),
            "product_status_type" => "onSelling",
            "current_page" => (string)$page,
            "page_size"    => (string)$pageSize
        ];

        // 5️⃣ Add params
        $request->addApiParam('aeop_a_e_product_list_query', json_encode($params));

        // 6️⃣ Execute API call - pass access token as second parameter
        $response = $client->execute($request, $accessToken);
        $data = json_decode($response, true);

        // 7️⃣ Log & return
        \Log::info('AliExpress Product List Response', [
            'params' => $params,
            'response' => $data,
        ]);

        return $data;
    } catch (\Exception $e) {
        \Log::error('AliExpress Product List Error', [
            'error' => $e->getMessage(),
        ]);
        return ['error' => $e->getMessage()];
    }
}
public function getLocalServiceProductList($page = 1, $pageSize = 20)
{
    try {
        // 1️⃣ Get fresh access token (using store_id = 9)
        $accessToken = $this->getAccessToken(9);
        if (!$accessToken) {
            throw new \Exception("Failed to get access token");
        }

        // 2️⃣ Initialize client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Initialize request
        $request = new \IopRequest('aliexpress.local.service.products.list', 'POST');

        // 4️⃣ Prepare search conditions
        $searchCondition = [
            "update_before"       => "2024-02-13 16:41:00",
            "manufacturer_id"     => "20",
            "msr_eu_id"           => "20",
            "leaf_category_id"    => null,
            "product_id"          => "123423",
            "update_after"        => "2024-08-13 16:41:00",
            "product_status"      => "ONLINE",
            "create_before"       => "2024-08-13 16:41:00",
            "create_after"        => "2024-02-13 16:41:00",
            "audit_failure_reason"=> "audit_sub_status_basic"
        ];

        // 5️⃣ Add API parameters
        $request->addApiParam('channel_seller_id', '6117436568');
        $request->addApiParam('channel', 'AE_GLOBAL');
        $request->addApiParam('page_size', (string) $pageSize);
        $request->addApiParam('search_condition_do', json_encode($searchCondition));
        $request->addApiParam('current_page', (string) $page);

        // 6️⃣ Execute API request - pass access token as second parameter
        $response = $client->execute($request, $accessToken);
        $data = json_decode($response, true);

        // 7️⃣ Log for debugging
        \Log::info('AliExpress Local Service Product List Response', [
            'page' => $page,
            'params' => $searchCondition,
            'response' => $data
        ]);

        return $data;

    } catch (\Exception $e) {
        \Log::error('AliExpress Local Service Product List Error', [
            'error' => $e->getMessage()
        ]);
        return ['error' => $e->getMessage()];
    }
}

public function getProductDetail($productId)
{
    try {
        // 1️⃣ Get fresh access token for store ID 9
        $accessToken = $this->getAccessToken(9); 
        if (!$accessToken) {
            throw new \Exception("Failed to get access token");
        }

        // 2️⃣ Initialize SDK client with proper timeouts
        $client = $this->createIopClient();

        // 3️⃣ Ensure product ID is string
        $productId = (string) $productId;

        // 4️⃣ Initialize request for product info
        $request = new \IopRequest('aliexpress.solution.product.info.get', 'POST');

        // 5️⃣ Required API parameters
        $request->addApiParam('product_id', $productId);
        $request->addApiParam('ship_to_country', 'US');  // mandatory

        // Optional: currency & language can be added if needed
        // $request->addApiParam('target_currency', 'USD');
        // $request->addApiParam('target_language', 'en');

        // 6️⃣ Execute API call - pass access token as second parameter
        $response = $client->execute($request, $accessToken);
        $data = json_decode($response, true);

        // 7️⃣ Log for debugging
        \Log::info('AliExpress Product Detail Response', [
            'product_id' => $productId,
            'response' => $data,
        ]);

        return $data;

    } catch (\Exception $e) {
        \Log::error('AliExpress Product Detail Error', [
            'product_id' => $productId,
            'error' => $e->getMessage(),
        ]);
        return ['error' => $e->getMessage()];
    }
}




}
