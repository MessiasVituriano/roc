<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O segundo momento deliberado do facilitador.
     *
     * Como a Fase 2 repete as perguntas da Fase 1, o gabarito fica preso até o
     * fim. Mas "o fim" não precisa ser o encerramento do evento: com esta
     * flag o facilitador abre o gabarito e o comparativo entre as fases
     * enquanto a sala ainda está inteira, e só encerra depois.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('answers_revealed')->default(false)->after('missions_revealed');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('answers_revealed');
        });
    }
};
