<?php

use Illuminate\Support\Facades\Route;

// Single Vue SPA: /  (participante), /display (telão), /master (painel).
Route::view('/{any?}', 'app')->where('any', '^(?!api|up).*$');
