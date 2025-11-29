<?php

use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ArticleController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\CollaboratorController;
use App\Http\Controllers\API\OnboardingController;
use App\Http\Controllers\API\SocialAuthController;
use App\Http\Controllers\API\TransactionController;
use App\Http\Controllers\API\UserProfileController;
use App\Http\Controllers\API\UserSettingController;
use App\Http\Controllers\API\VariationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Public routes (no authentication required)
// Note: La route /sanctum/csrf-cookie est gérée automatiquement par Sanctum

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Database connection test route
Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'success' => true,
            'message' => '✅ Connexion à la base de données réussie!',
            'database' => DB::connection()->getDatabaseName(),
            'driver' => DB::connection()->getDriverName(),
            'status' => 'connected'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => '❌ Erreur de connexion à la base de données',
            'error' => $e->getMessage(),
            'status' => 'disconnected'
        ], 500);
    }
});

// Social authentication routes
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);

// Protected routes (authentication required)
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Articles routes
    Route::apiResource('articles', ArticleController::class);
    
    // Variations routes
    Route::get('/variations', [VariationController::class, 'index']);
    Route::get('/variations/{id}', [VariationController::class, 'show']);
    Route::post('/variations', [VariationController::class, 'store']);
    Route::put('/variations/{id}', [VariationController::class, 'update']);
    Route::delete('/variations/{id}', [VariationController::class, 'destroy']);
    
    // Transactions routes
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/{id}', [TransactionController::class, 'show']);
    Route::post('/transactions', [TransactionController::class, 'store']);
    Route::put('/transactions/{id}', [TransactionController::class, 'update']);
    Route::delete('/transactions/{id}', [TransactionController::class, 'destroy']);
    
    // Collaborators routes
    Route::get('/collaborators', [CollaboratorController::class, 'index']);
    Route::get('/collaborators/{id}', [CollaboratorController::class, 'show']);
    Route::post('/collaborators', [CollaboratorController::class, 'store']);
    Route::put('/collaborators/{id}', [CollaboratorController::class, 'update']);
    Route::delete('/collaborators/{id}', [CollaboratorController::class, 'destroy']);
    
    // User profile routes
    Route::put('/user/profile', [UserProfileController::class, 'updateProfile']);
    Route::put('/user/password', [UserProfileController::class, 'updatePassword']);
    
    // Onboarding routes
    Route::get('/onboarding/check', [OnboardingController::class, 'check']);
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    
    // User settings routes
    Route::get('/user/settings', [UserSettingController::class, 'index']);
    Route::put('/user/settings', [UserSettingController::class, 'update']);
    
    // Analytics routes
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends']);
    Route::get('/analytics/category-analysis', [AnalyticsController::class, 'categoryAnalysis']);
    Route::get('/analytics/comparisons', [AnalyticsController::class, 'comparisons']);
    Route::get('/analytics/kpis', [AnalyticsController::class, 'kpis']);
    Route::get('/analytics/transactions', [AnalyticsController::class, 'transactions']);
    Route::get('/analytics/predictions', [AnalyticsController::class, 'predictions']);
});
