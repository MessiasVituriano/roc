<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O relógio passa a ser um por fase: 60s na Fase 1, 120s na Fase 2.
 *
 * A Fase 1 dobra de 30 para 60 pelo mesmo motivo que já a tinha levado de 20
 * para 30 — ler o cenário do hotel come o começo da rodada, e a troca de voto
 * dentro da rodada só é útil se sobrar tempo para reler. A Fase 2 passa a ter
 * relógio próprio, o dobro do da Fase 1, porque ali a decisão é conversada.
 *
 * A rodada final encolhe: eram 300s de consenso, agora são 120s. É o número
 * pedido para a fase inteira, e é o que a mesa tem para fechar posição —
 * **+30s** no painel estica quando a conversa merecer.
 *
 * O seeder já cria com os valores novos; esta migração é para os eventos que
 * existem. Como a de antes, ela mexe só em quem está no valor padrão anterior:
 * duração ajustada à mão pelo facilitador é decisão dele, não valor a corrigir.
 * O `is_bonus` separa as duas perguntas da Fase 2, que partiam de padrões
 * diferentes (300s a final, 60s o desempate) e chegam ao mesmo — sem ele o
 * `down()` não teria como devolver cada uma ao seu.
 */
return new class extends Migration
{
    private const PHASE_ONE_OLD = 30;

    private const PHASE_ONE_NEW = 60;

    private const FINAL_OLD = 300;

    private const BONUS_OLD = 60;

    private const PHASE_TWO_NEW = 120;

    public function up(): void
    {
        DB::table('questions')
            ->where('phase', 1)
            ->where('duration', self::PHASE_ONE_OLD)
            ->update(['duration' => self::PHASE_ONE_NEW]);

        DB::table('questions')
            ->where('phase', 2)
            ->where('is_bonus', false)
            ->where('duration', self::FINAL_OLD)
            ->update(['duration' => self::PHASE_TWO_NEW]);

        DB::table('questions')
            ->where('phase', 2)
            ->where('is_bonus', true)
            ->where('duration', self::BONUS_OLD)
            ->update(['duration' => self::PHASE_TWO_NEW]);

        // o relógio do evento fora de rodada, que é o que o painel mostra antes
        // do primeiro "abrir votação"
        DB::table('events')
            ->where('round_duration', self::PHASE_ONE_OLD)
            ->update(['round_duration' => self::PHASE_ONE_NEW]);
    }

    public function down(): void
    {
        DB::table('questions')
            ->where('phase', 2)
            ->where('is_bonus', false)
            ->where('duration', self::PHASE_TWO_NEW)
            ->update(['duration' => self::FINAL_OLD]);

        DB::table('questions')
            ->where('phase', 2)
            ->where('is_bonus', true)
            ->where('duration', self::PHASE_TWO_NEW)
            ->update(['duration' => self::BONUS_OLD]);

        DB::table('questions')
            ->where('phase', 1)
            ->where('duration', self::PHASE_ONE_NEW)
            ->update(['duration' => self::PHASE_ONE_OLD]);

        DB::table('events')
            ->where('round_duration', self::PHASE_ONE_NEW)
            ->update(['round_duration' => self::PHASE_ONE_OLD]);
    }
};
