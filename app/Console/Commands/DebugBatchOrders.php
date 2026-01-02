<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\BulkShippingHistory;

class DebugBatchOrders extends Command
{
    protected $signature = 'orders:debug-batch {batchId : The BulkShippingHistory batch ID}';
    protected $description = 'Debug orders from a specific bulk shipping batch to find why they are missing';

    public function handle()
    {
        $batchId = $this->argument('batchId');
        
        $batch = BulkShippingHistory::find($batchId);
        
        if (!$batch) {
            $this->error("Batch ID {$batchId} not found!");
            return Command::FAILURE;
        }
        
        $this->info("🔍 Debugging batch ID: {$batchId}");
        $this->info("   Processed: {$batch->processed}, Success: {$batch->success}, Failed: {$batch->failed}");
        $this->info("   Created: {$batch->created_at}");
        $this->newLine();
        
        $successOrderIds = $batch->success_order_ids ?? [];
        $failedOrderIds = $batch->failed_order_ids ?? [];
        
        $this->info("Success order IDs: " . count($successOrderIds));
        $this->info("Failed order IDs: " . count($failedOrderIds));
        $this->newLine();
        
        if (empty($successOrderIds)) {
            $this->warn('No success order IDs found in batch!');
            return Command::SUCCESS;
        }
        
        // Get all orders from the batch
        $orders = Order::whereIn('id', $successOrderIds)
            ->select('id', 'order_number', 'order_status', 'printing_status', 'queue', 'label_status', 'label_source', 'fulfillment_status')
            ->get();
        
        $this->info("Found {$orders->count()} orders in database");
        $this->newLine();
        
        // Categorize orders
        $categories = [
            'correct_awaiting_print' => [],
            'incorrect_status' => [],
            'no_active_shipment' => [],
            'stuck_in_queue' => [],
        ];
        
        foreach ($orders as $order) {
            if ($order->queue == 1) {
                $categories['stuck_in_queue'][] = $order;
                continue;
            }
            
            $activeShipment = Shipment::where('order_id', $order->id)
                ->where('label_status', 'active')
                ->first();
            
            if (!$activeShipment) {
                $categories['no_active_shipment'][] = $order;
                continue;
            }
            
            // Check if status is correct for awaiting print
            $orderStatusCorrect = strtolower($order->order_status) === 'shipped';
            $printingStatusCorrect = $order->printing_status == 1;
            
            if ($orderStatusCorrect && $printingStatusCorrect) {
                $categories['correct_awaiting_print'][] = $order;
            } else {
                $categories['incorrect_status'][] = [
                    'order' => $order,
                    'issues' => [
                        'order_status' => $order->order_status . ($orderStatusCorrect ? ' ✅' : ' ❌ (should be Shipped)'),
                        'printing_status' => $order->printing_status . ($printingStatusCorrect ? ' ✅' : ' ❌ (should be 1)'),
                    ],
                ];
            }
        }
        
        // Display results
        $this->info("📊 Analysis Results:");
        $this->newLine();
        
        // Correct orders
        $this->info("1. ✅ Correctly configured (should appear in Awaiting Print): " . count($categories['correct_awaiting_print']));
        if (count($categories['correct_awaiting_print']) > 0 && count($categories['correct_awaiting_print']) <= 10) {
            foreach ($categories['correct_awaiting_print'] as $order) {
                $this->line("   - {$order->order_number}");
            }
        }
        $this->newLine();
        
        // Incorrect status
        $this->warn("2. ⚠️  Incorrect status (won't appear anywhere): " . count($categories['incorrect_status']));
        if (count($categories['incorrect_status']) > 0) {
            $this->warn("   These orders have active shipments but wrong status:");
            foreach (array_slice($categories['incorrect_status'], 0, 10) as $item) {
                $order = $item['order'];
                $this->line("   - {$order->order_number}: {$item['issues']['order_status']}, {$item['issues']['printing_status']}");
            }
            if (count($categories['incorrect_status']) > 10) {
                $this->line("   ... and " . (count($categories['incorrect_status']) - 10) . " more");
            }
        }
        $this->newLine();
        
        // No active shipment
        $this->warn("3. ⚠️  No active shipment (label creation may have failed): " . count($categories['no_active_shipment']));
        if (count($categories['no_active_shipment']) > 0) {
            foreach (array_slice($categories['no_active_shipment'], 0, 10) as $order) {
                $this->line("   - {$order->order_number}: order_status='{$order->order_status}', printing_status={$order->printing_status}");
            }
            if (count($categories['no_active_shipment']) > 10) {
                $this->line("   ... and " . (count($categories['no_active_shipment']) - 10) . " more");
            }
        }
        $this->newLine();
        
        // Stuck in queue
        $this->warn("4. ⚠️  Stuck in queue: " . count($categories['stuck_in_queue']));
        if (count($categories['stuck_in_queue']) > 0) {
            foreach ($categories['stuck_in_queue'] as $order) {
                $this->line("   - {$order->order_number}");
            }
        }
        $this->newLine();
        
        // Summary
        $totalIssues = count($categories['incorrect_status']) + count($categories['no_active_shipment']) + count($categories['stuck_in_queue']);
        
        $this->info("📋 Summary:");
        $this->table(
            ['Category', 'Count', 'Status'],
            [
                ['✅ Correct (Awaiting Print)', count($categories['correct_awaiting_print']), 'OK'],
                ['⚠️  Incorrect status', count($categories['incorrect_status']), $totalIssues > 0 ? 'NEEDS FIX' : 'OK'],
                ['⚠️  No active shipment', count($categories['no_active_shipment']), 'INVESTIGATE'],
                ['⚠️  Stuck in queue', count($categories['stuck_in_queue']), 'NEEDS FIX'],
            ]
        );
        
        if ($totalIssues > 0) {
            $this->newLine();
            $this->warn("⚠️  {$totalIssues} orders have issues!");
            $this->info("Run: php artisan orders:fix-live-data");
        } else {
            $this->newLine();
            $this->info("✅ All orders are correctly configured!");
        }
        
        return Command::SUCCESS;
    }
}
