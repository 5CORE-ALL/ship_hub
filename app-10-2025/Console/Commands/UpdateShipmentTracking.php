<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\ProcessShipmentTracking;

class UpdateShipmentTracking extends Command
{
    protected $signature = 'shipments:update-tracking';
    protected $description = 'Dispatch shipment tracking updates to queue';

    public function handle()
    {
        $this->info('🔹 Dispatching shipment tracking updates to queue...');

        $shipments = DB::table('shipments as s')
            ->join('orders as o', 's.order_id', '=', 'o.id')
            ->where('s.tracking_updated', 0)
            ->where('o.is_manual', 0)
            ->where('s.queue', 0)
            ->whereNotNull('s.tracking_number')  
            ->where('s.tracking_number', '<>', '')
            ->where('o.marketplace', '<>', 'amazon')
            ->orderBy('s.id', 'desc')
            ->select('s.*', 'o.is_manual', 'o.store_id', 'o.marketplace','o.order_number')
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('⚠️ No shipments found to dispatch.');
            return 0;
        }

        foreach ($shipments as $shipment) {
            ProcessShipmentTracking::dispatch($shipment);
             DB::table('shipments')
                ->where('id', $shipment->id)
                ->update(['queue' => 1]);
        }

        $this->info("✅ Dispatched {$shipments->count()} shipments to queue!");
        return 0;
    }
}
