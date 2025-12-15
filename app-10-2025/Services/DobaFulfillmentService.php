<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;
use Exception;

class DobaFulfillmentService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://openapi.doba.com/api';
    }

    /**
     * Fulfill a Doba order
     *
     * @param string $purchaseOrderId Doba order number
     * @param array $items Array of items: ['goodsSkuCode'=>..., 'quantity'=>...]
     * @param string $trackingNumber Tracking number of shipment
     * @param string $carrier Carrier name
     * @param string $shipDate YYYY-MM-DD format
     * @return array
     */
    public function fulfillOrder(
        string $purchaseOrderId,
        array $items,
        string $trackingNumber,
        string $carrier,
        string $shipDate
    ): array {
        try {
            $order = Order::where('marketplace_order_id', $purchaseOrderId)
                ->where('marketplace', 'doba')
                ->first();

            if (!$order) {
                Log::warning("Doba order not found for fulfillment", ['order_number' => $purchaseOrderId]);
                return ['success' => false, 'error' => 'Order not found'];
            }

            // If trackingNumber is "NA", try fetching from local shipment
            if ($trackingNumber === 'NA') {
                $shipment = Shipment::where('order_id', $order->id)->latest('id')->first();
                if ($shipment && $shipment->tracking_number) {
                    $trackingNumber = $shipment->tracking_number;
                }
            }

            $shipDateTime = Carbon::parse($shipDate)->toIso8601String();

            // Prepare payload for Doba API
            $timestamp = $this->getMillisecond();
            $contentForSign = $this->getContent($timestamp);
            $sign = $this->generateSignature($contentForSign);

            $orderItems = [];
            foreach ($items as $item) {
                $orderItems[] = [
                    'goodsSkuCode' => $item['goodsSkuCode'],
                    'quantity' => $item['quantity'],
                    'trackingNumber' => $trackingNumber,
                    'logisticsCompany' => $carrier,
                    'shipTime' => $shipDateTime,
                ];
            }

            $payload = ['orderNo' => $purchaseOrderId, 'orderItemList' => $orderItems];

            Log::info("Doba Fulfillment API Request", ['payload' => $payload]);

            $response = Http::withOptions(['force_ip_resolve' => 'v4'])
                ->withHeaders([
                    'appKey' => env('DOBA_APP_KEY'),
                    'signType' => 'rsa2',
                    'timestamp' => $timestamp,
                    'sign' => $sign,
                    'Content-Type' => 'application/json',
                ])
                ->post("{$this->baseUrl}/seller/updateOrderLogistics", $payload);

            if (!$response->ok()) {
                Log::error("Doba API fulfillment failed", [
                    'order_number' => $purchaseOrderId,
                    'tracking_number' => $trackingNumber,
                    'carrier' => $carrier,
                    'response' => $response->body(),
                ]);
                return ['success' => false, 'error' => $response->body()];
            }

            // Update locally
            $order->update([
                'tracking_number' => $trackingNumber,
                'shipping_carrier' => $carrier,
                'fulfillment_status' => 'shipped',
            ]);

            Log::info("Doba order fulfillment updated locally", [
                'order_number' => $purchaseOrderId,
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
            ]);

            return ['success' => true, 'data' => $response->json()];
        } catch (Exception $e) {
            Log::error("Error fulfilling Doba order", [
                'order_number' => $purchaseOrderId,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
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
