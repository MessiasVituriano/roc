<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A rodada final da Fase 2 não é uma escolha entre alternativas: a mesa decide
 * livremente e o facilitador lança a pontuação. Isso pede duas mudanças:
 *
 * 1. `questions.manual_scoring` marca a pergunta que não tem régua própria;
 * 2. `table_votes.option_id` passa a aceitar nulo — a linha da rodada final
 *    guarda pontos sem alternativa. Todo cálculo de acerto continua sendo por
 *    `option_id`, então essas linhas simplesmente não contam como acerto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('manual_scoring')->default(false)->after('is_bonus');
        });

        Schema::table('table_votes', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('manual_scoring');
        });

        // linhas sem alternativa não cabem na coluna obrigatória de volta
        DB::table('table_votes')->whereNull('option_id')->delete();

        Schema::table('table_votes', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->nullable(false)->change();
        });
    }
};
