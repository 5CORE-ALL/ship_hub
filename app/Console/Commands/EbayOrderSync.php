<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use App\Services\EbayOrderService;
class EbayOrderSync extends Command
{
    protected $signature = 'ebay:sync-orders';
    protected $description = 'Fetch and sync eBay orders and items for all eBay stores';

    public function handle()
    {
        $this->info('🔹 Starting eBay order sync for all stores...');
        $stores = DB::table('stores as s')
            ->join('sales_channels as sc', 's.sales_channel_id', '=', 'sc.id')
            ->join('marketplaces as m', 's.marketplace_id', '=', 'm.id')
            ->leftJoin('integrations as i', 's.id', '=', 'i.store_id')
            ->where('sc.platform', 'ebay')
            ->where('s.id', '!=', 4) // Exclude store_id 4, include all others (including those without integrations)
            ->select(
                's.id as store_id',
                's.name as store_name',
                'sc.name as sales_channel_name',
                'm.name as marketplace_name',
                'i.access_token',
                'i.refresh_token',
                'i.app_id',
                'i.app_secret',
                'i.expires_at'
            )
            ->distinct()
            ->get();

        if ($stores->isEmpty()) {
            $this->error('⚠️ No eBay stores found.');
            return 1;
        }

        foreach ($stores as $store) {
            $this->info("Processing store: {$store->store_name} (ID: {$store->store_id})");

            // Check if integration exists - let getAccessToken handle token refresh
            if (!$store->refresh_token || !$store->app_id || !$store->app_secret) {
                $this->warn("⚠️ Missing integration data for store {$store->store_name} (ID: {$store->store_id}). Required: refresh_token, app_id, app_secret. Skipping.");
                Log::warning('eBay sync: Missing integration data', [
                    'store_id' => $store->store_id,
                    'store_name' => $store->store_name,
                    'has_refresh_token' => !empty($store->refresh_token),
                    'has_app_id' => !empty($store->app_id),
                    'has_app_secret' => !empty($store->app_secret),
                ]);
                continue;
            }

            // $needNewToken = false;
            // if ($store->expires_at && Carbon::parse($store->expires_at)->lt(now())) {
            //     $this->info("🔄 Refreshing access token for store {$store->store_name}");
            //     $response = Http::asForm()->withBasicAuth($store->app_id, $store->app_secret)
            //         ->post('https://api.ebay.com/identity/v1/oauth2/token', [
            //             'grant_type'    => 'refresh_token',
            //             'refresh_token' => $store->refresh_token,
            //             'scope'         => 'https://api.ebay.com/oauth/api_scope/sell.fulfillment.readonly',
            //         ]);

            //     if ($response->successful()) {
            //         $data = $response->json();
            //         DB::table('integrations')->where('store_id', $store->store_id)->update([
            //             'access_token' => $data['access_token'],
            //             'expires_at'   => now()->addSeconds($data['expires_in']),
            //         ]);
            //         $store->access_token = $data['access_token'];
            //         $this->info("✅ Access token refreshed for store {$store->store_name}");
            //     } else {
            //         $this->warn("❌ Refresh token invalid for store {$store->store_name}: " . $response->body());
            //         Log::warning('eBay token refresh failed', [
            //             'store_id' => $store->store_id,
            //             'response_status' => $response->status(),
            //             'response_body' => $response->body(),
            //         ]);
            //         $needNewToken = true;
            //     }
            // }

            // if ($needNewToken) {
            //     $this->warn("Store {$store->store_name} needs a new refresh token. Skipping until reauthorized.");
            //     continue;
            // }
            $ebayService = new EbayOrderService();
            $accessToken = $ebayService->getAccessToken($store->store_id);
            if (!$accessToken) {
                $this->warn("⚠️ Failed to get access token for store {$store->store_name}. Skipping.");
                continue;
            }
            $store->access_token = $accessToken;

            // Fetch orders with pagination
            $endpoint = "https://api.ebay.com/sell/fulfillment/v1/order";
            $createdAfter = Carbon::now()->subDays(2)->toIso8601ZuluString();
            $this->info("Fetching orders created after {$createdAfter}");

            $totalOrdersSynced = 0;
            $page = 1;
            $maxPages = 100; 
            $limit = 50;

            do {
                $this->info("Fetching page {$page} for store {$store->store_name}...");
                
                $response = Http::withToken($store->access_token)
                    ->timeout(7) 
                    ->get($endpoint, [
                        'filter' => "creationdate:[{$createdAfter}..]",
                        'limit' => $limit,
                        'offset' => ($page - 1) * $limit,
                    ]);

                if ($response->failed()) {
                    $this->error("Failed to fetch eBay orders (page {$page}) for store {$store->store_name}: " . $response->body());
                    Log::error('Failed to fetch eBay orders', [
                        'store_id' => $store->store_id,
                        'page' => $page,
                        'response_status' => $response->status(),
                        'response_body' => $response->body(),
                    ]);
                    break;
                }

                $responseData = $response->json();
                $orders = $responseData['orders'] ?? [];
                $totalOrders = $responseData['total'] ?? 0;
                $pagination = $responseData['paginationOutput'] ?? [];
                $currentPageTotal = count($orders);

                $this->info("Page {$page}: Found {$currentPageTotal} orders (Total: {$totalOrders})");

                if (empty($orders)) {
                    $this->info("No more orders found for store {$store->store_name}.");
                    break;
                }

                $pageOrdersSynced = 0;
                foreach ($orders as $order) {
                    try {
                        // Log::info('Processing eBay order', [
                        //     'raw_order' => $order,
                        //     'page' => $page,
                        // ]);

                        $orderId = $order['orderId'];
                        $items = $order['lineItems'] ?? [];

                        $totalQuantity = array_sum(array_column($items, 'quantity')) ?? 1;
                        $status = $order['orderFulfillmentStatus'] ?? null;

                        // Uncomment these lines if you want to skip certain order statuses
                        // if (strtoupper($status) === 'FULFILLED') {
                        //     $this->info("Skipping order {$orderId} because it is already fulfilled.");
                        //     continue;
                        // }
                        // if ($status !== 'NOT_STARTED') {
                        //     $this->info("Skipping order {$orderId} because status is {$status}.");
                        //     continue;
                        // }

                        $this->info("Inserting order {$orderId} (status = {$status}).");

                        $fulfillmentStatus = match (strtoupper($status)) {
                            'FULFILLED'            => 'FULFILLED',
                            'IN_PROGRESS'          => 'IN_PROGRESS',
                            'CANCELLED'            => 'CANCELLED',
                            'PARTIALLY_FULFILLED'  => 'PARTIALLY_FULFILLED',
                            'NOT_STARTED', 'ACTIVE' => 'UNFULFILLED',
                            default                => 'UNKNOWN',
                        };

                        $shipstationStatus = match ($status) {
                            'FULFILLED'    => 'shipped',
                            'CANCELLED'    => 'cancelled',
                            'ACTIVE',
                            'NOT_STARTED'  => 'unshipped',
                            default        => strtolower($status ?? 'unknown'),
                        };

                        $trackingNumber = null;
                        $shippingCarrier = null;
                        $shippingCost = 0.00;

                        // Fetch fulfillment details if available
                        if (!empty($order['fulfillmentHrefs'][0]) && !str_ends_with($order['fulfillmentHrefs'][0], '/shipping_fulfillment/')) {
                            // CORRECTION: Timeout should be on the request builder, not response
                            $fulfillmentResponse = Http::withToken($store->access_token)
                                ->timeout(10) // Set timeout for individual fulfillment requests
                                ->get($order['fulfillmentHrefs'][0]);

                            if ($fulfillmentResponse->successful()) {
                                $fulfillmentData = $fulfillmentResponse->json();
                                $trackingNumber = $fulfillmentData['shipmentTrackingNumber'] ?? null;
                                $shippingCarrier = $fulfillmentData['shippingCarrier'] ?? null;

                                // Log::info('eBay Tracking Number', [
                                //     'order_id' => $orderId,
                                //     'tracking_number' => $trackingNumber,
                                //     'carrier' => $shippingCarrier,
                                // ]);
                            } else {
                                Log::warning('Failed to fetch fulfillment details', [
                                    'order_id' => $orderId,
                                    'response_status' => $fulfillmentResponse->status(),
                                    'response_body' => $fulfillmentResponse->body(),
                                ]);
                            }
                        }

                        // Calculate totals
                        $buyerTotal = (float)($order['pricingSummary']['total']['value'] ?? 0.00);
                        if (!empty($order['lineItems'])) {
                            foreach ($order['lineItems'] as $li) {
                                if (!empty($li['ebayCollectAndRemitTaxes'])) {
                                    foreach ($li['ebayCollectAndRemitTaxes'] as $tax) {
                                        $buyerTotal += (float)($tax['amount']['value'] ?? 0.00);
                                    }
                                }
                            }
                        }
                        $sellerTotal = (float)($order['paymentSummary']['totalDueSeller']['value'] ?? 0.00);

                        $shippingService = $order['fulfillmentStartInstructions'][0]['shippingStep']['shippingServiceCode'] ?? null;
                        if (!empty($shippingService)) {
                            $shippingCarrier = match (true) {
                                stripos($shippingService, 'usps') !== false => 'USPS',
                                stripos($shippingService, 'ups') !== false => 'UPS',
                                stripos($shippingService, 'fedex') !== false => 'FedEx',
                                stripos($shippingService, 'dhl') !== false => 'DHL',
                                default => $shippingService,
                            };
                        }

                        $map = [
                            3 => 1,
                            4 => 2,
                            5 => 3,
                        ];
                        $shippingCost = $order['pricingSummary']['deliveryCost']['value'] 
                            ?? collect($order['lineItems'])->sum(fn($item) => $item['deliveryCost']['shippingCost']['value'] ?? 0)
                            ?? 0.00;
                        $marketplace = 'ebay' . ($map[$store->store_id] ?? $store->store_id);

                        $orderData = [
                            'marketplace'        => $marketplace,
                            'store_id'           => $store->store_id,
                            'order_number'       => $orderId,
                            'external_order_id'  => $orderId,
                            'order_date' => $order['creationDate'] ? Carbon::parse($order['creationDate'])->setTimezone('America/New_York') : null,
                            'order_age'          => isset($order['creationDate']) ? now()->diffInDays(Carbon::parse($order['creationDate'])) : null,
                            'quantity'           => $totalQuantity,
                            'order_total'        => $buyerTotal ?? 0.00,
                            'shipping_service'   => $shippingService,
                            'tracking_number'    => $trackingNumber,
                            'shipping_carrier'   => $shippingCarrier,
                            'shipping_cost'      => $shippingCost,
                            'item_sku'           => !empty($items) ? ($items[0]['sku'] ?? null) : null,
                            'item_name'          => !empty($items) ? ($items[0]['title'] ?? null) : null,
                            'recipient_name'     => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['fullName'] ?? null,
                            'recipient_email'    => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['email'] 
                                ?? $order['buyer']['email'] 
                                ?? null,
                            'recipient_phone'    => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['primaryPhone']['phoneNumber'] ?? null,
                            'ship_address1'      => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['addressLine1'] ?? null,
                            'ship_address2'      => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['addressLine2'] ?? null,
                            'ship_city'          => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['city'] ?? null,
                            'ship_state'         => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['stateOrProvince'] ?? null,
                            'ship_postal_code'   => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['postalCode'] ?? null,
                            'ship_country'       => $order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo']['contactAddress']['countryCode'] ?? null,
                            'payment_status'     => $order['orderPaymentStatus'] ?? null,
                            'order_status'       => $shipstationStatus,
                            'fulfillment_status' => $fulfillmentStatus,
                            'cancel_status'      => $order['cancelStatus']['cancelState'] ?? 'NONE_REQUESTED',
                            'raw_data'           => json_encode($order), // Uncommented for debugging
                            'raw_items'          => json_encode($items), // Uncommented for debugging
                        ];
            //             $existingOrder = Order::where('marketplace', $marketplace)
            // ->where('order_number', $orderId)
            // ->first();
            // if ($existingOrder) {
            //     $this->info("Skipping order {$orderId} because it already exists.");
            //     continue; // Skip the entire order and its items
            // }
        // if ($existingOrder && $existingOrder->order_status === 'shipped') {
        //     $this->info("Skipping order {$orderId} because it is already shipped in DB.");
        //     continue; 
        // }
                        // $existingOrder = Order::where('marketplace', $marketplace)
                        //     ->where('order_number', $orderId)
                        //     ->first();

                        // if ($existingOrder) {
                        //     if ($existingOrder->order_status === 'shipped' || $existingOrder->fulfillment_status === 'FULFILLED') {
                        //         $this->info("🚫 Skipping order {$orderId} — already shipped in DB.");
                        //         continue;
                        //     }

                        //     $this->info("🔁 Updating existing order {$orderId} (not shipped yet).");
                        // }
                        $existingOrder = Order::where('marketplace', $marketplace)
                            ->where('order_number', $orderId)
                            ->first();

                        if ($existingOrder) {
                            if (in_array($existingOrder->order_status, ['shipped'])) {
                                $this->info("🚫 Skipping order {$orderId} — already shipped in DB.");
                                continue;
                            }

                            $this->info("🔁 Updating existing order {$orderId} (not shipped yet).");
                        }

                        $orderModel = Order::updateOrCreate(
                            [
                                'marketplace' => $marketplace,
                                'order_number' => $orderId,
                            ],
                            $orderData
                        );

                        // Process order items - only skip if order was already shipped
                        if ($existingOrder && in_array($existingOrder->order_status, ['shipped'])) {
                            $this->info("Skipping order items for {$orderId} because order is already shipped.");
                            continue; // Skip items loop
                        }
                        foreach ($items as $item) {
                            try {
                                $itemTax = 0.00;
                                if (!empty($item['ebayCollectAndRemitTaxes'])) {
                                    foreach ($item['ebayCollectAndRemitTaxes'] as $tax) {
                                        $itemTax += floatval($tax['amount']['value'] ?? 0);
                                    }
                                }

                                $qty = $item['quantity'] ?? 1;
                                $dimensionData = getDimensionsBySku($item['sku'] ?? '', $qty); 

                                $itemData = [
                                    'order_id'           => $orderModel->id,
                                    'order_number'       => $orderId,
                                    'order_item_id'      => $item['lineItemId'] ?? null,
                                    'sku'                => $item['sku'] ?? null,
                                    'item_sku'           => $item['sku'] ?? null,
                                    'product_name'       => $item['title'] ?? null,
                                    'asin'               => null,
                                    'upc'                => $item['productIdentifier']['gtin'] ?? null,
                                    'quantity_ordered'   => $item['quantity'] ?? 0,
                                    'quantity_shipped'   => $item['quantityShipped'] ?? 0,
                                    'unit_price'         => $item['lineItemCost']['value'] ?? 0.00,
                                    'item_tax'           => $itemTax,
                                    'promotion_discount' => 0.00,
                                    'currency'           => $item['lineItemCost']['currency'] ?? 'USD',
                                    'is_gift'            => $item['giftDetails']['isGift'] ?? 0,
                                    'weight'             => $dimensionData['weight'] ?? 20,
                                    'weight_unit'        => null,
                                    'length'             => $dimensionData['length'] ?? 8,
                                    'width'              => $dimensionData['width'] ?? 6,
                                    'height'             => $dimensionData['height'] ?? 2,
                                    'dimensions'         => null,
                                    'marketplace'        => $marketplace,
                                    // 'raw_data'           => json_encode($item), // Uncomment for debugging
                                ];

                                OrderItem::updateOrCreate(
                                    [
                                        'order_id' => $orderModel->id,
                                        'order_item_id' => $item['lineItemId'] ?? '',
                                        'marketplace' => $marketplace,
                                    ],
                                    $itemData
                                );
                            } catch (\Exception $itemException) {
                                $this->error("Error saving item for order {$orderId}: " . $itemException->getMessage());
                                Log::error('eBay order item save error', [
                                    'store_id' => $store->store_id,
                                    'order_id' => $orderId,
                                    'item_id' => $item['lineItemId'] ?? null,
                                    'exception' => $itemException->getMessage(),
                                    'item' => $item,
                                ]);
                            }
                        }

                        $pageOrdersSynced++;
                        $totalOrdersSynced++;
                        $this->info("✅ Order {$orderId} for store {$store->store_name} synced (Page {$page}/{$pageOrdersSynced})");

                    } catch (\Exception $e) {
                        $this->error("Error saving order {$orderId}: " . $e->getMessage());
                        Log::error('eBay order save error', [
                            'store_id' => $store->store_id,
                            'order_id' => $orderId,
                            'page' => $page,
                            'exception' => $e->getMessage(),
                            'order' => $order,
                        ]);
                    }
                }

                // Check if there are more pages
                $hasMorePages = $currentPageTotal === $limit && $page < $maxPages;
                
                if ($hasMorePages) {
                    $this->info("📄 Page {$page} completed. Found {$pageOrdersSynced} new/updated orders. Continuing to next page...");
                    $page++;
                } else {
                    $this->info("📄 Page {$page} completed. Found {$pageOrdersSynced} new/updated orders. No more pages or limit reached.");
                    break;
                }

                // Add a small delay between pages to be respectful to the API
                sleep(1);

            } while ($hasMorePages);

            $this->info("✅ Completed processing store {$store->store_name}. Total orders synced: {$totalOrdersSynced}");
        }

        $this->info('✅ eBay orders sync completed for all stores!');
        return 0;
    }
}