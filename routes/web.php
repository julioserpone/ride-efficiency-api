<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Informational Landing
|--------------------------------------------------------------------------
|
| This application is an API-only service. The only browser-facing route
| is a static page describing the service, its owner, and its consumers.
|
*/

Route::view('/', 'welcome')->name('home');
