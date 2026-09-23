<?php

use App\HotPath\RedirectController;
use Illuminate\Support\Facades\Route;

// Registered outside the "web" group: no session, cookies or CSRF on the hot path.
Route::get('/c/{alias}', RedirectController::class);
