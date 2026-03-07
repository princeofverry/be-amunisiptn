<?php

namespace Database\Seeders;

use App\Models\Subtest;
use Illuminate\Database\Seeder;

class SubtestSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        //
        $items = [
            ['name' => 'Penalaran Umum', 'category' => 'TPS'],
            ['name' => 'Pengetahuan dan Pemahaman Umum', 'category' => 'TPS'],
            ['name' => 'Pemahaman Bacaan dan Menulis', 'category' => 'TPS'],
            ['name' => 'Pengetahuan Kuantitatif', 'category' => 'TPS'],
            ['name' => 'Literasi dalam Bahasa Indonesia', 'category' => 'Literasi'],
            ['name' => 'Literasi dalam Bahasa Inggris', 'category' => 'Literasi'],
            ['name' => 'Penalaran Matematika', 'category' => 'Literasi'],
        ];

        foreach ($items as $item) {
            Subtest::updateOrCreate(
                ['name' => $item['name']],
                ['category' => $item['category']]
            );
        }
    }
}