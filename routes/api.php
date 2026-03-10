<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\AmbulanceController;
use App\Http\Controllers\HospitalController;
use App\Http\Controllers\AdminController;


// 1. Public Health Check
Route::get('/health', function () {
    return response()->json(['status' => 'ok']);
});

// 2. Public Auth Routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOTP']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// 3. Protected Routes (Requires Authentication)
// Note: Ensure you have 'auth:sanctum' or 'auth:api' configured in your project
Route::middleware('auth:sanctum')->group(function () {

    // User Profile & Token Refresh
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/refresh', [AuthController::class, 'refresh']);

    // Emergencies
    Route::prefix('emergencies')->group(function () {
        Route::get('/', [EmergencyController::class, 'index']);
        Route::post('/', [EmergencyController::class, 'store']);
        Route::get('/nearby', [EmergencyController::class, 'nearby']);
        Route::get('/{id}', [EmergencyController::class, 'show']);
        Route::put('/{id}', [EmergencyController::class, 'update']);
        Route::patch('/{id}/status', [EmergencyController::class, 'updateStatus']);
        Route::post('/{id}/assign', [EmergencyController::class, 'assignResources']);
    });

    // Ambulances
    Route::prefix('ambulances')->group(function () {
        Route::get('/', [AmbulanceController::class, 'index']);
        Route::get('/nearby', [AmbulanceController::class, 'nearby']);
        Route::get('/{id}', [AmbulanceController::class, 'show']);
        Route::patch('/{id}/location', [AmbulanceController::class, 'updateLocation']);
        Route::patch('/{id}/status', [AmbulanceController::class, 'updateStatus']);
    });

    // Hospitals
    Route::prefix('hospitals')->group(function () {
        Route::get('/', [HospitalController::class, 'index']);
        Route::get('/nearby', [HospitalController::class, 'nearby']);
        Route::get('/availability', [HospitalController::class, 'availability']);
        Route::get('/{id}', [HospitalController::class, 'show']);
    });

    // 4. Admin Specific Routes
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'dashboard']);
        Route::get('/emergencies/live', [AdminController::class, 'liveEmergencies']);
        Route::get('/ambulances/status', [AdminController::class, 'fleetStatus']);
        Route::get('/analytics', [AdminController::class, 'analytics']);
        Route::get('/oxygen-levels', [AdminController::class, 'oxygenLevels']);
        
        Route::post('/broadcast-alert', [AdminController::class, 'broadcastAlert']);
        Route::post('/disaster/create', [AdminController::class, 'createDisasterEvent']);

        // Admin Management of Resources
        Route::apiResource('ambulances', AmbulanceController::class)->only(['store', 'update', 'destroy']);
        Route::apiResource('hospitals', HospitalController::class)->only(['store', 'update', 'destroy']);
        Route::patch('/hospitals/{id}/occupancy', [HospitalController::class, 'updateOccupancy']);
    });

    // 5. Dispatcher Specific Routes
    Route::middleware('role:admin,dispatcher')->prefix('dispatcher')->group(function () {
        Route::get('/dashboard', [EmergencyController::class, 'index']);
    });
});