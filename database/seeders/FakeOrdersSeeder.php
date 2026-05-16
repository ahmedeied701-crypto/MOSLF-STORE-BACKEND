<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderItem;

class FakeOrdersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $user = User::first(); 

        Order::factory(5)->create([
            'user_id' => $user->id,
        ])->each(function ($order) {
            OrderItem::factory(rand(1, 3))->create([
                'order_id' => $order->id,
            ]);
        });
    }
}
