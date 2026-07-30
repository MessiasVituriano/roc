<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Participant;
use App\Models\ParticipantVote;
use App\Models\TableVote;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SeedDemoParticipants extends Command
{
    protected $signature = 'live:demo
        {--participants=150 : How many people to spread across the tables}
        {--progress=0 : Percentual da rodada atual já respondido (0-100)}';

    protected $description = 'Fill the current event with fake participants to rehearse the room';

    protected array $names = [
        'Ana', 'Carlos', 'Julia', 'Pedro', 'Marina', 'Rafael', 'Beatriz', 'Lucas',
        'Camila', 'Bruno', 'Fernanda', 'Diego', 'Larissa', 'Thiago', 'Patrícia',
        'Gustavo', 'Renata', 'Felipe', 'Aline', 'Rodrigo', 'Juliana', 'Marcelo',
    ];

    public function handle(): int
    {
        $event = Event::query()->orderByDesc('id')->first();

        if (! $event) {
            $this->error('Nenhum evento encontrado. Rode php artisan db:seed antes.');

            return self::FAILURE;
        }

        $tables = $event->tables()->get();

        if ($tables->isEmpty()) {
            $this->error('O evento não tem mesas cadastradas.');

            return self::FAILURE;
        }

        $total = (int) $this->option('participants');

        for ($i = 0; $i < $total; $i++) {
            $table = $tables[$i % $tables->count()];

            Participant::create([
                'event_id' => $event->id,
                'event_table_id' => $table->id,
                'name' => $this->names[$i % count($this->names)].' '.Str::upper(Str::random(1)).'.',
                'email' => 'demo'.$i.'@exemplo.com',
                'gender' => ['male', 'female', 'custom'][$i % 3],
                'avatar_seed' => 'demo-'.$i.'-'.Str::random(6),
                'device_token' => Str::random(48),
                'connected' => true,
                'last_seen' => now(),
            ]);
        }

        if ($total > 0) {
            $this->info("{$total} participantes distribuídos em {$tables->count()} mesas.");
        }

        $percent = max(0, min(100, (int) $this->option('progress')));

        if ($percent > 0) {
            $this->fillAnswers($event, $percent);
        }

        return self::SUCCESS;
    }

    /**
     * Vota na rodada atual em nome de quem ainda não votou, com o viés da
     * missão embutido — é o que faz o placar por missão ficar legível no ensaio.
     */
    protected function fillAnswers(Event $event, int $percent): void
    {
        $question = $event->currentQuestion();

        if (! $question) {
            $this->warn('Nenhuma rodada carregada.');

            return;
        }

        $options = $question->options->values();
        $answers = 0;

        if ($question->isIndividual()) {
            $people = $event->participants()->orderBy('id')->get();
            $target = (int) round($people->count() * $percent / 100);

            foreach ($people->take($target) as $index => $person) {
                // cada missão puxa para uma alternativa diferente
                $bias = ($person->mission_id ?? 0) % $options->count();
                $option = $options[($index % 4 === 0) ? $index % $options->count() : $bias];

                ParticipantVote::firstOrCreate(
                    ['participant_id' => $person->id, 'question_id' => $question->id],
                    [
                        'option_id' => $option->id,
                        'event_table_id' => $person->event_table_id,
                        'mission_id' => $person->mission_id,
                        'points' => $option->points,
                    ],
                );
                $answers++;
            }

            $this->info("{$answers} votos individuais na rodada {$question->round}.");

            return;
        }

        $tables = $event->tables()->get();
        $target = (int) round($tables->count() * $percent / 100);

        foreach ($tables->take($target) as $index => $table) {
            $representative = $table->representative_id
                ?? $table->participants()->inRandomOrder()->value('id');

            if ($representative && ! $table->representative_id) {
                EventTable::where('id', $table->id)->update(['representative_id' => $representative]);
            }

            // a rodada final não tem alternativas: no ensaio, simula o que o
            // facilitador lançaria à mão, variando pela régua da dinâmica
            $option = $question->isManual() ? null : $options[$index % $options->count()];
            $points = $option?->points ?? [150, 80, 0, -50][$index % 4];

            TableVote::firstOrCreate(
                ['event_table_id' => $table->id, 'question_id' => $question->id],
                [
                    'option_id' => $option?->id,
                    'participant_id' => $option ? $representative : null,
                    'points' => $points,
                ],
            );
            $answers++;
        }

        $this->info("{$answers} decisões de mesa registradas.");
    }
}
