<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class FetchTemuOrders extends Command
{
    protected $signature = 'app:fetch-temu-orders';
    protected $description = 'Fetch Temu orders and save to Order/OrderItem models';

    public function handle()
    {
        $this->info("🔹 Starting Temu order fetch...");

        $pageNumber = 1;
        $pageSize = 100;

        do {
            $requestBody = [
                'type' => 'bg.order.list.v2.get',
                'pageNumber' => $pageNumber,
                'pageSize' => $pageSize,
                'createAfter' => Carbon::now()->subDays(30)->timestamp,
                'createBefore' => Carbon::now()->timestamp,
            ];

            $signedRequest = $this->generateSignValue($requestBody);

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->post('https://openapi-b-us.temu.com/openapi/router', $signedRequest);

            if ($response->failed()) {
                $this->error("Request failed: " . $response->body());
                Log::error('Temu API request failed', ['response' => $response->body()]);
                break;
            }

            $data = $response->json();
            Log::info('Temu API data', [$data]);

            if (!($data['success'] ?? false)) {
                $this->error("Temu API error: " . ($data['errorMsg'] ?? 'Unknown'));
                break;
            }

            $orders = $data['result']['pageItems'] ?? [];
            if (empty($orders)) {
                $this->info("No more orders found.");
                break;
            }

            foreach ($orders as $orderBatch) {
                foreach ($orderBatch['orderList'] ?? [] as $orderItem) {
                    try {
                        // Extract first product info for order-level SKU & Name
                        $itemSku  = $orderItem['skuId'] ?? null;
                        $itemName = $orderItem['goodsName'] ?? $orderItem['originalGoodsName'] ?? 'Unknown Item';

                        // Map order fields
                        $orderData = [
                            'marketplace'        => 'temu',
                            'order_number'       => $orderItem['orderSn'],
                            'external_order_id'  => $orderItem['orderSn'],
                            'item_sku'           => $itemSku,
                            'item_name'          => $itemName,
                            'order_date'         => isset($orderItem['createTime']) ? Carbon::createFromTimestamp($orderItem['createTime']) : null,
                            'quantity'           => $orderItem['quantity'] ?? 0,
                            'order_total'        => $orderItem['price'] ?? 0,
                            'currency'           => $orderItem['currency'] ?? 'USD',
                            'buyer_name'         => $orderItem['buyerName'] ?? null,
                            'recipient_name'     => $orderItem['buyerName'] ?? null,
                            'recipient_email'    => null,
                            'recipient_phone'    => $orderItem['buyerPhone'] ?? null,
                            'ship_address1'      => $orderItem['buyerAddress'] ?? null,
                            'order_status'       => $orderItem['orderStatus'] ?? null,
                            'fulfillment_status' => $orderItem['orderStatus'] ?? null,
                            'raw_data'           => json_encode($orderItem),
                        ];

                        $orderModel = Order::updateOrCreate(
                            ['order_number' => $orderItem['orderSn'], 'marketplace' => 'temu'],
                            $orderData
                        );

                        // Map order items
                        $itemData = [
                            'order_id'        => $orderModel->id,
                            'order_item_id'   => $orderItem['skuId'] ?? null,
                            'sku'             => $itemSku,
                            'product_name'    => $itemName,
                            'quantity_ordered'=> $orderItem['quantity'] ?? 0,
                            'unit_price'      => $orderItem['price'] ?? 0,
                            'currency'        => $orderItem['currency'] ?? 'USD',
                            'raw_data'        => json_encode($orderItem),
                        ];

                        OrderItem::updateOrCreate(
                            ['order_item_id' => $orderItem['skuId'] ?? null, 'order_id' => $orderModel->id],
                            $itemData
                        );

                        $this->info("✅ Order {$orderItem['orderSn']} saved successfully.");
                    } catch (\Exception $e) {
                        $this->error("Error saving order {$orderItem['orderSn']}: " . $e->getMessage());
                        Log::error('Temu order save error', [
                            'order' => $orderItem,
                            'exception' => $e->getMessage()
                        ]);
                    }
                }
            }

            $pageNumber++;
        } while (true);

        $this->info("✅ Temu orders fetch completed.");
        return 0;
    }

    private function generateSignValue($requestBody)
    {
        $appKey = env('TEMU_APP_KEY');
        $appSecret = env('TEMU_SECRET_KEY');
        $accessToken = env('TEMU_ACCESS_TOKEN');
        $timestamp = time();

        $params = [
            'access_token' => $accessToken,
            'app_key' => $appKey,
            'timestamp' => $timestamp,
            'data_type' => 'JSON',
        ];

        $signParams = array_merge($params, $requestBody);
        ksort($signParams);

        $temp = '';
        foreach ($signParams as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $temp .= $key . $value;
        }

        $signStr = $appSecret . $temp . $appSecret;
        $params['sign'] = strtoupper(md5($signStr));

        return array_merge($params, $requestBody);
    }
}
