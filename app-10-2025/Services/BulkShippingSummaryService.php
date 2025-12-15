<?php

namespace App\Services;

use App\Models\Order;
use App\Models\BulkShippingHistory;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class BulkShippingSummaryService
{
    /**
     * Send bulk shipping summary email
     *
     * @param BulkShippingHistory $history
     * @param array $summary
     * @param array $successOrderIds
     * @param array $failedOrderIds
     */
    public function sendSummaryEmail(
        BulkShippingHistory $history,
        array $summary,
        array $successOrderIds,
        array $failedOrderIds
    ) {
        $successOrders = $this->fetchOrdersWithShipment($successOrderIds);
        $failedOrders  = $this->fetchOrdersWithShipment($failedOrderIds);

        try {
            Mail::send('emails.bulk_shipping_summary', [
                'history'       => $history,
                'summary'       => $summary,
                'successOrders' => $successOrders,
                'failedOrders'  => $failedOrders,
            ], function ($message) use ($history) {
                $message->to([
                        'priyeshsurana8@gmail.com',
                        'mgr-operations@5core.com',
                        'shipping@5core.com',
                        // 'labelshreya2023@gmail.com'
                    ])
                    ->from('software10@5core.com', 'ShipHub System')
                    ->subject("Bulk Shipping Summary - " . ucfirst($history->status));
            });

            // Log success
            Log::info("Bulk shipping summary email sent successfully for history ID: {$history->id}");
        } catch (\Exception $e) {
            // Log failure
            Log::error("Failed to send bulk shipping summary email for history ID: {$history->id}. Error: " . $e->getMessage());

            // Optionally rethrow the exception if you want the command to mark failure
            throw $e;
        }
    }

    /**
     * Fetch orders with shipments info
     */
    protected function fetchOrdersWithShipment(array $orderIds)
    {
        if (empty($orderIds)) {
            return collect(); // return empty collection if no order IDs
        }

          return Order::query()
        ->select(
            'orders.id',
            'orders.order_number',
            'orders.marketplace',
            'shipments.label_url',
            'shipments.tracking_url',
            'shipments.tracking_number'
        )
        ->leftJoin('shipments', function ($join) {
            $join->on('shipments.order_id', '=', 'orders.id')
                 ->where('shipments.label_status', 'active')
                 ->where('shipments.void_status', 'active');
        })
        ->whereIn('orders.id', $orderIds)
        ->get();
    }
}
