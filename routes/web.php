<?php

use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WelcomeController::class, 'index']);

// La route /sanctum/csrf-cookie est gérée automatiquement par Sanctum
