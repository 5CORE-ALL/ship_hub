<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessShipmentTracking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $shipment;

    /**
     * Create a new job instance.
     */
    public function __construct($shipment)
    {
        $this->shipment = $shipment;
    }

    /**
     * Execute the job.
     */
    public function handle(\App\Repositories\FulfillmentRepository $fulfillmentRepo)
    {
        try {
            $fulfillmentRepo->createFulfillment(
                $this->shipment->marketplace,
                $this->shipment->store_id ?? 0,
                $this->shipment->order_number,
                $this->shipment->tracking_number ?? null
            );

            // Mark shipment as updated
            DB::table('shipments')
                ->where('id', $this->shipment->id)
                ->update(['tracking_updated' => 1]);

            Log::info("✅ Shipment ID {$this->shipment->id} processed in queue.");
        } catch (\Exception $e) {
            Log::warning("⚠️ Failed to update {$this->shipment->marketplace} order fulfillment", [
                'shipment_id'  => $this->shipment->id,
                'order_number' => $this->shipment->order_number,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
