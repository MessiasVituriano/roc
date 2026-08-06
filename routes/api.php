<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DisplayController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\PdfController;
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
Route::post('/change-table', [ParticipantController::class, 'changeTable']);
// corrigir o próprio cadastro, só antes de o evento abrir. O GET devolve o
// contato — o único caminho por onde ele sai, e só para o dono dele
Route::get('/me', [ParticipantController::class, 'me']);
Route::post('/update-profile', [ParticipantController::class, 'updateProfile']);

Route::get('/display', DisplayController::class);

// o material que a pessoa leva do evento: as decisões dela com as
// justificativas, liberado junto com o gabarito
Route::get('/my-answers.pdf', [PdfController::class, 'myAnswers']);

Route::prefix('admin')->middleware('master')->group(function () {
    Route::get('/overview', [AdminController::class, 'overview']);
    Route::get('/questions', [AdminController::class, 'questions']);
    // o acervo e a seleção: o evento tem mais perguntas cadastradas do que joga
    Route::get('/question-catalog', [AdminController::class, 'questionCatalog']);
    Route::post('/question-catalog', [AdminController::class, 'selectQuestions']);
    // recarrega o conteúdo do seeder sem apagar quem já está na sala
    Route::post('/reload-questions', [AdminController::class, 'reloadQuestions']);
    Route::get('/tables/{table}', [AdminController::class, 'table']);

    // fluxo da rodada
    Route::post('/open', [AdminController::class, 'open']);
    Route::post('/start', [AdminController::class, 'start']);
    Route::post('/close', [AdminController::class, 'close']);
    Route::post('/reveal', [AdminController::class, 'reveal']);
    Route::post('/unreveal', [AdminController::class, 'unreveal']);
    Route::post('/next', [AdminController::class, 'next']);
    Route::post('/previous', [AdminController::class, 'previous']);
    Route::post('/add-time', [AdminController::class, 'addTime']);
    Route::post('/reset-round', [AdminController::class, 'resetRound']);
    Route::post('/reset-event', [AdminController::class, 'resetEvent']);

    // virada de fase e desempate
    Route::post('/missions/assign', [AdminController::class, 'assignMissions']);
    Route::post('/responses/reveal', [AdminController::class, 'revealResponses']);
    Route::post('/responses/hide', [AdminController::class, 'hideResponses']);
    // o fecho da Fase 1: cada pessoa recebe o próprio total, sem gabarito
    Route::post('/phase-one/reveal', [AdminController::class, 'revealPhaseOne']);
    Route::post('/phase-one/hide', [AdminController::class, 'hidePhaseOne']);
    Route::post('/answers/reveal', [AdminController::class, 'revealAnswers']);
    Route::post('/answers/hide', [AdminController::class, 'hideAnswers']);
    Route::post('/next-phase', [AdminController::class, 'nextPhase']);
    // a rodada final é pontuada à mão, mesa a mesa
    Route::post('/final-score', [AdminController::class, 'finalScore']);
    Route::post('/bonus-round', [AdminController::class, 'bonusRound']);
    Route::post('/end', [AdminController::class, 'end']);
    // a lista de contatos: o único lugar em que o contato de todo mundo sai junto
    Route::get('/contacts.pdf', [PdfController::class, 'contacts']);

    // pessoas: tira do ranking individual sem tirar da dinâmica
    Route::post('/participants/{participant}/block', [AdminController::class, 'blockParticipant']);

    // mesas e layout
    Route::post('/tables/{table}/representative', [AdminController::class, 'setRepresentative']);
    Route::post('/tables', [AdminController::class, 'storeTable']);
    Route::patch('/tables/{table}', [AdminController::class, 'updateTable']);
    Route::delete('/tables/{table}', [AdminController::class, 'destroyTable']);
    Route::post('/layout', [AdminController::class, 'saveLayout']);
});
