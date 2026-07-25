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
        }

        $status = $this->getJson('/api/status', $this->auth($token))->json();
        foreach ($status['question']['options'] as $option) {
            $this->assertArrayNotHasKey('points', $option);
        }
        $this->assertArrayNotHasKey('bias_note', $display['question']);

        $this->postJson('/api/admin/reveal', [], $this->master)->assertOk();

        $display = $this->getJson('/api/display')->json();
        $this->assertNotNull($display['results']);
        $this->assertArrayHasKey('points', $display['results']['options'][0]);
        $this->assertArrayHasKey('effect', $display['results']['options'][0]);
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

        // a fase 2 tem uma rodada só; "próxima rodada" não pode cair no desempate
        $this->postJson('/api/admin/next', [], $this->master)
            ->assertOk()
            ->assertJsonPath('question.is_bonus', false)
            ->assertJsonPath('event.round', 1);
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
