<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Repositories\Contracts\ShippingRepositoryInterface;
use Exception;

class SendleRepository implements ShippingRepositoryInterface
{
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('services.sendle.base_url', 'https://api.sendle.com/v1');
        $this->apiKey  = config('services.sendle.api_key');
    }

    protected function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization'=> 'Basic ' . base64_encode($this->apiKey . ':'),
        ];
    }

    public function getCarriers()
    {
        // Sendle doesn't have carriers endpoint like ShipStation, so return basic info or supported services
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/products");

        if ($response->failed()) {
            throw new Exception("Sendle products API failed: " . $response->body());
        }

        return $response->json();
    }

    public function getRates(array $params)
    {
        if (empty($params['from']) || empty($params['to']) || empty($params['weight'])) {
            throw new Exception("Missing required parameters: from, to, weight");
        }

        $payload = [
            "from" => $params['from'], // ['suburb'=>'Sydney', 'postcode'=>'2000', 'country'=>'AU']
            "to"   => $params['to'],   // ['suburb'=>'Melbourne', 'postcode'=>'3000', 'country'=>'AU']
            "parcels" => [
                [
                    "weight" => [
                        "value" => $params['weight'], // kg
                        "unit"  => "kg"
                    ]
                ]
            ],
            "product" => $params['product'] ?? "STANDARD",
        ];

        Log::info("Sendle getRates payload", [$payload]);

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/quotes", $payload);

        if ($response->failed()) {
            throw new Exception("Sendle rates API failed: " . $response->body());
        }

        return $response->json();
    }

    public function createLabelByRateId(string $rateId)
    {
        // Sendle uses a booking endpoint
        $payload = [
            "quote_id" => $rateId,
            "sender"   => [], // pass sender details if needed
            "recipient"=> [], // pass recipient details
            "parcels"  => []  // pass parcel details
        ];

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/bookings", $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'error'   => $response->json()
            ];
        }

        return [
            'success'        => true,
            'label_id'       => $response->json('id'),
            'trackingNumber' => $response->json('tracking_number'),
            'labelUrl'       => $response->json('label_url'),
            'raw'            => $response->json()
        ];
    }

    public function voidLabel(string $labelId)
    {
        $response = Http::withHeaders($this->headers())
            ->delete("{$this->baseUrl}/bookings/{$labelId}");

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
}
