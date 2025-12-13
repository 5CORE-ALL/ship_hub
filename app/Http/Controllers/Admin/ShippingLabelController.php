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
    Log::info('Bulk Buy Shipping started.', [
        'input_order_ids' => $request->input('order_ids', [])
    ]);

    $orderIds = $request->input('order_ids', []);

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

    BulkBuyShippingJob::dispatch($orderIds,Auth::user()->id ?? 1);
    Log::info('BulkBuyShippingJob dispatched successfully.', [
        'order_ids' => $orderIds
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Bulk shipping job has been queued. It will run in the background.'
    ]);
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
