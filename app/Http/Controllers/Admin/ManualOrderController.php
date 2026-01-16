<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\SalesChannel;
use App\Models\Shipper;
use App\Models\Shipment;
use Illuminate\Http\Request; 
use App\Services\RateService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
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
use Illuminate\Support\Facades\Validator;
class ManualOrderController extends Controller
{
        public function store(Request $request)
    {

        return DB::transaction(function () use ($request) {
            $validator = Validator::make($request->all(), [
                'marketplace' => 'required|string|in:ebay,walmart,shopify,reverb,tiktok,temu,manual', // amazon removed: No longer maintaining Amazon orders shipment
                'order_number' => 'required',
                'order_date' => 'required|date',
                'order_total' => 'required|numeric|min:0.01',
                'order_notes' => 'nullable|string',
                'recipient_name' => 'required|string|max:255',
                'recipient_phone' => 'nullable|string|max:20',
                'recipient_email' => 'nullable|email|max:255',
                'ship_company' => 'nullable|string|max:255',
                'ship_address1' => 'required|string|max:255',
                'ship_address2' => 'nullable|string|max:255',
                'ship_city' => 'required|string|max:100',
                'ship_state' => 'required|string|max:100',
                'ship_postal_code' => 'required|string|max:20',
                'ship_country' => 'required|string|max:2',
                'ship_notes' => 'nullable|string',
                'order_items' => 'required|json',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }
            $orderItems = json_decode($request->input('order_items'), true);
            if (empty($orderItems) || !is_array($orderItems)) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one valid order item is required.'
                ], 422);
            }
            foreach ($orderItems as $item) {
                $itemValidator = Validator::make($item, [
                    'product_name' => 'required|string|max:255',
                    'sku' => 'required|string|max:100',
                    'quantity' => 'required|integer|min:1',
                    'price' => 'required|numeric|min:0',
                    'total_price' => 'required|numeric|min:0',
                    'height' => 'nullable|numeric|min:0',
                    'width' => 'nullable|numeric|min:0',
                    'length' => 'nullable|numeric|min:0',
                    'weight' => 'nullable|numeric|min:0',
                    'notes' => 'nullable|string'
                ]);

                if ($itemValidator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid order item data',
                        'errors' => $itemValidator->errors()
                    ], 422);
                }
            }

            // Calculate total from items to verify order_total
            $itemsTotal = array_sum(array_map(function ($item) {
                return $item['total_price'];
            }, $orderItems));

            if (abs($itemsTotal - $request->order_total) > 0.01) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order total does not match the sum of item totals.'
                ], 422);
            }


            $order = Order::create([
                'marketplace' => $request->marketplace,
                'order_number' => $request->order_number,
                'order_date' => $request->order_date,
                'order_total' => $request->order_total,
                'order_notes' => $request->order_notes,
                'recipient_name' => $request->recipient_name,
                'recipient_phone' => $request->recipient_phone,
                'recipient_email' => $request->recipient_email,
                'ship_company' => $request->ship_company,
                'ship_address1' => $request->ship_address1,
                'ship_address2' => $request->ship_address2,
                'ship_city' => $request->ship_city,
                'ship_state' => $request->ship_state,
                'ship_postal_code' => $request->ship_postal_code,
                'ship_country' => $request->ship_country,
                'ship_notes' => $request->ship_notes,
                'order_status' => 'pending',
                'is_manual'=>1,
                'source_name'=>'manual',
                'created_by' => auth()->id(), 
            ]);

            // Create order items
            foreach ($orderItems as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_name' => $item['product_name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'total_price' => $item['total_price'],
                    'height' => $item['height'],
                    'width' => $item['width'],
                    'length' => $item['length'],
                    'weight' => $item['weight'],
                    'notes' => $item['notes'],
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'Order created successfully',
                'redirect_url' => route('manual-orders.index')
            ], 200);
        });
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
        $marketplaces = Order::query()
        ->whereIn('marketplace', [
            'ebay1', 'ebay2', 'ebay3', 'shopify', 'walmart',
            'reverb', 'PLS', 'Temu', 'TikTok',
            'Best Buy USA', 'Business 5core', 'Wayfair'
        ])
        ->pluck('marketplace')
        ->unique()
        ->values();
            $columns = UserColumnVisibility::where('screen_name', 'awaiting_shipment')
            ->orderBy('order_index')
            ->get();
            $canBuyShipping = auth()->user()->can('buy_shipping');
            return view('admin.orders.manual_orders', compact(
                'orders',
                'services',
                'salesChannels',
                'marketplaces',
                'canBuyShipping',
                'columns'
            ));
        }
        public function create()
        {
            return view('admin.orders.manual_orders_create');
        }
    public function manualOrdersData1(Request $request)
    {
        $query = Order::query()
            ->select(
                'orders.*',
                'order_items.product_name'
            )
            ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.marketplace', 'manual'); 

        if (!empty($request->from_date)) {
            $query->whereDate('orders.created_at', '>=', $request->from_date);
        }
        if (!empty($request->to_date)) {
            $query->whereDate('orders.created_at', '<=', $request->to_date);
        }
        if (!empty($request->status)) {
            $query->where('orders.order_status', $request->status);
        }
        if (!empty($request->search['value'])) {
            $search = $request->search['value'];
            $query->where(function ($q) use ($search) {
                $q->where('orders.order_number', 'like', "%{$search}%")
                  ->orWhere('order_items.sku', 'like', "%{$search}%")
                  ->orWhere('order_items.product_name', 'like', "%{$search}%");
            });
        }

        $totalRecords = $query->count();

        if (!empty($request->order)) {
            $orderColumnIndex = $request->order[0]['column'];
            $orderColumnName = $request->columns[$orderColumnIndex]['data'];
            $orderDir = $request->order[0]['dir'];
            $query->orderBy($orderColumnName, $orderDir);
        } else {
            $query->orderBy('orders.created_at', 'desc');
        }

        $orders = $query
            ->skip($request->start)
            ->take($request->length)
            ->get();

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $orders,
        ]);
    }
    public function getManualOrdersData(Request $request)
   {
    $baseQuery = Order::query()
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->leftJoin('order_shipping_rates', function ($join) {
            $join->on('orders.id', '=', 'order_shipping_rates.order_id')
                 ->where('order_shipping_rates.is_cheapest', 1);
        })
        ->where('orders.printing_status', 0)
        ->whereIn('orders.marketplace', ['ebay1', 'ebay2', 'ebay3','manual','walmart','Shopify','Reverb','Temu']) // amazon removed: No longer maintaining Amazon orders shipment
        ->whereIn('orders.order_status', [
            'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','pending','Created'
        ])
        ->where('is_manual',1);
        

    if (!empty($request->marketplace)) {
        $baseQuery->where('orders.marketplace', $request->marketplace);
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

    if (!empty($request->weight_range) && $request->weight_range !== 'all') {
        if (in_array($request->weight_range, ['0.25', '0.5', '0.75'])) {
            $baseQuery->where('order_items.weight', (float) $request->weight_range);
        } elseif ($request->weight_range === '20+') {
            $baseQuery->where('order_items.weight', '>', 20);
        } else {
            $range = explode('-', $request->weight_range);
            if (count($range) === 2) {
                $minWeight = (float) $range[0];
                $maxWeight = (float) $range[1];
                $baseQuery->whereBetween('order_items.weight', [$minWeight, $maxWeight]);
            }
        }
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
            SUM(order_items.height) as height,
            SUM(order_items.width) as width,
            SUM(order_items.length) as length,
            SUM(order_items.weight) as weight,
            order_shipping_rates.rate_id as default_rate_id,
            order_shipping_rates.rate_id as default_currency,
            order_shipping_rates.service as default_carrier,
            order_shipping_rates.price as default_price,
            order_shipping_rates.source as default_source
        ")
        ->groupBy(
            'orders.id',
            'order_shipping_rates.rate_id',
            'order_shipping_rates.service',
            'order_shipping_rates.price',
            'order_shipping_rates.source'
        );

    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName = $request->columns[$orderColumnIndex]['data'];
        $orderDir = $request->order[0]['dir'];
        $query->orderBy($orderColumnName, $orderDir);
    } else {
        $query->orderBy('orders.created_at', 'desc');
    }

    $orders = $query
        ->skip($request->start)
        ->take($request->length)
        ->get();

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}

}
