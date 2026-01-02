<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Shipment;

class FixAwaitingPrintOrders extends Command
{
    protected $signature = 'orders:fix-awaiting-print {--dry-run : Run without making changes}';
    protected $description = 'Fix orders that have active shipments but incorrect printing_status or order_status';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
        }

        // Find orders that have active shipments but incorrect status
        $orders = DB::table('orders as o')
            ->join('shipments as s', function($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->where(function($query) {
                $query->where('o.printing_status', 0)
                      ->orWhere('o.order_status', 'unshipped')
                      ->orWhere('o.order_status', 'Unshipped');
            })
            ->select('o.id', 'o.order_number', 'o.order_status', 'o.printing_status', 's.id as shipment_id', 's.label_status')
            ->get();

        if ($orders->isEmpty()) {
            $this->info('✅ No orders found that need fixing.');
            return Command::SUCCESS;
        }

        $this->info("Found {$orders->count()} orders that need fixing.");

        $fixed = 0;
        foreach ($orders as $orderData) {
            $order = Order::find($orderData->id);
            
            if (!$order) {
                $this->warn("Order ID {$orderData->id} not found, skipping...");
                continue;
            }

            $this->line("Fixing order: {$order->order_number} (ID: {$order->id})");
            $this->line("  Current status: order_status={$order->order_status}, printing_status={$order->printing_status}");

            if (!$dryRun) {
                $order->update([
                    'order_status' => 'Shipped',
                    'printing_status' => 1,
                    'label_status' => 'purchased',
                    'label_source' => 'api',
                    'fulfillment_status' => 'shipped',
                ]);

                $this->info("  ✅ Updated: order_status=Shipped, printing_status=1");
            } else {
                $this->info("  [DRY RUN] Would update: order_status=Shipped, printing_status=1");
            }

            $fixed++;
        }

        if ($dryRun) {
            $this->info("\n🔍 DRY RUN COMPLETE - {$fixed} orders would be fixed");
            $this->info("Run without --dry-run to apply changes");
        } else {
            $this->info("\n✅ Fixed {$fixed} orders");
        }

        return Command::SUCCESS;
    }
}

