<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\RelatorioEstabelecimentoController;
use App\Models\Processo;
use App\Models\UsuarioInterno;
use App\Services\ProcessoLinhaTempoService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Diagnóstico (somente leitura): compara os processos com documentação incompleta da
 * tela de Processos (/admin/processos?quick=nao_enviado) com a etapa "Doc. incompleta"
 * do relatório de estabelecimentos, explicando o motivo de cada diferença.
 */
class CompararIncompletosLicenciamento extends Command
{
    protected $signature = 'relatorios:comparar-incompletos
                            {--ano= : Ano de referência (padrão: ano atual)}
                            {--tipo=licenciamento : Tipo de processo}';

    protected $description = 'Compara "Incompletos" da tela de Processos com "Doc. incompleta" do relatório de estabelecimentos';

    public function handle(ProcessoLinhaTempoService $linhaTempo): int
    {
        $ano = (int) ($this->option('ano') ?: now()->year);
        $tipo = (string) $this->option('tipo');

        $admin = UsuarioInterno::where('nivel_acesso', 'administrador')->first();
        auth('interno')->setUser($admin);

        // 1) Relatório (mesmo código da tela, filtro "Aprovados e ativos")
        $this->info('Calculando o relatório de estabelecimentos (pode levar alguns minutos)...');
        $controller = app(RelatorioEstabelecimentoController::class);
        $chamar = function (string $metodo, ...$args) use ($controller) {
            $r = new \ReflectionMethod($controller, $metodo);
            $r->setAccessible(true);
            return $r->invoke($controller, ...$args);
        };
        $filtros = $chamar('filtros', Request::create('/', 'GET', ['tipo' => $tipo, 'ano' => $ano, 'status_estabelecimento' => 'aprovado']), $admin);
        $tipos = $chamar('tiposFoco', $chamar('tiposControlados'), $filtros);
        $linhas = $chamar('montarLinhas', $admin, $filtros, $tipos);
        $relatorio = $linhas->where('etapa', 'doc_incompleta')
            ->map(fn ($l) => $l['demandas'][$tipo]['processo']->id)
            ->values();

        // 2) Tela de Processos (tipo + ano + aberto/parado, checklist incompleto)
        $this->info('Calculando a tela de processos...');
        $processos = Processo::with(['estabelecimento', 'documentos', 'pastas', 'unidades', 'tipoProcesso'])
            ->where('tipo', $tipo)->where('ano', $ano)->whereIn('status', ['aberto', 'parado'])
            ->get();
        $tela = $processos->filter(function ($p) {
            $obrigatorios = $p->getDocumentosObrigatoriosChecklist()->where('obrigatorio', true);
            return $obrigatorios->isNotEmpty() && !$obrigatorios->every(fn ($d) => $d['status'] === 'aprovado');
        })->pluck('id')->values();

        $alvaras = $linhaTempo->alvarasPorProcesso($tela);
        $porEstabelecimento = $linhas->keyBy(fn ($l) => $l['estabelecimento']->id);

        $this->newLine();
        $this->table(
            ['Tela de Processos (aberto + parado)', 'Relatório "Doc. incompleta"', 'Em comum'],
            [[$tela->count(), $relatorio->count(), $tela->intersect($relatorio)->count()]]
        );

        // Só na tela de Processos
        $soTela = [];
        foreach ($processos->whereIn('id', $tela->diff($relatorio)) as $p) {
            $e = $p->estabelecimento;
            $linha = $e ? $porEstabelecimento->get($e->id) : null;
            $usado = $linha['demandas'][$tipo]['processo'] ?? null;

            $motivo = match (true) {
                !$e => 'Sem estabelecimento',
                $alvaras->has($p->id) => 'Tem alvará: no relatório está em "Com alvará sanitário"',
                $e->status !== 'aprovado' || !$e->ativo => "Cadastro não aprovado/ativo (status={$e->status})",
                !$linha => 'Fora do relatório: sem atividade que exige este processo, descentralização ou fora do escopo',
                $usado && $usado->id !== $p->id => "Estabelecimento tem outro processo; o relatório usa {$usado->numero_processo}",
                default => 'No relatório está na etapa: ' . ($linha['etapa_label'] ?? $linha['etapa'] ?? '?'),
            };
            $soTela[] = [$p->numero_processo, $p->status, mb_strimwidth($e->nome_fantasia ?? $e->razao_social ?? '-', 0, 40, '…'), $motivo];
        }

        $this->newLine();
        $this->line('<comment>Só na tela de Processos (' . count($soTela) . '):</comment>');
        $soTela ? $this->table(['Processo', 'Status', 'Estabelecimento', 'Motivo'], $soTela) : $this->line('  nenhum');

        // Só no relatório
        $soRelatorio = Processo::with('estabelecimento')->whereIn('id', $relatorio->diff($tela))->get()
            ->map(fn ($p) => [
                $p->numero_processo,
                $p->ano,
                $p->status,
                mb_strimwidth($p->estabelecimento->nome_fantasia ?? '-', 0, 40, '…'),
                $p->ano != $ano
                    ? "Processo de {$p->ano}: o tipo é único por estabelecimento, o relatório usa o processo de qualquer ano"
                    : (!in_array($p->status, ['aberto', 'parado'], true) ? "Status {$p->status} não entra na tela de Processos" : 'Verificar'),
            ])->all();

        $this->newLine();
        $this->line('<comment>Só no relatório (' . count($soRelatorio) . '):</comment>');
        $soRelatorio ? $this->table(['Processo', 'Ano', 'Status', 'Estabelecimento', 'Motivo'], $soRelatorio) : $this->line('  nenhum');

        return self::SUCCESS;
    }
}
