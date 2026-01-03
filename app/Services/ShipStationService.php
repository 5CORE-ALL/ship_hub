<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\CarriersList;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response;
class ShipStationService
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.shipstation.base_url', 'https://api.shipstation.com/v2');
        $this->apiKey  = config('services.shipstation.api_key'); 
    }

    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'api-key'      => $this->apiKey,
        ];
    }

    /**
     * Fetch carrier list
     */
    public function getCarriers()
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/carriers");

        if ($response->failed()) {
            throw new Exception("ShipStation carriers API failed: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Fetch shipping rates
     */
    public function getRates(array $params)
    {
           $allCarrierIds = CarriersList::pluck('carrier_id')->toArray();
            $order = Order::with('cost')->find($params['order_id']);
            if (!$order) {
                throw new \Exception("Order or cost data not found for order ID {$params['order_id']}.");
            }
            $actualWeightPounds   = $order->cost && $order->cost->wt_act ? ($order->cost->wt_act / 16) : 0; 
            $billableWeightPounds = $order->cost && $order->cost->b ? $order->cost->b : 0; 
            $finalWeightPounds = $billableWeightPounds > 0 ? $billableWeightPounds : $actualWeightPounds;
            $finalWeightOunces = $finalWeightPounds * 16;

            $items = [];
            foreach ($order->items as $item) {
                $items[] = [
                    "name"       => $item->product_name ?? "Unknown Item",
                    "sku"        => $item->sku ?? "SKU-{$item->id}",
                    "quantity"   => $item->quantity_ordered ?? 1,
                    "unit_price" => $item->unit_price ?? 0,
                    "weight"     => [
                        "value" => $finalWeightOunces,
                        "unit"  => "ounce"
                    ],
                ];
           }

      $carrier = strtoupper($params['carrier'] ?? '');
      $postalCode = $params['ship_to_zip'] ?? "75201";
        if (preg_match('/^\d{5}/', $postalCode, $matches)) {
            $postalCode = $matches[0];
        }
      $labelMessages = $this->generateLabelReferences($order, $carrier);
      $labelString = $this->generateLabelReferences1($order, $carrier);
      $companyName = ($order->order_number ?? 'N/A');
      $companyName = preg_replace('/[^A-Za-z0-9\s\-\_]/', '', $companyName);
      
      // Use standard dimensions (8x6x2) for USPS, otherwise use dimensions from params
      $isUSPS = (strtoupper($carrier) === 'USPS');
      $packageLength = $isUSPS ? 8 : ($params['length'] ?? 5);
      $packageWidth = $isUSPS ? 6 : ($params['width'] ?? 5);
      $packageHeight = $isUSPS ? 2 : ($params['height'] ?? 5);
      
        $payload = [
            "rate_options" => [
                "carrier_ids" => $allCarrierIds,
                "calculate_tax_amount" => $params['calculate_tax_amount'] ?? false,
                "preferred_currency" => $params['currency'] ?? "usd",
                "is_return" => $params['is_return'] ?? false,
            ],
            "shipment" => [
                "validate_address" => "no_validation",
                "ship_to" => [
                    "name" => $params['ship_to_name'] ?? "John Doe",
                    // "phone" => $params['ship_to_phone'] ?? "0000000000",
                    "address_line1" => trim(($params['ship_to_address'] ?? "123 Destination St.")),
                    "address_line2" => trim($params["ship_to_address2"] ?? $params["ship_address2"] ?? "") ?: "",
                    // "address_line2"=> $skuString ?: null,
                    "company_name" => !empty($labelString) ? "($labelString)" : "",
                    "city_locality" => $params['ship_to_city'] ?? "Dallas",
                    "state_province" => $params['ship_to_state'] ?? "TX",
                    "postal_code" => $postalCode,
                    "country_code" => $params['ship_to_country'] ?? "US",
                    "address_residential_indicator" => $params['ship_to_residential'] ?? "no"
                ],
                "ship_from" => [
                    "name" => $params['ship_from_name'] ?? "Jane Smith",
                    "phone" => $params['ship_from_phone'] ?? "1111111111",
                    // "company_name" => "Order #" . ($order->order_number ?? 'N/A'),
                    "address_line1" => $params['ship_from_address'] ?? "456 Origin Rd.",
                    "city_locality" => $params['ship_from_city'] ?? "Austin",
                    "state_province" => $params['ship_from_state'] ?? "TX",
                    "postal_code" => $params['ship_from_zip'] ?? "78701",
                    "country_code" => $params['ship_from_country'] ?? "US",
                    "address_residential_indicator" => $params['ship_from_residential'] ?? "no"
                ],
                "items" => $items,
                "packages" => [
                    [
                        "package_code" => $params['package_code'] ?? "package",
                        "weight" => [
                            "value" => $params['weight_value'] ?? 1,
                            "unit" => $params['weight_unit'] ?? "ounce",
                        ],
                        "dimensions" => [
                            "unit" => $params['dim_unit'] ?? "inch",
                            "length" => $packageLength,
                            "width"  => $packageWidth,
                            "height" => $packageHeight,
                        ],
                       "label_messages" => [
                          'reference_1' => $order->order_number ?? 'N/A'
                        ],
                    ]
                ],
                "ship_date" => $params['ship_date'] ?? now()->toDateString(),
            ]
        ];
        log::info("paylaod",[$payload]);

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/rates", $payload);

             // log::info("response",[$response]);

        if ($response->failed()) {
            throw new Exception("ShipStation rates API failed: " . $response->body());
        }

        return $response->json();
    }
public function createLabelByRateId(string $rateId)
{
    $payload = [
        "validate_address"    => "no_validation",
        "label_layout"        => "4x6",
        "label_format"        => "pdf",
        "label_download_type" => "url",
        "display_scheme"      => "label"
    ];

    $response = Http::withHeaders([
        "api-key"      => $this->apiKey,
        "Content-Type" => "application/json"
    ])->timeout(60)->retry(2, 2000)->post("{$this->baseUrl}/labels/rates/{$rateId}", $payload);

    Log::info("ShipStation Label Create Response", [
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

    // Download the label PDF to local storage
    $labelDir = storage_path('app/public/labels');
    if (!file_exists($labelDir)) {
        mkdir($labelDir, 0755, true);
    }

    $remoteUrl = $data['label_download']['href'] ?? null;
    $localFileName = 'label_' . $data['label_id'] . '.pdf';
    $localFilePath = $labelDir . '/' . $localFileName;

    if ($remoteUrl) {
        try {
            file_put_contents($localFilePath, file_get_contents($remoteUrl));
        } catch (\Exception $e) {
            Log::error("Failed to download label PDF: " . $e->getMessage());
        }
    }

    // Local URL to return
    $localUrl = asset('storage/labels/' . $localFileName);

    return [
        'success'        => true,
        'label_id'       => $data['label_id'] ?? null,
        'trackingNumber' => $data['tracking_number'] ?? null,
        'labelUrl'       => $localUrl, // Local URL
        'raw'            => $data
    ];
}

    /**
     * Void a label by Label ID
     *
     * @param string $labelId
     * @return array
     */
    public function voidLabel(string $labelId)
    {
        $payload = [
            "validate_address"    => "no_validation",
            "label_layout"        => "4x6",
            "label_format"        => "pdf",
            "label_download_type" => "url",
            "display_scheme"      => "label"
        ];

        $response = Http::withHeaders([
            "api-key"      => $this->apiKey,
            "Content-Type" => "application/json"
        ])->put("{$this->baseUrl}/labels/{$labelId}/void", $payload);

        \Log::info("ShipStation Void API Response", [
            'label_id' => $labelId,
            'status'   => $response->status(),
            'body'     => $response->json()
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
protected function generateLabelReferences($order, $carrier)
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
        $references['reference_2'] = mb_substr("{$sku}-{$qty}pcs", 0, $limit);
    } else {
        $references['reference_2'] = "multi";

        try {
            // Mail::send('emails.multiple_skus', [
            //     'orderNumber' => $order->order_number,
            //     'items' => $items
            // ], function ($message) use ($order) {
            //     $message->to('priyeshsurana5@gmail.com')
            //             ->from('software10@5core.com', 'ShipHub')
            //             ->subject("Order #{$order->order_number} - Multiple SKUs");
            // });
        } catch (\Exception $e) {
            \Log::warning("Multiple SKU mail failed for order #{$order->order_number}: " . $e->getMessage());
        }
    }
    return $references;
}
// protected function generateLabelReferences1($order, $carrier): string
// {
//     $carrier = strtoupper($carrier ?? '');
//     $maxRefs = $carrier === 'UPS' ? 2 : 3;
//     $limit = 30;

//     $items = $order->items;

//     if ($items->count() === 1) {
//         $item = $items->first();
//         $sku = $item->sku ?? 'N/A';
//         $qty = $item->quantity_ordered ?? 1;

//         // Create label string (without >> or special chars)
//         $label = "{$sku}-{$qty}pcs";

//         // Remove or replace invalid characters
//         $label = preg_replace('/[^A-Za-z0-9\s\-\_]/', '', $label);

//         return mb_substr($label, 0, $limit);
//     }

//     try {
//         // Mail::send('emails.multiple_skus', [
//         //     'orderNumber' => $order->order_number,
//         //     'items' => $items
//         // ], function ($message) use ($order) {
//         //     $message->to('priyeshsurana5@gmail.com')
//         //             ->from('software10@5core.com', 'ShipHub')
//         //             ->subject("Order #{$order->order_number} - Multiple SKUs");
//         // });
//     } catch (\Exception $e) {
//         \Log::warning("Multiple SKU mail failed for order #{$order->order_number}: " . $e->getMessage());
//     }

//     // Return sanitized label for multi-item orders
//     return 'multi';
// }
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
