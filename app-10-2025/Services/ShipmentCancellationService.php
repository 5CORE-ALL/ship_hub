<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\CarrierAccount;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ShipmentCancellationService
{
    /**
     * Cancel a shipment for the specified carrier.
     *
     * @param string $trackingNumber
     * @param string $carrierName The carrier name (e.g., 'fedex')
     * @return array
     * @throws \Exception
     */
    public function cancelShipment(string $trackingNumber, string $carrierName): array
    {
        try {
            $carrierName = strtolower($carrierName);
            if (empty($carrierName)) {
                return [
                    'success' => false,
                    'message' => 'Carrier name is required.'
                ];
            }
            $carrierAccount = CarrierAccount::where('carrier_name', $carrierName)->first();
            if (!$carrierAccount) {
                return [
                    'success' => false,
                    'message' => "Carrier '$carrierName' account not found."
                ];
            }

            $accountNumber = $carrierAccount->account_number;
            $env = strtolower($carrierAccount->api_environment);

            $baseUrl = $env === 'production'
                ? 'https://apis.fedex.com'
                : 'https://apis-sandbox.fedex.com';

            $shipmentService = new ShipmentService($carrierName);
            $token = $shipmentService->getAccessToken();
            $endpoint = $baseUrl . '/ship/v1/shipments/cancel';

            $payload = [
                "accountNumber" => [
                    "value" => $accountNumber
                ],
                "trackingNumber"    => $trackingNumber,
                "emailShipment"     => false,
                "senderCountryCode" => "US",
                "deletionControl"   => "DELETE_ALL_PACKAGES",
                "version1" => [
                    "major" => 1748,
                    "minor" => 9518,
                    "patch" => 4028
                ]
            ];

            $response = Http::withToken($token)
                ->withHeaders([
                    'x-customer-transaction-id' => (string) Str::uuid(),
                    'x-locale' => 'en_US',
                    'Accept'   => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->put($endpoint, $payload);

            Log::info("FedEx Cancel Shipment Response", [$response->json()]);

            if ($response->failed()) {
                $errorMessage = $response->json()['message'] ?? 'Failed to cancel shipment with ' . ucfirst($carrierName);
                throw new \Exception($errorMessage);
            }

            return [
                'success' => true,
                'message' => 'Shipment cancelled with ' . ucfirst($carrierName) . '.',
                'data'    => $response->json()
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to cancel shipment with ' . ucfirst($carrierName) . ': ' . $e->getMessage()
            ];
        }
    }
}
