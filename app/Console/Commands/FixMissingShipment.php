<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Shipment;

class FixMissingShipment extends Command
{
    protected $signature = 'orders:fix-missing-shipment {order-number : Order number to fix}';
    protected $description = 'Fix orders that have label_status=purchased but no shipment record';

    public function handle()
    {
        $orderNumber = $this->argument('order-number');
        
        $this->info("🔍 Checking order: {$orderNumber}");
        $this->newLine();
        
        $order = Order::where('order_number', $orderNumber)->first();
        
        if (!$order) {
            $this->error("❌ Order {$orderNumber} not found!");
            return Command::FAILURE;
        }
        
        // Check if shipment exists
        $hasShipment = Shipment::where('order_id', $order->id)
            ->where('label_status', 'active')
            ->exists();
        
        if ($hasShipment) {
            $this->info("✅ Order already has an active shipment.");
            return Command::SUCCESS;
        }
        
        // Check if order has label information
        if (empty($order->label_id) && empty($order->label_status) || $order->label_status !== 'purchased') {
            $this->warn("⚠️  Order doesn't have label information (label_status: {$order->label_status}, label_id: {$order->label_id})");
            $this->info("This order should appear in Awaiting Shipment, not Awaiting Print.");
            
            // Reset printing_status if it's incorrectly set
            if ($order->printing_status == 1) {
                $order->update(['printing_status' => 0]);
                $this->info("✅ Reset printing_status to 0");
            }
            
            return Command::SUCCESS;
        }
        
        // Order has label but no shipment - create shipment record
        $this->warn("⚠️  Order has label_status='purchased' but no shipment record!");
        $this->info("Creating missing shipment record...");
        
        try {
            DB::beginTransaction();
            
            // Create shipment record
            $shipment = Shipment::create([
                'order_id' => $order->id,
                'tracking_number' => $order->tracking_number,
                'carrier' => $order->shipping_carrier ?: 'Standard',
                'label_id' => $order->label_id,
                'service_type' => $order->shipping_service,
                'label_url' => null, // Will be set if available
                'shipment_status' => 'created',
                'label_data' => json_encode([
                    'order_id' => $order->id,
                    'label_id' => $order->label_id,
                    'tracking_number' => $order->tracking_number,
                ]),
                'ship_date' => $order->ship_date ?? now(),
                'cost' => $order->shipping_cost ?? 0,
                'currency' => 'USD',
                'label_status' => 'active',
                'void_status' => 'active',
                'created_by' => 1, // System user
            ]);
            
            // Update order status
            $order->update([
                'order_status' => 'Shipped',
                'printing_status' => 1,
                'fulfillment_status' => 'shipped',
            ]);
            
            DB::commit();
            
            $this->info("✅ Created shipment record (ID: {$shipment->id})");
            $this->info("✅ Updated order status to 'Shipped' and printing_status to 1");
            $this->newLine();
            $this->info("✅ Order should now appear in Awaiting Print page!");
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error("❌ Failed to create shipment: " . $e->getMessage());
            return Command::FAILURE;
        }
        
        return Command::SUCCESS;
    }
}

