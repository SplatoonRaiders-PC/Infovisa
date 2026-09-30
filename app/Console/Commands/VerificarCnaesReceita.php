<?php

namespace App\Console\Commands;

use App\Models\Estabelecimento;
use App\Services\CnpjService;
use App\Services\VerificacaoCnaeService;
use Illuminate\Console\Command;

/**
 * Verifica, em lotes, se os CNAEs do CNPJ na Receita mudaram em relação às
 * atividades marcadas nos estabelecimentos. Divergências viram alerta no dashboard.
 *
 * Processa primeiro os nunca verificados e depois os verificados há mais tempo.
 * Usa primeiro as fontes com dados mais atualizados (Publica CNPJ WS, ReceitaWS),
 * que têm cota gratuita por minuto; por padrão espera a cota liberar em vez de
 * usar a base mensal (BrasilAPI/Minha Receita). Com --rapido não espera.
 */
class VerificarCnaesReceita extends Command
{
    protected $signature = 'estabelecimentos:verificar-cnaes
                            {--limite=200 : Quantidade máxima de estabelecimentos nesta execução}
                            {--rapido : Não espera a cota das fontes atualizadas (usa BrasilAPI/Minha Receita quando acabar)}
                            {--id=* : Verifica somente os IDs informados}';

    protected $description = 'Compara os CNAEs do CNPJ (Receita) com as atividades dos estabelecimentos e gera alertas de divergência';

    public function handle(VerificacaoCnaeService $servico, CnpjService $cnpjService): int
    {
        $ids = array_filter(array_map('intval', (array) $this->option('id')));
        $limite = max(1, (int) $this->option('limite'));
        $rapido = (bool) $this->option('rapido');

        $query = Estabelecimento::query()
            ->select('estabelecimentos.*')
            ->where('estabelecimentos.tipo_pessoa', 'juridica')
            ->whereNotNull('estabelecimentos.cnpj')
            ->where(fn ($q) => $q->whereNull('estabelecimentos.status')->orWhere('estabelecimentos.status', '!=', 'rejeitado'));

        if ($ids) {
            $query->whereIn('estabelecimentos.id', $ids);
        } else {
            // Nunca verificados primeiro, depois os mais antigos
            $query->leftJoin('estabelecimento_verificacoes_cnae as v', 'v.estabelecimento_id', '=', 'estabelecimentos.id')
                ->orderByRaw('v.verificado_em IS NOT NULL, v.verificado_em ASC, estabelecimentos.id ASC')
                ->limit($limite);
        }

        $estabelecimentos = $query->get();
        $contagem = ['ok' => 0, 'divergente' => 0, 'erro' => 0, 'muda_competencia' => 0];
        $porFonte = [];

        $this->info("Verificando {$estabelecimentos->count()} estabelecimento(s)" . ($rapido ? ' (modo rápido)' : ' (priorizando fontes atualizadas)') . '...');
        $barra = $this->output->createProgressBar($estabelecimentos->count());

        foreach ($estabelecimentos as $estabelecimento) {
            // Espera a cota das fontes atualizadas liberar (no máximo ~1 min)
            if (!$rapido && $cnpjService->consultasAtualizadasDisponiveis() === 0) {
                sleep(min(61, max(1, $cnpjService->segundosAteProximaConsultaAtualizada())));
            }

            try {
                $verificacao = $servico->verificar($estabelecimento);
                $chave = $verificacao->erro ? 'erro' : $verificacao->status;
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
                if (!$verificacao->erro) {
                    $porFonte[$verificacao->fonte ?? '?'] = ($porFonte[$verificacao->fonte ?? '?'] ?? 0) + 1;
                }
                if ($verificacao->status === 'divergente' && $verificacao->altera_competencia) {
                    $contagem['muda_competencia']++;
                }
            } catch (\Throwable $e) {
                $contagem['erro']++;
                report($e);
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine(2);
        $this->table(
            ['Sem divergência', 'Com divergência', 'Mudam competência', 'Falha na consulta'],
            [[$contagem['ok'], $contagem['divergente'], $contagem['muda_competencia'], $contagem['erro']]]
        );

        if ($porFonte) {
            $this->line('Fontes usadas: ' . collect($porFonte)->map(fn ($n, $f) => "{$f}: {$n}")->implode(' | '));
        }

        return self::SUCCESS;
    }
}
