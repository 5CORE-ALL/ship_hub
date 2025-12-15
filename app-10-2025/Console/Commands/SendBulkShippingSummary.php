<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BulkShippingHistory;
use App\Services\BulkShippingSummaryService;

class SendBulkShippingSummary extends Command
{
    protected $signature = 'bulk:send-summary';
    protected $description = 'Send bulk shipping summary emails for all unsent BulkShippingHistory records and mark them as sent.';

    public function handle()
    {
        $histories = BulkShippingHistory::where('mail_sent', 0)
            ->orderByDesc('id')
            ->get();

        if ($histories->isEmpty()) {
            $this->warn('⚠️ No unsent bulk shipping histories found.');
            return Command::SUCCESS;
        }

        $bulkEmailService = app(BulkShippingSummaryService::class);

        foreach ($histories as $history) {
            $this->info("📦 Sending summary email for history ID: {$history->id}...");

            $successOrderIds = $history->success_order_ids ?? [];
            $failedOrderIds  = $history->failed_order_ids ?? [];

            $summary = [
                'total_processed' => $history->processed,
                'success_count'   => count($successOrderIds),
                'failed_count'    => count($failedOrderIds)
            ];

            try {
                $bulkEmailService->sendSummaryEmail(
                    $history,
                    $summary,
                    $successOrderIds,
                    $failedOrderIds
                );

                $history->update(['mail_sent' => 1]);
                $this->info("✅ Summary email sent for history ID: {$history->id}");
            } catch (\Exception $e) {
                $this->error("❌ Failed to send email for history ID {$history->id}: " . $e->getMessage());
            }
        }

        $this->info('✅ All pending bulk shipping summaries processed.');
        return Command::SUCCESS;
    }
}
