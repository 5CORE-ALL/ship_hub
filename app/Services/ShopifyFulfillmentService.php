<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class ShopifyFulfillmentService
{
    protected string $shopUrl;
    protected string $apiKey;
    protected string $password;
    protected string $version;

    public function __construct()
    {
              $this->shopUrl = env('SHOPIFY_DOMAIN') ?: '5-core.myshopify.com';
        // $this->apiKey   = env('SHOPIFY_API_KEY');
        // $this->password = env('SHOPIFY_PASSWORD');
                $this->apiKey   = env('SHOPIFY_API_KEY') ?: '';
                $this->password = env('SHOPIFY_PASSWORD') ?: '';
                $this->version  = "2025-01";
        $this->version  = "2025-01";
    }

    /**
     * Fulfill Shopify order
     */
    // public function fulfillOrder(
    //     string $marketplace,
    //     $storeId,
    //     string $orderNumber,
    //     ?string $trackingNumber = null
    // ): ?array {
    //     Log::info("🚚 Creating fulfillment", [
    //         'marketplace'  => $marketplace,
    //         'store_id'     => $storeId,
    //         'order_number' => $orderNumber
    //     ]);

    //     $order = Order::with('items')
    //         ->where('store_id', $storeId)
    //         ->where('order_number', $orderNumber)
    //         ->first();

    //     if (!$order) {
    //         Log::error("❌ {$marketplace} Order not found", [
    //             'store_id'     => $storeId,
    //             'order_number' => $orderNumber
    //         ]);
    //         return null;
    //     }

    //     $lineItems = $order->items->map(fn($item) => [
    //         'id'       => $item->order_item_id,
    //         'quantity' => $item->quantity_ordered,
    //     ])->toArray();

    //     $url = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/orders/{$order->order_number}/fulfillments.json";

    //     $payload = [
    //         "fulfillment" => [
    //             "notify_customer" => true,
    //             "line_items"      => $lineItems,
    //         ],
    //     ];

    //     if ($trackingNumber) {
    //         $payload["fulfillment"]["tracking_number"] = $trackingNumber;
    //     }

    //     $response = Http::post($url, $payload);
    //     dd($response);

    //     if ($response->failed()) {
    //         Log::error("❌ Shopify Fulfillment API Error", [
    //             'body' => $response->body()
    //         ]);
    //         return null;
    //     }

    //     $data = $response->json();

    //     Log::info("✅ Shopify Fulfillment created successfully", [
    //         'response' => $data
    //     ]);

    //     return $data;
    // }
public function fulfillOrder(
    string $marketplace,
    $storeId,
    string $orderNumber,
    ?string $trackingNumber = null
): ?array {
    Log::info("🚚 Creating fulfillment", [
        'marketplace'  => $marketplace,
        'store_id'     => $storeId,
        'order_number' => $orderNumber
    ]);

    $order = Order::with('items')
        // ->where('store_id', $storeId)
        ->where('order_number', $orderNumber)
        ->first();

    if (!$order) {
        Log::error("❌ {$marketplace} Order not found", [
            'store_id'     => $storeId,
            'order_number' => $orderNumber
        ]);
        return null;
    }

    // Step 1: Get fulfillment orders for this order
    $fulfillmentOrdersUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/orders/{$order->marketplace_order_id}/fulfillment_orders.json";

    $fulfillmentOrdersResp = Http::get($fulfillmentOrdersUrl);
    if ($fulfillmentOrdersResp->failed()) {
        Log::error("❌ Shopify Fulfillment Orders API Error", [
            'body' => $fulfillmentOrdersResp->body()
        ]);
        return null;
    }

    $fulfillmentOrders = $fulfillmentOrdersResp->json()['fulfillment_orders'] ?? [];
  
    $fulfillmentOrderId = $fulfillmentOrders[0]['id'] ?? null;
 

    if (!$fulfillmentOrderId) {
        Log::warning("⚠️ No fulfillment order found for Shopify order", [
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

    if ($order->tracking_number) {
        $payload['fulfillment']['tracking_info'] = [
            'number' => $trackingNumber,
            'url' => "https://{$this->shopUrl}/tracking/{$order->tracking_number}"
        ];
    }

    $createFulfillmentUrl = "https://{$this->apiKey}:{$this->password}@{$this->shopUrl}/admin/api/{$this->version}/fulfillments.json";

    $response = Http::post($createFulfillmentUrl, $payload);



    if ($response->failed()) {
        Log::error("❌ Shopify Fulfillment API Error", [
            'body' => $response->body()
        ]);
        return null;
    }

    $data = $response->json();

    Log::info("✅ Shopify Fulfillment created successfully", [
        'response' => $data
    ]);
    if (isset($data['fulfillment']['id'])) {
        $order->update(['marketplace_order_id' => $data['fulfillment']['id']]);
    }

    return $data;
}



}
