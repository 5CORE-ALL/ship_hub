<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class VtrAnalyticsService
{
    /**
     * Fetch VTR for all eBay stores (prioritizing PROJECTED cycle for US program)
     *
     * @return array
     */
    public function getEbayVTR(): array
    {
        $stores = DB::table('stores as s')
            ->join('sales_channels as sc', 's.sales_channel_id', '=', 'sc.id')
            ->join('marketplaces as m', 's.marketplace_id', '=', 'm.id')
            ->leftJoin('integrations as i', 's.id', '=', 'i.store_id')
            ->where('sc.platform', 'ebay')
            ->select(
                's.id as store_id',
                's.name as store_name',
                'm.name as marketplace_name',
                'i.access_token',
                'i.refresh_token',
                'i.app_id',
                'i.app_secret',
                'i.expires_at'
            )
            ->where('i.store_id', 3) // Specific to store ID 3 as per query
            ->get();

        $results = [];

        foreach ($stores as $store) {
            try {
                $ebayService = app(\App\Services\EbayOrderService::class);
                $accessToken = $ebayService->getAccessToken($store->store_id);

                if (!$accessToken) {
                    $results[] = [
                        'id' => $store->store_id,
                        'marketplace_name' => $store->marketplace_name,
                        'success' => false,
                        'message' => 'Access token unavailable',
                    ];
                    continue;
                }

                $response = Http::withToken($accessToken)
                    ->get('https://api.ebay.com/sell/analytics/v1/seller_standards_profile');

                if ($response->failed()) {
                    $results[] = [
                        'id' => $store->store_id,
                        'marketplace_name' => $store->marketplace_name,
                        'success' => false,
                        'message' => 'Failed to fetch seller standards: ' . $response->body(),
                    ];
                    continue;
                }

                $data = $response->json();
                Log::info('eBay Seller Standards API Response', [
                    'store_id' => $store->store_id,
                    'response' => $data
                ]);

                // Updated to prioritize PROJECTED cycle for US program
                $projectedVtrMetric = null;
                $cycleType = null;
                $selectedProfile = null;

                foreach ($data['standardsProfiles'] ?? [] as $profile) {
                    if (($profile['program'] ?? '') === 'PROGRAM_US' && ($profile['cycle']['cycleType'] ?? '') === 'PROJECTED') {
                        $selectedProfile = $profile;
                        break; // Prioritize PROJECTED US
                    }
                }

                // Fallback to first profile if no projected US found
                if (!$selectedProfile) {
                    $selectedProfile = $data['standardsProfiles'][0] ?? null;
                }

                $vtrMetric = null;
                $cycleType = $selectedProfile['cycle']['cycleType'] ?? 'CURRENT'; // Track cycle type
                if ($selectedProfile && !empty($selectedProfile['metrics'])) {
                    foreach ($selectedProfile['metrics'] as $metric) {
                        if (($metric['metricKey'] ?? null) === 'VALID_TRACKING_UPLOADED_WITHIN_HANDLING_RATE') {
                            $vtrMetric = $metric;
                            break;
                        }
                    }
                }

                if (!$vtrMetric) {
                    $results[] = [
                        'id' => $store->store_id,
                        'marketplace_name' => $store->marketplace_name,
                        'success' => false,
                        'message' => 'VTR metric not found (projected or current)',
                        'data' => $data,
                    ];
                    continue;
                }

                $vtrValue = $vtrMetric['value']['value'] ?? null;
                $numerator = $vtrMetric['value']['numerator'] ?? null;
                $denominator = $vtrMetric['value']['denominator'] ?? null;
                $thresholdLower = $vtrMetric['thresholdLowerBound']['value'] ?? null;
                $periodStart = $vtrMetric['lookbackStartDate'] ?? null;
                $periodEnd = $vtrMetric['lookbackEndDate'] ?? null;

                $results[] = [
                    'id' => $store->store_id,
                    'marketplace_name' => $store->marketplace_name . " ({$cycleType})", // e.g., eBay US (PROJECTED)
                    'total_orders' => $denominator,
                    'valid_tracking' => $numerator,
                    'invalid_tracking' => $denominator ? ($denominator - $numerator) : 0,
                    'valid_tracking_rate' => $vtrValue,
                    'allowed_rate' => $thresholdLower,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'cycle_type' => $cycleType, // For DB uniqueness
                    'success' => true,
                ];

            } catch (\Exception $e) {
                $results[] = [
                    'id' => $store->store_id,
                    'marketplace_name' => $store->marketplace_name,
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return ['data' => $results];
    }
}