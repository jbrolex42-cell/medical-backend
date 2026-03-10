<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\EmergencyWebController;
use App\Http\Controllers\Web\AmbulanceWebController;
use App\Http\Controllers\Web\HospitalWebController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\AuthWebController;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

// Root route for API or status check
Route::get('/', function () {
    return response()->json([
        'app' => 'Emergency Medical System API',
        'status' => 'running'
    ]);
});

// Report emergency routes
Route::get('/report-emergency', [ReportController::class, 'index']);
Route::post('/report-emergency', [ReportController::class, 'store']);

// Track emergency route
Route::get('/track/{trackingId}', [ReportController::class, 'track']);

/*
|--------------------------------------------------------------------------
| Authentication Routes
|--------------------------------------------------------------------------
*/
Route::get('/login', function () {
    return response()->json([
        'message' => 'Login page',
        'error' => session('error') ?? null
    ]);
});

Route::post('/login', [AuthWebController::class, 'login']);
Route::get('/logout', [AuthWebController::class, 'logout']);

/*
|--------------------------------------------------------------------------
| Protected Routes (Require Auth Middleware)
|--------------------------------------------------------------------------
*/
Route::middleware(['web.auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);

    /*
    | Emergency Management
    */
    Route::get('/emergencies', [EmergencyWebController::class, 'index']);
    Route::get('/emergencies/{id}', [EmergencyWebController::class, 'show']);
    Route::get('/emergencies/{id}/dispatch', [EmergencyWebController::class, 'dispatchForm']);
    Route::post('/emergencies/{id}/dispatch', [EmergencyWebController::class, 'dispatch']);

    /*
    | Ambulance Management
    */
    Route::get('/ambulances', [AmbulanceWebController::class, 'index']);
    Route::get('/ambulances/create', [AmbulanceWebController::class, 'create']);
    Route::post('/ambulances', [AmbulanceWebController::class, 'store']);
    Route::get('/ambulances/{id}/edit', [AmbulanceWebController::class, 'edit']);
    Route::post('/ambulances/{id}', [AmbulanceWebController::class, 'update']);
    Route::post('/ambulances/{id}/delete', [AmbulanceWebController::class, 'destroy']);
    Route::get('/ambulances/live-tracking', [AmbulanceWebController::class, 'liveTracking']);

    /*
    | Hospital Management
    */
    Route::get('/hospitals', [HospitalWebController::class, 'index']);
    Route::get('/hospitals/create', [HospitalWebController::class, 'create']);
    Route::post('/hospitals', [HospitalWebController::class, 'store']);
    Route::get('/hospitals/{id}/edit', [HospitalWebController::class, 'edit']);
    Route::post('/hospitals/{id}', [HospitalWebController::class, 'update']);
    Route::post('/hospitals/{id}/delete', [HospitalWebController::class, 'destroy']);

    /*
    | Maps
    */
    Route::get('/map', function () {
        return response()->json([
            'title' => 'Live Map',
            'apiKey' => env('GOOGLE_MAPS_API_KEY')
        ]);
    });

});