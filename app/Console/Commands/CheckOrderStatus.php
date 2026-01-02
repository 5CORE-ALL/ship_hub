<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\Order;
use App\Models\Shipment;

class CheckOrderStatus extends Command
{
    protected $signature = 'orders:check-status {order_number}';
    protected $description = 'Check why an order is or is not appearing in awaiting print/shipment pages';

    public function handle()
    {
        $orderNumber = $this->argument('order_number');
        
        $order = Order::where('order_number', $orderNumber)->first();
        
        if (!$order) {
            $this->error("Order {$orderNumber} not found!");
            return Command::FAILURE;
        }
        
        $this->info("Order Details for: {$orderNumber}");
        $this->table(
            ['Field', 'Value'],
            [
                ['ID', $order->id],
                ['Order Number', $order->order_number],
                ['Order Status', $order->order_status],
                ['Printing Status', $order->printing_status],
                ['Label Status', $order->label_status ?? 'NULL'],
                ['Label Source', $order->label_source ?? 'NULL'],
                ['Queue', $order->queue],
                ['Marketplace', $order->marketplace],
                ['Cancel Status', $order->cancel_status ?? 'NULL'],
            ]
        );
        
        // Check shipments
        $shipments = Shipment::where('order_id', $order->id)->get();
        $this->newLine();
        $this->info("Shipments: " . $shipments->count());
        
        if ($shipments->isNotEmpty()) {
            $shipmentData = [];
            foreach ($shipments as $shipment) {
                $shipmentData[] = [
                    'ID' => $shipment->id,
                    'Label Status' => $shipment->label_status ?? 'NULL',
                    'Shipment Status' => $shipment->shipment_status ?? 'NULL',
                    'Created At' => $shipment->created_at,
                ];
            }
            $this->table(['ID', 'Label Status', 'Shipment Status', 'Created At'], $shipmentData);
        } else {
            $this->warn('⚠️  No shipments found for this order.');
        }
        
        // Check if it should appear in Awaiting Print
        $this->newLine();
        $this->info("Awaiting Print Check:");
        $hasActiveShipment = Shipment::where('order_id', $order->id)
            ->where('label_status', 'active')
            ->exists();
        $orderStatusOk = strtolower($order->order_status) === 'shipped';
        $printingStatusOk = $order->printing_status == 1;
        
        $canShowInAwaitingPrint = $hasActiveShipment && $orderStatusOk && $printingStatusOk;
        
        if ($canShowInAwaitingPrint) {
            $this->info("✅ This order SHOULD appear in Awaiting Print page.");
        } else {
            $this->warn("⚠️  This order will NOT appear in Awaiting Print because:");
            if (!$hasActiveShipment) $this->line("   - No shipment with label_status = 'active'");
            if (!$orderStatusOk) $this->line("   - order_status is '{$order->order_status}' (needs 'shipped')");
            if (!$printingStatusOk) $this->line("   - printing_status is {$order->printing_status} (needs 1)");
        }
        
        // Check if it should appear in Awaiting Shipment
        $this->newLine();
        $this->info("Awaiting Shipment Check:");
        $canShowInAwaitingShipment = 
            $order->printing_status == 0 &&
            in_array($order->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid']) &&
            $order->queue == 0 &&
            $order->marked_as_ship == 0 &&
            !in_array($order->cancel_status, ['CANCELED', 'IN_PROGRESS']) &&
            in_array($order->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon']);
        
        if ($canShowInAwaitingShipment) {
            $this->info("✅ This order SHOULD appear in Awaiting Shipment page.");
        } else {
            $this->warn("⚠️  This order will NOT appear in Awaiting Shipment because:");
            if ($order->printing_status != 0) $this->line("   - printing_status is {$order->printing_status} (needs 0)");
            if (!in_array($order->order_status, ['Unshipped', 'unshipped', 'PartiallyShipped', 'Accepted', 'awaiting_shipment', 'Created', 'Acknowledged', 'AWAITING_SHIPMENT', 'paid'])) $this->line("   - order_status is '{$order->order_status}'");
            if ($order->queue != 0) $this->line("   - queue is {$order->queue} (needs 0)");
            if ($order->marked_as_ship != 0) $this->line("   - marked_as_ship is {$order->marked_as_ship} (needs 0)");
            if (in_array($order->cancel_status, ['CANCELED', 'IN_PROGRESS'])) $this->line("   - cancel_status is '{$order->cancel_status}'");
            if (!in_array($order->marketplace, ['ebay1', 'ebay3', 'walmart', 'PLS', 'shopify', 'Best Buy USA', "Macy's, Inc.", 'Reverb', 'aliexpress', 'tiktok', 'amazon'])) $this->line("   - marketplace is '{$order->marketplace}'");
        }
        
        return Command::SUCCESS;
    }
}

