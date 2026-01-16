<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ShippingRateService
{
    protected ShipStationService $shipStation;
    protected SendleService $sendle;
    protected ShippoService $shippo;

    public function __construct(
        ShipStationService $shipStation,
        SendleService $sendle,
        ShippoService $shippo
    ) {
        $this->shipStation = $shipStation;
        $this->sendle = $sendle;
        $this->shippo = $shippo;
    }
public function getDefaultRate(array $params): array
{
    try {
        Log::info("Starting getDefaultRate", ['params' => $params]);

        $shipStationRates = $sendleRates = $shippoRates = [];

        // Fetch raw rates
        try {
            $shipStationRates = $this->shipStation->getRates($params);
            Log::info("ShipStation rates", [$shipStationRates]);
        } catch (\Exception $e) {
            Log::warning("ShipStation fetch failed: " . $e->getMessage());
        }

        // Sendle temporarily disabled - service unavailable
        // try {
        //     $sendleRates = $this->sendle->getRates($params);
        // } catch (\Exception $e) {
        //     Log::warning("Sendle fetch failed: " . $e->getMessage());
        // }
        $sendleRates = [];

        try {
            $shippoRates = $this->shippo->getRates($params);
        } catch (\Exception $e) {
            Log::warning("Shippo fetch failed: " . $e->getMessage());
        }

        // If absolutely no data came from any API
        if (empty($shipStationRates) && empty($sendleRates) && empty($shippoRates)) {
            return [
                'success' => false,
                'message' => 'No shipping rates available',
                'rates'   => []
            ];
        }

        $normalizedRates = [];
        $maxEta = $params['max_eta_days'] ?? 7;

        // ============================
        // Normalize Rates via PHP
        // ============================

        // ShipStation
        if (isset($shipStationRates['rate_response']['rates']) && is_array($shipStationRates['rate_response']['rates'])) {
            foreach ($shipStationRates['rate_response']['rates'] as $rate) {
                $shipping = $rate['shipping_amount']['amount'] ?? 0;
                $other    = $rate['other_amount']['amount'] ?? 0;
                $eta      = $rate['delivery_days'] ?? null;

                if ($eta !== null && $eta <= $maxEta) {
                    $normalizedRates[] = [
                        'source'   => 'ShipStation',
                        'carrier'  => $rate['carrier_friendly_name'] ?? $rate['carrier_nickname'] ?? 'Unknown',
                        'service'  => $rate['service_type'] ?? $rate['service_code'] ?? 'Unknown',
                        'price'    => $shipping + $other,
                        'currency' => $rate['shipping_amount']['currency'] ?? 'USD',
                        'eta_days' => $eta,
                        'raw'      => $rate,
                        'rate_id'  => $rate['rate_id'] ?? null,
                    ];
                }
            }
        }

        // Sendle temporarily disabled - service unavailable
        // $sendleOptions = $sendleRates['options'] ?? [];
        // if (is_array($sendleOptions)) {
        //     foreach ($sendleOptions as $rate) {
        //         $eta = $rate['estimated_time'] ?? $rate['delivery_estimate'] ?? null;
        //         if ($eta !== null && $eta <= $maxEta) {
        //             $normalizedRates[] = [
        //                 'source'   => 'Sendle',
        //                 'carrier'  => $rate['carrier'] ?? 'Sendle',
        //                 'service'  => $rate['name'] ?? $rate['rate_id'] ?? 'Unknown',
        //                 'price'    => $rate['price'] ?? 0,
        //                 'currency' => $rate['currency'] ?? 'USD',
        //                 'eta_days' => $eta,
        //                 'raw'      => $rate,
        //                 'rate_id'  => $rate['rate_id'] ?? null,
        //             ];
        //         }
        //     }
        // }

        // if (is_array($sendleRates)) {

        //     foreach ($sendleRates as $rate) {
        //         $quote = $rate['quote']['gross']['amount'] ?? null;
        //         $currency = $rate['quote']['gross']['currency'] ?? 'USD';
        //         $etaDays  = $rate['eta']['days_range'][1] ?? null; 

        //         if ($etaDays !== null && $etaDays <= $maxEta) {
        //             $normalizedRates[] = [
        //                 'source'   => 'Sendle',
        //                 'carrier'  => 'Sendle',
        //                 'service'  => $rate['product']['name'] ?? $rate['product']['code'] ?? 'Unknown',
        //                 'price'    => $quote,
        //                 'currency' => $currency,
        //                 'eta_days' => $etaDays,
        //                 'raw'      => $rate,
        //                 'rate_id'  => $rate['product']['code'] ?? null,
        //             ];
        //         }
        //     }
        // }
    
        $shippoOptions = $shippoRates;
        foreach ($shippoOptions as $rate) {
            $eta = $rate['estimated_days'] ?? null;

            if ($eta !== null && $eta <= $maxEta) { // agar filter chahiye
                $normalizedRates[] = [
                    'source'   => 'Shippo',
                    'carrier'  => $rate['provider'] ?? 'Shippo',
                    'service'  => $rate['servicelevel']['name'] ?? 'Unknown',
                    'price'    => (float)($rate['amount'] ?? 0),
                    'currency' => $rate['currency'] ?? 'USD',
                    'eta_days' => $eta,
                    'raw'      => $rate,
                    'rate_id'  => $rate['object_id'] ?? null,
                ];
            }
        }

        usort($normalizedRates, fn($a, $b) => $a['price'] <=> $b['price']);

        $cheapest = $normalizedRates[0] ?? null;

        return [
            'success'       => true,
            'default_rate'  => $cheapest,
            'rates'         => $normalizedRates,
            'cheapest_list' => $normalizedRates, // already sorted ascending
        ];

    } catch (\Exception $e) {
        Log::error("ShippingRateService error: " . $e->getMessage(), ['exception' => $e]);
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'rates'   => [],
        ];
    }
}

    // public function getDefaultRate(array $params): array
    // {
    //     try {
    //         Log::info("Starting getDefaultRate", ['params' => $params]);

    //         $shipStationRates = $sendleRates = $shippoRates = [];

    //         // Fetch raw rates
    //         try { 
    //             $shipStationRates = $this->shipStation->getRates($params); 
    //             log::info($shipStationRates,[$shipStationRates]);
    //         } catch (\Exception $e) { 
    //             Log::warning("ShipStation fetch failed: " . $e->getMessage()); 
    //         }

    //         try { 
    //             $sendleRates = $this->sendle->getRates($params); 
    //         } catch (\Exception $e) { 
    //             Log::warning("Sendle fetch failed: " . $e->getMessage()); 
    //         }

    //         try { 
    //             $shippoRates = $this->shippo->getRates($params); 
    //         } catch (\Exception $e) { 
    //             Log::warning("Shippo fetch failed: " . $e->getMessage()); 
    //         }

    //         // If absolutely no data came from any API
    //         if (empty($shipStationRates) && empty($sendleRates) && empty($shippoRates)) {
    //             return [
    //                 'success' => false,
    //                 'message' => 'No shipping rates available',
    //                 'rates'   => []
    //             ];
    //         }

    //         $openAiKey = config('services.openai.key');
    //         $normalizedRates = [];
    //         $gptCheapest = null;
    //         $maxEta = $params['max_eta_days'] ?? 7;

    //         // ========================
    //         // GPT Normalization FIRST
    //         // ========================
    //         if (!empty($openAiKey) && (!empty($shipStationRates) || !empty($sendleRates) || !empty($shippoRates))) {
    //             $prompt = "Here are raw shipping rates from ShipStation, Sendle, and Shippo:\n" .
    //                       json_encode([
    //                           'shipstation' => $shipStationRates,
    //                           'sendle'      => $sendleRates,
    //                           'shippo'      => $shippoRates
    //                       ]) . "\n\n" .
    //                       "Task: Normalize into JSON with keys:\n" .
    //                       "- rates: array of all rates with keys source, carrier, service, price, currency, eta_days, rate_id\n" .
    //                       "- gpt_cheapest: the cheapest rate object\n" .
    //                       "Rules:\n" .
    //                       "- Include all rates in 'rates'.\n" .
    //                       "- Source must be ShipStation, Sendle, or Shippo.\n" .
    //                       "- Price numeric, currency default USD.\n" .
    //                       "- Exclude rates with eta_days > {$maxEta}.\n" .
    //                       "- Provide rate_id for each rate (use object_id for Shippo).\n" .
    //                       "- Sort rates by price ascending.\n" .
    //                       "- Return JSON only, no extra text.";

    //             try {
    //                 $response = Http::withHeaders([
    //                     'Authorization' => "Bearer $openAiKey",
    //                     'Content-Type'  => 'application/json',
    //                 ])->timeout(120)->post('https://api.openai.com/v1/chat/completions', [
    //                     'model' => 'gpt-4o',
    //                     'messages' => [
    //                         ['role' => 'system', 'content' => 'You are a shipping assistant. Return JSON only.'],
    //                         ['role' => 'user', 'content' => $prompt],
    //                     ],
    //                 ]);
    //                 log::info("gpt response",[$response]);

    //                 if ($response->successful()) {
    //                     $body = $response->json();
    //                     $gptContent = $body['choices'][0]['message']['content'] ?? null;

    //                     if ($gptContent) {
    //                         $gptContent = trim($gptContent);
    //                         $gptContent = preg_replace('/^```json\s*/i', '', $gptContent);
    //                         $gptContent = preg_replace('/```$/', '', $gptContent);

    //                         $decoded = json_decode($gptContent, true);

    //                         if (is_array($decoded)) {
    //                             $normalizedRates = $decoded['rates'] ?? [];
    //                             $gptCheapest     = $decoded['gpt_cheapest'] ?? ($normalizedRates[0] ?? null);
    //                         }
    //                     }
    //                 } else {
    //                     Log::error("GPT API error", [
    //                         'status' => $response->status(),
    //                         'body'   => $response->body()
    //                     ]);
    //                 }
    //             } catch (\Exception $e) {
    //                 Log::error("GPT API call error: " . $e->getMessage());
    //             }
    //         }

    //         // ============================
    //         // FALLBACK: Raw normalization
    //         // ============================
    //         if (empty($normalizedRates)) {
    //             $normalizedRates = [];

    //             // ShipStation
    //             if (isset($shipStationRates['rate_response']['rates']) && is_array($shipStationRates['rate_response']['rates'])) {
    //                 foreach ($shipStationRates['rate_response']['rates'] as $rate) {
    //                     $shipping = $rate['shipping_amount']['amount'] ?? 0;
    //                     $other    = $rate['other_amount']['amount'] ?? 0;
    //                     $eta      = $rate['delivery_days'] ?? null;

    //                     if ($eta !== null && $eta <= $maxEta) {
    //                         $normalizedRates[] = [
    //                             'source'   => 'ShipStation',
    //                             'carrier'  => $rate['carrier_friendly_name'] ?? $rate['carrier_nickname'] ?? 'Unknown',
    //                             'service'  => $rate['service_type'] ?? $rate['service_code'] ?? 'Unknown',
    //                             'price'    => $shipping + $other,
    //                             'currency' => $rate['shipping_amount']['currency'] ?? 'USD',
    //                             'eta_days' => $eta,
    //                             'raw'      => $rate,
    //                             'rate_id'  => $rate['rate_id'] ?? null,
    //                         ];
    //                     }
    //                 }
    //             }

    //             // Sendle
    //             $sendleOptions = $sendleRates['options'] ?? [];
    //             if (is_array($sendleOptions)) {
    //                 foreach ($sendleOptions as $rate) {
    //                     $eta = $rate['delivery_estimate'] ?? null;

    //                     if ($eta !== null && $eta <= $maxEta) {
    //                         $normalizedRates[] = [
    //                             'source'   => 'Sendle',
    //                             'carrier'  => $rate['carrier'] ?? 'Sendle',
    //                             'service'  => $rate['name'] ?? $rate['rate_id'] ?? 'Unknown',
    //                             'price'    => $rate['price'] ?? 0,
    //                             'currency' => $rate['currency'] ?? 'USD',
    //                             'eta_days' => $eta,
    //                             'raw'      => $rate,
    //                             'rate_id'  => $rate['rate_id'] ?? null,
    //                         ];
    //                     }
    //                 }
    //             }

    //             // Shippo
    //             $shippoOptions = $shippoRates['rates'] ?? [];
    //             if (is_array($shippoOptions)) {
    //                 foreach ($shippoOptions as $rate) {
    //                     $eta = $rate['estimated_days'] ?? null;

    //                     if ($eta !== null && $eta <= $maxEta) {
    //                         $normalizedRates[] = [
    //                             'source'   => 'Shippo',
    //                             'carrier'  => $rate['provider'] ?? 'Shippo',
    //                             'service'  => $rate['servicelevel']['name'] ?? 'Unknown',
    //                             'price'    => $rate['amount'] ?? 0,
    //                             'currency' => $rate['currency'] ?? 'USD',
    //                             'eta_days' => $eta,
    //                             'raw'      => $rate,
    //                             'rate_id'  => $rate['object_id'] ?? null,
    //                         ];
    //                     }
    //                 }
    //             }

    //             usort($normalizedRates, fn($a, $b) => $a['price'] <=> $b['price']);
    //             $gptCheapest = $normalizedRates[0] ?? null;
    //         }

    //         // Final cheapest list
    //         $cheapestList = $normalizedRates;
    //         if ($gptCheapest && !in_array($gptCheapest, $cheapestList, true)) {
    //             $cheapestList[] = $gptCheapest;
    //         }
    //         usort($cheapestList, fn($a, $b) => $a['price'] <=> $b['price']);

    //         return [
    //             'success'       => true,
    //             'default_rate'  => $normalizedRates[0] ?? null,
    //             'gpt_cheapest'  => $gptCheapest,
    //             'rates'         => $normalizedRates,
    //             'cheapest_list' => $cheapestList,
    //         ];

    //     } catch (\Exception $e) {
    //         Log::error("ShippingRateService error: " . $e->getMessage(), ['exception' => $e]);
    //         return [
    //             'success' => false,
    //             'message' => $e->getMessage(),
    //             'rates'   => [],
    //         ];
    //     }
    // }
}
