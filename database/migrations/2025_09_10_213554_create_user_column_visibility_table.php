<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_column_visibility', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('screen_name', 50);   
            $table->string('column_name', 100);   
            $table->boolean('is_visible')->default(true);
            $table->integer('order_index')->nullable(); 
            $table->integer('width')->nullable();     
            $table->timestamps();
            $table->unique(['user_id', 'screen_name', 'column_name'], 'user_screen_column_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_column_visibility');
    }
};
