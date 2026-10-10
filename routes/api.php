<?php

use App\Http\Controllers\Api\CodeController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/search', SearchController::class);
Route::get('/codes/{code}', CodeController::class);
