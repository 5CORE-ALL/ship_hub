<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\CarrierAccount;
use Illuminate\Support\Facades\Log;
class ShipmentService
{
    protected $carrier;
    protected $key;
    protected $secret;
    protected $accountNumber;
    protected $baseUrl;

    public function __construct(string $carrier, $userId = null)
    {
        $this->carrier = strtolower($carrier);
        $carrierAccount = CarrierAccount::where('carrier_name', $carrier)
            ->first();

        if (!$carrierAccount) {
            throw new \Exception("$carrier account not found.");
        }

        $this->key           = $carrierAccount->client_id;
        $this->secret        = $carrierAccount->client_secret;
        $this->accountNumber = $carrierAccount->account_number;
        $env = strtolower($carrierAccount->api_environment);
        

        $this->baseUrl = match ($this->carrier) {
            'fedex' => $env === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com',
            'usps' => $env === 'production'
                ? 'https://secure.shippingapis.com'
                : 'https://secure.shippingapis.com/sandbox',
            default => throw new \Exception("Unsupported carrier: $carrier"),
        };
    }

    public function getAccessToken()
    {
        if ($this->carrier === 'fedex') {
            $response = Http::asForm()->post($this->baseUrl . '/oauth/token', [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->key,
                'client_secret' => $this->secret,
            ]);

            if ($response->failed()) {
                throw new \Exception('Failed to get FedEx token: ' . $response->body());
            }

            return $response->json()['access_token'];
        }
        throw new \Exception("Access token not implemented for {$this->carrier}");
    }
    public function createShipment(array $params)
    {
        $token = $this->getAccessToken();

        $payload = [
            "accountNumber" => [
                "value" => $this->accountNumber
            ],
            "labelResponseOptions" => "URL_ONLY",
            "requestedShipment" => [
                "shipper" => [
                    "contact" => [
                        "personName"  => $params['shipper_name'] ?? "5 Core Inc",
                        "phoneNumber" => $params['shipper_phone'] ?? "9513866372",
                        "companyName" => $params['shipper_company'] ?? "5 Core Inc",
                    ],
                    "address" => [
                        "streetLines"          => [$params['shipper_street'] ?? "123 Main St"],
                        "city"                 => $params['shipper_city'] ?? "Los Angeles",
                        "stateOrProvinceCode"  => $params['shipper_state'] ?? "CA",
                        "postalCode"           => $params['shipper_postal'] ?? "90001",
                        "countryCode"          => $params['shipper_country'] ?? "US",
                    ]
                ],
                "recipients" => [[   
                    "contact" => [
                        "personName"  => $params['recipient_name'] ?? "Default Recipient",
                        "phoneNumber" => $params['recipient_phone'] ?? "1111111111",
                        "companyName" => $params['recipient_company'] ?? "Customer",
                    ],
                    "address" => [
                        "streetLines"         => [$params['recipient_street'] ?? "Unknown Street"],
                        "city"                => $params['recipient_city'] ?? "New York",
                        "stateOrProvinceCode" => $params['recipient_state'] ?? "NY",
                        "postalCode"          => $params['recipient_postal'] ?? "10001",
                        "countryCode"         => $params['recipient_country'] ?? "US"
                    ]
                ]],
                "shipDatestamp"   => now()->toDateString(),
                "pickupType"      => $params['pickup_type'] ?? "DROPOFF_AT_FEDEX_LOCATION",
                "serviceType" => $params['service_type'],
                "packagingType"   => "YOUR_PACKAGING", //$params['packaging_type'] ?? 
                "shippingChargesPayment" => [   
                    "paymentType" => "SENDER",
                    "payor" => [
                        "responsibleParty" => [
                            "accountNumber" => [
                                "value" => $this->accountNumber
                            ]
                        ]
                    ]
                ],
                "labelSpecification" => [
                    "labelFormatType" => "COMMON2D",
                    "imageType"       => $params['label_type'] ?? "PDF",
                    "labelStockType"  => $params['label_stock'] ?? "PAPER_7X475",
                ],
                "requestedPackageLineItems" => [
                    [
                        "weight" => [
                            "units" => $params['weight_unit'] ?? "LB",
                            "value" => $params['weight'] ?? 1
                        ],
                        "dimensions" => [
                            "length" => $params['length'] ?? 10,
                            "width"  => $params['width'] ?? 5,
                            "height" => $params['height'] ?? 5,
                            "units"  => $params['dimension_unit'] ?? "IN"
                        ]
                    ]
                ]
            ]
        ];
        Log::info("FedEx Shipment Payload:\n" . json_encode($payload, JSON_PRETTY_PRINT));
 
        $endpoint = match ($this->carrier) {
            'fedex' => $this->baseUrl . '/ship/v1/shipments',
            'usps'  => $this->baseUrl . '/ship/v1/shipments', 
            default => throw new \Exception("Shipment not implemented for {$this->carrier}"),
        };

        $response = Http::withToken($token)->post($endpoint, $payload);

        if ($response->failed()) {
            throw new \Exception("{$this->carrier} Shipment API failed: " . $response->body());
        }

        $res = $response->json();
      return [
            'tracking_number' => $res['output']['transactionShipments'][0]['masterTrackingNumber'] ?? null,
            'label'           => $res['output']['transactionShipments'][0]['pieceResponses'][0]['packageDocuments'][0]['url'] ?? null,
            'label_type'      => $res['output']['transactionShipments'][0]['pieceResponses'][0]['packageDocuments'][0]['docType'] ?? 'PDF',
        ];
    }
}
