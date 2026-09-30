<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Última verificação dos CNAEs do CNPJ na Receita, por estabelecimento.
     * Quando status = 'divergente', vira alerta no dashboard (atividade marcada
     * que saiu do CNPJ ou CNAE novo no CNPJ).
     */
    public function up(): void
    {
        Schema::create('estabelecimento_verificacoes_cnae', function (Blueprint $table) {
            $table->id();
            $table->foreignId('estabelecimento_id')->unique()->constrained('estabelecimentos')->cascadeOnDelete();
            $table->string('status', 20)->default('ok'); // ok | divergente | erro

            // CNAEs do CNPJ na última consulta e a base já revisada (para detectar CNAEs novos)
            $table->json('cnaes_receita')->nullable();
            $table->json('cnaes_base')->nullable();
            // CNAEs que a equipe manteve marcados mesmo fora do CNPJ (não alertar de novo)
            $table->json('cnaes_ignorados')->nullable();

            // Divergências encontradas: [{codigo, descricao, competencia}]
            $table->json('cnaes_removidos')->nullable();
            $table->json('cnaes_novos')->nullable();

            $table->string('competencia_atual', 20)->nullable();
            $table->string('competencia_sugerida', 20)->nullable();
            $table->boolean('altera_competencia')->default(false);
            $table->boolean('novo_cnae_estadual')->default(false);

            $table->string('fonte', 40)->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('verificado_em')->nullable()->index();
            $table->timestamp('detectado_em')->nullable();
            $table->timestamp('revisado_em')->nullable();
            $table->foreignId('revisado_por')->nullable()->constrained('usuarios_internos')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estabelecimento_verificacoes_cnae');
    }
};
