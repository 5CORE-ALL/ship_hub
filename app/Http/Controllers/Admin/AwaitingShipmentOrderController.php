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
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\EbayOrderService;
use Carbon\Carbon;
use App\Models\DailyOverdueCount;

class AwaitingShipmentOrderController extends Controller
{
     public function index()
     {
        $orders = Order::whereIn('order_status', ['Unshipped', 'PartiallyShipped','Accepted'])
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
        $marketplaces = Order::pluck('marketplace')
    ->unique()
    ->values();
    $canBuyShipping = auth()->user()->can('buy shipping'); 
    
    // Calculate overdue orders count (orders that arrived before 3:30 PM Ohio time today)
    $ohioTimezone = 'America/New_York';
    $todayCutoff = Carbon::today($ohioTimezone)->setTime(15, 30, 0); // Today at 3:30 PM Ohio time
    $overdueCount = Order::whereIn('order_status', ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted'])
        ->where('created_at', '<', $todayCutoff->utc())
        ->count();

    // Record daily overdue count
    $today = Carbon::today($ohioTimezone);
    DailyOverdueCount::updateOrCreate(
        ['record_date' => $today],
        ['overdue_count' => $overdueCount]
    );

         return view('admin.orders.awaiting-shipment', compact('orders','services','salesChannels','marketplaces','canBuyShipping','overdueCount'));
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
    public function getAwaitingShipmentOrders(Request $request)
    {
    $query = Order::query()
        ->select(
            'orders.*',
            'order_items.product_name',
            'order_items.original_weight',
            'order_items.weight as item_weight',
            'order_items.quantity_ordered'
        )
        ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
        ->whereIn('orders.order_status', ['Unshipped','unshipped', 'PartiallyShipped','Accepted']);
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

    // Automatically fetch eDesk data for Amazon orders with missing recipient names (limit to first 10 to avoid performance issues)
    $edeskService = config('services.edesk.bearer_token') ? new \App\Services\EDeskService() : null;
    if ($edeskService) {
        $ordersToUpdate = $orders->filter(function ($order) {
            return $order->marketplace === 'amazon' && 
                   (empty($order->recipient_name) || $order->recipient_name === 'null' || $order->recipient_name === 'NULL');
        })->take(10); // Limit to 10 orders per page load to avoid performance issues
        
        foreach ($ordersToUpdate as $order) {
            try {
                $edeskCustomer = $edeskService->getCustomerDetailsByOrderId($order->order_number);
                if ($edeskCustomer) {
                    $updateData = [];
                    if (!empty($edeskCustomer['name'])) {
                        $updateData['recipient_name'] = trim($edeskCustomer['name']);
                    }
                    if (empty($order->ship_address1) && !empty($edeskCustomer['address1'])) {
                        $updateData['ship_address1'] = $edeskCustomer['address1'];
                    }
                    if (empty($order->ship_address2) && !empty($edeskCustomer['address2'])) {
                        $updateData['ship_address2'] = $edeskCustomer['address2'];
                    }
                    if (empty($order->ship_city) && !empty($edeskCustomer['city'])) {
                        $updateData['ship_city'] = $edeskCustomer['city'];
                    }
                    if (empty($order->ship_state) && !empty($edeskCustomer['state'])) {
                        $updateData['ship_state'] = $edeskCustomer['state'];
                    }
                    if (empty($order->ship_postal_code) && !empty($edeskCustomer['postal_code'])) {
                        $updateData['ship_postal_code'] = $edeskCustomer['postal_code'];
                    }
                    if (empty($order->ship_country) && !empty($edeskCustomer['country'])) {
                        $updateData['ship_country'] = $edeskCustomer['country'];
                    }
                    if (empty($order->recipient_phone) && !empty($edeskCustomer['phone'])) {
                        $updateData['recipient_phone'] = $edeskCustomer['phone'];
                    }
                    if (empty($order->recipient_email) && !empty($edeskCustomer['email'])) {
                        $updateData['recipient_email'] = $edeskCustomer['email'];
                    }
                    if (!empty($updateData)) {
                        $order->update($updateData);
                        $order->refresh(); // Refresh to get updated data
                        Log::info('Auto-updated order with eDesk data', [
                            'order_id' => $order->id,
                            'order_number' => $order->order_number,
                            'updated_fields' => array_keys($updateData),
                        ]);
                    }
                }
            } catch (\Exception $e) {
                // Silently fail - don't break the page if eDesk fetch fails
                Log::debug('Auto eDesk fetch failed for order', [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    // Add dimension columns (L, W, H, WT) to each order row
    $orders = $orders->map(function ($order) {
        // Calculate weight for this specific item row
        // Use original_weight if available, otherwise use item_weight
        $actualWeight = $order->original_weight ?? $order->item_weight ?? 0;
        $quantity = $order->quantity_ordered ?? 1;
        $totalWeight = $actualWeight * $quantity;
        
        // Add dimension columns
        $order->length_d = 8;
        $order->width_d = 6;
        $order->height_d = 2;
        $order->weight_d = round($totalWeight, 2);
        
        return $order;
    });

    return response()->json([
        'draw' => intval($request->draw),
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalRecords,
        'data' => $orders,
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

        foreach ($validated['order_ids'] as $orderId) {
            $order = Order::with('items')->findOrFail($orderId);

            $street1 = substr(trim($order->ship_address1 ?? ''), 0, 35) ?: 'Unknown Street';
            $street2 = substr(trim($order->ship_address2 ?? ''), 0, 35) ?: null;

            // Calculate dimension values (L (D), W (D), H (D), WT (D)) from order items
            $lengthD = 8;
            $widthD = 6;
            $heightD = 2;
            $weightD = 0;
            
            // Calculate weight_d: actual_weight * quantity for each item
            foreach ($order->items as $item) {
                $actualWeight = $item->original_weight ?? $item->weight ?? 0;
                $quantity = $item->quantity_ordered ?? 1;
                $weightD += ($actualWeight * $quantity);
            }
            
            // Convert weight to pounds based on weight_unit
            // Check weight_unit from first item if available, otherwise assume ounces
            $weightUnit = $order->items->first()->weight_unit ?? 'oz';
            if (strtolower($weightUnit) === 'oz' || strtolower($weightUnit) === 'ounce') {
                $weightInPounds = $weightD / 16; // Convert ounces to pounds
            } elseif (strtolower($weightUnit) === 'lb' || strtolower($weightUnit) === 'pound') {
                $weightInPounds = $weightD; // Already in pounds
            } else {
                // Default assumption: ounces (common in shipping)
                $weightInPounds = $weightD / 16;
            }
            
            // Fallback to form input if calculated weight is 0 or invalid
            if ($weightInPounds <= 0) {
                $weightInPounds = $validated['weight_lb'] + ($validated['weight_oz'] / 16);
            }

            $shipmentData = [
                'shipper_name'      => $order->shipper_name ?? '5 Core Inc',
                'shipper_phone'     => $order->shipper_phone ?? '9513866372',
                'shipper_company'   => $order->shipper_company ?? '5 Core Inc',
                'shipper_street'    => $order->shipper_street ?? '1221 W Sandusky Ave',
                'shipper_city'      => $order->shipper_city ?? 'Bellefontaine',
                'shipper_state'     => $order->shipper_state ?? 'OH',
                'shipper_postal'    => $order->shipper_postal ?? '43311',
                'item_sku'    =>       $order->item_sku,
                'item_quantity' =>      $order->quantity ?? 1,
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
                'weight'            => $weightInPounds,
                'length'            => $lengthD,
                'width'             => $widthD,
                'height'            => $heightD,
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

        return response()->json(['status'=>'success','message'=>'Labels generated successfully','url'=>$finalUrl]);

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

public function refreshEdeskData(Request $request)
{
    $request->validate([
        'order_ids' => 'required|array',
        'order_ids.*' => 'exists:orders,id',
    ]);

    $edeskService = config('services.edesk.bearer_token') ? new \App\Services\EDeskService() : null;
    
    if (!$edeskService) {
        return response()->json([
            'success' => false,
            'message' => 'eDesk is not configured. Please set EDESK_BEARER_TOKEN in your .env file.'
        ], 400);
    }

    $updated = 0;
    $failed = 0;
    $errors = [];

    foreach ($request->order_ids as $orderId) {
        try {
            $order = Order::findOrFail($orderId);
            
            // Only process Amazon orders
            if ($order->marketplace !== 'amazon') {
                continue;
            }

            // Fetch customer details from eDesk
            $edeskCustomer = $edeskService->getCustomerDetailsByOrderId($order->order_number);
            
            if ($edeskCustomer) {
                $updateData = [];
                
                // Update recipient name if missing and eDesk has it
                if (empty($order->recipient_name) || $order->recipient_name === 'null' || $order->recipient_name === 'NULL') {
                    if (!empty($edeskCustomer['name'])) {
                        $updateData['recipient_name'] = trim($edeskCustomer['name']);
                    }
                }
                
                // Always update address fields if eDesk has better data (even if Amazon has partial data)
                // This ensures we get complete address information
                if (!empty($edeskCustomer['address1']) && (empty($order->ship_address1) || $order->ship_address1 === 'null' || $order->ship_address1 === 'NULL')) {
                    $updateData['ship_address1'] = $edeskCustomer['address1'];
                }
                
                // Update address fields if missing
                if (empty($order->ship_address1) && !empty($edeskCustomer['address1'])) {
                    $updateData['ship_address1'] = $edeskCustomer['address1'];
                }
                if (empty($order->ship_address2) && !empty($edeskCustomer['address2'])) {
                    $updateData['ship_address2'] = $edeskCustomer['address2'];
                }
                if (empty($order->ship_city) && !empty($edeskCustomer['city'])) {
                    $updateData['ship_city'] = $edeskCustomer['city'];
                }
                if (empty($order->ship_state) && !empty($edeskCustomer['state'])) {
                    $updateData['ship_state'] = $edeskCustomer['state'];
                }
                if (empty($order->ship_postal_code) && !empty($edeskCustomer['postal_code'])) {
                    $updateData['ship_postal_code'] = $edeskCustomer['postal_code'];
                }
                if (empty($order->ship_country) && !empty($edeskCustomer['country'])) {
                    $updateData['ship_country'] = $edeskCustomer['country'];
                }
                if (empty($order->recipient_phone) && !empty($edeskCustomer['phone'])) {
                    $updateData['recipient_phone'] = $edeskCustomer['phone'];
                }
                if (empty($order->recipient_email) && !empty($edeskCustomer['email'])) {
                    $updateData['recipient_email'] = $edeskCustomer['email'];
                }
                
                if (!empty($updateData)) {
                    $order->update($updateData);
                    $updated++;
                    Log::info('Order updated with eDesk data', [
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'updated_fields' => array_keys($updateData),
                    ]);
                }
            } else {
                $failed++;
                $errors[] = "Order {$order->order_number}: No eDesk data found";
            }
        } catch (\Exception $e) {
            $failed++;
            $errors[] = "Order ID {$orderId}: " . $e->getMessage();
            Log::error('Failed to refresh eDesk data for order', [
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return response()->json([
        'success' => true,
        'message' => "Updated {$updated} order(s). {$failed} order(s) could not be updated.",
        'updated' => $updated,
        'failed' => $failed,
        'errors' => $errors,
    ]);
}
}
