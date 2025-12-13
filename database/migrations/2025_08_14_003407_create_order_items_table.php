<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // Relationship to orders table
            $table->unsignedBigInteger('order_id')->index(); 
            $table->string('order_number', 100)->nullable(); 
            $table->string('order_item_id', 100)->nullable(); 


            $table->string('sku', 100)->nullable();
            $table->string('asin', 20)->nullable();
            $table->string('upc', 50)->nullable();
            $table->text('product_name')->nullable();

            $table->integer('quantity_ordered')->default(0);
            $table->integer('quantity_shipped')->default(0);
            $table->decimal('unit_price', 10, 2)->default(0.00);
            $table->decimal('item_tax', 10, 2)->default(0.00);
            $table->decimal('promotion_discount', 10, 2)->default(0.00);
            $table->string('currency', 10)->nullable();

         
            $table->boolean('is_gift')->default(false);

    
            $table->decimal('weight', 10, 2)->nullable();
            $table->string('weight_unit', 10)->nullable();
            $table->string('dimensions', 100)->nullable();

     
            $table->string('marketplace', 50)->nullable();
            $table->json('raw_data')->nullable();

            $table->timestamps();
            $table->foreign('order_id')->references('id')->on('orders')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
