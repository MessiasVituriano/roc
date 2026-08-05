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
            'hotel' => 'Hotel Aurora',
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

    /**
     * Entra na Fase 2 e para na rodada 1 — o primeiro dos cinco cenários,
     * agora decidido pela mesa.
     */
    protected function startPhaseTwo(int $round = 1): Question
    {
        $this->postJson('/api/admin/next-phase', [], $this->master);

        for ($i = 1; $i < $round; $i++) {
            $this->postJson('/api/admin/next', [], $this->master);
        }

        $this->postJson('/api/admin/start', [], $this->master);

        return $this->question(2, $round);
    }

    /** A rodada final é a 6ª da Fase 2: vem depois dos cinco cenários de mesa. */
    protected function goToFinalRound(): Question
    {
        $this->postJson('/api/admin/next-phase', [], $this->master);

        for ($i = 1; $i < 6; $i++) {
            $this->postJson('/api/admin/next', [], $this->master);
        }

        return $this->question(2, 6);
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

    public function test_each_person_keeps_a_single_vote_per_round(): void
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

        $this->assertDatabaseCount('participant_votes', 2);
    }

    /**
     * Mudar de ideia faz parte da decisão: enquanto o cronômetro corre, o novo
     * voto substitui o anterior — e leva junto a pontuação da nova escolha.
     */
    public function test_a_vote_can_be_changed_while_the_round_is_open(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertCreated()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('changed', false);

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('changed', true)
            ->assertJsonPath('option_id', $this->optionWorth($question, 80))
            ->assertJsonPath('status.me.voted_option_id', $this->optionWorth($question, 80));

        // uma linha só, com a pontuação recongelada na escolha nova
        $this->assertDatabaseCount('participant_votes', 1);
        $this->assertDatabaseHas('participant_votes', [
            'participant_id' => Participant::first()->id,
            'option_id' => $this->optionWorth($question, 80),
            'points' => 80,
        ]);

        // repetir a mesma alternativa não é troca
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('changed', false);

        // e o placar segue a última escolha
        $this->assertSame(80, $this->getJson('/api/admin/overview', $this->master)
            ->json('individual_ranking.0.points'));
    }

    /** Fechada a rodada, a escolha vira definitiva — inclusive para trocar. */
    public function test_a_vote_cannot_be_changed_after_the_round_closes(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);

        $question = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana));

        $this->postJson('/api/admin/close', [], $this->master)->assertOk();

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($ana))
            ->assertStatus(409);

        $this->assertDatabaseHas('participant_votes', ['points' => 150]);
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

    /** Abre a Fase 2 na rodada de desempate, que é onde o posto é exercido. */
    protected function startBonusRound(): Question
    {
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/bonus-round', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);

        return Question::with('options')->where('is_bonus', true)->firstOrFail();
    }

    /**
     * O botão "Sou eu 🙋": o primeiro toque leva o posto, e o update condicional
     * é o que decide — não a aplicação. O segundo recebe a mesma resposta que
     * receberia se tivesse sido barrado por qualquer outro motivo.
     *
     * As duas telas saem daqui: quem assumiu vê o selo no cabeçalho, quem não
     * assumiu vê o nome de quem registra pela mesa.
     */
    public function test_the_first_to_claim_takes_the_representative_seat(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);
        $anaId = Participant::where('name', 'Ana')->value('id');

        $this->startBonusRound();

        // com o posto vazio, os dois celulares mostram o botão de assumir
        foreach ([$ana, $bruno] as $token) {
            $status = $this->getJson('/api/status', $this->auth($token))->json();
            $this->assertTrue($status['me']['can_answer']);
            $this->assertNull($status['my_table']['representative_id']);
        }

        $this->postJson('/api/claim-representative', [], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('representative_id', $anaId)
            ->assertJsonPath('status.me.is_representative', true)
            ->assertJsonPath('status.me.can_answer', true);

        // o segundo toque não desloca ninguém — e devolve o posto de quem venceu
        $this->postJson('/api/claim-representative', [], $this->auth($bruno))
            ->assertOk()
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('representative_id', $anaId)
            ->assertJsonPath('status.me.is_representative', false)
            ->assertJsonPath('status.me.can_answer', false);

        // e a tela dele passa a nomear quem registra pela mesa
        $this->getJson('/api/status', $this->auth($bruno))
            ->assertJsonPath('my_table.representative_id', $anaId)
            ->assertJsonPath('my_table.representative_name', 'Ana');

        $this->assertSame($anaId, EventTable::find(1)->representative_id);
    }

    /** O posto é de cada mesa: assumir a 1 não encosta na 2. */
    public function test_the_representative_seat_is_held_per_table(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $carla = $this->join('Carla', 2);

        $question = $this->startBonusRound();

        $this->postJson('/api/claim-representative', [], $this->auth($ana))
            ->assertJsonPath('claimed', true);

        // a mesa 2 continua com o posto livre, e quem senta nela ainda responde
        $this->getJson('/api/status', $this->auth($carla))
            ->assertJsonPath('my_table.representative_id', null)
            ->assertJsonPath('me.can_answer', true);

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($carla))
            ->assertCreated();

        // cada mesa com o seu: Carla assumiu a 2 pelo próprio voto
        $this->assertSame(
            Participant::where('name', 'Ana')->value('id'),
            EventTable::find(1)->representative_id,
        );
        $this->assertSame(
            Participant::where('name', 'Carla')->value('id'),
            EventTable::find(2)->representative_id,
        );

        // um voto por mesa, cada um na sua
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertCreated();
        $this->assertDatabaseCount('table_votes', 2);
    }

    /**
     * Na Fase 2 quem troca é o representante, pela mesa: a mesa combina, ele
     * registra, e a conversa continua até o cronômetro fechar. Uma linha só por
     * mesa, com os pontos recongelados na escolha nova.
     */
    public function test_the_representative_can_change_the_table_vote_while_the_round_is_open(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $question = $this->startBonusRound();

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 0)], $this->auth($ana))
            ->assertCreated()
            ->assertJsonPath('changed', false);

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('changed', true)
            ->assertJsonPath('status.me.voted_option_id', $this->optionWorth($question, 150));

        $this->assertDatabaseCount('table_votes', 1);
        $this->assertDatabaseHas('table_votes', [
            'event_table_id' => 1,
            'option_id' => $this->optionWorth($question, 150),
            'points' => 150,
        ]);

        // o colega enxerga a decisão da mesa como registrada, sem poder mexer
        $this->getJson('/api/status', $this->auth($bruno))
            ->assertJsonPath('my_table.has_voted', true)
            ->assertJsonPath('me.can_answer', false);

        // fechada a rodada, nem o representante troca mais
        $this->postJson('/api/admin/close', [], $this->master);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($ana))
            ->assertStatus(409);

        $this->assertDatabaseHas('table_votes', ['event_table_id' => 1, 'points' => 150]);
    }

    /**
     * O facilitador é o desempate humano do posto: troca quem registra sem
     * apagar o que já foi registrado, e devolve a mesa ao estado livre quando
     * quem assumiu saiu da sala.
     */
    public function test_the_facilitator_can_swap_the_representative_mid_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);
        $brunoId = Participant::where('name', 'Bruno')->value('id');

        $question = $this->startBonusRound();

        $this->postJson('/api/claim-representative', [], $this->auth($ana))
            ->assertJsonPath('claimed', true);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($ana))
            ->assertCreated();

        $this->postJson('/api/admin/tables/1/representative', ['participant_id' => $brunoId], $this->master)
            ->assertOk();

        // o posto troca de mãos: Bruno registra, Ana não
        $this->assertSame($brunoId, EventTable::find(1)->representative_id);
        $this->assertFalse($this->getJson('/api/status', $this->auth($ana))->json('me.can_answer'));

        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($ana))
            ->assertForbidden();

        // e Bruno corrige a decisão da mesa na mesma linha
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($bruno))
            ->assertOk()
            ->assertJsonPath('changed', true);

        $this->assertDatabaseCount('table_votes', 1);
        $this->assertDatabaseHas('table_votes', ['event_table_id' => 1, 'points' => 150]);

        // liberar o posto devolve a mesa à disputa, com o voto onde estava
        $this->postJson('/api/admin/tables/1/representative', ['participant_id' => null], $this->master)
            ->assertOk();

        $this->assertNull(EventTable::find(1)->representative_id);
        $this->assertTrue($this->getJson('/api/status', $this->auth($ana))->json('me.can_answer'));
        $this->assertDatabaseCount('table_votes', 1);
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
            // cinco cenários de mesa + a rodada final; o desempate fica fora
            ->assertJsonPath('event.total_rounds', 6)
            ->assertJsonPath('event.phase_mode', 'consensus')
            ->assertJsonPath('question.manual_scoring', false);

        // a final é o fim da fase: chega-se nela depois de rejogar os cinco
        for ($i = 1; $i < 6; $i++) {
            $this->postJson('/api/admin/next', [], $this->master);
        }

        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 6)
            ->assertJsonPath('question.manual_scoring', true);

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
        $this->goToFinalRound();
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
            ->assertJsonPath('event.total_rounds', 6);

        // "próxima rodada" atravessa os cinco cenários e para na final: o
        // desempate fica fora da contagem e só entra pelo botão do painel
        for ($i = 1; $i < 6; $i++) {
            $this->postJson('/api/admin/next', [], $this->master);
        }

        // a sexta é a última: daqui "próxima" não anda mais
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 6)
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
     * A estrutura do evento: os mesmos cinco cenários jogados duas vezes —
     * sozinho na Fase 1, em mesa na Fase 2 — e a rodada final fechando.
     *
     * A simetria não é decorativa: é o que faz o comparativo do fecho medir a
     * decisão coletiva contra a individual **na mesma pergunta**, em vez de
     * contra outra coisa.
     */
    public function test_phase_two_replays_the_same_five_scenarios_in_consensus(): void
    {
        $phaseOne = Question::with('options')->where('phase', 1)->orderBy('round')->get();
        $phaseTwo = Question::with('options')
            ->where('phase', 2)->where('is_bonus', false)->where('manual_scoring', false)
            ->orderBy('round')->get();

        $this->assertCount(5, $phaseOne);
        $this->assertCount(5, $phaseTwo);

        foreach ($phaseOne as $i => $question) {
            $mesa = $phaseTwo[$i];

            $this->assertSame(Question::MODE_INDIVIDUAL, $question->mode);
            $this->assertSame(Question::MODE_CONSENSUS, $mesa->mode);
            $this->assertFalse($question->isManual());
            $this->assertFalse($mesa->isManual());

            // mesma pergunta dos dois lados, na mesma ordem
            $this->assertSame($question->round, $mesa->round);
            $this->assertSame($question->label, $mesa->label);
            $this->assertSame($question->title, $mesa->title);
            $this->assertSame($question->context, $mesa->context);

            // a régua é a mesma em todas: uma melhor decisão (+150), uma boa
            // mas subótima (+80) e duas que não geram valor
            foreach ([$question, $mesa] as $each) {
                $points = $each->options->pluck('points');
                $this->assertCount(4, $points);
                $this->assertSame(1, $points->filter(fn ($p) => $p === 150)->count());
                $this->assertSame(1, $points->filter(fn ($p) => $p === 80)->count());
                $this->assertSame(2, $points->filter(fn ($p) => $p <= 0)->count());
            }

            // e alternativas próprias, não compartilhadas: o voto da mesa não
            // pode cair na mesma linha do voto individual
            $this->assertEmpty(
                $question->options->pluck('id')->intersect($mesa->options->pluck('id')),
            );
        }
    }

    /**
     * A Fase 2 rodada a rodada: a mesa percorre os mesmos cinco cenários, com o
     * representante registrando, e desemboca na rodada final.
     */
    public function test_the_table_walks_the_five_consensus_rounds_then_the_final(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.total_rounds', 6);

        for ($round = 1; $round <= 5; $round++) {
            $this->postJson('/api/admin/start', [], $this->master)
                ->assertOk()
                ->assertJsonPath('event.phase', 2)
                ->assertJsonPath('event.round', $round)
                ->assertJsonPath('question.manual_scoring', false);

            // uma decisão por mesa, registrada por quem assumiu o posto
            $question = $this->question(2, $round);
            $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
                ->assertCreated();
            $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, -50)], $this->auth($bruno))
                ->assertForbidden();

            $this->postJson('/api/admin/reveal', [], $this->master)->assertOk();
            $this->postJson('/api/admin/next', [], $this->master)->assertOk();
        }

        // cinco decisões de mesa, e a sexta rodada é a final
        $this->assertDatabaseCount('table_votes', 5);
        $this->getJson('/api/admin/overview', $this->master)
            ->assertJsonPath('event.round', 6)
            ->assertJsonPath('question.manual_scoring', true);

        // o posto foi assumido uma vez e valeu para as cinco
        $this->assertSame(
            Participant::where('name', 'Ana')->value('id'),
            EventTable::find(1)->representative_id,
        );

        // 5 × 150 de régua + a final lançada à mão entram no mesmo total
        $this->scoreTable(1, 120)->assertOk();
        $row = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
            ->firstWhere('table_id', 1);

        $this->assertSame(870, $row['phase_two_points']);
        $this->assertSame(6, $row['phase_two_votes']);
        // acerto conta só as cinco com régua — a final não tinha o que acertar
        $this->assertSame(5, $row['phase_two_correct']);
        $this->assertSame(100, $row['phase_two_accuracy']);
    }

    /**
     * O comparativo do fecho agora mede a mesma pergunta dos dois lados: a
     * régua da rodada final, que é do facilitador, fica fora das médias e do
     * acerto para não se misturar à régua das alternativas.
     */
    public function test_the_comparison_measures_both_phases_on_the_same_scale(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        // Fase 1: decide sozinha e escolhe a pior das cinco (−50 por decisão)
        for ($round = 1; $round <= 5; $round++) {
            $this->postJson('/api/admin/start', [], $this->master);
            $this->postJson('/api/vote', [
                'option_id' => $this->optionWorth($this->question(1, $round), -50),
            ], $this->auth($ana))->assertCreated();
            $this->postJson('/api/admin/next', [], $this->master);
        }

        $this->postJson('/api/admin/next-phase', [], $this->master)->assertOk();

        // Fase 2: a mesa acerta as cinco (150 por decisão)
        for ($round = 1; $round <= 5; $round++) {
            $this->postJson('/api/admin/start', [], $this->master);
            $this->postJson('/api/vote', [
                'option_id' => $this->optionWorth($this->question(2, $round), 150),
            ], $this->auth($ana))->assertCreated();
            $this->postJson('/api/admin/next', [], $this->master);
        }

        // e a rodada final recebe um lançamento fora de escala, de propósito
        $this->postJson('/api/admin/start', [], $this->master);
        $this->scoreTable(1, 900)->assertOk();

        $this->postJson('/api/admin/answers/reveal', [], $this->master)->assertOk();
        $comparison = $this->getJson('/api/display')->json('phase_comparison');

        // acerto existe dos dois lados: as perguntas são as mesmas
        $this->assertSame(0, $comparison['individual']['accuracy']);
        $this->assertSame(100, $comparison['table']['accuracy']);
        $this->assertSame(100, $comparison['accuracy_delta']);

        // média por decisão ignora a final: 150 − (−50), não (750+900)/6 + 50
        $this->assertSame(-50.0, (float) $comparison['individual']['average']);
        $this->assertSame(150.0, (float) $comparison['table']['average']);
        $this->assertSame(200.0, (float) $comparison['average_delta']);
        $this->assertTrue($comparison['consensus_won']);

        // mas os pontos somam tudo, inclusive a final
        $this->assertSame(1650, $comparison['table']['points']);

        // e a evolução usa a mesma medida das médias
        $row = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
            ->firstWhere('table_id', 1);
        $this->assertSame(200.0, (float) $row['evolution']);
    }

    /** A rodada final fecha a Fase 2: a sexta, sem alternativas e pontuada à mão. */
    public function test_the_final_round_closes_phase_two_without_alternatives(): void
    {
        $final = Question::with('options')->where('manual_scoring', true)->firstOrFail();

        $this->assertSame(2, $final->phase);
        $this->assertSame(6, $final->round);
        $this->assertSame(Question::MODE_CONSENSUS, $final->mode);
        $this->assertTrue($final->isManual());
        // sem alternativas: a mesa decide livremente
        $this->assertCount(0, $final->options);
        $this->assertStringContainsString('Maximizar o resultado total do hotel', $final->context);

        // e o desempate vem depois dela, fora da contagem
        $bonus = Question::where('is_bonus', true)->firstOrFail();
        $this->assertSame(2, $bonus->phase);
        $this->assertSame(7, $bonus->round);
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

    /**
     * Corrigir o cadastro na sala de espera: o nome é digitado em pé, num
     * celular, e sai torto. Enquanto ninguém votou, consertar não custa nada.
     */
    public function test_a_participant_can_fix_their_own_data_before_the_event_opens(): void
    {
        $ana = $this->join('Ana', 1);

        // o contato não trafega no poll de 1s — vem do /me, sob demanda
        $this->getJson('/api/status', $this->auth($ana))
            ->assertOk()
            ->assertJsonMissingPath('me.email');

        $this->getJson('/api/me', $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('name', 'Ana')
            ->assertJsonPath('email', 'ana@exemplo.com')
            ->assertJsonPath('hotel', 'Hotel Aurora');

        $this->postJson('/api/update-profile', [
            'name' => '  Ana Silva  ',
            'phone' => '+55 (11) 99999-9999',
            'hotel' => 'Hotel Bela Vista',
            'gender' => 'male',
            'avatar_seed' => 'a1-2-3-4',
        ], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('status.me.name', 'Ana Silva')
            ->assertJsonPath('status.me.gender', 'male')
            ->assertJsonPath('status.me.avatar_seed', 'a1-2-3-4');

        // trocou de e-mail para telefone, e o telefone entra normalizado
        $this->assertDatabaseHas('participants', [
            'name' => 'Ana Silva',
            'email' => null,
            'phone' => '11999999999',
            'hotel' => 'Hotel Bela Vista',
        ]);

        // o próprio contato não colide consigo mesmo ao salvar de novo
        $this->postJson('/api/update-profile', [
            'name' => 'Ana Silva',
            'phone' => '11999999999',
            'hotel' => 'Hotel Bela Vista',
            'gender' => 'male',
        ], $this->auth($ana))->assertOk();
    }

    /**
     * A escolha do avatar é fechada em masculino e feminino — no servidor, não
     * só no seletor. O sprite neutro continua existindo para desenhar linhas
     * antigas, mas ninguém entra nem se edita para ele.
     */
    public function test_the_avatar_style_is_limited_to_male_and_female(): void
    {
        $ana = $this->join('Ana', 1);

        foreach (['male', 'female'] as $gender) {
            $this->postJson('/api/update-profile', [
                'name' => 'Ana',
                'email' => 'ana@exemplo.com',
                'hotel' => 'Hotel Aurora',
                'gender' => $gender,
            ], $this->auth($ana))->assertOk();
        }

        foreach (['custom', 'outro'] as $gender) {
            $this->postJson('/api/update-profile', [
                'name' => 'Ana',
                'email' => 'ana@exemplo.com',
                'hotel' => 'Hotel Aurora',
                'gender' => $gender,
            ], $this->auth($ana))
                ->assertStatus(422)
                ->assertJsonValidationErrors('gender');
        }

        // e a entrada segue a mesma regra
        $this->postJson('/api/join', [
            'name' => 'Bruno',
            'email' => 'bruno@exemplo.com',
            'hotel' => 'Hotel Aurora',
            'gender' => 'custom',
            'table_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('gender');

        $this->assertSame('female', Participant::where('name', 'Ana')->value('gender'));
    }

    /** O contato de outra pessoa continua sendo dela: a colisão é recusada. */
    public function test_editing_cannot_steal_another_participants_contact(): void
    {
        $ana = $this->join('Ana', 1);
        $this->join('Bruno', 1);

        $this->postJson('/api/update-profile', [
            'name' => 'Ana',
            'email' => 'bruno@exemplo.com',
            'hotel' => 'Hotel Aurora',
            'gender' => 'female',
        ], $this->auth($ana))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('participants', ['name' => 'Ana', 'email' => 'ana@exemplo.com']);
    }

    /**
     * Aberto o evento, o cadastro congela: o nome já está no telão, o avatar já
     * é como a mesa reconhece a pessoa e a missão já foi sorteada.
     */
    public function test_the_registration_freezes_once_the_event_opens(): void
    {
        $ana = $this->join('Ana', 1);

        $this->postJson('/api/admin/open', [], $this->master)->assertOk();

        $this->postJson('/api/update-profile', [
            'name' => 'Outro Nome',
            'email' => 'ana@exemplo.com',
            'hotel' => 'Hotel Aurora',
            'gender' => 'female',
        ], $this->auth($ana))->assertStatus(409);

        $this->assertDatabaseHas('participants', ['name' => 'Ana']);

        // mas a mesa continua trocável: ali muda onde a pessoa senta, não quem ela é
        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))->assertOk();
    }

    /** Sem token de participante, nem o próprio cadastro é legível. */
    public function test_the_profile_endpoints_require_the_device_token(): void
    {
        $this->join('Ana', 1);

        $this->getJson('/api/me')->assertStatus(401);
        $this->postJson('/api/update-profile', [
            'name' => 'Ana',
            'email' => 'ana@exemplo.com',
            'hotel' => 'Hotel Aurora',
            'gender' => 'female',
        ])->assertStatus(401);
    }

    /**
     * Dez por mesa é o teto: é o tamanho que o rodízio de missões pressupõe e
     * o limite de uma conversa que precisa fechar em consenso.
     */
    public function test_a_table_stops_taking_people_at_ten(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        for ($i = 1; $i <= EventTable::MAX_PARTICIPANTS; $i++) {
            $this->join("Pessoa {$i}", 1);
        }

        // a décima primeira é recusada, e a mesa fica exatamente em dez
        $this->postJson('/api/join', [
            'name' => 'Tardia',
            'email' => 'tardia@exemplo.com',
            'hotel' => 'Hotel Aurora',
            'gender' => 'female',
            'table_id' => 1,
        ])->assertStatus(409);

        $this->assertSame(10, Participant::where('event_table_id', 1)->count());
        // e nada da pessoa recusada ficou para trás
        $this->assertDatabaseMissing('participants', ['email' => 'tardia@exemplo.com']);

        // a mesa cheia se anuncia como cheia na tela de cadastro
        $tables = collect($this->getJson('/api/bootstrap')->assertOk()->json('tables'));
        $this->assertTrue($tables->firstWhere('id', 1)['full']);
        $this->assertFalse($tables->firstWhere('id', 2)['full']);
        $this->assertSame(10, $this->getJson('/api/bootstrap')->json('max_participants'));

        // e a mesa ao lado continua recebendo
        $this->join('Tardia', 2);
        $this->assertSame(1, Participant::where('event_table_id', 2)->count());
    }

    /**
     * Trocar de mesa: o conserto de quem sentou na errada. Os votos já dados
     * ficam na mesa em que foram dados — reescrevê-los mudaria o placar de
     * duas mesas por causa de uma troca de cadeira.
     */
    public function test_a_participant_can_change_tables(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        // vota a rodada 1 sentada na mesa 1
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($ana))->assertCreated();
        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master);

        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('table_id', 2)
            ->assertJsonPath('status.my_table.id', 2);

        $this->assertSame(2, Participant::where('name', 'Ana')->value('event_table_id'));

        // o voto da rodada 1 continua na mesa 1: foi lá que a decisão aconteceu
        $this->assertDatabaseHas('participant_votes', ['event_table_id' => 1, 'points' => 150]);
        $ranking = collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'));
        $this->assertSame(150, $ranking->firstWhere('table_id', 1)['phase_one_points']);
        $this->assertSame(0, $ranking->firstWhere('table_id', 2)['phase_one_points']);

        // trocar para a mesa em que já se está não é troca
        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))
            ->assertStatus(409);
    }

    /** A mesa cheia recusa a troca pelo mesmo caminho que recusa a entrada. */
    public function test_changing_to_a_full_table_is_refused(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 2);

        for ($i = 1; $i <= EventTable::MAX_PARTICIPANTS; $i++) {
            $this->join("Pessoa {$i}", 1);
        }

        $this->postJson('/api/change-table', ['table_id' => 1], $this->auth($ana))
            ->assertStatus(409);

        $this->assertSame(2, Participant::where('name', 'Ana')->value('event_table_id'));
        $this->assertSame(10, Participant::where('event_table_id', 1)->count());
    }

    /**
     * Sair da mesa devolve o posto de representante — ele é da mesa, não da
     * pessoa. E é por isso que a troca é recusada com a votação aberta: no meio
     * da rodada, a saída de uma pessoa levaria a decisão da mesa junto.
     */
    public function test_changing_tables_releases_the_representative_seat(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $this->join('Bruno', 1);

        $this->startPhaseTwo();

        $this->postJson('/api/claim-representative', [], $this->auth($ana))
            ->assertJsonPath('claimed', true);

        // com a rodada aberta a troca é recusada
        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))
            ->assertStatus(409);

        $this->postJson('/api/admin/close', [], $this->master);

        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('status.me.is_representative', false);

        // a mesa 1 volta a poder eleger quem ficou nela
        $this->assertNull(EventTable::find(1)->representative_id);
        $this->assertSame(2, Participant::where('name', 'Ana')->value('event_table_id'));
    }

    /**
     * A missão acompanha quem já votou e é resorteada para quem não votou: o
     * rodízio é por mesa, e quem chega numa mesa nova entra pelo mesmo critério
     * de quem chega atrasado.
     */
    public function test_changing_tables_rebalances_the_mission_only_before_voting(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        // ainda não votou: a missão pode ser reequilibrada na mesa nova
        $this->postJson('/api/change-table', ['table_id' => 2], $this->auth($ana))->assertOk();
        $this->assertNotNull(Participant::where('name', 'Ana')->value('mission_id'));

        // depois de votar, a missão fica: ela está congelada em cada voto, e
        // trocá-la mudaria a pergunta que a pessoa vinha respondendo
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($ana))->assertCreated();
        $this->postJson('/api/admin/close', [], $this->master);

        $mission = Participant::where('name', 'Ana')->value('mission_id');

        $this->postJson('/api/change-table', ['table_id' => 3], $this->auth($ana))->assertOk();

        $this->assertSame($mission, Participant::where('name', 'Ana')->value('mission_id'));
    }

    /**
     * Seguir sem revelar: a rodada que a conversa já resolveu antes do telão.
     * Nada é apagado, e o voltar traz a rodada de volta — revelada, porque foi
     * jogada. É o que sustenta o botão continuar clicável fora da revelação.
     */
    public function test_the_master_can_move_on_without_revealing_the_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($ana));

        // sem passar pela revelação: a rodada 2 sobe parada, pronta para abrir
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 2)
            ->assertJsonPath('event.round_status', 'idle')
            ->assertJsonPath('event.voting_open', false);

        // o voto continua onde estava — seguir sem revelar não apaga nada
        $this->assertDatabaseCount('participant_votes', 1);

        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.round_status', 'revealed')
            ->assertJsonPath('results.total_votes', 1);
    }

    /** Pular uma rodada que nunca foi aberta é o mesmo caminho, sem voto. */
    public function test_an_unplayed_round_can_be_skipped(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 2)
            ->assertJsonPath('event.round_status', 'idle')
            ->assertJsonPath('question.round', 2);

        // e a rodada pulada continua disponível para ser aberta na volta
        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.round_status', 'idle');

        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.round_status', 'voting');
    }

    /**
     * Voltar rodada: o desfazer de um "próxima" clicado antes da hora, e o
     * caminho para rever uma rodada no telão durante a conversa.
     */
    public function test_the_master_can_step_back_a_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);

        // rodada 1 jogada e revelada, rodada 2 carregada
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($ana));
        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 2);

        // volta para a 1 — e ela volta revelada, porque já foi jogada: rever a
        // divergência da sala é a razão de voltar
        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.phase', 1)
            ->assertJsonPath('event.round_status', 'revealed')
            ->assertJsonPath('results.total_votes', 1);

        // nenhum voto foi apagado no caminho
        $this->assertDatabaseCount('participant_votes', 1);

        // na primeira rodada do evento não há para onde voltar
        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.phase', 1);
    }

    /** Rodada nunca jogada volta parada, não revelada — não há o que mostrar. */
    public function test_stepping_back_into_an_unplayed_round_leaves_it_idle(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master);

        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('event.round_status', 'idle');
    }

    /**
     * Na primeira rodada da Fase 2, "anterior" é a última da Fase 1 — o único
     * desfazer que existe para um "Ir para a Fase 2" precipitado.
     */
    public function test_stepping_back_from_the_first_round_returns_to_the_previous_phase(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2);

        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 1)
            ->assertJsonPath('event.round', 5)
            ->assertJsonPath('question.phase', 1)
            ->assertJsonPath('question.round', 5);

        // e a ida e volta é simétrica
        $this->postJson('/api/admin/next-phase', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('event.round', 1);
    }

    /** Do desempate, "anterior" é a rodada final — o bônus fica fora da conta. */
    public function test_stepping_back_from_the_bonus_round_lands_on_the_final_round(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/bonus-round', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', true);

        $this->postJson('/api/admin/previous', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('event.round', 6)
            ->assertJsonPath('question.is_bonus', false)
            ->assertJsonPath('question.manual_scoring', true);
    }

    /**
     * Recarregar a rodada: zera os votos **e** devolve o evento ao ponto de
     * abrir a votação. Zerar sem voltar o estado deixava o telão exibindo uma
     * distribuição vazia quando a rodada já tinha sido revelada.
     */
    public function test_reloading_a_round_clears_its_votes_and_reopens_it(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);
        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($token));
        $this->postJson('/api/admin/reveal', [], $this->master);

        $this->assertDatabaseCount('participant_votes', 1);

        $this->postJson('/api/admin/reset-round', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round_status', 'idle')
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('results', null);

        $this->assertDatabaseCount('participant_votes', 0);

        // e a rodada roda de novo, do início
        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.round_status', 'voting')
            ->assertJsonPath('event.round', 1)
            ->assertJsonPath('timer.duration', 60);

        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 80),
        ], $this->auth($token))->assertCreated();

        $this->assertDatabaseCount('participant_votes', 1);
    }

    /** Recarregar uma rodada não encosta nas outras. */
    public function test_reloading_a_round_leaves_the_other_rounds_alone(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);

        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 1), 150),
        ], $this->auth($token));
        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master);

        $this->postJson('/api/admin/start', [], $this->master);
        $this->postJson('/api/vote', [
            'option_id' => $this->optionWorth($this->question(1, 2), 80),
        ], $this->auth($token));

        $this->postJson('/api/admin/reset-round', [], $this->master)->assertOk();

        // o voto da rodada 2 se foi, o da 1 continua no placar
        $this->assertDatabaseCount('participant_votes', 1);
        $this->assertSame(150, $this->getJson('/api/admin/overview', $this->master)
            ->json('individual_ranking.0.points'));
    }

    public function test_join_requires_a_contact_and_the_hotel(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        // sem contato nenhum: os dois campos reclamam, porque um deles resolve
        $this->postJson('/api/join', ['name' => 'Ana', 'hotel' => 'Aurora', 'gender' => 'female', 'table_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['email', 'phone']);

        // com contato, mas sem hotel
        $this->postJson('/api/join', [
            'name' => 'Ana', 'email' => 'ana@exemplo.com', 'gender' => 'female', 'table_id' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors('hotel');

        $this->postJson('/api/join', [
            'name' => 'Ana', 'email' => 'ana@exemplo.com', 'hotel' => 'Aurora',
            'gender' => 'female', 'table_id' => 1,
        ])->assertCreated();

        // e-mail repetido, mesmo com outra caixa, é a mesma pessoa
        $this->postJson('/api/join', [
            'name' => 'Ana 2', 'email' => 'ANA@exemplo.com', 'hotel' => 'Aurora',
            'gender' => 'female', 'table_id' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseHas('participants', [
            'email' => 'ana@exemplo.com',
            'phone' => null,
            'hotel' => 'Aurora',
        ]);
    }

    /**
     * Quem trabalha na operação nem sempre tem e-mail à mão. O telefone entra
     * como dígitos — "(11) 99999-9999" e "+55 11 99999-9999" são a mesma
     * pessoa, e o índice único precisa enxergar isso.
     */
    public function test_join_accepts_a_phone_instead_of_an_email(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);

        $this->postJson('/api/join', [
            'name' => 'Bruno', 'phone' => '(11) 98888-7777', 'hotel' => 'Pousada do Porto',
            'gender' => 'male', 'table_id' => 1,
        ])->assertCreated();

        $this->assertDatabaseHas('participants', [
            'name' => 'Bruno',
            'email' => null,
            'phone' => '11988887777',
            'hotel' => 'Pousada do Porto',
        ]);

        // o mesmo número com código de país não pode virar uma segunda pessoa
        $this->postJson('/api/join', [
            'name' => 'Bruno de novo', 'phone' => '+55 (11) 98888-7777', 'hotel' => 'Outro',
            'gender' => 'male', 'table_id' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        // número curto demais não passa
        $this->postJson('/api/join', [
            'name' => 'Curto', 'phone' => '98888', 'hotel' => 'Outro',
            'gender' => 'male', 'table_id' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors('phone');

        // e quem entrou por telefone não ocupa o índice de e-mail de ninguém —
        // nem com o campo do outro contato indo em branco
        $this->postJson('/api/join', [
            'name' => 'Carla', 'email' => '', 'phone' => '11977776666', 'hotel' => 'Grand Plaza',
            'gender' => 'female', 'table_id' => 1,
        ])->assertCreated();

        $this->assertDatabaseCount('participants', 2);
        $this->assertDatabaseHas('participants', ['name' => 'Carla', 'email' => null]);
    }

    public function test_participant_contacts_never_leak_to_other_screens(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $token = $this->join('Ana', 1);

        $this->postJson('/api/join', [
            'name' => 'Bruno', 'phone' => '11988887777', 'hotel' => 'Pousada do Porto',
            'gender' => 'male', 'table_id' => 1,
        ])->assertCreated();

        foreach (['/api/display', '/api/status'] as $endpoint) {
            $body = $this->getJson($endpoint, $this->auth($token))->getContent();

            $this->assertStringNotContainsString('@', $body);
            $this->assertStringNotContainsString('11988887777', $body);
            // o hotel é do painel do facilitador, não do telão
            $this->assertStringNotContainsString('Pousada do Porto', $body);
        }

        // o painel lista o hotel — é como o facilitador identifica quem é quem
        // numa sala de 150 —, mas o contato não trafega no poll de 1s: ele vem
        // na gaveta da mesa, buscada sob demanda
        $overview = $this->getJson('/api/admin/overview', $this->master)->getContent();
        $this->assertStringContainsString('Pousada do Porto', $overview);
        $this->assertStringNotContainsString('11988887777', $overview);

        $drawer = $this->getJson('/api/admin/tables/1', $this->master)->json('participants');
        $this->assertSame('11988887777', collect($drawer)->firstWhere('name', 'Bruno')['contact']);
        $this->assertSame('Pousada do Porto', collect($drawer)->firstWhere('name', 'Bruno')['hotel']);
    }

    /** O hotel acompanha a pessoa nas duas listagens do painel. */
    public function test_the_master_listings_carry_the_hotel(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->join('Ana', 1);

        $overview = $this->getJson('/api/admin/overview', $this->master)->json();

        // a listagem por mesa (a gaveta do mapa)
        $person = collect($overview['tables'])->firstWhere('id', 1)['participants'][0];
        $this->assertSame('Hotel Aurora', $person['hotel']);

        // e o ranking individual
        $this->assertSame('Hotel Aurora', $overview['individual_ranking'][0]['hotel']);

        // no telão, nenhuma das duas
        $this->postJson('/api/admin/end', [], $this->master);
        $display = $this->getJson('/api/display')->json();

        $this->assertArrayNotHasKey('hotel', $display['tables'][0]['participants'][0]);
        $this->assertArrayNotHasKey('hotel', $display['individual_ranking'][0]);
    }

    /**
     * O bloqueio é de vitrine: tira a pessoa do pódio individual e deixa tudo
     * o mais como estava — inclusive os pontos que ela gerou para a mesa.
     */
    public function test_blocking_a_participant_only_removes_them_from_the_individual_ranking(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $this->postJson('/api/admin/start', [], $this->master);
        $question = $this->question(1, 1);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana));
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 80)], $this->auth($bruno));

        $anaId = Participant::where('name', 'Ana')->value('id');

        $this->postJson("/api/admin/participants/{$anaId}/block", ['blocked' => true], $this->master)
            ->assertOk();

        $overview = $this->getJson('/api/admin/overview', $this->master)->json();
        $people = collect($overview['individual_ranking'])->keyBy('name');

        // o painel ainda vê a pessoa — é de lá que se desbloqueia —, mas fora
        // da numeração de quem está no páreo
        $this->assertTrue($people['Ana']['blocked']);
        $this->assertNull($people['Ana']['position']);
        $this->assertFalse($people['Bruno']['blocked']);
        $this->assertSame(1, $people['Bruno']['position']);

        // o voto dela continua somando para a mesa e para o grupo de missão
        $table = collect($overview['table_ranking'])->firstWhere('table_id', 1);
        $this->assertSame(230, $table['phase_one_points']);
        $this->assertSame(2, $table['phase_one_votes']);
        $this->assertSame(230, collect($overview['mission_ranking'])->sum('points'));

        // e o celular dela não muda de comportamento: segue votando na Fase 1,
        // e o voto novo segue contando como o de todo mundo
        $this->assertTrue($this->getJson('/api/status', $this->auth($ana))->json('me.has_voted'));

        $this->postJson('/api/admin/reveal', [], $this->master);
        $this->postJson('/api/admin/next', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);

        $second = $this->question(1, 2);
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($second, 150)], $this->auth($ana))
            ->assertCreated()
            ->assertJsonPath('accepted', true);

        $this->assertSame(
            380,
            collect($this->getJson('/api/admin/overview', $this->master)->json('table_ranking'))
                ->firstWhere('table_id', 1)['phase_one_points'],
        );

        // no telão, o pódio não a conhece
        $this->postJson('/api/admin/end', [], $this->master);
        $public = collect($this->getJson('/api/display')->json('individual_ranking'));

        $this->assertSame(['Bruno'], $public->pluck('name')->all());

        // liberar devolve a pessoa ao ranking
        $this->postJson("/api/admin/participants/{$anaId}/block", ['blocked' => false], $this->master)
            ->assertOk();

        $this->assertCount(2, $this->getJson('/api/display')->json('individual_ranking'));
    }

    /**
     * O bloqueado não representa a mesa — e não descobre isso pela tela.
     *
     * O posto é a única forma de uma pessoa aparecer *falando pela mesa*, que é
     * o que o bloqueio existe para evitar. Do celular dele, tudo parece igual
     * ao de um colega que não assumiu: mesma tela, mesma resposta da API,
     * mesma mensagem de erro.
     */
    public function test_a_blocked_participant_cannot_become_the_table_representative(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $ana = $this->join('Ana', 1);
        $bruno = $this->join('Bruno', 1);

        $anaId = Participant::where('name', 'Ana')->value('id');
        $this->postJson("/api/admin/participants/{$anaId}/block", ['blocked' => true], $this->master);

        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/bonus-round', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master);

        // a tela dela é a de quem não registra pela mesa — a mesma de qualquer
        // colega depois que outra pessoa assumiu
        $this->assertFalse($this->getJson('/api/status', $this->auth($ana))->json('me.can_answer'));

        // e o botão, se ela chegar nele, responde como quem chegou em segundo
        $this->postJson('/api/claim-representative', [], $this->auth($ana))
            ->assertOk()
            ->assertJsonPath('claimed', false)
            ->assertJsonPath('representative_id', null);

        $this->assertNull(EventTable::find(1)->representative_id);

        $question = Question::with('options')->where('is_bonus', true)->firstOrFail();

        // votar direto também não a elege — e a mensagem é a de sempre
        $this->postJson('/api/vote', ['option_id' => $this->optionWorth($question, 150)], $this->auth($ana))
            ->assertForbidden()
            ->assertJsonPath('message', 'Apenas o representante da mesa responde nesta fase.');

        $this->assertNull(EventTable::find(1)->representative_id);
        $this->assertDatabaseCount('table_votes', 0);

        // o posto continua livre para quem não está bloqueado
        $this->postJson('/api/claim-representative', [], $this->auth($bruno))
            ->assertOk()
            ->assertJsonPath('claimed', true);

        // e o facilitador não consegue nomeá-la nem pelo painel
        $this->postJson('/api/admin/tables/1/representative', ['participant_id' => $anaId], $this->master)
            ->assertStatus(422)
            ->assertJsonValidationErrors('participant_id');
    }

    /** Bloquear quem já era representante devolve o posto para a mesa. */
    public function test_blocking_the_current_representative_releases_the_seat(): void
    {
        $this->postJson('/api/admin/open', [], $this->master);
        $this->join('Ana', 1);
        $this->join('Bruno', 1);

        $anaId = Participant::where('name', 'Ana')->value('id');

        $this->postJson('/api/admin/tables/1/representative', ['participant_id' => $anaId], $this->master)
            ->assertOk();
        $this->assertSame($anaId, EventTable::find(1)->representative_id);

        $this->postJson("/api/admin/participants/{$anaId}/block", ['blocked' => true], $this->master)
            ->assertOk();

        $this->assertNull(EventTable::find(1)->representative_id);
    }

    /**
     * Um relógio por fase: 60s para decidir sozinho, 120s para a mesa conversar
     * antes de decidir. O do evento parado acompanha a Fase 1, que é onde ele
     * aparece — antes do primeiro "abrir votação".
     */
    public function test_each_phase_has_its_own_round_clock(): void
    {
        foreach (Question::where('phase', 1)->get() as $question) {
            $this->assertSame(60, $question->duration);
        }

        // as duas da Fase 2 — a rodada final e o desempate — no mesmo relógio
        foreach (Question::where('phase', 2)->get() as $question) {
            $this->assertSame(120, $question->duration);
        }

        $this->postJson('/api/admin/open', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('timer.duration', 60);

        $this->postJson('/api/admin/next-phase', [], $this->master);
        $this->postJson('/api/admin/start', [], $this->master)
            ->assertOk()
            ->assertJsonPath('event.phase', 2)
            ->assertJsonPath('timer.duration', 120);
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
