<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Shipment;

class SyncWayfairOrders extends Command
{
    protected $signature = 'wayfair:sync-orders';
    protected $description = 'Fetch Wayfair orders, save orders/items, update shipments, and get tracking numbers';

    protected $authUrl = 'https://sso.auth.wayfair.com/oauth/token';
    protected $graphqlUrl = 'https://api.wayfair.com/v1/graphql';

    protected $clientId;
    protected $clientSecret;
    protected $audience;
    protected $grantType = 'client_credentials';

    public function __construct()
    {
        parent::__construct();
        $this->clientId     = config('services.wayfair.client_id');
        $this->clientSecret = config('services.wayfair.client_secret');
        $this->audience     = config('services.wayfair.audience');
    }

    public function handle()
    {
        $this->info("Fetching Wayfair access token...");

        $token = $this->getAccessToken();
        if (!$token) {
            $this->error("❌ Failed to retrieve access token.");
            return;
        }

        $totalSaved = 0;
        $purchaseOrders = $this->fetchDropshipOrders($token);

        foreach ($purchaseOrders as $order) {
            $orderId = $order['poNumber'] ?? null;
            if (!$orderId) continue;

            $products = $order['products'] ?? [];
            $totalQuantity = collect($products)->sum('quantity');
            $orderTotal = collect($products)->sum(fn($p) => ($p['price'] ?? 0) * ($p['quantity'] ?? 1));
            $firstProduct = $products[0] ?? [];

            // ----------- Order Status Logic ------------

            $orderStatus = 'Accepted';
            $fulfillmentStatus = 'Unshipped';
            if (!empty($order['estimatedShipDate']) && Carbon::parse($order['estimatedShipDate'])->isPast()) {
                $fulfillmentStatus = 'Shipped';
            }
            foreach ($products as $product) {
                if (!empty($product['event']['endDate']) && Carbon::parse($product['event']['endDate'])->isPast()) {
                    $fulfillmentStatus = 'Shipped';
                    break;
                }
            }

            // ---------------- Get Tracking Number ----------------
            $trackingNumber = $this->getTrackingNumber($token, $orderId);

            $orderData = [
                'store_id'            => 1,
                'marketplace'         => 'wayfair',
                'marketplace_order_id'=> $orderId,
                'order_number'        => $orderId,
                'external_order_id'   => $orderId,
                'order_date'          => isset($order['poDate']) ? Carbon::parse($order['poDate']) : null,
                'order_age'           => isset($order['poDate']) ? now()->diffInDays(Carbon::parse($order['poDate'])) : null,
                'quantity'            => $totalQuantity,
                'order_total'         => $orderTotal,
                'shipping_cost'       => 0,
                'order_status'        => $orderStatus,
                'fulfillment_status'  => $fulfillmentStatus,
                'recipient_name'      => $order['customerName'] ?? null,
                'recipient_company'   => null,
                'recipient_email'     => null,
                'recipient_phone'     => null,
                'ship_address1'       => $order['customerAddress1'] ?? null,
                'ship_address2'       => $order['customerAddress2'] ?? null,
                'ship_city'           => $order['customerCity'] ?? null,
                'ship_state'          => $order['customerState'] ?? null,
                'ship_postal_code'    => $order['customerPostalCode'] ?? null,
                'ship_country'        => 'US',
                'shipping_service'    => $order['shippingInfo']['shipSpeed'] ?? null,
                'shipping_carrier'    => $order['shippingInfo']['carrierCode'] ?? null,
                'tracking_number'     => $trackingNumber,
                'item_sku'            => $firstProduct['partNumber'] ?? null,
                'item_name'           => $firstProduct['name'] ?? null,
                'raw_data'            => json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];

            $dbOrder = Order::updateOrCreate(
                ['marketplace' => 'wayfair', 'order_number' => $orderId, 'external_order_id' => $orderId],
                $orderData
            );

            // Save order items
            foreach ($products as $product) {
                OrderItem::updateOrCreate(
                    ['order_id' => $dbOrder->id, 'sku' => $product['partNumber'] ?? null],
                    ['product_name' => $product['name'] ?? null, 'quantity' => $product['quantity'] ?? 1, 'price' => $product['price'] ?? 0]
                );
            }

            // ----------------- Handle Shipments -----------------
            if (!empty($order['packingSlipUrl'])) {
                $shipment = Shipment::firstOrNew(['order_id' => $dbOrder->id]);

                // Only download if label_url not already set
                if (empty($shipment->label_url)) {
                    try {
                        $packingResponse = Http::withToken($token)->get($order['packingSlipUrl']);
                        if ($packingResponse->successful()) {
                            $dir = storage_path('app/packing_slips');
                            if (!is_dir($dir)) mkdir($dir, 0755, true);
                            $filePath = "{$dir}/{$orderId}.pdf";
                            file_put_contents($filePath, $packingResponse->body());
                            $shipment->label_url = $filePath;
                        }
                    } catch (\Exception $e) {
                        $this->warn("⚠️ Error fetching packing slip for PO {$orderId}: " . $e->getMessage());
                    }
                }

                $shipment->carrier           = $order['shippingInfo']['carrierCode'] ?? null;
                $shipment->service_type      = $order['shippingInfo']['shipSpeed'] ?? null;
                $shipment->tracking_number   = $trackingNumber;
                $shipment->package_weight    = null; // add weight if available
                $shipment->package_dimensions= null; // add dimensions if available
                $shipment->label_status      = 'active';
                $shipment->shipment_status   = $fulfillmentStatus;
                $shipment->label_data        = json_encode($order['shippingInfo'] ?? [], JSON_UNESCAPED_SLASHES);
                $shipment->ship_date         = isset($order['estimatedShipDate']) ? Carbon::parse($order['estimatedShipDate']) : null;
                $shipment->cost              = 0;
                $shipment->currency          = 'USD';
                $shipment->save();
            }
            // -----------------------------------------------------------

            $totalSaved++;
            $this->info("✅ Saved Wayfair Order: $orderId with {$totalQuantity} items, Tracking: {$trackingNumber}");
        }

        $this->info("🎉 Completed. Total Wayfair orders saved: $totalSaved");
    }

    private function getAccessToken()
    {
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($this->authUrl, [
                'grant_type'    => $this->grantType,
                'audience'      => $this->audience,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

        return $response->successful() ? $response->json()['access_token'] ?? null : null;
    }

    private function fetchDropshipOrders($token)
    {
        $query = <<<'GRAPHQL'
        query getDropshipPurchaseOrders {
          getDropshipPurchaseOrders(
            limit: 50
            sortOrder: DESC
          ) {
            poNumber
            poDate
            estimatedShipDate
            customerName
            customerAddress1
            customerAddress2
            customerCity
            customerState
            customerPostalCode
            orderType
            shippingInfo {
              shipSpeed
              carrierCode
            }
            packingSlipUrl
            warehouse {
              id
              name
            }
            products {
              partNumber
              name
              quantity
              price
              event {
                startDate
                endDate
              }
            }
          }
        }
        GRAPHQL;

        $response = Http::withToken($token)->post($this->graphqlUrl, ['query' => $query]);
        \Log::info('Wayfair Dropship API Response', $response->json());

        return $response->successful() ? $response->json()['data']['getDropshipPurchaseOrders'] ?? [] : [];
    }

private function getTrackingNumber($token, $poNumber)
{
    // Remove any letter prefix
    $poNumberNumeric = preg_replace('/^[A-Z]+/', '', $poNumber);

    $query = <<<'GRAPHQL'
    query labelGenerationEvents($filters: [LabelGenerationEventFilterInput!]!) {
      labelGenerationEvents(
        limit: 1
        filters: $filters
      ) {
        id
        eventDate
        pickupDate
        poNumber
        shippingLabelInfo {
          carrier
          carrierCode
          trackingNumber
        }
      }
    }
    GRAPHQL;

    $filters = [
        ['field' => 'poNumber', 'equals' => $poNumberNumeric]
    ];
  
    $response = Http::withToken($token)->post($this->graphqlUrl, [
        'query' => $query,
        'variables' => ['filters' => $filters],
    ]);
 \Log::info('Wayfair Label API Response', $response->json());
    if (!$response->successful()) {
        \Log::error('Wayfair Label API failed', $response->json());
        return null;
    }

    $events = $response->json()['data']['labelGenerationEvents'] ?? [];
    if (empty($events)) return null;

    $event = $events[0];
    $trackingNumber = $event['shippingLabelInfo'][0]['trackingNumber'] ?? null;
    return $trackingNumber;
}


}
