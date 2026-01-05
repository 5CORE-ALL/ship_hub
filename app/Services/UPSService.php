<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\CarrierAccount;
use Illuminate\Support\Str;
class UPSService
{
    protected $clientId;
    protected $clientSecret;
    protected $accountNumber;
    protected $endpoint;
    protected $apiEnvironment;

    public function __construct($userId)
    {
        $account = DB::table('carrier_accounts')
            ->where('carrier_name', 'ups')
            ->first();

        if (!$account) {
            throw new \Exception("UPS account not found for user ID: {$userId}");
        }

        $this->clientId      = $account->client_id;
        $this->clientSecret  = $account->client_secret;
        $this->accountNumber = 'C2063D';
        $this->apiEnvironment = $account->api_environment ?? 'sandbox';

        // v2403 Rate API endpoint
        $this->endpoint = $this->apiEnvironment === 'sandbox'
            ? 'https://wwwcie.ups.com/api/rating/v2403/rate'
            : 'https://onlinetools.ups.com/api/rating/v2403/rate';
    }

    /**
     * Get OAuth access token
     */
    protected function getAccessToken()
    {
        $credentials = base64_encode("{$this->clientId}:{$this->clientSecret}");
        $tokenEndpoint = $this->apiEnvironment === 'sandbox'
            ? 'https://wwwcie.ups.com/security/v1/oauth/token'
            : 'https://onlinetools.ups.com/security/v1/oauth/token';

        $response = Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ])->asForm()->post($tokenEndpoint, [
            'grant_type' => 'client_credentials'
        ]);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch UPS token: " . $response->body());
        }

        return $response->json()['access_token'];
    }

    /**
     * Fetch UPS rates
     */
 public function getRate($params)
   {
    try {
    
        $shipperCountry = strtoupper($params['shipper_country'] ?? 'US');
        $weightUnit     = strtoupper($params['weight_unit'] ?? ($shipperCountry === 'US' ? 'LBS' : 'KGS'));
        $dimensionUnit  = strtoupper($params['dimension_unit'] ?? 'IN');
        $payload = [
            "RateRequest" => [
                "Request" => [
                    "TransactionReference" => [
                        "CustomerContext" => "Rating and Service"
                    ]
                ],
                "Shipment" => [
                    "Shipper" => [
                        "Name"          => $params['shipper_name'] ?? "Shipper",
                        "ShipperNumber" => $params['shipper_number'] ?? $this->accountNumber,
                        "Address"       => [
                            "AddressLine"       => [$params['shipper_address'] ?? "123 Main Street"],
                            "City"              => $params['shipper_city'] ?? "City",
                            "StateProvinceCode" => $params['shipper_state'] ?? "OH",
                            "PostalCode"        => $params['shipper_postal'] ?? "00000",
                            "CountryCode"       => $shipperCountry
                        ]
                    ],
                    "ShipTo" => [
                        "Name"    => $params['recipient_name'] ?? "Recipient",
                        "Address" => [
                            "AddressLine"       => $this->buildAddressLines(
                                $params['recipient_address'] ?? $params['recipient_street'] ?? "Address",
                                $params['recipient_address2'] ?? $params['recipient_street2'] ?? null
                            ),
                            "City"              => $params['recipient_city'] ?? "City",
                            "StateProvinceCode" => $params['recipient_state'] ?? "VA",
                            "PostalCode"        => $params['recipient_postal'] ?? "00000",
                            "CountryCode"       => strtoupper($params['recipient_country'] ?? "US")
                        ]
                    ],
                    "ShipFrom" => [
                        "Name"    => $params['shipper_name'] ?? "Shipper",
                        "Address" => [
                            "AddressLine"       => [$params['shipper_address'] ?? "123 Main Street"],
                            "City"              => $params['shipper_city'] ?? "City",
                            "StateProvinceCode" => $params['shipper_state'] ?? "OH",
                            "PostalCode"        => $params['shipper_postal'] ?? "00000",
                            "CountryCode"       => $shipperCountry
                        ]
                    ],
                    "PaymentDetails" => [
                        "ShipmentCharge" => [
                            [
                                "Type"        => "01",
                                "BillShipper" => [
                                    "AccountNumber" => $params['shipper_number'] ?? $this->accountNumber
                                ]
                            ]
                        ]
                    ],
                    "Service" => [
                        "Code" => $params['service_code'] ?? "03"
                    ],
                    "Package" => [
                        [
                            "PackagingType" => [
                                "Code"        => $params['packaging_type'] ?? "02",
                                "Description" => "Customer Packaging"
                            ],
                            "Dimensions" => [
                                "UnitOfMeasurement" => [
                                    "Code" => $dimensionUnit // IN or CM
                                ],
                                "Length" => (string) ($params['length'] ?? "1"),
                                "Width"  => (string) ($params['width'] ?? "1"),
                                "Height" => (string) ($params['height'] ?? "1")
                            ],
                            "PackageWeight" => [
                                "UnitOfMeasurement" => [
                                    "Code" =>'LBS' //$weightUnit // LBS or KGS
                                ],
                                "Weight" => (string) ($params['weight'] ?? "1")
                            ]
                        ]
                    ]
                ]
            ]
        ];

        Log::info("UPS Rate Payload", [
            'payload' => json_encode($payload, JSON_PRETTY_PRINT)
        ]);
        $response = Http::withToken($this->getAccessToken())
            ->post($this->endpoint, $payload);
        
        if (!$response->successful()) {
            return [
                'success' => false,
                'message' => "UPS Rate API failed: " . $response->body()
            ];
        }
        $result   = $response->json();
           Log::info("UPS Rate result", [
            'payload' => json_encode($result, JSON_PRETTY_PRINT)
        ]);
      
        $ratedShipment = $result['RateResponse']['RatedShipment'] ?? null;

        $ratedShipments = [];
        if ($ratedShipment) {
            // Handle array or single object response
            $ratedShipments = isset($ratedShipment[0])
                ? $ratedShipment
                : [$ratedShipment];
        }

        $firstShipment   = $ratedShipments[0] ?? null;
        $shippingCharge  = $firstShipment['TotalCharges']['MonetaryValue'] ?? 0;
        $currency        = $firstShipment['TotalCharges']['CurrencyCode'] ?? 'USD';

        Log::info("UPS Rate Response", [
            'shipping_charge' => $shippingCharge,
            'currency'        => $currency
        ]);

        return [
            'success'         => true,
            'data'            => $result,
            'shipping_charge' => $shippingCharge,
            'currency'        => $currency
        ];
    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
    /**
 * Create a shipment in UPS
 */
public function createShipment($params)
{
    try {
        // Set defaults
        $shipperCountry = strtoupper($params['shipper_country'] ?? 'US');
        $weightUnit = strtoupper($params['weight_unit'] ?? ($shipperCountry === 'US' ? 'LBS' : 'KGS'));
        $dimensionUnit = strtoupper($params['dimension_unit'] ?? 'IN');

        // Build UPS payload
        $recipientPhone = $params['recipient_phone'] ?? '0000000000';
        if (strlen($recipientPhone) < 10) {
            $recipientPhone = str_pad($recipientPhone, 10, '0', STR_PAD_RIGHT);
        }

        $shipperPostal = $params['shipper_postal'] ?? '';
        if (empty($shipperPostal) || preg_match('/^0+$/', $shipperPostal)) {
            $shipperPostal = '43311';
        }

        $payload = [
            "ShipmentRequest" => [
                "Request" => [
                    "SubVersion" => "1801",
                    "RequestOption" => "nonvalidate",
                    "TransactionReference" => [
                        "CustomerContext" => "Shipment Creation"
                    ]
                ],
                "Shipment" => [
                    "Description" => $this->buildShipmentDescription($params),
                    "Shipper" => [
                        "Name" => $params['shipper_name'] ?? "5 Core Inc",
                        "AttentionName" => $params['shipper_attention'] ?? "5 Core Inc",
                        "Phone" => ["Number" => $params['shipper_phone'] ?? "9513866372"],
                        "ShipperNumber" => $params['shipper_number'] ?? $this->accountNumber,
                        "Address" => [
                            "AddressLine" => [$params['shipper_address'] ?? "1221 W Sandusky Ave"],
                            "City" => $params['shipper_city'] ?? "Bellefontaine",
                            "StateProvinceCode" => $params['shipper_state'] ?? "OH",
                            "PostalCode" => $shipperPostal,
                            "CountryCode" => strtoupper($params['shipper_country'] ?? "US")
                        ]
                    ],
                    "ShipTo" => [
                        "Name" => !empty($params['recipient_name']) ? $params['recipient_name'] : "Recipient",
                        "AttentionName" => $this->buildAttentionName($params),
                        "Phone" => ["Number" => $recipientPhone],
                        "Address" => [
                            "AddressLine" => $this->buildAddressLines(
                                $params['recipient_address'] ?? $params['recipient_street'] ?? "123 Recipient St",
                                $params['recipient_address2'] ?? $params['recipient_street2'] ?? null
                            ),
                            "City" => $params['recipient_city'] ?? "City",
                            "StateProvinceCode" => $params['recipient_state'] ?? "VA",
                            "PostalCode" => $params['recipient_postal'] ?? "00000",
                            "CountryCode" => strtoupper($params['recipient_country'] ?? "US")
                        ],
                        "Residential" => $params['residential'] ?? false
                    ],
                    "ShipFrom" => [
                        "Name" => $params['ship_from_name'] ?? "5 Core Inc",
                        "AttentionName" => $params['ship_from_attention'] ?? "Attention",
                        "Phone" => ["Number" => $recipientPhone],
                        "Address" => [
                            "AddressLine" => [$params['ship_from_address'] ?? "5 Core Inc"],
                            "City" => $params['ship_from_city'] ?? "Bellefontaine",
                            "StateProvinceCode" => $params['ship_from_state'] ?? "OH",
                            "PostalCode" => '43311',
                            "CountryCode" => strtoupper($params['ship_from_country'] ?? "US")
                        ]
                    ],
                    "PaymentInformation" => [
                        "ShipmentCharge" => [
                            "Type" => "01",
                            "BillShipper" => [
                                "AccountNumber" => $params['shipper_number'] ?? $this->accountNumber
                            ]
                        ]
                    ],
                    "Service" => [
                        "Code" => $params['service_code'] ?? "03",
                        "Description" => $params['service_description'] ?? "Ground"
                    ],
                    "Package" => [
                        [
                            "Description" => $this->buildPackageDescription($params),
                            "Packaging" => [
                                "Code" => $params['packaging_code'] ?? "02",
                                "Description" => $params['packaging_description'] ?? "Customer Packaging"
                            ],
                            "Dimensions" => [
                                "UnitOfMeasurement" => [
                                    "Code" => $params['dimension_unit'] ?? "IN",
                                    "Description" => "Inches"
                                ],
                                "Length" => (string)($params['length'] ?? "1"),
                                "Width" => (string)($params['width'] ?? "1"),
                                "Height" => (string)($params['height'] ?? "1")
                            ],
                            "PackageWeight" => [
                                "UnitOfMeasurement" => [
                                    "Code" => "LBS",
                                    "Description" => "Pounds"
                                ],
                                "Weight" => (string)($params['weight'] ?? "1"),
                            ],
                           "ReferenceNumber" => $params['reference_numbers'] ?? $this->buildReferenceNumbers($params)
                        ]
                    ]
                ],
                "LabelSpecification" => [
                    "LabelImageFormat" => ["Code" => "GIF", "Description" => "GIF"],
                    "LabelStockSize" => ["Width" => "4", "Height" => "6"],
                    "HTTPUserAgent" => "Mozilla/4.5"
                ]
            ]
        ];

        Log::info("UPS Shipment Payload", [
            'payload' => json_encode($payload, JSON_PRETTY_PRINT)
        ]);

        // UPS Ship endpoint
        $endpoint = $this->apiEnvironment === 'sandbox'
            ? 'https://wwwcie.ups.com/api/shipments/v2409/ship'
            : 'https://onlinetools.ups.com/api/shipments/v2409/ship';

        $queryParams = [
            'additionaladdressvalidation' => $params['additional_address_validation'] ?? 'true'
        ];

        $response = Http::withToken($this->getAccessToken())
            ->post($endpoint . '?' . http_build_query($queryParams), $payload);

        if (!$response->successful()) {
            Log::error("UPS Shipment API failed", ['body' => $response->body()]);
            return [
                'success' => false,
                'message' => "UPS Shipment API failed: " . $response->body()
            ];
        }

        $result = $response->json();
        $shipmentResults = $result['ShipmentResponse']['ShipmentResults'] ?? null;

        if (!$shipmentResults) {
            return [
                'success' => false,
                'message' => "UPS Shipment API did not return shipment results.",
                'response' => $result
            ];
        }

        $trackingNumber = $shipmentResults['ShipmentIdentificationNumber'] ?? null;
        $label = $shipmentResults['PackageResults'][0]['ShippingLabel']['GraphicImage'] ?? null;

        if (!$label) {
            return [
                'success' => false,
                'message' => 'No label returned from UPS.',
                'response' => $result
            ];
        }

        $gifService = app(GifService::class);
        $filenameOrUrl = $gifService->saveBase64Gif($label);

        // return [
        //     'success' => true,
        //     'tracking_number' => $trackingNumber,
        //     'label_url' => $filenameOrUrl, // returns public URL
        //     'data' => $result
        // ];
        return [
        'success'         => true,
        'tracking_number' => $trackingNumber,
        'label_url'       => $filenameOrUrl,       
        'label'           => $label,             
        'label_type'      => 'GIF',              
        'data'            => $result             
    ];

    } catch (\Exception $e) {
        Log::error("UPS Shipment Exception", ['exception' => $e]);
        return [
            'success' => false,
            'message' => $e->getMessage()
        ];
    }
}
/**
 * Cancel / Void a UPS shipment
 * 
 * @param string $trackingNumber
 * @return array
 */
// public function cancelShipment(string $trackingNumber, string $carrierName): array
// {
//     try {
//         $carrierName = strtolower($carrierName);
//         if ($carrierName !== 'ups') {
//             return [
//                 'success' => false,
//                 'message' => "Carrier '$carrierName' is not supported for this function."
//             ];
//         }

//         if (empty($trackingNumber)) {
//             return [
//                 'success' => false,
//                 'message' => 'Tracking number is required.'
//             ];
//         }
//         $carrierAccount = CarrierAccount::where('carrier_name', 'ups')->first();
//         if (!$carrierAccount) {
//             return [
//                 'success' => false,
//                 'message' => "Carrier 'UPS' account not found."
//             ];
//         }
//         $env = strtolower($carrierAccount->api_environment);
//         $baseUrl = $env === 'production'
//             ? 'https://onlinetools.ups.com/api/shipments/v2403/void/cancel/'
//             : 'https://wwwcie.ups.com/api/shipments/v2403/void/cancel/';

//         $endpoint = $baseUrl . $trackingNumber;

//         $response = Http::withToken($carrierAccount->access_token)
//             ->withHeaders([
//                 'transId' => (string) Str::uuid(),
//                 'transactionSrc' => 'testing',
//             ])
//             ->delete($endpoint);

//         $result = $response->json();
//         Log::info("UPS Cancel Shipment Response", [$result]);
//         if (isset($result['response']['errors']) && count($result['response']['errors']) > 0) {
//             $errorMessages = array_map(fn($e) => $e['message'], $result['response']['errors']);
//             return [
//                 'success' => false,
//                 'message' => implode('; ', $errorMessages),
//                 'data' => $result
//             ];
//         }

//         if ($response->failed()) {
//             return [
//                 'success' => false,
//                 'message' => 'Failed to cancel shipment with UPS.',
//                 'data' => $result
//             ];
//         }
//         return [
//             'success' => true,
//             'message' => 'Shipment cancelled with ' . ucfirst($carrierName) . '.',
//             'data' => $result
//         ];

//     } catch (\Exception $e) {
//         return [
//             'success' => false,
//             'message' => 'Failed to cancel shipment with ' . ucfirst($carrierName) . ': ' . $e->getMessage(),
//             'data' => []
//         ];
//     }
// }
public function cancelShipment(string $trackingNumber, string $carrierName): array
{
    try {
        $carrierName = strtolower($carrierName);
        if ($carrierName !== 'ups') {
            return [
                'success' => false,
                'message' => "Carrier '$carrierName' is not supported for this function."
            ];
        }

        if (empty($trackingNumber)) {
            return [
                'success' => false,
                'message' => 'Tracking number is required.'
            ];
        }

        $env = strtolower($this->apiEnvironment);
        $baseUrl = $env === 'production'
            ? 'https://onlinetools.ups.com/api/shipments/v2403/void/cancel/'
            : 'https://wwwcie.ups.com/api/shipments/v2403/void/cancel/';

        $endpoint = $baseUrl . $trackingNumber;

        $response = Http::withToken($this->getAccessToken())
            ->withHeaders([
                'transId' => (string) Str::uuid(),
                'transactionSrc' => 'testing',
            ])
            ->delete($endpoint);

        $result = $response->json();
        Log::info("UPS Cancel Shipment Response", [$result]);

        if (isset($result['response']['errors']) && count($result['response']['errors']) > 0) {
            $errorMessages = array_map(fn($e) => $e['message'], $result['response']['errors']);
            return [
                'success' => false,
                'message' => implode('; ', $errorMessages),
                'data' => $result
            ];
        }

        if ($response->failed()) {
            return [
                'success' => false,
                'message' => 'Failed to cancel shipment with UPS.',
                'data' => $result
            ];
        }

        return [
            'success' => true,
            'message' => 'Shipment cancelled with ' . ucfirst($carrierName) . '.',
            'data' => $result
        ];

    } catch (\Exception $e) {
        return [
            'success' => false,
            'message' => 'Failed to cancel shipment with ' . ucfirst($carrierName) . ': ' . $e->getMessage(),
            'data' => []
        ];
    }
}

/**
 * Build AddressLine array with both address lines if second line exists
 * 
 * @param string $addressLine1 Main address line
 * @param string|null $addressLine2 Apartment number or second address line
 * @return array
 */
protected function buildAddressLines($addressLine1, $addressLine2 = null)
{
    $addressLines = [trim($addressLine1)];
    
    if (!empty($addressLine2)) {
        $trimmedLine2 = trim($addressLine2);
        if (!empty($trimmedLine2)) {
            $addressLines[] = $trimmedLine2;
        }
    }
    
    return $addressLines;
}

/**
 * Build ReferenceNumber array with SKU and quantity
 * 
 * @param array $params Parameters containing item_sku and item_quantity
 * @return array
 */
protected function buildReferenceNumbers($params)
{
    $references = [];
    
    $sku = !empty($params['item_sku']) ? $params['item_sku'] : null;
    $quantity = isset($params['item_quantity']) ? (int)$params['item_quantity'] : 1;
    
    if (!empty($sku)) {
        // Format: SKU-QTY (or just SKU if quantity is 1)
        // This format is more compact and will appear on the label
        $skuValue = $quantity > 1 ? "{$sku}-{$quantity}" : $sku;
        
        // Limit to 35 characters (UPS ReferenceNumber max length)
        $skuValue = substr($skuValue, 0, 35);
        
        // Use PO (Purchase Order) code for SKU reference - commonly displayed on labels
        $references[] = [
            "Code"  => "PO",
            "Value" => $skuValue
        ];
        
        // Also add as IN (Invoice Number) if quantity is specified
        if ($quantity > 1) {
            $references[] = [
                "Code"  => "IN",
                "Value" => "Qty: {$quantity}"
            ];
        }
    } else {
        // Fallback if no SKU
        $references[] = [
            "Code"  => "PO",
            "Value" => '-'
        ];
    }
    
    return $references;
}

/**
 * Build Package Description with SKU and quantity
 * 
 * @param array $params Parameters containing item_sku and item_quantity
 * @return string
 */
protected function buildPackageDescription($params)
{
    if (!empty($params['package_description'])) {
        return $params['package_description'];
    }
    
    $sku = !empty($params['item_sku']) ? $params['item_sku'] : null;
    $quantity = isset($params['item_quantity']) ? (int)$params['item_quantity'] : 1;
    
    if (!empty($sku)) {
        // Format: SKU (Qty: X) or just SKU if quantity is 1
        return $quantity > 1 ? "{$sku} (Qty: {$quantity})" : $sku;
    }
    
    return "Shipment via UPS";
}

/**
 * Build AttentionName with SKU and quantity for better label visibility
 * 
 * @param array $params Parameters containing item_sku, item_quantity, and recipient_attention
 * @return string
 */
protected function buildAttentionName($params)
{
    // Use custom attention name if provided
    if (!empty($params['recipient_attention'])) {
        $attentionName = $params['recipient_attention'];
    } else {
        $attentionName = "Recipient Attn";
    }
    
    $sku = !empty($params['item_sku']) ? $params['item_sku'] : null;
    $quantity = isset($params['item_quantity']) ? (int)$params['item_quantity'] : 1;
    
    if (!empty($sku)) {
        // Format: AttentionName - SKU (Qty: X) or AttentionName - SKU
        // UPS AttentionName max length is typically 35 characters
        $skuInfo = $quantity > 1 ? "{$sku} (Qty: {$quantity})" : $sku;
        $combined = "{$attentionName} - {$skuInfo}";
        
        // Limit to 35 characters, prioritizing SKU information
        if (strlen($combined) > 35) {
            // If too long, try just SKU and quantity
            $skuInfo = $quantity > 1 ? "{$sku}-{$quantity}" : $sku;
            $combined = strlen($skuInfo) <= 35 ? $skuInfo : substr($skuInfo, 0, 35);
        }
        
        return $combined;
    }
    
    return $attentionName;
}

/**
 * Build Shipment Description with SKU and quantity
 * 
 * @param array $params Parameters containing item_sku, item_quantity, and description
 * @return string
 */
protected function buildShipmentDescription($params)
{
    // Use custom description if provided
    if (!empty($params['description'])) {
        return $params['description'];
    }
    
    $sku = !empty($params['item_sku']) ? $params['item_sku'] : null;
    $quantity = isset($params['item_quantity']) ? (int)$params['item_quantity'] : 1;
    
    if (!empty($sku)) {
        // Format: SKU (Qty: X) or just SKU if quantity is 1
        return $quantity > 1 ? "{$sku} (Qty: {$quantity})" : $sku;
    }
    
    return "Shipment via UPS";
}

}
