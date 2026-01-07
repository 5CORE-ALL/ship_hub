<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\DimensionData;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ImportDimensionData extends Command implements ToCollection, WithHeadingRow
{
    protected $signature = 'dimension:import {file : Path to the Excel file}';
    protected $description = 'Import dimension and weight data from Excel file to dimension_data table';

    protected $updatedCount = 0;
    protected $createdCount = 0;
    protected $skippedCount = 0;
    protected $errors = [];

    public function handle()
    {
        $filePath = $this->argument('file');

        if (!file_exists($filePath)) {
            $this->error("❌ File not found: {$filePath}");
            return 1;
        }

        $this->info("📂 Reading Excel file: {$filePath}");
        
        try {
            Excel::import($this, $filePath);
            
            $this->info("\n✅ Import completed!");
            $this->info("   Created: {$this->createdCount} records");
            $this->info("   Updated: {$this->updatedCount} records");
            $this->info("   Skipped: {$this->skippedCount} records");
            
            if (!empty($this->errors)) {
                $this->warn("\n⚠️  Errors encountered:");
                foreach ($this->errors as $error) {
                    $this->error("   - {$error}");
                }
            }
            
            return 0;
        } catch (\Exception $e) {
            $this->error("❌ Error importing file: " . $e->getMessage());
            Log::error('Dimension data import failed', [
                'file' => $filePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    public function collection(Collection $rows)
    {
        $this->info("📊 Processing " . $rows->count() . " rows...");
        
        $bar = $this->output->createProgressBar($rows->count());
        $bar->start();

        foreach ($rows as $row) {
            try {
                // Get SKU - try different possible column names
                $sku = $this->getValue($row, ['sku', 'SKU', 'item_sku', 'Item SKU', 'product_sku']);
                
                if (empty($sku)) {
                    $this->skippedCount++;
                    $bar->advance();
                    continue;
                }

                // Get dimension values - try different possible column names
                // Maatwebsite Excel converts headers: spaces to underscores, lowercase, removes special chars
                $wtAct = $this->getNumericValue($row, [
                    'wt_act', 'wt act', 'WT ACT', 'Weight Actual', 'weight_actual',
                    'wtact', 'wt-act', 'wt.act'
                ]);
                $wtDecl = $this->getNumericValue($row, [
                    'wt_d', 'wt_d_', 'wt_d__', 'wt (d)', 'wt_(d)', 'WT (D)', 'wt d', 
                    'Weight Declared', 'weight_declared', 'wt_decl', 'wtd', 'wt-d', 'wt.d'
                ]);
                $length = $this->getNumericValue($row, [
                    'l_d', 'l_d_', 'l_d__', 'l(d)', 'l_(d)', 'L(D)', 'l (d)', 'L( D)', 
                    'Length (D)', 'length_d', 'ld', 'l-d', 'l.d'
                ]);
                $width = $this->getNumericValue($row, [
                    'w_d', 'w_d_', 'w_d__', 'w(d)', 'w_(d)', 'W(D)', 'w d', 'W (D)',
                    'Width (D)', 'width_d', 'wd', 'w-d', 'w.d'
                ]);
                $height = $this->getNumericValue($row, [
                    'h_d', 'h_d_', 'h_d__', 'h(d)', 'h_(d)', 'H(D)', 'h d', 'H (D)',
                    'Height (D)', 'height_d', 'hd', 'h-d', 'h.d'
                ]);

                // Skip if no data to update
                if ($wtAct === null && $wtDecl === null && $length === null && $width === null && $height === null) {
                    $this->skippedCount++;
                    $bar->advance();
                    continue;
                }

                // Prepare update data
                $updateData = [];
                if ($wtAct !== null) $updateData['wt_act'] = $wtAct;
                if ($wtDecl !== null) $updateData['wt_decl'] = $wtDecl;
                if ($length !== null) $updateData['l'] = $length;
                if ($width !== null) $updateData['w'] = $width;
                if ($height !== null) $updateData['h'] = $height;

                // Update or create dimension_data record
                $dimensionData = DimensionData::where('sku', $sku)->first();
                
                if ($dimensionData) {
                    $dimensionData->update($updateData);
                    $this->updatedCount++;
                } else {
                    DimensionData::create(array_merge(['sku' => $sku], $updateData));
                    $this->createdCount++;
                }

            } catch (\Exception $e) {
                $this->skippedCount++;
                $errorMsg = "SKU: " . ($sku ?? 'unknown') . " - " . $e->getMessage();
                $this->errors[] = $errorMsg;
                Log::warning('Dimension data import row error', [
                    'row' => $row->toArray(),
                    'error' => $e->getMessage()
                ]);
            }
            
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /**
     * Get value from row trying multiple possible column names
     */
    private function getValue($row, array $possibleKeys)
    {
        // Normalize row keys for comparison (lowercase, remove special chars)
        $normalizedRowKeys = [];
        foreach ($row->keys() as $rowKey) {
            $normalized = strtolower(preg_replace('/[^a-z0-9_]/', '_', $rowKey));
            // Remove multiple consecutive underscores
            $normalized = preg_replace('/_+/', '_', $normalized);
            // Remove leading/trailing underscores
            $normalized = trim($normalized, '_');
            $normalizedRowKeys[$normalized] = $rowKey;
        }
        
        foreach ($possibleKeys as $key) {
            // Try exact match first
            if (isset($row[$key])) {
                $value = $row[$key];
                return $value !== null && $value !== '' ? trim((string)$value) : null;
            }
            
            // Normalize the search key
            $normalizedKey = strtolower(preg_replace('/[^a-z0-9_]/', '_', $key));
            $normalizedKey = preg_replace('/_+/', '_', $normalizedKey);
            $normalizedKey = trim($normalizedKey, '_');
            
            // Try normalized match
            if (isset($normalizedRowKeys[$normalizedKey])) {
                $actualKey = $normalizedRowKeys[$normalizedKey];
                $value = $row[$actualKey];
                return $value !== null && $value !== '' ? trim((string)$value) : null;
            }
            
            // Try case-insensitive match
            foreach ($row->keys() as $rowKey) {
                if (strtolower($rowKey) === strtolower($key)) {
                    $value = $row[$rowKey];
                    return $value !== null && $value !== '' ? trim((string)$value) : null;
                }
            }
        }
        
        return null;
    }

    /**
     * Get numeric value from row trying multiple possible column names
     */
    private function getNumericValue($row, array $possibleKeys)
    {
        $value = $this->getValue($row, $possibleKeys);
        
        if ($value === null || $value === '') {
            return null;
        }
        
        // Remove any non-numeric characters except decimal point and minus sign
        $cleaned = preg_replace('/[^0-9.-]/', '', (string)$value);
        
        if ($cleaned === '' || $cleaned === '-') {
            return null;
        }
        
        $numeric = (float)$cleaned;
        
        // Return null if not a valid number
        return is_numeric($numeric) ? $numeric : null;
    }
}
