<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;

class MiraklFulfillmentService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://miraklconnect.com/api';
    }

    /**
     * Fulfill a Mirakl order (update tracking info)
     *
     * @param string $orderId Marketplace order ID
     * @param array $items Array of order lines: ['lineNumber'=>..., 'quantity'=>...]
     * @param string $trackingNumber
     * @param string $carrier
     * @param string $shipDate YYYY-MM-DD
     * @return array
     */
public function fulfillOrder(string $orderId, array $items, string $trackingNumber, ?string $carrier): array 
{
    Log::info("Step 1: Starting fulfillOrder for order {$orderId}");

    $token = $this->getAccessToken();
    if (!$token) {
        Log::warning("Step 2: Failed to get Mirakl access token");
        return ['success' => false, 'error' => 'Failed to retrieve Mirakl access token'];
    }
    Log::info("Step 2: Access token retrieved");

   $order = Order::query()
    ->select('orders.*', 'shipments.tracking_url', 'shipments.tracking_number')
    ->leftJoin('shipments', 'shipments.order_id', '=', 'orders.id')
    ->where('orders.order_number', $orderId)
    ->first();

    if (!$order) {
        Log::warning("Step 3: Order not found in database");
        return ['success' => false, 'error' => 'Order not found'];
    }
    Log::info("Step 3: Order found", ['order_id' => $order->id]);

    if ($trackingNumber === 'NA' || empty($trackingNumber)) {
        $trackingNumber = $order->tracking_number ?? 'NA';
        Log::info("Step 4: Tracking number set from order", ['tracking_number' => $trackingNumber]);
    } else {
        Log::info("Step 4: Tracking number provided", ['tracking_number' => $trackingNumber]);
    }

    $trackingUrl = $order->tracking_url ?? 'http://example.com/tracking';
    Log::info("Step 5: Using tracking URL", ['tracking_url' => $trackingUrl]);
    $carrier = str_starts_with($trackingNumber, '1Z') ? 'UPS' : 'USPS';
    $payload = [
        'carrier' => detectCarrier($trackingNumber),
        'tracking_number' => $trackingNumber,
        'tracking_url' => getTrackingUrl($trackingNumber, $carrier),
        'items' => array_map(fn($item) => [
            'order_line_id' => $item['lineNumber'],  
            'quantity' => $item['quantity'],
        ], $items),
    ];
    Log::info("Step 6: Payload prepared", ['payload' => $payload]);

    $endpoint = "{$this->baseUrl}/v2/orders/{$order->marketplace_order_id}/shipments";
    Log::info("Step 7: Endpoint URL prepared", ['endpoint' => $endpoint]);

    try {
        $response = Http::withToken($token)
            ->post($endpoint, $payload);

        Log::info("Step 8: API request sent", [
            'status' => $response->status(),
            'body' => $response->body()
        ]);

        if ($response->successful()) {
            Log::info("Step 9: Fulfillment successful", ['response' => $response->json()]);
            return ['success' => true, 'data' => $response->json()];
        }

        Log::warning("Step 9: Fulfillment failed", ['response' => $response->body()]);
        return ['success' => false, 'error' => $response->body()];

    } catch (\Exception $e) {
        Log::error("Step 10: Exception during fulfillment", ['exception' => $e->getMessage()]);
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


    /**
     * Get Mirakl access token from cache
     */
    private function getAccessToken(): ?string
    {
         return $this->refreshAccessToken();
    }

    /**
     * Refresh Mirakl access token
     */
    private function refreshAccessToken(): ?string
    {
        $response = Http::asForm()->post('https://auth.mirakl.net/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.macy.client_id'),
            'client_secret' => config('services.macy.client_secret'),
        ]);

        if ($response->successful() && isset($response->json()['access_token'])) {
            return $response->json()['access_token'];
        }

        Log::error('Failed to get Macy access token', ['body' => $response->body()]);
        return null;
    }

    /**
     * Format order items (optional helper)
     */
    public static function formatOrderItems(array $items): array
    {
        return array_map(fn($item) => [
            'lineNumber' => $item['lineNumber'],
            'quantity' => $item['quantity'],
        ], $items);
    }
}
