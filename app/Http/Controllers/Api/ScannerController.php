<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BarangayOfficialProfile;
use App\Models\TupadBeneficiaryProfile;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ScannerController extends Controller
{
    public function getStats()
    {
        $now = Carbon::now();

        return response()->json([
            'male_yearly' => TupadBeneficiaryProfile::where('sex', 'M')->whereYear('start_date', $now->year)->count(),
            'male_quarterly' => TupadBeneficiaryProfile::where('sex', 'M')->whereYear('start_date', $now->year)->whereRaw('QUARTER(start_date) = ?', [$now->quarter])->count(),
            'female_yearly' => TupadBeneficiaryProfile::where('sex', 'F')->whereYear('start_date', $now->year)->count(),
            'female_quarterly' => TupadBeneficiaryProfile::where('sex', 'F')->whereYear('start_date', $now->year)->whereRaw('QUARTER(start_date) = ?', [$now->quarter])->count(),
            'seniors' => TupadBeneficiaryProfile::where('age', '>=', 60)->count(),
        ]);
    }

    /**
     * Scanner 1: Check List of Beneficiaries against Barangay Officials
     */
    public function scanBarangayOfficials(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '384M');
        gc_enable();

        $request->validate([
            'lob_file' => 'required|file',
            'province' => 'required'
        ]);

        $provinceInput = $request->province;
        $searchProvince = is_array($provinceInput)
            ? ($provinceInput['name'] ?? $provinceInput['province'] ?? $provinceInput['id'] ?? '')
            : (string) $provinceInput;
        $searchProvince = trim($searchProvince);

        // OPTIMIZATION 1: Use toBase() for lightweight stdClass objects instead of Eloquent models
        $officials = BarangayOfficialProfile::query()
            ->leftJoin('provinces', 'provinces.id', '=', 'barangay_official_profiles.province_id')
            ->where(function ($q) use ($searchProvince) {
                $q->where('provinces.name', 'LIKE', '%' . $searchProvince . '%')
                  ->orWhere('barangay_official_profiles.province_id', $searchProvince)
                  ->orWhere('barangay_official_profiles.province', 'LIKE', '%' . $searchProvince . '%');
            })
            ->select('barangay_official_profiles.*')
            ->toBase()
            ->get();

        $officialsLookup = [];
        foreach ($officials as $off) {
            $f = strtolower(trim(preg_replace('/\s+/', ' ', $off->first_name ?? '')));
            $l = strtolower(trim(preg_replace('/\s+/', ' ', $off->last_name ?? '')));

            if (!empty($f) && !empty($l)) {
                $key = $f . '|' . $l;
                $officialsLookup[$key][] = $off;
            }
        }

        // OPTIMIZATION 2: Read raw cell values only with memory-safe sheet loading
        $sheets = $this->readExcelFile($request->file('lob_file'));

        $results = ['officials_hard' => [], 'officials_soft' => []];
        $totalProcessed = 0;

        foreach ($sheets as $data) {
            foreach ($data as $index => $row) {
                $fName  = strtolower($this->sanitizeString($row[1] ?? ''));
                $mName  = strtolower($this->sanitizeString($row[2] ?? ''));
                $lName  = strtolower($this->sanitizeString($row[3] ?? ''));
                $suffix = strtolower($this->sanitizeString($row[4] ?? ''));

                if (empty($fName) || empty($lName) || in_array($fName, ['first name', 'first_name', 'no', 'dole regional office:'])) {
                    continue;
                }

                $totalProcessed++;
                $lookupKey = $fName . '|' . $lName;

                if (isset($officialsLookup[$lookupKey])) {
                    $matches = $officialsLookup[$lookupKey];

                    // OPTIMIZATION 3: Native PHP loop instead of collect()->first()
                    $hardMatch = null;
                    foreach ($matches as $off) {
                        $dbMiddle = strtolower(trim(preg_replace('/\s+/', ' ', $off->middle_name ?? '')));
                        $dbSuffix = strtolower(trim(preg_replace('/\s+/', ' ', $off->suffix ?? '')));
                        $suffixMatch = ($dbSuffix === $suffix);

                        if (!empty($mName) && !empty($dbMiddle)) {
                            if ($suffixMatch && ($dbMiddle === $mName)) {
                                $hardMatch = $off;
                                break;
                            }
                        } elseif ($suffixMatch) {
                            $hardMatch = $off;
                            break;
                        }
                    }

                    if ($hardMatch) {
                        $results['officials_hard'][] = array_merge((array) $hardMatch, ['row_number' => $index + 1]);
                    } else {
                        $results['officials_soft'][] = array_merge((array) $matches[0], ['row_number' => $index + 1]);
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'total_processed' => $totalProcessed,
            'results' => $results
        ]);
    }

    /**
     * Scanner 2: Consolidated TUPAD 1-Year Duplicates & Barangay Official Security Scan
     */
    public function scanTupadDuplicates(Request $request)
    {
        set_time_limit(300);
        ini_set('memory_limit', '384M');
        gc_enable();

        $request->validate([
            'lob_file'   => 'required|file',
            'start_date' => 'required|date',
            'province'   => 'nullable|string'
        ]);

        $batchStartDate = Carbon::parse($request->start_date);
        $oneYearCutoff = $batchStartDate->copy()->subDays(365)->format('Y-m-d');

        // OPTIMIZATION 1: Use toBase() for fast stdClass lookup without Eloquent model weight
        $recentBeneficiaries = TupadBeneficiaryProfile::select('first_name', 'middle_name', 'last_name', 'suffix')
            ->where('start_date', '>=', $oneYearCutoff)
            ->toBase()
            ->get();

        $beneficiaryLookup = [];
        foreach ($recentBeneficiaries as $b) {
            $key = strtolower(trim($b->first_name)) . '|' . strtolower(trim($b->last_name));
            $beneficiaryLookup[$key][] = $b;
        }

        $officialsQuery = BarangayOfficialProfile::select('first_name', 'middle_name', 'last_name', 'suffix', 'position');
        if ($request->filled('province')) {
            $provinceInput = trim($request->province);
            $officialsQuery->leftJoin('provinces', 'provinces.id', '=', 'barangay_official_profiles.province_id')
                ->where(function ($q) use ($provinceInput) {
                    $q->where('provinces.name', 'LIKE', '%' . $provinceInput . '%')
                      ->orWhere('barangay_official_profiles.province_id', $provinceInput)
                      ->orWhere('barangay_official_profiles.province', 'LIKE', '%' . $provinceInput . '%');
                });
        }
        $officials = $officialsQuery->toBase()->get();

        $officialsLookup = [];
        foreach ($officials as $off) {
            $f = strtolower(trim(preg_replace('/\s+/', ' ', $off->first_name ?? '')));
            $l = strtolower(trim(preg_replace('/\s+/', ' ', $off->last_name ?? '')));
            if (!empty($f) && !empty($l)) {
                $officialsLookup[$f . '|' . $l][] = $off;
            }
        }

        // OPTIMIZATION 2: Read raw cell values safely
        $sheets = $this->readExcelFile($request->file('lob_file'));

        $totalProcessed = 0;
        $femaleCount = 0;
        $seniorCount = 0;
        $officialCount = 0;

        $duplicates = [];
        $seniors = [];
        $minors = [];
        $validRows = [];

        foreach ($sheets as $data) {
            foreach ($data as $index => $row) {
                if ($index < 18) continue; // Skip LOB headers

                $fName  = $this->sanitizeString($row[1] ?? '');   // Col B
                $mName  = $this->sanitizeString($row[2] ?? '');   // Col C
                $lName  = $this->sanitizeString($row[3] ?? '');   // Col D
                $suffix = $this->sanitizeString($row[4] ?? '');   // Col E
                $sexRaw = strtoupper($this->sanitizeString($row[16] ?? '')); // Col Q (Sex)
                $age    = (int) ($row[18] ?? 0);                   // Col S (Age)

                if (empty($fName) || empty($lName) || in_array(strtolower($fName), ['first name', 'first_name'])) {
                    continue;
                }

                $totalProcessed++;

                // Standardize Sex
                $sex = str_starts_with($sexRaw, 'F') ? 'F' : 'M';
                if ($sex === 'F') $femaleCount++;

                // Safe Birthdate parsing
                $bDate = $this->safeParseDate($row[5] ?? null);

                if ($age <= 0 && $bDate) {
                    $age = Carbon::parse($bDate)->age;
                }

                $rowPayload = [
                    'row_number'  => $index + 1,
                    'first_name'  => $fName,
                    'middle_name' => $mName,
                    'last_name'   => $lName,
                    'suffix'      => $suffix,
                    'sex'         => $sex,
                    'age'         => $age,
                    'birthdate'   => $bDate,
                    'start_date'  => $batchStartDate->format('Y-m-d')
                ];

                if ($age >= 60) {
                    $seniorCount++;
                    $seniors[] = array_merge($rowPayload, ['reason' => 'Senior Citizen (Age >= 60)']);
                } elseif ($age > 0 && $age < 18) {
                    $minors[] = array_merge($rowPayload, ['reason' => 'Minor (Age < 18)']);
                }

                $fClean = strtolower($fName);
                $mClean = strtolower($mName);
                $lClean = strtolower($lName);
                $sClean = strtolower($suffix);
                $lookupKey = $fClean . '|' . $lClean;

                // CHECK 1: Security Scan for Barangay Officials
                if (isset($officialsLookup[$lookupKey])) {
                    $offMatches = $officialsLookup[$lookupKey];
                    $isOfficial = false;

                    // OPTIMIZATION 3: Native PHP loop instead of collect()->contains()
                    foreach ($offMatches as $off) {
                        $dbMiddle = strtolower(trim(preg_replace('/\s+/', ' ', $off->middle_name ?? '')));
                        $dbSuffix = strtolower(trim(preg_replace('/\s+/', ' ', $off->suffix ?? '')));
                        $suffixMatch = ($dbSuffix === $sClean);

                        if (!empty($mClean) && !empty($dbMiddle)) {
                            if ($suffixMatch && ($dbMiddle === $mClean)) {
                                $isOfficial = true;
                                break;
                            }
                        } elseif ($suffixMatch) {
                            $isOfficial = true;
                            break;
                        }
                    }

                    if ($isOfficial) {
                        $officialCount++;
                        $duplicates[] = array_merge($rowPayload, [
                            'reason' => 'SECURITY INELIGIBILITY: Identified as an Active Barangay Official'
                        ]);
                        continue;
                    }
                }

                // CHECK 2: 1-Year TUPAD Duplicate Availment Scan
                if (isset($beneficiaryLookup[$lookupKey])) {
                    $matches = $beneficiaryLookup[$lookupKey];
                    $isDupe = false;

                    // OPTIMIZATION 3: Native PHP loop instead of collect()->contains()
                    foreach ($matches as $b) {
                        $dbMiddle = strtolower(trim(preg_replace('/\s+/', ' ', $b->middle_name ?? '')));
                        $dbSuffix = strtolower(trim(preg_replace('/\s+/', ' ', $b->suffix ?? '')));
                        $suffixMatch = ($dbSuffix === $sClean);

                        if (!empty($mClean) && !empty($dbMiddle)) {
                            if ($suffixMatch && ($dbMiddle === $mClean)) {
                                $isDupe = true;
                                break;
                            }
                        } elseif ($suffixMatch) {
                            $isDupe = true;
                            break;
                        }
                    }

                    if ($isDupe) {
                        $duplicates[] = array_merge($rowPayload, [
                            'reason' => 'DUPLICATE AVAILMENT: Claimed TUPAD within 1-year duration'
                        ]);
                        continue;
                    }
                }

                $validRows[] = $rowPayload;
            }
        }

        $payload = [
            'total_processed' => $totalProcessed,
            'female_count'    => $femaleCount,
            'senior_count'    => $seniorCount,
            'official_count'  => $officialCount,
            'duplicates'      => $duplicates,
            'seniors'         => $seniors,
            'minors'          => $minors,
            'valid_rows'      => $validRows
        ];

        return response()->json(array_merge([
            'success' => true,
            'results' => $payload
        ], $payload));
    }

    /**
     * Store scanned valid beneficiaries into Database
     */
    public function importTupadBeneficiaries(Request $request)
    {
        $request->validate([
            'beneficiaries' => 'required|array',
            'beneficiaries.*.first_name' => 'required|string',
            'beneficiaries.*.last_name' => 'required|string',
            'beneficiaries.*.start_date' => 'required|date'
        ]);

        $insertData = [];
        $now = now();

        foreach ($request->beneficiaries as $b) {
            $insertData[] = [
                'first_name'  => $b['first_name'],
                'middle_name' => $b['middle_name'] ?? null,
                'last_name'   => $b['last_name'],
                'suffix'      => $b['suffix'] ?? null,
                'birthdate'   => $b['birthdate'] ?? null,
                'sex'         => $b['sex'] ?? 'M',
                'age'         => $b['age'] ?? 0,
                'start_date'  => $b['start_date'],
                'created_at'  => $now,
                'updated_at'  => $now
            ];
        }

        foreach (array_chunk($insertData, 500) as $chunk) {
            TupadBeneficiaryProfile::insert($chunk);
        }

        return response()->json([
            'success' => true,
            'imported_count' => count($insertData)
        ]);
    }

    /**
     * Helper to read Excel files sheet-by-sheet with minimal memory footprint
     */
    private function readExcelFile($file): array
    {
        $filePath = $file->getRealPath();
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true); // Don't load styling, formulas, or formatting

        $info = $reader->listWorksheetInfo($filePath);
        $sheets = [];

        foreach ($info as $sheetInfo) {
            $sheetName = $sheetInfo['worksheetName'];
            $reader->setLoadSheetsOnly($sheetName);

            $spreadsheet = $reader->load($filePath);
            $sheet = $spreadsheet->getActiveSheet();
            $sheets[] = $sheet->toArray(null, true, false, false);

            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet, $sheet);
            gc_collect_cycles(); // Force immediate garbage collection after each sheet
        }

        unset($reader);
        gc_collect_cycles();

        return $sheets;
    }

    /**
     * Safely parse dates from Excel numbers or ambiguous strings
     */
    private function safeParseDate($value): ?string
    {
        if (empty($value) || strtolower(trim((string)$value)) === 'null') {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Date::excelToDateTimeObject($value)->format('Y-m-d');
            }

            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Clean and convert string to clean UTF-8
     */
    private function sanitizeString($value): string
    {
        $str = mb_convert_encoding(trim((string)$value), 'UTF-8', 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', $str));
    }
}