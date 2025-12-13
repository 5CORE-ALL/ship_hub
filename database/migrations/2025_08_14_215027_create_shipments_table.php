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
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')
                ->constrained('orders')
                ->onDelete('cascade');

            $table->string('carrier')->index(); // fedex, ups, usps, etc.
            $table->string('service_type')->nullable(); // e.g. FEDEX_GROUND
            $table->decimal('package_weight', 8, 2)->nullable(); // in LB or KG
            $table->json('package_dimensions')->nullable(); // {length, width, height, units}

            $table->string('tracking_number')->nullable()->index();
            $table->string('label_url')->nullable();
            $table->enum('shipment_status', ['created', 'in_transit', 'delivered', 'cancelled'])->default('created');

            $table->longText('label_data')->nullable(); // base64 PDF or file path
            $table->date('ship_date')->nullable();

            $table->decimal('cost', 10, 2)->nullable();
            $table->string('currency', 3)->default('USD');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
