<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

class FixLiveServerData extends Command
{
    protected $signature = 'orders:fix-live-data {--order-number= : Check specific order number}';
    protected $description = 'Fix existing data issues: unlock stuck orders and fix printing_status for orders with active shipments';

    public function handle()
    {
        $this->info('🔧 Fixing data issues on live server...');
        $this->newLine();
        
        // Step 1: Unlock orders stuck in queue
        $this->info('Step 1: Unlocking orders stuck in queue...');
        $unlocked = DB::table('orders')->where('queue', 1)->update(['queue' => 0]);
        
        if ($unlocked > 0) {
            $this->info("✅ Unlocked {$unlocked} orders that were stuck in queue.");
        } else {
            $this->info('✅ No orders stuck in queue.');
        }
        $this->newLine();
        
        // Step 2: Fix orders with active shipments but incorrect status (batch update for efficiency)
        $this->info('Step 2: Fixing orders with active shipments but incorrect status...');
        
        // Use a single UPDATE query for better performance
        $fixed = DB::table('orders as o')
            ->join('shipments as s', function($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->where(function($query) {
                // Orders with active shipments but order_status != 'shipped' (regardless of printing_status)
                $query->whereRaw('LOWER(o.order_status) != ?', ['shipped']);
            })
            ->update([
                'o.order_status' => 'Shipped',
                'o.printing_status' => 1,
                'o.label_status' => 'purchased',
                'o.label_source' => 'api',
                'o.fulfillment_status' => 'shipped',
                'o.updated_at' => now(),
            ]);
        
        if ($fixed > 0) {
            $this->info("✅ Fixed {$fixed} orders with active shipments.");
        } else {
            $this->info('✅ No orders need fixing.');
        }
        $this->newLine();
        
        // Step 3: Check specific order if provided, or check batch orders
        $orderNumber = $this->option('order-number');
        
        if ($orderNumber) {
            $this->checkOrder($orderNumber);
        } else {
            // Check batch orders
            $this->info('Step 3: Checking orders from batch 04-14054-* and 02-14054-*...');
            $batchOrders = Order::where('order_number', 'like', '04-14054-%')
                ->orWhere('order_number', 'like', '02-14054-%')
                ->select('id', 'order_number', 'order_status', 'printing_status', 'queue')
                ->get();
            
            if ($batchOrders->isNotEmpty()) {
                $this->info("Found {$batchOrders->count()} orders from the batch.");
                
                // Unlock any stuck orders in batch
                $stuckInBatch = $batchOrders->where('queue', 1)->pluck('id');
                if ($stuckInBatch->isNotEmpty()) {
                    DB::table('orders')->whereIn('id', $stuckInBatch)->update(['queue' => 0]);
                    $this->info("✅ Unlocked {$stuckInBatch->count()} batch orders stuck in queue.");
                }
                
                // Show summary
                $withActiveShipments = DB::table('orders as o')
                    ->join('shipments as s', function($join) {
                        $join->on('s.order_id', '=', 'o.id')
                             ->where('s.label_status', '=', 'active');
                    })
                    ->whereIn('o.order_number', $batchOrders->pluck('order_number'))
                    ->count();
                
                $this->info("  - Orders with active shipments: {$withActiveShipments}");
                $this->info("  - Orders without active shipments: " . ($batchOrders->count() - $withActiveShipments));
            } else {
                $this->info('No orders found in batch.');
            }
            
            // Check the reference order
            $this->newLine();
            $this->checkOrder('04-14054-11277');
        }
        
        $this->newLine();
        $this->info('✅ Data fix completed!');
        $this->info('Please refresh the Awaiting Shipment and Awaiting Print pages to see the changes.');
        
        return Command::SUCCESS;
    }
    
    private function checkOrder(string $orderNumber): void
    {
        $this->info("Checking order: {$orderNumber}...");
        
        $order = Order::where('order_number', $orderNumber)->first();
        
        if (!$order) {
            $this->warn("⚠️  Order {$orderNumber} not found in database.");
            return;
        }
        
        $hasActiveShipment = DB::table('shipments')
            ->where('order_id', $order->id)
            ->where('label_status', 'active')
            ->exists();
        
        $this->table(
            ['Field', 'Value'],
            [
                ['Order Number', $order->order_number],
                ['Order Status', $order->order_status],
                ['Printing Status', $order->printing_status],
                ['Queue', $order->queue],
                ['Has Active Shipments', $hasActiveShipment ? 'Yes' : 'No'],
            ]
        );
        
        // Check awaiting print
        if ($hasActiveShipment) {
            $canShowInAwaitingPrint = 
                strtolower($order->order_status) === 'shipped' && 
                $order->printing_status == 1;
            
            if ($canShowInAwaitingPrint) {
                $this->info('✅ This order SHOULD appear in Awaiting Print page.');
            } else {
                $this->warn('⚠️  This order will NOT appear in Awaiting Print because:');
                if (strtolower($order->order_status) !== 'shipped') {
                    $this->line("   - order_status is '{$order->order_status}' (needs 'shipped')");
                }
                if ($order->printing_status != 1) {
                    $this->line("   - printing_status is {$order->printing_status} (needs 1)");
                }
            }
        }
        
        // Check awaiting shipment
        $canShowInAwaitingShipment = 
            !$hasActiveShipment &&
            $order->printing_status == 0 &&
            in_array($order->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid']) &&
            $order->queue == 0 &&
            $order->marked_as_ship == 0 &&
            !in_array($order->cancel_status, ['CANCELED', 'IN_PROGRESS']) &&
            in_array($order->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon']);
        
        if ($canShowInAwaitingShipment) {
            $this->info('✅ This order SHOULD appear in Awaiting Shipment page.');
        } elseif (!$hasActiveShipment) {
            $this->warn('⚠️  This order will NOT appear in Awaiting Shipment because:');
            if ($order->printing_status != 0) $this->line("   - printing_status is {$order->printing_status} (needs 0)");
            if (!in_array($order->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid'])) {
                $this->line("   - order_status: '{$order->order_status}'");
            }
            if ($order->queue != 0) $this->line("   - queue is {$order->queue} (needs 0)");
            if ($order->marked_as_ship != 0) $this->line("   - marked_as_ship is {$order->marked_as_ship} (needs 0)");
            if (in_array($order->cancel_status, ['CANCELED', 'IN_PROGRESS'])) {
                $this->line("   - cancel_status: '{$order->cancel_status}'");
            }
            if (!in_array($order->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon'])) {
                $this->line("   - marketplace: '{$order->marketplace}'");
            }
        }
    }
}

