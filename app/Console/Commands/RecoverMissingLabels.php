<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BulkShippingHistory;
use App\Models\Order;
use App\Models\Shipment;
use App\Services\ShippingLabelService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RecoverMissingLabels extends Command
{
    protected $signature = 'labels:recover-missing 
                            {--history-id= : Specific BulkShippingHistory ID to recover from}
                            {--hours=1 : Number of hours to look back (default: 1 hour)}
                            {--dry-run : Show what would be recovered without actually processing}
                            {--force : Force retry even if active shipment exists}';

    protected $description = 'Recover missing labels from previous bulk purchases by identifying and retrying failed orders';

    protected ShippingLabelService $shippingLabelService;

    public function __construct(ShippingLabelService $shippingLabelService)
    {
        parent::__construct();
        $this->shippingLabelService = $shippingLabelService;
    }

    public function handle()
    {
        $historyId = $this->option('history-id');
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🔍 Starting missing labels recovery...');
        $this->newLine();

        if ($historyId) {
            $this->recoverFromHistory($historyId, $dryRun, $force);
        } else {
            $this->recoverFromTimeRange($hours, $dryRun, $force);
        }

        return Command::SUCCESS;
    }

    protected function recoverFromHistory($historyId, $dryRun, $force)
    {
        $this->info("📋 Recovering from BulkShippingHistory ID: {$historyId}");
        
        $history = BulkShippingHistory::find($historyId);
        
        if (!$history) {
            $this->error("❌ BulkShippingHistory ID {$historyId} not found!");
            return;
        }

        $this->displayHistoryInfo($history);

        $allOrderIds = $history->order_ids ?? [];
        $successOrderIds = $history->success_order_ids ?? [];
        $failedOrderIds = $history->failed_order_ids ?? [];

        // Find orders that should have succeeded but don't have active shipments
        $missingOrders = $this->findMissingOrders($allOrderIds, $successOrderIds, $force);

        if (empty($missingOrders)) {
            $this->info('✅ No missing orders found. All orders have active shipments.');
            return;
        }

        $this->displayMissingOrders($missingOrders);

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No labels will be created');
            return;
        }

        $this->processMissingOrders($missingOrders, $history->user_id ?? 1, $historyId);
    }

    protected function recoverFromTimeRange($hours, $dryRun, $force)
    {
        $this->info("📋 Recovering from last {$hours} hour(s)");
        
        $startTime = now()->subHours($hours);
        
        // Find recent bulk shipping histories
        $histories = BulkShippingHistory::where('created_at', '>=', $startTime)
            ->orderByDesc('id')
            ->get();

        if ($histories->isEmpty()) {
            $this->warn("⚠️ No bulk shipping histories found in the last {$hours} hour(s)");
            return;
        }

        $this->info("Found {$histories->count()} bulk shipping history record(s)");
        $this->newLine();

        $allMissingOrders = [];

        foreach ($histories as $history) {
            $this->info("Processing History ID: {$history->id} (Created: {$history->created_at})");
            
            $allOrderIds = $history->order_ids ?? [];
            $successOrderIds = $history->success_order_ids ?? [];
            
            $missingOrders = $this->findMissingOrders($allOrderIds, $successOrderIds, $force);
            
            if (!empty($missingOrders)) {
                $this->warn("  ⚠️ Found " . count($missingOrders) . " missing orders");
                $allMissingOrders = array_merge($allMissingOrders, $missingOrders);
            } else {
                $this->info("  ✅ All orders have active shipments");
            }
        }

        if (empty($allMissingOrders)) {
            $this->info('✅ No missing orders found across all histories.');
            return;
        }

        // Remove duplicates
        $allMissingOrders = array_unique($allMissingOrders);
        $this->newLine();
        $this->displayMissingOrders($allMissingOrders);

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No labels will be created');
            return;
        }

        $this->processMissingOrders($allMissingOrders, 1);
    }

    protected function findMissingOrders($allOrderIds, $successOrderIds, $force)
    {
        if (empty($allOrderIds)) {
            return [];
        }

        // Get orders that were marked as successful but don't have active shipments
        $orders = Order::whereIn('id', $allOrderIds)
            ->with(['cheapestRate'])
            ->get();

        $missingOrders = [];

        foreach ($orders as $order) {
            // Check if order has an active shipment
            $hasActiveShipment = Shipment::where('order_id', $order->id)
                ->where('label_status', 'active')
                ->where('void_status', 'active')
                ->exists();

            // If force is enabled, retry even if active shipment exists
            if ($force && $hasActiveShipment) {
                $this->warn("  ⚠️ Order {$order->id} ({$order->order_number}) has active shipment but --force is enabled");
                $missingOrders[] = $order->id;
                continue;
            }

            // If no active shipment, it's missing
            if (!$hasActiveShipment) {
                // Additional check: was it marked as successful?
                if (in_array($order->id, $successOrderIds)) {
                    $this->warn("  ⚠️ Order {$order->id} ({$order->order_number}) was marked successful but has no active shipment");
                } else {
                    $this->warn("  ⚠️ Order {$order->id} ({$order->order_number}) has no active shipment");
                }
                $missingOrders[] = $order->id;
            }
        }

        return $missingOrders;
    }

    protected function displayHistoryInfo($history)
    {
        $this->info("History Details:");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $history->id],
                ['Created At', $history->created_at],
                ['Status', $history->status],
                ['Total Processed', $history->processed],
                ['Success Count', $history->success],
                ['Failed Count', $history->failed],
                ['User ID', $history->user_id ?? 'N/A'],
            ]
        );
        $this->newLine();
    }

    protected function displayMissingOrders($missingOrderIds)
    {
        $this->warn("⚠️ Found " . count($missingOrderIds) . " missing order(s):");
        
        $orders = Order::whereIn('id', $missingOrderIds)
            ->select('id', 'order_number', 'marketplace', 'label_status', 'fulfillment_status')
            ->get();

        $tableData = [];
        foreach ($orders as $order) {
            $tableData[] = [
                $order->id,
                $order->order_number,
                $order->marketplace ?? 'N/A',
                $order->label_status ?? 'N/A',
                $order->fulfillment_status ?? 'N/A',
            ];
        }

        $this->table(
            ['Order ID', 'Order Number', 'Marketplace', 'Label Status', 'Fulfillment Status'],
            $tableData
        );
        $this->newLine();
    }

    protected function processMissingOrders($missingOrderIds, $userId, $historyId = null)
    {
        if (empty($missingOrderIds)) {
            return;
        }

        $this->info("🔄 Processing " . count($missingOrderIds) . " missing order(s)...");
        $this->newLine();

        // Lock orders
        $locked = Order::whereIn('id', $missingOrderIds)
            ->where('queue', 0)
            ->update([
                'queue' => 1,
                'queue_started_at' => now()
            ]);

        if ($locked === 0) {
            $this->warn("⚠️ Some orders are already being processed. Skipping locked orders.");
        }

        try {
            $result = $this->shippingLabelService->createLabels($missingOrderIds, $userId);

            $summary = $result['summary'] ?? [];
            $successCount = $summary['success_count'] ?? 0;
            $failedCount = $summary['failed_count'] ?? 0;

            $this->newLine();
            $this->info("✅ Recovery processing completed!");
            $this->table(
                ['Metric', 'Count'],
                [
                    ['Total Processed', $summary['total_processed'] ?? count($missingOrderIds)],
                    ['Success', $successCount],
                    ['Failed', $failedCount],
                ]
            );

            // Update the original history record if provided
            if ($historyId) {
                $history = BulkShippingHistory::find($historyId);
                if ($history) {
                    $originalSuccess = $history->success ?? 0;
                    $newSuccess = $originalSuccess + $successCount;
                    $originalFailed = $history->failed ?? 0;
                    $newFailed = max(0, $originalFailed - $successCount + $failedCount);

                    $history->update([
                        'success' => $newSuccess,
                        'failed' => $newFailed,
                        'status' => $newFailed > 0 ? 'partial' : 'completed',
                        'success_order_ids' => array_unique(array_merge(
                            $history->success_order_ids ?? [],
                            $summary['success_order_ids'] ?? []
                        )),
                        'failed_order_ids' => array_unique(array_merge(
                            $history->failed_order_ids ?? [],
                            $summary['failed_order_ids'] ?? []
                        )),
                    ]);

                    $this->info("📝 Updated BulkShippingHistory ID: {$historyId}");
                    $this->info("   Original: {$originalSuccess} success, {$originalFailed} failed");
                    $this->info("   Updated: {$newSuccess} success, {$newFailed} failed");
                }
            }

            // Show failed orders if any
            if (!empty($summary['failed_order_ids'])) {
                $this->newLine();
                $this->warn("⚠️ Failed Orders:");
                $failedOrders = Order::whereIn('id', $summary['failed_order_ids'])
                    ->select('id', 'order_number')
                    ->get();
                
                foreach ($failedOrders as $order) {
                    $this->warn("  - Order {$order->id} ({$order->order_number})");
                }
            }

        } catch (\Exception $e) {
            $this->error("❌ Error during recovery: " . $e->getMessage());
            Log::error("RecoverMissingLabels error", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'order_ids' => $missingOrderIds,
            ]);
        } finally {
            // Unlock orders
            Order::whereIn('id', $missingOrderIds)->update(['queue' => 0]);
        }
    }
}

