<?php

namespace App\Imports;

use App\Models\BarangayOfficialProfile;
use App\Models\Province;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class BarangayOfficialsImport implements ToCollection, WithChunkReading
{
    public static int $insertedCount = 0;
    private static bool $isFirstRow = true;
    private array $provinceCache = [];

    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            if (self::$isFirstRow) {
                self::$isFirstRow = false;
                continue;
            }

            $lastName  = isset($row[7]) ? trim((string)$row[7]) : '';
            $firstName = isset($row[8]) ? trim((string)$row[8]) : '';
            $provinceName = isset($row[2]) ? strtoupper(trim((string)$row[2])) : '';

            if (empty($lastName) || empty($firstName) || empty($provinceName)) {
                continue;
            }

            // Cache province lookups to reduce queries
            if (!isset($this->provinceCache[$provinceName])) {
                $province = Province::firstOrCreate(['name' => $provinceName]);
                $this->provinceCache[$provinceName] = $province->id;
            }

            BarangayOfficialProfile::create([
                'province_id'       => $this->provinceCache[$provinceName],
                'city_municipality' => isset($row[3]) ? trim((string)$row[3]) : '',
                'barangay'          => isset($row[4]) ? trim((string)$row[4]) : '',
                'position'          => isset($row[5]) ? trim((string)$row[5]) : '',
                'last_name'         => $lastName,
                'first_name'        => $firstName,
                'middle_name'       => isset($row[9]) ? trim((string)$row[9]) : '',
                'suffix'            => isset($row[10]) ? trim((string)$row[10]) : '',
            ]);

            self::$insertedCount++;
        }
    }

    public function chunkSize(): int
    {
        return 500;
    }
}