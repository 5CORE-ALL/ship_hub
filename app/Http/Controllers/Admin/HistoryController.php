<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BulkShippingHistory;
use App\Models\{Order, OrderItem, OrderShippingRate, SalesChannel, Shipment, ShippingService};
use Illuminate\Support\Facades\{DB, Http, Log, Response, Storage, Validator};
class HistoryController extends Controller
{
    /**
     * Show the Bulk Label History page.
     */
    public function bulkLabel()
    {
        return view('admin.history.bulk_label');
    }

    /**
     * Get bulk label history for DataTables.
     */
 public function getOrders1($batchId)
{
    $batch = BulkShippingHistory::find($batchId);

    if (!$batch) {
        return response()->json(['data' => []]);
    }

    // Get success order IDs from the batch
    $orderIds = is_array($batch->success_order_ids)
        ? $batch->success_order_ids
        : json_decode($batch->success_order_ids, true);

    if (empty($orderIds)) {
        return response()->json(['data' => []]);
    }
    // Base query: only include successful orders from this batch
    $baseQuery = Order::query()
        ->join('order_items', 'orders.id', '=', 'order_items.order_id')
        ->leftJoin('order_shipping_rates', function ($join) {
            $join->on('orders.id', '=', 'order_shipping_rates.order_id')
                 ->where('order_shipping_rates.is_cheapest', 1);
        })
        ->leftJoin('shipments', function ($join) {
            $join->on('orders.id', '=', 'shipments.order_id')
                 ->where('shipments.label_status', 'active')
                 ->where('shipments.void_status', 'active');
        })
        ->whereIn('orders.id', $orderIds) // Only success orders
        // ->where('orders.printing_status', 0)
        ->whereNotIn('orders.marketplace', ['walmart-s','ebay-s'])
        ->where(function ($q) {
            $q->whereNotIn('orders.source_name', ['ebay', 'ebay2', 'ebay3'])
              ->orWhereNull('orders.source_name');
        })
        ->whereIn('orders.marketplace', ['ebay1','ebay3','walmart','PLS','shopify','Best Buy USA',"Macy's, Inc.",'Reverb'])
        // ->where('orders.queue',0)
        // ->whereIn('orders.order_status', [
        //     'Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment','Created','Acknowledged','AWAITING_SHIPMENT'
        // ])
        ->where(function($query) {
            $query->whereNotIn('cancel_status', ['CANCELED', 'IN_PROGRESS'])
                  ->orWhereNull('cancel_status');
        });

    // Apply request filters
    if (!empty($request->marketplace)) {
        $baseQuery->where('orders.marketplace', $request->marketplace);
    }
    // if (!empty($request->from_date)) {
    //     $baseQuery->whereDate('orders.created_at', '>=', $request->from_date);
    // }
    // if (!empty($request->to_date)) {
    //     $baseQuery->whereDate('orders.created_at', '<=', $request->to_date);
    // }
    // if (!empty($request->status)) {
    //     $baseQuery->where('orders.order_status', $request->status);
    // }
    // if (!empty($request->weight_range) && $request->weight_range !== 'all') {
    //     if (in_array($request->weight_range, ['0.25', '0.5', '0.75'])) {
    //         $baseQuery->where('order_items.weight', (float) $request->weight_range);
    //     } elseif ($request->weight_range === '20+') {
    //         $baseQuery->where('order_items.weight', '>', 20);
    //     } else {
    //         $range = explode('-', $request->weight_range);
    //         if (count($range) === 2) {
    //             $baseQuery->whereBetween('order_items.weight', [(float)$range[0], (float)$range[1]]);
    //         }
    //     }
    // }
    // if (!empty($request->search['value'])) {
    //     $search = $request->search['value'];
    //     $baseQuery->where(function ($q) use ($search) {
    //         $q->where('orders.order_number', 'like', "%{$search}%")
    //           ->orWhere('order_items.sku', 'like', "%{$search}%")
    //           ->orWhere('order_items.product_name', 'like', "%{$search}%");
    //     });
    // }

    // Count distinct orders
    $totalRecords = $baseQuery->distinct('orders.id')->count('orders.id');

    // Fetch paginated data
    $query = $baseQuery->selectRaw("
            orders.*,
            CASE WHEN COUNT(order_items.id) = 1 THEN MAX(order_items.product_name)
                 ELSE CONCAT(COUNT(order_items.id), ' items') END as product_name,
            CASE WHEN COUNT(order_items.id) = 1 THEN MAX(order_items.sku)
                 ELSE CONCAT(COUNT(order_items.id), ' items') END as sku,
            SUM(order_items.height) as height,
            SUM(order_items.width) as width,
            SUM(order_items.length) as length,
            SUM(order_items.weight) as weight,
            order_shipping_rates.rate_id as default_rate_id,
            order_shipping_rates.rate_id as default_currency,
            order_shipping_rates.service as default_carrier,
            order_shipping_rates.price as default_price,
            order_shipping_rates.source as default_source,
            shipments.cost as shipment_cost
        ")
        ->groupBy(
            'orders.id',
            'order_shipping_rates.rate_id',
            'order_shipping_rates.service',
            'order_shipping_rates.price',
            'order_shipping_rates.source',
            'shipments.cost'
        );

    // Ordering
    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName = $request->columns[$orderColumnIndex]['data'];
        $orderDir = $request->order[0]['dir'];
        $query->orderBy($orderColumnName, $orderDir);
    } else {
        $query->orderBy('orders.created_at', 'desc');
    }

    $orders = $query->skip($request->start ?? 0)
                    ->take($request->length ?? 10)
                    ->get();

    return response()->json([
        'draw' => intval($request->draw ?? 1),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}


public function getOrders(Request $request,$batchId)
{

    $batch = BulkShippingHistory::find($batchId);

    if (!$batch) {
        return response()->json(['data' => []]);
    }

    // Get success order IDs from the batch
    $orderIds = is_array($batch->success_order_ids)
        ? $batch->success_order_ids
        : json_decode($batch->success_order_ids, true);

    if (empty($orderIds)) {
        return response()->json(['data' => []]);
    }
    // Subquery to format SKU with quantity for each order, and calculate totals
    $orderItems = DB::table('order_items')
        ->select(
            'order_id',
            DB::raw("
                CASE 
                    WHEN COUNT(DISTINCT sku) = 1 THEN 
                        CONCAT(
                            MAX(sku), 
                            '-', 
                            SUM(quantity_ordered), 
                            'pcs'
                        )
                    ELSE 
                        CONCAT(COUNT(DISTINCT sku), ' SKUs')
                END AS item_sku
            "),
            DB::raw("SUM(weight) as total_weight"),
            DB::raw("SUM(length) as total_length"),
            DB::raw("SUM(width) as total_width"),
            DB::raw("SUM(height) as total_height")
        )
        ->groupBy('order_id');

    $query = DB::table('orders as o')
        ->join('shipments as s', function ($join) {
            $join->on('s.order_id', '=', 'o.id')
                 ->where('s.label_status', '=', 'active');
        })
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
            's.cost as shipping_cost',
            's.currency as shipping_currency',
            's.label_url',
            's.label_id',
            's.tracking_url',
            'oi.item_sku',
            'oi.total_weight',
            'oi.total_length',
            'oi.total_width',
            'oi.total_height'
        )
        ->whereIn('o.order_status', ['shipped', 'delivered'])
        // ->where('o.printing_status', 2)
        ->whereIn('o.id', $orderIds);
        // ->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);

    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('o.order_number', 'like', "%{$search}%")
              ->orWhere('oi.item_sku', 'like', "%{$search}%");
        });
    }

    if (!empty($request->marketplace)) {
        $query->where('o.marketplace', $request->marketplace);
    }

    $totalRecords = $query->count();
    $allowedOrderColumns = ['order_number','marketplace','order_date','item_sku','tracking_number'];
    if (!empty($request->order)) {
        $orderColumnIndex = $request->order[0]['column'];
        $orderColumnName  = $request->columns[$orderColumnIndex]['data'];
        $orderDir         = $request->order[0]['dir'] ?? 'asc';
        if (in_array($orderColumnName, $allowedOrderColumns)) {
            $query->orderBy($orderColumnName, $orderDir);
        }
    } else {
        $query->orderBy('o.created_at', 'desc');
    }

    $start  = $request->start ?? 0;
    $length = $request->length ?? 10;

    $orders = $query->get();
    // skip($start)
    //                 ->take($length)
    //                 ->get();

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}
public function successHistory($batchId)
{
    $batch = BulkShippingHistory::findOrFail($batchId);

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
            'Best Buy USA', 'Business 5core', 'Wayfair',"Macy's, Inc."
        ])
        ->distinct()
        ->pluck('marketplace');
        $carrierName = Order::whereNotNull('shipping_carrier')
                                ->whereNotIn('shipping_carrier', ['', 'UPS®', 'Shipped'])
                                ->distinct()
                                ->pluck('shipping_carrier');

    return view('admin.history.success_history', [
        'batch' => $batch,
        'batchId'=>$batchId,
        'services' => $services,
        'salesChannels' => $salesChannels,
        'marketplaces' => $marketplaces,
        'carrierName'=>$carrierName
    ]);
}
public function getBatchOrders($batchId)
{
    
    $batch = BulkShippingHistory::find($batchId);

    if (!$batch) {
        return response()->json(['data' => []]);
    }
    $orderIds = is_array($batch->success_order_ids) 
        ? $batch->success_order_ids 
        : json_decode($batch->success_order_ids, true);

    if (empty($orderIds)) {
        return response()->json(['data' => []]);
    }
     $orders = Order::whereIn('id', $orderIds)
        ->select('order_number', 'marketplace')
        ->get();

    return response()->json([
        'data' => $orders
    ]);
}
public function getBatchOrdersfailed($batchId)
{
    
    $batch = BulkShippingHistory::find($batchId);

    if (!$batch) {
        return response()->json(['data' => []]);
    }
    $orderIds = is_array($batch->failed_order_ids) 
        ? $batch->failed_order_ids 
        : json_decode($batch->failed_order_ids, true);

    if (empty($orderIds)) {
        return response()->json(['data' => []]);
    }
    $orders = Order::whereIn('id', $orderIds)
        ->select('order_number', 'marketplace')
        ->get();

    return response()->json([
        'data' => $orders
    ]);
}
    public function getBulkLabelHistory(Request $request)
    {
        $query = \App\Models\BulkShippingHistory::query()
            ->with('user:id,name,email'); 

        // 🔍 Search
        if (!empty($request->search['value'])) {
            $search = $request->search['value'];
            $query->where(function ($q) use ($search) {
                $q->where('id', 'like', "%{$search}%")
                  ->orWhere('order_ids', 'like', "%{$search}%")
                  ->orWhere('providers', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        // 🔽 Ordering
        if (!empty($request->order)) {
            $orderColumnIndex = $request->order[0]['column'];
            $orderColumnName = $request->columns[$orderColumnIndex]['data'];
            $orderDir = $request->order[0]['dir'];
            $query->orderBy($orderColumnName, $orderDir);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $totalRecords = $query->count();

        $bulkLabels = $query
            ->skip($request->start)
            ->take($request->length)
            ->get();

        $data = [];
        foreach ($bulkLabels as $row) {
            // FIX: no need to explode, it's already an array
            $orderIdsArray = $row->order_ids ?? [];

            $data[] = [
                'id'             => $row->id,
                'user'           => $row->user ? $row->user->name : 'N/A',
                'order_ids'      => $row->order_ids, 
                'providers'      => $row->providers,
                'merged_pdf_url' => $row->merged_pdf_url, 
                'order_count'    => $row->processed,
                'success'    =>   $row->success,
                'failed'    =>   $row->failed,
                'created_at'     => $row->created_at ? $row->created_at->toDateTimeString() : null,
                'updated_at'     => $row->updated_at ? $row->updated_at->toDateTimeString() : null,
            ];
        }

        return response()->json([
            'draw' => intval($request->draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data,
        ]);
    }

    /**
     * Sync missing labels to bulk label history
     */
    public function syncMissingLabels(Request $request)
    {
        try {
            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');
            
            $shippingLabelService = app(\App\Services\ShippingLabelService::class);
            $result = $shippingLabelService->syncMissingLabelsToHistory($fromDate, $toDate);
            
            return response()->json([
                'success' => true,
                'message' => 'Missing labels synced successfully',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to sync missing labels', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to sync missing labels: ' . $e->getMessage()
            ], 500);
        }
    }
//  public function getBatchOrders(Request $request, $batchId)
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
//             'oi.item_sku'
//         )
//         ->where('o.order_status', 'shipped')
//         ->where('o.printing_status', 1);
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


}
