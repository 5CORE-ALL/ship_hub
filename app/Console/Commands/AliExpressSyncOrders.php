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

            $rawListResponse = $aliService->getOrders(2);
            $listResponse = json_decode(json_encode($rawListResponse), true, 1024);

            $ordersList = $listResponse['aliexpress_trade_seller_orderlist_get_response']['result']['target_list']['aeop_order_item_dto'] ?? [];

            if (empty($ordersList)) {
                $this->warn('⚠️ No orders found.');
                Log::warning('AliExpress order sync: No orders found', ['response' => $listResponse]);
                return 0;
            }

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
