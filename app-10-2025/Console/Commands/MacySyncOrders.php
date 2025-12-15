<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Carbon\Carbon;
use Exception;
use App\Services\MiraklFulfillmentService;

class MacySyncOrders extends Command
{
    protected $signature = 'macys:sync-orders';
    protected $description = 'Fetch and sync Macy orders and order items for the last 2 days';
    protected MiraklFulfillmentService $miraklService;

    public function __construct(MiraklFulfillmentService $miraklService)
    {
        parent::__construct();
        $this->miraklService = $miraklService;
    }

    public function handle()
    {
        $this->info('🔄 Syncing Macy orders for the last 2 days...');
        // Log::info('Starting Macy order sync');

        $token = (new \ReflectionClass($this->miraklService))
            ->getMethod('refreshAccessToken')
            ->invoke($this->miraklService);
        if (!$token) {
            $this->error('❌ Failed to get Macy API access token.');
            return 1;
        }

        $dateRange = $this->getDateRange();
        $pageToken = null;
        $page = 1;

        do {
            try {
                $url = 'https://miraklconnect.com/api/v2/orders?fulfillment_type=FULFILLED_BY_SELLER&limit=20';
                $url .= '&updated_from=' . urlencode($dateRange['begin']);
                if ($pageToken) $url .= '&page_token=' . urlencode($pageToken);

                $response = Http::withToken($token)->get($url);
                if ($response->status() === 401) {
                    Log::warning('Token expired. Refreshing token...');
                    $token = (new \ReflectionClass($this->miraklService))
            ->getMethod('refreshAccessToken')
            ->invoke($this->miraklService);
                    if (!$token) {
                        $this->error('❌ Failed to refresh Macy API token.');
                        break;
                    }
                    $response = Http::withToken($token)->get($url);
                }

                if (!$response->successful()) {
                    $this->error("❌ API Failed: " . $response->body());
                    Log::error('Macy API Response Error', ['body' => $response->body()]);
                    break;
                }

                $orders = $response->json()['data'] ?? [];
                Log::info('Mirakl API Orders Response', [
    'orders_count' => count($orders),
    'orders' => $orders
]);
                $pageToken = $response->json()['next_page_token'] ?? null;

                if (empty($orders)) break;

                foreach ($orders as $order) {
                    $orderId = $order['id'] ?? null;
                    if (!$orderId) continue;

                    $totalQuantity = array_sum(array_column($order['order_lines'] ?? [], 'quantity')) ?? 1;
                    $shippingCost = 0;
                    $orderTotal = 0;

                    foreach ($order['order_lines'] ?? [] as $line) {
                        $linePrice = $line['price']['amount'] ?? 0;
                        $lineQty = $line['quantity'] ?? 1;
                        $taxes = array_sum(array_map(fn($t) => $t['amount']['amount'] ?? 0, $line['taxes'] ?? []));
                        $shipping = $line['total_shipping_price']['amount'] ?? 0;

                        $orderTotal += ($linePrice * $lineQty) + $taxes + $shipping;
                        $shippingCost += $shipping;
                    }

                    $address = $order['shipping_info']['address'] ?? [];
                    $carrierName = $order['shipping_info']['carrier'] ?? null;
                    $trackingId = $order['shipping_info']['tracking_number'] ?? null;
                    $marketplaceName = $order['origin']['channel_name'] ?? 'macys';

                    // --- Orders table ---
                    $orderData = [
                        'store_id' => 1,
                        'marketplace' => $marketplaceName,
                        'marketplace_order_id' => $orderId,
                        'order_number' => $order['channel_order_id'],
                        'order_date' => isset($order['created_at']) ? Carbon::parse($order['created_at']) : null,
                        'order_age' => isset($order['created_at']) ? now()->diffInDays(Carbon::parse($order['created_at'])) : null,
                        'quantity' => $totalQuantity,
                        'order_total' => $orderTotal,
                        'shipping_cost' => $shippingCost,
                        'order_status' => $this->mapMacyStatusToShipStation($order['status'] ?? null),
                        'fulfillment_status' => $this->mapMacyStatusToShipStation($order['status'] ?? null),
                        'recipient_name' => trim(($address['first_name'] ?? '') . ' ' . ($address['last_name'] ?? '')),
                        'recipient_company' => $address['company'] ?? null,
                        'recipient_email' => $order['shipping_info']['email'] ?? null,
                        'recipient_phone' => $order['shipping_address']['telephone'] ?? null,
                        'ship_address1' => $address['street'] ?? null,
                        'ship_address2' => $address['street_additional_info'] ?? null,
                        'ship_city' => $address['city'] ?? null,
                        'ship_state' => $address['state'] ?? null,
                        'ship_postal_code' => $address['zip_code'] ?? null,
                        'ship_country' => $address['country_iso_code'] ?? null,
                        'shipping_service' => $order['shipping_info']['method'] ?? null,
                        'shipping_carrier' => $carrierName,
                        'tracking_id' => $trackingId,
                        'raw_data' => json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ];

                   $existingOrder = Order::where('marketplace', $marketplaceName)
                        ->where('order_number', $order['channel_order_id'])
                        ->first();

                    if ($existingOrder && strtolower($existingOrder->order_status) === 'shipped') {
                        unset($orderData['order_status']);
                        unset($orderData['fulfillment_status']);
                        $this->info("🚫 Skipping status update for already shipped order {$order['channel_order_id']}.");
                    }

                    // Update or create order (will not touch shipped status)
                    $orderModel = Order::updateOrCreate(
                        [
                            'marketplace' => $marketplaceName,
                            'order_number' => $order['channel_order_id'],
                        ],
                        $orderData
                    );

                    // --- Order Items table ---
                    foreach ($order['order_lines'] ?? [] as $line) {
                        $itemId = $line['id'] ?? $line['channel_order_line_id'];
                        $qty = $line['quantity'] ?? 1;
                        $dimensionData = getDimensionsBySku($line['product']['id'] ?? '', $qty);

                        $itemData = [
                            'order_id' => $orderModel->id,
                            'order_item_id' => $itemId,
                            'sku' => $line['product']['id'] ?? null,
                            'item_name' => $line['product']['title'] ?? null,
                            'product_name' => $line['product']['title'] ?? null,
                            'quantity_ordered' => $line['quantity'] ?? 1,
                            'quantity_shipped' => in_array(strtoupper($order['status'] ?? ''), ['SHIPPED','FULFILLED']) ? ($line['quantity'] ?? 1) : 0,
                            'unit_price' => $line['price']['amount'] ?? 0,
                            'item_tax' => array_sum(array_map(fn($t) => $t['amount']['amount'] ?? 0, $line['taxes'] ?? [])),
                            'promotion_discount' => 0,
                            'currency' => $line['price']['currency'] ?? 'USD',
                            'marketplace' => $marketplaceName,
                            'weight' => $dimensionData['weight'] ?? 0,
                            'length' => $dimensionData['length'] ?? 0,
                            'width' => $dimensionData['width'] ?? 0,
                            'height' => $dimensionData['height'] ?? 0
                        ];

                        OrderItem::updateOrCreate(
                            [
                                'order_id' => $orderModel->id,
                                'order_item_id' => $itemId,
                                'marketplace' => $marketplaceName,
                            ],
                            $itemData
                        );
                    }

                    // Log::info("✅ Macy order and items synced: {$orderId}");
                }

                $page++;
            } catch (Exception $e) {
                $this->error("⚠️ Error on page $page: " . $e->getMessage());
                Log::error('Macy order sync error', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                break;
            }
        } while ($pageToken);

        $this->info('✅ Macy order sync completed!');
        // Log::info('Macy order sync completed');
        return 0;
    }
    private function getDateRange()
    {
        $yesterday = Carbon::yesterday('UTC');
        $start = $yesterday->copy()->subDays(2);

        return [
            'begin' => $start->format('Y-m-d\TH:i:s+00:00'),
            'end' => $yesterday->format('Y-m-d\TH:i:s+00:00'),
        ];
    }

    private function mapMacyStatusToShipStation($status)
    {
        return match(strtoupper($status ?? '')) {
            'AWAITING_ACCEPTANCE', 'PROCESSING', 'PENDING' => 'unshipped',
            'SHIPPED', 'FULFILLED' => 'shipped',
            'CANCELLED' => 'cancelled',
            'ON_HOLD' => 'on_hold',
            'COMPLETED', 'CLOSED' => 'completed',
            'RETURNED' => 'returned',
            default => $status,
        };
    }
}
