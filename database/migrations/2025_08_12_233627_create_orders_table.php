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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Store & marketplace info
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->string('marketplace')->nullable(); 
            $table->string('marketplace_order_id')->nullable();
            $table->string('order_number')->unique();
            $table->dateTime('order_date')->nullable();
            $table->integer('order_age')->nullable(); // in days
            $table->text('notes')->nullable();
            $table->boolean('is_gift')->default(false);

            // Item details (for single-item orders; multi-item orders should have an order_items table)
            $table->string('item_sku')->nullable();
            $table->string('item_name')->nullable();
            $table->string('batch')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('order_total', 10, 2)->default(0);

            // Recipient / Shipping details
            $table->string('recipient_name')->nullable();
            $table->string('recipient_company')->nullable();
            $table->string('recipient_email')->nullable();
            $table->string('recipient_phone')->nullable();
            $table->string('ship_address1')->nullable();
            $table->string('ship_address2')->nullable();
            $table->string('ship_city')->nullable();
            $table->string('ship_state')->nullable();
            $table->string('ship_postal_code')->nullable();
            $table->string('ship_country')->nullable();

            // Shipping / Label details
            $table->string('shipping_carrier')->nullable();
            $table->string('shipping_service')->nullable();
            $table->decimal('shipping_cost', 10, 2)->nullable();
            $table->string('tracking_number')->nullable();
            $table->dateTime('ship_date')->nullable();
            $table->string('label_status')->nullable();

            // Status & integration info
            $table->string('order_status')->nullable();
            $table->string('payment_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('external_order_id')->nullable();
            $table->json('raw_data')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
