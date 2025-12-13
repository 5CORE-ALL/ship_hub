<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Str;

class SheinSyncOrders extends Command
{
    protected $signature = 'shein:sync-orders';
    protected $description = 'Fetch and sync all Shein orders except completed';

    public function handle()
    {
        $this->info('🔄 Syncing Shein orders...');

        try {
            $baseUrl = 'https://openapi.sheincorp.com';
            $listEndpoint = '/open-api/order/order-list';
            $detailEndpoint = '/open-api/order/order-detail';
            $maxIntervalHours = 48;
            $startDate = now()->subDays(2);
            $endDate = now();

            while ($startDate < $endDate) {
                $windowEnd = $startDate->copy()->addHours($maxIntervalHours);
                if ($windowEnd > $endDate) $windowEnd = $endDate->copy();

                if ($windowEnd <= $startDate) break; // safety check

                $page = 1;
                $hasMore = true;

                while ($hasMore) {
                    $timestamp = round(microtime(true) * 1000);
                    $randomKey = Str::random(5);
                    $signature = $this->generateSheinSignature($listEndpoint, $timestamp, $randomKey);

                    $payload = [
                        "page" => $page,
                        "pageSize" => 30,
                        "queryType" => 1,
                        // "orderStatus" => null,
                        "startTime" => $startDate->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
                        "endTime" => $windowEnd->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
                    ];

                    $headers = [
                        "Language" => "en",
                        "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
                        "x-lt-timestamp" => $timestamp,
                        "x-lt-signature" => $signature,
                        "Content-Type" => "application/json",
                    ];

                    $response = Http::withHeaders($headers)->post($baseUrl . $listEndpoint, $payload);

                    Log::info('Shein Order List Response', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    if (!$response->successful()) {
                        throw new Exception("Shein API Error: " . $response->body());
                    }

                    $responseInfo = $response->json()['info'] ?? [];
                    $orders = $responseInfo['orderList'] ?? [];
                    $totalCount = $responseInfo['count'] ?? 0;

                    // Filter out completed orders
                    $orders = array_filter($orders, fn($o) => ($o['orderStatus'] ?? 0) != 5);

                    if (empty($orders) && $page == 1) {
                        $this->warn("⚠️ No Shein orders found in window: {$startDate} - {$windowEnd}");
                        break;
                    }

                    foreach ($orders as $order) {
                        $orderId = $order['orderNo'] ?? null;
                        if (!$orderId) continue;

                        // --- Fetch order details ---
                        $detailTimestamp = round(microtime(true) * 1000);
                        $detailRandom = Str::random(5);
                        $detailSignature = $this->generateSheinSignature($detailEndpoint, $detailTimestamp, $detailRandom);

                        $detailRes = Http::withHeaders([
                            "Language" => "en",
                            "x-lt-openKeyId" => env('SHEIN_OPEN_KEY_ID'),
                            "x-lt-timestamp" => $detailTimestamp,
                            "x-lt-signature" => $detailSignature,
                            "Content-Type" => "application/json",
                        ])->post($baseUrl . $detailEndpoint, ["orderNoList" => [$orderId]]);

                        Log::info('Shein Order Detail Response', [
                            'order_id' => $orderId,
                            'status' => $detailRes->status(),
                            'body' => $detailRes->body(),
                        ]);

                        if (!$detailRes->successful()) {
                            Log::error("❌ Shein API detail error", ['order' => $orderId, 'body' => $detailRes->body()]);
                            continue;
                        }

                        $responseArray = $detailRes->json()['info'][0] ?? null;

                        if (!$responseArray) {
                            Log::warning("⚠️ No order info found for order: {$orderId}", ['response' => $detailRes->body()]);
                            continue;
                        }

                        $orderLines = $responseArray['orderGoodsInfoList'] ?? [];
                        $totalQuantity = array_sum(array_column($orderLines, 'goodsNum')) ?: 1;

                        // ✅ Correct order total calculation
                        $productTotal = $responseArray['productTotalPrice'] ?? 0;
                        $shippingCost = $responseArray['sellerShippingFee'] ?? 0;
                        $taxTotal = $responseArray['totalSaleTax'] ?? 0;

                        $orderTotal = $productTotal + $shippingCost + $taxTotal;

                        $address = $responseArray['consigneeInfo'] ?? [];

                        $orderData = [
                            'store_id' => 1,
                            'marketplace' => 'shein',
                            'marketplace_order_id' => $orderId,
                            'order_number' => $orderId,
                            'order_date' => isset($responseArray['orderTime']) ? Carbon::parse($responseArray['orderTime']) : null,
                            'order_age' => isset($responseArray['orderTime']) ? now()->diffInDays(Carbon::parse($responseArray['orderTime'])) : null,
                            'quantity' => $totalQuantity,
                            'order_total' => $orderTotal,
                            'shipping_cost' => $shippingCost,
                            'order_status' => $this->mapSheinStatus($responseArray['orderStatus'] ?? null),
                            'fulfillment_status' => $this->mapSheinStatus($responseArray['orderStatus'] ?? null),
                            'recipient_name' => $address['consigneeName'] ?? null,
                            'recipient_company' => $address['consigneeCompany'] ?? null,
                            'recipient_phone' => $address['consigneeTel'] ?? null,
                            'ship_address1' => $address['consigneeAddress'] ?? null,
                            'ship_city' => $address['consigneeCity'] ?? null,
                            'ship_state' => $address['consigneeState'] ?? null,
                            'ship_postal_code' => $address['consigneeZip'] ?? null,
                            'ship_country' => $address['consigneeCountry'] ?? null,
                            'shipping_service' => $responseArray['optionalLogisticsList'][0] ?? null,
                            'shipping_carrier' => $responseArray['optionalLogisticsList'][0] ?? null,
                            'tracking_id' => $responseArray['packageWaybillList'][0]['waybillNo'] ?? null,
                            'raw_data' => json_encode($responseArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        ];

                        $orderModel = Order::updateOrCreate(
                            ['marketplace' => 'shein', 'order_number' => $orderId],
                            $orderData
                        );

                        foreach ($orderLines as $line) {
                            $itemId = $line['goodsId'] ?? uniqid();
                            $qty = $line['goodsNum'] ?? 1;

                            $itemData = [
                                'order_id' => $orderModel->id,
                                'order_item_id' => $itemId,
                                'sku' => $line['skuCode'] ?? null,
                                'item_name' => $line['goodsTitle'] ?? null,
                                'product_name' => $line['goodsTitle'] ?? null,
                                'quantity_ordered' => $qty,
                                'quantity_shipped' => ($responseArray['orderStatus'] == 5 ? $qty : 0),
                                'unit_price' => $line['sellerCurrencyPrice'] ?? 0,
                                'item_tax' => $line['saleTax'] ?? 0,
                                'promotion_discount' => $line['sellerCurrencyDiscountPrice'] ?? 0,
                                'currency' => $responseArray['orderCurrency'] ?? 'USD',
                                'marketplace' => 'shein',
                            ];

                            OrderItem::updateOrCreate(
                                ['order_id' => $orderModel->id, 'order_item_id' => $itemId, 'marketplace' => 'shein'],
                                $itemData
                            );
                        }

                        $this->info("✅ Synced Shein order: {$orderId}");
                    }

                    $hasMore = ($page * $payload['pageSize']) < $totalCount;
                    $page++;
                }

                $startDate = $windowEnd->copy();
            }

        } catch (Exception $e) {
            $this->error("❌ Error syncing Shein orders: " . $e->getMessage());
            Log::error('Shein order sync error', ['exception' => $e->getMessage()]);
            return 1;
        }

        $this->info('🎉 Shein order sync completed!');
        return 0;
    }

    private function generateSheinSignature($path, $timestamp, $randomKey)
    {
        $openKeyId = env('SHEIN_OPEN_KEY_ID');
        $secretKey = env('SHEIN_SECRET_KEY');

        $value = $openKeyId . "&" . $timestamp . "&" . $path;
        $key = $secretKey . $randomKey;
        $hmacResult = hash_hmac('sha256', $value, $key, false);
        $base64Signature = base64_encode($hmacResult);

        return $randomKey . $base64Signature;
    }

    private function mapSheinStatus($status)
    {
        return match((int)$status) {
            1 => 'pending',
            2 => 'paid',
            3 => 'processing',
            4 => 'shipped',
            5 => 'completed',
            6 => 'cancelled',
            default => 'unknown',
        };
    }
}
