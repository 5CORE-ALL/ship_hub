<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AmazonReport;
use App\Services\AmazonCampaignService;
use App\Models\CommandLog;

class FetchAmazonReports extends Command
{
    protected $signature = 'amazon:fetch-reports';
    protected $description = 'Create Amazon Advertising async reports for SP, SB, SD';
    protected AmazonCampaignService $service;

    public function __construct(AmazonCampaignService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    protected $logFilePath;
    protected $relativeLogPath;
    protected $log;

    public function handle()
    {
        $startTime = microtime(true);

        $this->setupLogging();
        $this->writeLog("=== Amazon Report Fetch Started ===");
        $this->writeLog("Time: " . now());

        try {
            $profileId = env('AMAZON_ADS_PROFILE_IDS');

            $adTypes = [
                'SPONSORED_PRODUCTS' => 'spCampaigns',
                'SPONSORED_BRANDS'   => 'sbCampaigns',
                'SPONSORED_DISPLAY'  => 'sdCampaigns',
            ];

            $yesterday = now()->subDay()->toDateString();
            foreach ($adTypes as $adType => $reportTypeId) {
                $this->fetchReport($profileId, $adType, $reportTypeId, $yesterday);
            }

            $this->updateCommandLog('success', $startTime);
            $this->writeLog("=== Completed Successfully ===");
            $this->info("Amazon reports created successfully!");

        } catch (\Throwable $e) {
            $this->handleError($e);
        }
    }

    private function fetchReport($profileId, $adType, $reportTypeId, $date)
    {
        $this->writeLog("Requesting report: {$adType} | {$reportTypeId}");

        $token = $this->service->getAccessToken();
        if (!$token) {
            $this->writeLog("❌ Access token fetch failed.");
            return;
        }

        $reportName = "{$adType}_{$date}_Campaign";
        $exists = AmazonReport::where('ad_product', $adType)
        ->where('start_date', $date)
        ->exists();

        if ($exists) {
            $this->writeLog("⏭️ Report already exists for {$adType} on {$date} → Skipping API call.");
            return; // Don't request again
        }

        $response = Http::withToken($token)
            ->withHeaders([
                'Amazon-Advertising-API-Scope' => $profileId,
                'Amazon-Advertising-API-ClientId' => env('AMAZON_ADS_CLIENT_ID'),
                'Content-Type' => 'application/vnd.createasyncreportrequest.v3+json',
            ])
            ->post('https://advertising-api.amazon.com/reporting/reports', [
                'name' => $reportName,
                'startDate' => $date,
                'endDate' => $date,
                'configuration' => [
                    'adProduct' => $adType,
                    'groupBy' => ['campaign'],
                    'reportTypeId' => $reportTypeId,
                    'columns' => $this->getAllowedMetricsForAdType($adType),
                    'format' => 'GZIP_JSON',
                    'timeUnit' => 'SUMMARY',
                ]
            ]);

        $this->writeLog("API Response: " . $response->body());
 

        // failed
        if (!$response->ok()) {
            $this->writeLog("❌ Report request failed");
            return;
        }

        // duplicate (425)
        if ($response->status() === 425) {
            $body = $response->json();
            if (!empty($body['detail']) && preg_match('/([0-9a-f\-]{36})/', $body['detail'], $m)) {
                $reportId = $m[1];
                $this->writeLog("Duplicate report → Using existing: {$reportId}");

                $this->storeReport($reportId, $adType, $reportTypeId, $date, $date);
                return;
            }
        }

        // success → save
        $reportId = $response['reportId'] ?? null;

        $this->writeLog("✔ Report created: {$reportId}");

        $this->storeReport($reportId, $adType, $reportTypeId, $date, $date);
    }

    private function storeReport($reportId, $adType, $reportType, $start, $end)
    {
        AmazonReport::updateOrCreate(
            ['report_id' => $reportId],
            [
                'ad_product' => $adType,
                'report_type' => $reportType,
                'start_date' => $start,
                'end_date' => $end,
                'status' => 'IN_PROGRESS',
                'processed' => 0
            ]
        );

        $this->writeLog("Inserted into DB: {$reportId}");
    }

    private function setupLogging()
    {
        $this->relativeLogPath = '/logs/amazon_reports/amazon_reports_' . now()->format('Y_m_d_His') . '.log';
        $this->logFilePath = storage_path('app/public' . $this->relativeLogPath);

        if (!is_dir(dirname($this->logFilePath))) {
            mkdir(dirname($this->logFilePath), 0775, true);
        }

        $this->log = CommandLog::create([
            'command' => $this->signature,
            'status' => 'running',
            'log_file' => $this->relativeLogPath,
            'started_at' => now()
        ]);
    }

    private function writeLog($msg)
    {
        file_put_contents($this->logFilePath, '[' . now() . '] ' . $msg . PHP_EOL, FILE_APPEND);
    }

    private function updateCommandLog($status, $startTime)
    {
        $this->log->update([
            'status' => $status,
            'completed_at' => now(),
            'duration' => round(microtime(true) - $startTime, 2)
        ]);
    }

    private function handleError(\Throwable $e)
    {
        $this->writeLog("❌ Exception: " . $e->getMessage());
        $this->writeLog($e->getTraceAsString());

        $this->log->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'completed_at' => now(),
        ]);

        $this->error("Command failed: " . $e->getMessage());
    }
    private function getAllowedMetricsForAdType(string $adType): array
    {
  
        return match($adType) {
            'SPONSORED_PRODUCTS' => [
                'impressions', 'clicks', 'cost', 'spend', 'purchases1d', 'purchases7d',
                'purchases14d', 'purchases30d', 'sales1d', 'sales7d', 'sales14d', 'sales30d',
                'unitsSoldClicks1d', 'unitsSoldClicks7d', 'unitsSoldClicks14d', 'unitsSoldClicks30d',
                'attributedSalesSameSku1d', 'attributedSalesSameSku7d', 'attributedSalesSameSku14d', 'attributedSalesSameSku30d',
                'unitsSoldSameSku1d', 'unitsSoldSameSku7d', 'unitsSoldSameSku14d', 'unitsSoldSameSku30d',
                'clickThroughRate', 'costPerClick', 'qualifiedBorrows', 'addToList',
                'campaignId', 'campaignName', 'campaignBudgetAmount', 'campaignBudgetCurrencyCode',
                'royaltyQualifiedBorrows', 'purchasesSameSku1d', 'purchasesSameSku7d', 'purchasesSameSku14d', 
                'purchasesSameSku30d', 'kindleEditionNormalizedPagesRead14d', 'kindleEditionNormalizedPagesRoyalties14d', 'campaignBiddingStrategy', 'startDate', 'endDate', 'campaignStatus',
            ],
            'SPONSORED_BRANDS' => [
                'addToCart', 'addToCartClicks', 'addToCartRate', 'addToList', 'addToListFromClicks', 'qualifiedBorrows', 'qualifiedBorrowsFromClicks', 
                'royaltyQualifiedBorrows', 'royaltyQualifiedBorrowsFromClicks', 'impressions', 'clicks', 'cost', 'sales', 'salesClicks', 'purchases', 'purchasesClicks',
                'brandedSearches', 'brandedSearchesClicks', 'newToBrandSales', 'newToBrandSalesClicks', 'newToBrandPurchases', 'newToBrandPurchasesClicks',
                'campaignBudgetType', 'videoCompleteViews', 'videoUnmutes', 'viewabilityRate', 'viewClickThroughRate',
                'detailPageViews', 'detailPageViewsClicks', 'eCPAddToCart', 'newToBrandDetailPageViewRate', 'newToBrandDetailPageViews', 
                'newToBrandDetailPageViewsClicks', 'newToBrandECPDetailPageView', 'newToBrandPurchasesPercentage', 'newToBrandUnitsSold', 'newToBrandUnitsSoldClicks',
                'newToBrandUnitsSoldPercentage', 'unitsSold', 'unitsSoldClicks', 'topOfSearchImpressionShare', 'newToBrandPurchasesRate',
                'campaignId', 'campaignName', 'campaignBudgetAmount', 'campaignBudgetCurrencyCode', 'newToBrandSalesPercentage',
                'campaignStatus', 'salesPromoted', 'video5SecondViewRate', 'video5SecondViews', 'videoFirstQuartileViews', 'videoMidpointViews', 
                'videoThirdQuartileViews', 'viewableImpressions', 'startDate', 'endDate'
            ],
            
            'SPONSORED_DISPLAY' => [
                'addToCart', 'addToCartClicks', 'addToCartRate', 'addToCartViews', 'addToList', 'addToListFromClicks', 'addToListFromViews', 'qualifiedBorrows', 
                'qualifiedBorrowsFromClicks', 'qualifiedBorrowsFromViews', 'royaltyQualifiedBorrows', 'royaltyQualifiedBorrowsFromClicks', 
                'royaltyQualifiedBorrowsFromViews', 'brandedSearches', 'brandedSearchesClicks', 'brandedSearchesViews', 'brandedSearchRate', 
                'campaignBudgetCurrencyCode', 'campaignId', 'campaignName', 'clicks', 'cost', 'detailPageViews', 'detailPageViewsClicks', 'eCPAddToCart', 
                'eCPBrandSearch', 'impressions', 'impressionsViews', 'newToBrandPurchases', 'newToBrandPurchasesClicks', 'newToBrandSalesClicks', 
                'newToBrandUnitsSold', 'newToBrandUnitsSoldClicks', 'purchases', 'purchasesClicks', 'purchasesPromotedClicks', 'sales', 'salesClicks', 
                'salesPromotedClicks', 'unitsSold', 'unitsSoldClicks', 'videoCompleteViews', 'videoFirstQuartileViews', 'videoMidpointViews', 
                'videoThirdQuartileViews', 'videoUnmutes', 'viewabilityRate', 'viewClickThroughRate', 'startDate', 'endDate', 'campaignStatus' 
            ],
            default => []
        };
     }
}
