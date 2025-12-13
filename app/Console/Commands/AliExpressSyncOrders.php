<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\AliExpressAuthService;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\DB;

class AliExpressSyncOrders extends Command
{
    protected $signature = 'aliexpress:sync-orders';
    protected $description = 'Fetch and sync AliExpress orders with full order details (ordertail)';

    public function handle()
    {
        $this->info('🔄 Syncing AliExpress orders with full details...');
        Log::info('Starting AliExpress order sync with ordertail');

        try {
            $aliService = new AliExpressAuthService();
            
            // Check if access token is available
            $storeId = 9; // AliExpress uses store_id 9
            $accessToken = $aliService->getAccessToken($storeId);
            if (!$accessToken) {
                $integration = \App\Models\Integration::where('store_id', $storeId)->first();
                if (!$integration) {
                    $this->error("❌ No integration found for AliExpress store ID {$storeId}. Please set up the integration first.");
                    Log::error("AliExpress sync failed: No integration for store_id {$storeId}");
                } elseif (!$integration->refresh_token) {
                    $this->error("❌ AliExpress integration for store ID {$storeId} is missing refresh_token. Please re-authenticate.");
                    Log::error("AliExpress sync failed: Missing refresh_token for store_id {$storeId}");
                } else {
                    $this->error("❌ Failed to refresh AliExpress access token for store ID {$storeId}. Token may be expired or invalid.");
                    Log::error("AliExpress sync failed: Token refresh failed for store_id {$storeId}");
                }
                return Command::FAILURE;
            }

            // Fetch orders from last 30 days to catch any missed orders
            $days = 30;
            $allOrdersList = [];
            $currentPage = 1;
            $hasMorePages = true;
            $maxPages = 50; // Safety limit
            
            while ($hasMorePages && $currentPage <= $maxPages) {
                $rawListResponse = $aliService->getOrders($days, $currentPage);
                $listResponse = json_decode(json_encode($rawListResponse), true, 1024);
                
                // Check for API errors
                if (isset($listResponse['error'])) {
                    $errorMsg = $listResponse['error'] ?? 'Unknown error';
                    
                    // Provide more helpful error messages
                    if ($errorMsg == '28' || strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, '28') !== false) {
                        $this->error("❌ AliExpress API Connection Timeout Error");
                        $this->warn("💡 The API request timed out. This could be due to:");
                        $this->warn("   - Network connectivity issues");
                        $this->warn("   - AliExpress API being slow or unavailable");
                        $this->warn("   - Firewall blocking the connection");
                        $this->warn("   - Server IP not whitelisted in AliExpress app settings");
                    } else {
                        $this->error("❌ AliExpress API Error: " . $errorMsg);
                    }
                    
                    Log::error('AliExpress API Error', [
                        'error' => $errorMsg,
                        'error_code' => is_numeric($errorMsg) ? $errorMsg : null,
                        'full_response' => $listResponse
                    ]);
                    break;
                }
                
                // Check for error in response structure
                if (isset($listResponse['aliexpress_trade_seller_orderlist_get_response']['error_response'])) {
                    $errorResponse = $listResponse['aliexpress_trade_seller_orderlist_get_response']['error_response'];
                    $errorMsg = $errorResponse['msg'] ?? $errorResponse['error_message'] ?? 'Unknown API error';
                    $errorCode = $errorResponse['code'] ?? $errorResponse['error_code'] ?? 'Unknown';
                    
                    // Special handling for IP whitelist error
                    if ($errorCode === 'AppWhiteIpLimit') {
                        $this->error("❌ AliExpress IP Whitelist Error: The server IP address is not whitelisted in AliExpress app settings.");
                        $this->warn("💡 Solution: Add your server's IP address to the AliExpress app whitelist in the AliExpress Open Platform.");
                        $this->warn("   Current server IP needs to be added to: AliExpress Open Platform > Your App > Security Settings > IP Whitelist");
                    } else {
                        $this->error("❌ AliExpress API Error [{$errorCode}]: {$errorMsg}");
                    }
                    
                    Log::error('AliExpress API Error Response', [
                        'error_code' => $errorCode,
                        'error_message' => $errorMsg,
                        'full_response' => $errorResponse
                    ]);
                    break;
                }
                
                // Also check for direct error_response (not nested)
                if (isset($listResponse['error_response'])) {
                    $errorResponse = $listResponse['error_response'];
                    $errorMsg = $errorResponse['msg'] ?? $errorResponse['error_message'] ?? 'Unknown API error';
                    $errorCode = $errorResponse['code'] ?? $errorResponse['error_code'] ?? 'Unknown';
                    
                    if ($errorCode === 'AppWhiteIpLimit') {
                        $this->error("❌ AliExpress IP Whitelist Error: The server IP address is not whitelisted in AliExpress app settings.");
                        $this->warn("💡 Solution: Add your server's IP address to the AliExpress app whitelist in the AliExpress Open Platform.");
                    } else {
                        $this->error("❌ AliExpress API Error [{$errorCode}]: {$errorMsg}");
                    }
                    
                    Log::error('AliExpress API Error Response', [
                        'error_code' => $errorCode,
                        'error_message' => $errorMsg,
                        'full_response' => $errorResponse
                    ]);
                    break;
                }
                
                $responseData = $listResponse['aliexpress_trade_seller_orderlist_get_response']['result'] ?? [];
                $ordersList = $responseData['target_list']['aeop_order_item_dto'] ?? [];
                $totalPages = $responseData['total_page'] ?? 1;
                $totalCount = $responseData['total_count'] ?? 0;
                
                if ($currentPage === 1) {
                    $this->info("📊 Total orders available: {$totalCount} across {$totalPages} page(s)");
                }
                
                if (!empty($ordersList)) {
                    $allOrdersList = array_merge($allOrdersList, $ordersList);
                    $this->info("📄 Fetched page {$currentPage}/{$totalPages} - Found " . count($ordersList) . " orders (Total so far: " . count($allOrdersList) . ")");
                }
                
                // Check if there are more pages
                if ($currentPage >= $totalPages || empty($ordersList)) {
                    $hasMorePages = false;
                } else {
                    $currentPage++;
                }
            }

            if (empty($allOrdersList)) {
                $this->warn("⚠️ No orders found in the last {$days} days.");
                Log::info('AliExpress order sync: No orders found', [
                    'response_structure' => array_keys($listResponse ?? []),
                    'days' => $days,
                    'store_id' => $storeId,
                    'raw_response_sample' => isset($listResponse) ? json_encode(array_slice($listResponse, 0, 3)) : null
                ]);
                return 0;
            }
            
            $this->info("✅ Found " . count($allOrdersList) . " total orders to process.");
            $ordersList = $allOrdersList;

            $count = 0;

            foreach ($ordersList as $orderData) {
                try {
                    $orderId = $orderData['order_id'] ?? null;
                    if (!$orderId) continue;
                    $orderDetailResponse = $aliService->getOrderDetail($orderId);
                    $orderDetail = json_decode(json_encode($orderDetailResponse), true);

                    $target = $orderDetail['aliexpress_trade_new_redefining_findorderbyid_response']['target'] ?? null;
                    if (!$target) {
                        Log::warning("No order detail found for order {$orderId}");
                        continue;
                    }

                    // Extract shipping info
                    $shipping = $target['receipt_address'] ?? [];

                    // Extract products
                    $products = $target['child_order_list']['aeop_tp_child_order_dto'] ?? [];
                    $decryptResponse = $aliService->getOrderDetaildecrypt($orderId, $target['oaid'] ?? null);
                    $decryptData = json_decode(json_encode($decryptResponse), true);
                    $decryptInfo = $decryptData['aliexpress_trade_seller_order_decrypt_response']['result_obj'] ?? [];
                    $decryptedContact = [
                        'recipient_name'   => trim($decryptInfo['contact_person'] ?? $shipping['contact_person'] ?? $target['buyer_signer_fullname'] ?? ''),
                        'recipient_email'  => $decryptInfo['contact_email'] ?? $shipping['contact_email'] ?? $target['buyerloginid'] ?? null,
                        'recipient_phone'  => $decryptInfo['mobile_no'] ?? $shipping['mobile_no'] ?? null,
                        'ship_address1'    => $decryptInfo['detail_address'] ?? $shipping['detail_address'] ?? null,
                        'ship_address2'    => $decryptInfo['address2'] ?? $shipping['address2'] ?? null,
                    ];

                    // --- Create / update main order ---
                    // $order = Order::updateOrCreate(
                    //     ['marketplace_order_id' => $orderId, 'marketplace' => 'aliexpress'],
                    //     [
                    //         'store_id'           => 1,
                    //         'order_number'       => $orderId,
                    //         'external_order_id'  => $orderId,
                    //         'order_date'         => isset($target['gmt_create']) ? Carbon::parse($target['gmt_create']) : null,
                    //         'order_age'          => isset($target['gmt_create']) ? now()->diffInDays(Carbon::parse($target['gmt_create'])) : null,
                    //         'order_total'        => $target['order_amount']['amount'] ?? 0,
                    //         'quantity'           => collect($products)->sum(fn($p) => $p['product_count'] ?? 1),
                    //         'shipping_cost'      => collect($products)->sum(fn($p) => $p['logistics_amount']['amount'] ?? 0),
                    //         'order_status'       => $target['order_status'] ?? 'UNKNOWN',
                    //         'fulfillment_status' => $target['order_status'] ?? 'UNKNOWN',
                    //         'recipient_name'     => trim($shipping['contact_person'] ?? $target['buyer_signer_fullname'] ?? ''),
                    //         'recipient_email'    => $shipping['contact_email'] ?? $target['buyerloginid'] ?? null,
                    //         'recipient_phone'    => $shipping['mobile_no'] ?? $target['is_phone'] ? null : null,
                    //         'ship_address1'      => $shipping['detail_address'] ?? null,
                    //         'ship_address2'      => $shipping['address2'] ?? null,
                    //         'ship_city'          => $shipping['city'] ?? null,
                    //         'ship_state'         => $shipping['province'] ?? null,
                    //         'ship_postal_code'   => $shipping['zip'] ?? null,
                    //         'ship_country'       => $shipping['country'] ?? null,
                    //         'shipping_service'   => $products[0]['logistics_service_name'] ?? null,
                    //         'shipping_carrier'   => $products[0]['logistics_type'] ?? null,
                    //         'oaid'               => $target['oaid'] ?? null,
                    //         'raw_data'           => json_encode($target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    //     ]
                    // );
                     $statusMap = [
                        "PLACE_ORDER_SUCCESS"       =>'Unpaid',
                        'WAIT_BUYER_PAY'            => 'Awaiting Payment',
                        'WAIT_SELLER_SEND_GOODS'    => 'Unshipped',
                        'SELLER_PART_SEND_GOODS'    => 'Partially Shipped',
                        'WAIT_BUYER_ACCEPT_GOODS'   => 'Shipped',
                        'FINISH'                    => 'Delivered',
                        'IN_CANCEL'                 => 'Pending Cancellation',
                        'CANCELLED'                 => 'Cancelled',
                        'RISK_CONTROL'              => 'On Hold',
                        'WAIT_SELLER_EXAMINE_MONEY' => 'Refund Pending',
                        'FUND_PROCESSING'           => 'Refund Processing',
                        'FUND_CLOSED'               => 'Refund Completed',
                    ];
                    $aliStatus = $target['order_status'] ?? 'UNKNOWN';
                    $mappedStatus = $statusMap[$aliStatus] ?? 'Unknown';
                    $order = Order::updateOrCreate(
                        ['marketplace_order_id' => $orderId, 'marketplace' => 'aliexpress'],
                        array_merge([
                            'store_id'           => 1,
                            'order_number'       => $orderId,
                            'external_order_id'  => $orderId,
                            'order_date'         => isset($target['gmt_create']) ? Carbon::parse($target['gmt_create']) : null,
                            'order_age'          => isset($target['gmt_create']) ? now()->diffInDays(Carbon::parse($target['gmt_create'])) : null,
                            'order_total'        => $target['order_amount']['amount'] ?? 0,
                            'quantity'           => collect($products)->sum(fn($p) => $p['product_count'] ?? 1),
                            'shipping_cost'      => collect($products)->sum(fn($p) => $p['logistics_amount']['amount'] ?? 0),
                            'order_status'       => $mappedStatus,
                            'fulfillment_status' => $mappedStatus,
                            'ship_city'          => $shipping['city'] ?? null,
                            'ship_state'         => $shipping['province'] ?? null,
                            'ship_postal_code'   => $shipping['zip'] ?? null,
                            'ship_country'       => $shipping['country'] ?? null,
                            'shipping_service'   => $products[0]['logistics_service_name'] ?? null,
                            'shipping_carrier'   => $products[0]['logistics_type'] ?? null,
                            'oaid'               => $target['oaid'] ?? null,
                            'raw_data'           => json_encode($target, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ], $decryptedContact)
                    );


                    // --- Create / update order items ---
                    foreach ($products as $product) {
                        $qty = $product['product_count'] ?? 1;
                        $dimensionData = getDimensionsBySku($product['sku_code'] ?? '', $qty); 
                        OrderItem::updateOrCreate(
                            [
                                'order_id' => $order->id,
                                'sku'      => $product['sku_code'] ?? null,
                            ],
                            [
                                'item_name'     => $product['product_name'] ?? null,
                                'quantity_ordered'      => $product['product_count'] ?? 1,
                                'price'         => $product['product_price']['amount'] ?? 0,
                                'total'         => ($product['product_price']['amount'] ?? 0) * ($product['product_count'] ?? 1),
                                'shipping_cost' => $product['logistics_amount']['amount'] ?? 0,
                                'weight'             => $dimensionData['weight'] ?? 20,
                                'weight_unit'        => null,
                                'length'             => $dimensionData['length'] ?? 8,
                                'width'              => $dimensionData['width'] ?? 6,
                                'height'             => $dimensionData['height'] ?? 2,
                                'raw_data'      => json_encode($product, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                            ]
                        );
                    }

                    $count++;
                    Log::info('AliExpress order synced with details', ['order_id' => $orderId]);

                } catch (Exception $innerEx) {
                    Log::error('Error syncing order with details', [
                        'order_id' => $orderData['order_id'] ?? null,
                        'error'    => $innerEx->getMessage(),
                    ]);
                }
            }

            $this->info("✅ Synced {$count} AliExpress orders with full order details!");
            Log::info('AliExpress order sync with ordertail completed', ['count' => $count]);

        } catch (Exception $e) {
            $this->error('❌ AliExpress order sync failed: ' . $e->getMessage());
            Log::error('AliExpress order sync failed', ['error' => $e->getMessage()]);
        }

        return 0;
    }
}
