<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Order, OrderItem, OrderShippingRate, SalesChannel, Shipment, ShippingService};
use App\Services\{OrderRateFetcherService, RateService, ShippoService, ShipStationService, ShipmentCancellationService, ShipmentService, SendleService, UPSService, ShippingLabelService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Http, Log, Response, Storage, Validator};
use setasign\Fpdi\Fpdi;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
class ShipOrderController extends Controller
{
    protected ShipmentCancellationService $cancellationService;
    protected OrderRateFetcherService $orderRateFetcherService;

    public function __construct(ShipmentCancellationService $cancellationService,OrderRateFetcherService $orderRateFetcherService) {
        $this->cancellationService     = $cancellationService;
        $this->orderRateFetcherService = $orderRateFetcherService;
    }
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

        return view('admin.orders.shipped', compact('services','salesChannels','marketplaces','carrierName'));
}
public function bulkMarkShipped(Request $request)
{
    DB::beginTransaction();

    try {
        $orderIds = $request->input('order_ids', []);
        $isUnmark = $request->has('unmark') && $request->unmark;
        $markedAsShipped = $isUnmark ? 0 : 1;

        if (empty($orderIds)) {
            return response()->json([
                'success' => false,
                'message' => 'No orders selected.'
            ], 400);
        }

        // Update all selected orders
        Order::whereIn('id', $orderIds)->update([
            'marked_as_ship' => $markedAsShipped
        ]);

        DB::commit();

        return response()->json([
            'success' => true,
            'message' => $markedAsShipped
                ? 'Selected orders marked as shipped successfully!'
                : 'Selected orders unmarked as shipped successfully!',
            'marked_as_ship' => $markedAsShipped
        ]);
    } catch (\Exception $e) {
        DB::rollBack();
        return response()->json([
            'success' => false,
            'message' => 'Failed to update shipment status: ' . $e->getMessage()
        ], 500);
    }
}

public function markAsShipped(Request $request, $id)
    {
        $request->validate([
            'unmark' => 'sometimes|boolean'
        ]);

        try {
            DB::beginTransaction();

            $order = Order::findOrFail($id);
            $isMarked = $request->has('unmark') && $request->unmark;
            $markedAsShipped = $isMarked ? 0 : 1;

            $order->update([
                'marked_as_ship' => $markedAsShipped
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $markedAsShipped ? 'Order marked as shipped successfully!' : 'Order unmarked as shipped successfully!',
                'marked_as_ship' => $markedAsShipped
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Failed to update shipment status: ' . $e->getMessage()
            ], 500);
        }
    }
public function getShippedOrders(Request $request)
{
    $toDate   = $request->input('to_date') ?? now()->toDateString();
    $fromDate = $request->input('from_date') ?? now()->subWeek()->toDateString();

    // Aggregate order items: sum of weight, length, width, height
    $orderItems = DB::table('order_items')
        ->select(
            'order_id',
            DB::raw("
                CASE 
                    WHEN COUNT(DISTINCT sku) = 1 THEN 
                        CONCAT(MAX(sku), '-', SUM(quantity_ordered), 'pcs')
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
        ->where('o.printing_status', 2)
        ->whereBetween(DB::raw('DATE(s.updated_at)'), [$fromDate, $toDate]);

    if (!empty($request->search['value'])) {
        $search = $request->search['value'];
        $query->where(function ($q) use ($search) {
            $q->where('o.order_number', 'like', "%{$search}%")
              ->orWhere('oi.item_sku', 'like', "%{$search}%");
        });
    }

     if (!empty($request->marketplace)) {
        if (is_array($request->marketplace)) {
            $query->whereIn('o.marketplace', $request->marketplace);
        } else {
            $query->where('o.marketplace', $request->marketplace);
        }
    }
    if (!empty($request->shipping_carrier)) {
        $query->where('s.carrier', $request->shipping_carrier);
    }
    if (!empty($request->order_number)) {
        $orderNumbers = array_filter(array_map('trim', explode(',', $request->order_number)));
        if (!empty($orderNumbers)) {
            $query->whereIn('o.order_number', $orderNumbers);
        }
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
        $query->orderBy('o.updated_at', 'desc');
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

        public function getRate(Request $request)
        {
            try {
                $carrier = $request->input('carrier', 'FedEx');
                $userId = auth()->id();
                $rateService = new RateService($carrier, $userId);

                // Multiple orders
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
                        'shipper_name'     => $order->shipper_name ?? 'Your Warehouse Name',
                        'shipper_phone'    => $order->shipper_phone ?? '0000000000',
                        'shipper_company'  => $order->shipper_company ?? 'Warehouse Inc',
                        'shipper_street'   => $order->shipper_street ?? '123 Main Street',
                        'shipper_city'     => $order->shipper_city ?? 'Los Angeles',
                        'shipper_state'    => $order->shipper_state ?? 'CA',
                        'shipper_postal'   => $order->shipper_postal ?? '90001',
                        'shipper_country'  => $order->shipper_country ?? 'US',
                        'recipient_name'   => $request->input('to_name', 'Mike Hall'),
                        'recipient_phone'  => $request->input('to_phone', '+1 207-835-4259'),
                        'recipient_company'=> $request->input('to_company', ''),
                        'recipient_street' => $request->input('to_address', '4165 HOLBERT AVE'),
                        'recipient_city'   => $request->input('to_city', 'DRAPER'),
                        'recipient_state'  => $request->input('to_state', 'VA'),
                        'recipient_postal' => $request->input('to_postal', '24324-2813'),
                        'recipient_country'=> $request->input('to_country', 'US'),
                        'residential'      => $request->input('residential', true),
                        'weight'           => $request->input('weight', 20),
                        'weight_unit'      => $request->input('weight_unit', 'LB'),
                        'length'           => $request->input('length', 4),
                        'width'            => $request->input('width', 7),
                        'height'           => $request->input('height', 4),
                        'dimension_unit'   => $request->input('dimension_unit', 'IN'),

                        'service_type'     => $request->input('service_code', 'FEDEX_GROUND'),
                        'packaging_type'   => $request->input('packaging_type', 'YOUR_PACKAGING'),
                        'pickup_type'      => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                    ];

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
                            'message' => 'Rate not available'
                        ];
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

        // Determine carrier from service code
        $service = ShippingService::where('service_code', $validated['service_code'])
            ->where('active', 1)
            ->first();
        
        if (!$service) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Invalid service code',
            ], 422);
        }

        $carrier = strtolower($service->carrier_name);
        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($validated['order_ids'] as $orderId) {
            try {
                $order = Order::findOrFail($orderId);
                
                // Check if shipment already exists
                $existingShipment = Shipment::where('order_id', $orderId)
                    ->where('label_status', 'active')
                    ->first();
                
                if ($existingShipment) {
                    $results[] = [
                        'order_id' => $orderId,
                        'order_number' => $order->order_number ?? $orderId,
                        'status' => 'skipped',
                        'message' => 'Active shipment already exists for this order',
                    ];
                    continue;
                }

                $shipmentService = new ShipmentService($carrier, auth()->id());

                $shipmentData = [
                    'shipper_name'      => $order->shipper_name ?? 'Default Shipper',
                    'shipper_phone'     => $order->shipper_phone ?? '1234567890',
                    'shipper_company'   => $order->shipper_company ?? 'My Company',
                    'shipper_street'    => $order->shipper_street ?? '123 Main Street',
                    'shipper_city'      => $order->shipper_city ?? 'Los Angeles',
                    'shipper_state'     => $order->shipper_state ?? 'CA',
                    'shipper_postal'    => $order->shipper_postal ?? '90001',
                    'shipper_country'   => $order->shipper_country ?? 'US',

                    'recipient_name'    => $order->recipient_name ?? 'Default Recipient',
                    'recipient_phone'   => $order->recipient_phone ?? '9876543210',
                    'recipient_company' => $order->recipient_company ?? 'Customer',
                    'recipient_street'  => trim(($order->ship_address1 ?? '') . ' ' . ($order->ship_address2 ?? '')) ?: 'Unknown Street',
                    'recipient_city'    => $order->ship_city ?? 'New York',
                    'recipient_state'   => $order->ship_state ?? 'NY',
                    'recipient_postal'  => $order->ship_postal_code ?? '10001',
                    'recipient_country' => $order->ship_country ?? 'US',

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
                    'label_stock'       => 'PAPER_7X475',
                    'pickup_type'       => $request->input('pickup_type', 'DROPOFF_AT_FEDEX_LOCATION'),
                ];

                DB::transaction(function () use ($order, $shipmentData, $validated, $shipmentService, $carrier) {
                    $result = $shipmentService->createShipment($shipmentData);

                    Shipment::create([
                        'order_id'          => $order->id,
                        'carrier'           => $carrier,
                        'service_type'      => $validated['service_code'],
                        'package_weight'    => $shipmentData['weight'],
                        'package_dimensions'=> json_encode([
                            'length' => $validated['length'],
                            'width'  => $validated['width'],
                            'height' => $validated['height'],
                        ]),
                        'tracking_number'   => $result['tracking_number'] ?? null,
                        'label_url'         => $result['label'] ?? null,
                        'shipment_status'   => 'generated',
                        'label_status'      => 'active',
                        'label_data'        => json_encode($result), 
                        'ship_date'         => now(),
                        'cost'              => $result['cost'] ?? null,
                        'currency'          => $result['currency'] ?? 'USD',
                    ]);
                });

                $successCount++;
                $results[] = [
                    'order_id' => $orderId,
                    'order_number' => $order->order_number ?? $orderId,
                    'status' => 'success',
                    'message' => 'Label generated successfully',
                ];

            } catch (\Exception $e) {
                $failedCount++;
                \Log::error("Shipment creation failed for order {$orderId}", [
                    'order_id' => $orderId,
                    'carrier' => $carrier,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Update order to mark label status as failed (but don't change order_status)
                try {
                    $order = Order::find($orderId);
                    if ($order) {
                        $order->update([
                            'label_status' => 'failed',
                            'printing_status' => 0,
                            // Preserve original order_status - don't change it
                        ]);
                    }
                } catch (\Exception $updateException) {
                    \Log::error("Failed to update order status for order {$orderId}", [
                        'error' => $updateException->getMessage(),
                    ]);
                }

                $results[] = [
                    'order_id' => $orderId,
                    'order_number' => $order->order_number ?? $orderId,
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'error' => 'Shipment creation failed: ' . $e->getMessage(),
                ];
            }
        }

        // Return summary response
        $response = [
            'status' => $failedCount === 0 ? 'success' : ($successCount > 0 ? 'partial' : 'error'),
            'message' => $failedCount === 0 
                ? 'All labels generated successfully' 
                : "Processed {$successCount} orders successfully, {$failedCount} failed",
            'summary' => [
                'total' => count($validated['order_ids']),
                'success' => $successCount,
                'failed' => $failedCount,
            ],
            'results' => $results,
        ];

        $statusCode = $failedCount === 0 ? 200 : ($successCount > 0 ? 207 : 500); // 207 = Multi-Status

        return response()->json($response, $statusCode);
}
public function cancelShipments(Request $request)
{
    $request->validate([
        'order_ids'   => 'required|array|min:1',
        'order_ids.*' => 'exists:orders,id',
        'label_ids'   => 'required|array|min:1',
        'label_ids.*' => 'string|nullable',
        'reason'      => 'required|string|max:1000'
    ]);

    $orderIds = $request->input('order_ids');
    $reason = trim($request->input('reason'));

    // Filter out empty label IDs
    $labelIds = collect($request->input('label_ids'))
        ->filter(fn($id) => !empty($id))
        ->values()
        ->all();

    $shipStation = new ShipStationService();
    $sendleService = new SendleService();
    $shippoService = new ShippoService();

    $query = DB::table('orders as o')
        ->join('shipments as s', 'o.id', '=', 's.order_id')
        ->select(
            'o.id',
            'o.order_number',
            'o.label_id',
            'o.tracking_number',
            's.id as shipment_id',
            'o.default_carrier as carrier',
            's.label_status',
            's.void_status',
            's.tracking_number as shipment_tracking_number'
        )
        ->whereIn('o.id', $orderIds)
        ->whereIn('s.label_id', $labelIds)
        ->where('s.label_status', 'active')
        ->where('s.void_status', 'active');
    Log::info('Cancel Shipment Query', [
        'sql' => $query->toSql(),
        'bindings' => $query->getBindings()
    ]);

    $orders = $query->get();

    foreach ($orders as $order) {
        if (empty($order->label_id)) {
            return response()->json([
                'success' => false,
                'message' => "Order #{$order->order_number} has no label to cancel."
            ], 422);
        }
        $labelId = $order->label_id;
        $carrier = $order->carrier;
       // if (strlen($labelId) > 30) {
       //    $carrier = 'shippo';
       //  }
         Log::info('Cancel Shipment Debug', [
            'order_id'        => $order->id,
            'order_carrier'   => $order->carrier,    
            'label_id'        => $labelId,
            'label_strlen'    => strlen($labelId),
            'carrier_variable'=> $carrier,     
        ]);
        if ($order->carrier === 'Sendle') {
            Log::info("Using SENDLE void API for order {$order->order_number}");
            $response = $sendleService->voidLabel($order->label_id);
        } elseif ($carrier === 'shippo') {
            Log::info("Using SHIPPO void API for order {$order->order_number}");
            $response = $shippoService->voidLabel($order->label_id);
        } else {
            Log::info("Using SHIPSTATION void API for order {$order->order_number}");
            $response = $shipStation->voidLabel($order->label_id);
        }
        // Determine carrier and call proper void API
        // if ($order->carrier === 'Sendle') {
        //     $response = $sendleService->voidLabel($order->label_id);
        // } elseif ($carrier === 'shippo') {
        //     $response = $shippoService->voidLabel($order->label_id);
        // } else {
        //     $response = $shipStation->voidLabel($order->label_id);
        // }

        // Log::info("Void Label Response", [
        //     'order_id' => $order->id,
        //     'label_id' => $order->label_id,
        //     'response' => $response
        // ]);
        // $response = ['success' => true];
        if (!empty($response['success']) && $response['success'] === true) {
            DB::table('orders')->where('id', $order->id)->update([
                'order_status'    => 'unshipped',
                'printing_status' => 0,
                'label_status'    => 'voided',
                'tracking_number' => null,
            ]);

            DB::table('shipments')
                ->where('label_id', $order->label_id)
                ->where('order_id', $order->id)
                ->update([
                    'label_status'    => 'voided',
                    'void_status'     => 'voided',
                    'tracking_number' => null,
                    'cancelled_reason' => $reason,
                    'cancelled_by' => Auth::user()->id ?? 1,
                ]);

            return response()->json([
                'success'  => true,
                'message'  => 'Shipment cancelled successfully.',
                'response' => $response
            ]);
        } else {
            return response()->json([
                'success'  => false,
                'message'  => "Shipment cancellation failed for Order #{$order->order_number}.",
                'response' => $response
            ], 400);
        }
    }
}

private function cancelShipmentWithCarrier(Order $order): array
    {
        if (strtolower($order->shipping_carrier) === "ups") {
            // Instantiate UPSService manually with userId
            $upsService = new UPSService(auth()->id()); // or whichever userId you need
            return $upsService->cancelShipment($order->tracking_number, $order->shipping_carrier);
        } else {
            return $this->cancellationService->cancelShipment($order->tracking_number, $order->shipping_carrier);
        }
}
public function printLabels(Request $request, ShippingLabelService $labelService)
{
    $validated = $request->validate([
        'order_ids' => 'required|array',
        'order_ids.*' => 'exists:orders,id',
    ]);

    $orderIds = $validated['order_ids'];
    $mergedPdfUrl = $labelService->mergeLabelsPdf($orderIds);

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
  public function updateDimensions(Request $request)
    {
        $request->validate([
            'order_id' => 'required|integer|exists:orders,id',
            'field' => 'required|in:height,width,length,weight,sku,quantity,height_d,width_d,length_d,weight_d',
            'value' => 'nullable|numeric|min:0'
        ]);

        try {
            return DB::transaction(function () use ($request) {
                $orderItems = OrderItem::where('order_id', $request->order_id)->get();
                $field = $request->field;
                
                // Use the field directly - separate columns exist for _d fields
                $dbField = $field;
                // Convert empty string to null
                $value = $request->value === '' || $request->value === null ? null : (float) $request->value;
                $itemCount = $orderItems->count();

                if ($itemCount === 0) {
                    throw new \Exception('No order items found for this order.');
                }
                
                // Calculate update value: if multiple items, divide the total value among them
                $updateValue = null;
                if ($value !== null) {
                    $updateValue = $itemCount > 1 ? $value / $itemCount : $value;
                }
                
                // Use update() for more reliable persistence
                foreach ($orderItems as $orderItem) {
                    $orderItem->update([$dbField => $updateValue]);
                }
                
                // Verify the update was saved by refreshing the models
                $orderItems->each(function($item) {
                    $item->refresh();
                });
                
                // Log the update for debugging
                $sumValue = $orderItems->sum($dbField);
                Log::info("Updated dimension", [
                    'order_id' => $request->order_id,
                    'field' => $field,
                    'value' => $value,
                    'update_value' => $updateValue,
                    'item_count' => $itemCount,
                    'updated_items' => $orderItems->pluck('id')->toArray(),
                    'sum_after_update' => $sumValue,
                    'values' => $orderItems->pluck($field)->toArray()
                ]);
                
                // Reset shipping rate flags so rates can be refetched with new dimensions
                Order::where('id', $request->order_id)->update([
                    'shipping_rate_fetched' => 0
                ]);
                OrderShippingRate::where('order_id', $request->order_id)->delete();
                
                // Don't automatically fetch rates - let user trigger it manually or via background job
                // This prevents any potential interference with the dimension update

                // Get the sum of the updated field for verification
                $sumValue = $orderItems->sum($dbField);
                
                return response()->json([
                    'success' => true,
                    'message' => ucfirst($field) . ' updated successfully.',
                    'item_count' => $itemCount,
                    'updated_value' => $value, // The value that was set
                    'sum_value' => $sumValue, // The sum of all items (should match $value)
                    'field' => $field
                ]);
            });
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update ' . $request->field . ': ' . $e->getMessage()
            ], 500);
        }
    }
public function bulkUpdateDimensions(Request $request)
{
    $validator = Validator::make($request->all(), [
        'order_ids'   => 'required|array|min:1',
        'order_ids.*' => 'integer|exists:orders,id',
        'height'      => 'nullable|numeric|min:0',
        'length'      => 'nullable|numeric|min:0',
        'width'       => 'nullable|numeric|min:0',
        'weight'      => 'nullable|numeric|min:0',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed: ' . $validator->errors()->first(),
        ], 422);
    }

    $orderIds = $request->input('order_ids');
    // Convert empty strings to null and ensure numeric values are properly cast
    $fields   = [
        'height' => $request->input('height') === '' || $request->input('height') === null ? null : (float) $request->input('height'),
        'length' => $request->input('length') === '' || $request->input('length') === null ? null : (float) $request->input('length'),
        'width'  => $request->input('width') === '' || $request->input('width') === null ? null : (float) $request->input('width'),
        'weight' => $request->input('weight') === '' || $request->input('weight') === null ? null : (float) $request->input('weight'),
    ];

    // ✅ Update dimensions and reset shipping flags immediately
    foreach ($orderIds as $orderId) {
        $orderItems = \App\Models\OrderItem::where('order_id', $orderId)->get();
        $itemCount  = $orderItems->count();

        if ($itemCount === 0) {
            \Log::warning("Order $orderId has no items, skipping update.");
            continue;
        }

        foreach ($orderItems as $orderItem) {
            $updateData = [];
            foreach ($fields as $field => $value) {
                // Only update if value is provided (not null)
                if ($value !== null) {
                    $updateData[$field] = $itemCount > 1 ? $value / $itemCount : $value;
                }
                // If value is null, don't update that field (leave existing value)
            }
            if (!empty($updateData)) {
                $orderItem->update($updateData);
            }
        }

        // Reset shipping rate flags and remove old shipping rates
        \App\Models\Order::where('id', $orderId)->update(['shipping_rate_fetched' => 0]);
        \App\Models\OrderShippingRate::where('order_id', $orderId)->delete();
    }

    // ✅ Dispatch job to fetch shipping rates only
    \App\Jobs\BulkUpdateDimensionsJob::dispatch($orderIds);

    return response()->json([
        'success' => true,
        'message' => 'Orders updated successfully. Shipping rate fetching has been queued.',
    ]);
}





}
