<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log, Response, Storage, Validator};
use App\Services\{OrderRateFetcherService, RateService, ShippoService, ShipStationService, ShipmentCancellationService, ShipmentService, SendleService, UPSService, ShippingLabelService};
use App\Models\{Order, OrderItem, OrderShippingRate, SalesChannel, Shipment, ShippingService};
class CancellationReportController extends Controller
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

             $marketplaces = Order::query()
            ->whereIn('marketplace', [
                'ebay1', 'ebay2', 'ebay3', 'shopify', 'walmart',
                'reverb', 'PLS', 'Temu', 'TikTok',
                'Best Buy USA', 'Business 5core', 'Wayfair',"Macy's, Inc.",'amazon'
            ])
            ->pluck('marketplace')
            ->unique()
            ->values();
            $carrierName = Order::whereNotNull('shipping_carrier')
                                ->whereNotIn('shipping_carrier', ['', 'UPS®', 'Shipped'])
                                ->distinct()
                                ->pluck('shipping_carrier');
         return view('admin.report.cancellationreport', compact('services','salesChannels','marketplaces','carrierName'));
    }
    public function getData(Request $request)
{
    $fromDate = $request->input('from_date') ?? now()->toDateString();
    $toDate   = $request->input('to_date') ?? now()->toDateString();

    // 🧩 Subquery: order items summary
    $orderItems = DB::table('order_items')
        ->select(
            'order_id',
            DB::raw("MAX(sku) as item_sku"),
            DB::raw("SUM(weight) as total_weight"),
            DB::raw("SUM(length) as total_length"),
            DB::raw("SUM(width) as total_width"),
            DB::raw("SUM(height) as total_height")
        )
        ->groupBy('order_id');
    $query = DB::table('orders as o')
        ->join('shipments as s', function ($join) {
            $join->on('s.order_id', '=', 'o.id')
                 ->where('s.label_status', '=', 'voided');
        })
        ->leftJoin('shipments as s2', function ($join) {
            $join->on('s2.order_id', '=', 'o.id')
                 ->where('s2.label_status', '=', 'active')
                 ->whereColumn('s2.created_at', '>', 's.created_at');
        })
        ->leftJoin('users as u', 'u.id', '=', 's.cancelled_by')
        ->leftJoin('users as created_by_user', 'created_by_user.id', '=', 's.created_by')
        ->joinSub($orderItems, 'oi', function ($join) {
            $join->on('oi.order_id', '=', 'o.id');
        })
        ->select(
            'o.id',
            'o.marketplace',
            'o.order_number',
            'o.order_date',
            'o.payment_status',
            'o.fulfillment_status',
            'o.recipient_name',
            'o.print_count',
            'o.order_total',
            'o.quantity',
            's.tracking_number',
            's.carrier',
            's.service_type',
            's.updated_at',
            's.cancelled_reason',
            's.cost as shipping_cost',
            's.currency as shipping_currency',
            's.label_url',
            's.label_id',
            's.tracking_url',
            'oi.item_sku',
            'oi.total_weight',
            'oi.total_length',
            'oi.total_width',
            'oi.total_height',
            DB::raw("CASE WHEN s2.id IS NOT NULL THEN 1 ELSE 0 END as recreated_label"),
            'u.name as cancelled_by_name','created_by_user.name as created_by_name'
        );

    // 📅 (optional) date range filter
    // $query->whereBetween(DB::raw('DATE(s.updated_at)'), [$fromDate, $toDate]);

    // 🔍 Search filter
    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('o.order_number', 'like', "%{$search}%")
              ->orWhere('oi.item_sku', 'like', "%{$search}%")
              ->orWhere('s.tracking_number', 'like', "%{$search}%");
        });
    }

    // 🏷️ Marketplace filter
    if (!empty($request->marketplace)) {
        $query->where('o.marketplace', $request->marketplace);
    }

    // 🔢 Sorting
    $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];
    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName  = $request->columns[$orderColumnIndex]['data'];
        $orderDir         = $request->order[0]['dir'] ?? 'asc';
        if (in_array($orderColumnName, $allowedOrderColumns)) {
            $query->orderBy($orderColumnName, $orderDir);
        }
    } else {
        $query->orderBy('o.updated_at', 'desc');
    }

    // 📄 Pagination
    $start  = $request->start ?? 0;
    $length = $request->length ?? 10;

    $totalRecords = $query->count();
    $orders = $query->skip($start)->take($length)->get();

    // 📤 Response
    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}

//     public function getData(Request $request)
// {
//     $fromDate = $request->input('from_date') ?? now()->toDateString();
//     $toDate   = $request->input('to_date') ?? now()->toDateString();

//     // Subquery: order items summary
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

//     // Main query
//     $query = DB::table('orders as o')
//         ->join('shipments as s', function ($join) {
//             $join->on('s.order_id', '=', 'o.id')
//                 ->where('s.label_status', '=', 'voided');
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
//              DB::raw("CASE WHEN s2.id IS NOT NULL THEN 1 ELSE 0 END as recreated_label")
//         );
//         // ->whereBetween(DB::raw('DATE(s.updated_at)'), [$fromDate, $toDate]);

//     // Searching
//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('o.order_number', 'like', "%{$search}%")
//               ->orWhere('oi.item_sku', 'like', "%{$search}%");
//         });
//     }

//     // Marketplace filter
//     if (!empty($request->marketplace)) {
//         $query->where('o.marketplace', $request->marketplace);
//     }

//     // Sorting
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

//     // Pagination
//     $start  = $request->start ?? 0;
//     $length = $request->length ?? 10;

//     $totalRecords = $query->count();
//     $orders = $query->skip($start)->take($length)->get();

//     return response()->json([
//         'draw' => intval($request->draw),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }

    // public function getData(Request $request)
    // {
    //     $fromDate = $request->input('from_date') ?? now()->toDateString();
    //     $toDate   = $request->input('to_date') ?? now()->toDateString();
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

    //     $query = DB::table('orders as o')
    //         ->join('shipments as s', function ($join) {
    //             $join->on('s.order_id', '=', 'o.id')
    //                  ->where('s.label_status', '=', 'voided');
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
    //             's.cost as shipping_cost',
    //             's.currency as shipping_currency',
    //             's.label_url',
    //             's.label_id',
    //             's.tracking_url',
    //             'oi.item_sku',
    //             'oi.total_weight',
    //             'oi.total_length',
    //             'oi.total_width',
    //             'oi.total_height'
    //         );
    //         // ->whereBetween(DB::raw('DATE(s.updated_at)'), [$fromDate, $toDate]);

    //     if (!empty($request->search['value'])) {
    //         $search = $request->search['value'];
    //         $query->where(function ($q) use ($search) {
    //             $q->where('o.order_number', 'like', "%{$search}%")
    //               ->orWhere('oi.item_sku', 'like', "%{$search}%");
    //         });
    //     }

    //     if (!empty($request->marketplace)) {
    //         $query->where('o.marketplace', $request->marketplace);
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
    //         $query->orderBy('o.updated_at', 'desc');
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
}
