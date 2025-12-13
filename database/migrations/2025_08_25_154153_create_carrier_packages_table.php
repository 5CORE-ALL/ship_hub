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
        Schema::create('carrier_packages', function (Blueprint $table) {
            $table->id();
            $table->string('carrier_name');          // e.g. "UPS"
            $table->string('package_code')->unique(); // e.g. "ups_express_box_l"
            $table->string('display_name');          // e.g. "UPS Express® Box - Large"
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carrier_packages');
    }
};
