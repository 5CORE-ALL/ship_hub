<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\DimensionData;
use App\Models\SalesChannel;
use App\Models\Shipper;
use App\Models\Shipment;
use Illuminate\Http\Request; 
use App\Services\RateService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use App\Models\ShippingService;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\EbayOrderService;
use App\Services\ShipStationService;
use App\Services\SendleService;
use App\Services\ShopifyService;
use App\Repositories\FulfillmentRepository;
use App\Models\OrderShippingRate;
use App\Models\UserColumnVisibility;
use App\Models\DailyOverdueCount;
use App\Models\ShopifySku;
use Carbon\Carbon;



class AwaitingShipmentOrderBackupController extends Controller
{
    protected FulfillmentRepository $fulfillmentRepo;
    public function __construct(FulfillmentRepository $fulfillmentRepo)
    {
        $this->fulfillmentRepo = $fulfillmentRepo;
    }
    public function getCarriers(Request $request)
    {
        try {
            $orderId = $request->input('order_id');
            
            if (!$orderId) {
                return response()->json([
                    'success' => false,
                    'rates' => [],
                    'message' => 'Order ID is required'
                ], 400);
            }

            $rates = OrderShippingRate::where('order_id', $orderId)
                ->orderBy('price', 'asc')
                ->where('service', '!=', 'USPS Media Mail') 
                ->where('service', '!=', 'Saver Drop Off') 
                ->whereRaw('LOWER(service) NOT LIKE ?', ['%dropoff%']) 
                ->get(['id','carrier', 'service', 'price','source','is_cheapest']);

            return response()->json([
                'success' => true,
                'rates' => $rates
            ]);
        } catch (\Exception $e) {
            Log::error('Error in getCarriers: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);
            
            return response()->json([
                'success' => false,
                'rates' => [],
                'message' => 'Error loading carriers: ' . $e->getMessage()
            ], 500);
        }
    }
    public function index()
    {
        $orders = Order::whereIn('order_status', ['Unshipped', 'PartiallyShipped', 'Accepted'])
            ->orderBy('created_at', 'desc')
            ->get();
        $services = ShippingService::where('active', true)
            ->orderBy('carrier_name')
            ->get()
            ->groupBy('carrier_name');
        $salesChannels = SalesChannel::where('status', 'active')
            ->pluck('name')
            ->unique()
            ->values();
       $marketplaces = getMarketplaces();
        $columns = UserColumnVisibility::where('screen_name', 'awaiting_shipment')
        ->orderBy('order_index')
        ->get();
        $canBuyShipping = auth()->user()->can('buy_shipping');
        
        // Count pending shipments using the same base query logic as getAwaitingShipmentOrders
        $pendingCount = Order::query()
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.printing_status', 0)
            ->whereNotIn('orders.marketplace', ['walmart-s','ebay-s'])
            ->where(function ($q) {
                $q->whereNotIn('orders.source_name', ['ebay', 'ebay2', 'ebay3','shopify_draft_order'])
                  ->orWhereNull('orders.source_name');
            })
            ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','aliexpress','tiktok','amazon'])
            ->where('orders.queue',0)
            ->where('marked_as_ship',0)
            ->whereIn('orders.order_status', [
                'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','Created','Acknowledged','AWAITING_SHIPMENT','paid'
            ])
            ->where(function($query) {
                $query->whereNotIn('cancel_status', ['CANCELED', 'IN_PROGRESS'])
                  ->orWhereNull('cancel_status');
            })
            ->distinct('orders.id')
            ->count('orders.id');
        
        // Calculate overdue orders count (orders that were placed before 3:30 PM Ohio time today)
        // Use order_date (when order was placed) not created_at (when synced to database)
        // Use the same filters as pendingCount/getAwaitingShipmentOrders for consistency
        $ohioTimezone = 'America/New_York';
        $todayCutoff = Carbon::today($ohioTimezone)->setTime(15, 30, 0); // Today at 3:30 PM Ohio time
        
        // Get all orders matching the filters, then filter by date in PHP to handle timezone correctly
        // This ensures accurate comparison regardless of how order_date is stored in the database
        $baseQuery = Order::query()
            ->join('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.printing_status', 0)
            ->whereNotIn('orders.marketplace', ['walmart-s','ebay-s'])
            ->where(function ($q) {
                $q->whereNotIn('orders.source_name', ['ebay', 'ebay2', 'ebay3','shopify_draft_order'])
                  ->orWhereNull('orders.source_name');
            })
            ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','aliexpress','tiktok','amazon'])
            ->where('orders.queue',0)
            ->where('marked_as_ship',0)
            ->whereIn('orders.order_status', [
                'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','Created','Acknowledged','AWAITING_SHIPMENT','paid'
            ])
            ->where(function($query) {
                $query->whereNotIn('cancel_status', ['CANCELED', 'IN_PROGRESS'])
                  ->orWhereNull('cancel_status');
            })
            ->whereNotNull('orders.order_date')
            ->select('orders.id', 'orders.order_date')
            ->distinct('orders.id');
        
        // Get orders and filter by date in PHP to handle timezone conversion correctly
        $orders = $baseQuery->get();
        $overdueCount = $orders->filter(function($order) use ($todayCutoff, $ohioTimezone) {
            if (!$order->order_date) {
                return false;
            }
            // Convert order_date to Ohio timezone for comparison
            $orderDateOhio = Carbon::parse($order->order_date)->setTimezone($ohioTimezone);
            // Only count if order was placed before 3:30 PM Ohio time today
            return $orderDateOhio->isBefore($todayCutoff);
        })->count();

        // Record daily overdue count
        $today = Carbon::today($ohioTimezone);
        DailyOverdueCount::updateOrCreate(
            ['record_date' => $today],
            ['overdue_count' => $overdueCount]
        );
        
        return view('admin.orders.awaiting-shipment_backup', compact(
            'orders',
            'services',
            'salesChannels',
            'marketplaces',
            'canBuyShipping',
            'columns',
            'pendingCount',
            'overdueCount'
        ));
    }

    public function getOverdueCountHistory(Request $request)
    {
        $days = $request->input('days', 30); // Default to last 30 days
        $ohioTimezone = 'America/New_York';
        $startDate = Carbon::today($ohioTimezone)->subDays($days);
        
        $history = DailyOverdueCount::where('record_date', '>=', $startDate)
            ->orderBy('record_date', 'asc')
            ->get()
            ->map(function ($record) {
                return [
                    'date' => $record->record_date->format('Y-m-d'),
                    'count' => $record->overdue_count,
                ];
            });

        return response()->json($history);
    }

 public function getShippingOptions(Request $request)
 {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'length' => 'nullable|numeric',
            'width' => 'nullable|numeric',
            'height' => 'nullable|numeric',
            'weight' => 'nullable|numeric',
            'ship_from' => 'nullable|integer|exists:shippers,id',
        ]);
        $order = Order::findOrFail($request->order_id);
        $shipper = null;
        if ($request->has('ship_from')) {
            $shipper = Shipper::find($request->ship_from);
        }
        $length = $request->input('length', $order->cost->length ?? 4.0);
        $width = $request->input('width', $order->cost->width ?? 4.0);
        $height = $request->input('height', $order->cost->height ?? 7.0);
        $weight = $request->input('weight', $order->cost->wt_act ?? 0.25);
        $platform = $request->input('platform', 'shipstation');

        try {
            $options = [];
            if ($platform === 'shipstation') {
            $shipStation = new ShipStationService();
            $params = [
                'ship_to_name' => $order->recipient_name,
                'ship_to_address' => $order->ship_address1,
                'ship_to_address2' => $order->ship_address2 ?? '',
                'ship_to_city' => $order->ship_city,
                'ship_to_state' => $order->ship_state,
                'ship_to_zip' => $order->ship_postal_code,
                'ship_to_country' => $order->ship_country,
                'weight_value' => $weight,
                'weight_unit' => 'pound',
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'dim_unit' => 'inch',
                'order_id'=>$request->order_id
            ];

            if ($shipper) {
                $params['ship_from_name'] = $shipper->name ?? 'Jane Smith';
                $params['ship_from_phone'] = $shipper->shipper_number ?? '1111111111';
                $params['ship_from_address'] = $shipper->address ?? '456 Origin Rd.';
                $params['ship_from_city'] = $shipper->city ?? 'Austin';
                $params['ship_from_state'] = $shipper->state ?? 'TX';
                $params['ship_from_zip'] = $shipper->postal_code ?? '78701';
                $params['ship_from_country'] = $shipper->country ?? 'US';
                $params['ship_from_residential'] = $request->input('ship_from_residential', 'no');
            }
            $ratesResponse = $shipStation->getRates($params);
            $uspsGroundAdvantage = null;
            $cheapestRates = [];
            if (isset($ratesResponse['rate_response']['rates']) && count($ratesResponse['rate_response']['rates'])) {
                foreach ($ratesResponse['rate_response']['rates'] as $rate) {
                    $carrier = $rate['carrier_code'] ?? 'unknown';
                    $serviceCode = $rate['service_code'] ?? 'unknown';
                    $amount = ($rate['shipping_amount']['amount'] ?? 0) + ($rate['other_amount']['amount'] ?? 0);
                    if ($carrier === 'usps') {
                        if ($serviceCode === 'usps_ground_advantage') {
                            $uspsGroundAdvantage = [
                                'rate_id' => $rate['rate_id'] ?? 'unknown',
                                'id' => $serviceCode,
                                'name' => $rate['service_type'] ?? 'USPS Ground Advantage',
                                'estimated_time' => $rate['delivery_days'] ?? 'N/A',
                                'description' => $rate['rate_details'][0]['carrier_description'] ?? 'Shipping',
                                'price' => $amount,
                                'currency' => $rate['shipping_amount']['currency'] ?? 'USD',
                                'length' => $length,
                                'width' => $width,
                                'height' => $height,
                                'weight' => $weight,
                                'carrier' => $rate['carrier_friendly_name'] ?? 'USPS',
                            ];
                        }
                        continue;
                    }
                    if (!isset($cheapestRates[$carrier]) || $amount < ($cheapestRates[$carrier]['shipping_amount']['amount'] + ($cheapestRates[$carrier]['other_amount']['amount'] ?? 0))) {
                        $cheapestRates[$carrier] = $rate;
                    }
                }
                if ($uspsGroundAdvantage) {
                    $options[] = $uspsGroundAdvantage;
                }
                foreach ($cheapestRates as $carrier => $rate) {
                    $serviceCode = $rate['service_code'] ?? $rate['carrier_code'] ?? 'unknown';
                    $amount = ($rate['shipping_amount']['amount'] ?? 0) + ($rate['other_amount']['amount'] ?? 0);
                    $options[] = [
                        'rate_id' => $rate['rate_id'] ?? 'unknown',
                        'id' => $serviceCode,
                        'name' => $rate['service_type'] ?? $rate['carrier_friendly_name'] ?? 'Unknown Service',
                        'estimated_time' => $rate['delivery_days'] ?? 'N/A',
                        'description' => $rate['rate_details'][0]['carrier_description'] ?? 'Shipping',
                        'price' => $amount,
                        'currency' => $rate['shipping_amount']['currency'] ?? 'USD',
                        'length' => $length,
                        'width' => $width,
                        'height' => $height,
                        'weight' => $weight,
                        'carrier' => $rate['carrier_friendly_name'] ?? $carrier,
                    ];
                }
                if (count($options) > 1) {
                    usort($options, function ($a, $b) {
                        if ($a['id'] === 'usps_ground_advantage') return -1;
                        if ($b['id'] === 'usps_ground_advantage') return 1;
                        return $a['price'] <=> $b['price'];
                    });
                }
            }
            } 
            else
            {
                        $sendleService = new SendleService();
                        $ratesResponse = $sendleService->getRates([
                            'sender_suburb'     => $shipper->city ?? 'New York',
                            'sender_postcode'   => $shipper->postal_code ?? '10001',
                            'sender_country'    => $shipper->country ?? 'US',
                            'receiver_suburb'   => $order->ship_city,
                            'receiver_postcode' => $order->ship_postal_code,
                            'receiver_country'  => $order->ship_country,
                            'weight_value'      => $weight,
                            'length'            => $length,
                            'width'             => $width,
                            'height'            => $height,
                        ]);

                        if (isset($ratesResponse['options']) && count($ratesResponse['options'])) {
                            foreach ($ratesResponse['options'] as $rate) {
                                $options[] = [
                                    'rate_id'        => $rate['rate_id'] ?? 'unknown', 
                                    'id'             => $rate['id'] ?? 'unknown',      
                                    'name'           => $rate['name'] ?? 'Sendle Service',
                                    'estimated_time' => $rate['estimated_time'] ?? 'N/A',
                                    'description'    => $rate['description'] ?? 'Shipping',
                                    'price'          => $rate['price'] ?? 0,
                                    'currency'       => $rate['currency'] ?? 'USD',
                                    'length'         => $length,
                                    'width'          => $width,
                                    'height'         => $height,
                                    'weight'         => $weight,
                                    'carrier'        => $rate['carrier'] ?? 'Sendle',
                                    'platform'       => 'Sendle',
                                ];
                            }
                        }

            }

            return response()->json([
                'success' => true,
                'options' => $options,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
    public function buyShipping(Order $order)
    {
        if ($order->shipped) {
            return redirect()->back()->with('error', 'This order is already shipped.');
        }
       $order->load(['items', 'cost']);
          $shippers = Shipper::all();
        return view('admin.orders.buy-shipping', compact('order','shippers'));
    }
    public function getShippingDetails(Request $request)
    {
           $query = Order::query()
        ->select(
            'orders.*',
            'order_items.sku',
            'order_items.product_name',
            'cost_master.lp',
            'cost_master.cp',
            'cost_master.length',
            'cost_master.width',
            'cost_master.height'
        )
        ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
        ->join('cost_master', 'cost_master.sku', '=', 'orders.item_sku')
        ->where('orders.order_status', 'shipped')
        ->where('orders.id', $orderId)  
        ->first();
        return response()->json($query);
    }
public function buyLabel(Request $request)
{
       $request->validate([
          'order_id' => 'required|exists:orders,id',
          'rate_id'  => 'required|string',
          'shipFrom' => 'nullable|integer|exists:shippers,id',
       ]);
        $shipper = null;
        if ($request->has('shipFrom')) {
            $shipper = Shipper::find($request->shipFrom);
        }

    try {
         $order = Order::findOrFail($request->order_id);
        $length = $request->input('length', $order->cost->length ?? 4.0);
        $width = $request->input('width', $order->cost->width ?? 4.0);
        $height = $request->input('height', $order->cost->height ?? 7.0);
        $weight = $request->input('weight', $order->cost->wt_act ?? 0.25);
        $platform = $request->input('platform', 'shipstation');
        if ($platform === 'shipstation') {
           $shipStation = new ShipStationService();
           $label = $shipStation->createLabelByRateId($request->rate_id);
          

                 \Log::info("ShipStation Buy Label Response", [
                    'order_id' => $request->order_id,
                    'rate_id'  => $request->rate_id,
                    'response' => $label,
                ]);
               if (isset($label['success']) && $label['success'] === false ||
                    isset($label['error'])) {
                    $errorMessage = "Label purchase failed.";
                    if (isset($label['error']['errors']) && is_array($label['error']['errors']) && isset($label['error']['errors'][0]['message'])) {
                        $errorMessage = $label['error']['errors'][0]['message'];
                    } elseif (isset($label['error']['message'])) {
                        $errorMessage = $label['error']['message'];
                    } elseif (is_string($label['error'])) {
                        $errorMessage = $label['error'];
                    }

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage
                    ]);
                 }
                 } else {
                          $weightInKg = $weight * 0.453592;
                          $sendlePayload = [
                                    "sender" => [
                                        "contact" => [
                                            "name" => $shipper->name ?? '-',
                                            "email" => $shipper->email ?? '-',
                                            "phone" => $shipper->shipper_number ?? '1111111111',
                                            "company" => $shipper->name ?? '-'
                                        ],
                                        "address" => [
                                            "country" => $shipper->country ?? $order->sender_country ?? 'US',
                                            "address_line1" => $shipper->address ?? '-',
                                            "suburb" => $shipper->city ?? '-',
                                            "postcode" => $shipper->postal_code  ?? '00000',
                                            "state_name" => $shipper->state ?? 'State'
                                        ]
                                    ],
                                    "receiver" => [
                                        "contact" => [
                                            "name" => $order->recipient_name,
                                            "email" => $order->recipient_email ?? 'recipient@example.com',
                                            "phone" => $order->recipient_phone ?? '1111111111',
                                            "company" => $order->recipient_company ?? ''
                                        ],
                                        "address" => [
                                            "country" => $order->ship_country,
                                            "address_line1" => $order->ship_address1,
                                            "address_line2" => $order->ship_address2 ?? '',
                                            "suburb" => $order->ship_city,
                                            "postcode" => $order->ship_postal_code,
                                            "state_name" => $order->ship_state
                                        ],
                                        "instructions" => "N/A"
                                    ],
                                    "weight" => [
                                        "units" => "kg",
                                        "value" => $weightInKg
                                    ],
                                    "dimensions" => [
                                        "units" => "cm",
                                        "length" => $length,
                                        "width" => $width,
                                        "height" => $height
                                    ],
                                    "description" => $order->description ?? 'Goods',
                                    "customer_reference" => $order->item_sku,
                                    "product_code" => $request->rate_id, 
                                    'pickup_date' => now()->addWeekday()->format('Y-m-d'),
                                    "packaging_type" => "box",
                                    "hide_pickup_address" => true,
                                     "labels" => [
                                                    [
                                                        "format" => "pdf",
                                                        "size" => "cropped"
                                                    ]
                                                ]
                                ];
                                $sendleService = new SendleService();
                                $label = $sendleService->createOrder($sendlePayload);
                                 if (!isset($label['success']) || $label['success'] === false) {
                                    return response()->json([
                                        'success' => false,
                                        'message' => $label['error'] ?? 'Sendle label purchase failed.'
                                    ]);
                                }
              }

            $order->update([
                'label_id'         => $label['label_id'] ?? null,
                'tracking_number'  => $label['trackingNumber'] ?? null,
                'label_url'        => $label['labelUrl'] ?? null,
                'shipping_carrier' => $label['raw']['carrier_code'] ?? null, 
                'shipping_service' => $label['raw']['service_code'] ?? null, 
                'shipping_cost'    => $label['raw']['shipment_cost']['amount'] ?? null,
                'ship_date'        => $label['shipDate'] ?? now(),
                'label_status'     => isset($label['label_id']) ? 'purchased' : 'failed',
                'label_source'=>'api',
                'fulfillment_status' => 'shipped',
                'order_status'       => 'Shipped',
            ]);

        $shipment = Shipment::create([
            'order_id'           => $request->order_id,
            'tracking_number'    => $label['trackingNumber'] ?? null,
            'carrier'            => $label['raw']['carrier_code'] ?? null,
            'label_id'           => $label['label_id'] ?? null,
            'service_type'       => $label['raw']['service_code'] ?? null,
            // 'package_weight'     => $label['shipment_cost']['weight'] ?? null,
            // 'package_dimensions' => json_encode($label['package_dimensions'] ?? []),
            'package_weight'     => $label['raw']['packages'][0]['weight']['value'] ?? null,
            'package_dimensions' => json_encode($label['raw']['packages'][0]['dimensions'] ?? []),
            'label_url'          => $label['labelUrl'] ?? null,
            'shipment_status'    => 'created',
            'label_data'         => json_encode($label),
            'ship_date'          => now(),
            'cost'               => $label['raw']['shipment_cost']['amount'] ?? null,
            'currency'           => $label['raw']['shipment_cost']['currency'] ?? null,
            'tracking_url'       => $label['raw']['tracking_url'] ?? null,
            'label_status'        => 'active',
            'void_status'        => 'active',
        ]);


        // After $order->update([...])
                     try {
                         $this->fulfillmentRepo->createFulfillment(
                                $order->marketplace,
                                $order->store_id,
                                $order->order_number,
                                $result['tracking_number'] ?? null
                            );
                        } catch (\Exception $e) {
                            Log::warning("⚠️ Failed to update {$order->marketplace} order fulfillment", [
                                'order_id' => $order->id,
                                'error'    => $e->getMessage(),
                            ]);
                        }

        return response()->json([
            'success'   => true,
            'message'   => 'Label purchased successfully!',
            'label_url' => $shipment->label_url,
        ]);
    } catch (\Exception $e) {
        \Log::error("Buy Label Error: " . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Unexpected error: ' . $e->getMessage()
        ], 500);
    }
}
public function getAwaitingShipmentOrders(Request $request)
{
    $baseQuery = Order::query()
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->leftJoin('order_shipping_rates', function ($join) {
            $join->on('orders.id', '=', 'order_shipping_rates.order_id')
                 ->where('order_shipping_rates.is_cheapest', 1);
        })
        ->leftJoin('dimension_data', 'order_items.sku', '=', 'dimension_data.sku')
        ->where('orders.printing_status', 0)
        ->whereNotIn('orders.marketplace', ['walmart-s','ebay-s'])
        ->where(function ($q) {
            $q->whereNotIn('orders.source_name', ['ebay', 'ebay2', 'ebay3','shopify_draft_order'])
              ->orWhereNull('orders.source_name');
        })
        ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb','aliexpress','tiktok','amazon'])
        ->where('orders.queue',0)
        ->where('marked_as_ship',0)
        ->whereIn('orders.order_status', [
            'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','Created','Acknowledged','AWAITING_SHIPMENT','paid'
        ])
   ->where(function($query) {
        $query->whereNotIn('cancel_status', ['CANCELED', 'IN_PROGRESS'])
          ->orWhereNull('cancel_status');
    });

    // if (!empty($request->marketplace)) {
    //     $baseQuery->where('orders.marketplace', $request->marketplace);
    // }
    if (!empty($request->marketplace)) {
        if (is_array($request->marketplace)) {
            $baseQuery->whereIn('orders.marketplace', $request->marketplace);
        } else {
            $baseQuery->where('orders.marketplace', $request->marketplace);
        }
    }

    if (!empty($request->from_date)) {
        $baseQuery->whereDate('orders.created_at', '>=', $request->from_date);
    }

    if (!empty($request->to_date)) {
        $baseQuery->whereDate('orders.created_at', '<=', $request->to_date);
    }

    if (!empty($request->status)) {
        $baseQuery->where('orders.order_status', $request->status);
    }

    // if (!empty($request->weight_range) && $request->weight_range !== 'all') {
    //     if (in_array($request->weight_range, ['0.25', '0.5', '0.75'])) {
    //         $baseQuery->where('order_items.weight', (float) $request->weight_range);
    //     } elseif ($request->weight_range === '20+') {
    //         $baseQuery->where('order_items.weight', '>', 20);
    //     } else {
    //         $range = explode('-', $request->weight_range);
    //         if (count($range) === 2) {
    //             $minWeight = (float) $range[0];
    //             $maxWeight = (float) $range[1];
    //             $baseQuery->whereBetween('order_items.weight', [$minWeight, $maxWeight]);
    //         }
    //     }
    // }
    $weightRanges = $request->input('weight_ranges', []);

if (!empty($weightRanges) && !in_array('all', $weightRanges)) {
    $baseQuery->where(function ($q) use ($weightRanges) {
        foreach ($weightRanges as $range) {
            if (in_array($range, ['0.25', '0.5', '0.75'])) {
                $q->orWhere('order_items.weight', (float) $range);
            } elseif ($range === '20+') {
                $q->orWhere('order_items.weight', '>', 20);
            } else {
                $rangeParts = explode('-', $range);
                if (count($rangeParts) === 2) {
                    $minWeight = (float) $rangeParts[0];
                    $maxWeight = (float) $rangeParts[1];
                    $q->orWhereBetween('order_items.weight', [$minWeight, $maxWeight]);
                }
            }
        }
    });
}

    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $baseQuery->where(function ($q) use ($search) {
            $q->where('orders.order_number', 'like', "%{$search}%")
              ->orWhere('order_items.sku', 'like', "%{$search}%")
              ->orWhere('order_items.product_name', 'like', "%{$search}%");
        });
    }

    // Count distinct orders ignoring groupBy
    $totalRecords = $baseQuery->distinct('orders.id')->count('orders.id');

    // Apply grouping and selects for fetching paginated data
    $query = $baseQuery
        ->selectRaw("
            orders.*,
            CASE 
                WHEN COUNT(order_items.id) = 1 
                    THEN MAX(order_items.product_name)
                ELSE CONCAT(COUNT(order_items.id), ' items')
            END as product_name,
            CASE 
                WHEN COUNT(order_items.id) = 1 
                    THEN MAX(order_items.sku)
                ELSE CONCAT(COUNT(order_items.id), ' items')
            END as sku,
            SUM(COALESCE(order_items.original_height, order_items.height)) as height,
            SUM(COALESCE(order_items.original_width, order_items.width)) as width,
            SUM(COALESCE(order_items.original_length, order_items.length)) as length,
            SUM(COALESCE(order_items.original_weight, order_items.weight)) as weight,
            SUM(COALESCE(order_items.length_d, dimension_data.l, order_items.original_length, order_items.length)) as length_d,
            SUM(COALESCE(order_items.width_d, dimension_data.w, order_items.original_width, order_items.width)) as width_d,
            SUM(COALESCE(order_items.height_d, dimension_data.h, order_items.original_height, order_items.height)) as height_d,
            SUM(COALESCE(order_items.weight_d, dimension_data.wt_act)) as weight_d,
            NULL as cbm_in,
            MAX(dimension_data.wt_act) as wt_act,
            MAX(order_shipping_rates.rate_id) as default_rate_id,
            MAX(order_shipping_rates.currency) as default_currency,
            MAX(order_shipping_rates.carrier) as default_carrier,
            MAX(order_shipping_rates.service) as default_service,
            MAX(order_shipping_rates.price) as default_price,
            MAX(order_shipping_rates.source) as default_source,
            MAX(orders.shipping_rate_fetched) as shipping_rate_fetched
        ")
        ->groupBy('orders.id');

    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName = $request->columns[$orderColumnIndex]['data'];
        $orderDir = $request->order[0]['dir'];
        $query->orderBy($orderColumnName, $orderDir);
    } else {
        $query->orderBy('orders.created_at', 'desc');
    }

    $ordersArray = [];
    
    try {
        $orders = $query
            ->skip($request->start)
            ->take($request->length)
            ->get();

        // Get order IDs to fetch actual SKUs from order_items
        $orderIds = $orders->pluck('id')->filter()->unique()->values()->toArray();
        
        Log::info('Fetching INV for orders', [
            'order_count' => count($orders),
            'order_ids_count' => count($orderIds),
            'sample_order_ids' => array_slice($orderIds, 0, 5)
        ]);
        
        // Auto-populate D dimensions from DimensionData for order items that are missing them
        if (!empty($orderIds)) {
            try {
                // Get order items that are missing D dimensions
                $orderItemsNeedingDims = OrderItem::whereIn('order_id', $orderIds)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->where(function($query) {
                        $query->whereNull('length_d')
                            ->orWhereNull('width_d')
                            ->orWhereNull('height_d')
                            ->orWhereNull('weight_d');
                    })
                    ->get(['id', 'sku', 'length_d', 'width_d', 'height_d', 'weight_d']);
                
                if ($orderItemsNeedingDims->isNotEmpty()) {
                    // Get unique SKUs that need dimensions
                    $skusNeedingDims = $orderItemsNeedingDims->pluck('sku')->filter()->unique()->values()->toArray();
                    
                    // Fetch DimensionData for these SKUs
                    $dimensionDataMap = DimensionData::whereIn('sku', $skusNeedingDims)
                        ->get(['sku', 'l', 'w', 'h', 'wt_act'])
                        ->keyBy('sku');
                    
                    // Update order items with D dimensions from DimensionData
                    $updatedCount = 0;
                    foreach ($orderItemsNeedingDims as $orderItem) {
                        $dimData = $dimensionDataMap->get($orderItem->sku);
                        if ($dimData) {
                            $updateData = [];
                            if (is_null($orderItem->length_d) && !is_null($dimData->l)) {
                                $updateData['length_d'] = $dimData->l;
                            }
                            if (is_null($orderItem->width_d) && !is_null($dimData->w)) {
                                $updateData['width_d'] = $dimData->w;
                            }
                            if (is_null($orderItem->height_d) && !is_null($dimData->h)) {
                                $updateData['height_d'] = $dimData->h;
                            }
                            if (is_null($orderItem->weight_d) && !is_null($dimData->wt_act)) {
                                $updateData['weight_d'] = $dimData->wt_act;
                            }
                            
                            if (!empty($updateData)) {
                                $orderItem->update($updateData);
                                $updatedCount++;
                            }
                        }
                    }
                    
                    if ($updatedCount > 0) {
                        Log::info('Auto-populated D dimensions from DimensionData', [
                            'updated_items_count' => $updatedCount,
                            'total_items_checked' => $orderItemsNeedingDims->count()
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::warning('Error auto-populating D dimensions from DimensionData', [
                    'error' => $e->getMessage(),
                    'order_ids' => $orderIds
                ]);
            }
        }
        
        // Fetch INV values from shopify_skus table for all SKUs
        $invData = [];
        $orderInvMap = []; // Map order_id to INV value
        
        if (!empty($orderIds)) {
            try {
                // Get all SKUs from order_items for these orders
                $orderItems = DB::table('order_items')
                    ->whereIn('order_id', $orderIds)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->select('order_id', 'sku')
                    ->get();
                
                Log::info('Order items fetched', [
                    'order_items_count' => $orderItems->count(),
                    'sample_items' => $orderItems->take(5)->map(function($item) {
                        return ['order_id' => $item->order_id, 'sku' => $item->sku];
                    })->toArray()
                ]);
                
                // Collect unique SKUs
                $skus = $orderItems->pluck('sku')->filter()->unique()->values()->toArray();
                
                Log::info('SKUs collected', [
                    'skus_count' => count($skus),
                    'sample_skus' => array_slice($skus, 0, 10)
                ]);
                
                if (!empty($skus)) {
                    // Check if invent database connection is available before querying
                    try {
                        // Test the connection first - this will throw an exception if DB doesn't exist
                        DB::connection('invent')->getPdo();
                        
                        // Use DB facade directly to avoid model connection issues
                        $invData = DB::connection('invent')
                            ->table('shopify_skus')
                            ->whereIn('sku', $skus)
                            ->pluck('inv', 'sku')
                            ->toArray();
                        
                        Log::info('INV data fetched from shopify_skus', [
                            'skus_queried' => count($skus),
                            'inv_data_count' => count($invData),
                            'sample_skus' => array_slice($skus, 0, 5),
                            'sample_inv' => array_slice($invData, 0, 5, true)
                        ]);
                        
                        // Map INV values to order IDs (use first item's INV for each order)
                        foreach ($orderItems as $item) {
                            $orderId = $item->order_id;
                            $sku = $item->sku;
                            
                            // Only set if not already set (first item wins)
                            if (!isset($orderInvMap[$orderId]) && isset($invData[$sku])) {
                                $orderInvMap[$orderId] = $invData[$sku];
                            }
                        }
                        
                        Log::info('Order INV mapping completed', [
                            'orders_with_inv' => count($orderInvMap),
                            'sample_mapping' => array_slice($orderInvMap, 0, 10, true)
                        ]);
                    } catch (QueryException $dbException) {
                        // Database connection not available, skip inventory data
                        Log::warning('Invent database connection not available, skipping inventory data', [
                            'error' => $dbException->getMessage(),
                            'order_ids' => $orderIds
                        ]);
                        $invData = [];
                    } catch (\Exception $dbException) {
                        // Catch any other exceptions
                        Log::warning('Error accessing invent database, skipping inventory data', [
                            'error' => $dbException->getMessage(),
                            'order_ids' => $orderIds
                        ]);
                        $invData = [];
                    }
                } else {
                    Log::warning('No SKUs found in order_items', [
                        'order_ids' => $orderIds
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Error fetching INV data: ' . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'order_ids' => $orderIds,
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
                $invData = [];
                $orderInvMap = [];
            }
        }

        // Fetch dim-wt data from invent DB for all SKUs
        $dimWtDataMap = [];
        $orderDimWtMap = [];
        $orderItemsForDimWt = collect();
        
        if (!empty($orderIds)) {
            try {
                // Get all SKUs from order_items for these orders
                $orderItemsForDimWt = DB::table('order_items')
                    ->whereIn('order_id', $orderIds)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->select('order_id', 'sku')
                    ->get();
                
                // Collect unique SKUs
                $skusForDimWt = $orderItemsForDimWt->pluck('sku')->filter()->unique()->values()->toArray();
                
                if (!empty($skusForDimWt)) {
                    try {
                        // Test the invent database connection
                        DB::connection('invent')->getPdo();
                        
                        // Fetch dim-wt data from product_master.Values JSON column
                        $dimWtProducts = DB::connection('invent')
                            ->table('product_master')
                            ->whereIn('sku', $skusForDimWt)
                            ->where('sku', 'NOT LIKE', 'PARENT %')
                            ->select('sku', 'Values')
                            ->get();
                        
                        // Process each product to extract dim-wt data
                        foreach ($dimWtProducts as $product) {
                            $values = $product->Values;
                            if (is_string($values)) {
                                $values = json_decode($values, true);
                            }
                            if (!is_array($values)) {
                                $values = [];
                            }
                            
                            // Map the columns: W ACT -> WT ACT (from wt_act), W DECL -> WT (from wt_decl)
                            $dimWtDataMap[$product->sku] = [
                                'wt_act' => isset($values['wt_act']) ? (float)$values['wt_act'] : null,
                                'wt' => isset($values['wt_decl']) ? (float)$values['wt_decl'] : null,
                                'l' => isset($values['l']) ? (float)$values['l'] : null,
                                'w' => isset($values['w']) ? (float)$values['w'] : null,
                                'h' => isset($values['h']) ? (float)$values['h'] : null,
                            ];
                        }
                        
                        Log::info('Dim-wt data fetched from invent DB', [
                            'skus_queried' => count($skusForDimWt),
                            'dim_wt_data_count' => count($dimWtDataMap),
                        ]);
                    } catch (QueryException $dbException) {
                        Log::warning('Invent database connection not available for dim-wt data', [
                            'error' => $dbException->getMessage(),
                        ]);
                    } catch (\Exception $dbException) {
                        Log::warning('Error accessing invent database for dim-wt data', [
                            'error' => $dbException->getMessage(),
                        ]);
                    }
                }
                
                // Map dim-wt data to orders by SKU
                foreach ($orderItemsForDimWt as $item) {
                    $orderId = $item->order_id;
                    $sku = $item->sku;
                    
                    // Only set if not already set (first item wins)
                    if (!isset($orderDimWtMap[$orderId]) && isset($dimWtDataMap[$sku])) {
                        $orderDimWtMap[$orderId] = $dimWtDataMap[$sku];
                    }
                }
            } catch (\Exception $e) {
                Log::error('Error fetching dim-wt data: ' . $e->getMessage());
            }
        }

        // Convert to array format and add INV value and dim-wt data to each order
        $ordersArray = $orders->map(function ($order) use ($orderInvMap, $orderDimWtMap) {
            // Get order ID first
            $orderId = null;
            if (is_object($order)) {
                $orderId = $order->id ?? null;
            } else {
                $orderId = $order['id'] ?? null;
            }
            
            // Get INV value from map
            $invValue = null;
            if (isset($orderId) && isset($orderInvMap[$orderId])) {
                $invValue = $orderInvMap[$orderId];
            }
            
            // Get dim-wt data from map
            $dimWtData = null;
            if (isset($orderId) && isset($orderDimWtMap[$orderId])) {
                $dimWtData = $orderDimWtMap[$orderId];
            }
            
            // Convert to array using json_decode/encode to preserve all properties
            if (is_object($order)) {
                $orderArray = json_decode(json_encode($order), true);
                // Ensure inv is set
                $orderArray['inv'] = $invValue;
                // Add dim-wt data: WT ACT, WT, L, W, H
                if ($dimWtData) {
                    $orderArray['wt_act'] = $dimWtData['wt_act'] ?? $orderArray['wt_act'] ?? null;
                    $orderArray['wt'] = $dimWtData['wt'] ?? $orderArray['weight'] ?? null;
                    $orderArray['l'] = $dimWtData['l'] ?? $orderArray['length'] ?? null;
                    $orderArray['w'] = $dimWtData['w'] ?? $orderArray['width'] ?? null;
                    $orderArray['h'] = $dimWtData['h'] ?? $orderArray['height'] ?? null;
                }
                return $orderArray;
            } else {
                // Already an array
                $order['inv'] = $invValue;
                // Add dim-wt data: WT ACT, WT, L, W, H
                if ($dimWtData) {
                    $order['wt_act'] = $dimWtData['wt_act'] ?? $order['wt_act'] ?? null;
                    $order['wt'] = $dimWtData['wt'] ?? $order['weight'] ?? null;
                    $order['l'] = $dimWtData['l'] ?? $order['length'] ?? null;
                    $order['w'] = $dimWtData['w'] ?? $order['width'] ?? null;
                    $order['h'] = $dimWtData['h'] ?? $order['height'] ?? null;
                }
                return $order;
            }
        })->values()->toArray();
        
        // Count orders with INV
        $ordersWithInv = array_filter($ordersArray, function($order) {
            return isset($order['inv']) && $order['inv'] !== null && $order['inv'] !== '';
        });
        
        Log::info('Final orders with INV', [
            'orders_count' => count($ordersArray),
            'orders_with_inv' => count($ordersWithInv),
            'order_inv_map_count' => count($orderInvMap),
            'sample_orders' => !empty($ordersArray) ? array_slice(array_map(function($order) {
                return [
                    'id' => $order['id'] ?? 'NO_ID', 
                    'sku' => $order['sku'] ?? 'NO_SKU', 
                    'inv' => $order['inv'] ?? 'NO_INV'
                ];
            }, $ordersArray), 0, 5) : [],
            'sample_order_ids' => !empty($ordersArray) ? array_slice(array_column($ordersArray, 'id'), 0, 10) : []
        ]);
    } catch (\Exception $e) {
        Log::error('Error in getAwaitingShipmentOrders: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        return response()->json([
            'draw' => intval($request->draw ?? 0),
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => 'An error occurred while fetching orders. Please check the logs.'
        ], 500);
    }

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $ordersArray,
    ]);
}
public function getCarrierNameByServiceCode($serviceCode)
{
    return DB::table('shipping_service')
        ->where('service_code', $serviceCode)
        ->value('carrier_name');
}
public function getRate(Request $request)
{
    try {
        $serviceCode = $request->input('service_code', '03');
        $carrier = $this->getCarrierNameByServiceCode($serviceCode);
        $userId = auth()->id();

        // Init services
        if ($carrier == 'UPS') {
            $upsService = new \App\Services\UPSService($userId);
        } else {
            $rateService = new RateService($carrier, $userId);
        }

        $selectedOrders = $request->input('selectedOrderValues', []);
        if (empty($selectedOrders)) {
            return response()->json([
                'success' => false,
                'message' => 'No orders selected.'
            ]);
        }

        $totalAmount = 0;
        $currency = 'USD';
        $details = [];

        foreach ($selectedOrders as $orderItem) {
            $orderId = $orderItem['order_number'] ?? null;
            if (!$orderId) {
                continue;
            }

            $order = \App\Models\Order::where('order_number', $orderId)->first();
            if (!$order) {
                $details[] = [
                    'order_number' => $orderId,
                    'success' => false,
                    'message' => 'Order not found.'
                ];
                continue;
            }
            $params = [
                'shipper_name'      => $order->shipper_name ?? '5 Core Inc',
                'shipper_phone'     => $order->shipper_phone ?? '9513866372',
                'shipper_company'   => $order->shipper_company ?? '5 Core Inc',
                'shipper_street'    => $order->shipper_street ?? '1221 W Sandusky Ave',
                'shipper_city'      => $order->shipper_city ?? 'Bellefontaine',
                'shipper_state'     => $order->shipper_state ?? 'OH',
                'shipper_postal'    => $order->shipper_postal ?? '43311',
                'shipper_country'   => $order->shipper_country ?? 'US',
                'recipient_name'    => $request->input('to_name', 'Mike Hall'),
                'recipient_company' => $request->input('to_company', ''),
                'recipient_street'  => $request->input('to_address', '4165 HOLBERT AVE'),
                'recipient_city'    => $request->input('to_city', 'DRAPER'),
                'recipient_state'   => $request->input('to_state', 'VA'),
                'recipient_postal'  => $request->input('to_postal', '24324-2813'),
                'recipient_country' => $request->input('to_country', 'US'),
                'recipient_phone'   => $order->recipient_phone ?? '9876543210',

                'weight'           => $request->input('weight', 20),
                'weight_unit'      => $request->input('weight_unit', 'LB'),
                'length'           => (float)$request->input('length', 4),
                'width'            => (float)$request->input('width', 7),
                'height'           => (float)$request->input('height', 4),
                'dimension_unit'   => $request->input('dimension_unit', 'IN'),

                'pickup_type'      => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                'service_type'     => $request->input('service_code', 'FEDEX_GROUND'), // FedEx
                'ups_service'      => $request->input('ups_service', '03'), // UPS
                'packaging_type'   => $request->input('package_type', 'YOUR_PACKAGING'),
            ];

            // UPS Rate
            if (strtolower($carrier) == 'ups') {
                $rateResponse = $upsService->getRate($params);

                if (!empty($rateResponse['success']) && $rateResponse['success'] === true) {
                    $amount = $rateResponse['shipping_charge'] ?? 0;
                    $currency = $rateResponse['currency'] ?? 'USD';

                    $totalAmount += $amount;

                    $details[] = [
                        'order_number' => $orderId,
                        'success' => true,
                        'rate' => $amount,
                        'currency' => $currency
                    ];
                } else {
                    $details[] = [
                        'order_number' => $orderId,
                        'success' => false,
                        'message' => $rateResponse['message'] ?? 'UPS Rate not available'
                    ];
                }
            }
            // FedEx Rate
            else {
                $rates = $rateService->getRate($params);

                $rateDetails = collect($rates['output']['rateReplyDetails'] ?? [])
                    ->firstWhere('serviceType', $params['service_type']);

                if ($rateDetails && !empty($rateDetails['ratedShipmentDetails'])) {
                    $shipmentDetail = $rateDetails['ratedShipmentDetails'][0];
                    $amount = $shipmentDetail['totalNetCharge'] ?? 0;
                    $currency = $shipmentDetail['currency'] ?? 'USD';

                    $totalAmount += $amount;

                    $details[] = [
                        'order_number' => $orderId,
                        'success' => true,
                        'rate' => $amount,
                        'currency' => $currency
                    ];
                } else {
                    $details[] = [
                        'order_number' => $orderId,
                        'success' => false,
                        'message' => 'FedEx Rate not available'
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'rate' => $totalAmount,
            'currency' => $currency,
            'details' => $details
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}
public function createPrintLabels(Request $request)
{
    $validated = $request->validate([
        'order_ids'     => 'required|array',
        'order_ids.*'   => 'exists:orders,id',
        'service_code'  => 'required|string',
        'package_type'  => 'required|string',
        'weight_lb'     => 'required|numeric|min:0',
        'weight_oz'     => 'required|numeric|min:0',
        'length'        => 'required|numeric|min:0',
        'width'         => 'required|numeric|min:0',
        'height'        => 'required|numeric|min:0',
    ]);

    $labelFiles = [];

    try {
        $service = DB::table('shipping_service')
            ->where('service_code', $validated['service_code'])
            ->where('active', 1)
            ->first();

        if (!$service) {
            return response()->json(['status'=>'error','message'=>'Invalid service code'], 422);
        }

        $carrier = strtolower($service->carrier_name);

        $skippedOrders = [];
        $processedOrders = [];

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::findOrFail($orderId);
            
            // Check inventory (INV) - skip orders with INV = 0
            $invValue = null;
            try {
                // Get SKU from order items
                $orderItem = DB::table('order_items')
                    ->where('order_id', $orderId)
                    ->whereNotNull('sku')
                    ->where('sku', '!=', '')
                    ->first();
                
                if ($orderItem && !empty($orderItem->sku)) {
                    // Check if invent database connection is available
                    try {
                        DB::connection('invent')->getPdo();
                        $invValue = DB::connection('invent')
                            ->table('shopify_skus')
                            ->where('sku', $orderItem->sku)
                            ->value('inv');
                    } catch (\Exception $e) {
                        // Database not available, skip inventory check
                        Log::warning('Invent database not available for inventory check', [
                            'order_id' => $orderId
                        ]);
                    }
                }
                
                // Skip order if INV = 0
                if ($invValue !== null && (float)$invValue === 0.0) {
                    $skippedOrders[] = [
                        'order_number' => $order->order_number,
                        'reason' => 'No inventory available (INV = 0)'
                    ];
                    Log::warning('Skipping order with INV = 0', [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number,
                        'sku' => $orderItem->sku ?? 'N/A'
                    ]);
                    continue;
                }
            } catch (\Exception $e) {
                Log::error('Error checking inventory for order', [
                    'order_id' => $orderId,
                    'error' => $e->getMessage()
                ]);
                // Continue processing if inventory check fails
            }

            $street1 = substr(trim($order->ship_address1 ?? ''), 0, 35) ?: 'Unknown Street';
            $street2 = substr(trim($order->ship_address2 ?? ''), 0, 35) ?: null;

            $shipmentData = [
                'shipper_name'      => $order->shipper_name ?? '5 Core Inc',
                'shipper_phone'     => $order->shipper_phone ?? '9513866372',
                'shipper_company'   => $order->shipper_company ?? '5 Core Inc',
                'shipper_street'    => $order->shipper_street ?? '1221 W Sandusky Ave',
                'shipper_city'      => $order->shipper_city ?? 'Bellefontaine',
                'shipper_state'     => $order->shipper_state ?? 'OH',
                'shipper_postal'    => $order->shipper_postal ?? '43311',
                'item_sku'    =>       $order->item_sku,
                'shipper_country'   => (strtolower(trim($order->shipper_country)) === 'united states') ? 'US' : strtoupper(substr($order->shipper_country ?? 'US', 0, 2)),
                'recipient_name'    => $order->recipient_name ?? 'Default Recipient',
                'recipient_phone'   => $order->recipient_phone ?? '9876543210',
                'recipient_company' => $order->recipient_company ?? 'Customer',
                'recipient_street'  => $street1,
                'recipient_street2' => $street2,
                'recipient_city'    => $order->ship_city ?? 'New York',
                'recipient_state'   => $order->ship_state ?? 'NY',
                'recipient_postal'  => $order->ship_postal_code ?? '10001',
                'recipient_country' => (strtolower(trim($order->ship_country)) === 'united states') ? 'US' : strtoupper(substr($order->ship_country ?? 'US', 0, 2)),
                'residential'       => $order->recipient_company ? false : true,
                'service_type'      => $validated['service_code'],
                'packaging_type'    => $validated['package_type'],
                'weight_unit'       => 'LB',
                'weight'            => $validated['weight_lb'] + ($validated['weight_oz'] / 16),
                'length'            => $validated['length'],
                'width'             => $validated['width'],
                'height'            => $validated['height'],
                'dimension_unit'    => 'IN',
                'label_type'        => 'PDF',
                'label_stock'       => 'PAPER_4X6',
                'label_orientation' => 'PORTRAIT',
                'pickup_type'       => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                'customer_references' => [
                    ['type'=>'CUSTOMER_REFERENCE','value'=>$order->sku],
                ],
            ];

            Log::info(strtoupper($carrier) . " Shipment Payload:\n" . json_encode($shipmentData, JSON_PRETTY_PRINT));

            DB::transaction(function () use ($order, $shipmentData, $carrier, &$labelFiles) {
                
                if (strtolower($carrier) == 'ups') {
                    $upsService = new \App\Services\UPSService(auth()->id());
                    $result = $upsService->createShipment($shipmentData);
                } else {
                    $shipmentService = new \App\Services\ShipmentService($carrier, auth()->id());
                    $result = $shipmentService->createShipment($shipmentData);
                }
                $labelUrl = null;
                if (!empty($result['label'])) {
                    $fileName = 'labels/order_' . $order->id . '_' . time() . '.pdf';

                    if (strtolower($carrier) == 'ups') {
                        $labelUrl = $result['label_url'];
                        $relativePath = ltrim(parse_url($labelUrl, PHP_URL_PATH), '/');
                        $relativePath = str_replace('storage/', '', $relativePath); 
                        $labelFiles[] = storage_path('app/public/' . $relativePath);

                    } else {
                        $labelContent = base64_decode($result['label']);
                        Storage::disk('public')->put($fileName, $labelContent);
                        $labelUrl = asset('storage/' . $fileName);
                        $labelFiles[] = storage_path('app/public/' . $fileName);
                    }
                }
                Shipment::create([
                    'order_id'           => $order->id,
                    'carrier'            => $carrier,
                    'service_type'       => $shipmentData['service_type'],
                    'package_weight'     => $shipmentData['weight'],
                    'package_dimensions' => json_encode(['length'=>$shipmentData['length'],'width'=>$shipmentData['width'],'height'=>$shipmentData['height']]),
                    'tracking_number'    => $result['tracking_number'] ?? null,
                    'label_url'          => $labelUrl,
                    'shipment_status'    => 'generated',
                    'label_data'         => json_encode($result),
                    'ship_date'          => now(),
                    'cost'               => $result['cost'] ?? null,
                    'currency'           => $result['currency'] ?? 'USD',
                ]);

                $order->update([
                    'order_status'     => 'shipped',
                    'shipping_carrier' => $carrier,
                    'shipping_service' => $shipmentData['service_type'],
                    'tracking_number'  => $result['tracking_number'] ?? null,
                ]);
                
                $processedOrders[] = $order->order_number;

                // ebay
                if($order->marketplace=="ebay2")
                {
                    try {
                    (new \App\Services\EbayOrderService())
                        ->updateAfterLabelCreate(
                            $order->store_id,
                            $order->order_number,         
                            $result['tracking_number'] ?? null,
                            ucfirst($carrier),
                            'SHIPPED'                     
                        );
                    } catch (\Exception $e) {
                        Log::warning("Failed to update eBay order for {$order->order_number}", [
                            'message' => $e->getMessage()
                        ]);
                    }
                }
                // ebay
            });
        }
        if (count($labelFiles) > 1) {
            // $pdf = new \FPDI();
            $pdf = new Fpdi();
            foreach ($labelFiles as $file) {
                $pageCount = $pdf->setSourceFile($file);
                for ($page = 1; $page <= $pageCount; $page++) {
                    $tplIdx = $pdf->importPage($page);
                    $size = $pdf->getTemplateSize($tplIdx);
                    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                    $pdf->useTemplate($tplIdx);
                }
            }
            $mergedName = 'labels/merged_' . time() . '.pdf';
            $pdf->Output(storage_path('app/public/' . $mergedName), 'F');
            $finalUrl = asset('storage/' . $mergedName);
        } else {
            $finalUrl = asset('storage/' . str_replace(storage_path('app/public/'), '', $labelFiles[0]));
        }

        $message = 'Labels generated successfully';
        if (count($skippedOrders) > 0) {
            $message .= '. ' . count($skippedOrders) . ' order(s) skipped due to no inventory (INV = 0)';
        }
        
        return response()->json([
            'status' => 'success',
            'message' => $message,
            'url' => $finalUrl,
            'processed_count' => count($processedOrders),
            'skipped_count' => count($skippedOrders),
            'skipped_orders' => $skippedOrders
        ]);

    } catch (\Exception $e) {
        Log::error('Shipment creation failed', ['message'=>$e->getMessage()]);
        return response()->json(['status'=>'error','message'=>$e->getMessage()], 500);
    }
}

public function updateAddress(Request $request)
{
    $request->validate([
        'order_id' => 'required|exists:orders,id',
        'recipient_name' => 'required|string',
        'ship_address1' => 'required|string',
        'ship_address2' => 'nullable|string',
        'ship_city' => 'required|string',
        'ship_state' => 'required|string',
        'ship_postal_code' => 'required|string',
        'ship_country' => 'required|string',
    ]);

    $order = Order::findOrFail($request->order_id);

    $order->update([
        'recipient_name' => $request->recipient_name,
        'ship_address1' => $request->ship_address1,
        'ship_address2' => $request->ship_address2,
        'ship_city' => $request->ship_city,
        'ship_state' => $request->ship_state,
        'ship_postal_code' => $request->ship_postal_code,
        'ship_country' => $request->ship_country,
    ]);

    return response()->json([
        'success' => true,
        'address' => $order
    ]);
}
public function save(Request $request)
{
    UserColumnVisibility::updateOrCreate(
        [
            'user_id'     => auth()->id(),
            'screen_name' => $request->screen_name,
            'column_name' => $request->column_name,
        ],
        [
            'is_visible'  => $request->is_visible,
            'order_index' => $request->order_index,
        ]
    );

    return response()->json(['success' => true]);
}
public function getOrderDetails($orderId)
{
    $order = Order::with(['items', 'cheapestRate'])->findOrFail($orderId);

    // Calculate totals for dimensions and weight using original values
    $totals = [
        'height' => $order->items->sum(function($item) {
            return $item->original_height ?? $item->height ?? 0;
        }),
        'width'  => $order->items->sum(function($item) {
            return $item->original_width ?? $item->width ?? 0;
        }),
        'length' => $order->items->sum(function($item) {
            return $item->original_length ?? $item->length ?? 0;
        }),
        'weight' => $order->items->sum(function($item) {
            return $item->original_weight ?? $item->weight ?? 0;
        }),
    ];

    $response = [
        'id' => $order->id,
        'order_number' => $order->order_number,
        'items' => $order->items->map(function ($item) {
            return [
                'id' => $item->id,
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => $item->quantity_ordered ?? 1,
                'height' => $item->original_height ?? $item->height,
                'width' => $item->original_width ?? $item->width,
                'length' => $item->original_length ?? $item->length,
                'weight' => $item->original_weight ?? $item->weight,
            ];
        }),
        'totals' => $totals,
        'default_rate_id' => $order->cheapestRate->rate_id ?? null,
        'default_currency' => $order->cheapestRate->currency ?? null,
        'default_carrier' => $order->cheapestRate->service ?? null,
        'default_price' => $order->cheapestRate->price ?? null,
        'default_source' => $order->cheapestRate->source ?? null,
        'recipient_name' => $order->recipient_name,
        'recipient_phone' => $order->recipient_phone,
        'recipient_email' => $order->recipient_email,
        'ship_address1' => $order->ship_address1,
        'ship_address2' => $order->ship_address2,
        'ship_city' => $order->ship_city,
        'ship_state' => $order->ship_state,
        'ship_postal_code' => $order->ship_postal_code,
        'ship_country' => $order->ship_country,
        'shipping_carrier' => $order->shipping_carrier,
        'shipping_service' => $order->shipping_service,
        'shipping_cost' => $order->shipping_cost,
        'tracking_number' => $order->tracking_number,
        'ship_date' => $order->ship_date,
        'order_status' => $order->order_status,
        'raw_data' => $order->raw_data,
    ];

    return response()->json($response);
}

public function fetchRateO(Request $request)
{
    try {
        $orderId = $request->input('order_id');
        $length = $request->input('length', 0);
        $width = $request->input('width', 0);
        $height = $request->input('height', 0);
        $weight = $request->input('weight', 0);
        $shipToZip = $request->input('ship_to_zip', '');
        $shipToState = $request->input('ship_to_state', '');
        $shipToCity = $request->input('ship_to_city', '');
        $shipToCountry = $request->input('ship_to_country', 'US');
        
        // Validate required fields
        if (empty($orderId) || $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required dimensions or weight'
            ], 400);
        }
        
        // Get order to fetch shipping address details
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
        
        // Get shipper details
        $shipper = Shipper::first();
        if (!$shipper) {
            return response()->json([
                'success' => false,
                'message' => 'Shipper information not configured'
            ], 500);
        }
        
        // Prepare parameters for rate fetching
        $params = [
            'order_id'         => $orderId,
            'ship_to_name'     => $order->recipient_name ?? 'Recipient',
            'ship_to_address'  => $order->ship_address1 ?? '',
            'ship_to_address2' => $order->ship_address2 ?? '',
            'ship_to_city'     => $shipToCity ?: ($order->ship_city ?? 'Los Angeles'),
            'ship_to_state'    => $shipToState ?: ($order->ship_state ?? 'CA'),
            'ship_to_zip'      => $shipToZip ?: ($order->ship_postal_code ?? '90001'),
            'ship_to_country'  => $shipToCountry ?: ($order->ship_country ?? 'US'),
            'ship_to_phone'    => $order->recipient_phone ?? '5551234567',
            'receiver_suburb'  => $shipToCity ?: ($order->ship_city ?? 'Los Angeles'),
            'receiver_postcode'=> $shipToZip ?: ($order->ship_postal_code ?? '90001'),
            'receiver_country' => $shipToCountry ?: ($order->ship_country ?? 'US'),
            'weight_value'     => $weight,
            'weight_unit'      => 'pound',
            'length'           => $length,
            'width'            => $width,
            'height'           => $height,
            'dim_unit'         => 'inch',
            'shipper_postal'   => $shipper->postal_code ?? '90001',
            'recipient_postal' => $shipToZip ?: ($order->ship_postal_code ?? '90001'),
        ];
        
        // Use ShippingRateService to fetch rates
        $shippingRateService = app(\App\Services\ShippingRateService::class);
        $rateResult = $shippingRateService->getDefaultRate($params);
        
        if (!$rateResult['success'] || empty($rateResult['rates'])) {
            return response()->json([
                'success' => false,
                'message' => $rateResult['message'] ?? 'No rates available'
            ], 404);
        }
        
        // Get the cheapest rate (lowest price) - explicitly sort to ensure lowest is first
        $rates = $rateResult['rates'] ?? [];
        usort($rates, function($a, $b) {
            return ($a['price'] ?? 0) <=> ($b['price'] ?? 0);
        });
        
        $cheapestRate = $rates[0] ?? null;
        
        if (!$cheapestRate) {
            return response()->json([
                'success' => false,
                'message' => 'No rates found'
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'rate' => [
                'carrier' => $cheapestRate['carrier'] ?? 'Unknown',
                'service' => $cheapestRate['service'] ?? 'Unknown',
                'price' => $cheapestRate['price'] ?? 0,
                'source' => $cheapestRate['source'] ?? 'Unknown',
                'currency' => $cheapestRate['currency'] ?? 'USD'
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error fetching Best Rate (O): ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error fetching rate: ' . $e->getMessage()
        ], 500);
    }
}

public function fetchRateD(Request $request)
{
    try {
        $orderId = $request->input('order_id');
        $length = $request->input('length', 0);
        $width = $request->input('width', 0);
        $height = $request->input('height', 0);
        $weight = $request->input('weight', 0);
        $shipToZip = $request->input('ship_to_zip', '');
        $shipToState = $request->input('ship_to_state', '');
        $shipToCity = $request->input('ship_to_city', '');
        $shipToCountry = $request->input('ship_to_country', 'US');
        
        // Validate required fields
        if (empty($orderId) || $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Missing required D dimensions or weight (L (D), W (D), H (D), WT (D))'
            ], 400);
        }
        
        // Get order to fetch shipping address details
        $order = Order::find($orderId);
        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found'
            ], 404);
        }
        
        // Get shipper details
        $shipper = Shipper::first();
        if (!$shipper) {
            return response()->json([
                'success' => false,
                'message' => 'Shipper information not configured'
            ], 500);
        }
        
        // Prepare parameters for rate fetching using D dimensions
        $params = [
            'order_id'         => $orderId,
            'ship_to_name'     => $order->recipient_name ?? 'Recipient',
            'ship_to_address'  => $order->ship_address1 ?? '',
            'ship_to_address2' => $order->ship_address2 ?? '',
            'ship_to_city'     => $shipToCity ?: ($order->ship_city ?? 'Los Angeles'),
            'ship_to_state'    => $shipToState ?: ($order->ship_state ?? 'CA'),
            'ship_to_zip'      => $shipToZip ?: ($order->ship_postal_code ?? '90001'),
            'ship_to_country'  => $shipToCountry ?: ($order->ship_country ?? 'US'),
            'ship_to_phone'    => $order->recipient_phone ?? '5551234567',
            'receiver_suburb'  => $shipToCity ?: ($order->ship_city ?? 'Los Angeles'),
            'receiver_postcode'=> $shipToZip ?: ($order->ship_postal_code ?? '90001'),
            'receiver_country' => $shipToCountry ?: ($order->ship_country ?? 'US'),
            'weight_value'     => $weight,  // WT (D)
            'weight_unit'      => 'pound',
            'length'           => $length,  // L (D)
            'width'            => $width,    // W (D)
            'height'           => $height,  // H (D)
            'dim_unit'         => 'inch',
            'shipper_postal'   => $shipper->postal_code ?? '90001',
            'recipient_postal' => $shipToZip ?: ($order->ship_postal_code ?? '90001'),
        ];
        
        // Use ShippingRateService to fetch rates
        $shippingRateService = app(\App\Services\ShippingRateService::class);
        $rateResult = $shippingRateService->getDefaultRate($params);
        
        if (!$rateResult['success'] || empty($rateResult['rates'])) {
            return response()->json([
                'success' => false,
                'message' => $rateResult['message'] ?? 'No rates available'
            ], 404);
        }
        
        // Get the cheapest rate (lowest price) - explicitly sort to ensure lowest is first
        $rates = $rateResult['rates'] ?? [];
        usort($rates, function($a, $b) {
            return ($a['price'] ?? 0) <=> ($b['price'] ?? 0);
        });
        
        $cheapestRate = $rates[0] ?? null;
        
        if (!$cheapestRate) {
            return response()->json([
                'success' => false,
                'message' => 'No rates found'
            ], 404);
        }
        
        // Save all rates to database for Best Rate (D) - similar to OrderRateFetcherService
        $normalizedRates = $rateResult['rates'] ?? [];
        foreach ($normalizedRates as $rate) {
            OrderShippingRate::updateOrCreate(
                [
                    'order_id' => $orderId,
                    'rate_id'  => $rate['rate_id'] ?? null,
                    'service'  => $rate['service'],
                    'carrier'  => $rate['carrier'],
                ],
                [
                    'source'            => $rate['source'],
                    'price'             => $rate['price'],
                    'currency'          => $rate['currency'],
                    'is_cheapest'       => 0,
                ]
            );
        }
        
        // Mark the cheapest rate as is_cheapest and update order
        $dbCheapestRate = OrderShippingRate::where('order_id', $orderId)
            ->where('service', '!=', 'USPS Media Mail') 
            ->where('service', '!=', 'Saver Drop Off')
            ->whereRaw('LOWER(service) NOT LIKE ?', ['%dropoff%'])
            ->orderBy('price', 'asc')
            ->first();
            
        if ($dbCheapestRate) {
            OrderShippingRate::where('order_id', $orderId)->update(['is_cheapest' => 0]);
            $dbCheapestRate->update(['is_cheapest' => 1]);
            
            // Update order with default rate info
            $order->default_rate_id = $dbCheapestRate->rate_id;
            $order->default_carrier = $dbCheapestRate->carrier;
            $order->default_price = $dbCheapestRate->price;
            $order->default_currency = $dbCheapestRate->currency;
            $order->shipping_rate_fetched = true;
            $order->save();
        }
        
        return response()->json([
            'success' => true,
            'rate' => [
                'carrier' => $cheapestRate['carrier'] ?? 'Unknown',
                'service' => $cheapestRate['service'] ?? 'Unknown',
                'price' => $cheapestRate['price'] ?? 0,
                'source' => $cheapestRate['source'] ?? 'Unknown',
                'currency' => $cheapestRate['currency'] ?? 'USD'
            ]
        ]);
        
    } catch (\Exception $e) {
        Log::error('Error fetching Best Rate (D): ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'request' => $request->all()
        ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Error fetching rate: ' . $e->getMessage()
        ], 500);
    }
}

}
