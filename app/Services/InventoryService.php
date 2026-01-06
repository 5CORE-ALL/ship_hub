<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use App\Models\DimensionData;
use App\Models\ShopifySku;

class InventoryService
{
    /**
     * Get INV values for SKUs in dimension_data
     * Reads directly from invent database
     * 
     * @param array|null $skus Optional array of SKUs to filter. If null, gets all SKUs from dimension_data
     * @return \Illuminate\Support\Collection
     */
    public function getInvForDimensionSkus(?array $skus = null)
    {
        // If SKUs not provided, get all from dimension_data
        if ($skus === null) {
            $skus = DimensionData::pluck('sku')->toArray();
        }

        if (empty($skus)) {
            return collect([]);
        }

        // Read directly from invent database
        return ShopifySku::whereIn('sku', $skus)
            ->select('sku', 'inv', 'quantity', 'price', 'variant_id', 'image_src', 
                     'available_to_sell', 'committed', 'on_hand')
            ->get()
            ->keyBy('sku');
    }

    /**
     * Get INV value for a single SKU
     * Reads directly from invent database
     * 
     * @param string $sku
     * @return array|null
     */
    public function getInvForSku(string $sku)
    {
        $shopifySku = ShopifySku::where('sku', $sku)->first();
        
        if (!$shopifySku) {
            return null;
        }

        return [
            'sku' => $shopifySku->sku,
            'inv' => $shopifySku->inv ?? 0,
            'quantity' => $shopifySku->quantity ?? 0,
            'price' => $shopifySku->price,
            'variant_id' => $shopifySku->variant_id,
            'image_src' => $shopifySku->image_src,
            'available_to_sell' => $shopifySku->available_to_sell ?? 0,
            'committed' => $shopifySku->committed ?? 0,
            'on_hand' => $shopifySku->on_hand ?? 0,
        ];
    }

    /**
     * Get dimension data with INV values
     * Reads dimension_data from shiphub and INV from invent
     * 
     * @return \Illuminate\Support\Collection
     */
    public function getDimensionDataWithInv()
    {
        // Get all dimension data from shiphub
        $dimensionData = DimensionData::all();
        
        // Get SKUs
        $skus = $dimensionData->pluck('sku')->toArray();
        
        // Get INV data from invent
        $invData = $this->getInvForDimensionSkus($skus);
        
        // Merge INV data with dimension data
        return $dimensionData->map(function ($item) use ($invData) {
            $inv = $invData->get($item->sku);
            
            return [
                'dimension' => $item->toArray(),
                'inv' => $inv ? [
                    'inv' => $inv->inv ?? 0,
                    'quantity' => $inv->quantity ?? 0,
                    'price' => $inv->price,
                    'available_to_sell' => $inv->available_to_sell ?? 0,
                    'committed' => $inv->committed ?? 0,
                    'on_hand' => $inv->on_hand ?? 0,
                ] : null,
            ];
        });
    }

    /**
     * Check if SKU has inventory (INV > 0)
     * Reads directly from invent database
     * 
     * @param string $sku
     * @return bool
     */
    public function hasInventory(string $sku): bool
    {
        $shopifySku = ShopifySku::where('sku', $sku)->first();
        return $shopifySku && ($shopifySku->inv ?? 0) > 0;
    }
}
