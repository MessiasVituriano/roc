<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O evento passa a ter mais perguntas cadastradas do que joga.
 *
 * Três colunas sustentam isso:
 *
 * - **`active`** — se a pergunta entra no roteiro. As inativas continuam no
 *   banco com seus votos, se já foram jogadas; só param de ser alcançadas por
 *   "próxima rodada". As ativas são renumeradas para ficarem contíguas em
 *   `round`, que é como o resto do sistema anda pelas rodadas.
 *
 * - **`scenario_key`** — o mesmo cenário na Fase 1 e na Fase 2 carrega a mesma
 *   chave. É o que permite ligar e desligar **o cenário**, não a pergunta: o
 *   comparativo do fecho compara a mesma pergunta dos dois lados, e deixar um
 *   lado ativo sem o outro o quebraria em silêncio.
 *
 * - **`source`** — de qual conjunto a pergunta veio, para a tela de seleção
 *   agrupar em vez de listar catorze linhas soltas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('manual_scoring');
            $table->string('scenario_key')->nullable()->after('label');
            $table->string('source')->nullable()->after('scenario_key');
        });

        // As alternativas do conjunto novo passam de 255 caracteres — algumas
        // são um parágrafo de negociação inteiro. O varchar cabia no conteúdo
        // antigo e estourava no novo, no meio do seed.
        Schema::table('options', function (Blueprint $table) {
            $table->text('text')->change();
        });

        // o que já existe continua no ar e ganha identidade de conjunto
        DB::table('questions')->update(['source' => 'roc-original', 'active' => true]);

        // o par Fase 1 / Fase 2 de cada cenário se reconhece pela rodada, que é
        // como o espelho foi montado
        foreach (DB::table('questions')->where('is_bonus', false)->where('manual_scoring', false)->get() as $q) {
            DB::table('questions')
                ->where('id', $q->id)
                ->update(['scenario_key' => 'original-'.$q->round]);
        }
    }

    public function down(): void
    {
        Schema::table('options', function (Blueprint $table) {
            $table->string('text')->change();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn(['active', 'scenario_key', 'source']);
        });
    }
};
