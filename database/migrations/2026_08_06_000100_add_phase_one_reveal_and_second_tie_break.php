<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duas coisas que o roteiro passou a pedir.
 *
 * **`phase_one_revealed`** — o fecho da Fase 1. Sem revelação por rodada, a
 * pessoa atravessa cinco decisões sem saber nada do próprio resultado; este
 * clique devolve a ela o **total** que fez sozinha, e só isso: nem o gabarito
 * nem quanto valeu cada pergunta. É uma coluna separada de `answers_revealed`
 * de propósito — o que ela libera é o placar de uma pessoa sobre si mesma, não
 * a régua do evento.
 *
 * **A segunda rodada de desempate** — o desempate passa a ter duas perguntas.
 * A primeira já existia (rodada 7); esta migração cria a rodada 8 nos eventos
 * que existem, copiando a estrutura da primeira, porque as perguntas são
 * preenchidas à mão depois.
 */
return new class extends Migration
{
    private const FIRST_TIE_BREAK = 7;

    private const SECOND_TIE_BREAK = 8;

    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('phase_one_revealed')->default(false)->after('missions_revealed');
        });

        foreach (DB::table('events')->pluck('id') as $eventId) {
            $first = DB::table('questions')
                ->where('event_id', $eventId)
                ->where('is_bonus', true)
                ->orderBy('round')
                ->first();

            // evento sem desempate cadastrado, ou que já tem os dois
            if (! $first || DB::table('questions')
                ->where('event_id', $eventId)->where('is_bonus', true)->count() > 1) {
                continue;
            }

            DB::table('questions')
                ->where('id', $first->id)
                ->update(['round' => self::FIRST_TIE_BREAK]);

            $id = DB::table('questions')->insertGetId([
                'event_id' => $eventId,
                'phase' => $first->phase,
                'round' => self::SECOND_TIE_BREAK,
                'mode' => $first->mode,
                'label' => 'DESEMPATE 2',
                'title' => '[PREENCHER] Segunda pergunta de desempate',
                'context' => '[PREENCHER com a segunda pergunta bônus. Usada só se o empate sobreviver à primeira.]',
                'bias_note' => null,
                'duration' => $first->duration,
                'is_bonus' => true,
                'manual_scoring' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('options')->where('question_id', $first->id)->orderBy('order')->get() as $option) {
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

    public function down(): void
    {
        DB::table('questions')
            ->where('is_bonus', true)
            ->where('round', self::SECOND_TIE_BREAK)
            ->delete();

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('phase_one_revealed');
        });
    }
};
