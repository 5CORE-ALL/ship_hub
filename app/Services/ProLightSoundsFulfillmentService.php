<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class ProLightSoundsFulfillmentService
{
    protected string $shopUrl;
    protected string $apiKey;
    protected string $password;
    protected string $version;

    public function __construct()
    {
        // $this->shopUrl  = env('PROLIGHTSOUNDS_SHOPIFY_DOMAIN');
        // $this->apiKey   = env('PROLIGHTSOUNDS_SHOPIFY_API_KEY');
        // $this->password = env('PROLIGHTSOUNDS_SHOPIFY_PASSWORD');
        // $this->version  = "2025-07";
        $this->shopUrl  = env('PROLIGHTSOUNDS_SHOPIFY_DOMAIN', '5core-wholesale.myshopify.com');
        $this->apiKey   = env('PROLIGHTSOUNDS_SHOPIFY_API_KEY', '');
        $this->password = env('PROLIGHTSOUNDS_SHOPIFY_PASSWORD', '');
        $this->version  = "2025-07";
    }

    /**
     * Fulfill a ProLightSounds Shopify order
     */
    public function fulfillOrder(
        string $marketplace,
        ?int $storeId,
        string $orderNumber,
        ?string $trackingNumber = null,
        ?string $carrier = null,
        ?string $shippingServiceCode = null
    ): ?array {
        Log::info("🚚 Creating PLS fulfillment", [
            'marketplace'  => $marketplace,
            'store_id'     => $storeId,
            'order_number' => $orderNumber
        ]);

        $order = Order::with('items')
            ->where('marketplace', 'PLS')
            ->where('order_number', $orderNumber)
            ->first();

        if (!$order) {
            Log::error("❌ PLS Order not found", [
                'store_id' => $storeId,
                'order_number' => $orderNumber
            ]);
            return null;
        }

        // Get fulfillment orders
        $fulfillmentOrdersUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/orders/{$order->marketplace_order_id}/fulfillment_orders.json";

        $fulfillmentOrdersResp = Http::get($fulfillmentOrdersUrl);
        if ($fulfillmentOrdersResp->failed()) {
            Log::error("❌ PLS Fulfillment Orders API Error", [
                'body' => $fulfillmentOrdersResp->body()
            ]);
            return null;
        }

        $fulfillmentOrders = $fulfillmentOrdersResp->json()['fulfillment_orders'] ?? [];
        $fulfillmentOrderId = $fulfillmentOrders[0]['id'] ?? null;

        if (!$fulfillmentOrderId) {
            Log::warning("⚠️ No fulfillment order found for PLS order", [
                'order_number' => $orderNumber
            ]);
            return null;
        }

        $lineItems = $order->items->map(fn($item) => [
            'id'       => $item->order_item_id,
            'quantity' => $item->quantity_ordered,
        ])->toArray();

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
                'url' => "https://{$this->shopUrl}/tracking/{$trackingNumber}"
            ];
        }

        $createFulfillmentUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/fulfillments.json";
        $response = Http::post($createFulfillmentUrl, $payload);

        if ($response->failed()) {
            Log::error("❌ PLS Fulfillment API Error", [
                'body' => $response->body()
            ]);
            return null;
        }

        $data = $response->json();
        Log::info("✅ PLS Fulfillment created successfully", [
            'response' => $data
        ]);

        if (isset($data['fulfillment']['id'])) {
            $order->update(['marketplace_order_id' => $data['fulfillment']['id']]);
        }

        return $data;
    }
}
