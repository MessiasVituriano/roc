<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O bloqueio do painel master: tira a pessoa do ranking individual sem tirá-la
 * da dinâmica.
 *
 * É para o nome impróprio, o duplicado e o colaborador da casa que está na sala
 * ajudando — gente que continua votando e continua somando para a mesa e para o
 * grupo de missão, mas que não deve subir ao telão disputando o pódio
 * individual. Por isso um carimbo no participante, e não a exclusão do voto:
 * apagar o voto reescreveria o placar da mesa, que é o critério de vitória.
 *
 * Nulo = participando normalmente. O carimbo guarda *quando* foi bloqueado,
 * que é o que o facilitador precisa para se lembrar da decisão dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn('blocked_at');
        });
    }
};
