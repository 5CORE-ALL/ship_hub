<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class CheckAwaitingPrintOrders extends Command
{
    protected $signature = 'orders:check-awaiting-print {order-number? : Optional order number to check}';
    
    protected $description = 'Check which orders are still showing in awaiting print page';

    public function handle()
    {
        $orderNumber = $this->argument('order-number');
        
        $query = DB::table('orders as o')
            ->join('shipments as s', function ($join) {
                $join->on('s.order_id', '=', 'o.id')
                     ->where('s.label_status', '=', 'active');
            })
            ->whereRaw('LOWER(o.order_status) = ?', ['shipped'])
            ->where('o.printing_status', 1)
            ->select(
                'o.id',
                'o.order_number',
                'o.order_status',
                'o.printing_status',
                'o.marketplace',
                'o.order_date',
                's.created_at as shipment_created_at',
                's.label_status',
                DB::raw('TIMESTAMPDIFF(HOUR, s.created_at, NOW()) as hours_old'),
                DB::raw('TIMESTAMPDIFF(DAY, s.created_at, NOW()) as shipment_days_old'),
                DB::raw('TIMESTAMPDIFF(DAY, o.order_date, NOW()) as order_days_old')
            );

        if ($orderNumber) {
            $query->where('o.order_number', $orderNumber);
        }

        $orders = $query->orderBy('s.created_at', 'asc')->get();

        if ($orders->isEmpty()) {
            $this->info("✅ No orders found with printing_status = 1 that match awaiting print criteria.");
            if ($orderNumber) {
                $this->warn("   Order number '{$orderNumber}' is not in awaiting print.");
            }
            return Command::SUCCESS;
        }

        $this->info("📋 Found {$orders->count()} order(s) still in awaiting print:");
        $this->newLine();

        $tableData = [];
        foreach ($orders as $order) {
            $tableData[] = [
                'ID' => $order->id,
                'Order Number' => $order->order_number,
                'Marketplace' => $order->marketplace,
                'Order Status' => $order->order_status,
                'Printing Status' => $order->printing_status,
                'Order Date' => $order->order_date ? Carbon::parse($order->order_date)->format('Y-m-d') : 'N/A',
                'Order Days Old' => $order->order_days_old ?? 'N/A',
                'Shipment Created' => Carbon::parse($order->shipment_created_at)->format('Y-m-d H:i:s'),
                'Shipment Days Old' => $order->shipment_days_old,
                'Hours Old' => $order->hours_old,
            ];
        }

        $this->table(
            ['ID', 'Order Number', 'Marketplace', 'Order Status', 'Printing Status', 'Order Date', 'Order Days Old', 'Shipment Created', 'Shipment Days Old', 'Hours Old'],
            $tableData
        );

        // Check if any are older than 36 hours
        $oldOrders = $orders->filter(function($order) {
            return $order->hours_old > 36;
        });

        // Check for very old order dates
        $veryOldOrderDates = $orders->filter(function($order) {
            return isset($order->order_days_old) && $order->order_days_old > 90;
        });

        if ($oldOrders->count() > 0) {
            $this->newLine();
            $this->warn("⚠️  Found {$oldOrders->count()} order(s) older than 36 hours that should have been updated!");
            $this->info("   These orders may have been created/updated after the cleanup command ran.");
        }

        if ($veryOldOrderDates->count() > 0) {
            $this->newLine();
            $this->warn("⚠️  Found {$veryOldOrderDates->count()} order(s) with order_date older than 90 days!");
            $this->info("   These orders have old order dates but recent shipment dates.");
            foreach ($veryOldOrderDates as $order) {
                $this->line("   - Order {$order->order_number}: Order date is {$order->order_days_old} days old, but shipment is {$order->shipment_days_old} days old");
            }
        }

        return Command::SUCCESS;
    }
}
