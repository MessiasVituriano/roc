<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DisplayController;
use App\Http\Controllers\Api\ParticipantController;
use Illuminate\Support\Facades\Route;

/*
 * Todas as telas consultam estes endpoints a cada segundo. Os formatos dos
 * payloads são o contrato — trocar polling por websocket depois não pode mudá-los.
 */

Route::get('/bootstrap', [ParticipantController::class, 'bootstrap']);
Route::post('/join', [ParticipantController::class, 'join']);
Route::get('/status', [ParticipantController::class, 'status']);
Route::get('/timer', [ParticipantController::class, 'timer']);
Route::post('/vote', [ParticipantController::class, 'vote']);
Route::post('/claim-representative', [ParticipantController::class, 'claimRepresentative']);

Route::get('/display', DisplayController::class);

Route::prefix('admin')->middleware('master')->group(function () {
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/questions', [AdminController::class, 'questions']);
    Route::get('/tables/{table}', [AdminController::class, 'table']);

    // fluxo da rodada
    Route::post('/open', [AdminController::class, 'open']);
    Route::post('/start', [AdminController::class, 'start']);
    Route::post('/close', [AdminController::class, 'close']);
    Route::post('/reveal', [AdminController::class, 'reveal']);
    Route::post('/unreveal', [AdminController::class, 'unreveal']);
    Route::post('/next', [AdminController::class, 'next']);
    Route::post('/add-time', [AdminController::class, 'addTime']);
    Route::post('/reset-round', [AdminController::class, 'resetRound']);
    Route::post('/reset-event', [AdminController::class, 'resetEvent']);

    // virada de fase e desempate
    Route::post('/missions/assign', [AdminController::class, 'assignMissions']);
    Route::post('/missions/reveal', [AdminController::class, 'revealMissions']);
    Route::post('/missions/hide', [AdminController::class, 'hideMissions']);
    Route::post('/answers/reveal', [AdminController::class, 'revealAnswers']);
    Route::post('/answers/hide', [AdminController::class, 'hideAnswers']);
    Route::post('/next-phase', [AdminController::class, 'nextPhase']);
    // a rodada final é pontuada à mão, mesa a mesa
    Route::post('/final-score', [AdminController::class, 'finalScore']);
    Route::post('/bonus-round', [AdminController::class, 'bonusRound']);
    Route::post('/end', [AdminController::class, 'end']);

    // mesas e layout
    Route::post('/tables/{table}/representative', [AdminController::class, 'setRepresentative']);
    Route::post('/tables', [AdminController::class, 'storeTable']);
    Route::patch('/tables/{table}', [AdminController::class, 'updateTable']);
    Route::delete('/tables/{table}', [AdminController::class, 'destroyTable']);
    Route::post('/layout', [AdminController::class, 'saveLayout']);
});
