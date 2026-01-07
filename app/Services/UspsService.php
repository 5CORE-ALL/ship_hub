<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Exception;

class UspsService
{
    protected $clientId;
    protected $clientSecret;
    protected $baseUrl;

    public function __construct()
    {
        $this->clientId = env('USPS_CLIENT_ID');
        $this->clientSecret = env('USPS_CLIENT_SECRET');
        $this->baseUrl = 'https://apis.usps.com';
    }

    /**
     * Get a valid USPS access token (cached for 8 hours)
     */
    // public function getAccessToken()
    // {
    //     return Cache::remember('usps_access_token', 60 * 8, function () {
    //         $response = Http::withHeaders([
    //             'Content-Type' => 'application/json',
    //         ])->post($this->baseUrl . '/oauth2/v3/token', [
    //             'grant_type' => 'client_credentials',
    //             'client_id' => $this->clientId,
    //             'client_secret' => $this->clientSecret,
    //         ]);

    //         if ($response->failed()) {
    //             throw new Exception('USPS OAuth failed: ' . $response->body());
    //         }

    //         return $response->json()['access_token'];
    //     });
    // }
public function getAccessToken()
{
    return Cache::remember('usps_access_token', 60 * 8, function () {
        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/oauth2/v3/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret
        ]);

        if ($response->failed()) {
            throw new \Exception('USPS OAuth failed: ' . $response->body());
        }

        return $response->json()['access_token'];
    });
}
    /**
     * Get USPS shipping rates (all available services)
     */
    public function getRates(array $from, array $to, array $parcel)
    {
        $token = $this->getAccessToken();
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/prices/v3/rates', [
            'rateOptions' => [
                'carrier' => 'usps',
                'returnTransitTimes' => true,
            ],
            'requestedShipment' => [
                'shipper' => ['address' => $from],
                'recipient' => ['address' => $to],
                'parcel' => $parcel,
            ],
        ]);

        if ($response->failed()) {
            throw new Exception('USPS Rate fetch failed: ' . $response->body());
        }

        return $response->json();
    }
    // public function getAllServiceRates($originZip, $destinationZip, $weight, $length, $width, $height)
    // {
    //     $token = $this->getAccessToken();

    //     $mailClasses = [
    //         'PRIORITY_MAIL',
    //         'PRIORITY_MAIL_EXPRESS',
    //         'USPS_GROUND_ADVANTAGE',
    //         'FIRST-CLASS_PACKAGE_SERVICE',
    //         'PARCEL_SELECT',
    //         'MEDIA_MAIL',
    //         'LIBRARY_MAIL',
    //         'BOUND_PRINTED_MATTER',
    //     ];

    //     $basePayload = [
    //         'destinationEntryFacilityType' => 'NONE',
    //         'originZIPCode' => $originZip,
    //         'destinationZIPCode' => $destinationZip,
    //         'rateIndicator' => 'P8',
    //         'priceType' => 'RETAIL', 
    //         'processingCategory' => 'MACHINABLE',
    //         'weight' => $weight,
    //         'length' => $length,
    //         'width' => $width,
    //         'height' => $height,
    //         'mailingDate' => now()->toDateString(),
    //     ];

    //     $results = [];

    //     foreach ($mailClasses as $mailClass) {
    //         $payload = array_merge($basePayload, ['mailClass' => $mailClass]);

    //         $response = Http::withToken($token)
    //             ->acceptJson()
    //             ->post($this->baseUrl . '/prices/v3/base-rates/search', $payload);

    //         $results[$mailClass] = $response->json();
    //     }

    //     return $results;
    // }
   public function getAllServiceRates($originZip, $destinationZip, $weight, $length, $width, $height)
{
    $token = $this->getAccessToken();

    // $mailClasses = [
    //     'USPS_GROUND_ADVANTAGE',
    //     'PRIORITY_MAIL',
    //     'PRIORITY_MAIL_EXPRESS',
    //     'FIRST-CLASS_PACKAGE_SERVICE',
    //     'PARCEL_SELECT',
    //     'MEDIA_MAIL',
    //     'LIBRARY_MAIL',
    //     'BOUND_PRINTED_MATTER',
    // ];
 $mailClasses = [
    'PARCEL_SELECT',
    'PRIORITY_MAIL_EXPRESS',
    'PRIORITY_MAIL',
    'USPS_CONNECT_LOCAL',
    'USPS_CONNECT_MAIL',
    'USPS_CONNECT_REGIONAL',
    'USPS_GROUND_ADVANTAGE',
    'LIBRARY_MAIL',
    'MEDIA_MAIL'
  ];

    $results = [];
    $today = now()->toDateString();

    // Use standard dimensions (8x6x2) for USPS weight calculation
    $standardLength = 8;
    $standardWidth = 6;
    $standardHeight = 2;

    foreach ($mailClasses as $mailClass) {
        $payload = [
            "destinationEntryFacilityType" => "NONE",
            "originZIPCode"                => $originZip,
            "destinationZIPCode"           => $destinationZip,
            "mailClass"                    => $mailClass,
            "rateIndicator"                => "SP",              // ✅ updated as per your sample
            "priceType"                    => "COMMERCIAL",      // ✅ updated as per your sample
            "processingCategory"           => "MACHINABLE",
            "weight"                       => (float) $weight,
            "length"                       => (float) $standardLength,
            "width"                        => (float) $standardWidth,
            "height"                       => (float) $standardHeight,
            "mailingDate"                  => $today,
        ];

        $response = Http::withToken($token)
            ->acceptJson()
            ->post($this->baseUrl . '/prices/v3/base-rates/search', $payload);

        if ($response->failed()) {
            $results[$mailClass] = [
                'success' => false,
                'error'   => $response->json() ?? $response->body(),
            ];
        } else {
            $results[$mailClass] = [
                'success' => true,
                'data'    => $response->json(),
            ];
        }
    }

    return $results;
}

}
