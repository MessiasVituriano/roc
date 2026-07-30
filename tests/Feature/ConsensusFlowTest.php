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
use Illuminate\Testing\TestResponse;
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

    /** A rodada final é pontuada à mão: o placar dela passa por aqui. */
    protected function scoreTable(int $tableId, ?int $points): TestResponse
    {
        return $this->postJson(
            '/api/admin/final-score',
            ['event_table_id' => $tableId, 'points' => $points],
            $this->master,
        );
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

    /**
     * O rodízio é **por mesa**: é dentro dela que a Fase 2 acontece, e uma mesa
     * inteira com a mesma missão não teria conflito para resolver.
     */
    public function test_each_table_gets_all_four_missions_before_repeating(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        // mesa 1 com 10 pessoas: 4 + 4 + 2
        foreach (range(1, 10) as $i) {
            $this->join("Mesa um {$i}", 1);
        }

        // mesa 2 com 3: três missões diferentes, nenhuma repetida
        foreach (range(1, 3) as $i) {
            $this->join("Mesa dois {$i}", 2);
        }

        $first = Participant::where('event_table_id', 1)->pluck('mission_id');
        $this->assertCount(4, $first->unique(), 'a mesa de 10 precisa ter as 4 missões');
        $this->assertEqualsCanonicalizing(
            [3, 3, 2, 2],
            $first->countBy()->values()->all(),
            'com 10 pessoas o rodízio fecha em 4 + 4 + 2',
        );

        $second = Participant::where('event_table_id', 2)->pluck('mission_id');
        $this->assertCount(3, $second->unique(), 'com 3 pessoas nenhuma missão se repete');
    }

    /** O re-sorteio do painel redistribui tudo, mantendo o rodízio por mesa. */
    public function test_reassigning_missions_keeps_the_round_robin_inside_each_table(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        foreach (range(1, 6) as $i) {
            $this->join("Pessoa {$i}", 1);
        }

        $before = Participant::orderBy('id')->pluck('mission_id');

        $this->postJson('/api/admin/missions/assign', ['reassign' => true], $this->master)
            ->assertOk()
            ->assertJsonPath('assigned', 6);

        $after = Participant::orderBy('id')->pluck('mission_id');

        $this->assertCount(0, $after->filter(fn ($id) => $id === null));
        // 6 pessoas = ciclo de 4 + sobra de 2
        $this->assertEqualsCanonicalizing([2, 2, 1, 1], $after->countBy()->values()->all());
        $this->assertCount(6, $before);
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
     * O sigilo do gabarito é o que sustenta o fecho: saber a régua da rodada 1
     * muda como a sala joga as quatro seguintes, e pontuação individual é
     * gabarito por aritmética.
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
        $this->assertNull($table['phase_two_correct']);
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

        // Fase 2: a mesa decide a missão final e o facilitador lança os pontos
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->scoreTable(1, 150)->assertOk();

        $table = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
            ->firstWhere('table_id', 1);

        $this->assertSame(150, $table['phase_two_points']);
        $this->assertSame(1, $table['phase_two_votes']);
        // sem alternativa não havia régua: não há acerto a contar
        $this->assertNull($table['phase_two_correct']);
        $this->assertNull($table['phase_two_accuracy']);
        // a Fase 1 não se mexe quando a Fase 2 anda
        $this->assertSame(75, $table['phase_one_accuracy']);

        // e no ranking por pessoa a Fase 2 da mesa entra para os dois membros,
        // porque foi uma decisão que os dois carregam
        $people = collect($this->getJson('/api/admin/overview', $this->master)->json('individual_ranking'))
            ->keyBy('name');

        foreach (['Ana', 'Bruno'] as $name) {
            $this->assertSame(150, $people[$name]['table_points'], "{$name} deveria levar a Fase 2 da mesa");
            $this->assertNull($people[$name]['table_correct']);
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

        // Fase 1: um acerta (150), o outro não (0) → 50% de acerto, média 75
        $this->postJson('/api/admin/start', [], $this->master);
        $first = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 0)], $this->auth($bruno));

        // Fase 2: a rodada final, com as duas mesas pontuadas em 150
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->scoreTable(1, 150);
        $this->scoreTable(2, 150);

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
        $this->assertSame(1, $comparison['individual']['correct']);

        // a rodada final não tem régua: acerto só existe do lado individual, e
        // o que sobra para comparar é o valor gerado por decisão
        $this->assertNull($comparison['table']['accuracy']);
        $this->assertNull($comparison['table']['correct']);
        $this->assertNull($comparison['accuracy_delta']);
        $this->assertEquals(75, $comparison['individual']['average']);
        $this->assertEquals(150, $comparison['table']['average']);
        $this->assertEquals(75, $comparison['average_delta']);
        $this->assertTrue($comparison['consensus_won']);

        // o gabarito vem junto, e o evento nem precisou ser encerrado
        $this->assertArrayHasKey('answer_key', $display);
        $this->assertNotSame(Event::STATUS_FINISHED, $display['event']['status']);
        $this->assertSame(150, $this->getJson('/api/status', $this->auth($ana))->json('me.total_points'));

        // a rodada final entra no fecho como pontuação por mesa
        $this->assertSame(
            [150, 150],
            collect($display['final_round']['scores'])->where('scored', true)->pluck('points')->values()->all(),
        );

        // e dá para fechar de novo, se foi cedo demais
        $this->postJson('/api/admin/answers/hide', [], $this->master)->assertOk();
        $this->assertArrayNotHasKey('phase_comparison', $this->getJson('/api/display')->json());
    }

    public function test_the_answer_key_covers_the_five_scored_rounds(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 2);

        // Fase 1: Ana acerta, Bruno erra — 50% de acerto individual
        $this->postJson('/api/admin/start', [], $this->master);
        $first = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($first, -50)], $this->auth($bruno));

        $this->postJson('/api/admin/end', [], $this->master);

        $key = $this->getJson('/api/display')->json('answer_key');

        // só as rodadas com régua entram no gabarito — a final não tem
        $this->assertCount(5, $key);

        $round = collect($key)->firstWhere('round', 1);
        $this->assertSame($first->title, $round['title']);
        $this->assertSame(150, $round['best_points']);
        $this->assertSame(50, $round['individual_accuracy']);

        // a melhor decisão encabeça a lista, agora que pode
        $this->assertTrue($round['options'][0]['is_best']);
        $this->assertSame(1, $round['options'][0]['individual_votes']);
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

    /**
     * A rodada de desempate é a única da Fase 2 que ainda se registra pelo
     * celular — e continua valendo a regra do representante.
     */
    public function test_the_bonus_round_is_answered_by_the_table_representative(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('event.phase_mode', 'consensus');

        $this->postJson('/api/admin/bonus-round', [], $this->master)->assertOk();
        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', true);

        $question = Question::with('options')->where('is_bonus', true)->firstOrFail();

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

    /**
     * A rodada final: uma missão só, sem alternativas, decidida em consenso e
     * pontuada mesa a mesa pelo facilitador.
     */
    public function test_the_final_round_is_scored_by_the_facilitator(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('event.total_rounds', 1)
            ->assertJsonPath('event.phase_mode', 'consensus')
            ->assertJsonPath('question.manual_scoring', true);

        $this->postJson('/api/admin/start', [], $this->master)->assertOk();

        // o celular só exibe a missão: não há alternativa nem representante
        $status = $this->getJson('/api/status', $this->auth($ana))->json();
        $this->assertFalse($status['me']['can_answer']);
        $this->assertSame([], $status['question']['options']);
        $this->assertStringContainsString(
            'Maximizar o resultado total do hotel',
            $status['question']['context'],
        );

        $this->postJson('/api/vote', ['option_id' => 1], $this->auth($ana))->assertStatus(409);
        $this->postJson('/api/claim-representative', [], $this->auth($ana))->assertStatus(409);
        $this->assertDatabaseCount('table_votes', 0);

        // quem pontua é o facilitador, mesa a mesa
        $this->scoreTable(1, 120)->assertOk();
        $this->assertDatabaseHas('table_votes', [
            'event_table_id' => 1,
            'points' => 120,
            'option_id' => null,
        ]);

        $scores = collect($this->getJson('/api/admin/overview', $this->master)->json('final_round.scores'))
            ->keyBy('table_id');

        $this->assertSame(120, $scores[1]['points']);
        $this->assertTrue($scores[1]['scored']);
        $this->assertFalse($scores[2]['scored']);
        $this->assertNull($scores[2]['points']);

        // relançar corrige o valor em vez de somar uma linha nova
        $this->scoreTable(1, 150)->assertOk();
        $this->assertDatabaseCount('table_votes', 1);
        $this->assertSame(
            150,
            collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
                ->firstWhere('table_id', 1)['phase_two_points'],
        );

        // e `null` apaga o lançamento
        $this->scoreTable(1, null)->assertOk();
        $this->assertDatabaseCount('table_votes', 0);
    }

    /** A pontuação da rodada final só vai ao telão no clique do facilitador. */
    public function test_the_final_round_scores_wait_for_the_reveal(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->scoreTable(1, 150);

        // o facilitador tem sempre; o telão, não
        $this->assertArrayHasKey(
            'final_round',
            $this->getJson('/api/admin/overview', $this->master)->json(),
        );
        $this->assertArrayNotHasKey('final_round', $this->getJson('/api/display')->json());

        $this->postJson('/api/admin/reveal', [], $this->master)->assertOk();

        $display = $this->getJson('/api/display')->json();
        $this->assertArrayHasKey('final_round', $display);
        // a revelação da rodada final é o ranking, não a distribuição
        $this->assertTrue($display['results']['manual']);
        $this->assertSame([], $display['results']['options']);
        $this->assertSame(1, $display['results']['total_votes']);
        $this->assertSame(150, $display['results']['tables'][0]['points']);
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

        // Fase 2: a mesa 2 decide melhor a missão final e assume a liderança
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->scoreTable(1, 0);
        $this->scoreTable(2, 150);

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
        $this->join('Ana', 1);
        $this->join('Bruno', 2);

        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);

        // empate perfeito: mesmo total, mesma Fase 2, mesma evolução
        $this->scoreTable(1, 150);
        $this->scoreTable(2, 150);

        $this->assertTrue($this->getJson('/api/admin/overview', $this->master)->json('needs_tie_break'));

        // e a rodada bônus existe para resolver
        $this->postJson('/api/admin/bonus-round', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', true);
    }

    public function test_the_bonus_round_is_not_reachable_by_next_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.total_rounds', 1);

        // a Fase 2 tem uma rodada só, e "próxima rodada" para nela: o desempate
        // fica fora da contagem e só entra pelo botão dedicado do painel
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('question.is_bonus', false)
            ->assertJsonPath('question.manual_scoring', true);

        $this->postJson('/api/admin/bonus-round', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', true);
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

    /**
     * A estrutura do evento, como o PDF a define: cinco rodadas com régua na
     * Fase 1 e uma rodada final aberta na Fase 2.
     */
    public function test_phase_two_is_a_single_final_round_without_alternatives(): void
    {
        $phaseOne = Question::with('options')->where('phase', 1)->orderBy('round')->get();
        $phaseTwo = Question::with('options')->where('phase', 2)->where('is_bonus', false)->get();

        $this->assertCount(5, $phaseOne);
        $this->assertCount(1, $phaseTwo);

        foreach ($phaseOne as $question) {
            $this->assertSame(Question::MODE_INDIVIDUAL, $question->mode);
            $this->assertFalse($question->isManual());

            // a régua é a mesma em todas: uma melhor decisão (+150), uma boa
            // mas subótima (+80) e duas que não geram valor
            $points = $question->options->pluck('points');
            $this->assertCount(4, $points);
            $this->assertSame(1, $points->filter(fn ($p) => $p === 150)->count());
            $this->assertSame(1, $points->filter(fn ($p) => $p === 80)->count());
            $this->assertSame(2, $points->filter(fn ($p) => $p <= 0)->count());
        }

        $final = $phaseTwo->first();

        $this->assertSame(1, $final->round);
        $this->assertSame(Question::MODE_CONSENSUS, $final->mode);
        $this->assertTrue($final->isManual());
        // sem alternativas: a mesa decide livremente
        $this->assertCount(0, $final->options);
        $this->assertStringContainsString('Maximizar o resultado total do hotel', $final->context);
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
