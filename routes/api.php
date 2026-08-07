<?php

use App\Http\Controllers\API\AnalyticsController;
use App\Http\Controllers\API\ArticleController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\ClientController;
use App\Http\Controllers\API\CollaboratorController;
use App\Http\Controllers\API\CompanyController;
use App\Http\Controllers\API\HealthController;
use App\Http\Controllers\API\InvoiceController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OnboardingController;
use App\Http\Controllers\API\SocialAuthController;
use App\Http\Controllers\API\TransactionController;
use App\Http\Controllers\API\UserProfileController;
use App\Http\Controllers\API\UserSettingController;
use App\Http\Controllers\API\VariationController;
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

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
Route::post('/register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:10,1');
Route::post('/register/resend-code', [AuthController::class, 'resendRegistrationCode'])->middleware('throttle:3,1');
Route::post('/login', [AuthController::class, 'login']);

// Health check route - Pour vérifier que le serveur est actif
Route::get('/health', [HealthController::class, 'check']);

// Debug routes (test-db, test-cors) - Uniquement disponibles hors production
// pour éviter d'exposer des informations internes (driver DB, config CORS, etc.)
if (!app()->environment('production')) {
    Route::get('/test-db', [HealthController::class, 'testDb']);
    Route::get('/test-cors', [HealthController::class, 'testCors']);
    Route::post('/test-cors', [HealthController::class, 'testCorsPost']);
}

// Social authentication routes
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirectToProvider']);
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'handleProviderCallback']);
// Utilisée quand la redirect URI du provider pointe vers le frontend, qui nous relaie le code
Route::post('/auth/{provider}/exchange', [SocialAuthController::class, 'exchangeCode']);

// Protected routes (authentication required)
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    
    // Articles routes
    Route::apiResource('articles', ArticleController::class);
    Route::delete('/articles/all', [ArticleController::class, 'deleteAll']);
    
    // Stock replenishment routes
    Route::post('/articles/{id}/add-stock', [ArticleController::class, 'addStock']);
    Route::get('/articles/{id}/stock-history', [ArticleController::class, 'stockHistory']);
    
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
    Route::delete('/transactions/all', [TransactionController::class, 'deleteAll']);
    
    // Collaborators routes
    Route::get('/collaborators', [CollaboratorController::class, 'index']);
    Route::get('/collaborators/{id}', [CollaboratorController::class, 'show']);
    Route::post('/collaborators', [CollaboratorController::class, 'store']);
    Route::put('/collaborators/{id}', [CollaboratorController::class, 'update']);
    Route::delete('/collaborators/{id}', [CollaboratorController::class, 'destroy']);
    
    // User profile routes
    Route::put('/user/profile', [UserProfileController::class, 'updateProfile']);
    Route::put('/user/password', [UserProfileController::class, 'updatePassword']);
    
    // Onboarding routes (legacy)
    Route::get('/onboarding/check', [OnboardingController::class, 'check']);
    Route::post('/onboarding/complete', [OnboardingController::class, 'complete']);
    
    // =========================================================================
    // COMPANY / ENTREPRISE
    // =========================================================================
    
    // Company onboarding routes
    Route::get('/company/onboarding-status', [CompanyController::class, 'onboardingStatus']);
    Route::post('/company/complete-onboarding', [CompanyController::class, 'completeOnboarding']);
    
    // Company management routes
    Route::get('/company', [CompanyController::class, 'show']);
    Route::put('/company', [CompanyController::class, 'update']);
    Route::post('/company/logo', [CompanyController::class, 'uploadLogo']);
    Route::delete('/company/logo', [CompanyController::class, 'deleteLogo']);
    
    // User settings routes
    Route::get('/user/settings', [UserSettingController::class, 'index']);
    Route::put('/user/settings', [UserSettingController::class, 'update']);
    
    // Notifications routes
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::put('/notifications/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
    
    // Analytics routes
    Route::get('/analytics/overview', [AnalyticsController::class, 'overview']);
    Route::get('/analytics/trends', [AnalyticsController::class, 'trends']);
    Route::get('/analytics/category-analysis', [AnalyticsController::class, 'categoryAnalysis']);
    Route::get('/analytics/comparisons', [AnalyticsController::class, 'comparisons']);
    Route::get('/analytics/kpis', [AnalyticsController::class, 'kpis']);
    Route::get('/analytics/transactions', [AnalyticsController::class, 'transactions']);
    Route::get('/analytics/predictions', [AnalyticsController::class, 'predictions']);
    
    // =========================================================================
    // CLIENTS & FACTURATION
    // =========================================================================
    
    // Clients routes (CRUD)
    Route::get('/clients/dropdown', [ClientController::class, 'dropdown']);
    Route::apiResource('clients', ClientController::class);
    
    // Invoices routes (protégées par le middleware company.ready)
    Route::middleware('company.ready')->group(function () {
        // Dashboard facturation
        Route::get('/invoices/dashboard', [InvoiceController::class, 'dashboard']);
        
        // Thèmes disponibles
        Route::get('/invoices/themes', [InvoiceController::class, 'themes']);
        
        // CRUD Factures
        Route::get('/invoices', [InvoiceController::class, 'index']);
        Route::post('/invoices', [InvoiceController::class, 'store']);
        Route::get('/invoices/{id}', [InvoiceController::class, 'show']);
        Route::delete('/invoices/{id}', [InvoiceController::class, 'destroy']);
        
        // Actions spéciales
        Route::patch('/invoices/{id}/status', [InvoiceController::class, 'updateStatus']);
        Route::post('/invoices/{id}/duplicate', [InvoiceController::class, 'duplicate']);

        // PDF : aperçu (affichage inline) et téléchargement (forcé)
        Route::get('/invoices/{id}/preview', [InvoiceController::class, 'preview']);
        Route::get('/invoices/{id}/download', [InvoiceController::class, 'download']);
    });
});
