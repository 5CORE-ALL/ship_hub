<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\Order;
use App\Models\Shipment;
use Carbon\Carbon;

class WalmartFulfillmentService
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = 'https://marketplace.walmartapis.com';
    }

    /**
     * Fulfill a Walmart order
     * 
     * @param string $purchaseOrderId Walmart order ID
     * @param array $items Array of order lines: ['lineNumber'=>..., 'quantity'=>..., 'acknowledgedQuantity'=>...]
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
    $tokenData = $this->refreshAccessToken();
    if (!$tokenData || !isset($tokenData['accessToken'])) {
        return ['success' => false, 'error' => 'Failed to retrieve Walmart access token'];
    }
    $accessToken = $tokenData['accessToken'];

    $order = Order::query()
    ->select('orders.*', 'shipments.tracking_url', 'shipments.tracking_number')
    ->leftJoin('shipments', 'shipments.order_id', '=', 'orders.id')
    ->where('orders.order_number', $purchaseOrderId)
    ->first();

      $purchaseOrderId=$order->marketplace_order_id;
    $endpoint = "{$this->baseUrl}/v3/orders/{$purchaseOrderId}/shipping";
      try {
        $shipDateTime = Carbon::parse($shipDate ?: now())->toIso8601String();
    } catch (\Exception $e) {
        Log::warning("Invalid ship date passed: {$shipDate}, defaulting to now", ['error' => $e->getMessage()]);
        $shipDateTime = now()->toIso8601String();
    }
    $trackingUrl=$order->tracking_url;
    if($trackingNumber=="NA")
    {
         $shipment = Shipment::where('order_id', $order->id)
        ->latest('id')
        ->first();
        if ($shipment && $shipment->tracking_number) {
          $trackingNumber = $shipment->tracking_number;
       } 
    }
    $orderLines = [];
    $carriernm = $this->detectCarrier($trackingNumber);
    $trackingUrls = $this->getTrackingUrl($trackingNumber, $carriernm);
    foreach ($items as $item) {
        $orderLines[] = [
            "lineNumber" => $item['lineNumber'],
            "intentToCancelOverride" => false,
            "sellerOrderId" => $item['sellerOrderId'] ?? 'DEFAULT_SELLER_ORDER_ID',
            "orderLineStatuses" => [
                "orderLineStatus" => [
                    [
                        "status" => "Shipped",
                        "statusQuantity" => [
                            "unitOfMeasurement" => "EACH",
                            "amount" => $item['quantity'] ?? 1,
                        ],
                        "trackingInfo" => [
                            "shipDateTime" => Carbon::parse($shipDate)->toIso8601String(),
                            "carrierName" => ["carrier" => $carriernm],
                            "methodCode" => "Standard",
                            "trackingNumber" => $trackingNumber,
                            "trackingURL" => $trackingUrls ?? ''
                        ],
                        "returnCenterAddress" => $item['returnCenterAddress'] ?? [
                            "name" => "Walmart",
                            "address1" => "Walmartstore2",
                            "city" => "Huntsville",
                            "state" => "AL",
                            "postalCode" => "35805",
                            "country" => "USA",
                            "dayPhone" => "12344",
                            "emailId" => "walmart@walmart.com"
                        ]
                    ]
                ]
            ]
        ];
    }

    $payload = json_encode(["orderShipment" => ["orderLines" => ["orderLine" => $orderLines]]]);
    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "accept: application/json",
        "WM_SEC.ACCESS_TOKEN: $accessToken",
        "WM_QOS.CORRELATION_ID: " . Str::uuid(),
        "WM_SVC.NAME: Walmart Marketplace",
        "Content-Type: application/json",
        "Authorization: Bearer $accessToken",
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log raw response

    Log::info("Walmart Shipping Raw Response (cURL)", [
        'purchaseOrderId' => $purchaseOrderId,
        'payload' => $payload,
        'raw_response' => $response,
        'http_code' => $httpCode,
        'curl_error' => $curlError
    ]);
   

    if ($response && $httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'data' => json_decode($response, true)];
    }

    return ['success' => false, 'error' => $curlError ?: $response];
}



    /**
     * Refresh Walmart access token
     *
     * @return array|null
     */
    private function refreshAccessToken()
    {
        $clientId = config('services.walmart.client_id');
        $clientSecret = config('services.walmart.client_secret');

        $authHeader = 'Basic ' . base64_encode($clientId . ':' . $clientSecret);

        $response = Http::asForm()->withHeaders([
            'Authorization' => $authHeader,
            'WM_SVC.NAME' => 'Walmart Marketplace',
            'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
        ])->post('https://marketplace.walmartapis.com/v3/token', [
            'grant_type' => 'client_credentials',
        ]);

        if ($response->ok()) {
            $xml = simplexml_load_string($response->body());
            $json = json_encode($xml);
            return json_decode($json, true);
        }

        return null;
    }

    /**
     * Format items for Walmart API
     *
     * @param array $items ['lineNumber'=>..., 'quantity'=>..., 'acknowledgedQuantity'=>...]
     * @return array
     */
    public static function formatOrderItems(array $items): array
    {
        return array_map(fn($item) => [
            'lineNumber' => $item['lineNumber'],
            'quantity' => $item['quantity'],
            'acknowledgedQuantity' => $item['acknowledgedQuantity'] ?? $item['quantity'],
        ], $items);
    }
    /**
 * Detect carrier name based on tracking number prefix
 *
 * @param string $trackingNumber
 * @return string
 */
    private function detectCarrier(string $trackingNumber): string
    {
        $trackingNumber = strtoupper($trackingNumber);

        // Common carriers by prefix patterns
        $carriers = [
            'UPS' => '/^(1Z|T|Z)/',              
            'FedEx' => '/^\d{12,15}$/',           
            'USPS' => '/^(94|92|93|95|96|94)/',
            'DHL' => '/^\d{10}$/',             
            'Amazon Logistics' => '/^TBA\d+/'
        ];

        foreach ($carriers as $carrierName => $pattern) {
            if (preg_match($pattern, $trackingNumber)) {
                return $carrierName;
            }
        }
        return 'Standard';
    }
    /**
 * Generate tracking URL based on carrier and tracking number
 *
 * @param string $trackingNumber
 * @param string $carrier
 * @return string
 */
    private function getTrackingUrl(string $trackingNumber, string $carrier): string
    {
        $trackingNumber = strtoupper(trim($trackingNumber));

        switch ($carrier) {
            case 'UPS':
                return "https://wwwapps.ups.com/WebTracking/track?track=yes&trackNums={$trackingNumber}";
            case 'FedEx':
                return "https://www.fedex.com/fedextrack/?tracknumbers={$trackingNumber}";
            case 'USPS':
                return "https://tools.usps.com/go/TrackConfirmAction?tLabels={$trackingNumber}";
            case 'DHL':
                return "https://www.dhl.com/en/express/tracking.html?AWB={$trackingNumber}";
            case 'Amazon Logistics':
                return "https://track.amazon.com/track/{$trackingNumber}";
            default:
                return ''; // leave empty if unknown carrier
        }
    }
public function getVTR(int $reportDuration = 30): array
{
    $tokenData = $this->refreshAccessToken();
    if (!$tokenData || !isset($tokenData['accessToken'])) {
        return ['success' => false, 'error' => 'Failed to retrieve Walmart access token'];
    }
    $accessToken = $tokenData['accessToken'];

    $endpoint = "{$this->baseUrl}/v3/insights/performance/vtr/summary?reportDuration={$reportDuration}";

    $response = Http::withHeaders([
        'Accept' => 'application/json',
        'WM_SEC.ACCESS_TOKEN' => $accessToken,
        'WM_QOS.CORRELATION_ID' => (string) Str::uuid(),
        'WM_SVC.NAME' => 'Walmart Marketplace',
    ])->get($endpoint);

    if ($response->failed()) {
        Log::error('Walmart VTR API Failed', [
            'endpoint' => $endpoint,
            'response' => $response->body(),
            'status' => $response->status()
        ]);
        return ['success' => false, 'error' => 'Failed to fetch VTR: ' . $response->body()];
    }

    $data = $response->json();
    Log::info('Walmart VTR API Response', ['response' => $data]);

    // Parse actual API structure: data['payload'] contains metrics
    $payload = $data['payload'] ?? null;
    if (!$payload || $data['status'] !== 'OK') {
        return ['success' => false, 'error' => 'Invalid VTR response structure', 'data' => $data];
    }

    $vtrRate = $payload['cumulativeRate'] ?? null; // e.g., 73.18
    $invalidTracking = $payload['impactedCustomerCount'] ?? 0; // e.g., 70 (impacted customers/orders)

    // Derive total_orders: invalid / (1 - rate/100); fallback to invalid if rate=0
    $totalOrders = ($vtrRate > 0 && $invalidTracking > 0) ? round($invalidTracking / (1 - ($vtrRate / 100))) : $invalidTracking;
    $validTracking = max(0, $totalOrders - $invalidTracking); // Avoid negative
    $allowedRate = 99.00; // From "Above 99%" standard

    if (!$vtrRate || $totalOrders === 0) {
        return ['success' => false, 'error' => 'VTR data not found in response', 'data' => $data];
    }

    return [
        'success' => true,
        'data' => [
            'total_orders' => $totalOrders,
            'valid_tracking' => $validTracking,
            'invalid_tracking' => $invalidTracking,
            'valid_tracking_rate' => $vtrRate,
            'allowed_rate' => $allowedRate,
            'period_start' => Carbon::now()->subDays($reportDuration)->toIso8601String(),
            'period_end' => Carbon::now()->toIso8601String(),
            'risk_level' => $payload['riskLevel'] ?? null, // Bonus: URGENT REVIEW
            'rate_trend' => $payload['cumulativeRateTrend'] ?? null, // Bonus: green_up
        ]
    ];
}

}
