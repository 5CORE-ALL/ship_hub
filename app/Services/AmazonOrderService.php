<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AmazonOrderService
{
    protected Integration $integration;

    public function __construct(int $storeId = 1)
    {
        $this->integration = Integration::where('store_id', $storeId)->firstOrFail();
    }

    /**
     * Ensure access token is valid or refresh it.
     */
    public function ensureAccessToken(): void
    {
        if ($this->integration->expires_at->lt(now())) {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->integration->refresh_token,
                'client_id'     => $this->integration->app_id,
                'client_secret' => $this->integration->app_secret,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->integration->update([
                    'access_token'  => $data['access_token'],
                    'refresh_token' => $data['refresh_token'] ?? $this->integration->refresh_token,
                    'expires_at'    => now()->addSeconds($data['expires_in']),
                ]);
                Log::info('✅ Amazon access token refreshed.');
            } else {
                Log::error('Amazon token refresh failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception('Amazon token refresh failed');
            }
        }
    }

    /**
     * Fetch orders from Amazon.
     */
    public function fetchOrders(Carbon $createdAfter = null): array
    {
        $this->ensureAccessToken();

        $createdAfter ??= Carbon::today()->setTimezone('America/New_York');

        $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . '/orders/v0/orders';

        $response = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $this->integration->access_token,
            'x-amz-access-token' => $this->integration->access_token,
        ])->get($endpoint, [
            'MarketplaceIds' => 'ATVPDKIKX0DER',
            'CreatedAfter'   => $createdAfter->toIso8601String(),
        ]);

        if ($response->failed()) {
            Log::error('Failed to fetch Amazon orders', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception('Failed to fetch Amazon orders');
        }

        return $response->json()['payload']['Orders'] ?? [];
    }

    /**
     * Fetch order items for a specific order.
     */
    public function fetchOrderItems(string $orderId): array
    {
        $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/orderItems";

        $response = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $this->integration->access_token,
            'x-amz-access-token' => $this->integration->access_token,
        ])->get($endpoint);

        if ($response->failed()) {
            Log::warning("Failed to fetch items for order {$orderId}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return [];
        }

        return $response->json()['payload']['OrderItems'] ?? [];
    }

    /**
     * Fetch shipping address for a specific order.
     * Note: Amazon SP-API requires a separate call to get shipping address.
     * Rate limit: 60 requests per minute.
     */
    public function fetchOrderAddress(string $orderId): ?array
    {
        $this->ensureAccessToken();

        $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/address";

        $response = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $this->integration->access_token,
            'x-amz-access-token' => $this->integration->access_token,
        ])->get($endpoint);

        if ($response->failed()) {
            Log::warning("Failed to fetch address for order {$orderId}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            return null;
        }

        return $response->json()['payload']['ShippingAddress'] ?? null;
    }

    /**
     * Save an order and its items to the database.
     */
    public function saveOrder(array $order, array $items): Order
    {
        $totalQuantity = array_sum(array_column($items, 'QuantityOrdered')) ?? ($order['NumberOfItemsUnshipped'] ?? 1);

        // Fetch shipping address separately (Amazon SP-API requires separate call)
        // Note: Amazon may return partial address data for privacy reasons
        $shippingAddress = $this->fetchOrderAddress($order['AmazonOrderId']);
        
        // Also check BuyerInfo for email if available
        $buyerEmail = $order['BuyerInfo']['BuyerEmail'] ?? $order['BuyerEmail'] ?? null;
        $buyerName = $order['BuyerInfo']['BuyerName'] ?? $order['BuyerName'] ?? null;
        
        // Handle recipient name - Amazon may return empty string, treat as null
        $recipientName = null;
        if ($shippingAddress && !empty(trim($shippingAddress['Name'] ?? ''))) {
            $recipientName = trim($shippingAddress['Name']);
        } elseif ($buyerName && !empty(trim($buyerName))) {
            $recipientName = trim($buyerName);
        }

        $orderData = [
            'marketplace'        => 'amazon',
            'store_id'           => $this->integration->store_id,
            'order_number'       => $order['AmazonOrderId'],
            'external_order_id'  => $order['AmazonOrderId'],
            'order_date'         => $order['PurchaseDate'] ? Carbon::parse($order['PurchaseDate']) : null,
            'order_age'          => isset($order['PurchaseDate']) ? now()->diffInDays(Carbon::parse($order['PurchaseDate'])) : null,
            'quantity'           => $totalQuantity,
            'order_total'        => $order['OrderTotal']['Amount'] ?? 0.00,
            'recipient_name'     => $recipientName,
            'recipient_email'    => $buyerEmail ?? null,
            'recipient_phone'    => !empty($shippingAddress['Phone'] ?? '') ? $shippingAddress['Phone'] : null,
            'ship_address1'      => !empty($shippingAddress['AddressLine1'] ?? '') ? $shippingAddress['AddressLine1'] : null,
            'ship_address2'      => !empty($shippingAddress['AddressLine2'] ?? '') ? $shippingAddress['AddressLine2'] : null,
            'ship_city'          => !empty($shippingAddress['City'] ?? '') ? $shippingAddress['City'] : null,
            'ship_state'         => !empty($shippingAddress['StateOrRegion'] ?? '') ? $shippingAddress['StateOrRegion'] : null,
            'ship_postal_code'   => !empty($shippingAddress['PostalCode'] ?? '') ? $shippingAddress['PostalCode'] : null,
            'ship_country'       => !empty($shippingAddress['CountryCode'] ?? '') ? $shippingAddress['CountryCode'] : null,
            // DefaultShipFromLocationAddress might be in the order data or need separate call
            'shipper_name'       => $order['DefaultShipFromLocationAddress']['Name'] ?? null,
            'shipper_street'     => $order['DefaultShipFromLocationAddress']['AddressLine1'] ?? null,
            'shipper_city'       => $order['DefaultShipFromLocationAddress']['City'] ?? null,
            'shipper_state'      => $order['DefaultShipFromLocationAddress']['StateOrRegion'] ?? null,
            'shipper_postal'     => $order['DefaultShipFromLocationAddress']['PostalCode'] ?? null,
            'shipper_country'    => $order['DefaultShipFromLocationAddress']['CountryCode'] ?? null,
            'order_status'       => $order['OrderStatus'] ?? null,
            'raw_data'           => json_encode($order),
            'raw_items'          => json_encode($items),
        ];

        $orderModel = Order::updateOrCreate(
            [
                'marketplace' => 'amazon',
                'order_number' => $order['AmazonOrderId'],
                'external_order_id' => $order['AmazonOrderId'],
            ],
            $orderData
        );

        foreach ($items as $item) {
            $isGift = isset($item['IsGift']) ? ($item['IsGift'] === 'true' ? 1 : 0) : 0;

            $itemData = [
                'order_id'           => $orderModel->id,
                'order_number'       => $order['AmazonOrderId'],
                'order_item_id'      => $item['OrderItemId'] ?? null,
                'sku'                => $item['SellerSKU'] ?? null,
                'asin'               => $item['ASIN'] ?? null,
                'product_name'       => $item['Title'] ?? null,
                'quantity_ordered'   => $item['QuantityOrdered'] ?? 0,
                'quantity_shipped'   => $item['QuantityShipped'] ?? 0,
                'unit_price'         => $item['ItemPrice']['Amount'] ?? 0.00,
                'item_tax'           => $item['ItemTax']['Amount'] ?? 0.00,
                'promotion_discount' => $item['PromotionDiscount']['Amount'] ?? 0.00,
                'currency'           => $item['ItemPrice']['CurrencyCode'] ?? 'USD',
                'is_gift'            => $isGift,
                'weight'             => $item['ItemWeight']['Value'] ?? null,
                'weight_unit'        => $item['ItemWeight']['Unit'] ?? null,
                'dimensions'         => isset($item['ItemDimensions']) ? json_encode($item['ItemDimensions']) : null,
                'marketplace'        => 'amazon',
                'raw_data'           => json_encode($item),
            ];

            OrderItem::updateOrCreate(
                [
                    'order_id' => $orderModel->id,
                    'order_item_id' => $item['OrderItemId'] ?? null,
                    'marketplace' => 'amazon',
                ],
                $itemData
            );
        }

        return $orderModel;
    }
// public function getSellerPerformanceReport(?string $startDate = null, ?string $endDate = null): array
// {
//     $this->ensureAccessToken();

//     $startDate ??= now()->subDays(30)->toIso8601String();
//     $endDate ??= now()->toIso8601String();

//     Log::info('Requesting Seller Performance Report', [
//         'startDate' => $startDate,
//         'endDate' => $endDate,
//         'store_id' => $this->integration->store_id,
//     ]);

//     // 1️⃣ Request report
//     $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . '/reports/2021-06-30/reports';
//     $payload = [
//         'reportType' => 'GET_V2_SELLER_PERFORMANCE_REPORT',
//         'dataStartTime' => $startDate,
//         'dataEndTime' => $endDate,
//         'marketplaceIds' => ['ATVPDKIKX0DER'], 
//     ];

//     $response = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//         'Content-Type'       => 'application/json',
//     ])->post($endpoint, $payload);

//     if ($response->failed()) {
//         Log::error('Failed to request Seller Performance Report', [
//             'status' => $response->status(),
//             'body'   => $response->body(),
//         ]);
//         return [];
//     }

//     $reportId = $response->json()['reportId'] ?? null;
//     Log::info('Report requested', ['reportId' => $reportId]);

//     if (!$reportId) {
//         return [];
//     }

//     // 2️⃣ Poll until report is DONE
//     $status = 'IN_PROGRESS';
//     while ($status === 'IN_PROGRESS') {
//         sleep(5); // wait 5 seconds
//         $statusResponse = Http::withHeaders([
//             'Authorization'      => 'Bearer ' . $this->integration->access_token,
//             'x-amz-access-token' => $this->integration->access_token,
//         ])->get(env('AMAZON_BASE_URL') . "/reports/2021-06-30/reports/{$reportId}");

//         $statusData = $statusResponse->json();
//         $status = $statusData['processingStatus'] ?? 'IN_PROGRESS';

//         Log::info('Polling report status', [
//             'reportId' => $reportId,
//             'status' => $status,
//         ]);
//     }

//     // 3️⃣ Get report document
//     $documentId = $statusData['reportDocumentId'] ?? null;
//     Log::info('Report ready', ['reportId' => $reportId, 'documentId' => $documentId]);

//     if (!$documentId) return [];

//     $docResponse = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//     ])->get(env('AMAZON_BASE_URL') . "/reports/2021-06-30/documents/{$documentId}");

//     $docData = $docResponse->json();
//     $downloadUrl = $docData['url'] ?? null;
//     $compression = $docData['compressionAlgorithm'] ?? null;

//     Log::info('Report document info', [
//         'documentId' => $documentId,
//         'downloadUrl' => $downloadUrl,
//         'compression' => $compression,
//     ]);

//     if (!$downloadUrl) return [];

//     // 4️⃣ Download and decode report
//     $reportContent = Http::get($downloadUrl)->body();

//     if ($compression === 'GZIP') {
//         $reportContent = gzdecode($reportContent);
//     }

//     Log::info('Downloaded report content', ['length' => strlen($reportContent)]);

//     // 5️⃣ Parse CSV or XML content (VTR report is usually CSV)
//     $lines = explode("\n", trim($reportContent));
//     $headers = str_getcsv(array_shift($lines));
//     $rows = array_map(fn($line) => array_combine($headers, str_getcsv($line)), $lines);

//     Log::info('Parsed report rows', ['count' => count($rows)]);

//     return $rows; // ✅ This will contain your VTR metrics
// }
// public function getSellerPerformanceReport(?string $startDate = null, ?string $endDate = null): array
// {
//     $this->ensureAccessToken();

//     $startDate ??= now()->subDays(30)->toIso8601String();
//     $endDate ??= now()->toIso8601String();

//     $baseUrl = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com');

//     Log::info('Requesting Seller Performance Report', [
//         'startDate' => $startDate,
//         'endDate' => $endDate,
//         'store_id' => $this->integration->store_id,
//     ]);

//     // 1️⃣ Request report
//     $payload = [
//         'reportType' => 'GET_V2_SELLER_PERFORMANCE_REPORT',
//         'dataStartTime' => $startDate,
//         'dataEndTime' => $endDate,
//         'marketplaceIds' => ['ATVPDKIKX0DER'], // Required
//     ];

//     $response = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//         'Content-Type'       => 'application/json',
//     ])->post("{$baseUrl}/reports/2021-06-30/reports", $payload);

//     if ($response->failed()) {
//         Log::error('Failed to request Seller Performance Report', [
//             'status' => $response->status(),
//             'body'   => $response->body(),
//         ]);
//         return [];
//     }

//     $reportId = $response->json()['reportId'] ?? null;
//     Log::info('Report requested', ['reportId' => $reportId]);

//     if (!$reportId) return [];

//     // 2️⃣ Poll until report is DONE
//     $status = 'IN_PROGRESS';
//     while ($status === 'IN_PROGRESS') {
//         sleep(5); // wait 5 seconds
//         $statusResponse = Http::withHeaders([
//             'Authorization'      => 'Bearer ' . $this->integration->access_token,
//             'x-amz-access-token' => $this->integration->access_token,
//         ])->get("{$baseUrl}/reports/2021-06-30/reports/{$reportId}");

//         $statusData = $statusResponse->json();
//         $status = $statusData['processingStatus'] ?? 'IN_PROGRESS';

//         Log::info('Polling report status', [
//             'reportId' => $reportId,
//             'status' => $status,
//         ]);
//     }

//     // 3️⃣ Get report document
//     $documentId = $statusData['reportDocumentId'] ?? null;
//     Log::info('Report ready', ['reportId' => $reportId, 'documentId' => $documentId]);
//     if (!$documentId) return [];

//     $docResponse = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//     ])->get("{$baseUrl}/reports/2021-06-30/documents/{$documentId}");

//     $docData = $docResponse->json();
//     $downloadUrl = $docData['url'] ?? null;
//     $compression = $docData['compressionAlgorithm'] ?? null;

//     Log::info('Report document info', [
//         'documentId' => $documentId,
//         'downloadUrl' => $downloadUrl,
//         'compression' => $compression,
//     ]);

//     if (!$downloadUrl) return [];

//     // 4️⃣ Download and decode report
//     $reportContent = Http::get($downloadUrl)->body();
//     if ($compression === 'GZIP') {
//         $reportContent = gzdecode($reportContent);
//     }

//     Log::info('Downloaded report content', ['length' => strlen($reportContent)]);

//     // 5️⃣ Parse CSV content (VTR report is usually CSV)
//     $lines = explode("\n", trim($reportContent));
//     $headers = str_getcsv(array_shift($lines));
//     $rows = array_map(fn($line) => array_combine($headers, str_getcsv($line)), $lines);

//     Log::info('Parsed report rows', ['count' => count($rows)]);

//     return $rows; // ✅ This will contain your VTR metrics
// }

//     public function getSellerPerformanceReport(?string $startDate = null, ?string $endDate = null): array
// {
//     $this->ensureAccessToken();

//     $startDate ??= now()->subDays(30)->toIso8601String();
//     $endDate ??= now()->toIso8601String();

//     $baseUrl = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com');

//     Log::info('Requesting Seller Performance Report', [
//         'startDate' => $startDate,
//         'endDate' => $endDate,
//         'store_id' => $this->integration->store_id,
//     ]);

//     // 1️⃣ Request report
//     $payload = [
//         'reportType' => 'GET_V2_SELLER_PERFORMANCE_REPORT',
//         'dataStartTime' => $startDate,
//         'dataEndTime' => $endDate,
//         'marketplaceIds' => ['ATVPDKIKX0DER'], 
//     ];

//     $response = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//         'Content-Type'       => 'application/json',
//     ])->post("{$baseUrl}/reports/2021-06-30/reports", $payload);

//     if ($response->failed()) {
//         Log::error('Failed to request Seller Performance Report', [
//             'status' => $response->status(),
//             'body'   => $response->body(),
//         ]);
//         return [];
//     }

//     $reportId = $response->json()['reportId'] ?? null;
//     Log::info('Report requested', ['reportId' => $reportId]);
//     if (!$reportId) return [];

//     // 2️⃣ Poll until report is DONE
//     $status = 'IN_PROGRESS';
//     while ($status === 'IN_PROGRESS') {
//         sleep(5);
//         $statusResponse = Http::withHeaders([
//             'Authorization'      => 'Bearer ' . $this->integration->access_token,
//             'x-amz-access-token' => $this->integration->access_token,
//         ])->get("{$baseUrl}/reports/2021-06-30/reports/{$reportId}");

//         $statusData = $statusResponse->json();
//         $status = $statusData['processingStatus'] ?? 'IN_PROGRESS';

//         Log::info('Polling report status', [
//             'reportId' => $reportId,
//             'status' => $status,
//         ]);
//     }

//     // 3️⃣ Get report document
//     $documentId = $statusData['reportDocumentId'] ?? null;
//     Log::info('Report ready', ['reportId' => $reportId, 'documentId' => $documentId]);
//     if (!$documentId) return [];

//     $docResponse = Http::withHeaders([
//         'Authorization'      => 'Bearer ' . $this->integration->access_token,
//         'x-amz-access-token' => $this->integration->access_token,
//     ])->get("{$baseUrl}/reports/2021-06-30/documents/{$documentId}");

//     $docData = $docResponse->json();
//     $downloadUrl = $docData['url'] ?? null;
//     $compression = $docData['compressionAlgorithm'] ?? null;

//     Log::info('Report document info', [
//         'documentId' => $documentId,
//         'downloadUrl' => $downloadUrl,
//         'compression' => $compression,
//     ]);

//     if (!$downloadUrl) return [];

//     // 4️⃣ Download report
//     $reportContent = Http::get($downloadUrl)->body();

//     // 5️⃣ Decompress if GZIP
//     if ($compression === 'GZIP') {
//         $reportContent = gzdecode($reportContent);
//     }

//     // 6️⃣ Treat the whole content as JSON
//     $reportJson = json_decode($reportContent, true);

//     // If decoding fails, return raw content inside an array
//     if (json_last_error() !== JSON_ERROR_NONE) {
//         Log::warning('Report content is not valid JSON, returning raw content');
//         return ['raw_content' => $reportContent];
//     }

//     Log::info('Successfully fetched report JSON', ['count' => count($reportJson)]);

//     return $reportJson;
// }

public function getSellerPerformanceReport(?string $startDate = null, ?string $endDate = null): array
{
    $this->ensureAccessToken();

    $startDate ??= now()->subDays(30)->toIso8601String();
    $endDate ??= now()->toIso8601String();

    $baseUrl = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com');

    Log::info('Requesting Seller Performance Report', [
        'startDate' => $startDate,
        'endDate' => $endDate,
        'store_id' => $this->integration->store_id,
    ]);

    $payload = [
        'reportType' => 'GET_V2_SELLER_PERFORMANCE_REPORT',
        'dataStartTime' => $startDate,
        'dataEndTime' => $endDate,
        'marketplaceIds' => ['ATVPDKIKX0DER'],
    ];

    $response = Http::withHeaders([
        'Authorization'      => 'Bearer ' . $this->integration->access_token,
        'x-amz-access-token' => $this->integration->access_token,
        'Content-Type'       => 'application/json',
    ])->post("{$baseUrl}/reports/2021-06-30/reports", $payload);

    if ($response->failed()) {
        Log::error('Failed to request Seller Performance Report', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        return [];
    }

    $reportId = $response->json()['reportId'] ?? null;
    if (!$reportId) return [];

    // Poll until report is done
    $status = 'IN_PROGRESS';
    $statusData = [];
    while (in_array($status, ['IN_PROGRESS', 'IN_QUEUE'])) {
        sleep(5);
        $statusResponse = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $this->integration->access_token,
            'x-amz-access-token' => $this->integration->access_token,
        ])->get("{$baseUrl}/reports/2021-06-30/reports/{$reportId}");

        $statusData = $statusResponse->json();
        $status = $statusData['processingStatus'] ?? 'IN_PROGRESS';
    }

    $documentId = $statusData['reportDocumentId'] ?? null;
    if (!$documentId) return [];

    $docResponse = Http::withHeaders([
        'Authorization'      => 'Bearer ' . $this->integration->access_token,
        'x-amz-access-token' => $this->integration->access_token,
    ])->get("{$baseUrl}/reports/2021-06-30/documents/{$documentId}");

    $docData = $docResponse->json();
    $downloadUrl = $docData['url'] ?? null;
    $compression = $docData['compressionAlgorithm'] ?? null;

    if (!$downloadUrl) return [];

    // Download the report
    $reportContent = Http::get($downloadUrl)->body();

    // Decompress if GZIP
    if ($compression === 'GZIP') {
        $reportContent = gzdecode($reportContent);
    }

    // Return raw content as string
    return ['raw_content' => $reportContent];
}


}
