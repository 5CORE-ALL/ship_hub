<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bulk_shipping_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');  
            $table->json('order_ids');             
            $table->json('providers');           
            $table->string('merged_pdf_url');
            $table->integer('order_count');
            $table->timestamps();

            $table->foreign('user_id')
                  ->references('id')
                  ->on('users')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_shipping_histories');
    }
};
