<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
use App\Models\OrderItem;
use Exception;
use Illuminate\Support\Facades\Mail;

class ShippoService
{
    protected string $baseUrl;
    protected string $apiToken;

    public function __construct()
    {
        $this->baseUrl  = config('services.shippo.base_url', 'https://api.goshippo.com');
        $this->apiToken = config('services.shippo.api_token');
    }

    protected function headers(): array
    {
        return [
            'Authorization' => 'ShippoToken ' . $this->apiToken,
            'Content-Type'  => 'application/json',
        ];
    }
    public function getCarriers()
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/carriers/");

        if ($response->failed()) {
            throw new Exception("Shippo carriers API failed: " . $response->body());
        }

        return $response->json();
    }
    public function getRates(array $params)
    {
        $massUnit = strtolower($params['weight_unit'] ?? 'lb');
        if ($massUnit === 'pound') {
            $massUnit = 'lb';
        }
        $distanceUnit = strtolower($params['dim_unit'] ?? 'in');
        if ($distanceUnit === 'inch') {
            $distanceUnit = 'in';
        }
        $order = Order::with('cost')->find($params['order_id']);
           $order_items = OrderItem::where("order_id", $params['order_id'])->get();
                    $skuQuantities = $order_items
                        ->groupBy("sku")
                        ->map(function ($group) {
                            return $group->sum("quantity_ordered");
                        });
                    if ($skuQuantities->count() > 1) {
                        $skuQtyString = "multi";
                    } elseif ($skuQuantities->count() == 1) {
                        $sku = $skuQuantities->keys()->first();
                        $qty = $skuQuantities->first();
                        $skuQtyString = "{$sku}: {$qty}pcs";
                    } else {
                        $skuQtyString = "";
                    }
            $items = [];
            foreach ($order->items as $item) {
                $items[] = [
                    "name"       => $item->product_name ?? "Unknown Item",
                    "sku"        => $item->sku ?? "SKU-{$item->id}",
                    "quantity"   => $item->quantity_ordered ?? 1,
                    "unit_price" => $item->unit_price ?? 0
                ];
           }
           $carrier = strtoupper($params['carrier'] ?? '');
        $payload = [
            "address_from" => [
                "name"    => $params['ship_from_name'] ?? "Jane Smith",
                "street1" => $params['ship_from_address'] ?? "456 Origin Rd.",
                "city"    => $params['ship_from_city'] ?? "Austin",
                "state"   => $params['ship_from_state'] ?? "TX",
                "zip"     => $params['ship_from_zip'] ?? "78701",
                "country" => "US",
                "phone"   => $params['ship_from_phone'] ?? "1111111111"
            ],
            "address_to" => [
                "name"    => $params['ship_to_name'] ?? "John Doe",
                "street1" => $params['ship_to_address'] ?? "123 Destination St.",
                "city"    => $params['ship_to_city'] ?? "Dallas",
                "state"   => $params['ship_to_state'] ?? "TX",
                "zip"     => $params['ship_to_zip'] ?? "75201",
                "country" => "US",
                "phone"   => $params['ship_to_phone'] ?? "0000000000",
                "company" => "($skuQtyString)",
            ],
            "parcels" => [[
                "length" => (string)($params['length'] ?? 4.0),
                "width"  => (string)($params['width'] ?? 4.0),
                "height" => (string)($params['height'] ?? 7.0),
                "distance_unit" => $distanceUnit,
                "weight" => (string)($params['weight_value'] ?? 0.25),
                "mass_unit" => $massUnit
            ]],
            "validate_address"=> "no_validation",
            "extra" =>[
               'reference_1'=>$order->order_number ?? 'N/A'
            ],
            "async" => false,
            "label_file_type" => "PDF",  
            "label_size"      => "4x6"
        ];

        Log::info("Shippo Rates Payload", $payload);

        $response = Http::withHeaders($this->headers())
        ->timeout(15)
            ->post("{$this->baseUrl}/shipments/", $payload);
             Log::info("Shippo Rates Response", [
    'status'   => $response->status(),
    'body'     => $response->json(),
    'raw_body' => $response->body(), // just in case JSON parse fail ho
]);


        if ($response->failed()) {
            throw new Exception("Shippo rates API failed: " . $response->body());
        }

        $data = $response->json();
        return $data['rates'] ?? [];
    }

    /**
     * Create a Transaction (label) by Rate ID
     */
    public function createLabelByRateId(string $rateId)
    {
        $payload = [
            "rate" => $rateId,
            "label_file_type" => "PDF_4x6",
            "label_size"      => "4x6",
            "async" => false
        ];

        // $response = Http::withHeaders($this->headers())
        //     ->post("{$this->baseUrl}/transactions/", $payload);
        $response = Http::timeout(15)             
    ->connectTimeout(10)             
    ->withHeaders($this->headers())
    ->post("{$this->baseUrl}/transactions/", $payload);

        Log::info("Shippo Label Create Response", [
            'rate_id'  => $rateId,
            'payload'  => $payload,
            'response' => $response->json()
        ]);

        if ($response->failed()) {
            return [
                'success' => false,
                'error'   => $response->json()
            ];
        }

        $data = $response->json();
        $labelDir = storage_path('app/public/labels');
        if (!file_exists($labelDir)) {
            mkdir($labelDir, 0755, true);
        }

        $remoteUrl = $data['label_url'] ?? null;
        $localFileName = 'shippo_label_' . $data['object_id'] . '.pdf';
        $localFilePath = $labelDir . '/' . $localFileName;

        if ($remoteUrl) {
            try {
                file_put_contents($localFilePath, file_get_contents($remoteUrl));
            } catch (\Exception $e) {
                Log::error("Failed to download Shippo label PDF: " . $e->getMessage());
            }
        }

        $localUrl = asset('storage/labels/' . $localFileName);

        return [
            'success'        => true,
            'label_id'       => $data['object_id'] ?? null,
            'trackingNumber' => $data['tracking_number'] ?? null,
            'labelUrl'       => $localUrl,
            'raw'            => $data
        ];
    }

    /**
     * Void / Refund a label (transaction)
     */
    public function voidLabel(string $transactionId)
    {
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/refunds/", [
                "transaction" => $transactionId
            ]);

        Log::info("Shippo Void Label Response", [
            'transaction_id' => $transactionId,
            'status'         => $response->status(),
            'body'           => $response->json()
        ]);

        if ($response->failed()) {
            return [
                'success' => false,
                'error'   => $response->json()
            ];
        }

        return [
            'success' => true,
            'raw'     => $response->json()
        ];
    }

    /**
     * Optional: InstaLabel (one-step label creation)
     */
    public function createLabelInsta(array $params)
    {
        $payload = [
            "shipment" => [
                "address_from" => $params['address_from'],
                "address_to"   => $params['address_to'],
                "parcels"      => $params['parcels']
            ],
            "carrier_account"    => $params['carrier_account'],
            "servicelevel_token" => $params['servicelevel_token'],
            "label_file_type"    => "PDF",
            "async"              => false
        ];

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/transactions/", $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'error'   => $response->json()
            ];
        }

        return [
            'success' => true,
            'raw'     => $response->json()
        ];
}
function generateReferences($order, $carrier)
{
    $carrier = strtoupper($carrier ?? '');
    $maxRefs = $carrier === 'UPS' ? 2 : 3;
    $limit = 30;

    $references = [];
    $references['reference_1'] = "Order#" . ($order->order_number ?? 'N/A');

    $items = $order->items;

    if ($items->count() === 1) {
        $item = $items->first();
        $sku = $item->sku ?? 'N/A';
        $qty = $item->quantity_ordered ?? 1;
        $qtyText = $qty > 1 ? "-{$qty}pcs" : '';
        $references['reference_2'] = mb_substr(">> {$sku}{$qtyText}", 0, $limit);
        // $references['reference_2'] = mb_substr(">> {$sku}-{$qty}pcs", 0, $limit);
    } else {
        $references['reference_2'] = "multi";

        try {
        } catch (\Exception $e) {
            \Log::warning("Multiple SKU mail failed for order #{$order->order_number}: " . $e->getMessage());
        }
    }

    return $references;
}
protected function generateLabelReferences1($order, $carrier): string
{
    $carrier = strtoupper($carrier ?? '');
    $limit = 30;

    $items = $order->items;

    if ($items->count() > 0) {
        // Group by SKU and sum quantities
        $skuQuantities = $items->groupBy('sku')->map(function ($group) {
            return $group->sum('quantity_ordered');
        });

        // If only 1 unique SKU, return that with total quantity
        if ($skuQuantities->count() === 1) {
            $sku = $skuQuantities->keys()->first();
            $qty = $skuQuantities->first();

            // Build label string and sanitize
            // $label = "{$sku}-{$qty}pcs";
            $label = "{$sku}" . ($qty > 1 ? "-{$qty}pcs" : '');
            $label = preg_replace('/[^A-Za-z0-9\s\-\_]/', '', $label);

            return mb_substr($label, 0, $limit);
        }
    }

    // For multiple SKUs, just return 'multi' or build a combined string if you prefer
    return 'multi';
}
}
