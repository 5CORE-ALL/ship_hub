<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use Carbon\Carbon;
use Exception;

class BestBuySyncOrders extends Command
{
    protected $signature = 'bestbuy:sync-orders';
    protected $description = 'Fetch and sync BestBuy orders for the last 60 days';

    public function handle()
    {
        $this->info('🔄 Syncing BestBuy orders for the last 60 days...');
        Log::info('Starting BestBuy order sync');

        $token = $this->getAccessToken();
        if (!$token) {
            $this->error('❌ Failed to get BestBuy API access token.');
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
                    $token = $this->refreshAccessToken();
                    if (!$token) {
                        $this->error('❌ Failed to refresh BestBuy API token.');
                        break;
                    }
                    $response = Http::withToken($token)->get($url);
                }

                if (!$response->successful()) {
                    $this->error("❌ API Failed: " . $response->body());
                    Log::error('BestBuy API Response Error', ['body' => $response->body()]);
                    break;
                }

                $orders = $response->json()['data'] ?? [];
                $ordersData = $response->json();
                Log::info('BestBuy API Full Response', [
                    'response_pretty' => json_encode($ordersData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
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

                    $orderData = [
                        'store_id' => 1,
                        'marketplace' => 'bestbuy',
                        'marketplace_order_id' => $orderId,
                        'order_number' => $orderId,
                        'external_order_id' => $orderId,
                        'order_date' => isset($order['created_at']) ? Carbon::parse($order['created_at']) : null,
                        'order_age' => isset($order['created_at']) ? now()->diffInDays(Carbon::parse($order['created_at'])) : null,
                        'quantity' => $totalQuantity,
                        'order_total' => $orderTotal,
                        'shipping_cost' => $shippingCost,
                        'order_status' => $this->mapBestBuyStatusToShipStation($order['status'] ?? null),
                        'fulfillment_status' => $this->mapBestBuyStatusToShipStation($order['status'] ?? null),
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
                        'item_sku' => $order['order_lines'][0]['product']['id'] ?? null,
                        'item_name' => $order['order_lines'][0]['product']['title'] ?? null,
                        'raw_data' => json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ];

                    Order::updateOrCreate(
                        ['marketplace' => 'bestbuy', 'order_number' => $orderId, 'external_order_id' => $orderId],
                        $orderData
                    );

                    Log::info('BestBuy order synced', ['order_id' => $orderId]);
                }

                $page++;
            } catch (Exception $e) {
                $this->error("⚠️ Error on page $page: " . $e->getMessage());
                Log::error('BestBuy order sync error', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                break;
            }
        } while ($pageToken);

        $this->info('✅ BestBuy order sync completed!');
        Log::info('BestBuy order sync completed');
        return 0;
    }

    private function getAccessToken()
    {
        return cache()->remember('bestbuy_access_token', 3500, function () {
            return $this->refreshAccessToken();
        });
    }

    private function refreshAccessToken()
    {
        $response = Http::asForm()->post('https://auth.mirakl.net/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => config('services.bestbuy.client_id'),
            'client_secret' => config('services.bestbuy.client_secret'),
        ]);

        if ($response->successful() && isset($response->json()['access_token'])) {
            return $response->json()['access_token'];
        }

        Log::error('Failed to get BestBuy access token', ['body' => $response->body()]);
        return null;
    }

    private function getDateRange()
    {
        $yesterday = Carbon::yesterday('UTC');
        $start = $yesterday->copy()->subDays(60);

        return [
            'begin' => $start->format('Y-m-d\TH:i:s+00:00'),
            'end' => $yesterday->format('Y-m-d\TH:i:s+00:00'),
        ];
    }

    private function mapBestBuyStatusToShipStation($status)
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
