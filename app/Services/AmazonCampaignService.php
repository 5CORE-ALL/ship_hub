<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\AmazonSpCampaignReport;
use App\Models\AmazonSbCampaignReport;
use App\Models\AmazonSdCampaignReport;
use App\Services\AmazonCampaignService;
use Throwable;


class AmazonCampaignService
{
    /**
     * Fetch and store campaign reports for all ad types and date ranges.
     */
    public function fetchCampaignReports(string $profileId): void
    {
        $adTypes = [
            'SPONSORED_PRODUCTS' => 'spCampaigns',
            'SPONSORED_BRANDS'   => 'sbCampaigns',
            'SPONSORED_DISPLAY'  => 'sdCampaigns',
        ];

        $today = now();
        $ranges = [
            'L1'  => [$today->copy()->subDay()->toDateString(), $today->copy()->subDay()->toDateString()],
            'L7'  => [$today->copy()->subDays(7)->toDateString(), $today->copy()->subDay()->toDateString()],
            'L15' => [$today->copy()->subDays(15)->toDateString(), $today->copy()->subDay()->toDateString()],
            'L30' => [$today->copy()->subDays(30)->toDateString(), $today->copy()->subDay()->toDateString()],
            'L60' => [$today->copy()->subDays(60)->toDateString(), $today->copy()->subDay()->toDateString()],
        ];

        foreach ($adTypes as $adType => $reportTypeId) {
            foreach ($ranges as $label => [$start, $end]) {
                try {
                    $this->createAndProcessReport($profileId, $adType, $reportTypeId, $start, $end, $label);
                } catch (Throwable $e) {
                    Log::error("❌ {$adType} ({$label}) failed: " . $e->getMessage());
                }
            }
        }
    }
    public function updateReportStatus($report, $profileId)
    {
        $token = $this->getAccessToken();
        $statusResponse = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Amazon-Advertising-API-ClientId' => env('AMAZON_ADS_CLIENT_ID'),
            'Amazon-Advertising-API-Scope' => $profileId,
        ])->get("https://advertising-api.amazon.com/reporting/reports/{$report->report_id}");

        if ($statusResponse->successful()) {
            $status = $statusResponse['status'] ?? 'UNKNOWN';
            $downloadUrl = $statusResponse['location'] ?? null;

            $report->status = $status;
            $report->download_url = $downloadUrl;
            $report->save();

            $this->info("Updated report {$report->report_id}: {$status}");
        } else {
            Log::warn("Failed to fetch report {$report->report_id} status: " . $statusResponse->body());
        }
    }
    private function createAndProcessReport(string $profileId, string $adType, string $reportTypeId, string $startDate, string $endDate, string $rangeKey)
    {
        $token = $this->getAccessToken();
        $reportName = "{$adType}_{$rangeKey}_Campaign";

        $response = Http::withToken($token)
            ->withHeaders([
                'Amazon-Advertising-API-Scope' => $profileId,
                'Amazon-Advertising-API-ClientId' => env('AMAZON_ADS_CLIENT_ID'),
                'Content-Type' => 'application/vnd.createasyncreportrequest.v3+json',
            ])
            ->post('https://advertising-api.amazon.com/reporting/reports', [
                'name' => $reportName,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'configuration' => [
                    'adProduct' => $adType,
                    'groupBy' => ['campaign'],
                    'reportTypeId' => $reportTypeId,
                    'columns' => $this->getAllowedMetricsForAdType($adType),
                    'format' => 'GZIP_JSON',
                    'timeUnit' => 'SUMMARY',
                ]
            ]);

        if (!$response->ok()) {
            throw new \Exception("Report request failed: " . $response->body());
        }

        $reportId = $response->json('reportId');
        $this->waitForReportReady($profileId, $reportId, $adType, $rangeKey);
    }

    private function waitForReportReady(string $profileId, string $reportId, string $adType, string $rangeKey)
    {
        $token = $this->getAccessToken();
        $start = now();
        $timeoutSeconds = 1800;

        while (now()->diffInSeconds($start) < $timeoutSeconds) {
            sleep(120);
            $statusResponse = Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Amazon-Advertising-API-ClientId' => env('AMAZON_ADS_CLIENT_ID'),
                'Amazon-Advertising-API-Scope' => $profileId,
            ])->get("https://advertising-api.amazon.com/reporting/reports/{$reportId}");

            if (!$statusResponse->successful()) continue;

            $status = $statusResponse['status'] ?? 'UNKNOWN';
            Log::info("📊 Report {$reportId} status: {$status}");

            if ($status === 'COMPLETED') {
                $downloadUrl = $statusResponse['location'] ?? null;
                if ($downloadUrl) {
                    $this->downloadAndStoreReport($downloadUrl, $adType, $profileId, $rangeKey);
                }
                return;
            }

            if ($status === 'FAILED') {
                throw new \Exception("Report {$reportId} failed.");
            }
        }

        throw new \Exception("Report {$reportId} timeout after {$timeoutSeconds} seconds.");
    }

    private function downloadAndStoreReport(string $downloadUrl, string $adType, string $profileId, string $rangeKey)
    {
        $response = Http::withoutVerifying()->get($downloadUrl);
        if (!$response->ok()) {
            throw new \Exception("Failed to download report file.");
        }

        $data = json_decode(gzdecode($response->body()), true);
        if (!$data) {
            throw new \Exception("Invalid report content.");
        }

        foreach ($data as $row) {
            $row['profile_id'] = $profileId;
            $row['report_date_range'] = $rangeKey;
            $row['ad_type'] = $adType;

            match ($adType) {
                'SPONSORED_PRODUCTS' => AmazonSpCampaignReport::create($row),
                'SPONSORED_BRANDS'   => AmazonSbCampaignReport::create($row),
                'SPONSORED_DISPLAY'  => AmazonSdCampaignReport::create($row),
                default => null,
            };
        }

        Log::info("✅ Stored " . count($data) . " rows for {$adType} ({$rangeKey})");
    }

    public function getAccessToken(): string
    {
        $response = Http::asForm()->post('https://api.amazon.com/auth/o2/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => env('AMAZON_ADS_REFRESH_TOKEN'),
            'client_id' => env('AMAZON_ADS_CLIENT_ID'),
            'client_secret' => env('AMAZON_ADS_CLIENT_SECRET'),
        ]);

        if (!$response->successful()) {
            throw new \Exception('Amazon Ads token fetch failed: ' . $response->body());
        }

        return $response['access_token'];
    }

    private function getAllowedMetricsForAdType(string $adType): array
    {
        return match ($adType) {
            'SPONSORED_PRODUCTS' => ['impressions', 'clicks', 'cost', 'sales7d', 'campaignId', 'campaignName', 'campaignStatus'],
            'SPONSORED_BRANDS'   => ['impressions', 'clicks', 'cost', 'sales', 'campaignId', 'campaignName', 'campaignStatus'],
            'SPONSORED_DISPLAY'  => ['impressions', 'clicks', 'cost', 'sales', 'campaignId', 'campaignName', 'campaignStatus'],
            default => [],
        };
    }
}
