<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MarkOldAwaitingPrintOrdersAsShipped extends Command
{
    protected $signature = 'orders:mark-old-awaiting-print-as-shipped 
                            {--dry-run : Show what would be updated without making changes}
                            {--hours=36 : Number of hours threshold (default: 36)}
                            {--use-order-date : Use order_date instead of shipment_created_at}';
    
    protected $description = 'Mark orders older than specified hours (default 36) as printed (printing_status = 2) to remove them from awaiting print page';

    public function handle()
    {
        $hours = (int) $this->option('hours');
        $dryRun = $this->option('dry-run');
        $useOrderDate = $this->option('use-order-date');
        $threshold = Carbon::now()->subHours($hours);

        $dateField = $useOrderDate ? 'order_date' : 'shipment created_at';
        $this->info("🔍 Finding orders in awaiting print older than {$hours} hours (based on {$dateField})...");
        $this->info("   Threshold time: {$threshold->format('Y-m-d H:i:s')}");
        $this->newLine();

        // Find orders that match the awaiting print criteria and are older than threshold
        // This matches the exact query from PrintOrderController::getAwaitingPrintOrders
        $query = DB::table('orders as o')
            ->join('shipments as s', function ($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->whereRaw('LOWER(o.order_status) = ?', ['shipped'])
            ->where('o.printing_status', 1);

        if ($useOrderDate) {
            // Use order_date - orders where order_date + hours < now
            // Since order_date is a date, we add the hours to it and compare
            $query->whereNotNull('o.order_date')
                  ->whereRaw('DATE_ADD(o.order_date, INTERVAL ? HOUR) < ?', [$hours, now()]);
        } else {
            // Use shipment created_at (original behavior)
            $query->where('s.created_at', '<', $threshold);
        }

        $orders = $query->select(
                'o.id', 
                'o.order_number', 
                'o.marketplace', 
                'o.order_date',
                's.created_at as shipment_created_at'
            )
            ->get();

        $count = $orders->count();

        if ($count === 0) {
            $this->info("✅ No orders found matching the criteria.");
            return Command::SUCCESS;
        }

        $this->info("📋 Found {$count} order(s) to update:");
        $this->newLine();

        // Display the orders that will be updated
        $tableData = [];
        foreach ($orders as $order) {
            if ($useOrderDate && $order->order_date) {
                $orderDate = Carbon::parse($order->order_date);
                $age = $orderDate->diffForHumans();
                $daysOld = $orderDate->diffInDays(now());
                $tableData[] = [
                    'ID' => $order->id,
                    'Order Number' => $order->order_number,
                    'Marketplace' => $order->marketplace,
                    'Order Date' => $orderDate->format('Y-m-d'),
                    'Days Old' => $daysOld,
                    'Age' => $age,
                ];
            } else {
                $shipmentDate = Carbon::parse($order->shipment_created_at);
                $age = $shipmentDate->diffForHumans();
                $tableData[] = [
                    'ID' => $order->id,
                    'Order Number' => $order->order_number,
                    'Marketplace' => $order->marketplace,
                    'Shipment Created' => $shipmentDate->format('Y-m-d H:i:s'),
                    'Age' => $age,
                ];
            }
        }

        $headers = $useOrderDate 
            ? ['ID', 'Order Number', 'Marketplace', 'Order Date', 'Days Old', 'Age']
            : ['ID', 'Order Number', 'Marketplace', 'Shipment Created', 'Age'];
        $this->table($headers, $tableData);

        if ($dryRun) {
            $this->warn("🔍 DRY RUN MODE - No changes will be made.");
            $this->info("   Run without --dry-run to apply changes.");
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm("⚠️  Are you sure you want to update {$count} order(s)? This will set printing_status to 2 (printed).", true)) {
            $this->info("❌ Operation cancelled.");
            return Command::FAILURE;
        }

        // Update the orders
        $orderIds = $orders->pluck('id')->toArray();
        
        try {
            DB::beginTransaction();

            $updated = DB::table('orders')
                ->whereIn('id', $orderIds)
                ->update([
                    'printing_status' => 2, // 2 = printed (removes from awaiting print)
                    'updated_at' => now(),
                ]);

            DB::commit();

            $this->newLine();
            $this->info("✅ Successfully updated {$updated} order(s).");
            $this->info("   These orders will no longer appear in the awaiting print page.");
            $this->newLine();
            $this->info("📝 Summary:");
            $this->info("   - Orders updated: {$updated}");
            $this->info("   - printing_status changed: 1 → 2 (printed)");
            $this->info("   - order_status remains: 'shipped' (unchanged)");
            $this->info("   - All other data remains unchanged");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Failed to update orders: " . $e->getMessage());
            $this->error("   Stack trace: " . $e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}
