<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\CarriersList;
use App\Models\Order;
use App\Repositories\Contracts\ShippingRepositoryInterface;
use Exception;

class ShipStationRepository implements ShippingRepositoryInterface
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

    public function getCarriers()
    {
        $response = Http::withHeaders($this->headers())
            ->get("{$this->baseUrl}/carriers");

        if ($response->failed()) {
            throw new Exception("ShipStation carriers API failed: " . $response->body());
        }

        return $response->json();
    }

    public function getRates(array $params)
    {
        $allCarrierIds = CarriersList::pluck('carrier_id')->toArray();
        $order = Order::with('cost')->find($params['order_id']);
        if (!$order) {
            throw new \Exception("Order not found");
        }
        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/rates", $payload);

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

        $response = Http::withHeaders($this->headers())
            ->post("{$this->baseUrl}/labels/rates/{$rateId}", $payload);

        if ($response->failed()) {
            return [
                'success' => false,
                'error'   => $response->json()
            ];
        }

        return [
            'success'        => true,
            'label_id'       => $response->json('label_id'),
            'trackingNumber' => $response->json('tracking_number'),
            'labelUrl'       => $response->json('label_download.href'),
            'raw'            => $response->json()
        ];
    }

    public function voidLabel(string $labelId)
    {
        $response = Http::withHeaders($this->headers())
            ->put("{$this->baseUrl}/labels/{$labelId}/void");

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
