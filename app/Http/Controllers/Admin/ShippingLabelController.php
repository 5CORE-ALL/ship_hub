<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ShippingLabelService;
use Illuminate\Support\Facades\Log;
use App\Models\OrderShippingRate;
use App\Models\Order;
use App\Jobs\BulkBuyShippingJob;
use Illuminate\Support\Facades\Auth;
class ShippingLabelController extends Controller
{
    protected ShippingLabelService $shippingLabelService;

    public function __construct(ShippingLabelService $shippingLabelService)
    {
        $this->shippingLabelService = $shippingLabelService;
    }
    // public function bulkBuyShipping(Request $request)
    // {
    //     $orderIds = $request->input('order_ids', []);
    //     if (empty($orderIds)) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'No orders selected.'
    //         ]);
    //     }

    //     try {
    //         $labels = $this->shippingLabelService->createLabels($orderIds);
    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Bulk shipping purchased successfully.',
    //             'labels'  => $labels
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Bulk shipping error: ' . $e->getMessage(), ['order_ids' => $orderIds]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to buy bulk shipping: ' . $e->getMessage()
    //         ]);
    //     }
  //   // }
  //   public function bulkBuyShipping(Request $request)
  //  {
  //   $orderIds = $request->input('order_ids', []);

  //   if (empty($orderIds)) {
  //       return response()->json([
  //           'success' => false,
  //           'message' => 'No orders selected.'
  //       ]);
  //   }
  //   $lockedOrders = Order::whereIn('id', $orderIds)
  //       ->where('queue', 0)
  //       ->update([
  //           'queue' => 1,
  //           'queue_started_at' => now()
  //       ]);

  //   if ($lockedOrders === 0) {
  //       return response()->json([
  //           'success' => false,
  //           'message' => 'Selected orders are already being processed.'
  //       ]);
  //   }
  //   BulkBuyShippingJob::dispatch($orderIds);

  //   return response()->json([
  //       'success' => true,
  //       'message' => 'Bulk shipping job has been queued. It will run in the background.'
  //   ]);
  // }
    public function bulkBuyShipping(Request $request)
{
    // Support both old format (order_ids array) and new format (orders array with rate_type)
    $orders = $request->input('orders', []);
    $orderIds = $request->input('order_ids', []);
    
    // Convert old format to new format if needed
    if (!empty($orderIds) && empty($orders)) {
        $orders = array_map(function($orderId) {
            return ['order_id' => $orderId, 'rate_type' => 'D'];
        }, $orderIds);
    }
    
    // Extract order IDs and create rate info map
    $orderIds = array_column($orders, 'order_id');
    $rateInfoMap = [];
    foreach ($orders as $orderData) {
        $rateInfoMap[$orderData['order_id']] = [
            'rate_type' => $orderData['rate_type'] ?? 'D',
            'rate_id' => $orderData['rate_id'] ?? null,
            'rate_info' => $orderData['rate_info'] ?? null
        ];
    }
    
    Log::info('Bulk Buy Shipping started.', [
        'orders' => $orders,
        'order_ids' => $orderIds,
        'rate_info_map' => $rateInfoMap
    ]);

    if (empty($orderIds)) {
        Log::warning('No orders selected for bulk shipping.');
        return response()->json([
            'success' => false,
            'message' => 'No orders selected.'
        ]);
    }

    $lockedOrders = Order::whereIn('id', $orderIds)
        ->where('queue', 0)
        ->update([
            'queue' => 1,
            'queue_started_at' => now()
        ]);

    Log::info('Orders locked for bulk shipping.', [
        'order_ids' => $orderIds,
        'locked_count' => $lockedOrders
    ]);

    if ($lockedOrders === 0) {
        Log::warning('Selected orders already in queue or being processed.', [
            'order_ids' => $orderIds
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Selected orders are already being processed.'
        ]);
    }

    try {
        // Pass rate info map to createLabels
        $result = $this->shippingLabelService->createLabels($orderIds, Auth::user()->id ?? 1, $rateInfoMap);

        Log::info('Bulk shipping completed.', [
            'summary' => $result['summary'] ?? []
        ]);

        // Extract success and failed order IDs from result
        $successOrderIds = $result['summary']['success_order_ids'] ?? [];
        $failedOrderIds = $result['summary']['failed_order_ids'] ?? [];
        
        // Build response message with details
        $message = 'Bulk shipping processing completed.';
        if (!empty($successOrderIds) && !empty($failedOrderIds)) {
            $message = "Bulk shipping completed with partial success. {$result['summary']['success_count']} succeeded, {$result['summary']['failed_count']} failed.";
        } elseif (!empty($failedOrderIds)) {
            $message = "Bulk shipping completed but all orders failed. Please check the logs for details.";
        }

        return response()->json([
            'success' => !empty($successOrderIds), // True if at least one succeeded
            'message' => $message,
            'labels' => $result,
            'summary' => $result['summary'] ?? []
        ]);
    } catch (\Exception $e) {
        Log::error('Bulk shipping error: ' . $e->getMessage(), [
            'order_ids' => $orderIds,
            'trace' => $e->getTraceAsString()
        ]);

        // CRITICAL: Ensure all orders are unlocked even on exception
        try {
            $unlockedCount = Order::whereIn('id', $orderIds)->update(['queue' => 0]);
            Log::info('Orders unlocked after exception', [
                'order_ids' => $orderIds,
                'unlocked_count' => $unlockedCount
            ]);
        } catch (\Exception $unlockException) {
            Log::error('CRITICAL: Failed to unlock orders after exception', [
                'order_ids' => $orderIds,
                'unlock_error' => $unlockException->getMessage()
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Failed to buy bulk shipping: ' . $e->getMessage(),
            'order_ids' => $orderIds // Include order IDs so frontend knows which orders to check
        ], 500);
    } finally {
        // CRITICAL: Double-check that all orders are unlocked
        // This ensures orders are never stuck in queue state
        try {
            $stillLocked = Order::whereIn('id', $orderIds)
                ->where('queue', 1)
                ->pluck('id')
                ->toArray();
            
            if (!empty($stillLocked)) {
                Log::warning('Some orders were still locked in finally block, unlocking them', [
                    'still_locked_ids' => $stillLocked
                ]);
                Order::whereIn('id', $stillLocked)->update(['queue' => 0]);
            }
        } catch (\Exception $finallyException) {
            Log::error('CRITICAL: Error in finally block while unlocking orders', [
                'error' => $finallyException->getMessage(),
                'order_ids' => $orderIds
            ]);
        }
    }
}

    public function verifyAddress(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id'
        ]);

        $order = Order::findOrFail($request->order_id);
        $order->is_address_verified = 1;
        $order->save();

        return response()->json(['success' => true, 'message' => 'Address verification updated successfully']);
    }
    public function updateCarrier(Request $request)
    {
        $orderId = $request->order_id;
        $rateId  = $request->rate_id;
        OrderShippingRate::where('order_id', $orderId)->update(['is_cheapest' => 0]);
        $updated = OrderShippingRate::where('id', $rateId)->update(['is_cheapest' => 1]);
        if ($updated) {
            return response()->json([
                'success' => true,
                'message' => 'Cheapest rate updated successfully.'
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Rate not found or update failed.'
            ]);
        }
    }
}
