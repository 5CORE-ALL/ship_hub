<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\CarrierAccount;
use Illuminate\Support\Facades\Log;
class RateService
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
            // ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->first();

        if (!$carrierAccount) {
            throw new \Exception("$carrier account not found.");
        }

        $this->key           = $carrierAccount->client_id;
        $this->secret        = $carrierAccount->client_secret;
        $this->accountNumber = $carrierAccount->account_number;
        $env = strtolower($carrierAccount->api_environment);
        $this->baseUrl = match($this->carrier) {
            'fedex' => $env === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com',
            'usps' => $env === 'production'
                ? 'https://secure.shippingapis.com'
                : 'https://secure.shippingapis.com/sandbox',
            default => throw new \Exception("Unsupported carrier: $carrier"),
        };
    }
//  protected function getAccessToken()
// {
//     $response = Http::asForm()->post('https://apis-sandbox.fedex.com/oauth/token', [
//         'grant_type'    => 'client_credentials',
//         'client_id'     => $this->key,
//         'client_secret' => $this->secret,
//     ]);

//     if ($response->failed()) {
//         throw new \Exception('Failed to get FedEx token: ' . $response->body());
//     }

//     return $response->json()['access_token'];
// }
protected function getAccessToken()
{
    // Determine the token URL dynamically based on environment
    $tokenUrl = $this->baseUrl . '/oauth/token';

    $response = Http::asForm()->post($tokenUrl, [
        'grant_type'    => 'client_credentials',
        'client_id'     => $this->key,
        'client_secret' => $this->secret,
    ]);

    if ($response->failed()) {
        throw new \Exception('Failed to get FedEx token: ' . $response->body());
    }

    return $response->json()['access_token'];
}

    /**
     * Get rate quotes from FedEx
     */
    public function getRate(array $params)
    {
            $token = $this->getAccessToken();
            $payload = [
            "accountNumber" => [
                "value" => $this->accountNumber // keep your account number here
            ],
            "requestedShipment" => [
                "shipper" => [
                    "contact" => [
                        "personName"  => $params['shipper_name'],
                        "phoneNumber" => $params['shipper_phone'],
                        "companyName" => $params['shipper_company']
                    ],
                    "address" => [
                        "streetLines"          => [$params['shipper_street']],
                        "city"                 => $params['shipper_city'],
                        "stateOrProvinceCode"  => $params['shipper_state'],
                        "postalCode"           => $params['shipper_postal'],
                        "countryCode"          => $params['shipper_country']
                    ]
                ],
                "recipient" => [
                    "contact" => [
                        "personName"  => $params['recipient_name'],
                        "phoneNumber" => $params['recipient_phone'],
                        "companyName" => $params['recipient_company']
                    ],
                    "address" => [
                        "streetLines"         => [$params['recipient_street']],
                        "city"                => $params['recipient_city'],
                        "stateOrProvinceCode" => $params['recipient_state'],
                        "postalCode"          => $params['recipient_postal'],
                        "countryCode"         => $params['recipient_country'],
                        "residential"         => $params['residential'] ?? false
                    ]
                ],
                "pickupType"     => $params['pickup_type'],
                "serviceType"    => $params['service_type'],
                "packagingType"  => 'YOUR_PACKAGING',
                "rateRequestType"=> ["ACCOUNT"],
                "requestedPackageLineItems" => [
                    [
                        "weight" => [
                            "units" => $params['weight_unit'],
                            "value" => $params['weight']
                        ],
                        "dimensions" => [
                            "length" => $params['length'],
                            "width"  => $params['width'],
                            "height" => $params['height'],
                            "units"  => $params['dimension_unit']
                        ]
                    ]
                ]
            ]
        ];
        Log::info("UPS Rate Payload", ['payload' => json_encode($payload, JSON_PRETTY_PRINT)]);
        $response = Http::withToken($token)
            ->post('https://apis-sandbox.fedex.com/rate/v1/rates/quotes', $payload);
       
        if ($response->failed()) {
            throw new \Exception('FedEx Rate API failed: ' . $response->body());
        }

        return $response->json();
    }
    /**
 * Fetch and return the cheapest rate option for FedEx
 */
    public function compareRate(array $params)
    {
        $token = $this->getAccessToken();

        $payload = [
            "accountNumber" => [
                "value" => $this->accountNumber
            ],
            "requestedShipment" => [
                "shipper" => [
                    "contact" => [
                        "personName" => $params['shipper_name'],
                        "phoneNumber" => $params['shipper_phone'],
                        "companyName" => $params['shipper_company']
                    ],
                    "address" => [
                        "streetLines" => [$params['shipper_street']],
                        "city" => $params['shipper_city'],
                        "stateOrProvinceCode" => $params['shipper_state'],
                        "postalCode" => $params['shipper_postal'],
                        "countryCode" => $params['shipper_country']
                    ]
                ],
                "recipient" => [
                    "contact" => [
                        "personName" => $params['recipient_name'],
                        "phoneNumber" => $params['recipient_phone'],
                        "companyName" => $params['recipient_company']
                    ],
                    "address" => [
                        "streetLines" => [$params['recipient_street']],
                        "city" => $params['recipient_city'],
                        "stateOrProvinceCode" => $params['recipient_state'],
                        "postalCode" => $params['recipient_postal'],
                        "countryCode" => $params['recipient_country'],
                        "residential" => $params['residential'] ?? false
                    ]
                ],
                "pickupType" => $params['pickup_type'],
                "rateRequestType" => ["ACCOUNT"],
                "requestedPackageLineItems" => [
                    [
                        "weight" => [
                            "units" => $params['weight_unit'],
                            "value" => $params['weight']
                        ],
                        "dimensions" => [
                            "length" => $params['length'],
                            "width" => $params['width'],
                            "height" => $params['height'],
                            "units" => $params['dimension_unit']
                        ]
                    ]
                ]
            ]
        ];

        Log::info("FedEx Rate Payload", ['payload' => json_encode($payload, JSON_PRETTY_PRINT)]);
        $response = Http::withToken($token)
            ->post($this->baseUrl . '/rate/v1/rates/quotes', $payload);

        if ($response->failed()) {
            throw new \Exception('FedEx Rate API failed: ' . $response->body());
        }

        $fedexRate = $response->json();

        // Extract the cheapest rate from FedEx response
        $cheapest = null;
        $cheapestDetails = null;

        if (isset($fedexRate['output']['rateReplyDetails'])) {
            foreach ($fedexRate['output']['rateReplyDetails'] as $rateDetail) {
                $cost = $rateDetail['ratedShipmentDetails'][0]['totalNetCharge']['amount'] ?? null;
                $days = $rateDetail['transitTime'] ?? 'N/A';
                $type = $rateDetail['serviceType'] ?? 'N/A';

                if ($cost !== null && is_numeric($cost)) {
                    if ($cheapest === null || $cost < $cheapest) {
                        $cheapest = $cost;
                        $cheapestDetails = [
                            'carrier' => 'FedEx',
                            'cost' => $cost,
                            'days' => $days,
                            'type' => $type
                        ];
                    }
                }
            }
        }

        if ($cheapest === null) {
            throw new \Exception('No valid FedEx rates found.');
        }

        return $cheapestDetails;
    }
}
 