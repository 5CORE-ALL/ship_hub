<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ShippingLabelService;
use App\Services\OrderLockService;
use Illuminate\Support\Facades\Log;
use App\Models\OrderShippingRate;
use App\Models\Order;
use App\Jobs\BulkBuyShippingJob;
use Illuminate\Support\Facades\Auth;
class ShippingLabelController extends Controller
{
    protected ShippingLabelService $shippingLabelService;
    protected OrderLockService $orderLockService;

    public function __construct(ShippingLabelService $shippingLabelService, OrderLockService $orderLockService)
    {
        $this->shippingLabelService = $shippingLabelService;
        $this->orderLockService = $orderLockService;
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
        'rate_info_map' => $rateInfoMap,
        'user_id' => Auth::user()->id ?? 1
    ]);
    
    // Validate that orders exist and have required data
    $existingOrders = Order::whereIn('id', $orderIds)->pluck('id')->toArray();
    $missingOrders = array_diff($orderIds, $existingOrders);
    if (!empty($missingOrders)) {
        Log::warning('Some order IDs do not exist', ['missing_order_ids' => $missingOrders]);
        $missingList = implode(', ', array_slice($missingOrders, 0, 10));
        if (count($missingOrders) > 10) {
            $missingList .= ' and ' . (count($missingOrders) - 10) . ' more';
        }
        return response()->json([
            'success' => false,
            'error_type' => 'validation_error',
            'message' => '⚠️ Some selected orders do not exist in the system.',
            'instructions' => '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #fff3cd; border-radius: 5px; border-left: 4px solid #ffc107;">'
                . '<strong>What happened?</strong><br>'
                . 'The following orders could not be found: ' . $missingList . '<br><br>'
                . '<strong>What to do next?</strong><br>'
                . '1. Refresh the page to get the latest order list<br>'
                . '2. Remove the invalid orders from your selection<br>'
                . '3. Try again with only valid orders<br>'
                . '</div>',
            'missing_order_ids' => $missingOrders
        ], 400);
    }

    if (empty($orderIds)) {
        Log::warning('No orders selected for bulk shipping.');
        return response()->json([
            'success' => false,
            'error_type' => 'validation_error',
            'message' => '⚠️ No orders selected for bulk shipping.',
            'instructions' => '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #fff3cd; border-radius: 5px; border-left: 4px solid #ffc107;">'
                . '<strong>What to do next?</strong><br>'
                . '1. Select one or more orders from the list<br>'
                . '2. Click the "Buy Bulk Shipping" button again<br>'
                . '</div>'
        ]);
    }

    // ATOMIC LOCK: Use OrderLockService to atomically lock orders
    // This prevents race conditions where multiple requests try to lock the same orders
    $lockResult = $this->orderLockService->lockOrders($orderIds, 5);
    $lockableOrderIds = $lockResult['locked_ids'];
    $failedToLockIds = $lockResult['failed_ids'];
    $stuckOrderIds = $lockResult['stuck_ids'];

    if (empty($lockableOrderIds)) {
        Log::warning('Selected orders already in queue or being processed.', [
            'order_ids' => $orderIds,
            'failed_to_lock_ids' => $failedToLockIds,
            'stuck_order_ids' => $stuckOrderIds
        ]);
        
        // Build detailed error message with instructions
        $errorType = 'concurrency_error';
        $message = '⚠️ Selected orders are currently being processed by another request.';
        $instructions = '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #f8f9fa; border-radius: 5px;">';
        $instructions .= '<strong>What happened?</strong><br>';
        $instructions .= 'Some or all of the selected orders are already being processed. This can happen if:<br>';
        $instructions .= '• Another user is processing the same orders<br>';
        $instructions .= '• You clicked the button multiple times<br>';
        $instructions .= '• A previous request is still processing<br><br>';
        $instructions .= '<strong>What to do next?</strong><br>';
        $instructions .= '1. Wait 30-60 seconds for the current processing to complete<br>';
        $instructions .= '2. Refresh the page to see updated order status<br>';
        $instructions .= '3. Try again with only the orders that are not yet processed<br>';
        $instructions .= '4. If the issue persists, check the order status in the system<br>';
        $instructions .= '</div>';
        
        if (!empty($failedToLockIds)) {
            $message .= '<br><br><strong>Orders that could not be locked:</strong> ' . implode(', ', array_slice($failedToLockIds, 0, 10));
            if (count($failedToLockIds) > 10) {
                $message .= ' and ' . (count($failedToLockIds) - 10) . ' more';
            }
        }
        
        return response()->json([
            'success' => false,
            'error_type' => $errorType,
            'message' => $message,
            'instructions' => $instructions,
            'failed_to_lock_ids' => $failedToLockIds,
            'stuck_order_ids' => $stuckOrderIds
        ]);
    }

    Log::info('Orders locked for bulk shipping.', [
        'all_order_ids' => $orderIds,
        'lockable_order_ids' => $lockableOrderIds,
        'failed_to_lock_ids' => $failedToLockIds,
        'stuck_order_ids' => $stuckOrderIds,
        'locked_count' => count($lockableOrderIds)
    ]);

    // Filter rateInfoMap to only include locked orders
    $lockedRateInfoMap = [];
    foreach ($lockableOrderIds as $orderId) {
        if (isset($rateInfoMap[$orderId])) {
            $lockedRateInfoMap[$orderId] = $rateInfoMap[$orderId];
        }
    }

    try {
        // Pass only the successfully locked order IDs to createLabels
        $result = $this->shippingLabelService->createLabels($lockableOrderIds, Auth::user()->id ?? 1, $lockedRateInfoMap);

        Log::info('Bulk shipping completed.', [
            'summary' => $result['summary'] ?? []
        ]);

        // Extract success and failed order IDs from result
        $successOrderIds = $result['summary']['success_order_ids'] ?? [];
        $failedOrderIds = $result['summary']['failed_order_ids'] ?? [];
        
        // Get detailed error messages for failed orders
        $failedOrderDetails = [];
        if (isset($result['labels']) && is_array($result['labels'])) {
            foreach ($result['labels'] as $label) {
                if (isset($label['success']) && $label['success'] === false) {
                    $failedOrderDetails[] = [
                        'order_id' => $label['order_id'] ?? 'unknown',
                        'message' => $label['message'] ?? ($label['error'] ?? 'Unknown error'),
                        'error' => $label['error'] ?? null
                    ];
                }
            }
        }
        
        // Build response message with details
        $hasSuccess = !empty($successOrderIds);
        $hasFailures = !empty($failedOrderIds);
        
        $message = '✅ Bulk shipping processing completed successfully.';
        $instructions = '';
        $errorType = null;
        
        if ($hasSuccess && $hasFailures) {
            // Partial success
            $message = "⚠️ Bulk shipping completed with partial success. {$result['summary']['success_count']} succeeded, {$result['summary']['failed_count']} failed.";
            $errorType = 'partial_failure';
            $instructions = '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #fff3cd; border-radius: 5px; border-left: 4px solid #ffc107;">';
            $instructions .= '<strong>What happened?</strong><br>';
            $instructions .= 'Some orders were processed successfully, but others failed. Check the failed order details below.<br><br>';
            $instructions .= '<strong>What to do next?</strong><br>';
            $instructions .= '1. Review the failed orders listed below<br>';
            $instructions .= '2. Check if the failed orders have valid shipping addresses and rates<br>';
            $instructions .= '3. Fix any issues (missing rates, invalid addresses, etc.)<br>';
            $instructions .= '4. Try processing the failed orders again individually or in a smaller batch<br>';
            $instructions .= '5. Contact support if the same orders continue to fail<br>';
            $instructions .= '</div>';
        } elseif ($hasFailures && !$hasSuccess) {
            // All orders failed
            $errorType = 'complete_failure';
            $errorMessages = array_map(function($detail) {
                $orderId = $detail['order_id'] ?? 'unknown';
                $errorMsg = $detail['message'] ?? ($detail['error'] ?? 'Unknown error');
                
                // Handle different error message types
                if (is_array($errorMsg)) {
                    // Try to extract readable messages from array
                    $messages = [];
                    foreach ($errorMsg as $key => $value) {
                        if (is_string($value)) {
                            $messages[] = $value;
                        } elseif (is_array($value)) {
                            if (isset($value['text'])) {
                                $messages[] = $value['text'];
                            } elseif (isset($value['message'])) {
                                $messages[] = $value['message'];
                            } else {
                                $messages[] = json_encode($value);
                            }
                        } else {
                            $messages[] = (string)$value;
                        }
                    }
                    $errorMsg = !empty($messages) ? implode(", ", $messages) : json_encode($errorMsg);
                } elseif (is_object($errorMsg)) {
                    // Handle objects (shouldn't happen, but just in case)
                    $errorMsg = json_encode($errorMsg);
                }
                
                return "Order #{$orderId}: {$errorMsg}";
            }, $failedOrderDetails);
            
            // Categorize errors to provide specific instructions
            $hasConcurrencyErrors = false;
            $hasRateErrors = false;
            $hasAddressErrors = false;
            $hasOtherErrors = false;
            
            foreach ($failedOrderDetails as $detail) {
                $errorMsg = strtolower($detail['message'] ?? '');
                if (strpos($errorMsg, 'currently being processed') !== false || strpos($errorMsg, 'another request') !== false) {
                    $hasConcurrencyErrors = true;
                } elseif (strpos($errorMsg, 'rate') !== false || strpos($errorMsg, 'shipping') !== false) {
                    $hasRateErrors = true;
                } elseif (strpos($errorMsg, 'address') !== false || strpos($errorMsg, 'invalid') !== false) {
                    $hasAddressErrors = true;
                } else {
                    $hasOtherErrors = true;
                }
            }
            
            $message = "❌ Bulk shipping failed for all orders.";
            $instructions = '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #f8d7da; border-radius: 5px; border-left: 4px solid #dc3545;">';
            $instructions .= '<strong>What happened?</strong><br>';
            $instructions .= 'All selected orders failed to process. Common causes include:<br>';
            if ($hasConcurrencyErrors) {
                $instructions .= '• Orders are being processed by another request<br>';
            }
            if ($hasRateErrors) {
                $instructions .= '• Missing or invalid shipping rates<br>';
            }
            if ($hasAddressErrors) {
                $instructions .= '• Invalid or incomplete shipping addresses<br>';
            }
            if ($hasOtherErrors) {
                $instructions .= '• API errors or system issues<br>';
            }
            $instructions .= '<br><strong>What to do next?</strong><br>';
            $instructions .= '1. Review the detailed error messages for each order below<br>';
            if ($hasConcurrencyErrors) {
                $instructions .= '2. Wait 1-2 minutes and try again (orders may be processing)<br>';
            }
            if ($hasRateErrors) {
                $instructions .= '3. Ensure all orders have valid shipping rates fetched<br>';
                $instructions .= '4. Try fetching rates again for failed orders<br>';
            }
            if ($hasAddressErrors) {
                $instructions .= '5. Verify and fix shipping addresses for failed orders<br>';
            }
            $instructions .= '6. Try processing orders individually to identify specific issues<br>';
            $instructions .= '7. If the problem persists, contact technical support with the error details<br>';
            $instructions .= '</div>';
        }

        return response()->json([
            'success' => $hasSuccess, // Only true if at least one order succeeded
            'error_type' => $errorType,
            'message' => $message,
            'instructions' => $instructions,
            'labels' => $result,
            'summary' => $result['summary'] ?? [],
            'failed_details' => $failedOrderDetails // Include detailed error messages
        ]);
    } catch (\Exception $e) {
        Log::error('Bulk shipping error: ' . $e->getMessage(), [
            'order_ids' => $orderIds,
            'lockable_order_ids' => $lockableOrderIds,
            'trace' => $e->getTraceAsString()
        ]);

        // CRITICAL: Ensure all locked orders are unlocked even on exception
        try {
            $unlockedCount = $this->orderLockService->unlockOrders($lockableOrderIds);
            Log::info('Orders unlocked after exception', [
                'lockable_order_ids' => $lockableOrderIds,
                'unlocked_count' => $unlockedCount
            ]);
        } catch (\Exception $unlockException) {
            Log::error('CRITICAL: Failed to unlock orders after exception', [
                'lockable_order_ids' => $lockableOrderIds,
                'unlock_error' => $unlockException->getMessage()
            ]);
        }

        // Build error message with instructions for exception scenarios
        $errorType = 'system_error';
        $message = '❌ An unexpected error occurred while processing bulk shipping.';
        $instructions = '<div style="text-align: left; margin-top: 15px; padding: 15px; background-color: #f8d7da; border-radius: 5px; border-left: 4px solid #dc3545;">';
        $instructions .= '<strong>What happened?</strong><br>';
        $instructions .= 'A system error occurred during processing. This could be due to:<br>';
        $instructions .= '• Network connectivity issues<br>';
        $instructions .= '• Shipping API service unavailability<br>';
        $instructions .= '• Database connection problems<br>';
        $instructions .= '• System timeout or resource limits<br><br>';
        $instructions .= '<strong>What to do next?</strong><br>';
        $instructions .= '1. Check your internet connection<br>';
        $instructions .= '2. Wait a few minutes and try again<br>';
        $instructions .= '3. Try processing a smaller batch of orders<br>';
        $instructions .= '4. Check if the shipping service APIs are operational<br>';
        $instructions .= '5. If the error persists, contact technical support<br>';
        $instructions .= '6. Provide the following error details to support: ' . htmlspecialchars($e->getMessage()) . '<br>';
        $instructions .= '</div>';
        
        return response()->json([
            'success' => false,
            'error_type' => $errorType,
            'message' => $message,
            'instructions' => $instructions,
            'error_details' => $e->getMessage(),
            'order_ids' => $lockableOrderIds // Include order IDs so frontend knows which orders to check
        ], 500);
    } finally {
        // CRITICAL: Double-check that all locked orders are unlocked
        // This ensures orders are never stuck in queue state
        try {
            $stillLocked = Order::whereIn('id', $lockableOrderIds)
                ->where('queue', 1)
                ->pluck('id')
                ->toArray();
            
            if (!empty($stillLocked)) {
                Log::warning('Some orders were still locked in finally block, unlocking them', [
                    'still_locked_ids' => $stillLocked
                ]);
                $this->orderLockService->unlockOrders($stillLocked);
            }
        } catch (\Exception $finallyException) {
            Log::error('CRITICAL: Error in finally block while unlocking orders', [
                'error' => $finallyException->getMessage(),
                'lockable_order_ids' => $lockableOrderIds
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
