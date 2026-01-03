<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Order, OrderItem, OrderShippingRate, SalesChannel, Shipment, ShippingService};
use App\Services\{OrderRateFetcherService, RateService, ShippoService, ShipStationService, ShipmentCancellationService, ShipmentService, SendleService, UPSService, ShippingLabelService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log, Response, Storage, Validator};
use setasign\Fpdi\Fpdi;

class PrintOrderController extends Controller
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
            ->distinct()
            ->pluck('marketplace');

            $carrierName = Order::whereNotNull('shipping_carrier')
                                ->whereNotIn('shipping_carrier', ['', 'UPS®', 'Shipped'])
                                ->distinct()
                                ->pluck('shipping_carrier');

            return view('admin.orders.awaiting_print', compact('services','salesChannels','marketplaces','carrierName'));
    }
    public function printLabels(Request $request, ShippingLabelService $labelService)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $orderIds = $validated['order_ids'];
        $mergedPdfUrl = $labelService->mergeLabelsPdf_v2($orderIds);

        if ($mergedPdfUrl) {
            return response()->json([
                'success' => true,
                'label_urls' => [$mergedPdfUrl],
                'message' => 'Labels merged and generated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'label_urls' => [],
            'message' => 'No labels found or failed to merge PDFs'
        ], 404);
    }
// public function printLabelsV2(Request $request, ShippingLabelService $labelService, $type)
// {
//     $validated = $request->validate([
//         'date' => 'required|date', 
//     ]);

//     $date = $validated['date'];

//     // Fetch order IDs from shipments created on the given date
//     $orderIds = \App\Models\Shipment::whereDate('created_at', $date)
//         ->pluck('order_id')
//         ->toArray();

//     if (empty($orderIds)) {
//         return response()->json([
//             'success' => false,
//             'label_urls' => [],
//             'message' => 'No orders found for the specified date'
//         ], 404);
//     }

//     $mergedPdfUrl = $labelService->mergeLabelsPdf_v3($orderIds, $type);

//     if ($mergedPdfUrl) {
//         return response()->json([
//             'success' => true,
//             'label_urls' => [$mergedPdfUrl],
//             'message' => 'Labels merged and generated successfully'
//         ]);
//     }

//     return response()->json([
//         'success' => false,
//         'label_urls' => [],
//         'message' => 'No labels found or failed to merge PDFs'
//     ], 404);
// }
    // public function printLabelsV2(Request $request, ShippingLabelService $labelService,$type)
    // {
    //     $validated = $request->validate([
    //         'order_ids' => 'required|array',
    //         'order_ids.*' => 'exists:orders,id',
    //     ]);

    //     $orderIds = $validated['order_ids'];
    //     $mergedPdfUrl = $labelService->mergeLabelsPdf_v3($orderIds,$type);

    //     if ($mergedPdfUrl) {
    //         return response()->json([
    //             'success' => true,
    //             'label_urls' => [$mergedPdfUrl],
    //             'message' => 'Labels merged and generated successfully'
    //         ]);
    //     }

    //     return response()->json([
    //         'success' => false,
    //         'label_urls' => [],
    //         'message' => 'No labels found or failed to merge PDFs'
    //     ], 404);
    // }
    public function printLabelsV2(Request $request, ShippingLabelService $labelService, string $type)
    {
        $validated = $request->validate([
            'filter_date' => 'required|date',
            'modes'       => 'required|array',
            'modes.*'     => 'in:shiphub,manual',
        ]);

        $filterDate = $validated['filter_date'];
        $modes = $validated['modes'];

        // Call the updated merge function
        $mergedPdfUrl = $labelService->mergeLabelsPdf_v4($type, $modes, $filterDate);

        if ($mergedPdfUrl) {
            return response()->json([
                'success' => true,
                'label_urls' => [$mergedPdfUrl],
                'message' => 'Labels merged and generated successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'label_urls' => [],
            'message' => 'No labels found or failed to merge PDFs'
        ], 404);
    }

    public function printLabelsV1(Request $request, ShippingLabelService $labelService,$type)
    {
        try {
            $validated = $request->validate([
                'order_ids' => 'required|array',
                'order_ids.*' => 'exists:orders,id',
            ]);

            $orderIds = $validated['order_ids'];
            
            if (empty($orderIds)) {
                return response()->json([
                    'success' => false,
                    'label_urls' => [],
                    'message' => 'No orders selected'
                ], 400);
            }

            $mergedPdfUrl = $labelService->mergeLabelsPdf_v3($orderIds, $type);

            if ($mergedPdfUrl) {
                return response()->json([
                    'success' => true,
                    'label_urls' => [$mergedPdfUrl],
                    'message' => 'Labels merged and generated successfully'
                ]);
            }

            return response()->json([
                'success' => false,
                'label_urls' => [],
                'message' => 'No labels found or failed to merge PDFs'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error in printLabelsV1: ' . $e->getMessage(), [
                'order_ids' => $request->input('order_ids'),
                'type' => $type,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'label_urls' => [],
                'message' => $e->getMessage() ?: 'An unexpected error occurred while generating label.'
            ], 500);
        }
    }
//    public function getAwaitingPrintOrders(Request $request)
//   {
//     $latestShipments = \DB::table('shipments as s')
//         ->select('s.*')
//         ->where('s.label_status', 'active')
//         ->whereRaw('s.id = (SELECT s2.id FROM shipments s2 WHERE (s2.order_id = s.order_id OR s2.label_id = s.label_id) AND s2.label_status = "active" ORDER BY s2.created_at DESC LIMIT 1)');

//     $query = Order::select(
//             'orders.*',
//             'order_items.id as item_id',
//             'order_items.sku as item_sku',
//             'order_items.product_name as item_name',
//             'latest_shipments.tracking_number',
//             'latest_shipments.tracking_url',
//             'latest_shipments.carrier',
//             'latest_shipments.service_type',
//             'latest_shipments.cost as shipping_cost',
//             'latest_shipments.currency as shipping_currency',
//             DB::raw("
//                 CASE 
//                     WHEN LOWER(latest_shipments.carrier) = 'sendle' AND latest_shipments.label_url IS NOT NULL
//                     THEN CONCAT(
//                         SUBSTRING_INDEX(latest_shipments.label_url, '://', 1), '://', 
//                         '".env('SENDLE_KEY').":".env('SENDLE_SECRET')."@', 
//                         SUBSTRING_INDEX(latest_shipments.label_url, '://', -1)
//                     )
//                     ELSE latest_shipments.label_url
//                 END AS label_url
//             ")
//         )
//         ->join('order_items', 'order_items.order_id', '=', 'orders.id')
//         ->leftJoinSub($latestShipments, 'latest_shipments', function($join) {
//             $join->on('latest_shipments.order_id', '=', 'orders.id')
//                  ->orOn('latest_shipments.label_id', '=', 'orders.label_id');
//         })
//         ->whereIn('orders.marketplace', ['ebay1', 'ebay2', 'ebay3', 'shopify','walmart','reverb','PLS','Temu','TikTok','Best Buy USA','Business 5core','Wayfair','amazon',"Macy's, Inc."])
//         ->where('orders.label_source','api')
//         ->where('orders.printing_status',1)
//         ->whereIn('orders.order_status', ['shipped']);

//     if (!empty($request->marketplace)) {
//         $query->where('orders.marketplace', $request->marketplace);
//     }

//     if (!empty($request->from_date)) {
//         $query->whereDate('orders.created_at', '>=', $request->from_date);
//     }

//     if (!empty($request->to_date)) {
//         $query->whereDate('orders.created_at', '<=', $request->to_date);
//     }

//     if (!empty($request->status)) {
//         $query->where('orders.order_status', $request->status);
//     }

//     if (!empty($request->shipping_carrier)) {
//         $carrier = strtolower($request->shipping_carrier);
//         if ($carrier === 'ups') {
//             $query->whereIn('orders.shipping_carrier', ['UPS', 'UPS®']);
//         } else {
//             $query->where('orders.shipping_carrier', $request->shipping_carrier);
//         }
//     }

//     if (!empty($request->search['value'])) {
//         $search = $request->search['value'];
//         $query->where(function ($q) use ($search) {
//             $q->where('orders.order_number', 'like', "%{$search}%")
//               ->orWhere('order_items.sku', 'like', "%{$search}%")
//               ->orWhere('order_items.product_name', 'like', "%{$search}%");
//         });
//     }

//     $totalRecords = $query->count();
//     $allowedOrderColumns = array_merge(\Schema::getColumnListing('orders'), ['item_sku','item_name','tracking_number']);
//     if (!empty($request->order)) {
//         $orderColumnIndex = $request->order[0]['column'];
//         $orderColumnName = $request->columns[$orderColumnIndex]['data'];
//         $orderDir = $request->order[0]['dir'] ?? 'asc';

//         if (in_array($orderColumnName, $allowedOrderColumns)) {
//             $query->orderBy($orderColumnName, $orderDir);
//         }
//     } else {
//         $query->orderBy('orders.created_at', 'desc');
//     }

//     $orders = $query
//         ->skip($request->start)
//         ->take($request->length)
//         ->get()
//         ->unique('order_number')
//         ->values();

//     return response()->json([
//         'draw' => intval($request->draw),
//         'recordsTotal' => $totalRecords,
//         'recordsFiltered' => $totalRecords,
//         'data' => $orders,
//     ]);
// }

public function getAwaitingPrintOrders(Request $request)
{
    $fromDate = $request->input('from_date') ?? now()->toDateString();
    $toDate   = $request->input('to_date') ?? now()->toDateString();
    
    // Subquery to format SKU with quantity for each order
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
            ")
        )
        ->groupBy('order_id');
    
    $query = DB::table('orders as o')
        ->join('shipments as s', function ($join) {
            $join->on('s.order_id', '=', 'o.id')
                 ->where('s.label_status', '=', 'active');
        })
        ->leftJoinSub($orderItems, 'oi', function ($join) {
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
            DB::raw('COALESCE(oi.item_sku, "N/A") as item_sku')
        )
        ->whereRaw('LOWER(o.order_status) = ?', ['shipped'])
        ->where('o.printing_status', 1);
        // ->whereBetween(DB::raw('DATE(s.created_at)'), [$fromDate, $toDate]);

    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('o.order_number', 'like', "%{$search}%")
              ->orWhereRaw('COALESCE(oi.item_sku, "N/A") LIKE ?', ["%{$search}%"]);
        });
    }
    if (!empty($request->marketplace)) {
      $query->whereRaw('LOWER(o.marketplace) = ?', [strtolower($request->marketplace)]);
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

    $orders = $query->skip($start)
                    ->take($length)
                    ->get();

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
    ]);
}

    public function updateItems(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:order_items,id',
            'items.*.sku' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                foreach ($request->items as $item) {
                    $orderItem = OrderItem::findOrFail($item['id']);
                    if ($orderItem->order_id != $request->order_id) {
                        throw new \Exception('Item does not belong to this order.');
                    }
                    $orderItem->sku = $item['sku'];
                    $orderItem->quantity_ordered = $item['quantity'];
                    $orderItem->save();
                }
                return response()->json([
                    'success' => true,
                    'message' => 'Order items updated successfully.'
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update items: ' . $e->getMessage()
            ], 500);
        }
    }

    public function bulkMarkAsPrinted(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'required|integer|exists:orders,id',
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $orderIds = $request->order_ids;
                $updatedCount = Order::whereIn('id', $orderIds)
                    ->where('printing_status', 1) // Only update orders that are awaiting print
                    ->update([
                        'printing_status' => 2 // Mark as printed
                    ]);

                if ($updatedCount === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'No orders were updated. Make sure the selected orders are in "awaiting print" status.'
                    ], 400);
                }

                return response()->json([
                    'success' => true,
                    'message' => "{$updatedCount} order" . ($updatedCount > 1 ? 's' : '') . " marked as printed successfully!",
                    'updated_count' => $updatedCount
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error in bulkMarkAsPrinted: ' . $e->getMessage(), [
                'order_ids' => $request->order_ids,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to mark orders as printed: ' . $e->getMessage()
            ], 500);
        }
    }
}
