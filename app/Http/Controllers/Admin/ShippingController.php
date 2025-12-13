<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class ShippingController extends Controller
{
    public function getRates(Request $request)
    {
        $carrier = $request->input('carrier');
        $data = $request->only(['origin', 'destination', 'weight', 'dimensions']);

        switch (strtolower($carrier)) {
            case 'ups':
                return $this->upsRates($data);
            case 'fedex':
                return $this->fedexRates($data);
            case 'usps':
                return $this->uspsRates($data);
            default:
                return response()->json(['error' => 'Unsupported carrier'], 400);
        }
    }
public function getCarrierPackage($serviceCode)
{
    // Fetch the shipping service
    $shippingService = DB::table('shipping_service')
        ->where('service_code', $serviceCode)
        ->where('active', 1)
        ->first();

    if (!$shippingService) {
        return response()->json(['error' => 'Invalid or inactive service code'], 404);
    }

    $carrierName = strtolower($shippingService->carrier_name);

    // UPS service-to-package mapping
    $upsServicePackages = [
        '03' => ['02','2','04'],    
        '2'  => ['02','2'],        
        '12' => ['02','2','04'],
        '93' => ['02'],       
        '01' => ['02','2','04'],   
        '13' => ['02','2','04'],   
        '14' => ['02','2','04'], 
        '59' => ['02','2','04'], 
    ];

    if ($carrierName === 'ups' && isset($upsServicePackages[$serviceCode])) {
        $allowedPackages = $upsServicePackages[$serviceCode];
        $packages = DB::table('carrier_packages')
            ->where('carrier_name', $carrierName)
            ->where('active', 1)
            ->whereIn('package_code', $allowedPackages)
            ->select('package_code', 'display_name')
            ->get();
    } else {
        $packages = DB::table('carrier_packages')
            ->where('carrier_name', $carrierName)
            ->where('active', 1)
            ->select('package_code', 'display_name')
            ->get();
    }

    if ($packages->isEmpty()) {
        return response()->json([
            'error' => 'No packages found for this carrier and service code'
        ], 404);
    }

    return response()->json([
        'carrier' => $carrierName,
        'packages' => $packages
    ]);
}



    public function createShipment(Request $request)
    {
        $carrier = $request->input('carrier');
        $data = $request->only(['origin', 'destination', 'weight', 'dimensions', 'order_id']);

        switch (strtolower($carrier)) {
            case 'ups':
                return $this->upsCreateShipment($data);
            case 'fedex':
                return $this->fedexCreateShipment($data);
            case 'usps':
                return $this->uspsCreateShipment($data);
            default:
                return response()->json(['error' => 'Unsupported carrier'], 400);
        }
    }

    // UPS
    private function upsRates($data) { return response()->json(['carrier' => 'UPS', 'rates' => []]); }
    private function upsCreateShipment($data) { return response()->json(['carrier' => 'UPS', 'shipment_id' => 'UPS123']); }

    // FedEx
    private function fedexRates($data) { return response()->json(['carrier' => 'FedEx', 'rates' => []]); }
    private function fedexCreateShipment($data) { return response()->json(['carrier' => 'FedEx', 'shipment_id' => 'FDX123']); }

    // USPS
    private function uspsRates($data) 
    { 
        return response()->json(['carrier' => 'USPS', 'rates' => []]); 
    }
    private function uspsCreateShipment($data) { return response()->json(['carrier' => 'USPS', 'shipment_id' => 'USPS123']); }
}
