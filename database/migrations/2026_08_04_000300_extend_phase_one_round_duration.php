<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As rodadas da Fase 1 passam de 20 para 30 segundos.
 *
 * Vinte segundos bastam para escolher entre quatro alternativas — não bastam
 * para *ler* o cenário do hotel antes de escolher. Com a troca de voto agora
 * permitida dentro da rodada, o tempo extra também vira espaço para a pessoa
 * reler o enunciado e mudar de ideia.
 *
 * O seeder já cria com 30; esta migração é para os eventos que existem. Ela
 * mexe só em quem ainda está nos 20 originais: uma duração ajustada à mão pelo
 * facilitador é uma decisão dele, não um valor a corrigir.
 */
return new class extends Migration
{
    private const OLD = 20;

    private const NEW = 30;

    public function up(): void
    {
        DB::table('questions')
            ->where('phase', 1)
            ->where('duration', self::OLD)
            ->update(['duration' => self::NEW]);

        // o relógio do evento fora de rodada, que é o que o painel mostra antes
        // do primeiro "abrir votação"
        DB::table('events')
            ->where('round_duration', self::OLD)
            ->update(['round_duration' => self::NEW]);
    }

    public function down(): void
    {
        DB::table('questions')
            ->where('phase', 1)
            ->where('duration', self::NEW)
            ->update(['duration' => self::OLD]);

        DB::table('events')
            ->where('round_duration', self::NEW)
            ->update(['round_duration' => self::OLD]);
    }
};
