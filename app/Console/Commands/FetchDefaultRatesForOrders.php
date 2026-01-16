<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShippingRateService;
use App\Models\Order;
use App\Models\Shipper;
use App\Models\OrderShippingRate; 
use Illuminate\Support\Facades\Log;

class FetchDefaultRatesForOrders extends Command
{
    protected $signature = 'shipping:fetch-default-rates';
    protected $description = 'Fetch default (cheapest) shipping rate for every eligible order';
    protected ShippingRateService $shippingRateService;

    public function __construct(ShippingRateService $shippingRateService)
    {
        parent::__construct();
        $this->shippingRateService = $shippingRateService;
    }

    public function handle()
    {
        $this->info("🚚 Starting to fetch default rates for orders...");
        $orders = Order::query()
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
            ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','aliexpress','tiktok']) // amazon removed: No longer maintaining Amazon orders shipment
            ->whereIn('orders.order_status', [
                'Unshipped',
                'unshipped',
                'PartiallyShipped',
                'Accepted',
                'awaiting_shipment',
                'Created',
                'pending','paid'
            ])
                // ->where('orders.id','7736')
            ->where('orders.shipping_rate_fetched', false) 
            ->whereNotNull('orders.recipient_name')         
            ->where('orders.recipient_name', '!=', '')
            ->whereNotNull('orders.ship_address1')
            ->where('orders.ship_address1', '!=', '')
            ->limit(10)
            ->get();
        foreach ($orders as $order) {
            try {
                $shipper = Shipper::first();

                // Get dimensions from order_items relationship (sum for multiple items)
                // IMPORTANT: For Best Rate (D), we MUST use D dimensions and WT (D):
                // - length_d (L (D))
                // - width_d (W (D))
                // - height_d (H (D))
                // - weight_d (WT (D))
                $order->load('items');
                $length = $order->items->sum('length_d') ?: 4.0;
                $width  = $order->items->sum('width_d') ?: 4.0;
                $height = $order->items->sum('height_d') ?: 7.0;
                $weight = $order->items->sum('weight_d') ?: 0.25;


                $params = [
                    'order_id'        => $order->id,
                    'ship_to_name'    => $order->recipient_name,
                    'ship_to_address' => $order->ship_address1,
                    'ship_to_address2' => $order->ship_address2,
                    'ship_to_city'    => $order->ship_city,
                    'ship_to_state'   => $order->ship_state,
                    'ship_to_zip'     => $order->ship_postal_code,
                    'ship_to_country' => !empty($order->ship_country) ? $order->ship_country : 'US',
                    'ship_to_phone'    => $order->recipient_phone ?? '5551234567',
                    'receiver_suburb'   => !empty($order->ship_city) ? $order->ship_city : 'Los Angeles',
                    'receiver_postcode' => !empty($order->ship_postal_code) ? $order->ship_postal_code : '90001',
                    'receiver_country'  => !empty($order->ship_country) ? $order->ship_country : 'US',
                    'weight_value'    => $weight,
                    'weight_unit'     => 'pound',
                    'length'          => $length,
                    'width'           => $width,
                    'height'          => $height,
                    'dim_unit'        => 'inch',
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

                // Fetch rates via service
                $result = $this->shippingRateService->getDefaultRate($params);

                if ($result['success']) {
                    $normalizedRates = $result['rates'] ?? [];
                    $gptRate         = $result['gpt_cheapest'] ?? null;
                    $defaultRate     = $result['default_rate'];
                    // foreach ($normalizedRates as $rate) {
                    //     OrderShippingRate::updateOrCreate(
                    //         [
                    //             'order_id' => $order->id,
                    //             'rate_id'  => $rate['rate_id'] ?? null,
                    //             'service'  => $rate['service'],
                    //             'carrier'  => $rate['carrier'],
                    //         ],
                    //         [
                    //             'source'             => $rate['source'],
                    //             'price'              => $rate['price'],
                    //             'currency'           => $rate['currency'],
                    //             'is_cheapest'        => $rate['price'] == ($defaultRate['price'] ?? 0),
                    //             'is_gpt_suggestion'  => $gptRate && $rate['service'] == ($gptRate['service'] ?? '') && $rate['carrier'] == ($gptRate['carrier'] ?? '')
                    //         ]
                    //     );
                    // }
                    // $order->default_rate_id           = $defaultRate['rate_id'] ?? null;
                    // $order->default_carrier           = $defaultRate['carrier'] ?? null;
                    // $order->default_price             = $defaultRate['price'] ?? null;
                    // $order->default_currency          = $defaultRate['currency'] ?? null;
                    // $order->shipping_rate_fetched     = true; 
                    // $order->save();
                    $cheapestRate = collect($normalizedRates)->sortBy('price')->first();

                    foreach ($normalizedRates as $rate) {
                          $exists = OrderShippingRate::where('order_id', $order->id)
                            ->where('carrier', $rate['carrier'])
                            ->where('service', $rate['service'])
                            ->where('source', $rate['source'])
                            ->where('price', $rate['price']) 
                            ->exists();
                    if (!$exists) {
                        OrderShippingRate::updateOrCreate(
                            [
                                'order_id' => $order->id,
                                'rate_id'  => $rate['rate_id'] ?? null,
                                'service'  => $rate['service'],
                                'carrier'  => $rate['carrier'],
                            ],
                            [
                                'source'             => $rate['source'],
                                'price'              => $rate['price'],
                                'currency'           => $rate['currency'],
                                // 'is_cheapest'        => $rate['rate_id'] == ($cheapestRate['rate_id'] ?? $rate['rate_id']) &&
                                //                          $rate['service'] == $cheapestRate['service'] &&
                                //                          $rate['carrier'] == $cheapestRate['carrier'],
                                'is_gpt_suggestion'  => $gptRate && $rate['service'] == ($gptRate['service'] ?? '') &&
                                                         $rate['carrier'] == ($gptRate['carrier'] ?? '')
                            ]
                        );
                    }
                    }
                     // Log::info("Updating cheapest rate for order {$order->id}");

                    $cheapestRate = OrderShippingRate::where('order_id', $order->id)
                        ->where('service', '!=', 'USPS Media Mail') 
                        // ->where('service', 'NOT LIKE', '%DROPOFF%') 
                        ->whereRaw('LOWER(service) NOT LIKE ?', ['%dropoff%'])
                        ->where('service', '!=', 'Saver Drop Off') 
                        ->orderBy('price', 'asc')
                        ->first();

                    if ($cheapestRate) {
                        $reset = OrderShippingRate::where('order_id', $order->id)->update(['is_cheapest' => 0]);
                        $updated = OrderShippingRate::where('id', $cheapestRate->id)->update(['is_cheapest' => 1]);
                    }
                    $order->default_rate_id       = $cheapestRate['rate_id'] ?? null;
                    $order->default_carrier       = $cheapestRate['carrier'] ?? null;
                    $order->default_price         = $cheapestRate['price'] ?? null;
                    $order->default_currency      = $cheapestRate['currency'] ?? null;
                    $order->shipping_rate_fetched = true; 
                    $order->save();

                    $this->info("✅ Order {$order->id} - Default rate saved: {$defaultRate['carrier']} \${$defaultRate['price']}");
                } else {
                    $this->warn("⚠️ Order {$order->id} - No rates found");
                }
            } catch (\Exception $e) {
                Log::error("Failed to fetch rate for order {$order->id}", ['error' => $e->getMessage()]);
                $this->error("❌ Order {$order->id} - Error: " . $e->getMessage());
            }
        }

        $this->info("🎉 Done fetching default rates for this batch.");
    }
}
