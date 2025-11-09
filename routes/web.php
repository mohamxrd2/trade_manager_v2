<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// La route /sanctum/csrf-cookie est gérée automatiquement par Sanctum
