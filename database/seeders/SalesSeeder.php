<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class SalesSeeder extends Seeder
{
    public function run()
    {
        DB::table('sales')->insert([
            [
                'store_id' => 1,
                'order_id' => 'ORD123',
                'amount' => 100.50,
                'status' => 'shipped',
                'created_at' => Carbon::now()->subDays(1),
                'updated_at' => Carbon::now()->subDays(1),
            ],
            [
                'store_id' => 2,
                'order_id' => 'ORD124',
                'amount' => 250.00,
                'status' => 'pending',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ],
            [
                'store_id' => 1,
                'order_id' => 'ORD125',
                'amount' => 75.25,
                'status' => 'processing',
                'created_at' => Carbon::now()->subHours(2),
                'updated_at' => Carbon::now()->subHours(2),
            ],
        ]);
    }
}