<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A Fase 2 passa a rejogar, em mesa, os mesmos cinco cenários da Fase 1.
 *
 * Antes ela era só a rodada final — uma missão aberta, sem alternativas,
 * pontuada à mão. O comparativo do fecho, então, comparava coisas diferentes:
 * cinco escolhas com régua de um lado, uma missão aberta do outro. Com os
 * mesmos cinco cenários dos dois lados ele vira uma medida — a mesma pergunta,
 * a mesma régua, decidida sozinho e depois em mesa.
 *
 * A rodada final continua, agora como **rodada 6**: chegar nela tendo acabado
 * de rejogar em mesa o que cada um jogou sozinho é o que dá peso à decisão. O
 * desempate acompanha para a 7.
 *
 * O seeder já cria a forma nova; esta migração é para os eventos que existem.
 * Ela só age em quem ainda está na forma antiga — Fase 2 sem nenhum cenário de
 * mesa —, então rodar duas vezes não duplica nada e um evento já ajustado à
 * mão não é reescrito.
 */
return new class extends Migration
{
    private const FINAL_ROUND = 6;

    private const BONUS_ROUND = 7;

    public function up(): void
    {
        foreach (DB::table('events')->pluck('id') as $eventId) {
            // já tem cenário de mesa: evento novo, ou ajustado à mão
            $mirrored = DB::table('questions')
                ->where('event_id', $eventId)
                ->where('phase', 2)
                ->where('is_bonus', false)
                ->where('manual_scoring', false)
                ->exists();

            if ($mirrored) {
                continue;
            }

            // Abre espaço antes de inserir: o índice único (event_id, phase,
            // round) não deixa dois donos da rodada 1. O bônus sai primeiro
            // porque ele é quem está na 2, onde o segundo cenário vai entrar.
            DB::table('questions')
                ->where('event_id', $eventId)->where('phase', 2)->where('is_bonus', true)
                ->update(['round' => self::BONUS_ROUND]);

            DB::table('questions')
                ->where('event_id', $eventId)->where('phase', 2)->where('manual_scoring', true)
                ->update(['round' => self::FINAL_ROUND]);

            $this->mirror($eventId);
        }
    }

    /** Clona os cenários da Fase 1 — pergunta e alternativas — para a Fase 2. */
    private function mirror(int $eventId): void
    {
        // o relógio da fase sai da rodada final, que é a referência dela — e
        // sobrevive a um ajuste que o facilitador tenha feito à mão
        $duration = (int) DB::table('questions')
            ->where('event_id', $eventId)->where('phase', 2)->where('manual_scoring', true)
            ->value('duration') ?: 120;

        $scenarios = DB::table('questions')
            ->where('event_id', $eventId)
            ->where('phase', 1)
            ->where('is_bonus', false)
            ->orderBy('round')
            ->get();

        foreach ($scenarios as $scenario) {
            $id = DB::table('questions')->insertGetId([
                'event_id' => $eventId,
                'phase' => 2,
                'round' => $scenario->round,
                // a mesma pergunta, agora decidida pela mesa
                'mode' => 'consensus',
                'label' => $scenario->label,
                'title' => $scenario->title,
                'context' => $scenario->context,
                'bias_note' => $scenario->bias_note,
                'duration' => $duration,
                'is_bonus' => false,
                'manual_scoring' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // as alternativas vão junto: sem elas não há régua, e a rodada de
            // mesa cairia no caminho da pontuação à mão
            $options = DB::table('options')
                ->where('question_id', $scenario->id)
                ->orderBy('order')
                ->get();

            foreach ($options as $option) {
                DB::table('options')->insert([
                    'question_id' => $id,
                    'text' => $option->text,
                    'effect' => $option->effect,
                    'points' => $option->points,
                    'color' => $option->color,
                    'order' => $option->order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Desfaz o espelho. Apaga as perguntas clonadas — e, em cascata, os votos
     * de mesa presos nelas: são decisões que a forma antiga não tem onde
     * guardar.
     */
    public function down(): void
    {
        DB::table('questions')
            ->where('phase', 2)
            ->where('is_bonus', false)
            ->where('manual_scoring', false)
            ->delete();

        DB::table('questions')
            ->where('phase', 2)->where('manual_scoring', true)
            ->update(['round' => 1]);

        DB::table('questions')
            ->where('phase', 2)->where('is_bonus', true)
            ->update(['round' => 2]);
    }
};
