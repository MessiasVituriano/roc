<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Todas as rodadas passam a 90 segundos — o número que o documento da dinâmica
 * especifica, e o mesmo nas duas fases.
 *
 * A Fase 2 tinha o dobro da Fase 1 pela conversa que a mesa precisa ter antes
 * de decidir. Ela continua precisando, mas passou a acontecer **antes** de a
 * rodada abrir: a candidatura ao posto de representante virou um estado próprio,
 * com o cronômetro parado, então a mesa chega organizada.
 *
 * Como as anteriores, mexe só em quem está no padrão de antes (60 na Fase 1,
 * 120 na Fase 2). Duração ajustada à mão pelo facilitador é decisão dele.
 */
return new class extends Migration
{
    private const PHASE_ONE_OLD = 60;

    private const PHASE_TWO_OLD = 120;

    private const NEW = 90;

    public function up(): void
    {
        $this->move(1, self::PHASE_ONE_OLD, self::NEW);
        $this->move(2, self::PHASE_TWO_OLD, self::NEW);

        // o relógio do evento fora de rodada, que é o que o painel mostra antes
        // do primeiro "abrir votação"
        DB::table('events')
            ->where('round_duration', self::PHASE_ONE_OLD)
            ->update(['round_duration' => self::NEW]);
    }

    public function down(): void
    {
        $this->move(1, self::NEW, self::PHASE_ONE_OLD);
        $this->move(2, self::NEW, self::PHASE_TWO_OLD);

        DB::table('events')
            ->where('round_duration', self::NEW)
            ->update(['round_duration' => self::PHASE_ONE_OLD]);
    }

    private function move(int $phase, int $from, int $to): void
    {
        DB::table('questions')
            ->where('phase', $phase)
            ->where('duration', $from)
            ->update(['duration' => $to]);
    }
};
