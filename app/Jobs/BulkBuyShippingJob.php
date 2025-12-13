<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Services\ShippingLabelService;
use Illuminate\Support\Facades\Log;
use App\Models\Order;

class BulkBuyShippingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderIds;
    protected $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $orderIds, int $userId)
    {
        $this->orderIds = $orderIds;
        $this->userId = $userId;
    }

    /**
     * Make the job unique based on the batch of orders.
     */
    public function uniqueId()
    {
        // This ensures the exact same batch cannot be queued twice
        return implode('-', $this->orderIds);
    }

    /**
     * Execute the job.
     */
    // public function handle(ShippingLabelService $shippingLabelService)
    // {
    //     $processedOrders = [];

    //     foreach ($this->orderIds as $orderId) {
    //         $locked = Order::where('id', $orderId)
    //             ->where('queue', 0) 
    //             ->update([
    //                 'queue' => 1,
    //                 'queue_started_at' => now()
    //             ]);

    //         if ($locked === 0) {
    //             Log::info("Order {$orderId} is already being processed, skipping.");
    //             continue;
    //         }

    //         try {
    //             $labels = $shippingLabelService->createLabels([$orderId]);

    //             Log::info("Shipping purchased for order {$orderId}", [
    //                 'labels' => $labels
    //             ]);

    //             $processedOrders[] = $orderId;
    //         } catch (\Exception $e) {
    //             Log::error("Error processing order {$orderId}: " . $e->getMessage());
    //         } finally {
    //             Order::where('id', $orderId)->update(['queue' => 0]);
    //         }
    //     }

    //     if (empty($processedOrders)) {
    //         Log::info("No orders were processed in this job.");
    //     }
    // }
    public function handle(ShippingLabelService $shippingLabelService)
    {
        try {
            // Update processing start timestamp
            Order::whereIn('id', $this->orderIds)
                ->update(['queue_started_at' => now()]);

            // Process all orders
            $result = $shippingLabelService->createLabels($this->orderIds,$this->userId);

            Log::info("Bulk shipping processed", [
                'user_id' => $this->userId,
                'summary' => $result['summary'] ?? []
            ]);
        } catch (\Exception $e) {
            Log::error("Bulk shipping job error: " . $e->getMessage(), [
                'user_id' => $this->userId,
                'order_ids' => $this->orderIds
            ]);
        } finally {
            // Unlock orders when job finishes
            Order::whereIn('id', $this->orderIds)
                ->update(['queue' => 0]);
        }
    }


}
