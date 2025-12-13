<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');

            $table->string('rate_id')->nullable(); 
            $table->string('source');   
            $table->string('carrier');  
            $table->string('service');  
            $table->decimal('price', 10, 2)->default(0);
            $table->string('currency', 10)->default('USD');

            $table->boolean('is_cheapest')->default(false);
            $table->boolean('is_gpt_suggestion')->default(false);

            $table->json('raw_data')->nullable(); 

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipping_rates');
    }
};
