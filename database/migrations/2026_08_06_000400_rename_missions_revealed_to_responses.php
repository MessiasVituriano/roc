<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O clique da virada de fase deixa de revelar o placar por missão e passa a
 * revelar **como a sala respondeu**: cada alternativa com o percentual que a
 * escolheu.
 *
 * O placar por missão continua existindo — no painel, para o facilitador ler o
 * viés e narrar. O que saiu do telão foi ele: entre as duas fases, o que a sala
 * quer ver é onde ela mesma se dividiu, não uma média por grupo.
 *
 * A coluna é renomeada porque o nome passaria a mentir, e um `missions_revealed`
 * que revela respostas é o tipo de pegadinha que se descobre tarde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('missions_revealed', 'responses_revealed');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->renameColumn('responses_revealed', 'missions_revealed');
        });
    }
};
