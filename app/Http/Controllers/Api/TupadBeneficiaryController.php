<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TupadBeneficiaryProfile;
use Illuminate\Http\Request;

class TupadBeneficiaryController extends Controller
{
    public function index(Request $request)
    {
        $query = TupadBeneficiaryProfile::query()->latest();

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'LIKE', "%{$search}%")
                  ->orWhere('last_name', 'LIKE', "%{$search}%")
                  ->orWhere('middle_name', 'LIKE', "%{$search}%");
            });
        }

        return response()->json($query->paginate(15));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name'   => 'required|string|max:255',
            'suffix'      => 'nullable|string|max:50',
            'birthdate'   => 'nullable|date',
            'sex'         => 'required|string|in:M,F',
            'age'         => 'required|integer|min:0',
            'start_date'  => 'required|date',
        ]);

        $item = TupadBeneficiaryProfile::create($validated);

        return response()->json(['success' => true, 'data' => $item], 201);
    }

    public function update(Request $request, $id)
    {
        $item = TupadBeneficiaryProfile::findOrFail($id);

        $validated = $request->validate([
            'first_name'  => 'required|string|max:255',
            'middle_name' => 'nullable|string|max:255',
            'last_name'   => 'required|string|max:255',
            'suffix'      => 'nullable|string|max:50',
            'birthdate'   => 'nullable|date',
            'sex'         => 'required|string|in:M,F',
            'age'         => 'required|integer|min:0',
            'start_date'  => 'required|date',
        ]);

        $item->update($validated);

        return response()->json(['success' => true, 'data' => $item]);
    }

    public function destroy($id)
    {
        $item = TupadBeneficiaryProfile::findOrFail($id);
        $item->delete();

        return response()->json(['success' => true]);
    }
}