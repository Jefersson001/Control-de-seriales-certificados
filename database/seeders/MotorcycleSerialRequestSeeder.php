<?php

namespace Database\Seeders;

use App\Models\MotorcycleSerialRequest;
use Illuminate\Database\Seeder;

class MotorcycleSerialRequestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MotorcycleSerialRequest::factory()->count(10)->create();
    }
}
