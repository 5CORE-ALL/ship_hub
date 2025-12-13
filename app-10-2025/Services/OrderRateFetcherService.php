<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Shipper;
use App\Models\OrderShippingRate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class OrderRateFetcherService
{
    protected ShippingRateService $shippingRateService;

    public function __construct(ShippingRateService $shippingRateService)
    {
        $this->shippingRateService = $shippingRateService;
    }

    /**
     * Fetch shipping rates for orders.
     * @param int|null $orderId Single order if provided, otherwise batch
     * @param int $batchSize Number of orders to fetch in batch
     */
    public function fetch(?int $orderId = null, int $batchSize = 10): array
    {
        $ordersQuery = Order::query()
            ->select(
                'orders.*',
                'order_items.product_name',
                'order_items.sku',
                'order_items.height',
                'order_items.width',
                'order_items.length',
                'order_items.weight'
            )
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','amazon','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','aliexpress','amazon'])
            ->whereNotNull('orders.recipient_name')         
            ->where('orders.recipient_name', '!=', '')
            ->whereNotNull('orders.ship_address1')
            ->where('orders.ship_address1', '!=', '')
            ->whereIn('orders.order_status', [
                'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','paid'
            ])
            ->where('orders.shipping_rate_fetched', false);

        if ($orderId) {
            $ordersQuery->where('orders.id', $orderId);
        } else {
            $ordersQuery->limit($batchSize);
        }

        $orders = $ordersQuery->get();
        $results = [];

        foreach ($orders as $order) {
            try {
                $results[$order->id] = $this->processOrder($order);
            } catch (\Exception $e) {
                Log::error("Failed to fetch rate for order {$order->id}", ['error' => $e->getMessage()]);
                $results[$order->id] = [
                    'success' => false,
                    'message' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    /**
     * Process a single order and store shipping rates
     */
    protected function processOrder(Order $order): array
    {
        $shipper = Shipper::first();

        $length = $order->length ?? 4.0;
        $width  = $order->width ?? 4.0;
        $height = $order->height ?? 7.0;
        $weight = $order->weight ?? 0.25;

        $params = [
            'order_id'         => $order->id,
            'ship_to_name'     => $order->recipient_name,
            'ship_to_address'  => $order->ship_address1,
            'ship_to_city'     => $order->ship_city,
            'ship_to_state'    => $order->ship_state,
            'ship_to_zip'      => $order->ship_postal_code,
            'ship_to_country'  => $order->ship_country ?: 'US',
            'ship_to_phone'    => $order->recipient_phone ?: '5551234567',
            'receiver_suburb'  => $order->ship_city ?: 'Los Angeles',
            'receiver_postcode'=> $order->ship_postal_code ?: '90001',
            'receiver_country' => $order->ship_country ?: 'US',
            'weight_value'     => $weight,
            'weight_unit'      => 'pound',
            'length'           => $length,
            'width'            => $width,
            'height'           => $height,
            'dim_unit'         => 'inch',
        ];

        if ($shipper) {
            $params['ship_from_name']        = $shipper->name ?? 'Jane Smith';
            $params['ship_from_phone']       = $shipper->shipper_number ?? '1111111111';
            $params['ship_from_address']     = $shipper->address ?? '456 Origin Rd.';
            $params['ship_from_city']        = $shipper->city ?? 'Austin';
            $params['ship_from_state']       = $shipper->state ?? 'TX';
            $params['ship_from_zip']         = $shipper->postal_code ?? '78701';
            $params['ship_from_country']     = $shipper->country ?? 'US';
            $params['sender_suburb']         = $shipper->city ?? 'New York';
            $params['sender_postcode']       = $shipper->postal_code ?? '10001';
            $params['sender_country']        = $shipper->country ?? 'US';
            $params['ship_from_residential'] = 'no';
        }

        $result = $this->shippingRateService->getDefaultRate($params);

        if (!$result['success']) {
            return [
                'success' => false,
                'message' => "No rates found"
            ];
        }

        $normalizedRates = $result['rates'] ?? [];
        $gptRate         = $result['gpt_cheapest'] ?? null;
        $defaultRate     = $result['default_rate'] ?? null;

        // Save all rates
        foreach ($normalizedRates as $rate) {
            OrderShippingRate::updateOrCreate(
                [
                    'order_id' => $order->id,
                    'rate_id'  => $rate['rate_id'] ?? null,
                    'service'  => $rate['service'],
                    'carrier'  => $rate['carrier'],
                ],
                [
                    'source'            => $rate['source'],
                    'price'             => $rate['price'],
                    'currency'          => $rate['currency'],
                    'is_gpt_suggestion' => $gptRate
                        && $rate['service'] == ($gptRate['service'] ?? '')
                        && $rate['carrier'] == ($gptRate['carrier'] ?? ''),
                    'is_cheapest'       => 0, 
                ]
            );
        }

        $cheapestRate = OrderShippingRate::where('order_id', $order->id)
                        ->where('service', '!=', 'USPS Media Mail') 
                        ->where('service', '!=', 'Saver Drop Off')
                        // ->where('service', 'NOT LIKE', '%DROPOFF%') 
                        ->whereRaw('LOWER(service) NOT LIKE ?', ['%dropoff%'])
                        ->orderBy('price', 'asc')
                        ->first();
        if ($cheapestRate) {
            OrderShippingRate::where('order_id', $order->id)->update(['is_cheapest' => 0]);
            $cheapestRate->update(['is_cheapest' => 1]);
            $order->default_rate_id       = $cheapestRate->rate_id;
            $order->default_carrier       = $cheapestRate->carrier;
            $order->default_price         = $cheapestRate->price;
            $order->default_currency      = $cheapestRate->currency;
            $order->shipping_rate_fetched = true;
            $order->save();
        }

        return [
            'success' => true,
            'message' => $defaultRate
                ? "Default rate saved: {$defaultRate['carrier']} \${$defaultRate['price']}"
                : "Default rate saved",
        ];
    }
}
