<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\InventoryService;
use App\Models\DimensionData;

class InventoryController extends Controller
{
    protected $inventoryService;

    public function __construct(InventoryService $inventoryService)
    {
        $this->inventoryService = $inventoryService;
    }

    /**
     * Get INV values for all SKUs in dimension_data
     * Reads directly from invent database
     */
    public function getInvForDimensionSkus(Request $request)
    {
        $skus = $request->input('skus'); // Optional: filter by specific SKUs
        
        $invData = $this->inventoryService->getInvForDimensionSkus($skus);
        
        return response()->json([
            'success' => true,
            'data' => $invData->values(),
            'count' => $invData->count(),
        ]);
    }

    /**
     * Get INV value for a single SKU
     * Reads directly from invent database
     */
    public function getInvForSku(Request $request, string $sku)
    {
        $invData = $this->inventoryService->getInvForSku($sku);
        
        if (!$invData) {
            return response()->json([
                'success' => false,
                'message' => 'SKU not found in invent database',
            ], 404);
        }
        
        return response()->json([
            'success' => true,
            'data' => $invData,
        ]);
    }

    /**
     * Get dimension data with INV values
     * Reads dimension_data from shiphub and INV from invent
     */
    public function getDimensionDataWithInv(Request $request)
    {
        $data = $this->inventoryService->getDimensionDataWithInv();
        
        return response()->json([
            'success' => true,
            'data' => $data->values(),
            'count' => $data->count(),
        ]);
    }

    /**
     * Check if SKU has inventory
     * Reads directly from invent database
     */
    public function checkInventory(Request $request, string $sku)
    {
        $hasInventory = $this->inventoryService->hasInventory($sku);
        
        return response()->json([
            'success' => true,
            'sku' => $sku,
            'has_inventory' => $hasInventory,
        ]);
    }
}
