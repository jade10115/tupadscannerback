<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BarangayOfficialProfile;
use App\Models\Province;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class BarangayOfficialController extends Controller
{
    public function provinceSummary()
    {
        $provinces = Province::withCount('officials')->get();

        $result = $provinces->map(function ($p) {
            return [
                'id' => $p->id,
                'province' => $p->name,
                'total_officials' => $p->officials_count,
            ];
        });

        return response()->json($result);
    }

    public function index(Request $request)
    {
        $query = BarangayOfficialProfile::with('province');

        if ($request->has('province_id') && !empty($request->province_id)) {
            $query->where('province_id', $request->province_id);
        }

        return response()->json($query->latest()->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'province_id'       => 'required|exists:provinces,id',
            'city_municipality' => 'required',
            'barangay'          => 'required',
            'position'          => 'required',
            'first_name'        => 'required',
            'last_name'         => 'required',
            'middle_name'       => 'nullable',
            'suffix'            => 'nullable',
        ]);

        return response()->json(BarangayOfficialProfile::create($validated), 201);
    }

    public function update(Request $request, $id)
    {
        $official = BarangayOfficialProfile::findOrFail($id);
        $official->update($request->all());
        return response()->json($official);
    }

    public function destroy($id)
    {
        BarangayOfficialProfile::destroy($id);
        return response()->json(['message' => 'Deleted successfully']);
    }

    public function import(Request $request)
    {
        $request->validate([
            'excel_file' => 'required|file|mimes:xlsx,xls,csv|max:51200'
        ]);

        try {
            ini_set('memory_limit', '1024M');
            set_time_limit(600);

            \App\Imports\BarangayOfficialsImport::$insertedCount = 0;
            
            Excel::import(new \App\Imports\BarangayOfficialsImport, $request->file('excel_file'));

            $inserted = \App\Imports\BarangayOfficialsImport::$insertedCount;

            return response()->json([
                'message' => "Successfully imported {$inserted} barangay officials."
            ], 200);

        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Import failed: ' . $e->getMessage()
            ], 422);
        }
    }
}