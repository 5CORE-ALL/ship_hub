<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Integration;
use App\Models\Order;
use Carbon\Carbon;
use Exception;

class DobaSyncOrders extends Command
{
    protected $signature = 'doba:sync-orders';
    protected $description = 'Fetch and sync Doba orders and items for the last 30 days into orders table';

    public function handle()
    {
        $this->info('🔄 Syncing Doba orders for the last 30 days...');
        Log::info('Starting Doba order sync process');

        $integration = Integration::where('store_id', 1)->first();
        if (!$integration) {
            $this->error('No integration found for store_id 1.');
            Log::error('Doba integration missing for store_id 1');
            return 1;
        }

        $statuses = [1, 4, 5, 6, 7]; // Doba statuses
        $dateRange = $this->getDateRange();
        Log::info('Date range for API request', [
            'beginTime' => $dateRange['begin'],
            'endTime' => $dateRange['end'],
        ]);

        foreach ($statuses as $status) {
            $page = 1;
            do {
                try {
                    $timestamp = $this->getMillisecond();
                    $contentForSign = $this->getContent($timestamp);
                    $sign = $this->generateSignature($contentForSign);

                    $payload = [
                        'beginTime' => $dateRange['begin'],
                        'endTime' => $dateRange['end'],
                        'pageNo' => $page,
                        'pageSize' => 100,
                        'status' => $status,
                    ];

                    Log::info('Doba API Request', [
                        'url' => 'https://openapi.doba.com/api/seller/queryOrderDetail',
                        'timestamp' => $timestamp,
                        'payload' => $payload,
                        'signature' => $sign,
                    ]);

                    $response = Http::withOptions(['force_ip_resolve' => 'v4'])
                        ->withHeaders([
                            'appKey' => env('DOBA_APP_KEY'),
                            'signType' => 'rsa2',
                            'timestamp' => $timestamp,
                            'sign' => $sign,
                            'Content-Type' => 'application/json',
                        ])->post('https://openapi.doba.com/api/seller/queryOrderDetail', $payload);

                    if (!$response->ok()) {
                        $this->error("❌ API Failed for status $status: " . $response->body());
                        Log::error('Doba API Response Error', [
                            'status' => $status,
                            'page' => $page,
                            'http_status' => $response->status(),
                            'response_body' => $response->body(),
                        ]);
                        break;
                    }

                    $orders = $response->json()['businessData'][0]['data'] ?? [];
                    Log::info('Doba API Response', [
                        'status' => $status,
                        'page' => $page,
                        'order_count' => count($orders),
                        'order_data'=>$orders

                    ]);

                    if (empty($orders)) break;

                    foreach ($orders as $order) {
                        if (!isset($order['orderItemList']) || empty($order['orderItemList'])) continue;

                        $orderId = $order['ordBusiId'] ?? $order['orderNo'] ?? null;
                        if (!$orderId) continue;

                        $totalQuantity = array_sum(array_column($order['orderItemList'], 'quantity')) ?? 1;

                        $orderData = [
                            'store_id' => $integration->store_id,
                            'marketplace' => 'doba',
                            'marketplace_order_id' => $orderId,
                            'order_number' => $orderId,
                            'external_order_id' => $orderId,
                            'order_date' => isset($order['ordTime']) ? Carbon::parse($order['ordTime']) : null,
                            'order_age' => isset($order['ordTime']) ? now()->diffInDays(Carbon::parse($order['ordTime'])) : null,
                            'quantity' => $totalQuantity,
                            'order_total' => $order['orderTotal'] ?? 0,
                            'shipping_service' => $order['shippingMethod'] ?? null,
                            'shipping_carrier' => $order['shippingMethod'] ?? null,
                            'shipping_cost' => $order['shippingSubtotal'] ?? 0,
                            'order_status' =>  $this->mapDobaStatusToShipStation($order['ordStatus'] ?? null),
                            'fulfillment_status' => $this->mapDobaStatusToShipStation($order['ordStatus'] ?? null),
                            'recipient_name' => $order['shippingAddress']['name'] ?? null,
                            'recipient_phone' => $order['shippingAddress']['telephone'] ?? null,
                            'ship_address1' => $order['shippingAddress']['address1'] ?? null,
                            'ship_address2' => $order['shippingAddress']['address2'] ?? null,
                            'ship_city' => $order['shippingAddress']['cityName'] ?? null,
                            'ship_state' => $order['shippingAddress']['provinceName'] ?? null,
                            'ship_postal_code' => $order['shippingAddress']['zip'] ?? null,
                            'ship_country' => $order['shippingAddress']['countryName'] ?? null,
                            'item_sku' => $order['orderItemList'][0]['goodsSkuCode'] ?? null,
                            'item_name' => $order['orderItemList'][0]['goodsName'] ?? null,
                            'raw_data' => json_encode($order),
                        ];

                        $orderModel = Order::updateOrCreate(
                            ['marketplace' => 'doba', 'order_number' => $orderId, 'external_order_id' => $orderId],
                            $orderData
                        );

                        Log::info('Order synced', ['order_id' => $orderId]);
                    }

                    $page++;
                } catch (Exception $e) {
                    $this->error("⚠️ Error processing status $status page $page: " . $e->getMessage());
                    Log::error('Doba order sync error', [
                        'status' => $status,
                        'page' => $page,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    break;
                }
            } while (count($orders) === 100);
        }

        $this->info('✅ Doba order sync completed!');
        Log::info('Doba order sync completed');
        return 0;
    }

    private function mapDobaStatusToShipStation($dobaStatus)
    {
        return match($dobaStatus) {
            'Awaiting Shipment', 'Processing', 'Pending' => 'unshipped',
            'In Transit', 'Delivered' => 'shipped',
            'Closed' => 'completed',
            'Returned' => 'returned',
            'On Hold' => 'on_hold',
            default => 'unshipped',
        };
    }

    private function getDateRange()
    {
        $yesterday = Carbon::yesterday('UTC');
        $start = $yesterday->copy()->subDays(30);
        return [
            'begin' => $start->format('Y-m-d\TH:i:s+00:00'),
            'end' => $yesterday->format('Y-m-d\TH:i:s+00:00'),
        ];
    }

    private function getMillisecond()
    {
        list($s1, $s2) = explode(' ', microtime());
        return intval((float)sprintf('%.0f', (floatval($s1) + floatval($s2)) * 1000));
    }

    private function getContent($timestamp)
    {
        $appKey = env('DOBA_APP_KEY');
        return "appKey={$appKey}&signType=rsa2&timestamp={$timestamp}";
    }

    private function generateSignature($content)
    {
        $privateKeyFormatted = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap(env('DOBA_PRIVATE_KEY'), 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";

        $private_key = openssl_pkey_get_private($privateKeyFormatted);
        if (!$private_key) {
            $error = openssl_error_string();
            throw new Exception("Invalid private key: $error");
        }

        openssl_sign($content, $signature, $private_key, OPENSSL_ALGO_SHA256);
        return base64_encode($signature);
    }
}
