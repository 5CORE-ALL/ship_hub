<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Order;
class ReverbFulfillmentService
{
    protected string $token;

    public function __construct()
    {
        $this->token = config('services.reverb.token');

        if (!$this->token) {
            Log::error('Reverb API token is missing in configuration.');
        }
    }

    /**
     * Fulfill a Reverb order
     *
     * @param string $orderNumber
     * @param string|null $carrier
     * @param string|null $trackingNumber
     * @param bool $sendNotification
     * @return array|null
     */
    public function fulfillOrder(
        string $orderNumber,
        ?string $carrier = null,
        ?string $trackingNumber = null,
        bool $sendNotification = true
    ): ?array {
     
        if (!$this->token) {
            return null;
        }
        // $order = Order::where('order_number', $orderNumber)->first();
         $order = Order::query()
    ->select('orders.*', 'shipments.tracking_url', 'shipments.tracking_number')
    ->leftJoin('shipments', 'shipments.order_id', '=', 'orders.id')
    ->where('orders.order_number', $orderNumber)
    ->first();
        // if ($order) {
        //     $carrier = $carrier ?? $order->shipping_carrier;
        //     if (!$carrier && isset($order->order_data['shipping']['carrier'])) {
        //         $carrier = $order->order_data['shipping']['carrier'];
        //     }
        // }
       $trackingNumber = $order->tracking_number;
        $carrier = detectCarrier($trackingNumber);
       
        

        $payload = [
            'provider' => $carrier,
            'tracking_number' => $trackingNumber,
            'send_notification' => $sendNotification,
        ];

        Log::info("🔹 Reverb fulfillment request for order {$orderNumber}", [
            'payload' => $payload,
        ]);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/hal+json',
                'Accept' => 'application/hal+json',
                'Accept-Version' => '3.0',
                'Authorization' => 'Bearer ' . $this->token,
            ])->post("https://api.reverb.com/api/my/orders/selling/{$orderNumber}/ship", $payload);

            $responseData = $response->json();

            if ($response->successful()) {
                Log::info("✅ Reverb fulfillment successful for order {$orderNumber}", [
                    'response' => $responseData,
                ]);
                return [
                    'success' => true,
                    'data' => $responseData,
                ];
            }

            Log::error("❌ Reverb fulfillment failed for order {$orderNumber}", [
                'status' => $response->status(),
                'response' => $responseData,
            ]);

            return null;

        } catch (\Exception $e) {
            Log::error("⚠️ Reverb fulfillment exception for order {$orderNumber}: " . $e->getMessage());
            return null;
        }
    }
}
