<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AmazonFulfillmentService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com');
    }

    /**
     * Get integration for a store
     */
    protected function getIntegration(int $storeId = 1): Integration
    {
        return Integration::where('store_id', $storeId)->firstOrFail();
    }

    /**
     * Ensure access token is valid or refresh it.
     */
    protected function ensureAccessToken(Integration $integration): void
    {
        if ($integration->expires_at->lt(now())) {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'client_id'     => $integration->app_id,
                'client_secret' => $integration->app_secret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $integration->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $integration->refresh_token,
                    'expires_at'    => now()->addSeconds($data['expires_in']),
                ]);
                Log::info('✅ Amazon access token refreshed for fulfillment.');
            } else {
                Log::error('Amazon token refresh failed for fulfillment', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Amazon token refresh failed');
            }
        }
    }

    /**
     * Map carrier name to Amazon carrier code
     */
    protected function mapCarrierToCode(?string $carrier): string
    {
        if (!$carrier) {
            return 'UNS';
        }

        $carrier = strtoupper(trim($carrier));
        
        $mapping = [
            'UPS' => 'UPS',
            'USPS' => 'USPS',
            'FEDEX' => 'FedEx',
            'DHL' => 'DHL',
            'ONTRAC' => 'OnTrac',
            'AMAZON' => 'Amazon',
            'STANDARD' => 'UNS',
        ];

        return $mapping[$carrier] ?? 'UNS';
    }

    /**
     * Fulfill an Amazon order by confirming shipment
     *
     * @param string $marketplace
     * @param int|null $storeId
     * @param string $orderNumber Amazon order ID
     * @param string|null $trackingNumber
     * @param string|null $carrier
     * @param string|null $shippingServiceCode
     * @return array|null
     */
    public function fulfillOrder(
        string $marketplace,
        ?int $storeId,
        string $orderNumber,
        ?string $trackingNumber = null,
        ?string $carrier = null,
        ?string $shippingServiceCode = null
    ): ?array {
        try {
            $storeId = $storeId ?? 1;
            $integration = $this->getIntegration($storeId);
            $this->ensureAccessToken($integration);

            Log::info("🚚 Creating Amazon fulfillment", [
                'marketplace'  => $marketplace,
                'store_id'     => $storeId,
                'order_number' => $orderNumber,
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
            ]);

            // Get order from database
            $order = Order::with('items')
                ->where('order_number', $orderNumber)
                ->where('marketplace', 'amazon')
                ->first();

            if (!$order) {
                Log::error("❌ Amazon order not found: {$orderNumber}");
                return ['success' => false, 'error' => 'Order not found'];
            }

            // Use Amazon order ID (external_order_id or order_number)
            $amazonOrderId = $order->external_order_id ?? $order->order_number;

            // Get tracking number from shipment if not provided
            if (!$trackingNumber) {
                $shipment = \App\Models\Shipment::where('order_id', $order->id)
                    ->where('label_status', 'active')
                    ->latest()
                    ->first();
                
                if ($shipment && $shipment->tracking_number) {
                    $trackingNumber = $shipment->tracking_number;
                } else {
                    $trackingNumber = $order->tracking_number;
                }
            }

            if (!$trackingNumber) {
                Log::error("❌ No tracking number found for Amazon order: {$orderNumber}");
                return ['success' => false, 'error' => 'Tracking number is required'];
            }

            // Detect carrier if not provided
            if (!$carrier) {
                $carrier = detectCarrier($trackingNumber) ?? 'Standard';
            }

            $carrierCode = $this->mapCarrierToCode($carrier);
            $carrierName = $carrier; // Use the carrier name as-is

            // Build order items array
            // Amazon SP-API expects OrderItemList with specific structure
            $orderItems = [];
            foreach ($order->items as $item) {
                if (!empty($item->order_item_id)) {
                    // Try lowercase field names as Amazon SP-API might expect camelCase
                    $orderItems[] = [
                        'orderItemId' => (string) $item->order_item_id,
                        'quantity' => (int) $item->quantity_ordered,
                    ];
                }
            }

            if (empty($orderItems)) {
                Log::error("❌ No valid order items found for Amazon order: {$orderNumber}", [
                    'items_count' => $order->items->count(),
                    'items' => $order->items->map(fn($i) => ['id' => $i->id, 'order_item_id' => $i->order_item_id])->toArray(),
                ]);
                return ['success' => false, 'error' => 'No valid order items found'];
            }
            
            Log::info("Amazon order items prepared", [
                'orderItems' => $orderItems,
                'count' => count($orderItems),
                'raw_items' => $order->items->toArray(),
            ]);

            // Build payload
            // Amazon SP-API requires marketplaceId in the request body
            $marketplaceId = 'ATVPDKIKX0DER'; // Amazon US marketplace ID
            
            // Amazon SP-API shipment confirmation payload structure
            // According to Amazon SP-API docs: orderItems must be INSIDE packageDetail
            // Also requires shippingMethod field
            $payload = [
                'marketplaceId' => $marketplaceId,
                'packageDetail' => [
                    'packageReferenceId' => 1,
                    'carrierCode' => $carrierCode,
                    'carrierName' => $carrierName,
                    'shippingMethod' => $shippingServiceCode ?? 'Standard', // Required field
                    'trackingNumber' => $trackingNumber,
                    'shipDate' => now()->toIso8601String(),
                    'orderItems' => $orderItems, // Must be INSIDE packageDetail, not at root
                ],
            ];
            
            // Log payload for debugging (remove sensitive data in production)
            Log::info("Amazon fulfillment payload prepared", [
                'order_number' => $orderNumber,
                'marketplaceId' => $marketplaceId,
                'orderItems_count' => count($orderItems),
            ]);

            // Call Amazon SP-API to confirm shipment
            $endpoint = "{$this->baseUrl}/orders/v0/orders/{$amazonOrderId}/shipmentConfirmation";

            Log::info("Amazon fulfillment API request", [
                'endpoint' => $endpoint,
                'marketplaceId' => $marketplaceId,
                'amazonOrderId' => $amazonOrderId,
                'payload' => $payload,
                'full_url' => $endpoint,
            ]);

            // Make the request with marketplaceId in body
            $response = Http::withHeaders([
                'Authorization'      => 'Bearer ' . $integration->access_token,
                'x-amz-access-token' => $integration->access_token,
                'Content-Type'       => 'application/json',
            ])->post($endpoint, $payload);
            
            Log::info("Amazon fulfillment API response", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if ($response->failed()) {
                Log::error("❌ Amazon fulfillment API failed", [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'order_number' => $orderNumber,
                ]);
                return [
                    'success' => false,
                    'error' => $response->body(),
                    'status' => $response->status(),
                ];
            }

            $data = $response->json();
            Log::info("✅ Amazon fulfillment created successfully", [
                'order_number' => $orderNumber,
                'response' => $data,
            ]);

            return [
                'success' => true,
                'data' => $data,
            ];

        } catch (\Exception $e) {
            Log::error("❌ Amazon fulfillment exception", [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}

