<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // draft | open | running | finished
            $table->string('status')->default('draft');
            // 1 = individual, 2 = consenso da mesa — as mesmas 5 rodadas nas duas
            $table->unsignedTinyInteger('phase')->default(1);
            $table->unsignedTinyInteger('current_round')->default(1);
            $table->foreignId('current_question_id')->nullable();
            // idle | voting | revealed — a revelação é sempre um clique do facilitador
            $table->string('round_status')->default('idle');
            $table->timestamp('round_started_at')->nullable();
            $table->timestamp('round_ends_at')->nullable();
            $table->unsignedSmallInteger('round_duration')->default(20);
            // a virada de fase: quando o placar por grupo de missão vai ao telão
            $table->boolean('missions_revealed')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // As 4 missões situacionais sorteadas no início da Fase 1.
        Schema::create('missions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->text('statement');
            $table->string('icon')->default('🎯');
            $table->string('color')->default('#c9922e');
            $table->timestamps();

            $table->unique(['event_id', 'key']);
        });

        // "mesas" — named event_tables to avoid clashing with the SQL notion of tables
        Schema::create('event_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->default('🚀');
            $table->string('color')->default('#6366f1');
            $table->float('position_x')->default(50);
            $table->float('position_y')->default(50);
            // fase 2: quem registra o consenso pela mesa
            $table->unsignedBigInteger('representative_id')->nullable();
            $table->timestamps();

            $table->index(['event_id']);
        });

        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_table_id')->constrained('event_tables')->cascadeOnDelete();
            $table->foreignId('mission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('email');
            // O cadastro escolhe entre male | female — só seleciona o sprite.
            // O default `custom` é o sprite neutro, para a linha criada fora do
            // cadastro: ele desenha, mas não é escolhível.
            $table->string('gender')->default('custom');
            $table->string('avatar_seed');
            $table->string('device_token', 64)->unique();
            $table->boolean('connected')->default(true);
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'email']);
            $table->index(['event_table_id']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('phase')->default(1);
            $table->unsignedTinyInteger('round')->default(1);
            // individual | consensus
            $table->string('mode')->default('individual');
            $table->string('label')->nullable();     // "TARIFA & OCUPAÇÃO"
            $table->string('title');
            $table->text('context')->nullable();     // o cenário do hotel
            $table->text('bias_note')->nullable();   // viés esperado, só para o facilitador
            $table->unsignedSmallInteger('duration')->default(20);
            // pergunta de desempate, fora da contagem normal de rodadas
            $table->boolean('is_bonus')->default(false);
            $table->timestamps();

            $table->unique(['event_id', 'phase', 'round']);
        });

        Schema::create('options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->string('text');
            $table->text('effect')->nullable();       // "Efeitos da decisão"
            // régua fixa da dinâmica: +150 (melhor), +80, 0, -50
            $table->smallInteger('points')->default(0);
            $table->string('color')->nullable();
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->index(['question_id', 'order']);
        });

        // Fase 1: uma linha por pessoa por rodada.
        Schema::create('participant_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_table_id')->constrained('event_tables')->cascadeOnDelete();
            $table->foreignId('mission_id')->nullable()->constrained()->nullOnDelete();
            // congelado no voto, para o placar não mudar se a régua for editada
            $table->smallInteger('points')->default(0);
            $table->timestamps();

            $table->unique(['participant_id', 'question_id']);
            $table->index(['question_id', 'mission_id']);
        });

        // Fase 2: uma linha por mesa por rodada.
        Schema::create('table_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_table_id')->constrained('event_tables')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            $table->smallInteger('points')->default(0);
            $table->timestamps();

            $table->unique(['event_table_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_votes');
        Schema::dropIfExists('participant_votes');
        Schema::dropIfExists('options');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('event_tables');
        Schema::dropIfExists('missions');
        Schema::dropIfExists('events');
    }
};
