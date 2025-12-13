<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_services', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_name');
            $table->string('service_code')->unique();
            $table->string('display_name');
            $table->string('category')->nullable(); 
            $table->boolean('one_rate')->default(false); 
            $table->boolean('active')->default(true); 
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_services');
    }
};
