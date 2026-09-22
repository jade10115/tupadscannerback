<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BarangayOfficialController;
use App\Http\Controllers\Api\ScannerController;
use App\Http\Controllers\Api\TupadBeneficiaryController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->name('login');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Scanner & Stats Routes


Route::get('/scanner/stats', [ScannerController::class, 'getStats']);
Route::post('/scanner/scan-officials', [ScannerController::class, 'scanBarangayOfficials']);
Route::post('/scanner/scan-tupad-dupes', [ScannerController::class, 'scanTupadDuplicates']);

    // Barangay Officials Routes
    Route::get('/barangay-officials/provinces', [BarangayOfficialController::class, 'provinceSummary']);
    Route::get('/barangay-officials', [BarangayOfficialController::class, 'index']);
    Route::post('/barangay-officials', [BarangayOfficialController::class, 'store']);
    Route::put('/barangay-officials/{id}', [BarangayOfficialController::class, 'update']);
    Route::delete('/barangay-officials/{id}', [BarangayOfficialController::class, 'destroy']);
    Route::post('/barangay-officials/import', [BarangayOfficialController::class, 'import']);

    // Admin & Beneficiary Resources
    Route::get('/users', [AuthController::class, 'indexUsers']);
    Route::post('/users', [AuthController::class, 'storeUser']);
    Route::apiResource('/tupad-beneficiaries', TupadBeneficiaryController::class);


    Route::get('/stats', [ScannerController::class, 'getStats']);
    Route::post('/scan-officials', [ScannerController::class, 'scanBarangayOfficials']);
    Route::post('/scan-tupad-duplicates', [ScannerController::class, 'scanTupadDuplicates']);
    Route::post('/upload-tupad-beneficiaries', [ScannerController::class, 'importTupadBeneficiaries']);

    Route::apiResource('tupad-beneficiaries', TupadBeneficiaryController::class);
});