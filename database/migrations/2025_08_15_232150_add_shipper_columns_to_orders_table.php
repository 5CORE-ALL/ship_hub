<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShipperColumnsToOrdersTable extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('shipper_name')->nullable();
            $table->string('shipper_phone')->nullable();
            $table->string('shipper_company')->nullable();
            $table->string('shipper_street')->nullable();
            $table->string('shipper_city')->nullable();
            $table->string('shipper_state')->nullable();
            $table->string('shipper_postal')->nullable();
            $table->string('shipper_country')->nullable();
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'shipper_name',
                'shipper_phone',
                'shipper_company',
                'shipper_street',
                'shipper_city',
                'shipper_state',
                'shipper_postal',
                'shipper_country'
            ]);
        });
    }
}
