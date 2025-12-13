<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateStoresTable extends Migration
{
    public function up()
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->foreignId('sales_channel_id')->constrained('sales_channels')->onDelete('cascade');
            $table->foreignId('marketplace_id')->constrained('marketplaces')->onDelete('cascade');
            $table->string('status')->default('pending');
            $table->text('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('stores');
    }
}
