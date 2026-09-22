<?php

namespace Database\Seeders;

use App\Models\Province;
use Illuminate\Database\Seeder;

class ProvinceSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = [
            'BILIRAN',
            'EASTERN SAMAR',
            'LEYTE',
            'NORTHERN SAMAR',
            'SAMAR (WESTERN SAMAR)',
            'SOUTHERN LEYTE',
            'CITY OF TACLOBAN',
        ];

        foreach ($provinces as $name) {
            Province::firstOrCreate(['name' => $name]);
        }
    }
}