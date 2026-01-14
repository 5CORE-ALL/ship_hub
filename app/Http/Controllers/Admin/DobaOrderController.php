<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DobaOrderController extends Controller
{
    /**
     * Display DOBA orders page
     */
    public function index()
    {
        return view('admin.orders.doba_orders');
    }

    /**
     * Get DOBA orders data for DataTables
     */
    public function getDobaOrdersData(Request $request)
    {
        $baseQuery = Order::query()
            ->leftJoin('order_items', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.marketplace', 'doba')
            ->where(function ($q) {
                $q->where('orders.doba_label_required', true)
                  ->orWhere('orders.doba_label_provided', true);
            })
            ->select(
                'orders.*',
                \DB::raw('MAX(order_items.sku) as sku'),
                \DB::raw('MAX(order_items.product_name) as product_name')
            )
            ->groupBy('orders.id');

        // Filter by label type
        if ($request->has('label_type') && $request->label_type !== 'all') {
            if ($request->label_type === 'required') {
                $baseQuery->where('orders.doba_label_required', true)
                          ->where('orders.doba_label_provided', false);
            } elseif ($request->label_type === 'provided') {
                $baseQuery->where('orders.doba_label_provided', true);
            }
        }

        // Date filters
        if (!empty($request->from_date)) {
            $baseQuery->whereDate('orders.order_date', '>=', $request->from_date);
        }
        if (!empty($request->to_date)) {
            $baseQuery->whereDate('orders.order_date', '<=', $request->to_date);
        }

        // Search
        if (!empty($request->search['value'])) {
            $search = $request->search['value'];
            $baseQuery->where(function ($q) use ($search) {
                $q->where('orders.order_number', 'like', "%{$search}%")
                  ->orWhere('order_items.sku', 'like', "%{$search}%")
                  ->orWhere('order_items.product_name', 'like', "%{$search}%")
                  ->orWhere('orders.recipient_name', 'like', "%{$search}%");
            });
        }

        // Count total records
        $totalRecords = $baseQuery->count();

        // Ordering
        if (!empty($request->order)) {
            $orderColumnIndex = $request->order[0]['column'];
            $orderColumnName = $request->columns[$orderColumnIndex]['data'] ?? 'orders.created_at';
            $orderDir = $request->order[0]['dir'];
            
            // Map column names
            if ($orderColumnName === 'order_number') {
                $orderColumnName = 'orders.order_number';
            } elseif ($orderColumnName === 'order_date') {
                $orderColumnName = 'orders.order_date';
            } elseif ($orderColumnName === 'sku') {
                $orderColumnName = 'order_items.sku';
            } else {
                $orderColumnName = 'orders.created_at';
            }
            
            $baseQuery->orderBy($orderColumnName, $orderDir);
        } else {
            $baseQuery->orderBy('orders.created_at', 'desc');
        }

        // Pagination
        $orders = $baseQuery
            ->skip($request->start ?? 0)
            ->take($request->length ?? 25)
            ->get();

        // Format data for DataTables
        $data = $orders->map(function ($order) {
            $labelType = '';
            $labelStatus = '';
            
            if ($order->doba_label_required && !$order->doba_label_provided) {
                $labelType = 'required';
                $labelStatus = '<span class="badge bg-warning">Label Required</span>';
            } elseif ($order->doba_label_provided) {
                $labelType = 'provided';
                $labelStatus = '<span class="badge bg-info">Label Provided</span>';
            }

            return [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'order_date' => $order->order_date ? $order->order_date->format('Y-m-d H:i') : '',
                'order_age' => $order->order_age ?? 0,
                'sku' => $order->sku ?? $order->item_sku ?? '',
                'product_name' => $order->product_name ?? $order->item_name ?? '',
                'recipient_name' => $order->recipient_name ?? '',
                'quantity' => $order->quantity ?? 1,
                'order_total' => number_format($order->order_total ?? 0, 2),
                'label_type' => $labelType,
                'label_status' => $labelStatus,
                'doba_label_file' => $order->doba_label_file,
                'doba_label_sku' => $order->doba_label_sku,
                'ship_address1' => $order->ship_address1 ?? '',
                'ship_city' => $order->ship_city ?? '',
                'ship_state' => $order->ship_state ?? '',
                'ship_postal_code' => $order->ship_postal_code ?? '',
                'ship_country' => $order->ship_country ?? '',
            ];
        });

        return response()->json([
            'draw' => intval($request->draw ?? 1),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecords,
            'data' => $data,
        ]);
    }

    /**
     * Move orders to purchase label section
     * Handles both label required and label provided orders
     */
    public function moveToPurchaseLabel(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
        ]);

        try {
            DB::beginTransaction();

            $orderIds = $request->order_ids;
            
            // Get orders that are either:
            // 1. Label required (need label) OR
            // 2. Label provided (customer provided label, ready after SKU edit)
            $orders = Order::whereIn('id', $orderIds)
                ->where('marketplace', 'doba')
                ->where(function($q) {
                    $q->where(function($subQ) {
                        // Label required orders
                        $subQ->where('doba_label_required', true)
                             ->where('doba_label_provided', false);
                    })->orWhere(function($subQ) {
                        // Label provided orders (ready to move after SKU edit)
                        $subQ->where('doba_label_provided', true);
                    });
                })
                ->get();

            $movedCount = 0;
            foreach ($orders as $order) {
                // Update order to indicate it's ready for label purchase
                $order->update([
                    'order_status' => 'awaiting_label_purchase',
                ]);
                $movedCount++;
            }

            DB::commit();

            if ($movedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No eligible orders found. Orders must be either "Label Required" or "Label Provided" to move to purchase label section.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $movedCount . ' order(s) moved to purchase label section.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error moving DOBA orders to purchase label', [
                'error' => $e->getMessage(),
                'order_ids' => $request->order_ids,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to move orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload customer-provided label
     */
    public function uploadLabel(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'label_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'sku' => 'nullable|string|max:255',
        ]);

        try {
            $order = Order::where('id', $request->order_id)
                ->where('marketplace', 'doba')
                ->firstOrFail();

            if ($request->hasFile('label_file')) {
                $file = $request->file('label_file');
                $fileName = 'doba_labels/' . $order->order_number . '_' . time() . '.' . $file->getClientOriginalExtension();
                $path = $file->storeAs('public', $fileName);

                $order->update([
                    'doba_label_provided' => true,
                    'doba_label_file' => $fileName,
                    'doba_label_sku' => $request->sku ?? $order->item_sku,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Label uploaded successfully.',
                    'file_path' => $fileName,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'No file uploaded.',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Error uploading DOBA label', [
                'error' => $e->getMessage(),
                'order_id' => $request->order_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload label: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Edit SKU in customer-provided label
     */
    public function editLabelSku(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'sku' => 'required|string|max:255',
        ]);

        try {
            $order = Order::where('id', $request->order_id)
                ->where('marketplace', 'doba')
                ->where('doba_label_provided', true)
                ->firstOrFail();

            $order->update([
                'doba_label_sku' => $request->sku,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'SKU updated successfully.',
            ]);
        } catch (\Exception $e) {
            Log::error('Error editing DOBA label SKU', [
                'error' => $e->getMessage(),
                'order_id' => $request->order_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update SKU: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Move edited label to purchase label section
     * This is specifically for label provided orders that have been edited
     */
    public function moveToPurchaseLabelFromEdit(Request $request)
    {
        $request->validate([
            'order_ids' => 'required|array',
            'order_ids.*' => 'exists:orders,id',
        ]);

        try {
            DB::beginTransaction();

            $orderIds = $request->order_ids;
            // For label provided orders, we require SKU to be set (edited)
            // Label file is optional as it might be a tracking number only
            $orders = Order::whereIn('id', $orderIds)
                ->where('marketplace', 'doba')
                ->where('doba_label_provided', true)
                ->whereNotNull('doba_label_sku')
                ->get();

            $movedCount = 0;
            foreach ($orders as $order) {
                // Update order to indicate it's ready for label purchase
                $order->update([
                    'order_status' => 'awaiting_label_purchase',
                ]);
                $movedCount++;
            }

            DB::commit();

            if ($movedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'No eligible orders found. Label provided orders must have SKU edited before moving to purchase label section.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => $movedCount . ' order(s) moved to purchase label section.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error moving DOBA orders to purchase label from edit', [
                'error' => $e->getMessage(),
                'order_ids' => $request->order_ids,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to move orders: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download customer-provided label
     */
    public function downloadLabel($orderId)
    {
        try {
            $order = Order::where('id', $orderId)
                ->where('marketplace', 'doba')
                ->where('doba_label_provided', true)
                ->whereNotNull('doba_label_file')
                ->firstOrFail();

            $filePath = storage_path('app/public/' . $order->doba_label_file);
            
            if (!file_exists($filePath)) {
                abort(404, 'Label file not found');
            }

            return response()->download($filePath);
        } catch (\Exception $e) {
            Log::error('Error downloading DOBA label', [
                'error' => $e->getMessage(),
                'order_id' => $orderId,
            ]);

            abort(500, 'Failed to download label');
        }
    }
}
