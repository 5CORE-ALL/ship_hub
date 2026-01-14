<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
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
                        
                        // Determine label requirements
                        $orderStatus = $order['ordStatus'] ?? null;
                        $trackingNumber = $order['trackingNumber'] ?? $order['logisticsNumber'] ?? null;
                        $labelUrl = $order['labelUrl'] ?? $order['shippingLabelUrl'] ?? null;
                        $hasLabelUrl = !empty($labelUrl);
                        
                        // Label is required if order needs shipping and no tracking/label exists
                        $labelRequired = in_array($orderStatus, [1, 4, 5]) && empty($trackingNumber) && !$hasLabelUrl;
                        
                        // Label is provided if customer has provided tracking or label URL
                        $labelProvided = !empty($trackingNumber) || $hasLabelUrl;
                        
                        // Download and store label file if URL is provided
                        $labelFilePath = null;
                        if ($hasLabelUrl && $labelUrl) {
                            try {
                                $labelFilePath = $this->downloadAndStoreLabel($labelUrl, $orderId);
                            } catch (Exception $e) {
                                Log::warning('Failed to download DOBA label', [
                                    'order_id' => $orderId,
                                    'label_url' => $labelUrl,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }

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
                            'tracking_number' => $trackingNumber,
                            'order_status' =>  $this->mapDobaStatusToShipStation($orderStatus),
                            'fulfillment_status' => $this->mapDobaStatusToShipStation($orderStatus),
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
                            'doba_label_required' => $labelRequired,
                            'doba_label_provided' => $labelProvided,
                            'doba_label_file' => $labelFilePath,
                            'doba_label_sku' => $labelProvided ? ($order['orderItemList'][0]['goodsSkuCode'] ?? null) : null,
                            'raw_data' => json_encode($order),
                        ];

                        $orderModel = Order::updateOrCreate(
                            ['marketplace' => 'doba', 'order_number' => $orderId, 'external_order_id' => $orderId],
                            $orderData
                        );

                        // Create/update order items
                        foreach ($order['orderItemList'] as $item) {
                            $itemSku = $item['goodsSkuCode'] ?? null;
                            if (!$itemSku) continue;

                            OrderItem::updateOrCreate(
                                [
                                    'order_id' => $orderModel->id,
                                    'sku' => $itemSku,
                                ],
                                [
                                    'order_number' => $orderId,
                                    'order_item_id' => $item['orderItemId'] ?? $item['goodsSkuCode'] ?? null,
                                    'product_name' => $item['goodsName'] ?? null,
                                    'quantity_ordered' => $item['quantity'] ?? 1,
                                    'unit_price' => $item['goodsPrice'] ?? $item['price'] ?? 0,
                                    'marketplace' => 'doba',
                                    'raw_data' => json_encode($item),
                                ]
                            );
                        }

                        Log::info('DOBA order synced', [
                            'order_id' => $orderId,
                            'label_required' => $labelRequired,
                            'label_provided' => $labelProvided,
                            'items_count' => count($order['orderItemList'])
                        ]);
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

    /**
     * Download and store label file from URL
     *
     * @param string $labelUrl
     * @param string $orderId
     * @return string|null Path to stored file or null if failed
     */
    private function downloadAndStoreLabel(string $labelUrl, string $orderId): ?string
    {
        try {
            $response = Http::timeout(30)->get($labelUrl);
            
            if (!$response->ok()) {
                Log::warning('Failed to download DOBA label', [
                    'order_id' => $orderId,
                    'label_url' => $labelUrl,
                    'http_status' => $response->status()
                ]);
                return null;
            }

            // Determine file extension from URL or content type
            $extension = 'pdf'; // Default to PDF
            $contentType = $response->header('Content-Type');
            if (stripos($contentType, 'pdf') !== false) {
                $extension = 'pdf';
            } elseif (stripos($contentType, 'image') !== false) {
                if (stripos($contentType, 'jpeg') !== false || stripos($contentType, 'jpg') !== false) {
                    $extension = 'jpg';
                } elseif (stripos($contentType, 'png') !== false) {
                    $extension = 'png';
                }
            } else {
                // Try to get extension from URL
                $urlPath = parse_url($labelUrl, PHP_URL_PATH);
                if ($urlPath) {
                    $urlExtension = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                    if (in_array($urlExtension, ['pdf', 'jpg', 'jpeg', 'png'])) {
                        $extension = $urlExtension === 'jpeg' ? 'jpg' : $urlExtension;
                    }
                }
            }

            // Ensure directory exists
            $labelDir = storage_path('app/public/doba_labels');
            if (!file_exists($labelDir)) {
                mkdir($labelDir, 0755, true);
            }

            // Store file
            $fileName = 'doba_labels/' . $orderId . '_' . time() . '.' . $extension;
            Storage::disk('public')->put($fileName, $response->body());

            Log::info('DOBA label downloaded and stored', [
                'order_id' => $orderId,
                'file_path' => $fileName
            ]);

            return $fileName;
        } catch (Exception $e) {
            Log::error('Error downloading DOBA label', [
                'order_id' => $orderId,
                'label_url' => $labelUrl,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
