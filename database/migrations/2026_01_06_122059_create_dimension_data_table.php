<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('dimension_data', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 255);
            $table->string('parent', 255)->nullable();
            $table->unsignedBigInteger('product_master_id')->nullable();
            
            // Weight fields
            $table->decimal('wt_act', 10, 2)->nullable()->comment('Weight Actual');
            $table->decimal('wt_decl', 10, 2)->nullable()->comment('Weight Declared');
            
            // Product dimensions
            $table->decimal('l', 10, 2)->nullable()->comment('Length');
            $table->decimal('w', 10, 2)->nullable()->comment('Width');
            $table->decimal('h', 10, 2)->nullable()->comment('Height');
            $table->decimal('cbm', 10, 6)->nullable()->comment('Cubic Meter');
            
            // Carton dimensions
            $table->decimal('ctn_l', 10, 2)->nullable()->comment('Carton Length');
            $table->decimal('ctn_w', 10, 2)->nullable()->comment('Carton Width');
            $table->decimal('ctn_h', 10, 2)->nullable()->comment('Carton Height');
            $table->decimal('ctn_cbm', 10, 6)->nullable()->comment('Carton CBM');
            $table->decimal('ctn_qty', 10, 2)->nullable()->comment('Carton Quantity');
            $table->decimal('ctn_cbm_each', 10, 6)->nullable()->comment('Carton CBM Each');
            $table->decimal('ctn_gwt', 10, 2)->nullable()->comment('Carton Gross Weight');
            
            // Additional fields
            $table->decimal('cbm_e', 10, 2)->nullable()->comment('CBM Each');
            
            $table->timestamps();
            
            // Indexes for faster queries
            $table->index('product_master_id');
        });
        
        // Add SKU index with prefix to handle long SKU values
        DB::statement('CREATE INDEX dimension_data_sku_index ON dimension_data (sku(191))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dimension_data');
    }
};
