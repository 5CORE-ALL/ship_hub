<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class BusinessFiveCoreShopifyFulfillmentService
{
    protected string $shopUrl;
    protected string $apiKey;
    protected string $password;
    protected string $version;

    public function __construct()
    {
        $this->shopUrl  = config('services.shopify_5core.domain');
        $this->apiKey   = config('services.shopify_5core.api_key');
        $this->password = config('services.shopify_5core.password');
        $this->version  = "2025-07"; // same as other
    }

    public function fulfillOrder(
        string $marketplace,
        $storeId,
        string $orderNumber,
        ?string $trackingNumber = null
    ): ?array {
        Log::info("🚚 [Business 5core] Creating fulfillment", [
            'marketplace'  => $marketplace,
            'store_id'     => $storeId,
            'order_number' => $orderNumber
        ]);

        $order = Order::with('items')
            ->where('order_number', $orderNumber)
            ->where('marketplace', 'Business 5core')
            ->first();

        if (!$order) {
            Log::error("❌ {$marketplace} (Business 5core) Order not found", [
                'store_id'     => $storeId,
                'order_number' => $orderNumber
            ]);
            return null;
        }

        // Get fulfillment orders
        $fulfillmentOrdersUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/orders/{$order->marketplace_order_id}/fulfillment_orders.json";

        $fulfillmentOrdersResp = Http::get($fulfillmentOrdersUrl);
        if ($fulfillmentOrdersResp->failed()) {
            Log::error("❌ [Business 5core] Fulfillment Orders API Error", [
                'body' => $fulfillmentOrdersResp->body()
            ]);
            return null;
        }

        $fulfillmentOrders = $fulfillmentOrdersResp->json()['fulfillment_orders'] ?? [];
        $fulfillmentOrderId = $fulfillmentOrders[0]['id'] ?? null;

        if (!$fulfillmentOrderId) {
            Log::warning("⚠️ [Business 5core] No fulfillment order found", [
                'order_number' => $orderNumber
            ]);
            return null;
        }

        $payload = [
            'fulfillment' => [
                'notify_customer' => true,
                'line_items_by_fulfillment_order' => [
                    ['fulfillment_order_id' => $fulfillmentOrderId]
                ]
            ]
        ];

        if ($trackingNumber) {
            $payload['fulfillment']['tracking_info'] = [
                'number' => $trackingNumber,
                'url'    => "https://{$this->shopUrl}/tracking/{$trackingNumber}"
            ];
        }

        $createFulfillmentUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/fulfillments.json";

        $response = Http::post($createFulfillmentUrl, $payload);

        if ($response->failed()) {
            Log::error("❌ [Business 5core] Fulfillment API Error", [
                'body' => $response->body()
            ]);
            return null;
        }

        $data = $response->json();

        Log::info("✅ [Business 5core] Fulfillment created successfully", [
            'response' => $data
        ]);

        if (isset($data['fulfillment']['id'])) {
            $order->update(['marketplace_order_id' => $data['fulfillment']['id']]);
        }

        return $data;
    }
}
