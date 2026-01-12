<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Integration;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\EDeskService;
use Carbon\Carbon;

class AmazonSyncOrders extends Command
{
    protected $signature = 'amazon:sync-orders';
    protected $description = 'Fetch and sync Amazon orders and items into orders and order_items tables';
    protected ?EDeskService $edeskService;

    public function handle()
    {
        $this->info('🔄 Syncing Amazon orders and items...');
        
        // Initialize eDesk service if bearer token is configured
        $this->edeskService = config('services.edesk.bearer_token') ? new EDeskService() : null;
        if ($this->edeskService) {
            $this->info('✅ eDesk integration enabled for customer details');
        }

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
        $createdAfter = Carbon::now('America/New_York')->subDays(30)->toIso8601String();
        $this->info("📅 Fetching orders created after {$createdAfter} (last 30 days)");

        // Fetch orders with pagination support
        $allOrders = [];
        $nextToken = null;
        $page = 1;
        
        do {
            $params = [
                'MarketplaceIds' => 'ATVPDKIKX0DER',
                'CreatedAfter'   => $createdAfter,
            ];
            
            if ($nextToken) {
                $params['NextToken'] = $nextToken;
                $this->info("📄 Fetching page {$page}...");
            }
            
            $response = Http::withHeaders([
                'Authorization'      => 'Bearer ' . $integration->access_token,
                'x-amz-access-token' => $integration->access_token,
            ])->get($endpoint, $params);

            if ($response->failed()) {
                $this->error('❌ Failed to fetch orders: ' . $response->body());
                Log::error('Failed to fetch Amazon orders', [
                    'response_status' => $response->status(),
                    'response_body'   => $response->body(),
                    'page' => $page,
                ]);
                break;
            }

            $responseData = $response->json();
            $payload = $responseData['payload'] ?? [];
            $orders = $payload['Orders'] ?? [];
            $nextToken = $payload['NextToken'] ?? null;
            
            $allOrders = array_merge($allOrders, $orders);
            
            $this->info("✅ Page {$page}: Found " . count($orders) . " orders (Total so far: " . count($allOrders) . ")");
            
            Log::info('Amazon API Response', [
                'page' => $page,
                'orders_on_page' => count($orders),
                'total_orders' => count($allOrders),
                'has_next_token' => !empty($nextToken),
            ]);
            
            $page++;
            
            // Add small delay between pages to respect rate limits
            if ($nextToken) {
                usleep(500000); // 0.5 second delay
            }
            
        } while ($nextToken);

        if (empty($allOrders)) {
            $this->info('ℹ️ No orders found.');
            return 0;
        }
        
        $orders = $allOrders;
        $this->info("📦 Total orders fetched: " . count($orders));

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

                // Fetch customer details from eDesk if Amazon data is incomplete
                $edeskCustomer = null;
                if ($this->edeskService) {
                    try {
                        $edeskCustomer = $this->edeskService->getCustomerDetailsByOrderId($orderId);
                        if ($edeskCustomer) {
                            $this->info("  📧 eDesk customer details found for order {$orderId}");
                            Log::info('eDesk customer details fetched', [
                                'order_id' => $orderId,
                                'has_name' => !empty($edeskCustomer['name']),
                                'has_email' => !empty($edeskCustomer['email']),
                                'has_address' => !empty($edeskCustomer['address1']),
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to fetch eDesk customer details', [
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Use eDesk data to fill in missing Amazon data
                if ($edeskCustomer) {
                    // Fill in missing recipient name
                    if (empty($recipientName) && !empty($edeskCustomer['name'])) {
                        $recipientName = trim($edeskCustomer['name']);
                    }

                    // Fill in missing email
                    if (empty($buyerEmail) && !empty($edeskCustomer['email'])) {
                        $buyerEmail = $edeskCustomer['email'];
                    }

                    // Fill in missing address fields
                    if ($shippingAddress) {
                        if (empty($shippingAddress['AddressLine1']) && !empty($edeskCustomer['address1'])) {
                            $shippingAddress['AddressLine1'] = $edeskCustomer['address1'];
                        }
                        if (empty($shippingAddress['AddressLine2']) && !empty($edeskCustomer['address2'])) {
                            $shippingAddress['AddressLine2'] = $edeskCustomer['address2'];
                        }
                        if (empty($shippingAddress['City']) && !empty($edeskCustomer['city'])) {
                            $shippingAddress['City'] = $edeskCustomer['city'];
                        }
                        if (empty($shippingAddress['StateOrRegion']) && !empty($edeskCustomer['state'])) {
                            $shippingAddress['StateOrRegion'] = $edeskCustomer['state'];
                        }
                        if (empty($shippingAddress['PostalCode']) && !empty($edeskCustomer['postal_code'])) {
                            $shippingAddress['PostalCode'] = $edeskCustomer['postal_code'];
                        }
                        if (empty($shippingAddress['CountryCode']) && !empty($edeskCustomer['country'])) {
                            $shippingAddress['CountryCode'] = $edeskCustomer['country'];
                        }
                        if (empty($shippingAddress['Phone']) && !empty($edeskCustomer['phone'])) {
                            $shippingAddress['Phone'] = $edeskCustomer['phone'];
                        }
                    } else {
                        // If Amazon didn't return any address, use eDesk address entirely
                        $shippingAddress = [
                            'Name' => $edeskCustomer['name'] ?? null,
                            'AddressLine1' => $edeskCustomer['address1'] ?? null,
                            'AddressLine2' => $edeskCustomer['address2'] ?? null,
                            'City' => $edeskCustomer['city'] ?? null,
                            'StateOrRegion' => $edeskCustomer['state'] ?? null,
                            'PostalCode' => $edeskCustomer['postal_code'] ?? null,
                            'CountryCode' => $edeskCustomer['country'] ?? null,
                            'Phone' => $edeskCustomer['phone'] ?? null,
                        ];
                    }
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
                    // For shipped orders, only update if we have missing critical fields (recipient_name, ship_address1)
                    // This allows eDesk data to fill in missing information
                    $isShipped = strtolower(trim($existingOrder->order_status)) === 'shipped';
                    $hasMissingFields = empty($existingOrder->recipient_name) || empty($existingOrder->ship_address1);
                    
                    if ($isShipped && !$hasMissingFields) {
                        Log::info('Skipping update for already shipped order with complete data', [
                            'order_number' => $orderId,
                            'existing_status' => $existingOrder->order_status,
                            'api_status' => $order['OrderStatus'] ?? null,
                        ]);
                        $orderModel = $existingOrder;
                    } else {
                        // Update order (even if shipped, if missing fields need to be filled)
                        if ($isShipped && $hasMissingFields) {
                            // Preserve order_status for shipped orders, but update missing fields
                            unset($orderData['order_status']);
                            Log::info('Updating shipped order with missing fields (eDesk data)', [
                                'order_number' => $orderId,
                                'missing_recipient_name' => empty($existingOrder->recipient_name),
                                'missing_address' => empty($existingOrder->ship_address1),
                            ]);
                        }
                        $existingOrder->update($orderData);
                        $orderModel = $existingOrder;
                        $this->info("✅ Order {$orderId} updated" . ($isShipped ? " (shipped, filling missing fields)" : ""));
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
