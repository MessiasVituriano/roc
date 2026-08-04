<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O cadastro passa a aceitar **e-mail ou telefone** — quem trabalha na recepção
 * nem sempre tem e-mail corporativo à mão, e travar a entrada por isso custa
 * gente na sala. A pessoa escolhe o tipo e preenche um só.
 *
 * `email` vira nulo permitido e ganha um irmão `phone`. Os dois mantêm índice
 * único por evento: em Postgres (e em SQLite) nulos não colidem entre si, então
 * um índice por coluna resolve — quem entrou por telefone não disputa o índice
 * de e-mail.
 *
 * `hotel` é obrigatório na aplicação, mas nulo no banco: as linhas que já
 * existem entraram antes desta regra e não têm o dado para preencher.
 */
return new class extends Migration
{
    public function up(): void
    {
        // o índice sai antes da alteração da coluna: em SQLite a mudança
        // recria a tabela, e recriar com o índice ainda apontando para a
        // coluna antiga é o caminho mais curto para uma migração quebrada
        Schema::table('participants', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'email']);
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable();
            $table->string('hotel', 120)->nullable();
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->unique(['event_id', 'email']);
            $table->unique(['event_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropUnique(['event_id', 'email']);
            $table->dropUnique(['event_id', 'phone']);
        });

        // quem entrou só com telefone não cabe numa coluna de e-mail obrigatória
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['phone', 'hotel']);
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->unique(['event_id', 'email']);
        });
    }
};
