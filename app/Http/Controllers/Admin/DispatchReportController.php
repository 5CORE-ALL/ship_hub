<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log, Response, Storage, Validator};
use App\Services\{OrderRateFetcherService, RateService, ShippoService, ShipStationService, ShipmentCancellationService, ShipmentService, SendleService, UPSService, ShippingLabelService};
use App\Models\{Order, OrderItem, OrderShippingRate, SalesChannel, Shipment, ShippingService};
use Illuminate\Support\Facades\Auth;
class DispatchReportController extends Controller
{
    public function index()
     {
            $services = ShippingService::where('active', true)
                                       ->orderBy('carrier_name')
                                       ->get()
                                       ->groupBy('carrier_name');

            $salesChannels = SalesChannel::where('status', 'active')
                                         ->pluck('name')
                                         ->unique()
                                         ->values();
            $marketplaces = getMarketplaces();
            $carrierName = getCarrierNames();
         return view('admin.report.dispatch_report', compact('services','salesChannels','marketplaces','carrierName'));
    }
    public function dispatch(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array|min:1',
        ]);

        try {
            DB::beginTransaction();

            $updated = Order::whereIn('id', $request->order_ids)->update([
                'dispatch_status' => 1,
                'dispatch_date' => now(),
                'dispatch_by' => Auth::user()->id ?? 1,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $updated > 1
                    ? "{$updated} orders dispatched successfully."
                    : "Order dispatched successfully.",
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to dispatch orders: ' . $e->getMessage(),
            ], 500);
        }
    }
// public function getDispatchOrders(Request $request)
// {
//     $fromDate = $request->input('from_date') ?? now()->toDateString();
//     $toDate   = $request->input('to_date') ?? now()->toDateString();
//     $orderItems = DB::table('order_items')
//         ->select(
//             'order_id',
//             DB::raw("CASE WHEN COUNT(id) > 1 THEN SUM(quantity_ordered) ELSE MAX(sku) END AS item_sku")
//         )
//         ->groupBy('order_id');

//     // Main query
//     $query = DB::table('orders as o')
//         ->join('shipments as s', function ($join) {
//             $join->on('s.order_id', '=', 'o.id')
//                 ->where('s.label_status', '=', 'active');
//         })
//         ->joinSub($orderItems, 'oi', function ($join) {
//             $join->on('oi.order_id', '=', 'o.id');
//         })
//         ->leftJoin('users as u', 'u.id', '=', 'o.dispatch_by') 
//         ->select(
//             'o.id',
//             'o.marketplace',
//             'o.order_number',
//             'o.order_date',
//             'o.payment_status',
//             'o.fulfillment_status',
//             'o.recipient_name',
//             'o.order_total',
//             'o.quantity',
//             's.tracking_number',
//             's.carrier',
//             's.service_type',
//             's.cost as shipping_cost',
//             's.currency as shipping_currency',
//             's.label_url',
//             's.label_id',
//             's.tracking_url',
//             'oi.item_sku',
//             'o.dispatch_by',
//             'u.name as dispatched_by_name', 
//             'o.dispatch_date',
//             'o.dispatch_status'
//         )
//         ->where('o.order_status', 'shipped')->where('o.printing_status', 2);
//         // ->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);

//     // Search
//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('o.order_number', 'like', "%{$search}%")
//               ->orWhere('oi.item_sku', 'like', "%{$search}%")
//               ->orWhere('u.name', 'like', "%{$search}%");
//         });
//     }

//     // Marketplace filter
//     if (!empty($request->marketplace)) {
//         $query->where('o.marketplace', $request->marketplace);
//     }

//     // Sorting
//     $totalRecords = $query->count();
//     $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];
//     if (!empty($request->order)) {
//         $orderColumnIndex = $request->order[0]['column'];
//         $orderColumnName  = $request->columns[$orderColumnIndex]['data'];
//         $orderDir         = $request->order[0]['dir'] ?? 'asc';

//         if (in_array($orderColumnName, $allowedOrderColumns)) {
//             $query->orderBy($orderColumnName, $orderDir);
//         }
//     } else {
//         $query->orderBy('o.created_at', 'desc');
//     }

//     // Pagination
//     $start  = $request->start ?? 0;
//     $length = $request->length ?? 10;

//     $orders = $query->skip($start)
//                     ->take($length)
//                     ->get();

//     return response()->json([
//         'draw' => intval($request->draw),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }
// public function getDispatchOrders(Request $request)
// {
//     $fromDate = $request->input('from_date') ?? now()->toDateString();
//     $toDate   = $request->input('to_date') ?? now()->toDateString();
//     $orderItems = DB::table('order_items')
//         ->select(
//             'order_id',
//             DB::raw("CASE WHEN COUNT(id) > 1 THEN SUM(quantity_ordered) ELSE MAX(sku) END AS item_sku")
//         )
//         ->groupBy('order_id');

//     $query = DB::table('orders as o')
//         ->join('shipments as s', function ($join) {
//             $join->on('s.order_id', '=', 'o.id')
//                 ->where('s.label_status', '=', 'active');
//         })
//         ->joinSub($orderItems, 'oi', function ($join) {
//             $join->on('oi.order_id', '=', 'o.id');
//         })
//         ->leftJoin('users as u', 'u.id', '=', 'o.dispatch_by')
//         ->select(
//             'o.id',
//             'o.marketplace',
//             'o.order_number',
//             'o.order_date',
//             'o.payment_status',
//             'o.fulfillment_status',
//             'o.recipient_name',
//             'o.order_total',
//             'o.quantity',
//             's.tracking_number',
//             's.carrier',
//             's.service_type',
//             's.cost as shipping_cost',
//             's.currency as shipping_currency',
//             's.label_url',
//             's.label_id',
//             's.tracking_url',
//             'oi.item_sku',
//             'o.dispatch_by',
//             'u.name as dispatched_by_name',
//             'o.dispatch_date',
//             'o.dispatch_status'
//         )
//         ->where('o.order_status', 'shipped')->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);;

//     // Search
//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('o.order_number', 'like', "%{$search}%")
//               ->orWhere('oi.item_sku', 'like', "%{$search}%")
//               ->orWhere('u.name', 'like', "%{$search}%");
//         });
//     }
//     if (!empty($request->status)) {
//         $query->where('o.dispatch_status', $request->status);
//     }

//     // Filter
//     if (!empty($request->marketplace)) {
//         $query->where('o.marketplace', $request->marketplace);
//     }

//     $totalRecords = $query->count();
//     $query->orderBy('o.dispatch_status', 'asc');

//     // ✅ Safe ordering
//     $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];

//     if (!empty($request->order) && isset($request->order[0]['column']) && isset($request->columns[$request->order[0]['column']]['data'])) {
//         $orderColumnIndex = $request->order[0]['column'];
//         $orderColumnName  = $request->columns[$orderColumnIndex]['data'] ?? null;
//         $orderDir         = $request->order[0]['dir'] ?? 'asc';

//         if ($orderColumnName && in_array($orderColumnName, $allowedOrderColumns)) {
//             $query->orderBy($orderColumnName, $orderDir);
//         }
//     } else {
//         $query->orderBy('o.created_at', 'desc');
//     }

//     // Pagination
//     $start  = intval($request->start ?? 0);
//     $length = intval($request->length ?? 10);

//     $orders = $query->skip($start)
//                     ->take($length)
//                     ->get();

//     return response()->json([
//         'draw' => intval($request->draw ?? 1),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }

public function getDispatchOrders(Request $request)
{
    $filterDate = $request->input('filter_date') ?? now()->toDateString();
    $fromDate = $toDate = $filterDate;

    $orderItems = DB::table('order_items')
        ->select(
            'order_id',
            DB::raw("CASE WHEN COUNT(id) > 1 THEN 'Multiple SKUs' ELSE MAX(sku) END AS item_sku")
        )
        ->groupBy('order_id');

    $query = DB::table('orders as o')
        ->leftJoin('shipments as s', function ($join) {
            $join->on('s.order_id', '=', 'o.id')
                ->where('s.label_status', '=', 'active');
        })
        ->joinSub($orderItems, 'oi', function ($join) {
            $join->on('oi.order_id', '=', 'o.id');
        })
        ->leftJoin('users as u', 'u.id', '=', 'o.dispatch_by')
        ->select(
            'o.id',
            'o.marketplace',
            'o.order_number',
            'o.order_date',
            'o.payment_status',
            'o.fulfillment_status',
            'o.recipient_name',
            'o.order_total',
            'o.quantity',
            's.tracking_number',
            's.carrier',
            's.service_type',
            's.cost as shipping_cost',
            's.currency as shipping_currency',
            's.label_url',
            's.label_id',
            's.tracking_url',
            'oi.item_sku',
            'o.dispatch_by',
            'u.name as dispatched_by_name',
            'o.dispatch_date',
            'o.dispatch_status'
        )
        ->where('o.order_status', 'shipped')
        ->where(function ($q) use ($filterDate) {
            $q->whereNull('o.dispatch_date')
                ->whereDate('o.order_date', $filterDate)
                ->orWhereDate('o.dispatch_date', $filterDate);
        });

    // Base filters (applied to both total and filtered counts)
    if (!empty($request->marketplace)) {
        $query->where('o.marketplace', $request->marketplace);
    }
    if (!empty($request->shipping_carrier)) {
        $query->where('s.carrier', $request->shipping_carrier);
    }
    if (!empty($request->status)) {
        $query->where('o.dispatch_status', $request->status);
    }

    // Records total (after base filters, before search)
    $totalRecords = $query->count();

    // Search (global search)
    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('o.order_number', 'like', "%{$search}%")
              ->orWhere('oi.item_sku', 'like', "%{$search}%")
              ->orWhere('u.name', 'like', "%{$search}%")
              ->orWhere('o.recipient_name', 'like', "%{$search}%");
        });
    }

    // Records filtered (after search)
    $recordsFiltered = $query->count();

    // Ordering
    $query->orderBy('dispatch_status', 'asc'); // Pending (0) first
    $allowedOrderColumns = ['order_number', 'marketplace', 'dispatch_date', 'item_sku', 'tracking_number', 'dispatched_by_name', 'recipient_name', 'quantity'];
    if (!empty($request->order) && isset($request->order[0]['column']) && isset($request->columns[$request->order[0]['column']]['data'])) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName = $request->columns[$orderColumnIndex]['data'] ?? null;
        $orderDir = $request->order[0]['dir'] ?? 'asc';
        if ($orderColumnName && in_array($orderColumnName, $allowedOrderColumns)) {
            $query->orderBy($orderColumnName, $orderDir);
        }
    } else {
        $query->orderBy('o.created_at', 'desc');
    }

    // Pagination
    $start = intval($request->start ?? 0);
    $length = intval($request->length ?? 10);
    $orders = $query->skip($start)
                    ->take($length)
                    ->get();

    return response()->json([
        'draw' => intval($request->draw ?? 1),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $recordsFiltered,
        'data' => $orders,
    ]);
}
//    public function getDispatchOrders(Request $request)
//    {
//     $fromDate = $request->input('from_date') ?? now()->toDateString();
//     $toDate   = $request->input('to_date') ?? now()->toDateString();
//     $orderItems = DB::table('order_items')
//         ->select(
//             'order_id',
//             DB::raw("CASE WHEN COUNT(id) > 1 THEN SUM(quantity_ordered) ELSE MAX(sku) END AS item_sku")
//         )
//         ->groupBy('order_id');
//     $query = DB::table('orders as o')
//         ->join('shipments as s', function ($join) {
//             $join->on('s.order_id', '=', 'o.id')
//                  ->where('s.label_status', '=', 'active');
//         })
//         ->joinSub($orderItems, 'oi', function ($join) {
//             $join->on('oi.order_id', '=', 'o.id');
//         })
//         ->select(
//             'o.id',
//             'o.marketplace',
//             'o.order_number',
//             'o.order_date',
//             'o.payment_status',
//             'o.fulfillment_status',
//             'o.recipient_name',
//             'o.order_total',
//             'o.quantity',
//             's.tracking_number',
//             's.carrier',
//             's.service_type',
//             's.cost as shipping_cost',
//             's.currency as shipping_currency',
//             's.label_url',
//             's.label_id',
//             's.tracking_url',
//             'oi.item_sku',
//             'o.dispatch_by',
//             'o.dispatch_date',
//             'o.dispatch_status'
//         )
//         ->where('o.order_status', 'shipped');
//         // ->where('o.printing_status', 1);
//         // ->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);

//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('o.order_number', 'like', "%{$search}%")
//               ->orWhere('oi.item_sku', 'like', "%{$search}%");
//         });
//     }
//     if (!empty($request->marketplace)) {
//       $query->where('o.marketplace', $request->marketplace);
//     }

//     $totalRecords = $query->count();
//     $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];
//     if (!empty($request->order)) {
//         $orderColumnIndex = $request->order[0]['column'];
//         $orderColumnName  = $request->columns[$orderColumnIndex]['data'];
//         $orderDir         = $request->order[0]['dir'] ?? 'asc';
//         if (in_array($orderColumnName, $allowedOrderColumns)) {
//             $query->orderBy($orderColumnName, $orderDir);
//         }
//     } else {
//         $query->orderBy('o.created_at', 'desc');
//     }
//     $start  = $request->start ?? 0;
//     $length = $request->length ?? 10;

//     $orders = $query->skip($start)
//                     ->take($length)
//                     ->get();

//     return response()->json([
//         'draw' => intval($request->draw),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }
// public function data(Request $request)
// {
//     $fromDate = $request->input('from_date') ?? now()->toDateString();
//     $toDate   = $request->input('to_date') ?? now()->toDateString();

//     // 🧩 Subquery: order items summary
//     $orderItems = DB::table('order_items')
//         ->select(
//             'order_id',
//             DB::raw("MAX(sku) as item_sku"),
//             DB::raw("SUM(weight) as total_weight"),
//             DB::raw("SUM(length) as total_length"),
//             DB::raw("SUM(width) as total_width"),
//             DB::raw("SUM(height) as total_height")
//         )
//         ->groupBy('order_id');

//     // 🧠 Main query: cancelled (voided) labels + check if re-created later
//     $query = DB::table('orders as o')
//         ->join('shipments as s', function ($join) {
//             $join->on('s.order_id', '=', 'o.id')
//                  ->where('s.label_status', '=', 'voided');
//         })
//         ->leftJoin('shipments as s2', function ($join) {
//             $join->on('s2.order_id', '=', 'o.id')
//                  ->where('s2.label_status', '=', 'active')
//                  ->whereColumn('s2.created_at', '>', 's.created_at');
//         })
//         ->joinSub($orderItems, 'oi', function ($join) {
//             $join->on('oi.order_id', '=', 'o.id');
//         })
//         ->select(
//             'o.id',
//             'o.marketplace',
//             'o.order_number',
//             'o.order_date',
//             'o.payment_status',
//             'o.fulfillment_status',
//             'o.recipient_name',
//             'o.print_count',
//             'o.order_total',
//             'o.quantity',
//             's.tracking_number',
//             's.carrier',
//             's.service_type',
//             's.updated_at',
//             's.cancelled_reason',
//             's.cost as shipping_cost',
//             's.currency as shipping_currency',
//             's.label_url',
//             's.label_id',
//             's.tracking_url',
//             'oi.item_sku',
//             'oi.total_weight',
//             'oi.total_length',
//             'oi.total_width',
//             'oi.total_height',
//             DB::raw("CASE WHEN s2.id IS NOT NULL THEN 1 ELSE 0 END as recreated_label")
//         );

//     // 📅 (optional) date range filter
//     // $query->whereBetween(DB::raw('DATE(s.updated_at)'), [$fromDate, $toDate]);

//     // 🔍 Search filter
//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('o.order_number', 'like', "%{$search}%")
//               ->orWhere('oi.item_sku', 'like', "%{$search}%")
//               ->orWhere('s.tracking_number', 'like', "%{$search}%");
//         });
//     }

//     // 🏷️ Marketplace filter
//     if (!empty($request->marketplace)) {
//         $query->where('o.marketplace', $request->marketplace);
//     }

//     // 🔢 Sorting
//     $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];
//     if (!empty($request->order)) {
//         $orderColumnIndex = $request->order[0]['column'];
//         $orderColumnName  = $request->columns[$orderColumnIndex]['data'];
//         $orderDir         = $request->order[0]['dir'] ?? 'asc';
//         if (in_array($orderColumnName, $allowedOrderColumns)) {
//             $query->orderBy($orderColumnName, $orderDir);
//         }
//     } else {
//         $query->orderBy('o.updated_at', 'desc');
//     }

//     // 📄 Pagination
//     $start  = $request->start ?? 0;
//     $length = $request->length ?? 10;

//     $totalRecords = $query->count();
//     $orders = $query->skip($start)->take($length)->get();

//     // 📤 Response
//     return response()->json([
//         'draw' => intval($request->draw),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }
}
