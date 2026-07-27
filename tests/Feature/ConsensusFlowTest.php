<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\EventTable;
use App\Models\Mission;
use App\Models\Participant;
use App\Models\Question;
use Database\Seeders\LiveConsensusSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsensusFlowTest extends TestCase
{
    use RefreshDatabase;

    protected array $master = ['X-Master-Token' => 'master-dev-token'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['live.master_token' => 'master-dev-token']);
        $this->seed(LiveConsensusSeeder::class);
    }

    protected function join(string $name, int $tableId): string
    {
        return $this->postJson('/api/join', [
            'name' => $name,
            'email' => Str::slug($name).'@exemplo.com',
            'gender' => 'female',
            'table_id' => $tableId,
        ])->assertCreated()->json('token');
    }

    protected function auth(string $token): array
    {
        return ['X-Participant-Token' => $token];
    }

    protected function question(int $phase, int $round): Question
    {
        return Question::with('options')->where('phase', $phase)->where('round', $round)->firstOrFail();
    }

    /** Uma alternativa por pontuação, para os testes não dependerem da ordem. */
    protected function optionWorth(Question $question, int $points): int
    {
        return $question->options->firstWhere('points', $points)->id;
    }

    public function test_master_endpoints_require_the_shared_token(): void
    {
        $this->postJson('/api/admin/start')->assertUnauthorized();
        $this->postJson('/api/admin/start', [], ['X-Master-Token' => 'wrong'])->assertUnauthorized();
    }

    public function test_missions_are_assigned_evenly_across_participants(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        foreach (range(1, 10) as $i) {
            $this->join("Pessoa {$i}", ($i % 4) + 1);
        }

        // a missão sai já na entrada, então os grupos ficam equilibrados
        // mesmo com quem chega atrasado
        $counts = Mission::withCount('participants')->get()->pluck('participants_count');

        $this->assertSame(10, $counts->sum());
        $this->assertLessThanOrEqual(1, $counts->max() - $counts->min());
    }

    public function test_points_and_effects_are_hidden_until_the_facilitator_reveals(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        // durante a votação nada de gabarito, nem no telão nem no celular
        $display = $this->getJson('/api/display')->assertOk()->json();
        $this->assertNull($display['results']);
        foreach ($display['question']['options'] as $option) {
            $this->assertArrayNotHasKey('points', $option);
            $this->assertArrayNotHasKey('effect', $option);
            $this->assertArrayNotHasKey('color', $option);
        }

        $status = $this->getJson('/api/status', $this->auth($token))->json();
        foreach ($status['question']['options'] as $option) {
            $this->assertArrayNotHasKey('points', $option);
        }
        $this->assertArrayNotHasKey('bias_note', $display['question']);

        $this->postJson('/api/admin/reveal', [], $this->master)->assertOk();

        // revelado: a distribuição aparece, o gabarito não
        $display = $this->getJson('/api/display')->json();
        $this->assertNotNull($display['results']);
        $this->assertFalse($display['results']['has_answer_key']);

        foreach ($display['results']['options'] as $option) {
            $this->assertArrayHasKey('percent', $option);
            $this->assertArrayNotHasKey('points', $option);
            $this->assertArrayNotHasKey('effect', $option);
            $this->assertArrayNotHasKey('is_best', $option);
            // `color` sai de colorForPoints(): verde = +150, vermelho = -50.
            // Mandá-la seria mandar a régua pintada.
            $this->assertArrayNotHasKey('color', $option);
        }
    }

    /**
     * O sigilo do gabarito é o que sustenta a Fase 2: ela repete as perguntas
     * da Fase 1, então qualquer pista sobre a melhor alternativa numa fase
     * entrega a outra.
     */
    public function test_the_answer_key_stays_hidden_until_the_event_ends(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($token));
        $this->postJson('/api/admin/reveal', [], $this->master);

        // a ordem também é gabarito: ordenar por régua põe a melhor no topo
        $revealed = $this->getJson('/api/display')->json('results.options');
        $this->assertSame(
            $question->options->pluck('text')->all(),
            collect($revealed)->pluck('text')->all(),
            'a revelação deve manter a ordem original das alternativas',
        );

        // nem os pontos da própria escolha, nem o total — que entregaria por diferença
        $me = $this->getJson('/api/status', $this->auth($token))->json('me');
        $this->assertTrue($me['has_voted']);
        $this->assertNull($me['round_points']);
        $this->assertNull($me['total_points']);
        // acerto é gabarito: saber que acertou é saber qual era a certa
        $this->assertNull($me['correct']);

        $public = $this->getJson('/api/display')->json();
        $this->assertArrayNotHasKey('answer_key', $public);
        // o placar por mesa é gabarito por aritmética numa mesa pequena
        $this->assertArrayNotHasKey('table_ranking', $public);

        // o facilitador enxerga tudo desde sempre — o painel dele nunca é projetado
        $master = $this->getJson('/api/admin/overview', $this->master)->json();
        $this->assertTrue($master['results']['has_answer_key']);
        $this->assertArrayHasKey('points', $master['results']['options'][0]);
        $this->assertArrayHasKey('table_ranking', $master);
        $this->assertFalse($master['needs_tie_break']);

        // encerrado: o gabarito enfim aparece. `end()` volta a rodada para
        // `idle`, então quem carrega a revelação final é o answer_key, não
        // o `results` da rodada corrente.
        $this->postJson('/api/admin/end', [], $this->master)->assertOk();

        $display = $this->getJson('/api/display')->json();
        $this->assertNull($display['results']);
        $this->assertArrayHasKey('answer_key', $display);
        $this->assertArrayHasKey('table_ranking', $display);
        $this->assertCount(5, $display['answer_key']);

        $me = $this->getJson('/api/status', $this->auth($token))->json('me');
        $this->assertSame(150, $me['total_points']);
        $this->assertSame(1, $me['correct']);
        $this->assertSame(5, $me['rounds']);
    }

    public function test_the_scoreboards_count_hits_in_both_phases(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        // Fase 1, rodada 1: Ana acerta, Bruno erra
        $this->postJson('/api/admin/start', [], $this->master);
        $first = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, -50)], $this->auth($bruno));

        // Fase 1, rodada 2: os dois acertam
        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $second = $this->question(1, 2);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($second, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($second, 150)], $this->auth($bruno));

        $overview = $this->getJson('/api/admin/overview', $this->master)->json();

        // individual: Ana 2/2, Bruno 1/2
        $people = collect($overview['individual_ranking'])->keyBy('name');
        $this->assertSame(2, $people['Ana']['correct']);
        $this->assertSame(100, $people['Ana']['accuracy']);
        $this->assertSame(1, $people['Bruno']['correct']);
        $this->assertSame(50, $people['Bruno']['accuracy']);
        // a régua da Fase 1 tem 5 rodadas, independente de quantas já rolaram
        $this->assertSame(5, $people['Ana']['rounds']);

        // sem Fase 2 ainda, a coluna da mesa é zero e o total é o individual
        $this->assertSame(0, $people['Ana']['table_points']);
        $this->assertSame($people['Ana']['points'], $people['Ana']['combined_points']);

        // mesa na Fase 1: 3 acertos em 4 votos de membros
        $table = collect($overview['table_ranking'])->firstWhere('table_id', 1);
        $this->assertSame(3, $table['phase_one_correct']);
        $this->assertSame(4, $table['phase_one_votes']);
        $this->assertSame(75, $table['phase_one_accuracy']);
        $this->assertSame(0, $table['phase_two_correct']);
        $this->assertNull($table['phase_two_accuracy']);

        // por missão, o recorte que revela o viés — no painel do facilitador
        foreach ($overview['mission_ranking'] as $row) {
            $this->assertNotNull($row['correct']);
        }

        // mas não no telão da virada, que acontece antes da Fase 2
        $this->postJson('/api/admin/missions/reveal', [], $this->master);
        foreach ($this->getJson('/api/display')->json('mission_ranking') as $row) {
            $this->assertNull($row['correct'], 'acerto por missão não pode ir ao telão antes do fim');
            $this->assertNull($row['accuracy']);
        }

        // Fase 2: a mesa decide junto e acerta
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $mirror = $this->question(2, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($mirror, 150)], $this->auth($ana));

        $table = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
            ->firstWhere('table_id', 1);

        $this->assertSame(1, $table['phase_two_correct']);
        $this->assertSame(1, $table['phase_two_votes']);
        $this->assertSame(100, $table['phase_two_accuracy']);
        // a Fase 1 não se mexe quando a Fase 2 anda
        $this->assertSame(75, $table['phase_one_accuracy']);

        // e no ranking por pessoa a Fase 2 da mesa entra para os dois membros,
        // porque foi uma decisão que os dois carregam
        $people = collect($this->getJson('/api/admin/overview', $this->master)->json('individual_ranking'))
            ->keyBy('name');

        foreach (['Ana', 'Bruno'] as $name) {
            $this->assertSame(150, $people[$name]['table_points'], "{$name} deveria levar a Fase 2 da mesa");
            $this->assertSame(1, $people[$name]['table_correct']);
            $this->assertSame(
                $people[$name]['points'] + 150,
                $people[$name]['combined_points'],
            );
        }

        // individual continua sendo individual: Ana acertou as duas, Bruno uma
        $this->assertNotSame($people['Ana']['points'], $people['Bruno']['points']);
    }

    /**
     * O comparativo é o fecho da dinâmica — e o clique que o abre é o mesmo que
     * libera o gabarito, porque os dois dizem a mesma coisa.
     */
    public function test_revealing_the_answers_opens_the_phase_comparison(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 2);

        // Fase 1: um acerta, o outro erra → 50%
        $this->postJson('/api/admin/start', [], $this->master);
        $first = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 0)], $this->auth($bruno));

        // Fase 2, mesma pergunta: as duas mesas acertam → 100%
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $mirror = $this->question(2, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($mirror, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($mirror, 150)], $this->auth($bruno));

        // fechado: sem comparativo e sem gabarito no telão
        $before = $this->getJson('/api/display')->json();
        $this->assertFalse($before['event']['answers_revealed']);
        $this->assertArrayNotHasKey('phase_comparison', $before);
        $this->assertArrayNotHasKey('answer_key', $before);

        $this->postJson('/api/admin/answers/reveal', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.answers_revealed', true);

        $display = $this->getJson('/api/display')->json();
        $comparison = $display['phase_comparison'];

        $this->assertSame(50, $comparison['individual']['accuracy']);
        $this->assertSame(100, $comparison['table']['accuracy']);
        $this->assertSame(50, $comparison['accuracy_delta']);
        $this->assertTrue($comparison['consensus_won']);
        $this->assertSame(1, $comparison['individual']['correct']);
        $this->assertSame(2, $comparison['table']['correct']);

        // o gabarito vem junto, e o evento nem precisou ser encerrado
        $this->assertArrayHasKey('answer_key', $display);
        $this->assertNotSame(Event::STATUS_FINISHED, $display['event']['status']);
        $this->assertSame(150, $this->getJson('/api/status', $this->auth($ana))->json('me.total_points'));

        // e dá para fechar de novo, se foi cedo demais
        $this->postJson('/api/admin/answers/hide', [], $this->master)->assertOk();
        $this->assertArrayNotHasKey('phase_comparison', $this->getJson('/api/display')->json());
    }

    public function test_the_answer_key_pairs_each_round_with_both_phases(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 2);

        // Fase 1: Ana acerta, Bruno erra — 50% de acerto individual
        $this->postJson('/api/admin/start', [], $this->master);
        $first = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, -50)], $this->auth($bruno));

        // Fase 2, mesma pergunta: as duas mesas acertam — 100%
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $mirror = $this->question(2, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($mirror, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($mirror, 150)], $this->auth($bruno));

        $this->postJson('/api/admin/end', [], $this->master);

        $round = collect($this->getJson('/api/display')->json('answer_key'))->firstWhere('round', 1);

        $this->assertSame($first->title, $round['title']);
        $this->assertSame(150, $round['best_points']);
        $this->assertSame(50, $round['individual_accuracy']);
        $this->assertSame(100, $round['table_accuracy']);

        // a melhor decisão encabeça a lista, agora que pode
        $this->assertTrue($round['options'][0]['is_best']);
        $this->assertSame(1, $round['options'][0]['individual_votes']);
        $this->assertSame(2, $round['options'][0]['table_votes']);
    }

    public function test_the_bias_note_never_reaches_the_public_screens(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/admin/reveal', [], $this->master);

        // nota de condução: existe para o facilitador, nunca para a plateia
        $this->assertStringNotContainsString('Viés', $this->getJson('/api/display')->getContent());
        $this->assertNotNull(
            $this->getJson('/api/admin/overview', $this->master)->json('question.bias_note')
        );
    }

    public function test_a_vote_freezes_the_points_of_the_chosen_option(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);

        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($question, 150),
        ], $this->auth($token))->assertCreated()->assertJsonPath('accepted', true);

        $this->assertDatabaseHas('participant_votes', [
            'participant_id' => Participant::first()->id,
            'points' => 150,
            'mission_id' => Participant::first()->mission_id,
        ]);

        // editar a régua depois não reescreve o placar já formado
        $question->options()->update(['points' => 0]);
        $this->assertSame(150, $this->getJson('/api/admin/overview', $this->master)
            ->json('individual_ranking.0.points'));
    }

    public function test_nobody_votes_twice_in_the_same_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertCreated();
        // colegas de mesa votam de forma independente na Fase 1
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($bruno))
            ->assertCreated();

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('accepted', false)
            ->assertJsonPath('option_id', $this->optionWorth($question, 150));

        $this->assertDatabaseCount('participant_votes', 2);
    }

    public function test_votes_are_rejected_once_the_round_closes(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/admin/close', [], $this->master)->assertOk();

        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($token))->assertStatus(409);

        $this->assertDatabaseCount('participant_votes', 0);
    }

    public function test_the_mission_scoreboard_reveals_the_bias(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $tokens = collect(range(1, 4))->map(fn ($i) => $this->join("Pessoa {$i}", 1));
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);
        // a primeira pessoa acerta a melhor decisão, as demais não
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($tokens[0]));
        foreach ($tokens->slice(1) as $token) {
            $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($token));
        }

        $ranking = $this->getJson('/api/admin/overview', $this->master)->json('mission_ranking');

        $this->assertCount(4, $ranking);
        // ordenado pela média, então a missão de quem acertou lidera
        $this->assertEquals(150, $ranking[0]['average']);
        $this->assertEquals(-50, $ranking[3]['average']);

        // o recorte por missão só vai ao telão na virada de fase
        $this->assertArrayNotHasKey('mission_ranking', $this->getJson('/api/display')->json());

        $this->postJson('/api/admin/missions/reveal', [], $this->master)->assertOk();
        $this->assertArrayHasKey('mission_ranking', $this->getJson('/api/display')->json());
    }

    public function test_phase_two_is_answered_by_the_table_representative(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('event.phase_mode', 'consensus');
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(2, 1);

        // quem responde primeiro assume o posto; os demais ficam bloqueados
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertCreated();
        $this->assertSame(
            Participant::where('name', 'Ana')->value('id'),
            EventTable::find(1)->representative_id,
        );

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($bruno))
            ->assertForbidden();

        $this->assertDatabaseCount('table_votes', 1);
        $this->assertDatabaseHas('table_votes', ['points' => 150]);
    }

    public function test_the_table_ranking_follows_the_four_level_tie_break(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 2);

        // Fase 1: as duas mesas fazem a mesma pontuação
        $this->postJson('/api/admin/start', [], $this->master);
        $round = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($round, 80)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($round, 80)], $this->auth($bruno));

        // Fase 2: a mesa 2 decide melhor e assume a liderança
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $final = $this->question(2, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($final, 0)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($final, 150)], $this->auth($bruno));

        $ranking = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'));

        $leader = $ranking->firstWhere('position', 1);
        $this->assertSame(2, $leader['table_id']);
        $this->assertSame(230, $leader['total_points']);
        $this->assertSame(150, $leader['phase_two_points']);
        // evolução: 150 na Fase 2 contra 80 por rodada na Fase 1
        $this->assertEquals(70, $leader['evolution']);

        $second = $ranking->firstWhere('table_id', 1);
        $this->assertSame(80, $second['total_points']);
        $this->assertFalse($this->getJson('/api/admin/overview', $this->master)->json('needs_tie_break'));
    }

    public function test_a_leadership_tie_flags_the_bonus_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 2);

        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $final = $this->question(2, 1);

        // empate perfeito: mesmo total, mesma Fase 2, mesma evolução
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($final, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($final, 150)], $this->auth($bruno));

        $this->assertTrue($this->getJson('/api/admin/overview', $this->master)->json('needs_tie_break'));

        // e a rodada bônus existe para resolver
        $this->postJson('/api/admin/bonus-round', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', true);
    }

    public function test_the_bonus_round_is_not_reachable_by_next_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/next-phase', [], $this->master);

        // anda até a última rodada real da Fase 2
        for ($round = 1; $round < 5; $round++) {
            $this->postJson('/api/admin/next', [], $this->master)
                ->assertOk()
                ->assertJsonPath('event.round', $round + 1);
        }

        // na quinta, "próxima rodada" para — o desempate mora na rodada 6 e só
        // é alcançável pelo botão dedicado do painel
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', false)
            ->assertJsonPath('event.round', 5);
    }

    public function test_adding_time_extends_the_round_without_restarting_it(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/start', ['duration' => 20], $this->master);

        $before = $this->getJson('/api/admin/overview', $this->master)->json('timer.remaining');

        $this->postJson('/api/admin/add-time', ['seconds' => 30], $this->master)
            ->assertOk()
            ->assertJsonPath('timer.duration', 50);

        $after = $this->getJson('/api/admin/overview', $this->master)->json('timer.remaining');
        $this->assertGreaterThan($before, $after);

        $this->postJson('/api/admin/add-time', ['seconds' => 0], $this->master)
            ->assertStatus(422);
    }

    public function test_phase_two_repeats_the_phase_one_questions_as_table_decisions(): void
    {
        $phaseOne = Question::with('options')->where('phase', 1)->orderBy('round')->get();
        $phaseTwo = Question::with('options')->where('phase', 2)->where('is_bonus', false)
            ->orderBy('round')->get();

        $this->assertCount(5, $phaseOne);
        $this->assertCount(5, $phaseTwo);

        foreach ($phaseOne as $i => $original) {
            $mirror = $phaseTwo[$i];

            $this->assertSame($original->round, $mirror->round);
            $this->assertSame($original->title, $mirror->title);
            $this->assertSame($original->context, $mirror->context);
            $this->assertSame($original->label, $mirror->label);

            // mesma pergunta, mas o voto passa a ser da mesa
            $this->assertSame(Question::MODE_INDIVIDUAL, $original->mode);
            $this->assertSame(Question::MODE_CONSENSUS, $mirror->mode);

            // a régua tem de ser idêntica, senão a evolução Fase 1 → Fase 2
            // deixa de comparar a mesma coisa
            $this->assertSame(
                $original->options->map->only(['text', 'effect', 'points', 'order'])->all(),
                $mirror->options->map->only(['text', 'effect', 'points', 'order'])->all(),
            );
        }
    }

    public function test_phase_two_walks_its_five_table_rounds(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.total_rounds', 5);

        for ($round = 1; $round <= 5; $round++) {
            $this->postJson('/api/admin/start', [], $this->master)
                ->assertOk()
                ->assertJsonPath('event.phase', 2)
                ->assertJsonPath('event.round', $round)
                ->assertJsonPath('event.phase_mode', 'consensus');

            $this->postJson('/api/vote', [
                'option_id' => $this->optionWorth($this->question(2, $round), 150),
            ], $this->auth($ana))->assertCreated();

            $this->postJson('/api/admin/reveal', [], $this->master)->assertOk();
            $this->postJson('/api/admin/next', [], $this->master)->assertOk();
        }

        // uma linha de voto por mesa por rodada, e o placar soma as cinco
        $this->assertDatabaseCount('table_votes', 5);

        $leader = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
            ->firstWhere('table_id', 1);

        $this->assertSame(750, $leader['phase_two_points']);
    }

    public function test_the_event_walks_five_rounds_then_phase_two(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        for ($round = 1; $round <= 5; $round++) {
            $this->postJson('/api/admin/start', [], $this->master)
                ->assertOk()
                ->assertJsonPath('event.phase', 1)
                ->assertJsonPath('event.round', $round)
                ->assertJsonPath('event.total_rounds', 5);

            $this->postJson('/api/admin/reveal', [], $this->master)
                ->assertOk()
                ->assertJsonPath('event.round_status', 'revealed');

            $this->postJson('/api/admin/next', [], $this->master)->assertOk();
        }

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2);

        $this->postJson('/api/admin/end', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.status', Event::STATUS_FINISHED);
    }

    public function test_resetting_a_round_clears_its_votes(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($token));

        $this->assertDatabaseCount('participant_votes', 1);
        $this->postJson('/api/admin/reset-round', [], $this->master)->assertOk();
        $this->assertDatabaseCount('participant_votes', 0);
    }

    public function test_join_requires_a_valid_and_unique_email(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        $this->postJson('/api/join', ['name' => 'Ana', 'gender' => 'female', 'table_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/join', [
            'name' => 'Ana', 'email' => 'ana@exemplo.com', 'gender' => 'female', 'table_id' => 1,
        ])->assertCreated();

        $this->postJson('/api/join', [
            'name' => 'Ana 2', 'email' => 'ANA@exemplo.com', 'gender' => 'female', 'table_id' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('participants', 1);
    }

    public function test_participant_emails_never_leak_to_other_screens(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);

        $this->assertStringNotContainsString('@', $this->getJson('/api/display')->getContent());
        $this->assertStringNotContainsString('@', $this->getJson('/api/status', $this->auth($token))->getContent());
    }

    public function test_layout_positions_can_be_saved_in_bulk(): void
    {
        $this->postJson('/api/admin/layout', [
            'tables' => [['id' => 1, 'position_x' => 10.5, 'position_y' => 80.25]],
        ], $this->master)->assertOk()->assertJsonPath('saved', 1);

        $tables = collect($this->getJson('/api/display')->json('tables'))->keyBy('id');
        $this->assertEquals(10.5, $tables[1]['position_x']);
    }
}
