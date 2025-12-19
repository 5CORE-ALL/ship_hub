<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;

class AmazonSyncOrders extends Command
{
    protected $signature = 'amazon:sync-orders';
    protected $description = 'Fetch and sync Amazon orders and items into orders and order_items tables';

    public function handle()
    {
        $this->info('🔄 Syncing Amazon orders and items...');

        // Fetch integration for store_id 1
        $integration = Integration::where('store_id', 1)->first();
        if (!$integration) {
            $this->error('❌ No integration found for store_id 1.');
            Log::error('Amazon integration missing for store_id 1');
            return 1;
        }

        // Refresh access token if expired
        if ($integration->expires_at->lt(now())) {
            $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $integration->refresh_token ?? null,
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
                $this->info('✅ Access token refreshed');
            } else {
                $this->error('❌ Amazon token refresh failed');
                Log::error('Amazon token refresh failed', [
                    'response_status' => $response->status(),
                    'response_body'   => $response->body(),
                ]);
                return 1;
            }
        }

        // Set endpoint and dynamic date (using New York timezone as you used)
        $endpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . '/orders/v0/orders';
        $createdAfter = Carbon::now('America/New_York')->subDays(2)->toIso8601String();
        $this->info("📅 Fetching orders created after {$createdAfter}");

        // Fetch orders
        $response = Http::withHeaders([
            'Authorization'      => 'Bearer ' . $integration->access_token,
            'x-amz-access-token' => $integration->access_token,
        ])->get($endpoint, [
            'MarketplaceIds' => 'ATVPDKIKX0DER',
            'CreatedAfter'   => $createdAfter,
        ]);

        if ($response->failed()) {
            $this->error('❌ Failed to fetch orders: ' . $response->body());
            Log::error('Failed to fetch Amazon orders', [
                'response_status' => $response->status(),
                'response_body'   => $response->body(),
            ]);
            return 1;
        }

        $orders = $response->json()['payload']['Orders'] ?? [];
        Log::info('Amazon API Response', [
            'total_orders' => count($orders),
            'response' => $response->json(),
        ]);

        if (empty($orders)) {
            $this->info('ℹ️ No orders found.');
            return 0;
        }

        foreach ($orders as $order) {
            try {
                $orderId = $order['AmazonOrderId'];
                Log::info('Processing Order', [
                    'order_id' => $orderId,
                    'status' => $order['OrderStatus'] ?? 'unknown',
                    'shipping_address' => $order['ShippingAddress'] ?? [],
                    'recipient_name' => $order['ShippingAddress']['Name'] ?? ($order['BuyerName'] ?? 'missing'),
                ]);

                // Fetch order items
                $itemsEndpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/orderItems";
                $itemsResponse = Http::withHeaders([
                    'Authorization'      => 'Bearer ' . $integration->access_token,
                    'x-amz-access-token' => $integration->access_token,
                ])->get($itemsEndpoint);

                $items = [];
                if ($itemsResponse->successful()) {
                    $items = $itemsResponse->json()['payload']['OrderItems'] ?? [];
                    Log::info('Order Items Fetched', [
                        'order_id' => $orderId,
                        'item_count' => count($items),
                    ]);
                } else {
                    Log::warning("Failed to fetch items for Order {$orderId}", [
                        'status' => $itemsResponse->status(),
                        'body' => $itemsResponse->body(),
                    ]);
                }

                // Fetch shipping address (Amazon SP-API requires separate call)
                // Rate limit: 60 requests per minute - add small delay to respect rate limit
                usleep(1000000); // 1 second delay between address calls
                $addressEndpoint = env('AMAZON_BASE_URL', 'https://sellingpartnerapi-na.amazon.com') . "/orders/v0/orders/{$orderId}/address";
                $addressResponse = Http::withHeaders([
                    'Authorization'      => 'Bearer ' . $integration->access_token,
                    'x-amz-access-token' => $integration->access_token,
                ])->get($addressEndpoint);

                $shippingAddress = null;
                if ($addressResponse->successful()) {
                    $shippingAddress = $addressResponse->json()['payload']['ShippingAddress'] ?? null;
                    Log::info('Shipping Address Fetched', [
                        'order_id' => $orderId,
                        'has_address' => !empty($shippingAddress),
                    ]);
                } else {
                    Log::warning("Failed to fetch address for Order {$orderId}", [
                        'status' => $addressResponse->status(),
                        'body' => $addressResponse->body(),
                    ]);
                }

                // Calculate total quantity from items (fallback to NumberOfItemsUnshipped or 1)
                $totalQuantity = 0;
                if (!empty($items)) {
                    foreach ($items as $it) {
                        $totalQuantity += intval($it['QuantityOrdered'] ?? 0);
                    }
                }
                if ($totalQuantity === 0) {
                    $totalQuantity = $order['NumberOfItemsUnshipped'] ?? ($order['NumberOfItemsShipped'] ?? 1);
                }

                // Get buyer info (might be in BuyerInfo object)
                $buyerEmail = $order['BuyerInfo']['BuyerEmail'] ?? $order['BuyerEmail'] ?? null;
                $buyerName = $order['BuyerInfo']['BuyerName'] ?? $order['BuyerName'] ?? null;
                
                // Handle recipient name - Amazon may return empty string, treat as null
                $recipientName = null;
                if ($shippingAddress && !empty(trim($shippingAddress['Name'] ?? ''))) {
                    $recipientName = trim($shippingAddress['Name']);
                } elseif ($buyerName && !empty(trim($buyerName))) {
                    $recipientName = trim($buyerName);
                }

                // Build order data array
                // Use shipping address from separate API call, not from order data
                // Note: Amazon may return partial address data for privacy reasons
                $orderData = [
                    'marketplace'        => 'amazon',
                    'store_id'           => $integration->store_id,
                    'order_number'       => $orderId,
                    'external_order_id'  => $orderId,
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
                    // DefaultShipFromLocationAddress might not be available - check if it exists in order data
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

                // --- NEW: preserve shipped order_status if already shipped in DB ---
            
                $existingOrder = Order::where('marketplace', 'amazon')
                    ->where('order_number', $orderId)
                    ->first();

                if ($existingOrder) {
                    // ✅ Only skip update if already shipped
                    if (strtolower(trim($existingOrder->order_status)) === 'shipped') {
                        Log::info('Skipping update for already shipped order', [
                            'order_number' => $orderId,
                            'existing_status' => $existingOrder->order_status,
                            'api_status' => $order['OrderStatus'] ?? null,
                        ]);
                        $orderModel = $existingOrder;
                    } else {
                        // Update since not shipped yet
                        $existingOrder->update($orderData);
                        $orderModel = $existingOrder;
                        $this->info("✅ Order {$orderId} updated to status: " . ($order['OrderStatus'] ?? 'unknown'));
                    }
                } else {
                    // Create new order
                    $orderModel = Order::create($orderData);
                    $this->info("✅ Order {$orderId} synced");
                }


                // Save order items
                foreach ($items as $item) {
                    // Convert IsGift string to boolean (1 or 0)
                    $isGift = isset($item['IsGift']) ? ($item['IsGift'] === 'true' ? 1 : 0) : 0;
                    $qty = $item['QuantityOrdered'] ?? 1;

                    // If you have a helper to get dimensions by SKU, use it; else provide sensible defaults
                    $dimensionData = function_exists('getDimensionsBySku') ? getDimensionsBySku($item['SellerSKU'] ?? '', $qty) : [
                        'weight' => 20,
                        'length' => 8,
                        'width'  => 6,
                        'height' => 2
                    ];

                    $itemData = [
                        'order_id'           => $orderModel->id,
                        'order_number'       => $orderId,
                        'order_item_id'      => $item['OrderItemId'] ?? null,
                        'sku'                => $item['SellerSKU'] ?? null,
                        'asin'               => $item['ASIN'] ?? null,
                        'upc'                => null,
                        'product_name'       => $item['Title'] ?? null,
                        'quantity_ordered'   => $item['QuantityOrdered'] ?? 1,
                        'quantity_shipped'   => $item['QuantityShipped'] ?? 1,
                        'unit_price'         => $item['ItemPrice']['Amount'] ?? 0.00,
                        'item_tax'           => $item['ItemTax']['Amount'] ?? 0.00,
                        'promotion_discount' => $item['PromotionDiscount']['Amount'] ?? 0.00,
                        'currency'           => $item['ItemPrice']['CurrencyCode'] ?? 'USD',
                        'is_gift'            => $isGift,
                        'dimensions'         => isset($item['ItemDimensions']) ? json_encode($item['ItemDimensions']) : null,
                        'marketplace'        => 'amazon',
                        'weight'             => $dimensionData['weight'] ?? 20,
                        'weight_unit'        => null,
                        'length'             => $dimensionData['length'] ?? 8,
                        'width'              => $dimensionData['width'] ?? 6,
                        'height'             => $dimensionData['height'] ?? 2,
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

            } catch (\Exception $e) {
                $this->error("⚠️ Error saving order {$orderId}: " . $e->getMessage());
                Log::error('Amazon order save error', [
                    'order_id' => $orderId,
                    'exception' => $e->getMessage(),
                    'order' => $order,
                ]);
            }
        }

        $this->info('🎉 Amazon order and item sync completed!');
        return 0;
    }
}
