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
        Schema::create('sales_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name'); 
            $table->string('logo')->nullable(); 
            $table->string('platform')->nullable();
            $table->string('store_url')->nullable(); 
            $table->string('app_id')->nullable(); 
            $table->string('app_secret')->nullable(); 
            $table->string('redirect_uri')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales_channels');
    }
};
