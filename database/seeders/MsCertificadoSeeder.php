<?php

namespace Database\Seeders;

use App\Models\MsCertificado;
use Illuminate\Database\Seeder;

class MsCertificadoSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        MsCertificado::factory()->count(10)->create();
    }
}
