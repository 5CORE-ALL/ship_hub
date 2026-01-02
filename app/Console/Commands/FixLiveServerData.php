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
        
        // Fix orders with active shipments but wrong order_status or printing_status
        // This includes:
        // - Orders with order_status != 'shipped' (should be 'Shipped')
        // - Orders with printing_status != 1 (should be 1 for awaiting print)
        $fixed = DB::table('orders as o')
            ->join('shipments as s', function($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->where(function($query) {
                // Orders with active shipments but order_status != 'shipped' OR printing_status != 1
                $query->whereRaw('LOWER(o.order_status) != ?', ['shipped'])
                      ->orWhere('o.printing_status', '!=', 1);
            })
            ->update([
                'o.order_status' => 'Shipped',
                'o.printing_status' => 1,  // Always set to 1 for awaiting print (not 2)
                'o.label_status' => 'purchased',
                'o.label_source' => 'api',
                'o.fulfillment_status' => 'shipped',
                'o.updated_at' => now(),
            ]);
        
        if ($fixed > 0) {
            $this->info("✅ Fixed {$fixed} orders with active shipments (corrected order_status and/or printing_status).");
        } else {
            $this->info('✅ No orders need fixing.');
        }
        $this->newLine();
        
        // Step 2b: Fix orders with label_status='purchased' but no shipment record
        $this->info('Step 2b: Fixing orders with purchased labels but missing shipment records...');
        
        $ordersWithLabelsButNoShipments = DB::table('orders as o')
            ->leftJoin('shipments as s', function($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->where('o.label_status', '=', 'purchased')
            ->whereNotNull('o.label_id')
            ->whereNull('s.id')
            ->select('o.id', 'o.order_number', 'o.label_id', 'o.tracking_number', 'o.shipping_carrier', 'o.shipping_service', 'o.shipping_cost', 'o.ship_date')
            ->get();
        
        if ($ordersWithLabelsButNoShipments->isNotEmpty()) {
            $created = 0;
            foreach ($ordersWithLabelsButNoShipments as $orderData) {
                try {
                    DB::table('shipments')->insert([
                        'order_id' => $orderData->id,
                        'tracking_number' => $orderData->tracking_number,
                        'carrier' => $orderData->shipping_carrier ?: 'Standard',
                        'label_id' => $orderData->label_id,
                        'service_type' => $orderData->shipping_service,
                        'shipment_status' => 'created',
                        'label_data' => json_encode(['order_id' => $orderData->id, 'label_id' => $orderData->label_id]),
                        'ship_date' => $orderData->ship_date ?? now(),
                        'cost' => $orderData->shipping_cost ?? 0,
                        'currency' => 'USD',
                        'label_status' => 'active',
                        'void_status' => 'active',
                        'created_by' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    
                    // Update order status
                    DB::table('orders')
                        ->where('id', $orderData->id)
                        ->update([
                            'order_status' => 'Shipped',
                            'printing_status' => 1,
                            'fulfillment_status' => 'shipped',
                            'updated_at' => now(),
                        ]);
                    
                    $created++;
                } catch (\Exception $e) {
                    $this->warn("  ⚠️  Failed to create shipment for {$orderData->order_number}: " . $e->getMessage());
                }
            }
            
            if ($created > 0) {
                $this->info("✅ Created {$created} missing shipment records and updated order statuses.");
            }
        } else {
            $this->info('✅ No orders with missing shipment records.');
        }
        $this->newLine();
        
        // Step 3: Fix all orders from batch 679 (80 orders batch)
        $this->info('Step 3: Fixing all orders from batch 679 (80 orders batch)...');
        
        try {
            $batch = \App\Models\BulkShippingHistory::find(679);
            
            if ($batch && !empty($batch->success_order_ids)) {
                $successOrderIds = is_array($batch->success_order_ids) 
                    ? $batch->success_order_ids 
                    : json_decode($batch->success_order_ids, true);
                
                $this->info("Found batch 679 with {$batch->processed} processed orders ({$batch->success} success, {$batch->failed} failed)");
                $this->info("Fixing all {$batch->success} success orders from this batch...");
                $this->newLine();
                
                // Get all orders from the batch
                $batchOrders = Order::whereIn('id', $successOrderIds)
                    ->select('id', 'order_number', 'order_status', 'printing_status', 'queue', 'label_status', 'label_id', 'tracking_number', 'shipping_carrier', 'shipping_service', 'shipping_cost', 'ship_date')
                    ->get();
                
                $this->info("Found {$batchOrders->count()} orders in database from batch 679");
                $this->newLine();
                
                $fixedCount = 0;
                $missingShipmentCount = 0;
                $statusFixedCount = 0;
                $unlockedCount = 0;
                
                foreach ($batchOrders as $order) {
                    $fixed = false;
                    
                    // Fix 1: Unlock if stuck in queue
                    if ($order->queue == 1) {
                        DB::table('orders')->where('id', $order->id)->update(['queue' => 0]);
                        $unlockedCount++;
                        $fixed = true;
                    }
                    
                    // Fix 2: Check if shipment exists
                    $hasActiveShipment = DB::table('shipments')
                        ->where('order_id', $order->id)
                        ->where('label_status', 'active')
                        ->exists();
                    
                    // Fix 3: Create missing shipment if order has label but no shipment
                    if (!$hasActiveShipment && $order->label_status === 'purchased' && !empty($order->label_id)) {
                        try {
                            DB::table('shipments')->insert([
                                'order_id' => $order->id,
                                'tracking_number' => $order->tracking_number,
                                'carrier' => $order->shipping_carrier ?: 'Standard',
                                'label_id' => $order->label_id,
                                'service_type' => $order->shipping_service,
                                'shipment_status' => 'created',
                                'label_data' => json_encode(['order_id' => $order->id, 'label_id' => $order->label_id]),
                                'ship_date' => $order->ship_date ?? now(),
                                'cost' => $order->shipping_cost ?? 0,
                                'currency' => 'USD',
                                'label_status' => 'active',
                                'void_status' => 'active',
                                'created_by' => 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                            
                            $hasActiveShipment = true;
                            $missingShipmentCount++;
                            $fixed = true;
                        } catch (\Exception $e) {
                            $this->warn("  ⚠️  Failed to create shipment for {$order->order_number}: " . $e->getMessage());
                        }
                    }
                    
                    // Fix 4: Fix order status and printing_status if needed
                    $needsStatusFix = false;
                    $updates = [];
                    
                    if ($hasActiveShipment) {
                        // Should have order_status = 'Shipped' and printing_status = 1
                        if (strtolower($order->order_status) !== 'shipped') {
                            $updates['order_status'] = 'Shipped';
                            $needsStatusFix = true;
                        }
                        if ($order->printing_status != 1) {
                            $updates['printing_status'] = 1;
                            $needsStatusFix = true;
                        }
                        if ($order->fulfillment_status !== 'shipped') {
                            $updates['fulfillment_status'] = 'shipped';
                            $needsStatusFix = true;
                        }
                        if ($order->label_status !== 'purchased') {
                            $updates['label_status'] = 'purchased';
                            $needsStatusFix = true;
                        }
                        if (empty($order->label_source)) {
                            $updates['label_source'] = 'api';
                            $needsStatusFix = true;
                        }
                    } else {
                        // No active shipment - should have printing_status = 0 for awaiting shipment
                        if ($order->printing_status == 1 && $order->label_status !== 'purchased') {
                            $updates['printing_status'] = 0;
                            $needsStatusFix = true;
                        }
                    }
                    
                    if ($needsStatusFix) {
                        $updates['updated_at'] = now();
                        DB::table('orders')->where('id', $order->id)->update($updates);
                        $statusFixedCount++;
                        $fixed = true;
                    }
                    
                    if ($fixed) {
                        $fixedCount++;
                    }
                }
                
                $this->newLine();
                $this->info("📊 Batch 679 Fix Summary:");
                $this->table(
                    ['Fix Type', 'Count'],
                    [
                        ['Total orders fixed', $fixedCount],
                        ['Orders unlocked from queue', $unlockedCount],
                        ['Missing shipments created', $missingShipmentCount],
                        ['Status corrections applied', $statusFixedCount],
                    ]
                );
                
                // Verify all orders are now correct
                $this->newLine();
                $this->info("Verifying all orders are correctly configured...");
                
                $correctCount = 0;
                $incorrectCount = 0;
                
                foreach ($batchOrders as $order) {
                    $hasActiveShipment = DB::table('shipments')
                        ->where('order_id', $order->id)
                        ->where('label_status', 'active')
                        ->exists();
                    
                    $orderAfterFix = DB::table('orders')->where('id', $order->id)->first();
                    
                    if ($hasActiveShipment) {
                        // Should appear in Awaiting Print
                        $isCorrect = strtolower($orderAfterFix->order_status) === 'shipped' 
                                   && $orderAfterFix->printing_status == 1;
                        if ($isCorrect) {
                            $correctCount++;
                        } else {
                            $incorrectCount++;
                        }
                    } else {
                        // Should appear in Awaiting Shipment (if other criteria met)
                        $isCorrect = $orderAfterFix->printing_status == 0;
                        if ($isCorrect) {
                            $correctCount++;
                        } else {
                            $incorrectCount++;
                        }
                    }
                }
                
                $this->info("✅ Correctly configured: {$correctCount}");
                if ($incorrectCount > 0) {
                    $this->warn("⚠️  Still need attention: {$incorrectCount}");
                } else {
                    $this->info("✅ All {$batchOrders->count()} orders from batch 679 are now correctly configured!");
                }
                
            } else {
                $this->warn("⚠️  Batch 679 not found or has no success order IDs.");
            }
        } catch (\Exception $e) {
            $this->error("❌ Error processing batch 679: " . $e->getMessage());
        }
        
        $this->newLine();
        
        // Step 4: Check specific order if provided, or check reference orders
        $orderNumber = $this->option('order-number');
        
        if ($orderNumber) {
            $this->checkOrder($orderNumber);
        } else {
            // Check reference orders from the batch
            $this->info('Step 4: Checking reference orders...');
            $this->checkOrder('04-14054-11277');
            $this->newLine();
            $this->checkOrder('02-14054-81298');
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

