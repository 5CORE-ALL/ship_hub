<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\VtrAnalyticsService;
use App\Services\WalmartFulfillmentService;
use App\Models\MarketplaceTrackingStat;
use Illuminate\Support\Facades\Log;
use App\Services\AmazonOrderService;
class FetchEbayVTRCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ebay:vtr-fetch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch Valid Tracking Rate (VTR) for all eBay and Walmart stores';

    /**
     * Execute the console command.
     */
    public function handle(VtrAnalyticsService $vtrService, WalmartFulfillmentService $walmartService,AmazonOrderService $amazonService)
    {
        $this->info('🚀 Fetching eBay Valid Tracking Rate...');

        try {
            $ebayResult = $vtrService->getEbayVTR();

            // Log the results
            Log::info('✅ eBay VTR fetch completed', $ebayResult);

            $ebaySavedCount = 0;
            $ebayUpdatedCount = 0;

            // Display in console and save/update in table for eBay
            foreach ($ebayResult['data'] as $storeResult) {
                $status = $storeResult['success'] ? '✅' : '❌';
                $vtr = $storeResult['valid_tracking_rate'] ?? 'N/A';
                $this->line("{$status} eBay Store ID: {$storeResult['id']} | Marketplace: {$storeResult['marketplace_name']} | VTR: {$vtr}%");

                if ($storeResult['success']) {
                    // Save or update in marketplace_tracking_stats table
                    $map = [
                        3 => 1,
                        4 => 2,
                        5 => 3,
                    ];
                    $storeId = $storeResult['id'];
                    $mappedId = $map[$storeId] ?? $storeId;
                    $customMarketplace = 'ebay' . $mappedId;

                    $stat = MarketplaceTrackingStat::updateOrCreate(
                        [
                            'marketplace_name' => $customMarketplace
                        ],
                        [
                            'total_orders' => $storeResult['total_orders'],
                            'valid_tracking' => $storeResult['valid_tracking'],
                            'invalid_tracking' => $storeResult['invalid_tracking'],
                            'valid_tracking_rate' => $storeResult['valid_tracking_rate'],
                            'allowed_rate' => $storeResult['allowed_rate'],
                        ]
                    );

                    if ($stat->wasRecentlyCreated) {
                        $ebaySavedCount++;
                    } else {
                        $ebayUpdatedCount++;
                    }
                }
            }

            $this->info("✅ eBay: Saved: {$ebaySavedCount} | Updated: {$ebayUpdatedCount} records in marketplace_tracking_stats.");
        } catch (\Exception $e) {
            Log::error('❌ Error while fetching eBay VTR: ' . $e->getMessage());
            $this->error('eBay Error: ' . $e->getMessage());
        }

        $this->info('🚀 Fetching Walmart Valid Tracking Rate...');

        try {
            $vtrResult = $walmartService->getVTR(30); // 30 days

            if (!$vtrResult['success']) {
                $this->error('Walmart VTR Error: ' . ($vtrResult['error'] ?? 'Unknown error'));
                return 1;
            }

            // Log the results
            Log::info('✅ Walmart VTR fetch completed', $vtrResult);

            $walmartSavedCount = 0;
            $walmartUpdatedCount = 0;

            // Process single Walmart result (hardcoded marketplace)
            $storeResult = $vtrResult['data']; // From service response
            $status = $vtrResult['success'] ? '✅' : '❌';
            $vtr = $storeResult['valid_tracking_rate'] ?? 'N/A';
            $this->line("{$status} Walmart | Marketplace: walmart | VTR: {$vtr}%");

            if ($vtrResult['success']) {
                $customMarketplace = 'walmart'; // Hardcoded

                $stat = MarketplaceTrackingStat::updateOrCreate(
                    [
                        'marketplace_name' => $customMarketplace
                    ],
                    [
                        'total_orders' => $storeResult['total_orders'],
                        'valid_tracking' => $storeResult['valid_tracking'],
                        'invalid_tracking' => $storeResult['invalid_tracking'],
                        'valid_tracking_rate' => $storeResult['valid_tracking_rate'],
                        'allowed_rate' => $storeResult['allowed_rate'],
                    ]
                );

                if ($stat->wasRecentlyCreated) {
                    $walmartSavedCount++;
                } else {
                    $walmartUpdatedCount++;
                }
            }

            $this->info("✅ Walmart: Saved: {$walmartSavedCount} | Updated: {$walmartUpdatedCount} records in marketplace_tracking_stats.");
            $this->info('✅ Completed fetching VTR for all eBay and Walmart stores.');
        } catch (\Exception $e) {
            Log::error('❌ Error while fetching Walmart VTR: ' . $e->getMessage());
            $this->error('Walmart Error: ' . $e->getMessage());
        }
          $this->info('🚀 Fetching Amazon Valid Tracking Rate...');
          try {
    // Fetch Amazon Seller Performance Report
    $amazonResult = $amazonService->getSellerPerformanceReport();

    if (empty($amazonResult['raw_content'])) {
        $this->error('Amazon VTR Error: No data returned');
    } else {
        $reportData = json_decode($amazonResult['raw_content'], true);

        $validTrackingRate = $reportData['performanceMetrics'][0]['validTrackingRate']['rate'] ?? null;
        $shipmentCount = $reportData['performanceMetrics'][0]['validTrackingRate']['shipmentCount'] ?? null;
        $allowedRatePercent = isset($reportData['performanceMetrics'][0]['validTrackingRate']['targetValue'])
            ? round($reportData['performanceMetrics'][0]['validTrackingRate']['targetValue'] * 100, 2)
            : 95.0; // fallback if missing

        $vtrPercent = $validTrackingRate !== null ? round($validTrackingRate * 100, 2) : 0;

        $this->line("✅ Amazon | VTR: " . ($validTrackingRate !== null ? $vtrPercent . '%' : 'N/A'));

        MarketplaceTrackingStat::updateOrCreate(
            ['marketplace_name' => 'amazon'],
            [
                'total_orders' => $shipmentCount ?? 0,
                'valid_tracking' => $shipmentCount !== null && $validTrackingRate !== null ? round($shipmentCount * $validTrackingRate) : 0,
                'invalid_tracking' => $shipmentCount !== null && $validTrackingRate !== null ? round($shipmentCount * (1 - $validTrackingRate)) : 0,
                'valid_tracking_rate' => $vtrPercent,
                'allowed_rate' => $allowedRatePercent,
            ]
        );
    }
} catch (\Exception $e) {
    Log::error('❌ Amazon VTR error: ' . $e->getMessage());
    $this->error('Amazon Error: ' . $e->getMessage());
}
        // try {
        //     // Assuming your Amazon service has a method returning structured VTR data
        //     $amazonResult = $amazonService->getSellerPerformanceReport(); 

        //     if (empty($amazonResult['raw_content'])) {
        //         $this->error('Amazon VTR Error: No data returned');
        //     } else {
        //         $reportData = json_decode($amazonResult['raw_content'], true);

        //         // Extract "validTrackingRate" safely from report
        //         $validTrackingRate = $reportData['performanceMetrics'][0]['validTrackingRate']['rate'] ?? null;
        //         $shipmentCount = $reportData['performanceMetrics'][0]['validTrackingRate']['shipmentCount'] ?? null;

        //         $this->line("✅ Amazon | VTR: " . ($validTrackingRate !== null ? round($validTrackingRate * 100, 2) . '%' : 'N/A'));

        //         MarketplaceTrackingStat::updateOrCreate(
        //             ['marketplace_name' => 'amazon'],
        //             [
        //                 'total_orders' => $shipmentCount ?? 0,
        //                 'valid_tracking' => $shipmentCount !== null && $validTrackingRate !== null ? round($shipmentCount * $validTrackingRate) : 0,
        //                 'invalid_tracking' => $shipmentCount !== null && $validTrackingRate !== null ? round($shipmentCount * (1 - $validTrackingRate)) : 0,
        //                 'valid_tracking_rate' => round($validTrackingRate * 100, 2),
        //                 'allowed_rate' => 0.95, // default allowed rate
        //             ]
        //         );
        //     }
        // } catch (\Exception $e) {
        //     Log::error('❌ Amazon VTR error: ' . $e->getMessage());
        //     $this->error('Amazon Error: ' . $e->getMessage());
        // }

        return 0;
    }
}