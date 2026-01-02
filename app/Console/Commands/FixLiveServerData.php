<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;

class FixLiveServerData extends Command
{
    protected $signature = 'orders:fix-live-data';
    protected $description = 'Fix existing data issues on live server: unlock stuck orders and fix printing_status for orders with active shipments';

    public function handle()
    {
        $this->info('🔧 Fixing data issues on live server...');
        $this->newLine();
        
        // Step 1: Unlock orders stuck in queue
        $this->info('Step 1: Unlocking orders stuck in queue...');
        $stuckInQueue = Order::where('queue', 1)->count();
        
        if ($stuckInQueue > 0) {
            $unlocked = Order::where('queue', 1)->update(['queue' => 0]);
            $this->info("✅ Unlocked {$unlocked} orders that were stuck in queue.");
        } else {
            $this->info('✅ No orders stuck in queue.');
        }
        $this->newLine();
        
        // Step 2: Fix orders with active shipments but incorrect status
        $this->info('Step 2: Fixing orders with active shipments but incorrect status...');
        
        $ordersToFix = DB::table('orders as o')
            ->join('shipments as s', function($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->where(function($query) {
                $query->where('o.printing_status', 0)
                      ->orWhere('o.order_status', 'unshipped')
                      ->orWhere('o.order_status', 'Unshipped');
            })
            ->select('o.id', 'o.order_number', 'o.order_status', 'o.printing_status')
            ->get();
        
        if ($ordersToFix->isNotEmpty()) {
            $fixed = 0;
            foreach ($ordersToFix as $orderData) {
                $order = Order::find($orderData->id);
                if ($order) {
                    $order->update([
                        'order_status' => 'Shipped',
                        'printing_status' => 1,
                        'label_status' => 'purchased',
                        'label_source' => 'api',
                        'fulfillment_status' => 'shipped',
                    ]);
                    $fixed++;
                }
            }
            $this->info("✅ Fixed {$fixed} orders with active shipments.");
        } else {
            $this->info('✅ No orders need fixing.');
        }
        $this->newLine();
        
        // Step 3: Check and fix specific orders from the batch
        $this->info('Step 3: Checking orders from batch 04-14054-* and 02-14054-*...');
        $batchOrders = Order::where('order_number', 'like', '04-14054-%')
            ->orWhere('order_number', 'like', '02-14054-%')
            ->get();
        
        $this->info("Found {$batchOrders->count()} orders from the batch.");
        
        foreach ($batchOrders as $specificOrder) {
            $this->line("Checking: {$specificOrder->order_number}...");
            
            // Check if it has active shipment but wrong status
            $hasActiveShipment = DB::table('shipments')
                ->where('order_id', $specificOrder->id)
                ->where('label_status', 'active')
                ->exists();
            
            if ($hasActiveShipment) {
                // Should appear in awaiting print
                $needsFix = false;
                $fixes = [];
                
                if (strtolower($specificOrder->order_status) !== 'shipped') {
                    $needsFix = true;
                    $fixes[] = "order_status: {$specificOrder->order_status} -> Shipped";
                }
                
                if ($specificOrder->printing_status != 1) {
                    $needsFix = true;
                    $fixes[] = "printing_status: {$specificOrder->printing_status} -> 1";
                }
                
                if ($needsFix) {
                    $this->warn("  ⚠️  Needs fix: " . implode(', ', $fixes));
                    $specificOrder->update([
                        'order_status' => 'Shipped',
                        'printing_status' => 1,
                        'label_status' => 'purchased',
                        'label_source' => 'api',
                        'fulfillment_status' => 'shipped',
                    ]);
                    $this->info("  ✅ Fixed!");
                } else {
                    $this->info("  ✅ Already correct");
                }
            } else {
                // Should appear in awaiting shipment (if no active shipment)
                if ($specificOrder->queue == 1) {
                    $this->warn("  ⚠️  Stuck in queue, unlocking...");
                    $specificOrder->update(['queue' => 0]);
                    $this->info("  ✅ Unlocked!");
                } else {
                    $this->info("  ℹ️  No active shipment (should appear in awaiting shipment if other criteria met)");
                }
            }
        }
        
        // Also check the specific order mentioned
        $this->newLine();
        $this->info('Checking order 04-14054-11277...');
        $specificOrder = Order::where('order_number', '04-14054-11277')->first();
        
        if ($specificOrder) {
            $this->table(
                ['Field', 'Value'],
                [
                    ['Order Number', $specificOrder->order_number],
                    ['Order Status', $specificOrder->order_status],
                    ['Printing Status', $specificOrder->printing_status],
                    ['Queue', $specificOrder->queue],
                    ['Has Active Shipments', DB::table('shipments')
                        ->where('order_id', $specificOrder->id)
                        ->where('label_status', 'active')
                        ->exists() ? 'Yes' : 'No'],
                ]
            );
            
            // Check why it's not showing in awaiting shipment
            $canShowInAwaitingShipment = 
                $specificOrder->printing_status == 0 &&
                in_array($specificOrder->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid']) &&
                $specificOrder->queue == 0 &&
                $specificOrder->marked_as_ship == 0 &&
                !in_array($specificOrder->cancel_status, ['CANCELED', 'IN_PROGRESS']) &&
                in_array($specificOrder->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon']);
            
            if ($canShowInAwaitingShipment) {
                $this->info('✅ This order should appear in Awaiting Shipment page.');
            } else {
                $this->warn('⚠️  This order may not appear in Awaiting Shipment due to:');
                if ($specificOrder->printing_status != 0) $this->line('   - printing_status is not 0');
                if (!in_array($specificOrder->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid'])) $this->line('   - order_status: ' . $specificOrder->order_status);
                if ($specificOrder->queue != 0) $this->line('   - queue is not 0');
                if ($specificOrder->marked_as_ship != 0) $this->line('   - marked_as_ship is not 0');
                if (in_array($specificOrder->cancel_status, ['CANCELED', 'IN_PROGRESS'])) $this->line('   - cancel_status: ' . $specificOrder->cancel_status);
                if (!in_array($specificOrder->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon'])) $this->line('   - marketplace: ' . $specificOrder->marketplace);
            }
        } else {
            $this->warn('⚠️  Order 04-14054-11277 not found in database.');
        }
        
        $this->newLine();
        $this->info('✅ Data fix completed!');
        $this->info('Please refresh the Awaiting Shipment and Awaiting Print pages to see the changes.');
        
        return Command::SUCCESS;
    }
}

