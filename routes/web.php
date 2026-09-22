<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// require __DIR__.'/auth.php'; // <--- Comment out or remove this line