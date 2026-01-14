<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

class FixDobaLabelFields extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'doba:fix-label-fields 
                            {--dry-run : Run without making changes to see what would be updated}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix DOBA order label fields - mark orders with tracking as label_provided and orders needing labels as label_required';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be made');
            $this->line('');
        }

        $this->info('🔧 Fixing DOBA Label Fields...');
        $this->line('');

        // Step 1: Update orders with tracking numbers to mark as label provided
        $this->info('Step 1: Updating orders with tracking numbers...');
        
        $ordersWithTracking = Order::where('marketplace', 'doba')
            ->whereNotNull('tracking_number')
            ->where('tracking_number', '!=', '')
            ->where('doba_label_provided', false)
            ->get();

        $this->line("   Found {$ordersWithTracking->count()} orders with tracking numbers but label_provided = false");

        if (!$dryRun && $ordersWithTracking->count() > 0) {
            $updated = 0;
            $bar = $this->output->createProgressBar($ordersWithTracking->count());
            $bar->start();

            foreach ($ordersWithTracking as $order) {
                $order->doba_label_provided = true;
                $order->doba_label_required = false; // If label is provided, it's not required
                $order->save();
                $updated++;
                $bar->advance();
            }

            $bar->finish();
            $this->line('');
            $this->info("   ✓ Updated {$updated} orders to mark label_provided = true");
        } else {
            $this->info("   ℹ️  Would update {$ordersWithTracking->count()} orders");
        }

        $this->line('');

        // Step 2: Update orders without tracking that need labels
        $this->info('Step 2: Updating orders that need labels...');
        
        $ordersNeedingLabels = Order::where('marketplace', 'doba')
            ->where(function($q) {
                $q->where('order_status', 'awaiting_shipment')
                  ->orWhereIn('order_status', ['unshipped', 'pending']);
            })
            ->where(function($q) {
                $q->whereNull('tracking_number')
                  ->orWhere('tracking_number', '');
            })
            ->where('doba_label_provided', false)
            ->where('doba_label_required', false)
            ->get();

        $this->line("   Found {$ordersNeedingLabels->count()} orders that need labels");

        if (!$dryRun && $ordersNeedingLabels->count() > 0) {
            $updatedRequired = 0;
            $bar = $this->output->createProgressBar($ordersNeedingLabels->count());
            $bar->start();

            foreach ($ordersNeedingLabels as $order) {
                $order->doba_label_required = true;
                $order->doba_label_provided = false;
                $order->save();
                $updatedRequired++;
                $bar->advance();
            }

            $bar->finish();
            $this->line('');
            $this->info("   ✓ Updated {$updatedRequired} orders to mark label_required = true");
        } else {
            $this->info("   ℹ️  Would update {$ordersNeedingLabels->count()} orders");
        }

        $this->line('');

        // Final summary
        $totalDoba = Order::where('marketplace', 'doba')->count();
        $labelRequired = Order::where('marketplace', 'doba')->where('doba_label_required', true)->count();
        $labelProvided = Order::where('marketplace', 'doba')->where('doba_label_provided', true)->count();
        $withEither = Order::where('marketplace', 'doba')
            ->where(function($q) {
                $q->where('doba_label_required', true)
                  ->orWhere('doba_label_provided', true);
            })
            ->count();

        $this->info('═══════════════════════════════════════════════════════');
        $this->info('📊 FINAL STATUS');
        $this->info('═══════════════════════════════════════════════════════');
        $this->line('');
        $this->info("Total DOBA Orders: {$totalDoba}");
        $this->info("Orders with label_required = true: {$labelRequired}");
        $this->info("Orders with label_provided = true: {$labelProvided}");
        $this->info("Orders visible in UI: {$withEither}");
        $this->line('');

        if ($dryRun) {
            $this->warn('⚠️  This was a DRY RUN - No changes were made');
            $this->info('Run without --dry-run to apply changes');
        } else {
            $this->info('✅ Fix completed successfully!');
        }

        return 0;
    }
}
