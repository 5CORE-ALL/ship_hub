<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AmazonReport;
use App\Models\AmazonCampaignReport; 
use App\Services\AmazonCampaignService;
use App\Models\CampaignReport;
class ProcessAmazonReports extends Command
{
    protected $signature = 'amazon:process-reports';
    protected $description = 'Check pending Amazon reports and process completed reports into campaign tables';
    protected AmazonCampaignService $service;
    public function __construct(AmazonCampaignService $service)
    {
        parent::__construct();
        $this->service = $service;
    }
    public function handle()
    {
        $profileId = env('AMAZON_ADS_PROFILE_IDS');

        Log::info("Starting Amazon Reports Processing");
        $pendingReports = AmazonReport::whereIn('status', ['PENDING', 'IN_PROGRESS'])->get();
         Log::info("Pending reports count: " . $pendingReports->count());

        foreach ($pendingReports as $report) {
            try {
                Log::info("Processing pending report: {$report}, status: {$report->status}");
                $this->updateReportStatus($report, $profileId);
            } catch (\Throwable $e) {
                Log::error("Failed to update report {$report->report_id}: " . $e->getMessage());
            }
        }

        // 2️⃣ Process completed but unprocessed reports
        $readyReports = AmazonReport::where('status', 'COMPLETED')
            ->where('is_processed', 0)
            ->get();
        foreach ($readyReports as $report) {
            try {
                $this->processReportData($report, $profileId);
            } catch (\Throwable $e) {
                Log::error("Failed to process report {$report->report_id}: " . $e->getMessage());
            }
        }

        $this->info("✅ Amazon reports processing finished.");
    }
    public function updateReportStatus($report, $profileId)
    {
     
        $token = $this->service->getAccessToken();
        $statusResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Amazon-Advertising-API-ClientId' => env('AMAZON_ADS_CLIENT_ID'),
            'Amazon-Advertising-API-Scope' => $profileId,
        ])->get("https://advertising-api.amazon.com/reporting/reports/{$report->report_id}");

        if ($statusResponse->successful()) {

              Log::info("Report ID {$report->report_id} status fetched successfully.", [
        'status' => $statusResponse->status(),
        'body' => $statusResponse->body()
    ]);
            $status = $statusResponse['status'] ?? 'UNKNOWN';
            $downloadUrl = $statusResponse['url'] ?? null;

            $report->status = $status;
            $report->download_url = $downloadUrl;
            $report->save();

            $this->info("Updated report {$report->report_id}: {$status}");
        } else {
            Log::warn("Failed to fetch report {$report->report_id} status: " . $statusResponse->body());
        }
    }


    protected function processReportData($report, $profileId)
    {
        if (!$report->download_url) {
            Log::warning("Report {$report->report_id} has no download URL.");
            return;
        }
        $response = Http::withoutVerifying()->get($report->download_url);


        if (!$response->ok()) {
            throw new \Exception("Failed to download report {$report->report_id}");
        }
        $jsonData = gzdecode($response->body());
        $rows = json_decode($jsonData, true);
        if (!is_array($rows) || count($rows) === 0) {
            Log::warning("Report {$report->report_id} has no rows to process.");
            return;
        }

        foreach ($rows as $row) {
         
             Log::info("Processing Amazon report row:", $row);
       $data = [
            'marketplace' => 'amazon',
            'profile_id' => $profileId,
            'campaign_id' => $row['campaignId'] ?? null,
            'campaign_name' => $row['campaignName'] ?? null,
            'ad_type' => $report->ad_product,
            'report_date_range' => $report->name ?? 'L1',
            'start_date' => $row['startDate'] ?? $report->start_date,
            'end_date' => $row['endDate'] ?? $report->end_date,
            'impressions' => $row['impressions'] ?? 0,
            'clicks' => $row['clicks'] ?? 0,
            'cost' => $row['cost'] ?? 0,
            'spend' => $row['spend'] ?? 0,
            'sales' => $row['sales1d'] ?? 0,
            'orders' => $row['purchases1d'] ?? 0,
            'cost_per_click' => $row['costPerClick'] ?? 0,
            'campaign_status' => $row['campaignStatus'] ?? null,
            'campaign_budget_amount' => $row['campaignBudgetAmount'] ?? 0,
            'campaign_budget_currency_code' => $row['campaignBudgetCurrencyCode'] ?? 'USD',
        ];


               CampaignReport::updateOrCreate(
                [
                    'campaign_id' => $data['campaign_id'],
                    'profile_id' => $profileId,
                    'start_date' => $data['start_date'],
                ],
                $data
            );
        }
        $report->is_processed = 1;
        $report->save();

        Log::info("Processed report {$report->report_id}, rows: " . count($rows));
    }


}
