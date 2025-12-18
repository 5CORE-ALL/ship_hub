<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ShippingLabelService;

class SyncMissingLabelsToHistory extends Command
{
    protected $signature = 'labels:sync-missing-history 
                            {--from-date= : Start date (Y-m-d format)}
                            {--to-date= : End date (Y-m-d format)}';
    
    protected $description = 'Sync missing labels to bulk label history page. Finds shipments with active labels that don\'t have a BulkShippingHistory entry and creates entries for them.';

    public function handle()
    {
        $this->info('🔄 Starting sync of missing labels to bulk history...');
        
        $fromDate = $this->option('from-date');
        $toDate = $this->option('to-date');
        
        if ($fromDate) {
            $this->info("📅 From date: {$fromDate}");
        }
        if ($toDate) {
            $this->info("📅 To date: {$toDate}");
        }
        
        try {
            $shippingLabelService = app(ShippingLabelService::class);
            $result = $shippingLabelService->syncMissingLabelsToHistory($fromDate, $toDate);
            
            $this->info('');
            $this->info('✅ Sync completed successfully!');
            $this->info('');
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Total Shipments Found', $result['total_shipments']],
                    ['Missing Shipments', $result['missing_shipments'] ?? 0],
                    ['Batches Created', $result['synced_batches']],
                    ['Total Orders Synced', $result['total_orders']],
                ]
            );
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Failed to sync missing labels: ' . $e->getMessage());
            $this->error('Stack trace: ' . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}

