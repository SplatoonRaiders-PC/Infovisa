<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Models\Municipio;
use App\Models\Processo;
use App\Models\TipoDocumento;
use App\Models\TipoProcesso;
use App\Models\UsuarioInterno;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Relatório de controle de estabelecimentos x processos.
 *
 * Para cada estabelecimento, identifica quais processos ele DEVERIA ter
 * (pelas atividades exercidas) e se já abriu:
 *  - CNAE comum             → Licenciamento (anual: verificado no ano de referência)
 *  - Atividade PROJ_ARQ     → Projeto Arquitetônico
 *  - Atividade ANAL_ROT     → Análise de Rotulagem
 */
class RelatorioEstabelecimentoController extends Controller
{
    private const ATIVIDADES_ESPECIAIS = [
        'PROJ_ARQ' => 'projeto_arquitetonico',
        'ANAL_ROT' => 'analise_rotulagem',
    ];

    private const TIPOS_CONTROLADOS = ['licenciamento', 'projeto_arquitetonico', 'analise_rotulagem'];

    private const STATUS_INATIVOS = ['arquivado', 'concluido', 'aprovado', 'indeferido'];

    /** IDs (como chaves) dos processos arquivados que têm Alvará Sanitário assinado */
    private ?Collection $arquivadosComAlvara = null;

    /** Cadastros do escopo sem nenhuma atividade marcada (calculado em montarLinhas). */
    private int $totalSemAtividade = 0;

    public function index(Request $request)
    {
        $usuario = auth('interno')->user();
        $filtros = $this->filtros($request, $usuario);
        $tipos = $this->tiposControlados();
        $tiposFoco = $this->tiposFoco($tipos, $filtros);

        $linhas = $this->montarLinhas($usuario, $filtros, $tiposFoco);
        $linhasFiltradas = $this->aplicarFiltrosLinhas($linhas, $filtros);

        $indicadores = $this->indicadores($linhas, $tiposFoco, $filtros);
        $graficos = $this->graficos($linhas, $tiposFoco, $filtros, $usuario);

        $estabelecimentos = $this->paginar($linhasFiltradas, 20, $request);

        $municipios = ($usuario->isAdmin() || $usuario->isEstadual())
            ? Municipio::query()->orderBy('nome')->get(['id', 'nome'])
            : collect();

        $anos = Processo::query()->select('ano')->whereNotNull('ano')->distinct()->orderByDesc('ano')->pluck('ano')
            ->push((int) now()->year)->unique()->sortDesc()->values();

        $escopoVisual = $usuario->isAdmin()
            ? 'Todos os municípios e competências'
            : ($usuario->isMunicipal() ? 'Seu município · competência municipal' : 'Competência estadual');

        return view('admin.relatorios.estabelecimentos', compact(
            'estabelecimentos', 'indicadores', 'graficos', 'filtros', 'tipos',
            'municipios', 'anos', 'escopoVisual'
        ) + ['totalFiltrado' => $linhasFiltradas->count(), 'totalSemAtividade' => $this->totalSemAtividade]);
    }

    public function export(Request $request): StreamedResponse
    {
        $usuario = auth('interno')->user();
        $filtros = $this->filtros($request, $usuario);
        $tipos = $this->tiposFoco($this->tiposControlados(), $filtros);
        $linhas = $this->aplicarFiltrosLinhas($this->montarLinhas($usuario, $filtros, $tipos), $filtros);

        $nomeArquivo = 'relatorio-estabelecimentos-processos-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($linhas, $tipos, $filtros) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");

            $cabecalho = ['Estabelecimento', 'Razão social', 'CNPJ/CPF', 'Município', 'Competência', 'Setor', 'Situação'];
            if ($filtros['tipo']) {
                $cabecalho[] = 'Etapa';
            }
            foreach ($tipos as $tipo) {
                $cabecalho[] = $tipo->nome . ($tipo->anual ? ' (' . $filtros['ano'] . ')' : '');
            }
            $cabecalho[] = 'Processos ativos';
            $cabecalho[] = 'Último processo aberto em';
            fputcsv($out, $cabecalho, ';');

            foreach ($linhas as $linha) {
                $e = $linha['estabelecimento'];
                $registro = [
                    $e->nome_fantasia ?: $e->razao_social,
                    $e->razao_social,
                    $e->documento_formatado,
                    $linha['municipio'],
                    ucfirst($linha['competencia']),
                    $linha['setor'] === 'publico' ? 'Público' : 'Privado',
                    $linha['situacao_label'],
                ];
                if ($filtros['tipo']) {
                    $registro[] = $linha['etapa_label'] ?? '';
                }

                foreach ($tipos as $codigo => $tipo) {
                    $demanda = $linha['demandas'][$codigo] ?? null;
                    $registro[] = !$demanda
                        ? 'Não se aplica'
                        : ($demanda['atendida'] ? 'Aberto - ' . $demanda['processo']->numero_processo : 'PENDENTE');
                }

                $registro[] = $linha['processos_ativos']->map(fn ($p) => $p->numero_processo . ' (' . $p->tipo_nome . ')')->implode(', ');
                $registro[] = $linha['ultimo_processo']?->format('d/m/Y') ?? '';

                fputcsv($out, $registro, ';');
            }

            fclose($out);
        }, $nomeArquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtros(Request $request, UsuarioInterno $usuario): array
    {
        $podeFiltrarMunicipio = $usuario->isAdmin() || $usuario->isEstadual();

        return [
            'ano' => (int) ($request->input('ano') ?: now()->year),
            'competencia' => $usuario->isAdmin() && in_array($request->input('competencia'), ['estadual', 'municipal'], true)
                ? $request->input('competencia') : null,
            'municipio_id' => $podeFiltrarMunicipio && $request->filled('municipio_id') ? $request->integer('municipio_id') : null,
            'tipo' => in_array($request->input('tipo'), self::TIPOS_CONTROLADOS, true) ? $request->input('tipo') : null,
            'situacao' => in_array($request->input('situacao'), ['pendente', 'em_dia', 'com_ativo', 'sem_ativo', 'sem_exigencia', 'sem_atividade', 'com_alvara', 'doc_completa', 'doc_incompleta', 'completa_favoravel', 'completa_pendencia', 'completa_sem_parecer'], true)
                ? $request->input('situacao') : null,
            'status_estabelecimento' => $request->input('status_estabelecimento', 'aprovado') === 'todos' ? 'todos' : 'aprovado',
            'setor' => in_array($request->input('setor'), ['publico', 'privado'], true) ? $request->input('setor') : null,
            'busca' => trim((string) $request->input('busca')),
        ];
    }

    /**
     * Tipos de processo controlados pelo relatório, indexados pelo código.
     */
    private function tiposControlados(): Collection
    {
        return TipoProcesso::query()
            ->whereIn('codigo', self::TIPOS_CONTROLADOS)
            ->where('ativo', true)
            ->get()
            ->sortBy(fn ($t) => array_search($t->codigo, self::TIPOS_CONTROLADOS, true))
            ->keyBy('codigo');
    }

    /**
     * Quando o usuário escolhe um "Processo exigido", o relatório passa a considerar
     * somente esse tipo (demandas, situação, processos ativos, indicadores e gráficos).
     */
    private function tiposFoco(Collection $tipos, array $filtros): Collection
    {
        // Obs.: em Eloquent\Collection o only() filtra pela chave primária do model, não pelo código
        return $filtros['tipo'] ? $tipos->filter(fn ($tipo, $codigo) => $codigo === $filtros['tipo']) : $tipos;
    }

    private function montarLinhas(UsuarioInterno $usuario, array $filtros, Collection $tipos): Collection
    {
        $query = Estabelecimento::query()
            ->with([
                'municipioRelacionado:id,nome',
                'processos' => fn ($q) => $q->with('tipoProcesso')->orderByDesc('created_at'),
            ]);

        // Cadastros rejeitados nunca entram no relatório (nem em "Todos os cadastros")
        $query->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'rejeitado'));

        // Cadastros da própria vigilância usados no processo de Descentralização não são estabelecimentos fiscalizados
        $query->whereDoesntHave('processos', fn ($q) => $q->where('tipo', 'descentralizacao'));

        if ($filtros['status_estabelecimento'] === 'aprovado') {
            $query->where('status', 'aprovado')->where('ativo', true);
        }

        if ($usuario->isMunicipal()) {
            $query->where('municipio_id', $usuario->municipio_id ?: 0);
        } elseif ($filtros['municipio_id']) {
            $query->where('municipio_id', $filtros['municipio_id']);
        }

        if ($usuario->isEstadual()) {
            $query->where(fn ($q) => $q->whereNull('competencia_manual')->orWhere('competencia_manual', '!=', 'municipal'));
        }

        if ($filtros['busca'] !== '') {
            $busca = $filtros['busca'];
            $buscaDigitos = preg_replace('/\D/', '', $busca);
            $query->where(function ($q) use ($busca, $buscaDigitos) {
                $q->where('nome_fantasia', 'ilike', "%{$busca}%")
                    ->orWhere('razao_social', 'ilike', "%{$busca}%");
                if ($buscaDigitos !== '') {
                    $q->orWhere('cnpj', 'ilike', "%{$buscaDigitos}%")->orWhere('cpf', 'ilike', "%{$buscaDigitos}%");
                }
            });
        }

        $estabelecimentos = $query->orderByRaw('COALESCE(nome_fantasia, razao_social) asc')->get();

        // Processos ARQUIVADOS só contam se o licenciamento foi concluído (tem Alvará Sanitário assinado)
        $arquivados = $estabelecimentos
            ->flatMap(fn ($e) => $e->processos->where('status', 'arquivado')->whereIn('tipo', self::TIPOS_CONTROLADOS)->pluck('id'))
            ->values();
        $this->arquivadosComAlvara = app(\App\Services\ProcessoLinhaTempoService::class)
            ->alvarasPorProcesso($arquivados)
            ->keys()
            ->flip();

        $linhas = $estabelecimentos
            ->map(fn (Estabelecimento $e) => $this->montarLinha($e, $tipos, $filtros))
            ->filter(fn ($linha) => $this->dentroDoEscopo($linha, $usuario, $filtros['competencia']))
            ->values();

        // Antes do filtro por tipo: cadastros sem nenhuma atividade marcada (ficam fora do cálculo)
        $this->totalSemAtividade = $linhas->where('sem_atividade', true)->count();

        // Com tipo escolhido, só entram estabelecimentos que exigem esse processo
        $linhas = $linhas
            ->when($filtros['tipo'], fn ($c) => $c->filter(fn ($linha) => isset($linha['demandas'][$filtros['tipo']])))
            ->values();

        return $filtros['tipo'] ? $this->classificarEtapas($linhas, $filtros['tipo']) : $linhas;
    }

    /**
     * Com um tipo de processo escolhido, classifica em que etapa está o processo do ano de cada estabelecimento:
     *  - nao_abriu      → não abriu o processo (no ano, se for anual)
     *  - com_alvara     → (licenciamento) processo com Alvará Sanitário assinado
     *  - doc_completa   → todos os documentos obrigatórios aprovados (ainda sem alvará)
     *  - doc_incompleta → falta enviar/aprovar documento obrigatório
     */
    private function classificarEtapas(Collection $linhas, string $tipo): Collection
    {
        $processos = new \Illuminate\Database\Eloquent\Collection(
            $linhas->map(fn ($l) => $l['demandas'][$tipo]['processo'] ?? null)->filter()->values()->all()
        );

        // processo_id => ['definitivo' => ?Carbon, 'provisorio' => ?Carbon] (uma consulta só)
        $comAlvara = $tipo === 'licenciamento'
            ? app(\App\Services\ProcessoLinhaTempoService::class)->alvarasPorProcesso($processos->pluck('id'))
            : collect();

        // Checklist de documentos obrigatórios só para quem ainda não tem alvará (mesma regra da tela de Processos)
        $semAlvara = $processos->reject(fn ($p) => $comAlvara->has($p->id))->values();
        if ($semAlvara->isNotEmpty()) {
            $semAlvara->load(['documentos', 'pastas', 'unidades']);
        }

        $linhas = $linhas->map(function ($linha) use ($tipo, $comAlvara) {
            $processo = $linha['demandas'][$tipo]['processo'] ?? null;

            if (!$processo) {
                $etapa = 'nao_abriu';
            } elseif ($comAlvara->has($processo->id)) {
                $etapa = 'com_alvara';
                // Tempo só para o alvará definitivo (provisório não conclui o licenciamento)
                $definitivo = $comAlvara[$processo->id]['definitivo'];
                $linha['alvara_definitivo'] = (bool) $definitivo;
                $linha['dias_ate_alvara'] = $definitivo ? (int) $processo->created_at->diffInDays($definitivo) : null;
            } else {
                $processo->setRelation('estabelecimento', $linha['estabelecimento']);
                $obrigatorios = $processo->getDocumentosObrigatoriosChecklist()->where('obrigatorio', true);
                $completo = $obrigatorios->isEmpty() || $obrigatorios->every(fn ($d) => $d['status'] === 'aprovado');
                $etapa = $completo ? 'doc_completa' : 'doc_incompleta';
            }

            $linha['etapa'] = $etapa;
            $linha['etapa_label'] = [
                'nao_abriu' => 'Não abriu',
                'com_alvara' => 'Com alvará sanitário',
                'doc_completa' => $tipo === 'licenciamento' ? 'Doc. completa · sem alvará' : 'Doc. completa',
                'doc_incompleta' => 'Doc. incompleta',
            ][$etapa];

            return $linha;
        });

        return $tipo === 'licenciamento' ? $this->classificarParecer($linhas) : $linhas;
    }

    /**
     * Licenciamento com documentação completa e sem alvará: situação do parecer
     * (considerando só documentos ASSINADOS do processo do ano):
     *  - completa_pendencia   → último parecer é desfavorável/indeferido OU há notificação com prazo em aberto
     *  - completa_favoravel   → último parecer é favorável (e sem notificação em aberto)
     *  - completa_sem_parecer → nenhum parecer e nenhuma notificação em aberto
     *
     * Os tipos são identificados pelo nome (os códigos variam entre ambientes). Compara pelo INÍCIO
     * do nome, pois "desfavorável" também contém "favor".
     */
    private function classificarParecer(Collection $linhas): Collection
    {
        $processos = $linhas->where('etapa', 'doc_completa')
            ->map(fn ($l) => $l['demandas']['licenciamento']['processo'] ?? null)
            ->filter();

        if ($processos->isEmpty()) {
            return $linhas;
        }

        $tiposFavoravel = TipoDocumento::query()->where('nome', 'ilike', 'parecer favor%')->pluck('id')->all();
        $tiposDesfavoravel = TipoDocumento::query()
            ->where(fn ($q) => $q->where('nome', 'ilike', 'parecer desfavor%')
                ->orWhere('nome', 'ilike', 'parecer indeferido%')
                ->orWhere('codigo', 'parecer-indeferido'))
            ->pluck('id')->all();
        $tiposNotificacao = TipoDocumento::query()
            ->where(fn ($q) => $q->where('codigo', 'notificacao')->orWhere('nome', 'ilike', 'notifica%'))
            ->pluck('id')->all();

        $documentos = \App\Models\DocumentoDigital::query()
            ->whereIn('processo_id', $processos->pluck('id'))
            ->where('status', 'assinado')
            ->whereIn('tipo_documento_id', array_merge($tiposFavoravel, $tiposDesfavoravel, $tiposNotificacao))
            ->get(['id', 'processo_id', 'tipo_documento_id', 'finalizado_em', 'created_at', 'prazo_finalizado_em', 'data_vencimento'])
            ->groupBy('processo_id');

        return $linhas->map(function ($linha) use ($documentos, $tiposFavoravel, $tiposDesfavoravel, $tiposNotificacao) {
            if (($linha['etapa'] ?? null) !== 'doc_completa') {
                return $linha;
            }

            $docs = $documentos->get($linha['demandas']['licenciamento']['processo']->id, collect());

            $ultimoParecer = $docs
                ->filter(fn ($d) => in_array($d->tipo_documento_id, array_merge($tiposFavoravel, $tiposDesfavoravel), true))
                ->sortByDesc(fn ($d) => $d->finalizado_em ?? $d->created_at)
                ->first();

            $notificacaoAberta = $docs
                ->filter(fn ($d) => in_array($d->tipo_documento_id, $tiposNotificacao, true) && is_null($d->prazo_finalizado_em))
                ->sortBy(fn ($d) => $d->data_vencimento ?? $d->created_at)
                ->first();

            $desfavoravel = $ultimoParecer && in_array($ultimoParecer->tipo_documento_id, $tiposDesfavoravel, true);

            if ($desfavoravel || $notificacaoAberta) {
                $sub = 'completa_pendencia';
                $motivos = [];
                if ($desfavoravel) {
                    $motivos[] = 'Parecer desfavorável';
                }
                if ($notificacaoAberta) {
                    $motivos[] = 'Notificação em prazo' . ($notificacaoAberta->data_vencimento ? ' (até ' . $notificacaoAberta->data_vencimento->format('d/m/Y') . ')' : '');
                }
                $detalhe = implode(' · ', $motivos);
            } elseif ($ultimoParecer) {
                $sub = 'completa_favoravel';
                $detalhe = 'Parecer favorável';
            } else {
                $sub = 'completa_sem_parecer';
                $detalhe = 'Aguardando parecer';
            }

            $linha['sub_etapa'] = $sub;
            $linha['sub_etapa_label'] = $detalhe;
            $linha['etapa_label'] = 'Doc. completa · ' . mb_strtolower($detalhe);

            return $linha;
        });
    }

    private function montarLinha(Estabelecimento $e, Collection $tipos, array $filtros): array
    {
        $competencia = $e->isCompetenciaEstadual() ? 'estadual' : 'municipal';
        // Só as atividades MARCADAS no cadastro. getTodasAtividades() usa o CNAE da Receita quando
        // nada está marcado, o que faria cadastros sem atividade "exigirem" licenciamento.
        $atividades = $this->atividadesMarcadas($e);
        $possuiCnaeComum = collect($atividades)->contains(fn ($c) => !array_key_exists($c, self::ATIVIDADES_ESPECIAIS));

        $exigidos = [];
        if ($possuiCnaeComum) {
            $exigidos[] = 'licenciamento';
        }
        foreach (self::ATIVIDADES_ESPECIAIS as $atividade => $codigoTipo) {
            if (in_array($atividade, $atividades, true)) {
                $exigidos[] = $codigoTipo;
            }
        }

        $demandas = [];
        foreach ($exigidos as $codigo) {
            $tipo = $tipos->get($codigo);
            if (!$tipo) {
                continue;
            }

            $processosDoTipo = $e->processos
                ->where('tipo', $codigo)
                ->when($tipo->anual, fn ($c) => $c->where('ano', $filtros['ano']));

            // Arquivado sem alvará não conta (o estabelecimento precisa abrir de novo)
            $ignorado = fn ($p) => $p->status === 'arquivado' && !$this->arquivadosComAlvara?->has($p->id);
            $processo = $processosDoTipo->reject($ignorado)->first();

            $demandas[$codigo] = [
                'codigo' => $codigo,
                'nome' => $tipo->nome,
                'atendida' => (bool) $processo,
                'processo' => $processo,
                'anual' => (bool) $tipo->anual,
                // Só para informação na lista: processo arquivado (sem alvará) que foi desconsiderado
                'arquivado' => $processo ? null : $processosDoTipo->filter($ignorado)->first(),
            ];
        }

        // Com tipo escolhido, processos ativos/último processo/aberturas consideram só esse tipo
        $processosConsiderados = $filtros['tipo']
            ? $e->processos->where('tipo', $filtros['tipo'])->values()
            : $e->processos;

        $processosAtivos = $processosConsiderados->reject(fn ($p) => in_array($p->status, self::STATUS_INATIVOS, true))->values();
        $pendente = collect($demandas)->contains(fn ($d) => !$d['atendida']);

        if (empty($atividades)) {
            $situacao = 'sem_exigencia';
            $situacaoLabel = 'Sem atividade marcada';
        } elseif (empty($demandas)) {
            $situacao = 'sem_exigencia';
            $situacaoLabel = 'Sem exigência';
        } elseif ($pendente) {
            $situacao = 'pendente';
            $situacaoLabel = 'Pendente';
        } else {
            $situacao = 'em_dia';
            $situacaoLabel = 'Em dia';
        }

        $municipio = $e->relationLoaded('municipioRelacionado') ? $e->getRelation('municipioRelacionado') : null;

        $tipoSetor = $e->tipo_setor instanceof \App\Enums\TipoSetor ? $e->tipo_setor->value : ($e->tipo_setor ?: 'privado');

        return [
            'estabelecimento' => $e,
            'competencia' => $competencia,
            'setor' => $tipoSetor === 'publico' ? 'publico' : 'privado',
            'municipio_id' => $e->municipio_id,
            'municipio' => $municipio?->nome ?? ($e->cidade ?: '—'),
            'demandas' => $demandas,
            'processos_ativos' => $processosAtivos,
            'processos' => $processosConsiderados,
            'ultimo_processo' => $processosConsiderados->first()?->created_at,
            'situacao' => $situacao,
            'situacao_label' => $situacaoLabel,
            'sem_atividade' => empty($atividades),
        ];
    }

    /**
     * Códigos das atividades marcadas pelo estabelecimento (CNAE só dígitos, PROJ_ARQ, ANAL_ROT), sem fallback.
     */
    private function atividadesMarcadas(Estabelecimento $e): array
    {
        return collect($e->atividades_exercidas ?? [])
            ->map(fn ($a) => is_array($a) ? ($a['codigo'] ?? null) : (is_string($a) ? $a : null))
            ->filter()
            ->map(fn ($codigo) => array_key_exists(strtoupper($codigo), self::ATIVIDADES_ESPECIAIS)
                ? strtoupper($codigo)
                : preg_replace('/\D/', '', $codigo))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function dentroDoEscopo(array $linha, UsuarioInterno $usuario, ?string $competenciaFiltro): bool
    {
        if ($usuario->isMunicipal()) {
            return $linha['competencia'] === 'municipal';
        }

        if ($usuario->isEstadual()) {
            return $linha['competencia'] === 'estadual';
        }

        if (!$usuario->isAdmin()) {
            return false;
        }

        return !$competenciaFiltro || $linha['competencia'] === $competenciaFiltro;
    }

    private function aplicarFiltrosLinhas(Collection $linhas, array $filtros): Collection
    {
        $tipo = $filtros['tipo'];

        return $linhas
            ->when($tipo, fn ($c) => $c->filter(fn ($l) => isset($l['demandas'][$tipo])))
            ->when($filtros['setor'], fn ($c) => $c->where('setor', $filtros['setor']))
            ->when($filtros['situacao'], function ($c) use ($filtros, $tipo) {
                return $c->filter(function ($l) use ($filtros, $tipo) {
                    return match ($filtros['situacao']) {
                        'pendente' => $tipo ? !$l['demandas'][$tipo]['atendida'] : $l['situacao'] === 'pendente',
                        'em_dia' => $tipo ? $l['demandas'][$tipo]['atendida'] : $l['situacao'] === 'em_dia',
                        'com_ativo' => $l['processos_ativos']->isNotEmpty(),
                        'sem_ativo' => $l['processos_ativos']->isEmpty(),
                        'sem_exigencia' => $l['situacao'] === 'sem_exigencia',
                        'sem_atividade' => $l['sem_atividade'],
                        'com_alvara', 'doc_completa', 'doc_incompleta' => ($l['etapa'] ?? null) === $filtros['situacao'],
                        'completa_favoravel', 'completa_pendencia', 'completa_sem_parecer' => ($l['sub_etapa'] ?? null) === $filtros['situacao'],
                        default => true,
                    };
                });
            })
            ->values();
    }

    private function indicadores(Collection $linhas, Collection $tipos, array $filtros): array
    {
        $comExigencia = $linhas->where('situacao', '!=', 'sem_exigencia');
        $pendentes = $linhas->where('situacao', 'pendente');

        $porTipo = $tipos->map(function ($tipo, $codigo) use ($linhas) {
            $com = $linhas->filter(fn ($l) => isset($l['demandas'][$codigo]));
            $atendidos = $com->filter(fn ($l) => $l['demandas'][$codigo]['atendida'])->count();
            $naoAbriram = $com->reject(fn ($l) => $l['demandas'][$codigo]['atendida']);

            return [
                'nome' => $tipo->nome,
                'anual' => (bool) $tipo->anual,
                'exigem' => $com->count(),
                'atendidos' => $atendidos,
                'pendentes' => $com->count() - $atendidos,
                'cobertura' => $com->count() ? round($atendidos / $com->count() * 100) : null,
                // Público x privado
                'exigem_publico' => $com->where('setor', 'publico')->count(),
                'exigem_privado' => $com->where('setor', 'privado')->count(),
                'pendentes_publico' => $naoAbriram->where('setor', 'publico')->count(),
                'pendentes_privado' => $naoAbriram->where('setor', 'privado')->count(),
            ];
        });

        $ativos = $linhas->flatMap(fn ($l) => $l['processos_ativos']);

        return [
            'total' => $linhas->count(),
            'com_ativo' => $linhas->filter(fn ($l) => $l['processos_ativos']->isNotEmpty())->count(),
            'sem_ativo' => $linhas->filter(fn ($l) => $l['processos_ativos']->isEmpty())->count(),
            'pendentes' => $pendentes->count(),
            'pendentes_publico' => $pendentes->where('setor', 'publico')->count(),
            'pendentes_privado' => $pendentes->where('setor', 'privado')->count(),
            'publico' => $linhas->where('setor', 'publico')->count(),
            'privado' => $linhas->where('setor', 'privado')->count(),
            'em_dia' => $linhas->where('situacao', 'em_dia')->count(),
            'sem_exigencia' => $linhas->where('situacao', 'sem_exigencia')->count(),
            'cobertura' => $comExigencia->count() ? round(($comExigencia->count() - $pendentes->count()) / $comExigencia->count() * 100) : null,
            'processos_ativos' => $ativos->count(),
            'processos_parados' => $ativos->where('status', 'parado')->count(),
            'por_tipo' => $porTipo,
            // Etapas (só quando há um tipo de processo escolhido)
            'com_alvara' => $linhas->where('etapa', 'com_alvara')->count(),
            'alvara_definitivo' => $linhas->where('alvara_definitivo', true)->count(),
            'alvara_nao_definitivo' => $linhas->where('etapa', 'com_alvara')->where('alvara_definitivo', false)->count(),
            'media_dias_alvara' => ($dias = $linhas->pluck('dias_ate_alvara')->filter(fn ($d) => $d !== null))->isNotEmpty()
                ? (int) round($dias->avg()) : null,
            'doc_completa' => $linhas->where('etapa', 'doc_completa')->count(),
            'doc_incompleta' => $linhas->where('etapa', 'doc_incompleta')->count(),
            // Licenciamento com doc. completa: situação do parecer
            'completa_favoravel' => $linhas->where('sub_etapa', 'completa_favoravel')->count(),
            'completa_pendencia' => $linhas->where('sub_etapa', 'completa_pendencia')->count(),
            'completa_sem_parecer' => $linhas->where('sub_etapa', 'completa_sem_parecer')->count(),
            'estadual' => $linhas->where('competencia', 'estadual')->count(),
            'municipal' => $linhas->where('competencia', 'municipal')->count(),
            'ano' => $filtros['ano'],
        ];
    }

    private function graficos(Collection $linhas, Collection $tipos, array $filtros, UsuarioInterno $usuario): array
    {
        // Situação por competência (estadual x municipal)
        $porCompetencia = collect(['estadual', 'municipal'])->mapWithKeys(function ($comp) use ($linhas) {
            $grupo = $linhas->where('competencia', $comp);
            return [$comp => [
                'em_dia' => $grupo->where('situacao', 'em_dia')->count(),
                'pendente' => $grupo->where('situacao', 'pendente')->count(),
                'sem_exigencia' => $grupo->where('situacao', 'sem_exigencia')->count(),
            ]];
        });

        // Processos ativos por tipo
        $ativos = $linhas->flatMap(fn ($l) => $l['processos_ativos']);
        $ativosPorTipo = $ativos->groupBy(fn ($p) => $p->tipo_nome)->map->count()->sortDesc();

        // Processos ativos por status
        $ativosPorStatus = $ativos->groupBy(fn ($p) => $p->status === 'parado' ? 'Parado' : 'Em tramitação')->map->count();

        // Idade dos processos ativos
        $faixas = ['Até 30 dias' => 0, '31 a 60 dias' => 0, '61 a 90 dias' => 0, 'Mais de 90 dias' => 0];
        foreach ($ativos as $p) {
            $dias = (int) $p->created_at->diffInDays(now());
            $faixa = $dias <= 30 ? 'Até 30 dias' : ($dias <= 60 ? '31 a 60 dias' : ($dias <= 90 ? '61 a 90 dias' : 'Mais de 90 dias'));
            $faixas[$faixa]++;
        }

        // Aberturas de processos por mês no ano de referência (por competência do estabelecimento)
        $meses = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        $aberturas = ['estadual' => array_fill(0, 12, 0), 'municipal' => array_fill(0, 12, 0)];
        foreach ($linhas as $l) {
            foreach ($l['processos'] as $p) {
                if ((int) $p->created_at->year === $filtros['ano']) {
                    $aberturas[$l['competencia']][$p->created_at->month - 1]++;
                }
            }
        }

        // Municípios com mais estabelecimentos pendentes
        $topMunicipios = $usuario->isMunicipal() ? collect() : $linhas->where('situacao', 'pendente')
            ->groupBy('municipio')->map->count()->sortDesc()->take(10);

        return [
            'por_competencia' => $porCompetencia,
            'cobertura_tipos' => $tipos->map(fn ($t, $codigo) => [
                'nome' => $t->nome . ($t->anual ? ' ' . $filtros['ano'] : ''),
                'atendidos' => $linhas->filter(fn ($l) => ($l['demandas'][$codigo]['atendida'] ?? null) === true)->count(),
                'pendentes' => $linhas->filter(fn ($l) => ($l['demandas'][$codigo]['atendida'] ?? null) === false)->count(),
            ])->values(),
            'ativos_por_tipo' => $ativosPorTipo,
            'ativos_por_status' => $ativosPorStatus,
            'idade_ativos' => $faixas,
            'meses' => $meses,
            'aberturas' => $aberturas,
            'top_municipios' => $topMunicipios,
        ];
    }

    private function paginar(Collection $itens, int $porPagina, Request $request): LengthAwarePaginator
    {
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $itens->forPage($pagina, $porPagina)->values(),
            $itens->count(),
            $porPagina,
            $pagina,
            ['path' => route('admin.relatorios.estabelecimentos'), 'query' => $request->query()]
        );
    }
}
