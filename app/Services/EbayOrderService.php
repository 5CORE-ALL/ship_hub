<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EbayOrderService
{
    /**
     * Update eBay order after shipment label creation
     *
     * @param int $storeId
     * @param string $marketplaceOrderId
     * @param string|null $trackingNumber
     * @param string|null $carrier
     * @param string $status
     * @return bool
     */
public function updateAfterLabelCreate(
    int $storeId,
    string $marketplaceOrderId,
    ?string $trackingNumber = null,
    ?string $carrier = null,
    ?string $shippingServiceCode = null
): bool {
    $accessToken = $this->getAccessToken($storeId);
    if (!$accessToken) {
        Log::error("eBay access token not available for store {$storeId}");
        return false;
    }

    try {
        $order = \App\Models\Order::where('order_number', $marketplaceOrderId)->first();
        if (!$order) {
            Log::error("Order {$marketplaceOrderId} not found in database");
            return false;
        }

        $orderItems = \App\Models\OrderItem::where('order_id', $order->id)->get();

        $lineItems = [];
        foreach ($orderItems as $item) {
            if (!empty($item->order_item_id)) {
                $lineItems[] = [
                    'lineItemId' => $item->order_item_id,
                    'quantity'   => $item->quantity_ordered,
                ];
            }
        }
        if (empty($lineItems)) {
            Log::error("No valid line items found for order {$marketplaceOrderId}");
            return false;
        }

        // Build payload
        $payload = [
            'lineItems'   => $lineItems,
            'shippedDate' => now()->toIso8601String(),
        ];
        
        // Use the tracking number passed in, or fall back to the latest shipment
        if ($trackingNumber === null) {
            $shipment = \App\Models\Shipment::where('order_id', $order->id)->latest()->first();
            $trackingNumber = $shipment->tracking_number ?? null;
        }
        
        if ($trackingNumber !== null) {
            $payload['shippingCarrierCode'] = detectCarrier($trackingNumber) ?? $carrier;
            $payload['trackingNumber'] = $trackingNumber;
        }
        
        // if (!empty($order->shipping_carrier)) {
        //     $payload['shippingCarrierCode'] = detectCarrier($order->tracking_number);
        // }
        // if (!empty($order->tracking_number)) {
        //     $payload['trackingNumber'] = $order->tracking_number;
        // }

        if (!empty($order->shipping_service)) {
            $payload['shippingServiceCode'] = $order->shipping_service;
        }

        // Log full payload for debugging
        Log::info("eBay fulfillment payload for order {$marketplaceOrderId}: " . json_encode($payload, JSON_PRETTY_PRINT));

        // Call eBay Fulfillment API
        $response = Http::withToken($accessToken)
            ->post("https://api.ebay.com/sell/fulfillment/v1/order/{$marketplaceOrderId}/shipping_fulfillment", $payload);

        if (!$response->successful()) {
            throw new \Exception($response->body());
        }

        Log::info("✅ eBay order {$marketplaceOrderId} marked as fulfilled");
        return true;

    } catch (\Exception $e) {
        Log::error("Failed to update eBay order {$marketplaceOrderId}: " . $e->getMessage());
        return false;
    }
}


    /**
     * Update eBay order after label cancellation
     *
     * @param int $storeId
     * @param string $marketplaceOrderId
     * @param string $status
     * @return bool
     */
   public function updateAfterLabelCancel(int $storeId, string $marketplaceOrderId, string $status = 'LABEL_CANCELLED'): bool
{
    $accessToken = $this->getAccessToken($storeId);
    if (!$accessToken) {
        Log::error("eBay access token not available for store {$storeId}");
        return false;
    }

    try {
        $order = \App\Models\Order::where('order_number', $marketplaceOrderId)->first();
        if (!$order) {
            Log::error("Order {$marketplaceOrderId} not found in database");
            return false;
        }

        $orderItems = \App\Models\OrderItem::where('order_id', $order->id)->get();
        $lineItems = [];
        foreach ($orderItems as $item) {
            if (!empty($item->order_items_id)) {
                $lineItems[] = [
                    'lineItemId' => $item->order_items_id,
                    'quantity'   => $item->quantity_ordered,
                ];
            }
        }

        if (empty($lineItems)) {
            Log::error("No valid line items found for order {$marketplaceOrderId}");
            return false;
        }

        // Internal update only
        $order->fulfillment_status = $status;
        $order->save();

        Log::info("Order {$marketplaceOrderId} marked as {$status} internally.");
        
        // Optional: add eBay note/comment
        if ($accessToken) {
            $payload = [
                'message' => "Shipment label was cancelled. No fulfillment was sent.",
                'visibility' => 'SELLER_ONLY', // or 'BUYER_AND_SELLER'
            ];

            // Replace with actual eBay API endpoint to add comment/note
            // $response = Http::withToken($accessToken)
            //     ->post("https://api.ebay.com/sell/fulfillment/v1/order/{$marketplaceOrderId}/order_note", $payload);

            Log::info("eBay note added for order {$marketplaceOrderId} cancellation.");
        }

        return true;

    } catch (\Exception $e) {
        Log::error("Failed to cancel label for order {$marketplaceOrderId}: " . $e->getMessage());
        return false;
    }
}


    /**
     * Get or refresh eBay access token
     *
     * @param int $storeId
     * @return string|null
     */
    public function getAccessToken(int $storeId): ?string
    {
        $integration = DB::table('integrations')->where('store_id', $storeId)->first();

        if (!$integration) {
            Log::warning("No integration found for store_id {$storeId}");
            return null;
        }

        // Check if we have required credentials for token refresh
        if (!$integration->refresh_token || !$integration->app_id || !$integration->app_secret) {
            Log::warning("Missing credentials for store_id {$storeId}. Required: refresh_token, app_id, app_secret");
            return null;
        }

        // If access_token exists and is still valid, return it
        if ($integration->access_token && $integration->expires_at && Carbon::parse($integration->expires_at)->gt(now())) {
            return $integration->access_token;
        }

        // Token expired or missing, try to refresh
        $response = Http::asForm()->withBasicAuth($integration->app_id, $integration->app_secret)
            ->post('https://api.ebay.com/identity/v1/oauth2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token,
                'scope' => 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly'
            ]);

        Log::info('eBay token refresh response', [
            'store_id' => $storeId,
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if ($response->successful()) {
            $data = $response->json();
            DB::table('integrations')->where('store_id', $storeId)->update([
                'access_token' => $data['access_token'],
                'expires_at'   => now()->addSeconds($data['expires_in']),
            ]);

            return $data['access_token'];
        } else {
            Log::warning("❌ Refresh token invalid for store {$storeId}: " . $response->body());
            return null;
        }
    }
    public function getValidTrackingRate(int $storeId): array
   {
    try {
        $accessToken = $this->getAccessToken($storeId);

        if (!$accessToken) {
            return [
                'success' => false,
                'message' => "eBay access token not available for store {$storeId}",
            ];
        }

        $url = "https://api.ebay.com/sell/analytics/v1/seller_standards_profile";

        $response = Http::withToken($accessToken)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->get($url);

        if ($response->failed()) {
            Log::error("❌ Failed to fetch eBay VTR for store {$storeId}: " . $response->body());
            return [
                'success' => false,
                'message' => 'Failed to fetch seller standards: ' . $response->body(),
            ];
        }

        $data = $response->json();
        $profile = $data['standardsProfiles'][0] ?? null;

        if (!$profile || empty($profile['metrics'])) {
            return [
                'success' => false,
                'message' => 'Standards profile or metrics not found',
                'data' => $data,
            ];
        }

        $vtrMetric = collect($profile['metrics'])
            ->firstWhere('metricKey', 'VALID_TRACKING_UPLOADED_WITHIN_HANDLING_RATE');

        if (!$vtrMetric) {
            return [
                'success' => false,
                'message' => 'Valid Tracking Rate metric not found',
                'data' => $data,
            ];
        }

        return [
            'success' => true,
            'channel' => 'eBay',
            'vtr' => $vtrMetric['value']['value'] ?? null,
            'numerator' => $vtrMetric['value']['numerator'] ?? null,
            'denominator' => $vtrMetric['value']['denominator'] ?? null,
            'thresholdLower' => $vtrMetric['thresholdLowerBound']['value'] ?? null,
        ];

    } catch (\Exception $e) {
        Log::error("⚠️ Exception while fetching eBay VTR for store {$storeId}: " . $e->getMessage());
        return [
            'success' => false,
            'message' => $e->getMessage(),
        ];
    }
}
}
