<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\SalesChannel;
use App\Models\Shipment;
use Illuminate\Http\Request; 
use App\Services\RateService;
use App\Services\ShipmentService;
use Illuminate\Support\Facades\DB;
use App\Models\ShippingService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\log;
use setasign\Fpdi\Fpdi;
use App\Services\ShipmentCancellationService;
use App\Services\UPSService;
use Illuminate\Support\Facades\Storage;

class ShipOrderBackupController extends Controller
{
     public function __construct(ShipmentCancellationService $cancellationService)
    {
        $this->cancellationService = $cancellationService;
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

        $marketplaces = Order::distinct()->pluck('marketplace');

        $carrierName = Order::whereNotNull('shipping_carrier')
                            ->whereNotIn('shipping_carrier', ['', 'UPS®', 'Shipped'])
                            ->distinct()
                            ->pluck('shipping_carrier');

        return view('admin.orders.shipped_backup', compact('services','salesChannels','marketplaces','carrierName'));
    }
public function getShippedOrders(Request $request)
{
    // $query = Order::query()
    //     ->select(
    //         'orders.*',
    //         'order_items.sku',
    //         'order_items.product_name'
    //     )
    //     ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
    //     ->whereIn('orders.order_status', ['shipped']);
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
    ->whereIn('orders.order_status', ['shipped']);

    if (!empty($request->marketplace)) {
        $query->where('orders.marketplace', $request->marketplace);
    }

    if (!empty($request->from_date)) {
        $query->whereDate('orders.created_at', '>=', $request->from_date);
    }

    if (!empty($request->to_date)) {
        $query->whereDate('orders.created_at', '<=', $request->to_date);
    }

    if (!empty($request->status)) {
        $query->where('orders.order_status', $request->status);
    }
    // if (!empty($request->shipping_carrier)) {
    //     $query->where('orders.shipping_carrier', $request->shipping_carrier);
    // }
    if (!empty($request->shipping_carrier)) {
    $carrier = strtolower($request->shipping_carrier);

        if ($carrier === 'ups') {
            $query->whereIn('orders.shipping_carrier', ['UPS', 'UPS®']);
        } else {
            $query->where('orders.shipping_carrier', $request->shipping_carrier);
        }
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

        public function getRate(Request $request)
        {
            try {
                $carrier = $request->input('carrier', 'FedEx');
                $userId = auth()->id();
                $rateService = new RateService($carrier, $userId);

                // Multiple orders
                $selectedOrders = $request->input('selectedOrderValues', []); // array of orders
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
                        'shipper_street'   => $order->shipper_address ?? '123 Main Street',
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

        try {
            foreach ($validated['order_ids'] as $orderId) {
                $order = Order::findOrFail($orderId);
                $shipmentService = new ShipmentService('fedex', auth()->id());

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

                DB::transaction(function () use ($order, $shipmentData, $validated, $shipmentService) {
                    $result = $shipmentService->createShipment($shipmentData);

                    Shipment::create([
                        'order_id'          => $order->id,
                        'carrier'           => 'fedex',
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
                        'label_data'        => json_encode($result), 
                        'ship_date'         => now(),
                        'cost'              => $result['cost'] ?? null,
                        'currency'          => $result['currency'] ?? 'USD',
                    ]);
                });
            }

            return response()->json([
                'status'  => 'success',
                'message' => 'Labels generated successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('Shipment creation failed', ['message' => $e->getMessage()]);
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
public function cancelShipments(Request $request)
{
    $validator = Validator::make($request->all(), [
        'order_ids'   => 'required|array|min:1',
        'order_ids.*' => 'exists:orders,id',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid order IDs provided.',
            'errors'  => $validator->errors()
        ], 422);
    }

    $orderIds     = $request->input('order_ids');
    $successCount = 0;
    $errors       = [];

    try {
        $orders = Order::with('shipment')->whereIn('id', $orderIds)->get();

        foreach ($orders as $order) {
            if (empty($order->tracking_number)) {
                $errors[] = "Order #{$order->order_number} is not cancellable.";
                continue;
            }

            $cancelResult = $this->cancelShipmentWithCarrier($order);

            if ($cancelResult['success']) {
                // ✅ Update order
                $order->order_status     = 'unshipped';   // revert back
                $order->label_status     = 'voided';     // label voided
                $order->tracking_number  = null;
                $order->shipping_carrier = null;
                $order->save();

                // ✅ Update shipment if exists
                if ($order->shipment) {
                    $order->shipment->label_status    = 'voided';
                    $order->shipment->tracking_number = null;
                    $order->shipment->save();
                }

                $successCount++;
            } else {
                $errors[] = "Order #{$order->order_number}: {$cancelResult['message']}";
            }
        }

        if ($successCount === count($orderIds)) {
            return response()->json([
                'success' => true,
                'message' => $successCount > 1
                    ? "{$successCount} shipments cancelled successfully."
                    : 'Shipment cancelled successfully.'
            ], 200);
        } elseif ($successCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "Partially cancelled: {$successCount} of " . count($orderIds) . " shipments cancelled.",
                'errors'  => $errors
            ], 207); 
        } else {
            return response()->json([
                'success' => false,
                'message' => 'No shipments were cancelled.',
                'errors'  => $errors
            ], 400);
        }
    } catch (\Exception $e) {
        Log::error('Shipment cancellation failed: ' . $e->getMessage(), [
            'order_ids' => $orderIds,
            'exception' => $e
        ]);

        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred while cancelling shipments.'
        ], 500);
    }
}

    // public function cancelShipmentWithCarrier(Order $order): array
    // {
    //     if($order->shipping_carrier=="ups")
    //     {
    //          return $this->UPSService->cancelShipment($order->tracking_number, $order->shipping_carrier);
    //     }
    //     else
    //     {
    //          return $this->cancellationService->cancelShipment($order->tracking_number, $order->shipping_carrier);
    //     }
       

    // }
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
    public function printLabels(Request $request)
    {
        $validated = $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
        ]);

        $orderIds = $validated['order_ids'];
        $labelUrls = [];

        try {
            // Fetch orders with their shipment label URLs
            $shipments = Shipment::whereIn('order_id', $orderIds)
                ->join('orders', 'shipments.order_id', '=', 'orders.id')
                ->whereNotNull('shipments.label_url')
                ->where('orders.order_status', 'shipped')
                ->select('shipments.label_url')
                ->get();

            if ($shipments->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid orders with labels found.'
                ], 404);
            }

            // Collect label URLs
            foreach ($shipments as $shipment) {
                $filePath = str_replace(asset('storage/'), '', $shipment->label_url);
                if (Storage::disk('public')->exists($filePath)) {
                    $labelUrls[] = $shipment->label_url;
                } else {
                    Log::warning("Label file missing for shipment", ['label_url' => $shipment->label_url, 'order_id' => $shipment->order_id]);
                }
            }

            if (empty($labelUrls)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid label files found for the selected orders.'
                ], 404);
            }

            // Handle single vs. multiple labels
            if (count($labelUrls) === 1) {
                // Single order: return the label URL directly
                return response()->json([
                    'success' => true,
                    'label_urls' => [$labelUrls[0]],
                    'message' => 'Label retrieved successfully.'
                ]);
            } else {
                // Multiple orders: merge PDFs
                $pdf = new Fpdi();

                foreach ($labelUrls as $url) {
                    $filePath = storage_path('app/public/' . str_replace(asset('storage/'), '', $url));
                    if (!file_exists($filePath)) {
                        Log::warning("Label file not found: {$filePath}");
                        continue;
                    }

                    $pageCount = $pdf->setSourceFile($filePath);
                    for ($page = 1; $page <= $pageCount; $page++) {
                        $tplIdx = $pdf->importPage($page);
                        $size = $pdf->getTemplateSize($tplIdx);
                        $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $pdf->useTemplate($tplIdx);
                    }
                }

                if ($pdf->PageNo() === 0) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to merge labels: no valid pages found.'
                    ], 500);
                }

                $mergedName = 'labels/merged_' . time() . '.pdf';
                $mergedPath = storage_path('app/public/' . $mergedName);
                $pdf->Output($mergedPath, 'F');

                return response()->json([
                    'success' => true,
                    'label_urls' => [asset('storage/' . $mergedName)],
                    'message' => 'Labels merged and retrieved successfully.'
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Label reprint failed', ['message' => $e->getMessage(), 'order_ids' => $orderIds]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to reprint labels: ' . $e->getMessage()
            ], 500);
        }
    }
}
