<?php

namespace App\Http\Controllers;

use App\Models\Processo;
use App\Models\Estabelecimento;
use App\Models\TipoProcesso;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoAcompanhamento;
use App\Models\ProcessoDesignacao;
use App\Models\ProcessoAlerta;
use App\Models\ProcessoEvento;
use App\Models\ModeloDocumento;
use App\Models\UsuarioInterno;
use App\Models\DocumentoResposta;
use App\Models\DocumentoDigital;
use App\Models\TipoSetor;
use App\Models\Unidade;
use App\Services\ResponsavelTecnicoNomeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;
use Barryvdh\DomPDF\Facade\Pdf;

class ProcessoController extends Controller
{
    /**
     * Busca documentos obrigatórios para um processo baseado nas atividades exercidas do estabelecimento
     * ou diretamente pelo tipo de processo (para processos especiais como Projeto Arquitetônico e Análise de Rotulagem)
     */
    private function buscarDocumentosObrigatoriosParaProcesso($processo)
    {
        return $processo->getDocumentosObrigatoriosChecklist();
    }

    /**
     * Retorna os documentos obrigatórios por município (escopo 'por_municipio')
     * para processos de credenciamento de unidade móvel.
     */
    private function buscarDocumentosObrigatoriosPorMunicipioUnidadeMovel($processo)
    {
        $estabelecimento = $processo->estabelecimento;

        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        $codigosCnae = collect($atividadesExercidas)->map(function ($atividade) {
            $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
        })->filter()->values()->toArray();

        $docsConfig = \App\Models\DocumentoUnidadeMovel::paraEstesCnaes($codigosCnae);
        $docsPorMunicipio = $docsConfig->where('escopo', 'por_municipio');

        if ($docsPorMunicipio->isEmpty()) {
            return collect();
        }

        // Pastas protegidas = pastas de município
        $pastasProtegidas = $processo->pastas()->where('protegida', true)->orderBy('ordem')->get();

        if ($pastasProtegidas->isEmpty()) {
            return collect();
        }

        $resultado = collect();

        foreach ($pastasProtegidas as $pasta) {
            $docsNaPasta = collect();

            foreach ($docsPorMunicipio as $docConf) {
                $tipoDoc = $docConf->tipoDocumento;
                if (!$tipoDoc) continue;

                // Verifica se já foi enviado nesta pasta
                $docEnviado = $processo->documentos
                    ->where('tipo_documento_obrigatorio_id', $tipoDoc->id)
                    ->where('pasta_id', $pasta->id)
                    ->sortByDesc('created_at')
                    ->first();

                $statusEnvio = $docEnviado ? $docEnviado->status_aprovacao : null;
                $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);

                if (!$docsNaPasta->contains('id', $tipoDoc->id)) {
                    $docsNaPasta->push([
                        'id' => $tipoDoc->id,
                        'nome' => $tipoDoc->nome,
                        'descricao' => $tipoDoc->descricao,
                        'obrigatorio' => $docConf->obrigatorio,
                        'observacao' => null,
                        'lista_nome' => 'Documentos Por Município - ' . $pasta->nome,
                        'status' => $statusEnvio,
                        'ja_enviado' => $jaEnviado,
                        'status_envio' => $statusEnvio,
                        'pasta_id' => $pasta->id,
                        'documento_comum' => false,
                    ]);
                }
            }

            $resultado[$pasta->id] = [
                'pasta' => $pasta,
                'nome' => $pasta->nome,
                'documentos' => $docsNaPasta->sortBy([['obrigatorio', 'desc'], ['nome', 'asc']])->values(),
                'total' => $docsNaPasta->where('obrigatorio', true)->count(),
                'enviados' => $docsNaPasta->where('obrigatorio', true)->where('ja_enviado', true)->count(),
                'aprovados' => $docsNaPasta->where('obrigatorio', true)->where('status', 'aprovado')->count(),
            ];
        }

        return $resultado;
    }

    private function processoPertenceAoEscopoUsuario(?Processo $processo, $usuario): bool
    {
        return $processo?->pertenceAoEscopoDoUsuario($usuario) ?? false;
    }

    private function tipoProcessoVisivelParaUsuarioNoEstabelecimento(TipoProcesso $tipoProcesso, Estabelecimento $estabelecimento, $usuario): bool
    {
        if (!$tipoProcesso->disponivelParaEstabelecimento($estabelecimento)) {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        $escopoCompetencia = $tipoProcesso->resolverEscopoCompetencia($estabelecimento);

        if ($usuario->isEstadual()) {
            return $escopoCompetencia === 'estadual';
        }

        if ($usuario->isMunicipal()) {
            return $escopoCompetencia === 'municipal'
                && $usuario->municipio_id
                && (int) $estabelecimento->municipio_id === (int) $usuario->municipio_id;
        }

        return false;
    }

    /**
     * Exibe todos os processos do sistema com filtros
     */
    public function indexGeral(Request $request)
    {
        $usuario = auth('interno')->user();
        $query = Processo::with(['estabelecimento', 'usuario', 'tipoProcesso']);

        // ✅ FILTRO AUTOMÁTICO POR MUNICÍPIO/COMPETÊNCIA
        if (!$usuario->isAdmin()) {
            if ($usuario->isMunicipal() && $usuario->municipio_id) {
                // Gestor/Técnico Municipal: vê apenas processos do próprio município
                $query->whereHas('estabelecimento', function ($q) use ($usuario) {
                    $q->where('municipio_id', $usuario->municipio_id);
                    // Filtro territorial do setor (ex: Luzimangues por CEP e/ou bairro/localidade)
                    $cepsFiltro = $usuario->getCepsFiltro();
                    $bairrosFiltro = $usuario->getBairrosFiltro();
                    if (!empty($cepsFiltro) || !empty($bairrosFiltro)) {
                        $q->filtroTerritorial($cepsFiltro, $bairrosFiltro);
                    }
                });
            }
        }

        // Busca unificada: número do processo OU estabelecimento (nome/CNPJ)
        if ($request->filled('busca')) {
            $busca = $request->busca;
            $query->where(function($q) use ($busca) {
                $q->where('numero_processo', 'ilike', '%' . $busca . '%')
                  ->orWhereHas('estabelecimento', function ($qe) use ($busca) {
                      $qe->where('nome_fantasia', 'ilike', '%' . $busca . '%')
                        ->orWhere('razao_social', 'ilike', '%' . $busca . '%')
                        ->orWhere('cnpj', 'ilike', '%' . $busca . '%');
                  });
            });
        }

        // Filtro por número do processo (mantido para compatibilidade)
        if ($request->filled('numero_processo')) {
            $query->where('numero_processo', 'ilike', '%' . $request->numero_processo . '%');
        }

        // Filtro por estabelecimento (nome ou CNPJ) (mantido para compatibilidade)
        if ($request->filled('estabelecimento')) {
            $query->whereHas('estabelecimento', function ($q) use ($request) {
                $q->where('nome_fantasia', 'ilike', '%' . $request->estabelecimento . '%')
                  ->orWhere('razao_social', 'ilike', '%' . $request->estabelecimento . '%')
                  ->orWhere('cnpj', 'ilike', '%' . $request->estabelecimento . '%');
            });
        }

        // Filtro por tipo de processo
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        // Filtro por status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filtro para exibir apenas processos ativos (mesma lógica do dashboard)
        if ($request->boolean('apenas_ativos')) {
            $query->whereNotIn('status', ['arquivado', 'concluido']);
        }

        // Filtro por ano
        if ($request->filled('ano')) {
            $query->where('ano', $request->ano);
        }

        // Filtro por responsável/setor
        if ($request->filled('responsavel')) {
            switch ($request->responsavel) {
                case 'meus':
                    $query->where('responsavel_atual_id', $usuario->id);
                    break;
                case 'meu_setor':
                    if ($usuario->setor) {
                        $query->where('setor_atual', $usuario->setor);
                    }
                    break;
                case 'nao_atribuido':
                    $query->whereNull('responsavel_atual_id')
                        ->whereNull('setor_atual')
                        ->where('status', '!=', 'arquivado');
                    break;
            }
        }

        // Filtros que podem reduzir a lista antes de carregar os registros.
        if ($request->filled('setor')) {
            $query->where('setor_atual', $request->setor);
        }

        if ($request->boolean('monitorando')) {
            $query->whereIn('processos.id', function ($subquery) use ($usuario) {
                $subquery->select('processo_id')
                    ->from('processo_acompanhamentos')
                    ->where('usuario_interno_id', $usuario->id);
            });
        }

        // Ordenação
        $ordenacao = $request->get('ordenacao', 'recentes');
        switch ($ordenacao) {
            case 'antigos':
                $query->orderBy('created_at', 'asc');
                break;
            case 'numero':
                $query->orderBy('ano', 'desc')->orderBy('numero_sequencial', 'desc');
                break;
            case 'estabelecimento':
                $query->join('estabelecimentos', 'processos.estabelecimento_id', '=', 'estabelecimentos.id')
                      ->orderBy('estabelecimentos.nome_fantasia', 'asc')
                      ->select('processos.*');
                break;
            default: // recentes
                $query->orderBy('created_at', 'desc');
        }

        // Carrega só o essencial para filtros/ordenação/estatísticas.
        // 'documentos' é carregado apenas para os itens da página atual (ver abaixo).
        $processosCollection = $query->with(['responsavelAtual'])->get();

        // ✅ FILTRO ADICIONAL POR COMPETÊNCIA
        if (!$usuario->isAdmin()) {
            if ($usuario->isEstadual()) {
                $processosCollection = $processosCollection->filter(fn ($processo) => $this->processoPertenceAoEscopoUsuario($processo, $usuario))->values();
            } elseif ($usuario->isMunicipal()) {
                $processosCollection = $processosCollection->filter(fn ($processo) => $this->processoPertenceAoEscopoUsuario($processo, $usuario))->values();
            }
        }

        // Setores disponíveis para filtro
        $setoresCodigos = $processosCollection->pluck('setor_atual')->filter()->unique()->values();
        $setoresNomes = TipoSetor::whereIn('codigo', $setoresCodigos)->pluck('nome', 'codigo');
        $setoresDisponiveis = $setoresCodigos->mapWithKeys(fn ($codigo) => [$codigo => $setoresNomes[$codigo] ?? $codigo])->sort();

        // Dados para filtros
        $codigosTiposVisiveis = $processosCollection->pluck('tipo')->filter()->unique()->values();
        $tiposProcesso = TipoProcesso::ativos()->paraUsuario($usuario)->whereIn('codigo', $codigosTiposVisiveis)->ordenado()->get();
        $statusDisponiveis = Processo::statusDisponiveis();
        $anos = Processo::select('ano')->distinct()->orderBy('ano', 'desc')->pluck('ano');

        // IDs de processos com pendências (query rápida no banco)
        $idsProcessosBase = $processosCollection->pluck('id');
        $processosComDocsPendentes = ProcessoDocumento::where('status_aprovacao', 'pendente')
            ->where('tipo_usuario', 'externo')
            ->whereIn('processo_id', $idsProcessosBase)
            ->pluck('processo_id')->unique();
        
        $processosComRespostasPendentes = DocumentoResposta::where('documento_respostas.status', 'pendente')
            ->join('documentos_digitais', 'documento_respostas.documento_digital_id', '=', 'documentos_digitais.id')
            ->whereIn('documentos_digitais.processo_id', $idsProcessosBase)
            ->pluck('documentos_digitais.processo_id')->unique();
        
        $processosComPendencias = $processosComDocsPendentes->merge($processosComRespostasPendentes)->unique();

        // O checklist completo e pesado; calcule so quando algum filtro precisar dele.
        $processosNaoArquivados = $processosCollection->where('status', '!=', 'arquivado');
        $processosCompletos = collect();
        $processosIncompletos = collect();
        $precisaClassificarDocumentacao = in_array($request->quick, ['completo', 'nao_enviado'], true)
            || in_array($request->docs_obrigatorios, ['completos', 'pendentes'], true);

        if ($precisaClassificarDocumentacao) {
            if ($processosNaoArquivados->isNotEmpty()) {
                $processosNaoArquivados->load(['documentos', 'pastas', 'unidades']);
            }

            foreach ($processosNaoArquivados as $processo) {
                $docsObrigatorios = $processo->getDocumentosObrigatoriosChecklist();
                $obrigatorios = $docsObrigatorios->where('obrigatorio', true);

                if ($obrigatorios->isEmpty()) {
                    // Sem docs obrigatorios = considerado completo.
                    $processosCompletos->push($processo->id);
                } else {
                    $todosAprovados = $obrigatorios->every(fn($d) => $d['status'] === 'aprovado');
                    if ($todosAprovados) {
                        $processosCompletos->push($processo->id);
                    } else {
                        $processosIncompletos->push($processo->id);
                    }
                }
            }
        }

        // Resumo rápido
        $resumoQuick = [
            'todos' => $processosCollection->count(),
            'completo' => $precisaClassificarDocumentacao ? $processosCompletos->count() : null,
            'nao_enviado' => $precisaClassificarDocumentacao ? $processosIncompletos->count() : null,
            'aguardando' => $processosComPendencias->intersect($idsProcessosBase)->count(),
            'arquivado' => $processosCollection->where('status', 'arquivado')->count(),
            'nao_atribuido' => $processosCollection->filter(fn($p) => $p->status !== 'arquivado' && !$p->responsavel_atual_id && !$p->setor_atual)->count(),
        ];

        // Filtro rápido
        if ($request->filled('quick')) {
            $filtroRapido = $request->quick;
            $processosCollection = $processosCollection->filter(function ($processo) use ($processosComPendencias, $processosCompletos, $processosIncompletos, $filtroRapido) {
                if ($filtroRapido === 'completo') return $processosCompletos->contains($processo->id);
                if ($filtroRapido === 'nao_enviado') return $processosIncompletos->contains($processo->id);
                if ($filtroRapido === 'aguardando') return $processosComPendencias->contains($processo->id);
                if ($filtroRapido === 'arquivado') return $processo->status === 'arquivado';
                if ($filtroRapido === 'nao_atribuido') return $processo->status !== 'arquivado' && !$processo->responsavel_atual_id && !$processo->setor_atual;
                return true;
            })->values();
        }

        // Filtro de documentacao obrigatoria
        if ($request->filled('docs_obrigatorios')) {
            if ($request->docs_obrigatorios === 'completos') {
                $processosCollection = $processosCollection->filter(fn ($processo) => $processosCompletos->contains($processo->id))->values();
            } elseif ($request->docs_obrigatorios === 'pendentes') {
                $processosCollection = $processosCollection->filter(fn ($processo) => $processosIncompletos->contains($processo->id))->values();
            }
        }

        // Ordenação por prazo (apenas para projeto_arquitetonico) - precisa calcular prazo antes de paginar
        if (in_array($ordenacao, ['prazo_menor', 'prazo_maior']) && $request->get('tipo') === 'projeto_arquitetonico') {
            // Carrega documentos para calcular prazo de todos os processos filtrados
            $processosCollection->load(['documentos', 'tipoProcesso']);
            
            $prazosPorProcesso = [];
            foreach ($processosCollection as $processo) {
                $docsObrigatorios = $this->buscarDocumentosObrigatoriosParaProcesso($processo);
                $diasRestantes = null;
                
                if ($docsObrigatorios->count() > 0) {
                    $totalOk = $docsObrigatorios->where('status', 'aprovado')->count();
                    $total = $docsObrigatorios->count();
                    
                    if ($processo->status !== 'arquivado' && $totalOk === $total && 
                        $processo->tipoProcesso && $processo->tipoProcesso->exibir_fila_publica && 
                        $processo->tipoProcesso->prazo_fila_publica > 0) {
                        $docsAprovados = $docsObrigatorios->where('obrigatorio', true)->where('status', 'aprovado');
                        $dataCompletos = null;
                        foreach ($docsAprovados as $docObrig) {
                            $docProcesso = $processo->documentos
                                ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                                ->where('status_aprovacao', 'aprovado')
                                ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                                ->first();
                            $dataRef = $docProcesso?->aprovado_em ?? $docProcesso?->updated_at;
                            if ($dataRef && (!$dataCompletos || $dataRef > $dataCompletos)) $dataCompletos = $dataRef;
                        }
                        if ($dataCompletos) {
                            $grupoRisco = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                            $prazo = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRisco);
                            $dataLimite = $processo->calcularDataLimiteFilaPublica($dataCompletos, $prazo);
                            $diasRestantes = (int) round(\Carbon\Carbon::now()->diffInDays($dataLimite, false));
                        }
                    }
                }
                
                $prazosPorProcesso[$processo->id] = $diasRestantes;
            }
            
            // Ordena: processos com prazo primeiro, depois sem prazo
            $processosCollection = $processosCollection->sortBy(function ($processo) use ($prazosPorProcesso, $ordenacao) {
                $dias = $prazosPorProcesso[$processo->id];
                if ($dias === null) {
                    // Sem prazo vai para o final
                    return $ordenacao === 'prazo_menor' ? PHP_INT_MAX : PHP_INT_MIN;
                }
                return $ordenacao === 'prazo_menor' ? $dias : -$dias;
            })->values();
        }

        // Paginacao
        $perPage = 10;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $itensPagina = $processosCollection->forPage($currentPage, $perPage)->values();

        // Carrega 'documentos' APENAS para os itens da página atual (evita carregar milhares de docs)
        if ($itensPagina->isNotEmpty()) {
            $itensPagina->load('documentos');
        }

        // Calcula docs obrigatórios APENAS para os processos da página atual (10 no máximo)
        $statusDocsObrigatorios = [];
        $prazoFilaPublica = [];
        foreach ($itensPagina as $processo) {
            $docsObrigatorios = $this->buscarDocumentosObrigatoriosParaProcesso($processo);
            if ($docsObrigatorios->count() > 0) {
                $totalOk = $docsObrigatorios->where('status', 'aprovado')->count();
                $total = $docsObrigatorios->count();
                $statusDocsObrigatorios[$processo->id] = [
                    'total' => $total,
                    'ok' => $totalOk,
                    'pendente' => $docsObrigatorios->where('status', 'pendente')->count(),
                    'rejeitado' => $docsObrigatorios->where('status', 'rejeitado')->count(),
                    'nao_enviado' => $docsObrigatorios->whereNull('status')->count(),
                    'completo' => $totalOk === $total,
                ];

                if ($processo->status !== 'arquivado' && $totalOk === $total && 
                    $processo->tipoProcesso && $processo->tipoProcesso->exibir_fila_publica && 
                    $processo->tipoProcesso->prazo_fila_publica > 0) {
                    
                    $docsAprovados = $docsObrigatorios->where('obrigatorio', true)->where('status', 'aprovado');
                    $dataCompletos = null;
                    foreach ($docsAprovados as $docObrig) {
                        $docProcesso = $processo->documentos
                            ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                            ->where('status_aprovacao', 'aprovado')
                            ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                            ->first();
                        $dataRef = $docProcesso?->aprovado_em ?? $docProcesso?->updated_at;
                        if ($dataRef && (!$dataCompletos || $dataRef > $dataCompletos)) $dataCompletos = $dataRef;
                    }
                    if ($dataCompletos) {
                        $grupoRisco = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                        $prazo = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRisco);
                        $dataReferenciaPrazo = $processo->getDataReferenciaFilaPublica($dataCompletos);
                        $dataLimite = $processo->calcularDataLimiteFilaPublica($dataCompletos, $prazo);
                        $diasRestantes = (int) round(\Carbon\Carbon::now()->diffInDays($dataLimite, false));
                        $prazoFilaPublica[$processo->id] = [
                            'prazo' => $prazo, 'dias_restantes' => $diasRestantes,
                            'atrasado' => $diasRestantes < 0, 'pausado' => $processo->status === 'parado',
                            'prazo_reiniciado' => $processo->prazoFilaPublicaFoiReiniciado($dataCompletos),
                            'data_referencia_prazo' => $dataReferenciaPrazo,
                        ];
                    }
                }
            }
        }

        $processos = new LengthAwarePaginator(
            $itensPagina, $processosCollection->count(), $perPage, $currentPage,
            ['path' => route('admin.processos.index-geral', [], false), 'query' => $request->query()]
        );

        return view('processos.index', compact(
            'processos', 'tiposProcesso', 'statusDisponiveis', 'anos', 
            'processosComPendencias', 'processosComDocsPendentes', 'processosComRespostasPendentes',
            'statusDocsObrigatorios', 'prazoFilaPublica', 'resumoQuick', 'setoresDisponiveis'
        ));
    }

    /**
     * Exibe a listagem geral de alertas vinculados aos processos
     */
    public function alertasProcessosIndex(Request $request)
    {
        $usuario = auth('interno')->user();

        $query = ProcessoAlerta::with([
            'processo.estabelecimento.municipioRelacionado',
            'processo.tipoProcesso',
            'usuarioCriador'
        ]);

        if ($request->filled('busca')) {
            $busca = trim($request->busca);
            $query->where(function ($q) use ($busca) {
                $q->where('descricao', 'ilike', '%' . $busca . '%')
                  ->orWhereHas('processo', function ($qp) use ($busca) {
                      $qp->where('numero_processo', 'ilike', '%' . $busca . '%')
                         ->orWhereHas('estabelecimento', function ($qe) use ($busca) {
                             $qe->where('nome_fantasia', 'ilike', '%' . $busca . '%')
                                ->orWhere('razao_social', 'ilike', '%' . $busca . '%')
                                ->orWhere('nome_completo', 'ilike', '%' . $busca . '%')
                                ->orWhere('cnpj', 'ilike', '%' . $busca . '%');
                         });
                  });
            });
        }

        if ($request->filled('estabelecimento_id')) {
            $query->whereHas('processo', function ($q) use ($request) {
                $q->where('estabelecimento_id', $request->estabelecimento_id);
            });
        }

        $alertasCollection = $query
            ->orderByRaw("CASE WHEN status != 'concluido' AND data_alerta < CURRENT_DATE THEN 0 ELSE 1 END")
            ->orderBy('data_alerta', 'asc')
            ->orderBy('created_at', 'desc')
            ->get();

        if (!$usuario->isAdmin()) {
            $alertasCollection = $alertasCollection->filter(function ($alerta) use ($usuario) {
                if (!$alerta->processo) {
                    return false;
                }

                return $this->processoPertenceAoEscopoUsuario($alerta->processo, $usuario);
            })->values();
        }

        $estatisticas = [
            'total' => $alertasCollection->count(),
            'pendentes' => $alertasCollection->where('status', '!=', 'concluido')->count(),
            'vencidos' => $alertasCollection->filter(function ($alerta) {
                return $alerta->status !== 'concluido' && $alerta->data_alerta && $alerta->data_alerta->isPast();
            })->count(),
            'concluidos' => $alertasCollection->where('status', 'concluido')->count(),
        ];

        if ($request->filled('status')) {
            if ($request->status === 'pendente') {
                $alertasCollection = $alertasCollection->where('status', '!=', 'concluido')->values();
            } elseif ($request->status === 'concluido') {
                $alertasCollection = $alertasCollection->where('status', 'concluido')->values();
            }
        }

        $estabelecimentos = $alertasCollection
            ->map(function ($alerta) {
                return $alerta->processo?->estabelecimento;
            })
            ->filter()
            ->unique('id')
            ->sortBy(function ($est) {
                return $est->nome_fantasia ?: $est->razao_social ?: $est->nome_completo;
            })
            ->values();

        $perPage = 15;
        $currentPage = LengthAwarePaginator::resolveCurrentPage();
        $itensPagina = $alertasCollection->forPage($currentPage, $perPage)->values();

        $alertas = new LengthAwarePaginator(
            $itensPagina,
            $alertasCollection->count(),
            $perPage,
            $currentPage,
            [
                'path' => route('admin.alertas-processos.index', [], false),
                'query' => $request->query(),
            ]
        );

        return view('processos.alertas', compact('alertas', 'estatisticas', 'estabelecimentos'));
    }

    /**
     * Exibe lista de documentos pendentes de aprovação
     */
    public function documentosPendentes(Request $request)
    {
        $usuario = auth('interno')->user();
        
        // Query para ProcessoDocumento pendentes
        $docsQuery = ProcessoDocumento::where('status_aprovacao', 'pendente')
            ->where('tipo_usuario', 'externo')
            ->with(['processo.estabelecimento', 'processo.tipoProcesso', 'usuarioExterno']);
        
        // Query para DocumentoResposta pendentes
        $respostasQuery = DocumentoResposta::where('status', 'pendente')
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'itemAtendimento', 'usuarioExterno']);
        
        // Filtrar por competência do usuário
        if (!$usuario->isAdmin()) {
            if ($usuario->isEstadual()) {
                // Estadual - filtra por competência manual ou null
                $docsQuery->whereHas('processo.estabelecimento', function($q) {
                    $q->where('competencia_manual', 'estadual')
                      ->orWhereNull('competencia_manual');
                });
                $respostasQuery->whereHas('documentoDigital.processo.estabelecimento', function($q) {
                    $q->where('competencia_manual', 'estadual')
                      ->orWhereNull('competencia_manual');
                });
            } elseif ($usuario->isMunicipal() && $usuario->municipio_id) {
                $docsQuery->whereHas('processo.estabelecimento', function($q) use ($usuario) {
                    $q->where('municipio_id', $usuario->municipio_id)
                        ->where(function ($sub) {
                            $sub->whereNull('competencia_manual')
                                ->orWhere('competencia_manual', 'municipal');
                        });
                });
                $respostasQuery->whereHas('documentoDigital.processo.estabelecimento', function($q) use ($usuario) {
                    $q->where('municipio_id', $usuario->municipio_id)
                        ->where(function ($sub) {
                            $sub->whereNull('competencia_manual')
                                ->orWhere('competencia_manual', 'municipal');
                        });
                });
            }
        }
        
        // Filtro por estabelecimento
        if ($request->filled('estabelecimento')) {
            $termo = $request->estabelecimento;
            $docsQuery->whereHas('processo.estabelecimento', function($q) use ($termo) {
                $q->where('nome_fantasia', 'like', "%{$termo}%")
                  ->orWhere('razao_social', 'like', "%{$termo}%")
                  ->orWhere('cnpj', 'like', "%{$termo}%");
            });
            $respostasQuery->whereHas('documentoDigital.processo.estabelecimento', function($q) use ($termo) {
                $q->where('nome_fantasia', 'like', "%{$termo}%")
                  ->orWhere('razao_social', 'like', "%{$termo}%")
                  ->orWhere('cnpj', 'like', "%{$termo}%");
            });
        }
        
        // Filtro por tipo de processo
        if ($request->filled('tipo_processo')) {
            $tipoProcesso = $request->tipo_processo;
            $docsQuery->whereHas('processo', function($q) use ($tipoProcesso) {
                $q->where('tipo', $tipoProcesso);
            });
            $respostasQuery->whereHas('documentoDigital.processo', function($q) use ($tipoProcesso) {
                $q->where('tipo', $tipoProcesso);
            });
        }
        
        $documentosPendentes = $docsQuery->orderBy('created_at', 'desc')->get();
        $respostasPendentes = $respostasQuery->orderBy('created_at', 'desc')->get();
        
        // Filtrar por competência em memória (lógica complexa baseada em atividades)
        if ($usuario->isEstadual()) {
            $documentosPendentes = $documentosPendentes->filter(fn($d) => $this->processoPertenceAoEscopoUsuario($d->processo, $usuario));
            $respostasPendentes = $respostasPendentes->filter(fn($r) => $this->processoPertenceAoEscopoUsuario($r->documentoDigital?->processo, $usuario));
        } elseif ($usuario->isMunicipal()) {
            $documentosPendentes = $documentosPendentes->filter(fn($d) => $this->processoPertenceAoEscopoUsuario($d->processo, $usuario));
            $respostasPendentes = $respostasPendentes->filter(fn($r) => $this->processoPertenceAoEscopoUsuario($r->documentoDigital?->processo, $usuario));
        }
        
        // Buscar tipos de processo ativos para o filtro
        $tiposProcesso = TipoProcesso::ativos()->ordenado()->get();
        
        return view('processos.documentos-pendentes', compact('documentosPendentes', 'respostasPendentes', 'tiposProcesso'));
    }

    /**
     * Exibe a lista de processos do estabelecimento
     */
    public function index($estabelecimentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        
        $processos = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->with(['usuario', 'tipoProcesso'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($processo) => $this->processoPertenceAoEscopoUsuario($processo, auth('interno')->user()))
            ->values();
        
        // Busca tipos de processo ativos e ordenados (filtrados por usuário)
        $tiposProcesso = TipoProcesso::ativos()
            ->paraUsuario(auth('interno')->user())
            ->ordenado()
            ->get()
            ->filter(fn ($tipo) => $this->tipoProcessoVisivelParaUsuarioNoEstabelecimento($tipo, $estabelecimento, auth('interno')->user()))
            ->values();
        
        return view('estabelecimentos.processos.index', compact('estabelecimento', 'processos', 'tiposProcesso'));
    }

    /**
     * Exibe formulário para criar novo processo
     */
    public function create($estabelecimentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $tiposProcesso = TipoProcesso::ativos()
            ->paraUsuario(auth('interno')->user())
            ->ordenado()
            ->get()
            ->filter(fn ($tipo) => $this->tipoProcessoVisivelParaUsuarioNoEstabelecimento($tipo, $estabelecimento, auth('interno')->user()))
            ->values();

        return view('estabelecimentos.processos.create', compact('estabelecimento', 'tiposProcesso'));
    }

    /**
     * Salva novo processo
     */
    public function store(Request $request, $estabelecimentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        
        // Busca códigos dos tipos ativos (filtrados por usuário)
        $codigosAtivos = TipoProcesso::ativos()
            ->paraUsuario(auth('interno')->user())
            ->ordenado()
            ->get()
            ->filter(fn ($tipo) => $this->tipoProcessoVisivelParaUsuarioNoEstabelecimento($tipo, $estabelecimento, auth('interno')->user()))
            ->pluck('codigo')
            ->toArray();
        
        $validated = $request->validate([
            'tipo' => 'required|in:' . implode(',', $codigosAtivos),
            'observacoes' => 'nullable|string|max:1000',
        ]);
        
        // Busca o tipo de processo para verificar se é anual
        $tipoProcesso = TipoProcesso::where('codigo', $validated['tipo'])->first();
        
        // Verifica se é processo anual e se já existe no ano atual
        if ($tipoProcesso && $tipoProcesso->anual) {
            $anoAtual = date('Y');
            $jaExiste = Processo::where('estabelecimento_id', $estabelecimento->id)
                ->where('tipo', $validated['tipo'])
                ->where('ano', $anoAtual)
                ->exists();
            
            if ($jaExiste) {
                return redirect()
                    ->back()
                    ->with('error', 'Já existe um processo de ' . $tipoProcesso->nome . ' para o ano ' . $anoAtual . ' neste estabelecimento.');
            }
        }

        // Verifica se é processo único por estabelecimento e se já existe (em qualquer ano)
        if ($tipoProcesso && $tipoProcesso->unico_por_estabelecimento) {
            $jaExisteUnico = Processo::where('estabelecimento_id', $estabelecimento->id)
                ->where('tipo', $validated['tipo'])
                ->exists();
            
            if ($jaExisteUnico) {
                return redirect()
                    ->back()
                    ->with('error', 'Este estabelecimento já possui um processo do tipo ' . $tipoProcesso->nome . '. Este tipo de processo é único e não pode ser aberto novamente.');
            }
        }
        
        // Usa transaction para evitar duplicação de número
        try {
            $processo = \DB::transaction(function () use ($estabelecimento, $validated, $tipoProcesso) {
                // Gera número do processo dentro da transaction
                $numeroData = Processo::gerarNumeroProcesso();
                
                // Prepara dados do processo
                $dadosProcesso = [
                    'estabelecimento_id' => $estabelecimento->id,
                    'usuario_id' => Auth::guard('interno')->user()->id,
                    'tipo' => $validated['tipo'],
                    'ano' => $numeroData['ano'],
                    'numero_sequencial' => $numeroData['numero_sequencial'],
                    'numero_processo' => $numeroData['numero_processo'],
                    'status' => 'aberto',
                    'observacoes' => $validated['observacoes'] ?? null,
                ];
                
                // Resolve o setor inicial considerando override municipal por município.
                $setorInicial = $tipoProcesso?->resolverSetorInicial($estabelecimento);
                if ($setorInicial) {
                    $dadosProcesso['setor_atual'] = $setorInicial->codigo;
                }
                
                // Cria o processo
                return Processo::create($dadosProcesso);
            });
            
            return redirect()
                ->route('admin.estabelecimentos.processos.index', $estabelecimento->id)
                ->with('success', 'Processo ' . $processo->numero_processo . ' criado com sucesso!');
        } catch (\Exception $e) {
            \Log::error('Erro ao criar processo', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'estabelecimento_id' => $estabelecimento->id,
                'tipo' => $validated['tipo'] ?? null
            ]);
            
            return redirect()
                ->back()
                ->with('error', 'Erro ao criar processo: ' . $e->getMessage());
        }
    }

    /**
     * Exibe detalhes de um processo
     */
    public function show($estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::with(['responsaveisLegais', 'responsaveisTecnicos'])->findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::with([
                'usuario', 
                'estabelecimento', 
                'tipoProcesso',
                'responsavelAtual',
                'responsavelAntesArquivar',
                'documentos' => function($query) {
                    $query->with(['documentoSubstituido', 'ordemServico', 'aprovadoPor'])->orderBy('created_at', 'desc');
                },
                'documentos.usuario', 
                'usuariosAcompanhando',
                'acompanhamentos'
            ])
            ->where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        // Busca modelos de documentos ativos
        $modelosDocumento = ModeloDocumento::with('tipoDocumento')
            ->disponiveisParaUsuario(auth('interno')->user())
            ->ativo()
            ->ordenado()
            ->get();
        
        // Busca documentos digitais do processo (incluindo rascunhos)
        // Inclui também documentos de lote (OS com múltiplos estabelecimentos) onde
        // o processo está no array processos_ids mas não tem processo_id individual
        $documentosDigitais = \App\Models\DocumentoDigital::with(['tipoDocumento', 'usuarioCriador', 'assinaturas.usuarioInterno', 'primeiraVisualizacao.usuarioExterno', 'respostas.usuarioExterno', 'respostas.avaliadoPor', 'respostas.itemAtendimento', 'itensAtendimento.respostaAtual.usuarioExterno', 'itensAtendimento.respostaAtual.avaliadoPor', 'ordemServico', 'usuarioProrrogouPrazo'])
            ->where(function ($q) use ($processoId) {
                $pid = (int) $processoId;
                $q->where('processo_id', $pid)
                  ->orWhereRaw("processos_ids::jsonb @> ?::jsonb", [json_encode([$pid])])
                  ->orWhereRaw("processos_ids::jsonb @> ?::jsonb", [json_encode([(string) $pid])]);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('id');

        // Filtra documentos de OS que estão vinculados a uma atividade específica de outro estabelecimento
        // Quando uma atividade da OS está vinculada a um estabelecimento específico (não "Todos/Geral"),
        // os documentos dessa atividade só devem aparecer no processo daquele estabelecimento.
        $estabelecimentoProcessoAtual = $processo?->estabelecimento_id ?? $estabelecimento->id;
        $documentosDigitais = $documentosDigitais->filter(function ($doc) use ($estabelecimentoProcessoAtual) {
            // Documentos sem OS ou sem atividade_index não são filtrados
            if (!$doc->os_id || $doc->atividade_index === null) {
                return true;
            }

            $os = $doc->ordemServico;
            if (!$os || !is_array($os->atividades_tecnicos)) {
                return true;
            }

            $atividade = $os->atividades_tecnicos[$doc->atividade_index] ?? null;
            if (!$atividade) {
                return true;
            }

            $estabelecimentoVinculado = $atividade['estabelecimento_id'] ?? null;

            // Se atividade está vinculada a "Todos/Geral" (null), mostra para todos
            if (empty($estabelecimentoVinculado)) {
                return true;
            }

            // Verifica se o estabelecimento vinculado está realmente na OS
            // (pode ter sido marcado um id inválido por bug anterior — nesse caso, trata como Todos/Geral)
            $estabelecimentosDaOs = collect();
            try {
                $estabelecimentosDaOs = $os->estabelecimentos->pluck('id');
            } catch (\Throwable $e) {
                $estabelecimentosDaOs = collect();
            }
            if ($os->estabelecimento_id) {
                $estabelecimentosDaOs->push($os->estabelecimento_id);
            }
            $estabelecimentosDaOs = $estabelecimentosDaOs->unique();

            if ($estabelecimentosDaOs->isNotEmpty() && !$estabelecimentosDaOs->contains((int) $estabelecimentoVinculado)) {
                // estabelecimento_id da atividade não pertence à OS — considera como Todos/Geral
                return true;
            }

            // Se atividade está vinculada a um estabelecimento específico válido,
            // só mostra no processo desse estabelecimento
            return (int) $estabelecimentoVinculado === (int) $estabelecimentoProcessoAtual;
        })->values();

        // Verifica se algum documento de notificação precisa ter o prazo iniciado automaticamente (§1º - 5 dias úteis)
        foreach ($documentosDigitais as $doc) {
            if ($doc->prazo_notificacao && !$doc->prazo_iniciado_em && $doc->todasAssinaturasCompletas()) {
                $doc->verificarInicioAutomaticoPrazo();
            }

            $doc->finalizarPrazoAutomaticamenteSeItensAtendidos(auth('interno')->id());
        }
        
        // Mescla documentos digitais e arquivos externos em uma única coleção ordenada por data
        $todosDocumentos = collect();
        
        // Adiciona documentos digitais com flag de tipo
        foreach ($documentosDigitais as $docDigital) {
            $todosDocumentos->push([
                'tipo' => 'digital',
                'documento' => $docDigital,
                'created_at' => $docDigital->created_at,
            ]);
        }
        
        // Adiciona arquivos externos (exceto documentos digitais e rejeitados que já foram substituídos)
        $documentosIds = $processo->documentos->pluck('id');
        $documentosSubstituidosIds = $processo->documentos->whereNotNull('documento_substituido_id')->pluck('documento_substituido_id');
        
        foreach ($processo->documentos->where('tipo_documento', '!=', 'documento_digital') as $arquivo) {
            // Não mostra documentos rejeitados que já foram substituídos
            if ($arquivo->status_aprovacao === 'rejeitado' && $documentosSubstituidosIds->contains($arquivo->id)) {
                continue;
            }
            $todosDocumentos->push([
                'tipo' => 'arquivo',
                'documento' => $arquivo,
                'created_at' => $arquivo->created_at,
            ]);
        }
        
        // Adiciona Ordens de Serviço vinculadas ao processo
        // Busca tanto pelo campo processo_id (legado) quanto pela tabela pivot
        $ordensServico = \App\Models\OrdemServico::where('processo_id', $processoId)
            ->orWhereHas('estabelecimentos', function ($query) use ($processoId) {
                $query->where('ordem_servico_estabelecimentos.processo_id', $processoId);
            })
            ->with(['estabelecimento', 'municipio'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('id'); // Evita duplicatas
        
        foreach ($ordensServico as $os) {
            $todosDocumentos->push([
                'tipo' => 'ordem_servico',
                'documento' => $os,
                'created_at' => $os->created_at,
            ]);
        }
        
        // Ordena por pasta (agrupado) e por data (mais recente primeiro)
        // Itens sem pasta ficam no topo, seguidos das pastas ordenadas
        $ordemPastas = $processo->pastas()
            ->orderBy('ordem')
            ->orderBy('nome')
            ->pluck('id')
            ->values()
            ->flip();

        $todosDocumentos = $todosDocumentos
            ->sort(function ($itemA, $itemB) use ($ordemPastas) {
                $pastaIdA = $itemA['documento']->pasta_id ?? null;
                $pastaIdB = $itemB['documento']->pasta_id ?? null;

                $ordemA = $pastaIdA ? ($ordemPastas[$pastaIdA] ?? 999999) : -1;
                $ordemB = $pastaIdB ? ($ordemPastas[$pastaIdB] ?? 999999) : -1;

                if ($ordemA !== $ordemB) {
                    return $ordemA <=> $ordemB;
                }

                return $itemB['created_at'] <=> $itemA['created_at'];
            })
            ->values();
        
        // Busca designações do processo
        $designacoes = ProcessoDesignacao::where('processo_id', $processoId)
            ->with(['usuarioDesignado', 'usuarioDesignador'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Busca alertas do processo
        $alertas = ProcessoAlerta::where('processo_id', $processoId)
            ->with('usuarioCriador')
            ->orderBy('data_alerta', 'asc')
            ->get();
        
        // Busca documentos obrigatórios baseados nas atividades do estabelecimento
        $documentosObrigatorios = $this->buscarDocumentosObrigatoriosParaProcesso($processo);

        // Monta documentos obrigatórios por pasta de unidade
        $documentosObrigatoriosPorUnidade = collect();

        // PJ Unidade Móvel: usa pastas protegidas (por município) com docs de escopo 'por_municipio'
        if ($processo->tipoProcesso && $processo->tipoProcesso->codigo === 'credenciamento_movel') {
            $documentosObrigatoriosPorUnidade = $this->buscarDocumentosObrigatoriosPorMunicipioUnidadeMovel($processo);
        } else {
            $pastasUnidade = $processo->pastas()->whereNotNull('unidade_id')->orderBy('ordem')->get();
            if ($pastasUnidade->count() > 0) {
                foreach ($pastasUnidade as $pasta) {
                    $docsUnidade = $documentosObrigatorios->map(function ($doc) use ($processo, $pasta) {
                        $docEnviado = $processo->documentos
                            ->where('tipo_documento_obrigatorio_id', $doc['id'])
                            ->where('pasta_id', $pasta->id)
                            ->sortByDesc('created_at')
                            ->first();

                        $statusEnvio = $docEnviado ? $docEnviado->status_aprovacao : null;
                        $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);

                        return array_merge($doc, [
                            'status' => $statusEnvio,
                            'ja_enviado' => $jaEnviado,
                            'status_envio' => $statusEnvio,
                            'pasta_id' => $pasta->id,
                            'unidade_id' => $pasta->unidade_id,
                        ]);
                    })->values();

                    $documentosObrigatoriosPorUnidade[$pasta->id] = [
                        'unidade' => $pasta->unidade,
                        'pasta' => $pasta,
                        'nome' => $pasta->nome,
                        'documentos' => $docsUnidade,
                        'total' => $docsUnidade->where('obrigatorio', true)->count(),
                        'enviados' => $docsUnidade->where('obrigatorio', true)->where('ja_enviado', true)->count(),
                        'aprovados' => $docsUnidade->where('obrigatorio', true)->where('status', 'aprovado')->count(),
                    ];
                }
            }
        }
        
        // Calcula informações do prazo de fila pública se aplicável
        $avisoFilaPublica = null;
        if ($processo->status !== 'arquivado' &&
            $processo->tipoProcesso && 
            $processo->tipoProcesso->exibir_fila_publica && 
            $processo->tipoProcesso->exibir_aviso_prazo_fila && 
            $processo->tipoProcesso->prazo_fila_publica > 0) {
            
            // Verifica se todos os documentos obrigatórios estão aprovados
            $todosAprovados = true;
            $dataDocumentosCompletos = null;
            
            // Filtra apenas os documentos obrigatórios
            $docsObrigatorios = $documentosObrigatorios->where('obrigatorio', true);
            
            // Se não há documentos obrigatórios configurados, considera como completo
            if ($docsObrigatorios->isEmpty()) {
                $todosAprovados = true;
                // Usa a data de criação do processo como base
                $dataDocumentosCompletos = $processo->created_at;
            } else {
                foreach ($docsObrigatorios as $docObrig) {
                    if ($docObrig['status'] !== 'aprovado') {
                        $todosAprovados = false;
                        break;
                    }
                    
                    // Pega a maior data de aprovação (usando tipo_documento_obrigatorio_id)
                    $docProcesso = $processo->documentos
                        ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                        ->where('status_aprovacao', 'aprovado')
                        ->sortByDesc(fn ($documento) => $documento->aprovado_em ?? $documento->updated_at)
                        ->first();

                    $dataReferenciaAprovacao = $docProcesso?->aprovado_em ?? $docProcesso?->updated_at;

                    if ($dataReferenciaAprovacao && (!$dataDocumentosCompletos || $dataReferenciaAprovacao > $dataDocumentosCompletos)) {
                        $dataDocumentosCompletos = $dataReferenciaAprovacao;
                    }
                }
            }
            
            if ($todosAprovados && $dataDocumentosCompletos) {
                $grupoRisco = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                $prazo = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRisco);
                $dataReferenciaPrazo = $processo->getDataReferenciaFilaPublica($dataDocumentosCompletos);
                $dataLimite = $processo->calcularDataLimiteFilaPublica($dataDocumentosCompletos, $prazo);
                $diasRestantes = (int) round(\Carbon\Carbon::now()->diffInDays($dataLimite, false));
                $atrasado = $diasRestantes < 0;
                
                $avisoFilaPublica = [
                    'prazo' => $prazo,
                    'data_documentos_completos' => $dataDocumentosCompletos,
                    'data_referencia_prazo' => $dataReferenciaPrazo,
                    'data_limite' => $dataLimite,
                    'dias_restantes' => $diasRestantes,
                    'atrasado' => $atrasado,
                    'pausado' => $processo->status === 'parado',
                    'prazo_reiniciado' => $processo->prazoFilaPublicaFoiReiniciado($dataDocumentosCompletos),
                ];
            }
        }

        // Calcula prazo por unidade (mesma lógica, mas verificando docs da pasta da unidade)
        $avisoFilaPublicaPorUnidade = collect();
        if ($processo->status !== 'arquivado' &&
            $processo->tipoProcesso &&
            $processo->tipoProcesso->exibir_fila_publica &&
            $processo->tipoProcesso->prazo_fila_publica > 0 &&
            $documentosObrigatoriosPorUnidade->isNotEmpty()) {

            foreach ($documentosObrigatoriosPorUnidade as $pastaId => $info) {
                // Pula pastas concluídas/deferidas
                $pasta = $info['pasta'] ?? null;
                if ($pasta && ($pasta->status ?? 'ativo') === 'concluida') {
                    continue;
                }

                $docsObrigUnidade = $info['documentos']->where('obrigatorio', true);
                if ($docsObrigUnidade->isEmpty()) continue;

                $todosAprovadosUnidade = true;
                $dataUltimoAprovadoUnidade = null;

                foreach ($docsObrigUnidade as $docObrig) {
                    if ($docObrig['status'] !== 'aprovado') {
                        $todosAprovadosUnidade = false;
                        break;
                    }
                    $docProcesso = $processo->documentos
                        ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                        ->where('pasta_id', $pastaId)
                        ->where('status_aprovacao', 'aprovado')
                        ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                        ->first();

                    $dataRef = $docProcesso?->aprovado_em ?? $docProcesso?->updated_at;
                    if ($dataRef && (!$dataUltimoAprovadoUnidade || $dataRef > $dataUltimoAprovadoUnidade)) {
                        $dataUltimoAprovadoUnidade = $dataRef;
                    }
                }

                $pastaModel = $pasta instanceof \App\Models\ProcessoPasta ? $pasta : \App\Models\ProcessoPasta::find($pastaId);

                if ($todosAprovadosUnidade && $dataUltimoAprovadoUnidade && $pastaModel) {
                    $grupoRiscoU = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                    $prazoU = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRiscoU);

                    // Considera parada/reinício do processo e da própria unidade (pasta ou vínculo)
                    $prazoCalculadoU = $processo->calcularPrazoFilaPublicaUnidade($pastaModel, $dataUltimoAprovadoUnidade, (int) $prazoU);

                    $avisoFilaPublicaPorUnidade[$pastaId] = array_merge($prazoCalculadoU, [
                        'nome' => $info['nome'],
                        'prazo' => $prazoU,
                        'data_documentos_completos' => $dataUltimoAprovadoUnidade,
                        'pasta' => $pastaModel,
                    ]);
                }
            }
        }
        
        // Verifica se o tipo de processo tem unidades configuradas e quais ainda podem ser adicionadas
        $tipoProcessoTemUnidades = false;
        $unidadesDisponiveis = collect();
        if ($processo->tipoProcesso) {
            $unidadesDoTipo = $processo->tipoProcesso->unidades()->ativas()->get();
            if ($unidadesDoTipo->isNotEmpty()) {
                $tipoProcessoTemUnidades = true;
                $unidadesDisponiveis = $unidadesDoTipo;
            }
        }

        // Define quem pode vincular documentos a obrigatórios:
        // - Administrador sempre pode
        // - Gestor estadual e técnico estadual podem quando o tipo de processo tem unidades
        $usuarioLogadoNivel = auth('interno')->user()->nivel_acesso->value ?? null;
        $podeVincularDocObrigatorio = auth('interno')->user()->isAdmin()
            || ($tipoProcessoTemUnidades && in_array($usuarioLogadoNivel, ['gestor_estadual', 'tecnico_estadual']));

        return view('estabelecimentos.processos.show', compact('estabelecimento', 'processo', 'modelosDocumento', 'documentosDigitais', 'todosDocumentos', 'designacoes', 'alertas', 'documentosObrigatorios', 'documentosObrigatoriosPorUnidade', 'avisoFilaPublica', 'avisoFilaPublicaPorUnidade', 'tipoProcessoTemUnidades', 'unidadesDisponiveis', 'podeVincularDocObrigatorio'));
    }

    /**
     * Gera PDF do processo na íntegra (todos os documentos compilados)
     */
    public function integra($estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::with('municipio')->findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        $processo = Processo::with([
            'usuario', 
            'estabelecimento.municipio', 
            'tipoProcesso',
            'documentos' => function($query) {
                // Exclui documentos rejeitados e do tipo documento_digital (já vem pelos documentosDigitais)
                $query->where(function($q) {
                    $q->whereNull('status_aprovacao')
                      ->orWhere('status_aprovacao', '!=', 'rejeitado');
                })
                ->where('tipo_documento', '!=', 'documento_digital')
                ->orderBy('created_at', 'asc');
            }
        ])
        ->where('estabelecimento_id', $estabelecimentoId)
        ->findOrFail($processoId);
        
        // Busca documentos digitais do processo (apenas assinados) — inclui documentos de lote
        $documentosDigitais = \App\Models\DocumentoDigital::with(['tipoDocumento', 'usuarioCriador', 'assinaturas', 'ordemServico', 'respostas' => function($query) {
                $query->where('status', '!=', 'rejeitado')->orderBy('created_at', 'asc');
            }])
            ->where(function ($q) use ($processoId) {
                $pid = (int) $processoId;
                $q->where('processo_id', $pid)
                  ->orWhereRaw("processos_ids::jsonb @> ?::jsonb", [json_encode([$pid])]);
            })
            ->where('status', 'assinado')
            ->orderBy('created_at', 'asc')
            ->get()
            ->unique('id');

        // Filtra documentos de OS vinculados a estabelecimento específico (mesma lógica do show)
        $estabelecimentoProcessoAtual = $processo?->estabelecimento_id ?? $estabelecimentoId;
        $documentosDigitais = $documentosDigitais->filter(function ($doc) use ($estabelecimentoProcessoAtual) {
            if (!$doc->os_id || $doc->atividade_index === null) {
                return true;
            }
            $os = $doc->ordemServico;
            if (!$os || !is_array($os->atividades_tecnicos)) {
                return true;
            }
            $atividade = $os->atividades_tecnicos[$doc->atividade_index] ?? null;
            if (!$atividade) {
                return true;
            }
            $estabelecimentoVinculado = $atividade['estabelecimento_id'] ?? null;
            if (empty($estabelecimentoVinculado)) {
                return true;
            }

            // Verifica se o estabelecimento vinculado está realmente na OS
            $estabelecimentosDaOs = collect();
            try {
                $estabelecimentosDaOs = $os->estabelecimentos->pluck('id');
            } catch (\Throwable $e) {
                $estabelecimentosDaOs = collect();
            }
            if ($os->estabelecimento_id) {
                $estabelecimentosDaOs->push($os->estabelecimento_id);
            }
            $estabelecimentosDaOs = $estabelecimentosDaOs->unique();

            if ($estabelecimentosDaOs->isNotEmpty() && !$estabelecimentosDaOs->contains((int) $estabelecimentoVinculado)) {
                return true;
            }

            return (int) $estabelecimentoVinculado === (int) $estabelecimentoProcessoAtual;
        })->values();
        
        // Busca ordens de serviço vinculadas ao processo (campo legado + tabela pivot)
        $ordensServico = \App\Models\OrdemServico::with(['estabelecimento.municipio', 'municipio', 'processo'])
            ->where(function ($query) use ($processoId) {
                $query->where('processo_id', $processoId)
                    ->orWhereHas('estabelecimentos', function ($q) use ($processoId) {
                        $q->where('ordem_servico_estabelecimentos.processo_id', $processoId);
                    });
            })
            ->where('status', '!=', 'cancelada')
            ->orderBy('created_at', 'asc')
            ->get()
            ->unique('id');
        
        // Determina qual logomarca usar (mesma lógica dos PDFs)
        $logomarca = null;
        if ($estabelecimento->isCompetenciaEstadual()) {
            $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
        } elseif ($estabelecimento->municipio_id && ($municipioLogo = \App\Models\Municipio::find($estabelecimento->municipio_id))) {
            // Obs.: $estabelecimento->municipio devolve a coluna texto, não a relação
            if (!empty($municipioLogo->logomarca)) {
                $logomarca = $municipioLogo->logomarca;
            } else {
                $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
            }
        } else {
            $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
        }
        
        // Prepara dados para o PDF
        $data = [
            'estabelecimento' => $estabelecimento,
            'processo' => $processo,
            'documentosDigitais' => $documentosDigitais,
            'ordensServico' => $ordensServico,
            'logomarca' => $logomarca,
        ];
        
        // Gera o PDF inicial (capa + dados)
        $pdf = Pdf::loadView('estabelecimentos.processos.integra-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 15)
            ->setOption('margin-right', 15);
        
        // Nome do arquivo (remove caracteres inválidos)
        $numeroProcessoLimpo = str_replace(['/', '\\'], '_', $processo->numero_processo ?? 'sem_numero');
        $nomeArquivo = 'processo_integra_' . $numeroProcessoLimpo . '.pdf';
        
        // Salva o PDF inicial temporariamente
        $pdfInicial = $pdf->output();
        $tempInicial = storage_path('app/temp_integra_inicial.pdf');
        file_put_contents($tempInicial, $pdfInicial);
        
        // Mescla com os PDFs dos documentos digitais, respostas, arquivos anexados e ordens de serviço
        try {
            $fpdi = new \setasign\Fpdi\Fpdi();
            
            // Adiciona páginas do PDF inicial (capa)
            $pageCount = $fpdi->setSourceFile($tempInicial);
            for ($i = 1; $i <= $pageCount; $i++) {
                $template = $fpdi->importPage($i);
                $size = $fpdi->getTemplateSize($template);
                $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $fpdi->useTemplate($template);
            }
            
            // === MESCLAGEM EM ORDEM CRONOLÓGICA ===
            // Cria lista unificada de todos os itens com data para ordenar
            $itensParaMesclar = collect();

            // Documentos digitais (com suas respostas logo após)
            foreach ($documentosDigitais as $doc) {
                if ($doc->arquivo_pdf && Storage::disk('public')->exists($doc->arquivo_pdf)) {
                    $itensParaMesclar->push([
                        'tipo' => 'digital',
                        'data' => $doc->created_at,
                        'path' => storage_path('app/public/' . $doc->arquivo_pdf),
                        'label' => $doc->numero_documento,
                    ]);
                }
                // Respostas do documento
                foreach ($doc->respostas as $resposta) {
                    if ($resposta->caminho && Storage::disk('public')->exists($resposta->caminho)) {
                        $extensaoResposta = strtolower(pathinfo($resposta->nome_arquivo ?? $resposta->nome_original, PATHINFO_EXTENSION));
                        if ($extensaoResposta === 'pdf') {
                            $itensParaMesclar->push([
                                'tipo' => 'resposta',
                                'data' => $resposta->created_at,
                                'path' => storage_path('app/public/' . $resposta->caminho),
                                'label' => $resposta->nome_original,
                            ]);
                        }
                    }
                }
            }

            // Arquivos anexados (internos e externos)
            foreach ($processo->documentos as $documento) {
                $extensao = strtolower($documento->extensao ?? pathinfo($documento->nome_arquivo ?? '', PATHINFO_EXTENSION));
                if ($extensao !== 'pdf' || empty($documento->caminho)) continue;

                $pdfPath = null;
                if ($documento->tipo_usuario === 'externo') {
                    if (Storage::disk('public')->exists($documento->caminho)) {
                        $pdfPath = storage_path('app/public/' . $documento->caminho);
                    }
                } else {
                    // Documento interno
                    $caminhoCompleto = storage_path('app/' . $documento->caminho);
                    if (file_exists($caminhoCompleto)) {
                        $pdfPath = $caminhoCompleto;
                    } elseif (Storage::disk('public')->exists($documento->caminho)) {
                        $pdfPath = storage_path('app/public/' . $documento->caminho);
                    }
                }

                if ($pdfPath && file_exists($pdfPath)) {
                    $itensParaMesclar->push([
                        'tipo' => 'arquivo',
                        'data' => $documento->created_at,
                        'path' => $pdfPath,
                        'label' => $documento->nome_original,
                    ]);
                }
            }

            // Ordena tudo por data (mais antigo primeiro)
            $itensParaMesclar = $itensParaMesclar->sortBy('data')->values();

            // Mescla todos os PDFs na ordem cronológica
            foreach ($itensParaMesclar as $item) {
                $pathParaMesclar = $item['path'];
                $tempConvertido = null;

                try {
                    $docPageCount = $fpdi->setSourceFile($pathParaMesclar);
                } catch (\Exception $e) {
                    // PDF incompatível com FPDI — tenta converter usando Ghostscript
                    \Log::info('Convertendo PDF incompatível com Ghostscript: ' . ($item['label'] ?? ''), [
                        'erro_original' => $e->getMessage()
                    ]);

                    $tempConvertido = storage_path('app/temp_gs_' . uniqid() . '.pdf');
                    $cmdGs = sprintf(
                        'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/default -sOutputFile=%s %s 2>&1',
                        escapeshellarg($tempConvertido),
                        escapeshellarg($pathParaMesclar)
                    );
                    exec($cmdGs, $output, $exitCode);

                    if ($exitCode === 0 && file_exists($tempConvertido) && filesize($tempConvertido) > 0) {
                        $pathParaMesclar = $tempConvertido;
                        try {
                            $docPageCount = $fpdi->setSourceFile($pathParaMesclar);
                        } catch (\Exception $e2) {
                            \Log::warning('Erro mesmo após conversão Ghostscript: ' . ($item['label'] ?? ''), [
                                'erro' => $e2->getMessage()
                            ]);
                            @unlink($tempConvertido);
                            continue;
                        }
                    } else {
                        \Log::warning('Falha ao converter PDF com Ghostscript: ' . ($item['label'] ?? ''), [
                            'output' => implode("\n", $output ?? []),
                        ]);
                        @unlink($tempConvertido);
                        continue;
                    }
                }

                try {
                    for ($i = 1; $i <= $docPageCount; $i++) {
                        $template = $fpdi->importPage($i);
                        $size = $fpdi->getTemplateSize($template);
                        $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $fpdi->useTemplate($template);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Erro ao importar páginas do PDF: ' . ($item['label'] ?? ''), [
                        'erro' => $e->getMessage()
                    ]);
                } finally {
                    if ($tempConvertido && file_exists($tempConvertido)) {
                        @unlink($tempConvertido);
                    }
                }
            }
            
            // Adiciona PDFs das ordens de serviço vinculadas (exceto canceladas)
            foreach ($ordensServico as $os) {
                $tempOs = null;
                $tempOsConvertido = null;
                try {
                    $html = view('ordens-servico.pdf', ['ordemServico' => $os])->render();
                    $osPdf = Pdf::loadHTML($html)
                        ->setPaper('a4')
                        ->setOption('margin-top', 10)
                        ->setOption('margin-bottom', 10)
                        ->setOption('margin-left', 10)
                        ->setOption('margin-right', 10);

                    $tempOs = storage_path('app/temp_os_' . $os->id . '.pdf');
                    file_put_contents($tempOs, $osPdf->output());

                    $pathOs = $tempOs;

                    try {
                        $osPageCount = $fpdi->setSourceFile($pathOs);
                    } catch (\Exception $eFpdi) {
                        // Tenta converter com Ghostscript
                        $tempOsConvertido = storage_path('app/temp_os_gs_' . $os->id . '.pdf');
                        $cmdGs = sprintf(
                            'gs -dBATCH -dNOPAUSE -q -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -sOutputFile=%s %s 2>&1',
                            escapeshellarg($tempOsConvertido),
                            escapeshellarg($pathOs)
                        );
                        exec($cmdGs, $outGs, $codeGs);

                        if ($codeGs === 0 && file_exists($tempOsConvertido) && filesize($tempOsConvertido) > 0) {
                            $pathOs = $tempOsConvertido;
                            $osPageCount = $fpdi->setSourceFile($pathOs);
                        } else {
                            throw $eFpdi;
                        }
                    }

                    for ($i = 1; $i <= $osPageCount; $i++) {
                        $template = $fpdi->importPage($i);
                        $size = $fpdi->getTemplateSize($template);
                        $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                        $fpdi->useTemplate($template);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Erro ao adicionar PDF da OS #' . $os->numero, [
                        'erro' => $e->getMessage()
                    ]);
                } finally {
                    if ($tempOs && file_exists($tempOs)) @unlink($tempOs);
                    if ($tempOsConvertido && file_exists($tempOsConvertido)) @unlink($tempOsConvertido);
                }
            }
            
            // Remove arquivo temporário
            @unlink($tempInicial);
            
            // Retorna o PDF mesclado
            return response($fpdi->Output('S', $nomeArquivo))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', 'attachment; filename="' . $nomeArquivo . '"');
                
        } catch (\Exception $e) {
            // Se falhar a mesclagem, retorna apenas o PDF inicial
            \Log::error('Erro ao mesclar PDFs: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            @unlink($tempInicial);
            return $pdf->download($nomeArquivo);
        }
    }

    /**
     * Adiciona/Remove acompanhamento do processo
     */
    public function toggleAcompanhamento(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $usuarioId = Auth::guard('interno')->user()->id;
        
        $acompanhamento = ProcessoAcompanhamento::where('processo_id', $processoId)
            ->where('usuario_interno_id', $usuarioId)
            ->first();
        
        if ($acompanhamento) {
            // Remove acompanhamento
            $acompanhamento->delete();
            $mensagem = 'Você parou de acompanhar este processo.';
        } else {
            // Adiciona acompanhamento com descrição opcional
            ProcessoAcompanhamento::create([
                'processo_id' => $processoId,
                'usuario_interno_id' => $usuarioId,
                'descricao' => $request->input('descricao'),
            ]);
            $mensagem = 'Você está acompanhando este processo.';
        }
        
        return redirect()->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', $mensagem);
    }

    /**
     * Atualiza a descrição do acompanhamento
     */
    public function atualizarDescricaoAcompanhamento(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $usuarioId = Auth::guard('interno')->user()->id;

        $acompanhamento = ProcessoAcompanhamento::where('processo_id', $processoId)
            ->where('usuario_interno_id', $usuarioId)
            ->firstOrFail();

        $acompanhamento->update([
            'descricao' => $request->input('descricao'),
        ]);

        return redirect()->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Descrição do acompanhamento atualizada.');
    }

    /**
     * Atualiza o status do processo
     */
    public function updateStatus(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $validated = $request->validate([
            'status' => 'required|in:' . implode(',', array_keys(Processo::statusDisponiveis())),
        ]);
        
        $processo->update(['status' => $validated['status']]);
        
        return redirect()
            ->back()
            ->with('success', 'Status do processo atualizado com sucesso!');
    }

    /**
     * Remove um processo e todos os arquivos vinculados
     * APENAS ADMINISTRADOR pode excluir processos
     */
    public function destroy($estabelecimentoId, $processoId)
    {
        // Verifica se o usuário é administrador
        $usuario = auth('interno')->user();
        if (!$usuario->isAdmin()) {
            return redirect()
                ->back()
                ->with('error', 'Apenas administradores podem excluir processos.');
        }

        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $numeroProcesso = $processo->numero_processo;
        
        // Busca todos os documentos do processo
        $documentos = ProcessoDocumento::where('processo_id', $processoId)->get();
        
        // Exclui os arquivos físicos do storage
        foreach ($documentos as $documento) {
            if ($documento->caminho) {
                $caminhoCompleto = storage_path('app/' . $documento->caminho);
                if (file_exists($caminhoCompleto)) {
                    unlink($caminhoCompleto);
                }
            }
        }
        
        // Remove o diretório do processo se existir
        $diretorioProcesso = storage_path('app/processos/' . $processoId);
        if (is_dir($diretorioProcesso)) {
            // Remove arquivos restantes no diretório
            $arquivos = glob($diretorioProcesso . '/*');
            foreach ($arquivos as $arquivo) {
                if (is_file($arquivo)) {
                    unlink($arquivo);
                }
            }
            // Remove o diretório
            @rmdir($diretorioProcesso);
        }
        
        // Exclui os registros de documentos do banco
        ProcessoDocumento::where('processo_id', $processoId)->delete();
        
        // Exclui o processo
        $processo->delete();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.index', $estabelecimentoId)
            ->with('success', 'Processo ' . $numeroProcesso . ' e todos os arquivos vinculados foram removidos com sucesso!');
    }

    /**
     * Upload de arquivo para o processo
     */
    public function uploadArquivo(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        // Verificar se o arquivo foi enviado corretamente
        if (!$request->hasFile('arquivo')) {
            $uploadMax = ini_get('upload_max_filesize');
            $postMax = ini_get('post_max_size');
            return redirect()
                ->back()
                ->with('error', "Nenhum arquivo foi enviado. O limite atual do servidor é: upload_max_filesize={$uploadMax}, post_max_size={$postMax}. Arquivos maiores que esses limites são descartados automaticamente. Peça ao administrador para aumentar esses valores no php.ini.");
        }
        
        $arquivo = $request->file('arquivo');
        
        // Verificar se houve erro no upload
        if (!$arquivo->isValid()) {
            $erros = [
                UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite máximo permitido pelo servidor (' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite máximo permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload foi feito parcialmente. Tente novamente.',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Erro no servidor: pasta temporária não encontrada.',
                UPLOAD_ERR_CANT_WRITE => 'Erro no servidor: falha ao gravar o arquivo.',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por uma extensão do PHP.',
            ];
            $codigoErro = $arquivo->getError();
            $mensagemErro = $erros[$codigoErro] ?? 'Erro desconhecido no upload (código: ' . $codigoErro . ').';
            
            return redirect()
                ->back()
                ->with('error', 'Falha no upload: ' . $mensagemErro);
        }
        
        $request->validate([
            'arquivo' => 'required|file|mimes:pdf|max:10240', // 10MB max
        ], [
            'arquivo.required' => 'Selecione um arquivo para upload.',
            'arquivo.mimes' => 'Apenas arquivos PDF são permitidos.',
            'arquivo.max' => 'O arquivo não pode ser maior que 10MB.',
        ]);
        
        try {
            $nomeOriginal = $arquivo->getClientOriginalName();
            $extensao = $arquivo->getClientOriginalExtension();
            $tamanho = $arquivo->getSize();
            
            // Gera nome único para o arquivo
            $nomeArquivo = Str::slug(pathinfo($nomeOriginal, PATHINFO_FILENAME)) . '_' . time() . '.' . $extensao;
            
            // Define o diretório com DIRECTORY_SEPARATOR
            $diretorio = 'processos' . DIRECTORY_SEPARATOR . $processoId;
            
            // Garante que o diretório existe (cria recursivamente se necessário)
            $caminhoCompleto = storage_path('app') . DIRECTORY_SEPARATOR . $diretorio;
            if (!file_exists($caminhoCompleto)) {
                mkdir($caminhoCompleto, 0755, true);
            }
            
            // Move o arquivo manualmente para garantir que funcione
            $caminhoArquivo = $caminhoCompleto . DIRECTORY_SEPARATOR . $nomeArquivo;
            $arquivo->move($caminhoCompleto, $nomeArquivo);
            
            // Verifica se o arquivo foi realmente salvo
            if (!file_exists($caminhoArquivo)) {
                throw new \Exception('Falha ao salvar o arquivo. Caminho tentado: ' . $caminhoArquivo);
            }
            
            // Caminho relativo para salvar no banco (com barras normais)
            $caminhoRelativo = 'processos/' . $processoId . '/' . $nomeArquivo;

            // Determinar tipo e nome de exibição
            $tipoSelecionado = $request->input('tipo_documento', 'Arquivo Externo');
            $tipoSlug = Str::slug($tipoSelecionado, '_');
            
            // Lógica para nome visual e tipo
            if ($tipoSelecionado === 'Arquivo Externo' || $tipoSelecionado === 'Usar nome do arquivo') {
                $tipoSlug = 'arquivo_externo';
                $nomeVisual = $nomeOriginal;
            } else {
                // Se escolheu um tipo específico (ex: Termo de Vistoria), usa esse nome
                $nomeVisual = $tipoSelecionado . '.' . $extensao;
            }
            
            // Cria registro no banco
            ProcessoDocumento::create([
                'processo_id' => $processoId,
                'usuario_id' => Auth::id(),
                'tipo_usuario' => 'interno',
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeVisual,
                'caminho' => $caminhoRelativo,
                'extensao' => $extensao,
                'tamanho' => $tamanho,
                'tipo_documento' => $tipoSlug,
            ]);
            
            return redirect()
                ->back()
                ->with('success', 'Arquivo enviado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erro ao fazer upload do arquivo: ' . $e->getMessage());
        }
    }

    /**
     * Visualizar arquivo do processo
     */
    public function visualizarArquivo($estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $pid = (int) $processoId;

        // Busca como documento digital — inclui documentos de lote (processos_ids)
        // e documentos vinculados ao processo via OS (Ordem de Serviço)
        $docDigital = \App\Models\DocumentoDigital::where('id', $documentoId)
            ->where(function ($q) use ($pid) {
                $q->where('processo_id', $pid)
                  ->orWhereRaw("processos_ids::jsonb @> ?::jsonb", [json_encode([$pid])])
                  ->orWhereIn('os_id', function ($sub) use ($pid) {
                      $sub->select('id')->from('ordens_servico')->where('processo_id', $pid);
                  })
                  ->orWhereIn('os_id', function ($sub) use ($pid) {
                      $sub->select('ordem_servico_id')->from('ordem_servico_estabelecimentos')->where('processo_id', $pid);
                  });
            })
            ->first();

        if ($docDigital) {
            $documentoAssinadoCompleto = $docDigital->status === 'assinado' && $docDigital->todasAssinaturasCompletas();

            // Para documentos de lote: se tem PDF gerado, serve direto
            // (o PDF do original é genérico — sem variáveis substituídas)
            // Para visualização personalizada por estabelecimento, gera preview dinâmico
            $isLote = !empty($docDigital->processos_ids) && count($docDigital->processos_ids) > 1;

            // Documentos criados em OS (Ordem de Serviço) são genéricos por natureza —
            // o conteúdo é o mesmo, mas o cabeçalho precisa ser do estabelecimento atual
            $ehDocumentoDeOS = !empty($docDigital->os_id);

            // Documento de OS assinado: gera PDF assinado dinâmico com cabeçalho do estabelecimento atual
            if ($ehDocumentoDeOS && $documentoAssinadoCompleto) {
                $processo = \App\Models\Processo::with([
                    'tipoProcesso',
                    'estabelecimento.responsaveis',
                    'estabelecimento.municipio',
                    'estabelecimento.municipioRelacionado',
                ])->find($pid);

                $estabelecimentoDoc = $processo?->estabelecimento ?? $estabelecimento;

                // Gera código de autenticidade se ainda não tiver
                if (empty($docDigital->codigo_autenticidade)) {
                    $docDigital->codigo_autenticidade = \App\Models\DocumentoDigital::gerarCodigoAutenticidade();
                    $docDigital->save();
                }

                $urlAutenticidade = route('verificar.autenticidade', ['codigo' => $docDigital->codigo_autenticidade]);
                $qrCode = new \Endroid\QrCode\QrCode($urlAutenticidade);
                $writer = new \Endroid\QrCode\Writer\PngWriter();
                $qrCodeBase64 = base64_encode($writer->write($qrCode)->getString());

                // Determina logomarca
                $logomarca = null;
                if ($estabelecimentoDoc) {
                    if ($estabelecimentoDoc->isCompetenciaEstadual()) {
                        $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                    } elseif ($estabelecimentoDoc->municipio_id) {
                        $municipio = $estabelecimentoDoc->relationLoaded('municipioRelacionado') && $estabelecimentoDoc->municipioRelacionado
                            ? $estabelecimentoDoc->municipioRelacionado
                            : \App\Models\Municipio::find($estabelecimentoDoc->municipio_id);
                        $logomarca = ($municipio && !empty($municipio->logomarca))
                            ? $municipio->logomarca
                            : \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                    } else {
                        $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                    }
                } else {
                    $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                }

                // Carrega assinaturas
                $docDigital->load(['tipoDocumento', 'assinaturas' => function($q) {
                    $q->where('status', 'assinado')->orderBy('ordem');
                }, 'assinaturas.usuarioInterno']);

                $pdf = Pdf::loadView('documentos.pdf-assinado', [
                    'documento' => $docDigital,
                    'estabelecimento' => $estabelecimentoDoc,
                    'processo' => $processo,
                    'assinaturas' => $docDigital->assinaturas,
                    'urlAutenticidade' => $urlAutenticidade,
                    'codigoAutenticidade' => $docDigital->codigo_autenticidade,
                    'qrCodeBase64' => $qrCodeBase64,
                    'logomarca' => $logomarca,
                ])
                    ->setPaper('a4', 'portrait')
                    ->setOption('margin-top', 10)
                    ->setOption('margin-bottom', 10)
                    ->setOption('margin-left', 15)
                    ->setOption('margin-right', 15);

                return $pdf->stream(($docDigital->numero_documento ?? 'documento') . '.pdf');
            }

            if ($docDigital->arquivo_pdf && $documentoAssinadoCompleto && !$isLote) {
                // Documento individual finalizado: serve o PDF gerado
                $caminhoCompleto = storage_path('app/public') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $docDigital->arquivo_pdf);

                if (!file_exists($caminhoCompleto)) {
                    abort(404, 'PDF não encontrado');
                }

                return response()->file($caminhoCompleto, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="documento.pdf"'
                ]);
            }

            // Documento de lote ou não finalizado: gera preview com dados do estabelecimento do processo atual
            $processo = \App\Models\Processo::with([
                'tipoProcesso',
                'estabelecimento.responsaveis',
                'estabelecimento.municipio',
                'estabelecimento.municipioRelacionado',
            ])->find($pid);

            $estabelecimentoDoc = $processo?->estabelecimento ?? $estabelecimento;

            // Para documentos de lote, substitui variáveis com dados do estabelecimento atual
            $conteudoParaExibir = $docDigital->conteudo;
            if ($isLote && $processo && $estabelecimentoDoc) {
                $conteudoParaExibir = app(\App\Http\Controllers\DocumentoDigitalController::class)
                    ->substituirVariaveis($docDigital->conteudo, $estabelecimentoDoc, $processo);
            }

            // Clona o documento com conteúdo substituído para o preview
            $docParaPreview = clone $docDigital;
            $docParaPreview->conteudo = $conteudoParaExibir;

            // Determina logomarca
            $logomarca = null;
            if ($estabelecimentoDoc) {
                if ($estabelecimentoDoc->isCompetenciaEstadual()) {
                    $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                } elseif ($estabelecimentoDoc->municipio_id) {
                    $municipio = $estabelecimentoDoc->relationLoaded('municipioRelacionado') && $estabelecimentoDoc->municipioRelacionado
                        ? $estabelecimentoDoc->municipioRelacionado
                        : \App\Models\Municipio::find($estabelecimentoDoc->municipio_id);
                    $logomarca = ($municipio && !empty($municipio->logomarca))
                        ? $municipio->logomarca
                        : \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                } else {
                    $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
                }
            } else {
                $logomarca = \App\Models\ConfiguracaoSistema::logomarcaEstadual();
            }

            $pdf = Pdf::loadView('documentos.pdf-preview', [
                'documento' => $docParaPreview,
                'processo' => $processo,
                'estabelecimento' => $estabelecimentoDoc,
                'logomarca' => $logomarca,
            ])
                ->setPaper('a4')
                ->setOption('margin-top', 10)
                ->setOption('margin-bottom', 10)
                ->setOption('margin-left', 10)
                ->setOption('margin-right', 10);

            return $pdf->stream(($docDigital->numero_documento ?? 'documento') . '_preview.pdf');
        }
        
        // Senão, busca como arquivo externo
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);
        
        // Verifica se é documento digital ou arquivo externo
        // Arquivos externos enviados por usuários externos são salvos em public
        if ($documento->tipo_documento === 'documento_digital' || $documento->tipo_usuario === 'externo') {
            $caminhoCompleto = storage_path('app/public') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento->caminho);
        } else {
            $caminhoCompleto = storage_path('app') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento->caminho);
        }

        // Se o documento foi aprovado e possui versão carimbada com validação, serve a versão carimbada
        if ($documento->isAprovado() && $documento->temVersaoCarimbada()) {
            $caminhoCarimbado = app(\App\Services\CarimboValidacaoService::class)->resolverCaminhoCarimbado($documento);
            if ($caminhoCarimbado) {
                $caminhoCompleto = $caminhoCarimbado;
            }
        }
        
        if (!file_exists($caminhoCompleto)) {
            abort(404, 'Arquivo não encontrado: ' . $documento->caminho);
        }
        
        // Detecta o tipo MIME correto
        $mimeType = mime_content_type($caminhoCompleto);
        
        // Retorna o arquivo para visualização inline com headers corretos
        return response()->file($caminhoCompleto, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $documento->nome_original . '"'
        ]);
    }

    /**
     * Download de arquivo do processo
     */
    public function downloadArquivo($estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);
        
        // Verifica se é documento digital ou arquivo externo
        // Arquivos externos enviados por usuários externos são salvos em public
        if ($documento->tipo_documento === 'documento_digital' || $documento->tipo_usuario === 'externo') {
            $caminhoCompleto = storage_path('app/public') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento->caminho);
        } else {
            $caminhoCompleto = storage_path('app') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento->caminho);
        }

        // Se o documento foi aprovado e possui versão carimbada com validação, serve a versão carimbada
        if ($documento->isAprovado() && $documento->temVersaoCarimbada()) {
            $caminhoCarimbado = app(\App\Services\CarimboValidacaoService::class)->resolverCaminhoCarimbado($documento);
            if ($caminhoCarimbado) {
                $caminhoCompleto = $caminhoCarimbado;
            }
        }
        
        if (!file_exists($caminhoCompleto)) {
            abort(404, 'Arquivo não encontrado.');
        }
        
        return response()->download($caminhoCompleto, $documento->nome_original);
    }

    /**
     * Atualiza o nome do arquivo
     */
    public function updateNomeArquivo(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);
        
        $request->validate([
            'nome_original' => 'required|string|max:255',
        ], [
            'nome_original.required' => 'O nome do arquivo é obrigatório.',
            'nome_original.max' => 'O nome do arquivo não pode ter mais de 255 caracteres.',
        ]);
        
        try {
            $documento->update([
                'nome_original' => $request->nome_original,
            ]);
            
            return redirect()
                ->back()
                ->with('success', 'Nome do arquivo atualizado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erro ao atualizar nome do arquivo: ' . $e->getMessage());
        }
    }

    /**
     * Remove arquivo do processo (requer senha de assinatura)
     */
    public function deleteArquivo(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);
        
        $usuario = auth('interno')->user();
        $processo = Processo::findOrFail($processoId);
        
        // Valida senha de assinatura
        if (!$usuario->temSenhaAssinatura()) {
            return response()->json([
                'success' => false, 
                'message' => 'Você precisa configurar sua senha de assinatura primeiro.'
            ], 400);
        }

        $senhaAssinatura = $request->input('senha_assinatura');
        
        if (!$senhaAssinatura || !Hash::check($senhaAssinatura, $usuario->senha_assinatura_digital)) {
            return response()->json([
                'success' => false, 
                'message' => 'Senha de assinatura incorreta.'
            ], 400);
        }
        
        try {
            $nomeArquivo = $documento->nome_original;
            
            // Registra no histórico antes de excluir
            ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => $usuario->id,
                'tipo_evento' => 'documento_excluido',
                'titulo' => 'Arquivo Excluído',
                'descricao' => $nomeArquivo,
                'dados_adicionais' => [
                    'nome_arquivo' => $nomeArquivo,
                    'tipo_usuario' => $documento->tipo_usuario,
                    'excluido_por' => $usuario->nome,
                ]
            ]);
            
            // Remove arquivo físico
            $caminhoCompleto = storage_path('app') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $documento->caminho);
            if (file_exists($caminhoCompleto)) {
                unlink($caminhoCompleto);
            }
            
            // Remove registro do banco
            $documento->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Arquivo removido com sucesso!'
            ]);
                
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover arquivo: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Aprova documento enviado por usuário externo
     */
    public function aprovarDocumento($estabelecimentoId, $processoId, $documentoId)
    {
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->where('status_aprovacao', 'pendente')
            ->findOrFail($documentoId);
        
        $documento->update([
            'status_aprovacao' => 'aprovado',
            'aprovado_por' => auth('interno')->id(),
            'aprovado_em' => now(),
        ]);

        // Carimba o PDF com faixa de validação + QR Code, se o tipo de documento exigir
        $avisoCarimbo = null;
        $documento->load(['tipoDocumentoObrigatorio', 'aprovadoPor', 'processo']);

        if ($documento->tipoDocumentoObrigatorio?->carimbar_aprovacao) {
            if (strtolower((string) $documento->extensao) === 'pdf') {
                try {
                    app(\App\Services\CarimboValidacaoService::class)->carimbar($documento);
                } catch (\Exception $e) {
                    \Log::warning('Falha ao carimbar documento aprovado com validação', [
                        'processo_documento_id' => $documento->id,
                        'erro' => $e->getMessage(),
                    ]);
                    $avisoCarimbo = 'Documento aprovado, mas não foi possível gerar a versão carimbada com QR Code: ' . $e->getMessage();
                }
            } else {
                $avisoCarimbo = 'Documento aprovado, mas o carimbo de validação só é aplicado em arquivos PDF.';
            }
        }
        
        // Retorna JSON se for requisição AJAX
        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $avisoCarimbo ?? 'Documento aprovado com sucesso!',
                'aviso_carimbo' => $avisoCarimbo !== null,
            ]);
        }
        
        if ($avisoCarimbo) {
            return redirect()->back()->with('warning', $avisoCarimbo);
        }

        return redirect()
            ->back()
            ->with('success', 'Documento aprovado com sucesso!');
    }

    /**
     * Carimba manualmente um documento (modo de carimbo "manual" no tipo de documento).
     * Pode ser acionado com o documento pendente ou aprovado.
     */
    public function carimbarDocumento($estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)->findOrFail($processoId);

        $documento = ProcessoDocumento::where('processo_id', $processo->id)->findOrFail($documentoId);
        $documento->load(['tipoDocumentoObrigatorio', 'aprovadoPor', 'processo']);

        // Só permite carimbar manualmente se o tipo estiver configurado para modo manual
        if (!$documento->tipoDocumentoObrigatorio || !$documento->tipoDocumentoObrigatorio->carimboManual()) {
            $msg = 'Este documento não está configurado para carimbo manual.';
            if (request()->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        // O carimbo manual só pode ser aplicado em documentos já aprovados
        if ($documento->status_aprovacao !== 'aprovado') {
            $msg = 'O documento precisa estar aprovado para ser carimbado.';
            if (request()->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        if (strtolower((string) $documento->extensao) !== 'pdf') {
            $msg = 'O carimbo de validação só é aplicado em arquivos PDF.';
            if (request()->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 422);
            }
            return back()->with('error', $msg);
        }

        try {
            $verificadoPor = auth('interno')->user()?->nome;
            $template = $documento->tipoDocumentoObrigatorio->carimbo_texto;
            app(\App\Services\CarimboValidacaoService::class)->carimbar($documento, $verificadoPor, $template);
        } catch (\Exception $e) {
            \Log::warning('Falha ao carimbar documento manualmente', [
                'processo_documento_id' => $documento->id,
                'erro' => $e->getMessage(),
            ]);
            $msg = 'Não foi possível gerar a versão carimbada: ' . $e->getMessage();
            if (request()->expectsJson()) {
                return response()->json(['success' => false, 'message' => $msg], 500);
            }
            return back()->with('error', $msg);
        }

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Documento carimbado com sucesso!']);
        }

        return back()->with('success', 'Documento carimbado com sucesso!');
    }

    /**
     * Vincula um documento existente a um documento obrigatório do checklist.
     * Permitido para administradores e (gestor/técnico estadual em processos com unidades).
     */
    public function vincularDocumentoObrigatorio(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $usuario = auth('interno')->user();
        if (!$usuario) {
            abort(403, 'Acesso não autorizado.');
        }

        $processo = Processo::with('tipoProcesso.unidades')->findOrFail($processoId);
        $tipoTemUnidades = $processo->tipoProcesso && $processo->tipoProcesso->unidades()->ativas()->exists();

        $podeVincular = $usuario->isAdmin()
            || ($tipoTemUnidades && in_array($usuario->nivel_acesso->value ?? null, ['gestor_estadual', 'tecnico_estadual']));

        if (!$podeVincular) {
            abort(403, 'Você não tem permissão para vincular documentos a obrigatórios neste processo.');
        }

        $request->validate([
            'tipo_documento_obrigatorio_id' => 'required|exists:tipos_documento_obrigatorio,id',
        ]);

        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);

        $documento->update([
            'tipo_documento_obrigatorio_id' => $request->tipo_documento_obrigatorio_id,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Documento vinculado ao documento obrigatório com sucesso!',
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Documento vinculado ao documento obrigatório com sucesso!');
    }

    /**
     * Rejeita documento enviado por usuário externo
     */
    public function rejeitarDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $request->validate([
            'motivo_rejeicao' => 'required|string|max:1000',
        ]);

        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->where('status_aprovacao', 'pendente')
            ->findOrFail($documentoId);
        
        $documento->update([
            'status_aprovacao' => 'rejeitado',
            'motivo_rejeicao' => $request->motivo_rejeicao,
            'aprovado_por' => auth('interno')->id(),
            'aprovado_em' => now(),
        ]);
        
        // Retorna JSON se for requisição AJAX
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Documento rejeitado. O usuário externo será notificado.'
            ]);
        }
        
        return redirect()
            ->back()
            ->with('success', 'Documento rejeitado. O usuário externo será notificado.');
    }

    /**
     * Analisa documento com IA e retorna sugestão de aprovação ou rejeição
     */
    public function analisarDocumentoIA(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->where('status_aprovacao', 'pendente')
            ->findOrFail($documentoId);

        $estabelecimento = \App\Models\Estabelecimento::with('responsaveisTecnicos')->findOrFail($estabelecimentoId);

        // Carrega configurações de IA
        $configs = \App\Models\ConfiguracaoSistema::whereIn('chave', [
            'ia_api_url', 'ia_api_key', 'ia_model', 'ia_model_visao',
        ])->pluck('valor', 'chave');

        $apiUrl  = $configs['ia_api_url'] ?? null;
        $apiKey  = $configs['ia_api_key'] ?? null;
        $modeloTexto = $configs['ia_model'] ?? 'deepseek-ai/DeepSeek-V3';
        $modeloVisaoPadrao = $configs['ia_model_visao'] ?? 'Qwen/Qwen3-VL-8B-Instruct';

        if (!$apiUrl || !$apiKey) {
            return response()->json(['error' => 'IA não está configurada no sistema (API URL / API Key ausente).'], 422);
        }

        // Critérios do tipo de documento
        $tipoDoc    = $documento->tipoDocumentoObrigatorio;
        $criterios  = $tipoDoc?->criterio_ia;
        $tipoDocNome = $tipoDoc?->nome ?? $documento->tipo_documento ?? 'Documento';
        // Modelo de visão pode ser sobrescrito por documento
        $modeloVisao = ($tipoDoc?->ia_modelo_visao) ?: $modeloVisaoPadrao;

        if (empty(trim($criterios ?? ''))) {
            return response()->json(['error' => 'Nenhum critério de análise configurado para este tipo de documento. Configure em Configurações → Tipos de Documento Obrigatório.'], 422);
        }

        // Localiza o arquivo
        $caminhoCompleto = null;
        if ($documento->caminho) {
            foreach ([
                storage_path('app/' . $documento->caminho),
                storage_path('app/public/' . $documento->caminho),
            ] as $tentativa) {
                if (file_exists($tentativa)) {
                    $caminhoCompleto = $tentativa;
                    break;
                }
            }
        }

        if (!$caminhoCompleto) {
            return response()->json(['error' => 'Arquivo do documento não encontrado no servidor.'], 404);
        }

        // Dados do estabelecimento para comparação
        // Envia TODOS os RTs cadastrados para a IA (sem filtro do guard)
        // O guard existe para impedir cadastro de RT com nome empresarial,
        // mas na análise de IA ele remove RTs legítimos como "RUI BARBOSA JUNIOR"
        // quando a razão social é "R BARBOSA JUNIOR"
        $dadosEstab = [
            'razao_social'   => $estabelecimento->nome_razao_social ?? '',
            'nome_fantasia'  => $estabelecimento->nome_fantasia ?? '',
            'cnpj_formatado' => $estabelecimento->documento_formatado ?? '',
            'cnpj'           => preg_replace('/\D/', '', $estabelecimento->documento ?? ''),
            'logradouro'     => (string) ($estabelecimento->endereco ?? ''),
            'numero'         => (string) ($estabelecimento->numero ?? ''),
            'bairro'         => (string) ($estabelecimento->bairro ?? ''),
            'cidade'         => (string) ($estabelecimento->cidade ?? ''),
            'estado'         => (string) ($estabelecimento->estado ?? ''),
            'endereco'       => trim(implode(', ', array_filter([
                $estabelecimento->endereco,
                $estabelecimento->numero,
                $estabelecimento->bairro,
                $estabelecimento->cidade . '/' . $estabelecimento->estado,
            ]))),
            'responsaveis_tecnicos' => $estabelecimento->responsaveisTecnicos
                ->map(function ($responsavel) {
                    $registroConselho = trim(implode(' ', array_filter([
                        $responsavel->conselho,
                        $responsavel->numero_registro_conselho,
                    ])));

                    return [
                        'nome' => (string) ($responsavel->nome ?? ''),
                        'cpf' => (string) preg_replace('/\D/', '', $responsavel->cpf ?? ''),
                        'cpf_formatado' => (string) ($responsavel->cpf_formatado ?? ''),
                        'conselho' => (string) ($registroConselho ?: ($responsavel->conselho ?? '')),
                    ];
                })
                ->filter(fn ($responsavel) => !empty($responsavel['nome']))
                ->values()
                ->all(),
            'responsaveis_legais' => $estabelecimento->responsaveisLegais
                ->map(function ($responsavel) {
                    return [
                        'nome' => (string) ($responsavel->nome ?? ''),
                        'cpf' => (string) preg_replace('/\D/', '', $responsavel->cpf ?? ''),
                        'cpf_formatado' => (string) ($responsavel->cpf_formatado ?? ''),
                    ];
                })
                ->filter(fn ($responsavel) => !empty($responsavel['nome']))
                ->values()
                ->all(),
        ];

        // Tenta extrair texto do PDF
        $textoExtraido = '';
        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf    = $parser->parseFile($caminhoCompleto);
            $textoExtraido = trim($pdf->getText() ?? '');
        } catch (\Exception $e) {
            \Log::warning('IA: Erro ao extrair texto do PDF', ['msg' => $e->getMessage()]);
        }

        $imagemBase64 = null;
        $executarAnalise = function (?string $orientacaoAdicional = null) use (&$imagemBase64, $apiUrl, $apiKey, $modeloTexto, $modeloVisao, $tipoDocNome, $criterios, $dadosEstab, $textoExtraido, $caminhoCompleto) {
            if (strlen($textoExtraido) >= 100) {
                return [
                    $this->iaAnalisarComTexto($apiUrl, $apiKey, $modeloTexto, $tipoDocNome, $criterios, $dadosEstab, $textoExtraido, $orientacaoAdicional),
                    false,
                ];
            }

            $imagemBase64 ??= $this->iaPdfParaImagemBase64($caminhoCompleto);

            if ($imagemBase64) {
                return [
                    $this->iaAnalisarComVisao($apiUrl, $apiKey, $modeloVisao, $tipoDocNome, $criterios, $dadosEstab, $imagemBase64, $orientacaoAdicional),
                    true,
                ];
            }

            if (strlen($textoExtraido) > 0) {
                return [
                    $this->iaAnalisarComTexto(
                        $apiUrl,
                        $apiKey,
                        $modeloTexto,
                        $tipoDocNome,
                        $criterios,
                        $dadosEstab,
                        $textoExtraido . "\n[AVISO: PDF pode ser scaneado; texto extraído pode estar incompleto]",
                        $orientacaoAdicional,
                    ),
                    false,
                ];
            }

            return [[
                'error' => 'Não foi possível extrair conteúdo do documento. O PDF parece ser uma imagem scaneada e o Ghostscript não está disponível para conversão.',
            ], false];
        };

        [$resultado, $usouVisao] = $executarAnalise();

        if (isset($resultado['error'])) {
            return response()->json($resultado, 422);
        }

        $resultado = $this->iaSanitizarResultado($resultado, $criterios);

        if (($resultado['_reanalisar_sem_data_inconsistente'] ?? false) === true) {
            [$resultadoReanalise, $usouVisaoReanalise] = $executarAnalise($this->iaBuildOrientacaoCorretivaDatas());

            if (!isset($resultadoReanalise['error'])) {
                $resultado = $this->iaSanitizarResultado($resultadoReanalise, $criterios);
                $usouVisao = $usouVisaoReanalise;
            }
        }

        if (($resultado['_reanalisar_sem_data_inconsistente'] ?? false) === true) {
            \Log::warning('IA: Reanálise não encontrou inconsistência válida após correção de datas', [
                'resultado' => $resultado,
                'tipo_documento' => $tipoDocNome,
            ]);

            $resultado = [
                'decisao' => 'aprovado',
                'motivo' => 'Não foi identificada inconsistência válida após aplicar as regras de data configuradas para este documento.',
            ];
        }

        if (isset($resultado['error'])) {
            return response()->json($resultado, 422);
        }

        unset($resultado['_reanalisar_sem_data_inconsistente']);

        $resultado['usou_visao']  = $usouVisao;
        $resultado['modelo_usado'] = $usouVisao ? $modeloVisao : $modeloTexto;

        return response()->json($resultado);
    }

    private function iaBuildSystemPrompt(string $tipoDocNome, string $criterios, array $dadosEstab, ?string $orientacaoAdicional = null): string
    {
        $dataAtual = now();
        $dataAtualBr = $dataAtual->format('d/m/Y');
        $dataAtualIso = $dataAtual->format('Y-m-d');

        $dados  = "- Razão Social: {$dadosEstab['razao_social']}\n";
        if ($dadosEstab['nome_fantasia']) {
            $dados .= "- Nome Fantasia: {$dadosEstab['nome_fantasia']}\n";
        }
        $dados .= "- CNPJ: {$dadosEstab['cnpj_formatado']} ({$dadosEstab['cnpj']})\n";
        $dados .= "- Endereço: {$dadosEstab['endereco']}\n";

        if (!empty($dadosEstab['responsaveis_tecnicos'])) {
            $dados .= "- Responsáveis Técnicos ativos no sistema:\n";
            foreach ($dadosEstab['responsaveis_tecnicos'] as $responsavelTecnico) {
                $linhaResponsavel = '  - ' . $responsavelTecnico['nome'];

                if (!empty($responsavelTecnico['cpf_formatado']) || !empty($responsavelTecnico['cpf'])) {
                    $linhaResponsavel .= ' | CPF: ' . ($responsavelTecnico['cpf_formatado'] ?: $responsavelTecnico['cpf']);
                }

                if (!empty($responsavelTecnico['conselho'])) {
                    $linhaResponsavel .= ' | Conselho: ' . $responsavelTecnico['conselho'];
                }

                $dados .= $linhaResponsavel . "\n";
            }
        } else {
            $dados .= "- Responsáveis Técnicos ativos no sistema: nenhum cadastrado\n";
        }

        if (!empty($dadosEstab['responsaveis_legais'])) {
            $dados .= "- Responsáveis Legais ativos no sistema:\n";
            foreach ($dadosEstab['responsaveis_legais'] as $responsavelLegal) {
                $linhaResponsavel = '  - ' . $responsavelLegal['nome'];
                if (!empty($responsavelLegal['cpf_formatado']) || !empty($responsavelLegal['cpf'])) {
                    $linhaResponsavel .= ' | CPF: ' . ($responsavelLegal['cpf_formatado'] ?: $responsavelLegal['cpf']);
                }
                $dados .= $linhaResponsavel . "\n";
            }
        }

        $orientacaoAdicional = trim((string) $orientacaoAdicional);
        $blocoOrientacaoAdicional = $orientacaoAdicional !== ''
            ? "\nORIENTAÇÃO ADICIONAL DE REAVALIAÇÃO:\n{$orientacaoAdicional}\n"
            : '';

        return <<<PROMPT
Você é um analisador de documentos para vigilância sanitária estadual do Brasil. Sua função é verificar se o documento enviado por uma empresa está correto e pode ser aprovado.

DATA ATUAL DO SISTEMA PARA COMPARAÇÕES DE DATA:
- Hoje é {$dataAtualBr} no formato brasileiro DD/MM/AAAA
- Data ISO equivalente: {$dataAtualIso}

DADOS DO ESTABELECIMENTO CADASTRADO NO SISTEMA:
{$dados}
TIPO DE DOCUMENTO A ANALISAR: {$tipoDocNome}

CRITÉRIOS DE ANÁLISE:
{$criterios}
{$blocoOrientacaoAdicional}

INSTRUÇÕES OBRIGATÓRIAS:
1. Analise o documento com base EXCLUSIVAMENTE nos critérios acima. Os critérios são a autoridade máxima — se um critério diz que algo não é exigido, NÃO rejeite por esse motivo.
2. Compare os dados do documento com os dados cadastrados no sistema, mas SOMENTE os dados que os critérios pedem para verificar.
3. Para ENDEREÇO: só verifique se os critérios pedirem. Não exija igualdade literal. Considere compatível quando o logradouro for claramente o mesmo, mesmo com abreviações ou variações de escrita. Só rejeite quando houver divergência relevante que indique outro local.
4. Para RESPONSÁVEL TÉCNICO: só verifique se os critérios pedirem. Considere válido se o nome corresponder claramente a qualquer RT ativo no sistema, mesmo com variações de acentuação, caixa ou abreviação. Se os critérios dizem que não é necessário, ignore completamente.
5. Para CNPJ: só verifique se os critérios pedirem.
6. Para DATAS — regras fundamentais:
   a) Use obrigatoriamente a data atual informada acima. Não invente a data de hoje.
   b) Interprete datas numéricas no padrão brasileiro DD/MM/AAAA. Exemplo: 01/04/2026 = 1 de abril de 2026.
   c) Interprete datas por extenso em português corretamente.
   d) Não confunda data de emissão com data de validade/vencimento.
   e) Se os critérios dizem que o documento não tem prazo de validade, NÃO rejeite por data de validade.
   f) Se os critérios dizem que a data de emissão pode ser anterior à data atual, NÃO rejeite por data de emissão anterior.
   g) Só rejeite por data quando os critérios EXPLICITAMENTE definirem regras de data E essas regras forem violadas.
   h) Quando os critérios definirem regras de data, aplique EXATAMENTE o que dizem — nem mais, nem menos.
7. NÃO invente exigências que não estão nos critérios. Se os critérios não mencionam responsável técnico, CNPJ, endereço ou validade, não exija esses dados.
8. Retorne SOMENTE um objeto JSON válido — sem markdown, sem texto adicional antes ou depois.
9. O campo "decisao" deve ser exatamente "aprovado" ou "rejeitado".
10. O campo "motivo" deve estar em português, ser claro e objetivo (máximo 400 caracteres).
11. Se aprovado: confirme brevemente quais critérios foram atendidos.
12. Se rejeitado: explique exatamente qual critério configurado foi violado.

FORMATO EXIGIDO:
{"decisao":"aprovado","motivo":"Motivo aqui"}
PROMPT;
    }

    private function iaAnalisarComTexto(string $apiUrl, string $apiKey, string $modelo, string $tipoDocNome, string $criterios, array $dadosEstab, string $texto, ?string $orientacaoAdicional = null): array
    {
        $systemPrompt = $this->iaBuildSystemPrompt($tipoDocNome, $criterios, $dadosEstab, $orientacaoAdicional);
        $userMsg      = "Analise o seguinte conteúdo do documento ({$tipoDocNome}):\n\n" . mb_substr($texto, 0, 40000);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(60)->post($apiUrl, [
            'model'       => $modelo,
            'messages'    => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userMsg],
            ],
            'max_tokens'  => 600,
            'temperature' => 0.1,
        ]);

        return $this->iaParseResposta($response);
    }

    private function iaAnalisarComVisao(string $apiUrl, string $apiKey, string $modeloVisao, string $tipoDocNome, string $criterios, array $dadosEstab, string $imagemBase64, ?string $orientacaoAdicional = null): array
    {
        $systemPrompt = $this->iaBuildSystemPrompt($tipoDocNome, $criterios, $dadosEstab, $orientacaoAdicional);

        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . $apiKey,
            'Content-Type'  => 'application/json',
        ])->timeout(90)->post($apiUrl, [
            'model'    => $modeloVisao,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => [
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imagemBase64]],
                    ['type' => 'text',      'text'      => "Analise este documento ({$tipoDocNome}) e retorne a decisão em JSON."],
                ]],
            ],
            'max_tokens'  => 600,
            'temperature' => 0.1,
        ]);

        return $this->iaParseResposta($response);
    }

    private function iaPdfParaImagemBase64(string $pdfPath): ?string
    {
        try {
            $tempFile  = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ia_pdf_' . uniqid() . '.jpg';
            $gsCmd     = PHP_OS_FAMILY === 'Windows' ? 'gswin64c' : 'gs';
            $redirect  = PHP_OS_FAMILY === 'Windows' ? '2>NUL' : '2>/dev/null';

            $cmd = sprintf(
                '%s -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -r150 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s %s',
                $gsCmd,
                escapeshellarg($tempFile),
                escapeshellarg($pdfPath),
                $redirect
            );

            exec($cmd, $out, $code);

            if ($code !== 0 || !file_exists($tempFile)) {
                \Log::warning('IA: Ghostscript falhou', ['code' => $code, 'cmd' => $cmd]);
                return null;
            }

            $bytes = file_get_contents($tempFile);
            @unlink($tempFile);

            return $bytes ? base64_encode($bytes) : null;
        } catch (\Exception $e) {
            \Log::error('IA: Erro ao converter PDF para imagem', ['msg' => $e->getMessage()]);
            return null;
        }
    }

    private function iaParseResposta($response): array
    {
        if (!$response->successful()) {
            \Log::error('IA: Erro na API', ['status' => $response->status(), 'body' => $response->body()]);
            
            $status = $response->status();
            $mensagem = match($status) {
                402 => 'Créditos da API esgotados. Verifique o saldo da conta Together AI.',
                429 => 'Limite de requisições da API atingido. Aguarde alguns minutos e tente novamente.',
                401 => 'Chave de API inválida ou expirada. Verifique em Configurações > Sistema.',
                503 => 'Serviço da IA temporariamente indisponível. Tente novamente em instantes.',
                default => 'Erro ao consultar a IA (HTTP ' . $status . '). Verifique as configurações de API.',
            };
            
            return ['error' => $mensagem];
        }

        $data    = $response->json();
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        // Remove blocos de código markdown se presentes
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', $content);
        $content = trim($content);

        // Tenta extrair JSON da resposta
        if (preg_match('/\{.*"decisao".*\}/s', $content, $m)) {
            $json = json_decode($m[0], true);
            if ($json && isset($json['decisao'])) {
                return ['decisao' => $json['decisao'], 'motivo' => $json['motivo'] ?? ''];
            }
        }

        $json = json_decode($content, true);
        if ($json && isset($json['decisao'])) {
            return ['decisao' => $json['decisao'], 'motivo' => $json['motivo'] ?? ''];
        }

        \Log::warning('IA: Resposta não é JSON válido', ['content' => $content]);
        return ['error' => 'A IA retornou uma resposta em formato inesperado.', 'raw' => mb_substr($content, 0, 500)];
    }

    private function iaSanitizarResultado(array $resultado, ?string $criterios = null): array
    {
        $motivoOriginal = trim((string) ($resultado['motivo'] ?? ''));

        if ($motivoOriginal === '') {
            return $resultado;
        }

        $motivoSanitizado = $this->iaRemoverFrasesComDataInconsistente($motivoOriginal, $criterios);

        if ($motivoSanitizado === $motivoOriginal) {
            return $resultado;
        }

        \Log::warning('IA: Motivo sanitizado por inconsistência de datas', [
            'motivo_original' => $motivoOriginal,
            'motivo_sanitizado' => $motivoSanitizado,
        ]);

        if ($motivoSanitizado === '') {
            $resultado['motivo'] = '';
            $resultado['_reanalisar_sem_data_inconsistente'] = true;

            return $resultado;
        }

        $resultado['motivo'] = $motivoSanitizado;

        return $resultado;
    }

    private function iaBuildOrientacaoCorretivaDatas(): string
    {
        return <<<TXT
ATENÇÃO — REAVALIAÇÃO OBRIGATÓRIA:
A análise anterior rejeitou o documento por questão de data, mas isso pode estar ERRADO.
Releia os CRITÉRIOS DE ANÁLISE com atenção. Se os critérios dizem que a data de emissão pode ser anterior, ou que o documento não tem prazo de validade, NÃO rejeite por data.
Só considere problema de data se: a emissão for futura, se contrariar EXPLICITAMENTE um critério configurado, ou se houver validade expressa vencida.
Se a única inconsistência era uma interpretação errada da data, APROVE o documento e confirme os critérios atendidos.
TXT;
    }

    private function iaRemoverFrasesComDataInconsistente(string $motivo, ?string $criterios = null): string
    {
        $frases = preg_split('/(?<=\.)\s+/', trim($motivo), -1, PREG_SPLIT_NO_EMPTY);

        if (!$frases) {
            return $motivo;
        }

        $frasesMantidas = [];
        $removeuAlguma = false;

        foreach ($frases as $frase) {
            if ($this->iaFraseTemDataInconsistente($frase, $criterios)) {
                $removeuAlguma = true;
                continue;
            }

            $frasesMantidas[] = trim($frase);
        }

        if (!$removeuAlguma) {
            return $motivo;
        }

        if (empty($frasesMantidas)) {
            return '';
        }

        $frasesMantidas[0] = preg_replace('/^(Além disso|Alem disso|Ademais|Também|Tambem|Ainda|Por outro lado),\s*/i', '', $frasesMantidas[0]);
        $frasesMantidas[0] = $this->iaCapitalizarPrimeiraLetra($frasesMantidas[0]);

        return trim(implode(' ', $frasesMantidas));
    }

    private function iaFraseTemDataInconsistente(string $frase, ?string $criterios = null): bool
    {
        $fraseNormalizada = $this->iaNormalizarTextoComparacao($frase);

        $falaDataFutura = preg_match('/\bfutur\w*\b|posterior(?:\s+a)?\s+data\s+atual|posterior\s+ao\s+dia\s+de\s+hoje/', $fraseNormalizada) === 1;
        $falaDataAnteriorInvalida = preg_match('/anterior(?:\s+a)?\s+data\s+atual|inferior(?:\s+a)?\s+data\s+atual|antes\s+da\s+data\s+atual/', $fraseNormalizada) === 1;

        if (!$falaDataFutura && !$falaDataAnteriorInvalida) {
            return false;
        }

        $datas = $this->iaExtrairDatasDoTexto($frase);

        if (empty($datas)) {
            return false;
        }

        if ($falaDataAnteriorInvalida && !$this->iaCriterioPermiteEmissaoAnterior($criterios)) {
            return false;
        }

        if ($falaDataFutura && preg_match('/validade|venciment\w*|vigenc\w*/', $fraseNormalizada)) {
            return true;
        }

        $hoje = now()->startOfDay();

        if ($falaDataFutura) {
            foreach ($datas as $data) {
                if ($data->gt($hoje)) {
                    return false;
                }
            }

            return true;
        }

        foreach ($datas as $data) {
            if ($data->gt($hoje)) {
                return false;
            }
        }

        return true;
    }

    private function iaCriterioPermiteEmissaoAnterior(?string $criterios): bool
    {
        $criterios = $this->iaNormalizarTextoComparacao((string) $criterios);

        if ($criterios === '') {
            return false;
        }

        return preg_match('/data\s+de\s+emiss[a-z\s]*pode\s+ser\s+anterior(?:\s+a)?\s+data\s+atual/', $criterios) === 1
            || preg_match('/se\s+houver\s+apenas\s+data\s+de\s+emiss[a-z\s]*considere\s+valido.*nao\s+for\s+futura/', $criterios) === 1
            || str_contains($criterios, 'so considerar problema de data quando')
            || preg_match('/nao\s+confundir\s+data\s+de\s+emiss[a-z\s]*com\s+data\s+de\s+validade/', $criterios) === 1;
    }

    private function iaNormalizarTextoComparacao(string $texto): string
    {
        $texto = mb_strtolower(\Illuminate\Support\Str::ascii($texto), 'UTF-8');
        $texto = preg_replace('/[^a-z0-9]+/', ' ', $texto);

        return trim(preg_replace('/\s+/', ' ', (string) $texto));
    }

    private function iaExtrairDatasDoTexto(string $texto): array
    {
        $datas = [];

        if (preg_match_all('/\b(0?[1-9]|[12][0-9]|3[01])\/(0?[1-9]|1[0-2])\/(\d{4})\b/', $texto, $matchesNumericos, PREG_SET_ORDER)) {
            foreach ($matchesNumericos as $match) {
                $data = $this->iaCriarDataSegura((int) $match[3], (int) $match[2], (int) $match[1]);
                if ($data) {
                    $datas[] = $data;
                }
            }
        }

        if (preg_match_all('/\b(0?[1-9]|[12][0-9]|3[01])\s+de\s+(janeiro|fevereiro|março|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro)\s+de\s+(\d{4})\b/iu', $texto, $matchesExtenso, PREG_SET_ORDER)) {
            $meses = [
                'janeiro' => 1,
                'fevereiro' => 2,
                'marco' => 3,
                'abril' => 4,
                'maio' => 5,
                'junho' => 6,
                'julho' => 7,
                'agosto' => 8,
                'setembro' => 9,
                'outubro' => 10,
                'novembro' => 11,
                'dezembro' => 12,
            ];

            foreach ($matchesExtenso as $match) {
                $mesNormalizado = strtolower(\Illuminate\Support\Str::ascii($match[2]));
                $mes = $meses[$mesNormalizado] ?? null;

                if (!$mes) {
                    continue;
                }

                $data = $this->iaCriarDataSegura((int) $match[3], $mes, (int) $match[1]);
                if ($data) {
                    $datas[] = $data;
                }
            }
        }

        return $datas;
    }

    private function iaCriarDataSegura(int $ano, int $mes, int $dia): ?\Carbon\Carbon
    {
        try {
            return \Carbon\Carbon::createFromDate($ano, $mes, $dia)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function iaCapitalizarPrimeiraLetra(string $texto): string
    {
        $texto = trim($texto);

        if ($texto === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($texto, 0, 1), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
    }

    /**
     * Revalida documento aprovado ou rejeitado (volta para pendente)
     */
    public function revalidarDocumento($estabelecimentoId, $processoId, $documentoId)
    {
        $documento = ProcessoDocumento::where('processo_id', $processoId)
            ->findOrFail($documentoId);
        
        $statusAnterior = $documento->status_aprovacao;

        if ($statusAnterior === 'pendente') {
            $mensagem = 'Documento já está pendente.';
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $mensagem
                ]);
            }
            return redirect()->back()->with('info', $mensagem);
        }
        
        $documento->update([
            'status_aprovacao' => 'pendente',
            'aprovado_por' => null,
            'aprovado_em' => null,
            'motivo_rejeicao' => null,
        ]);
        
        $mensagem = $statusAnterior === 'rejeitado' 
            ? 'Documento rejeitado foi revalidado e voltou para análise (pendente).'
            : 'Documento voltou para análise (pendente).';

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $mensagem
            ]);
        }
        
        return redirect()
            ->back()
            ->with('success', $mensagem);
    }

    /**
     * Visualiza uma resposta a documento digital
     */
    public function visualizarRespostaDocumento($estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->findOrFail($respostaId);

        $path = storage_path('app/public/' . $resposta->caminho);
        
        if (!file_exists($path)) {
            abort(404, 'Arquivo não encontrado.');
        }

        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $resposta->nome_original . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Download de uma resposta a documento digital
     */
    public function downloadRespostaDocumento($estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->findOrFail($respostaId);

        $path = storage_path('app/public/' . $resposta->caminho);
        
        if (!file_exists($path)) {
            return back()->with('error', 'Arquivo não encontrado.');
        }

        return response()->download($path, $resposta->nome_original);
    }

    /**
     * Aprova uma resposta a documento digital
     */
    public function aprovarRespostaDocumento($estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->findOrFail($respostaId);

        $resposta->aprovar(auth('interno')->id());
        
        // Registrar evento no histórico
        ProcessoEvento::registrarRespostaAprovada($processo, $resposta);

        $prazoFinalizadoAutomaticamente = $documento->finalizarPrazoAutomaticamenteSeItensAtendidos(auth('interno')->id());
        $mensagem = $prazoFinalizadoAutomaticamente
            ? 'Resposta aprovada com sucesso! Todos os itens foram atendidos e o prazo foi encerrado automaticamente.'
            : 'Resposta aprovada com sucesso!';

        // Retorna JSON se for requisição AJAX
        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => $mensagem,
                'prazo_finalizado_automaticamente' => $prazoFinalizadoAutomaticamente,
            ]);
        }

        return redirect()
            ->back()
            ->with('success', $mensagem);
    }

    /**
     * Rejeita uma resposta a documento digital
     */
    public function rejeitarRespostaDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $request->validate([
            'motivo_rejeicao' => 'required|string|max:1000',
        ]);

        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->findOrFail($respostaId);

        $resposta->rejeitar(auth('interno')->id(), $request->motivo_rejeicao);
        $documento->reabrirPrazoAutomaticoSeItensNaoAtendidos();
        
        // Registrar evento no histórico
        ProcessoEvento::registrarRespostaRejeitada($processo, $resposta, $request->motivo_rejeicao);

        // Retorna JSON se for requisição AJAX
        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Resposta rejeitada. O estabelecimento será notificado.'
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Resposta rejeitada. O estabelecimento será notificado.');
    }

    /**
     * Revalida uma resposta aprovada ou rejeitada (volta para pendente)
     */
    public function revalidarRespostaDocumento($estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->whereIn('status', ['aprovado', 'rejeitado'])
            ->findOrFail($respostaId);

        $resposta->update([
            'status' => 'pendente',
            'motivo_rejeicao' => null,
            'avaliado_por' => null,
            'avaliado_em' => null,
        ]);

        $documento->reabrirPrazoAutomaticoSeItensNaoAtendidos();

        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Resposta revalidada. Voltou para pendente.'
            ]);
        }

        return redirect()
            ->back()
            ->with('success', 'Resposta revalidada. Voltou para pendente.');
    }

    /**
     * Exclui uma resposta a documento digital (requer senha de assinatura)
     */
    public function excluirRespostaDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId, $respostaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->findOrFail($respostaId);

        // Valida senha de assinatura
        $usuario = auth('interno')->user();
        
        if (!$usuario->temSenhaAssinatura()) {
            return response()->json([
                'success' => false,
                'message' => 'Você precisa configurar sua senha de assinatura primeiro.'
            ], 400);
        }

        $senhaAssinatura = $request->input('senha_assinatura');
        
        if (!$senhaAssinatura || !\Hash::check($senhaAssinatura, $usuario->senha_assinatura_digital)) {
            return response()->json([
                'success' => false,
                'message' => 'Senha de assinatura incorreta.'
            ], 400);
        }

        $nomeArquivo = $resposta->nome_arquivo;

        // Exclui o arquivo físico se existir
        if ($resposta->caminho_arquivo) {
            $caminhoCompleto = storage_path('app/' . $resposta->caminho_arquivo);
            if (file_exists($caminhoCompleto)) {
                unlink($caminhoCompleto);
            }
        }

        // Registra no histórico antes de excluir
        ProcessoEvento::create([
            'processo_id' => $processo->id,
            'usuario_interno_id' => $usuario->id,
            'tipo_evento' => 'documento_excluido',
            'titulo' => 'Resposta Excluída',
            'descricao' => $nomeArquivo,
            'dados_adicionais' => [
                'nome_arquivo' => $nomeArquivo,
                'documento_digital_id' => $documento->id,
                'documento_nome' => $documento->nome ?? $documento->tipoDocumento->nome ?? 'N/D',
                'excluido_por' => $usuario->nome,
                'tipo' => 'resposta',
            ]
        ]);

        $resposta->delete();

        return response()->json([
            'success' => true,
            'message' => "Resposta '{$nomeArquivo}' excluída com sucesso."
        ]);
    }

    /**
     * Gera documento digital a partir de um modelo
     */
    public function gerarDocumento(Request $request, $estabelecimentoId, $processoId)
    {
        $request->validate([
            'modelo_documento_id' => 'required|exists:modelo_documentos,id',
        ]);

        try {
            $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);
            
            $modelo = ModeloDocumento::with('tipoDocumento')
                ->disponiveisParaUsuario(auth('interno')->user())
                ->findOrFail($request->modelo_documento_id);
            
            // Substitui variáveis no conteúdo HTML
            $conteudo = $this->substituirVariaveis($modelo->conteudo, $estabelecimento, $processo);
            
            // Gera PDF
            $pdf = Pdf::loadHTML($conteudo);
            $pdf->setPaper('A4', 'portrait');
            
            // Define nome do arquivo
            $nomeArquivo = Str::slug($modelo->tipoDocumento->nome) . '_' . time() . '.pdf';
            $nomeOriginal = $modelo->tipoDocumento->nome . ' - ' . $processo->numero_processo . '.pdf';
            
            // Define diretório
            $diretorio = 'processos' . DIRECTORY_SEPARATOR . $processoId;
            $caminhoCompleto = storage_path('app') . DIRECTORY_SEPARATOR . $diretorio;
            
            // Garante que o diretório existe
            if (!file_exists($caminhoCompleto)) {
                mkdir($caminhoCompleto, 0755, true);
            }
            
            // Salva PDF
            $caminhoArquivo = $caminhoCompleto . DIRECTORY_SEPARATOR . $nomeArquivo;
            $pdf->save($caminhoArquivo);
            
            // Caminho relativo para o banco
            $caminhoRelativo = 'processos/' . $processoId . '/' . $nomeArquivo;
            
            // Cria registro no banco
            ProcessoDocumento::create([
                'processo_id' => $processoId,
                'usuario_id' => Auth::id(),
                'tipo_usuario' => 'interno',
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeOriginal,
                'caminho' => $caminhoRelativo,
                'extensao' => 'pdf',
                'tamanho' => filesize($caminhoArquivo),
                'tipo_documento' => 'documento_digital',
            ]);
            
            return redirect()
                ->back()
                ->with('success', 'Documento digital gerado com sucesso!');
                
        } catch (\Exception $e) {
            return redirect()
                ->back()
                ->with('error', 'Erro ao gerar documento: ' . $e->getMessage());
        }
    }

    /**
     * Substitui variáveis no conteúdo do modelo
     */
    private function substituirVariaveis($conteudo, $estabelecimento, $processo)
    {
        // Formata atividades
        $atividadesTexto = $this->formatarAtividadesEstabelecimento($estabelecimento);
        
        $variaveis = [
            '{estabelecimento_nome}' => $estabelecimento->nome_fantasia ?? $estabelecimento->nome_razao_social,
            '{estabelecimento_razao_social}' => $estabelecimento->nome_razao_social,
            '{estabelecimento_cnpj}' => $estabelecimento->cnpj_formatado,
            '{estabelecimento_endereco}' => $estabelecimento->endereco . ', ' . $estabelecimento->numero,
            '{estabelecimento_bairro}' => $estabelecimento->bairro,
            '{estabelecimento_cidade}' => $estabelecimento->cidade,
            '{estabelecimento_estado}' => $estabelecimento->estado,
            '{estabelecimento_cep}' => $estabelecimento->cep,
            '{estabelecimento_telefone}' => $estabelecimento->telefone_formatado ?? '',
            '{atividades}' => $atividadesTexto,
            '{processo_numero}' => $processo->numero_processo,
            '{processo_tipo}' => $processo->tipo,
            '{processo_status}' => $processo->status_formatado,
            '{processo_data_criacao}' => $processo->created_at->format('d/m/Y'),
            '{processo_data_criacao_extenso}' => $processo->created_at->translatedFormat('d \d\e F \d\e Y'),
            '{data_atual}' => now()->format('d/m/Y'),
            '{data_extenso}' => now()->translatedFormat('d \d\e F \d\e Y'),
            '{data_extenso_maiusculo}' => strtoupper(now()->translatedFormat('d \d\e F \d\e Y')),
            '{data_atual_extenso}' => now()->translatedFormat('d \d\e F \d\e Y'),
            '{ano_atual}' => now()->format('Y'),
        ];
        
        return str_replace(array_keys($variaveis), array_values($variaveis), $conteudo);
    }

    /**
     * Formata as atividades do estabelecimento para exibição em documentos
     */
    private function formatarAtividadesEstabelecimento($estabelecimento)
    {
        if (!$estabelecimento) {
            return '';
        }

        $listaAtividades = [];

        // 1. Primeiro tenta usar atividades_exercidas (atividades selecionadas pelo usuário)
        if ($estabelecimento->atividades_exercidas && is_array($estabelecimento->atividades_exercidas) && count($estabelecimento->atividades_exercidas) > 0) {
            foreach ($estabelecimento->atividades_exercidas as $atividade) {
                if (is_array($atividade)) {
                    $codigo = $atividade['codigo'] ?? '';
                    $descricao = $atividade['descricao'] ?? $atividade['nome'] ?? '';
                    $principal = isset($atividade['principal']) && $atividade['principal'];
                    
                    // Verifica se o código é um CNAE válido
                    $codigoLimpo = preg_replace('/[^0-9]/', '', $codigo);
                    $isCodigoCnaeValido = !empty($codigoLimpo) && strlen($codigoLimpo) >= 5 && strlen($codigoLimpo) <= 7;
                    
                    if ($isCodigoCnaeValido && ($descricao || $codigo)) {
                        $texto = '<div style="margin-bottom: 10px; display: flex; align-items: baseline;">';
                        if ($codigo) {
                            $codigoFormatado = $this->formatarCodigoCnae($codigo);
                            $texto .= "<span style=\"font-weight: bold; margin-right: 15px; min-width: 90px; display: inline-block;\">{$codigoFormatado}</span>";
                        }
                        $texto .= "<span>{$descricao}";
                        if ($principal) {
                            $texto .= ' - Principal';
                        }
                        $texto .= '</span></div>';
                        $listaAtividades[] = $texto;
                    }
                }
            }
        }

        // 2. Se não tem atividades exercidas válidas, usa CNAE principal e secundários
        if (empty($listaAtividades)) {
            // CNAE Principal
            if ($estabelecimento->cnae_fiscal) {
                $codigoFormatado = $this->formatarCodigoCnae($estabelecimento->cnae_fiscal);
                $descricao = $estabelecimento->cnae_fiscal_descricao ?? '';
                $texto = "<div style=\"margin-bottom: 10px; display: flex; align-items: baseline;\">";
                $texto .= "<span style=\"font-weight: bold; margin-right: 15px; min-width: 90px; display: inline-block;\">{$codigoFormatado}</span>";
                $texto .= "<span>{$descricao} - Principal</span></div>";
                $listaAtividades[] = $texto;
            }

            // CNAEs Secundários
            if ($estabelecimento->cnaes_secundarios && is_array($estabelecimento->cnaes_secundarios)) {
                foreach ($estabelecimento->cnaes_secundarios as $cnae) {
                    if (is_array($cnae)) {
                        $codigo = $cnae['codigo'] ?? '';
                        $descricao = $cnae['descricao'] ?? $cnae['texto'] ?? '';
                        
                        if ($codigo || $descricao) {
                            $texto = '<div style="margin-bottom: 10px; display: flex; align-items: baseline;">';
                            if ($codigo) {
                                $codigoFormatado = $this->formatarCodigoCnae($codigo);
                                $texto .= "<span style=\"font-weight: bold; margin-right: 15px; min-width: 90px; display: inline-block;\">{$codigoFormatado}</span>";
                            }
                            $texto .= "<span>{$descricao}</span></div>";
                            $listaAtividades[] = $texto;
                        }
                    }
                }
            }
        }

        return implode("", $listaAtividades);
    }

    /**
     * Formata código CNAE no padrão XX.XX-X-XX
     */
    private function formatarCodigoCnae($codigo)
    {
        // Remove caracteres não numéricos
        $codigo = preg_replace('/[^0-9]/', '', $codigo);
        
        // Se já tem 7 dígitos, formata no padrão XX.XX-X-XX
        if (strlen($codigo) === 7) {
            return substr($codigo, 0, 2) . '.' . 
                   substr($codigo, 2, 2) . '-' . 
                   substr($codigo, 4, 1) . '-' . 
                   substr($codigo, 5, 2);
        }
        
        return $codigo;
    }

    /**
     * Arquivar processo
     */
    public function arquivar(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $request->validate([
            'motivo_arquivamento' => 'required|string|min:10',
        ], [
            'motivo_arquivamento.required' => 'O motivo do arquivamento é obrigatório.',
            'motivo_arquivamento.min' => 'O motivo deve ter no mínimo 10 caracteres.',
        ]);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            $statusAntigo = $processo->status;

            // Guardar setor/responsável atual antes de arquivar (para restaurar depois)
            $setorAnterior = $processo->setor_atual;
            $responsavelAnteriorId = $processo->responsavel_atual_id;
            $responsavelAnterior = $processo->responsavelAtual;

            // Atualizar processo - limpa setor/responsável e guarda backup
            $processo->update([
                'status' => 'arquivado',
                'motivo_arquivamento' => $request->motivo_arquivamento,
                'data_arquivamento' => now(),
                'usuario_arquivamento_id' => Auth::guard('interno')->user()->id,
                // Guarda backup do setor/responsável
                'setor_antes_arquivar' => $setorAnterior,
                'responsavel_antes_arquivar_id' => $responsavelAnteriorId,
                // Limpa setor/responsável atual
                'setor_atual' => null,
                'responsavel_atual_id' => null,
                'responsavel_desde' => null,
            ]);

            // ✅ REGISTRAR EVENTO NO HISTÓRICO
            \App\Models\ProcessoEvento::registrarArquivamento(
                $processo,
                $request->motivo_arquivamento,
                null,
                [
                    'status_antigo' => $statusAntigo,
                    'setor_anterior' => $setorAnterior,
                    'setor_anterior_nome' => $processo->setor_antes_arquivar_nome ?? $setorAnterior,
                    'responsavel_anterior_id' => $responsavelAnteriorId,
                    'responsavel_anterior' => $responsavelAnterior?->nome,
                ]
            );

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', 'Processo arquivado com sucesso!');

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao arquivar processo: ' . $e->getMessage());
        }
    }

    /**
     * Desarquivar processo
     */
    public function desarquivar($estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            // Restaurar setor/responsável anterior (se existia)
            $setorRestaurar = $processo->setor_antes_arquivar;
            $responsavelRestaurarId = $processo->responsavel_antes_arquivar_id;
            $responsavelRestaurar = $responsavelRestaurarId ? UsuarioInterno::find($responsavelRestaurarId) : null;

            // Atualizar processo - restaura setor/responsável
            $processo->update([
                'status' => 'aberto',
                // Restaura setor/responsável
                'setor_atual' => $setorRestaurar,
                'responsavel_atual_id' => $responsavelRestaurarId,
                'responsavel_desde' => $setorRestaurar || $responsavelRestaurarId ? now() : null,
                // Limpa backup
                'setor_antes_arquivar' => null,
                'responsavel_antes_arquivar_id' => null,
            ]);

            // ✅ REGISTRAR EVENTO NO HISTÓRICO
            \App\Models\ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => Auth::guard('interno')->user()->id,
                'tipo_evento' => 'processo_desarquivado',
                'titulo' => 'Processo Desarquivado',
                'descricao' => 'Processo foi desarquivado e reaberto' . 
                    ($setorRestaurar ? '. Restaurado para setor: ' . $setorRestaurar : '') .
                    ($responsavelRestaurarId ? '. Responsável restaurado.' : ''),
                'dados_adicionais' => [
                    'motivo_arquivamento_anterior' => $processo->motivo_arquivamento,
                    'data_arquivamento_anterior' => $processo->data_arquivamento?->toDateTimeString(),
                    'setor_restaurado' => $setorRestaurar,
                    'setor_restaurado_nome' => $processo->setor_antes_arquivar_nome ?? $setorRestaurar,
                    'responsavel_restaurado_id' => $responsavelRestaurarId,
                    'responsavel_restaurado' => $responsavelRestaurar?->nome,
                ],
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', 'Processo desarquivado com sucesso!' . 
                    ($setorRestaurar || $responsavelRestaurarId ? ' Setor/responsável anterior restaurado.' : ''));

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao desarquivar processo: ' . $e->getMessage());
        }
    }

    /**
     * Parar processo
     */
    public function parar(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $request->validate([
            'motivo_parada' => 'required|string|min:10',
            'escopo_parada' => 'nullable|string|in:principal,unidades,pastas',
            'unidades_parar' => 'nullable|array',
            'unidades_parar.*' => 'exists:unidades,id',
            'pastas_parar' => 'nullable|array',
            'pastas_parar.*' => 'exists:processo_pastas,id',
        ], [
            'motivo_parada.required' => 'O motivo da parada é obrigatório.',
            'motivo_parada.min' => 'O motivo deve ter no mínimo 10 caracteres.',
        ]);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            $escopoParada = $request->input('escopo_parada', 'principal');
            $unidadesParar = $request->input('unidades_parar', []);
            $pastasParar = $request->input('pastas_parar', []);
            $usuarioId = Auth::guard('interno')->user()->id;

            // Se escopo é "pastas" e tem pastas selecionadas, para só as pastas
            if ($escopoParada === 'pastas' && !empty($pastasParar)) {
                $pastas = \App\Models\ProcessoPasta::where('processo_id', $processo->id)
                    ->whereIn('id', $pastasParar)
                    ->get();

                foreach ($pastas as $pasta) {
                    if ($pasta->status !== 'parado') {
                        $pasta->update([
                            'status' => 'parado',
                            'motivo_parada' => $request->motivo_parada,
                            'data_parada' => now(),
                            'usuario_parada_id' => $usuarioId,
                        ]);
                    }
                }

                $nomesPastas = $pastas->pluck('nome')->implode(', ');
                \App\Models\ProcessoEvento::create([
                    'processo_id' => $processo->id,
                    'usuario_interno_id' => $usuarioId,
                    'tipo_evento' => 'pasta_parada',
                    'titulo' => "Pasta(s) parada(s): {$nomesPastas}",
                    'descricao' => "Pasta(s) parada(s): {$nomesPastas}. Motivo: {$request->motivo_parada}",
                ]);

                return redirect()
                    ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                    ->with('success', "Pasta(s) parada(s): {$nomesPastas}");
            }

            // Se escopo é "unidades" e tem unidades selecionadas, para só as unidades
            if ($escopoParada === 'unidades' && !empty($unidadesParar)) {
                foreach ($unidadesParar as $unidadeId) {
                    $pivot = $processo->unidades()->where('unidade_id', $unidadeId)->first();
                    if ($pivot && $pivot->pivot->status !== 'parado') {
                        $processo->unidades()->updateExistingPivot($unidadeId, [
                            'status' => 'parado',
                            'motivo_parada' => $request->motivo_parada,
                            'data_parada' => now(),
                            'usuario_parada_id' => $usuarioId,
                        ]);
                    }
                }

                $nomesUnidades = Unidade::whereIn('id', $unidadesParar)->pluck('nome')->implode(', ');
                \App\Models\ProcessoEvento::create([
                    'processo_id' => $processo->id,
                    'usuario_interno_id' => $usuarioId,
                    'tipo_evento' => 'unidade_parada',
                    'titulo' => "Unidade(s) parada(s): {$nomesUnidades}",
                    'descricao' => "Unidade(s) parada(s): {$nomesUnidades}. Motivo: {$request->motivo_parada}",
                ]);

                return redirect()
                    ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                    ->with('success', "Unidade(s) parada(s): {$nomesUnidades}");
            }

            // Parada do processo principal (comportamento original)
            $processo->update([
                'status' => 'parado',
                'motivo_parada' => $request->motivo_parada,
                'data_parada' => now(),
                'usuario_parada_id' => $usuarioId,
            ]);

            \App\Models\ProcessoEvento::registrarParada(
                $processo,
                $request->motivo_parada
            );

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', 'Processo parado com sucesso!');

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao parar processo: ' . $e->getMessage());
        }
    }

    /**
     * Conclui (defere) uma pasta/unidade individualmente.
     * Quando todas as pastas forem concluídas, o processo é arquivado automaticamente.
     */
    public function concluirPasta(Request $request, $estabelecimentoId, $processoId, $pastaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $request->validate([
            'motivo_conclusao' => 'required|string|min:5',
        ], [
            'motivo_conclusao.required' => 'Informe o motivo da conclusão (ex: Deferido).',
            'motivo_conclusao.min' => 'O motivo deve ter no mínimo 5 caracteres.',
        ]);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            $pasta = \App\Models\ProcessoPasta::where('processo_id', $processo->id)
                ->findOrFail($pastaId);

            if ($pasta->isConcluida()) {
                return back()->with('error', 'Esta pasta já foi concluída.');
            }

            $usuarioId = Auth::guard('interno')->user()->id;

            $pasta->update([
                'status' => 'concluida',
                'motivo_conclusao' => $request->motivo_conclusao,
                'data_conclusao' => now(),
                'usuario_conclusao_id' => $usuarioId,
            ]);

            \App\Models\ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => $usuarioId,
                'tipo_evento' => 'pasta_concluida',
                'titulo' => "Pasta concluída: {$pasta->nome}",
                'descricao' => "Pasta '{$pasta->nome}' concluída. Motivo: {$request->motivo_conclusao}",
            ]);

            // Verifica se todas as pastas do processo estão concluídas → arquiva o processo
            $totalPastas = \App\Models\ProcessoPasta::where('processo_id', $processo->id)->count();
            $pastasConcluidas = \App\Models\ProcessoPasta::where('processo_id', $processo->id)
                ->where('status', 'concluida')
                ->count();

            if ($totalPastas > 0 && $totalPastas === $pastasConcluidas && $processo->status !== 'arquivado') {
                $processo->update([
                    'status' => 'arquivado',
                    'motivo_arquivamento' => 'Todas as unidades concluídas',
                    'data_arquivamento' => now(),
                    'usuario_arquivamento_id' => $usuarioId,
                ]);

                \App\Models\ProcessoEvento::create([
                    'processo_id' => $processo->id,
                    'usuario_interno_id' => $usuarioId,
                    'tipo_evento' => 'processo_arquivado',
                    'titulo' => 'Processo arquivado automaticamente',
                    'descricao' => 'Todas as unidades/pastas foram concluídas. Processo arquivado automaticamente.',
                ]);

                return redirect()
                    ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                    ->with('success', "Pasta '{$pasta->nome}' concluída. Como todas as unidades foram concluídas, o processo foi arquivado automaticamente.");
            }

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', "Pasta '{$pasta->nome}' concluída com sucesso.");

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao concluir pasta: ' . $e->getMessage());
        }
    }

    /**
     * Reabre uma pasta concluída (caso tenha sido fechada por engano).
     */
    public function reabrirPasta($estabelecimentoId, $processoId, $pastaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            $pasta = \App\Models\ProcessoPasta::where('processo_id', $processo->id)
                ->findOrFail($pastaId);

            if (!$pasta->isConcluida()) {
                return back()->with('error', 'Esta pasta não está concluída.');
            }

            $usuarioId = Auth::guard('interno')->user()->id;

            $pasta->update([
                'status' => 'ativo',
                'motivo_conclusao' => null,
                'data_conclusao' => null,
                'usuario_conclusao_id' => null,
            ]);

            // Se o processo foi arquivado automaticamente por causa da conclusão, reabre também
            if ($processo->status === 'arquivado' && $processo->motivo_arquivamento === 'Todas as unidades concluídas') {
                $processo->update([
                    'status' => 'aberto',
                    'motivo_arquivamento' => null,
                    'data_arquivamento' => null,
                    'usuario_arquivamento_id' => null,
                ]);

                \App\Models\ProcessoEvento::create([
                    'processo_id' => $processo->id,
                    'usuario_interno_id' => $usuarioId,
                    'tipo_evento' => 'processo_reaberto',
                    'titulo' => 'Processo reaberto',
                    'descricao' => "Processo reaberto pois a pasta '{$pasta->nome}' foi reaberta.",
                ]);
            }

            \App\Models\ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => $usuarioId,
                'tipo_evento' => 'pasta_reaberta',
                'titulo' => "Pasta reaberta: {$pasta->nome}",
                'descricao' => "Pasta '{$pasta->nome}' foi reaberta.",
            ]);

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', "Pasta '{$pasta->nome}' reaberta com sucesso.");

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao reabrir pasta: ' . $e->getMessage());
        }
    }

    /**
     * Reiniciar processo
     */
    public function reiniciar($estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->findOrFail($processoId);

            // Atualizar processo
            $processo->update([
                'status' => 'aberto',
                'tempo_total_parado_segundos' => 0,
                'prazo_fila_publica_reiniciado_em' => now(),
                'motivo_parada' => null,
                'data_parada' => null,
                'usuario_parada_id' => null,
            ]);

            // ✅ REGISTRAR EVENTO NO HISTÓRICO
            \App\Models\ProcessoEvento::registrarReinicio($processo);

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', 'Processo reiniciado com sucesso!');

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao reiniciar processo: ' . $e->getMessage());
        }
    }

    /**
     * Adiciona uma nova unidade ao processo (admin/gestor/técnico)
     */
    public function adicionarUnidade(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
                ->with('tipoProcesso')
                ->findOrFail($processoId);

            if ($processo->status !== 'aberto') {
                return back()->with('error', 'Só é possível adicionar unidades em processos abertos.');
            }

            $unidadesDoTipo = $processo->tipoProcesso->unidades()->ativas()->pluck('unidades.id')->toArray();
            if (empty($unidadesDoTipo)) {
                return back()->with('error', 'Este tipo de processo não possui unidades configuradas.');
            }

            $request->validate([
                'unidade_id' => 'required|exists:unidades,id',
            ]);

            $unidadeId = (int) $request->unidade_id;

            if (!in_array($unidadeId, $unidadesDoTipo)) {
                return back()->with('error', 'Esta unidade não está disponível para este tipo de processo.');
            }

            $processo->unidades()->syncWithoutDetaching([$unidadeId]);

            $unidade = \App\Models\Unidade::find($unidadeId);
            $pastasExistentes = $processo->pastas()->where('unidade_id', $unidadeId)->count();
            $nomePasta = $unidade->nome;
            if ($pastasExistentes > 0) {
                $nomePasta = $unidade->nome . ' (' . ($pastasExistentes + 1) . ')';
            }

            $cores = ['#8B5CF6', '#EC4899', '#06B6D4', '#F59E0B', '#10B981', '#EF4444'];
            $ultimaOrdem = $processo->pastas()->max('ordem') ?? 0;

            \App\Models\ProcessoPasta::create([
                'processo_id' => $processo->id,
                'nome' => $nomePasta,
                'descricao' => 'Documentos da unidade ' . $nomePasta,
                'cor' => $cores[($pastasExistentes + 1) % count($cores)],
                'ordem' => $ultimaOrdem + 1,
                'unidade_id' => $unidade->id,
                'protegida' => true,
            ]);

            \App\Models\ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => Auth::guard('interno')->user()->id,
                'tipo_evento' => 'movimentacao',
                'titulo' => "Unidade adicionada: {$nomePasta}",
                'descricao' => "Unidade '{$nomePasta}' adicionada ao processo.",
            ]);

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', 'Unidade "' . $nomePasta . '" adicionada com sucesso!');

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao adicionar unidade: ' . $e->getMessage());
        }
    }

    /**
     * Retoma uma unidade específica que estava parada
     */
    public function retomarUnidade($estabelecimentoId, $processoId, $unidadeId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)->findOrFail($processoId);
            $pivot = $processo->unidades()->where('unidade_id', $unidadeId)->first();

            if (!$pivot || $pivot->pivot->status !== 'parado') {
                return back()->with('error', 'Esta unidade não está parada.');
            }

            // Calcula tempo parado e acumula
            $tempoParado = $pivot->pivot->tempo_total_parado_segundos ?? 0;
            if ($pivot->pivot->data_parada) {
                $tempoParado += max(0, now()->getTimestamp() - \Carbon\Carbon::parse($pivot->pivot->data_parada)->getTimestamp());
            }

            $processo->unidades()->updateExistingPivot($unidadeId, [
                'status' => 'ativo',
                'motivo_parada' => null,
                'data_parada' => null,
                'usuario_parada_id' => null,
                'tempo_total_parado_segundos' => $tempoParado,
            ]);

            $nomeUnidade = Unidade::find($unidadeId)?->nome ?? 'Unidade';
            \App\Models\ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => Auth::guard('interno')->user()->id,
                'tipo_evento' => 'unidade_retomada',
                'titulo' => "Unidade retomada: {$nomeUnidade}",
                'descricao' => "Unidade retomada: {$nomeUnidade}",
            ]);

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', "Unidade '{$nomeUnidade}' retomada com sucesso!");

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao retomar unidade: ' . $e->getMessage());
        }
    }

    /**
     * Retoma uma pasta/unidade parada.
     * - modo "continuar": o prazo volta a contar de onde parou (o tempo parado é descontado)
     * - modo "reiniciar": o prazo da unidade recomeça do zero, igual ao "Reiniciar Processo"
     */
    public function retomarPasta(Request $request, $estabelecimentoId, $processoId, $pastaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $request->validate([
            'modo' => 'required|in:continuar,reiniciar',
        ]);

        try {
            $processo = Processo::where('estabelecimento_id', $estabelecimentoId)->findOrFail($processoId);
            $pasta = \App\Models\ProcessoPasta::where('processo_id', $processo->id)->findOrFail($pastaId);

            if ($pasta->isConcluida()) {
                return back()->with('error', 'Esta unidade já foi concluída.');
            }

            $reiniciar = $request->input('modo') === 'reiniciar';

            if (!$pasta->isParada()) {
                return back()->with('error', 'Esta unidade não está parada.');
            }

            $dadosRetomada = [
                'status' => 'ativo',
                'motivo_parada' => null,
                'data_parada' => null,
                'usuario_parada_id' => null,
            ];

            if ($reiniciar) {
                $dadosPasta = $dadosRetomada + [
                    'tempo_total_parado_segundos' => 0,
                    'prazo_fila_publica_reiniciado_em' => now(),
                ];
            } else {
                $tempoParado = (int) ($pasta->tempo_total_parado_segundos ?? 0);
                if ($pasta->data_parada) {
                    $tempoParado += max(0, now()->getTimestamp() - $pasta->data_parada->getTimestamp());
                }
                $dadosPasta = $dadosRetomada + ['tempo_total_parado_segundos' => $tempoParado];
            }

            DB::transaction(function () use ($processo, $pasta, $dadosPasta, $dadosRetomada, $reiniciar) {
                $pasta->update($dadosPasta);

                // Mantém o vínculo da unidade coerente (parada feita pela unidade em vez da pasta)
                if ($pasta->unidade_id) {
                    $pivot = $processo->unidades()->where('unidade_id', $pasta->unidade_id)->first()?->pivot;
                    if ($pivot && ($reiniciar || $pivot->status === 'parado')) {
                        $tempoPivot = (int) ($pivot->tempo_total_parado_segundos ?? 0);
                        if (!$reiniciar && $pivot->status === 'parado' && $pivot->data_parada) {
                            $tempoPivot += max(0, now()->getTimestamp() - \Carbon\Carbon::parse($pivot->data_parada)->getTimestamp());
                        }
                        $processo->unidades()->updateExistingPivot($pasta->unidade_id, $dadosRetomada + [
                            'tempo_total_parado_segundos' => $reiniciar ? 0 : $tempoPivot,
                        ]);
                    }
                }

                \App\Models\ProcessoEvento::create([
                    'processo_id' => $processo->id,
                    'usuario_interno_id' => Auth::guard('interno')->user()->id,
                    'tipo_evento' => $reiniciar ? 'unidade_prazo_reiniciado' : 'unidade_retomada',
                    'titulo' => $reiniciar ? "Prazo da unidade reiniciado: {$pasta->nome}" : "Unidade retomada: {$pasta->nome}",
                    'descricao' => $reiniciar
                        ? "Unidade '{$pasta->nome}' reiniciada. O prazo da unidade voltou a contar do zero em " . now()->format('d/m/Y H:i') . '.'
                        : "Unidade '{$pasta->nome}' retomada. O prazo continua de onde parou.",
                ]);
            });

            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', $reiniciar
                    ? "Prazo da unidade '{$pasta->nome}' reiniciado com sucesso!"
                    : "Unidade '{$pasta->nome}' retomada com sucesso!");

        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao retomar unidade: ' . $e->getMessage());
        }
    }

    /**
     * Valida se o usuário tem permissão para acessar o processo
     */
    private function validarPermissaoAcesso($estabelecimento, ?Processo $processo = null)
    {
        $usuario = auth('interno')->user();

        if (!$processo) {
            $processoRoute = request()->route('processo');

            if ($processoRoute) {
                $processoId = $processoRoute instanceof Processo ? $processoRoute->id : $processoRoute;

                $processo = Processo::with('tipoProcesso')
                    ->where('estabelecimento_id', $estabelecimento->id)
                    ->find($processoId);
            }
        }

        $escopoCompetencia = $processo && $processo->tipoProcesso
            ? $processo->tipoProcesso->resolverEscopoCompetencia($estabelecimento)
            : ($estabelecimento->isCompetenciaEstadual() ? 'estadual' : 'municipal');
        
        // Administrador tem acesso total
        if ($usuario->isAdmin()) {
            return true;
        }
        
        // Usuário estadual só pode acessar processos de competência estadual
        if ($usuario->isEstadual()) {
            if ($escopoCompetencia !== 'estadual') {
                abort(403, 'Você não tem permissão para acessar processos de competência municipal.');
            }
            return true;
        }
        
        // Usuário municipal só pode acessar processos do próprio município e de competência municipal
        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id || $estabelecimento->municipio_id != $usuario->municipio_id) {
                abort(403, 'Você não tem permissão para acessar processos de outros municípios.');
            }
            if ($escopoCompetencia !== 'municipal') {
                abort(403, 'Você não tem permissão para acessar processos de competência estadual.');
            }
            return true;
        }
        
        return true;
    }

    /**
     * Carrega anotações de um PDF
     */
    public function carregarAnotacoes($documentoId)
    {
        $documento = ProcessoDocumento::findOrFail($documentoId);
        $processo = $documento->processo;
        $estabelecimento = $processo->estabelecimento;
        
        // Valida permissão de acesso
        $this->validarPermissaoAcesso($estabelecimento);
        
        // Busca anotações de TODOS os usuários para este documento (compartilhado)
        $anotacoes = \App\Models\ProcessoDocumentoAnotacao::where('processo_documento_id', $documentoId)
            ->with('usuario:id,nome')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function($anotacao) {
                return [
                    'id' => $anotacao->id,
                    'tipo' => $anotacao->tipo,
                    'pagina' => $anotacao->pagina,
                    'dados' => $anotacao->dados,
                    'comentario' => $anotacao->comentario,
                    'usuario_id' => $anotacao->usuario_id,
                    'usuario_nome' => $anotacao->usuario->nome ?? 'Usuário',
                    'created_at' => $anotacao->created_at->format('d/m/Y H:i'),
                ];
            });

        return response()->json($anotacoes);
    }

    /**
     * Salva anotações feitas em um PDF
     */
    public function salvarAnotacoes(Request $request, $documentoId)
    {
        try {
            $documento = ProcessoDocumento::findOrFail($documentoId);
            $processo = $documento->processo;
            
            if (!$processo) {
                return response()->json([
                    'success' => false,
                    'message' => 'Processo não encontrado para este documento.'
                ], 404);
            }
            
            $estabelecimento = $processo->estabelecimento;
            
            if (!$estabelecimento) {
                return response()->json([
                    'success' => false,
                    'message' => 'Estabelecimento não encontrado para este processo.'
                ], 404);
            }
            
            // Valida permissão de acesso
            $this->validarPermissaoAcesso($estabelecimento);
            
            // Permitir array vazio para limpar anotações do usuário atual
            $anotacoes = $request->input('anotacoes', []);
            if (!is_array($anotacoes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Formato inválido de anotações.'
                ], 422);
            }

            // Se houver itens, validar o schema de cada anotação
            if (!empty($anotacoes)) {
                $request->validate([
                    'anotacoes.*.tipo' => 'required|string|in:highlight,text,drawing,area,comment',
                    'anotacoes.*.pagina' => 'required|integer|min:1',
                    'anotacoes.*.dados' => 'required|array',
                    'anotacoes.*.comentario' => 'nullable|string',
                ]);
            }

            $usuarioId = auth('interno')->id();
            
            // Pega os IDs das anotações que vieram do banco (IDs inteiros pequenos, não timestamps)
            // IDs do banco são inteiros sequenciais, IDs temporários do frontend são timestamps grandes
            $idsRecebidos = collect($anotacoes)
                ->filter(function($a) {
                    // Só considera como ID do banco se for inteiro e menor que 1000000000 (antes de 2001)
                    // Timestamps JavaScript são maiores que 1000000000000 (13 dígitos)
                    return isset($a['id']) && is_numeric($a['id']) && $a['id'] < 1000000000;
                })
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();
            
            // Remove apenas as anotações do usuário atual que não estão mais na lista
            \App\Models\ProcessoDocumentoAnotacao::where('processo_documento_id', $documentoId)
                ->where('usuario_id', $usuarioId)
                ->whereNotIn('id', $idsRecebidos)
                ->delete();

            // Salva novas anotações (apenas as que não têm ID do banco)
            foreach ($anotacoes as $anotacao) {
                // Se já tem ID do banco e é de outro usuário, pula (não pode editar)
                if (isset($anotacao['id']) && $anotacao['id'] < 1000000000 && isset($anotacao['usuario_id']) && $anotacao['usuario_id'] != $usuarioId) {
                    continue;
                }
                
                // Se não tem ID ou é um ID temporário (timestamp), é uma nova anotação
                $isNovaAnotacao = !isset($anotacao['id']) || $anotacao['id'] >= 1000000000;
                
                if ($isNovaAnotacao) {
                    \App\Models\ProcessoDocumentoAnotacao::create([
                        'processo_documento_id' => $documentoId,
                        'usuario_id' => $usuarioId,
                        'pagina' => $anotacao['pagina'],
                        'tipo' => $anotacao['tipo'],
                        'dados' => $anotacao['dados'],
                        'comentario' => $anotacao['comentario'] ?? null,
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Anotações salvas com sucesso!'
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro de validação: ' . implode(', ', $e->validator->errors()->all())
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], $e->getStatusCode());
        } catch (\Exception $e) {
            \Log::error('Erro ao salvar anotações', [
                'documento_id' => $documentoId,
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar anotações: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Busca setores e usuários internos para designação
     */
    public function buscarUsuariosParaDesignacao($estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::with('tipoProcesso')
            ->where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $usuarioLogado = auth('interno')->user();
        $setorUsuarioLogado = $usuarioLogado->setor;
        $nivelAcessoUsuario = $usuarioLogado->nivel_acesso->value ?? $usuarioLogado->nivel_acesso;
        $municipioUsuario = $usuarioLogado->municipio_id;
        
        // Determina o perfil do usuário logado
        $isAdmin = $nivelAcessoUsuario === 'administrador';
        $isUsuarioEstadual = in_array($nivelAcessoUsuario, ['administrador', 'gestor_estadual', 'tecnico_estadual']);
        $isUsuarioMunicipal = in_array($nivelAcessoUsuario, ['gestor_municipal', 'tecnico_municipal']);
        
        // Busca setores disponíveis
        $setores = \App\Models\TipoSetor::where('ativo', true)
            ->where(function ($query) use ($isAdmin, $isUsuarioMunicipal, $municipioUsuario) {
                if ($isAdmin) {
                    // Admin: vê TODOS os setores (globais e municipais)
                    // Sem filtro adicional
                } elseif ($isUsuarioMunicipal && $municipioUsuario) {
                    // Municipal: setores globais OU vinculados ao município do usuário
                    $query->whereDoesntHave('municipios')
                          ->orWhereHas('municipios', fn($q) => $q->where('municipios.id', $municipioUsuario));
                } else {
                    // Estadual (não-admin): apenas setores globais (sem município)
                    $query->whereDoesntHave('municipios');
                }
            })
            ->orderBy('nome')
            ->get();
        
        // Níveis que caracterizam setores estaduais e municipais
        $niveisSetorEstadual = ['gestor_estadual', 'tecnico_estadual'];
        $niveisSetorMunicipal = ['gestor_municipal', 'tecnico_municipal'];
        
        // Filtra setores por nível de acesso do usuário logado
        $setoresDisponiveis = $setores->filter(function($setor) use ($isAdmin, $isUsuarioEstadual, $isUsuarioMunicipal, $niveisSetorEstadual, $niveisSetorMunicipal) {
            // Admin vê TODOS os setores
            if ($isAdmin) {
                return true;
            }
            
            // Se não tem níveis de acesso definidos, disponível para todos
            if (!$setor->niveis_acesso || count($setor->niveis_acesso) === 0) {
                return true;
            }
            
            // Verifica se o setor é estadual ou municipal baseado nos níveis configurados
            $isSetorEstadual = !empty(array_intersect($setor->niveis_acesso, $niveisSetorEstadual));
            $isSetorMunicipal = !empty(array_intersect($setor->niveis_acesso, $niveisSetorMunicipal));
            
            // Se usuário é estadual (gestor_estadual, tecnico_estadual), mostra setores estaduais
            if ($isUsuarioEstadual) {
                return $isSetorEstadual && !$isSetorMunicipal;
            }
            
            // Se usuário é municipal, mostra setores municipais
            if ($isUsuarioMunicipal) {
                return $isSetorMunicipal && !$isSetorEstadual;
            }
            
            return false;
        })->values();
        
        // Níveis de usuários estaduais e municipais para filtrar usuários
        $niveisUsuariosEstaduais = ['administrador', 'gestor_estadual', 'tecnico_estadual'];
        $niveisUsuariosMunicipais = ['gestor_municipal', 'tecnico_municipal'];
        
        // Busca usuários internos ativos
        $query = UsuarioInterno::where('ativo', true);
        
        // Filtra usuários baseado no perfil do usuário logado
        if ($isAdmin) {
            // Admin vê TODOS os usuários (estaduais e municipais)
            // Sem filtro adicional
            //
        } elseif ($isUsuarioEstadual) {
            // Usuário estadual vê todos os usuários estaduais
            $query->whereIn('nivel_acesso', $niveisUsuariosEstaduais);
        } elseif ($isUsuarioMunicipal) {
            // Usuário municipal vê apenas usuários do seu município
            $query->where('municipio_id', $municipioUsuario)
                  ->whereIn('nivel_acesso', $niveisUsuariosMunicipais);
        }
        
        $usuarios = $query->orderBy('nome')
            ->get(['id', 'nome', 'cargo', 'nivel_acesso', 'setor']);
        
        // Agrupa usuários por setor (apenas dos setores disponíveis para tramitação)
        $usuariosPorSetor = [];
        foreach ($setoresDisponiveis as $setor) {
            $usuariosDoSetor = $usuarios->where('setor', $setor->codigo)->values();
            if ($usuariosDoSetor->count() > 0) {
                $usuariosPorSetor[] = [
                    'setor' => [
                        'codigo' => $setor->codigo,
                        'nome' => $setor->nome,
                    ],
                    'usuarios' => $usuariosDoSetor
                ];
            }
        }
        
        // Adiciona usuários sem setor
        $usuariosSemSetor = $usuarios->whereNull('setor')->values();
        if ($usuariosSemSetor->count() > 0) {
            $usuariosPorSetor[] = [
                'setor' => [
                    'codigo' => null,
                    'nome' => 'Sem Setor',
                ],
                'usuarios' => $usuariosSemSetor
            ];
        }
        
        // Mapeia setores para array simples com apenas codigo e nome
        $setoresArray = $setoresDisponiveis->map(function($setor) {
            return [
                'codigo' => $setor->codigo,
                'nome' => $setor->nome,
            ];
        })->values();
        
        return response()->json([
            'setores' => $setoresArray,
            'usuariosPorSetor' => $usuariosPorSetor,
            'isUsuarioEstadual' => $isUsuarioEstadual,
            'setorUsuarioLogado' => $setorUsuarioLogado,
            'debug' => [
                'nivelAcessoUsuario' => $nivelAcessoUsuario,
                'totalSetores' => $setores->count(),
                'setoresFiltrados' => $setoresDisponiveis->count(),
                'totalUsuarios' => $usuarios->count(),
            ]
        ]);
    }

    /**
     * Designa responsáveis para o processo (apenas usuários)
     */
    public function designarResponsavel(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $validated = $request->validate([
            'tipo_designacao' => 'required|in:usuario',
            'usuarios_designados' => 'required|array|min:1',
            'usuarios_designados.*' => 'required|exists:usuarios_internos,id',
            'descricao_tarefa' => 'required|string|max:1000',
            'data_limite' => 'nullable|date|after_or_equal:today',
            'definir_responsavel_atual' => 'nullable|boolean',
        ]);
        
        $designados = 0;
        $tipoProcesso = $processo->tipoProcesso;
        $isCompetenciaEstadual = $tipoProcesso && in_array($tipoProcesso->competencia, ['estadual', 'estadual_exclusivo']);
        $ultimoDesignado = null;
        
        // Designação apenas por usuário
        foreach ($validated['usuarios_designados'] as $usuarioId) {
            $usuarioDesignado = UsuarioInterno::find($usuarioId);
            
            // Verifica competência
            $podeDesignar = false;
            if ($isCompetenciaEstadual) {
                $podeDesignar = $usuarioDesignado && $usuarioDesignado->municipio_id === null;
            } else {
                $podeDesignar = $usuarioDesignado && $usuarioDesignado->municipio_id == $estabelecimento->municipio_id;
            }
            
            if ($podeDesignar) {
                ProcessoDesignacao::create([
                    'processo_id' => $processo->id,
                    'usuario_designado_id' => $usuarioId,
                    'usuario_designador_id' => auth('interno')->id(),
                    'descricao_tarefa' => $validated['descricao_tarefa'],
                    'data_limite' => $validated['data_limite'] ?? null,
                    'status' => 'pendente',
                ]);
                $designados++;
                $ultimoDesignado = $usuarioDesignado;
            }
        }
        
        // Se marcou para definir como responsável atual ou se é o único designado
        if ($designados > 0 && $ultimoDesignado) {
            $definirResponsavel = $request->boolean('definir_responsavel_atual', $designados === 1);
            
            if ($definirResponsavel) {
                $processo->atribuirPara(
                    $ultimoDesignado->setor,
                    $ultimoDesignado->id
                );
            }
        }
        
        if ($designados > 0) {
            $mensagem = $designados === 1 
                ? 'Responsável designado com sucesso!' 
                : "{$designados} responsáveis designados com sucesso!";
            
            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('success', $mensagem);
        }
        
        return back()->withErrors([
            'usuarios_designados' => 'Nenhum responsável válido foi designado.'
        ]);
    }

    /**
     * Atribui o processo a um setor e/ou responsável (passar processo)
     */
    public function atribuirProcesso(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $validated = $request->validate([
            'setor_atual' => 'nullable|string|max:255',
            'responsavel_atual_id' => 'nullable|exists:usuarios_internos,id',
            'motivo_atribuicao' => 'nullable|string|max:1000',
            'prazo_atribuicao' => 'nullable|date|after_or_equal:today',
        ]);
        
        $setorAnterior = $processo->setor_atual;
        $responsavelAnterior = $processo->responsavelAtual;
        $nomeSetorAnterior = $processo->setor_atual_nome;
        $prazoAnterior = $processo->prazo_atribuicao;
        
        // Busca o nome do setor se informado
        $nomeSetorNovo = null;
        if ($validated['setor_atual']) {
            $tipoSetor = \App\Models\TipoSetor::where('codigo', $validated['setor_atual'])->first();
            $nomeSetorNovo = $tipoSetor ? $tipoSetor->nome : $validated['setor_atual'];
        }
        
        // Atualiza o processo
        $processo->update([
            'setor_atual' => $validated['setor_atual'] ?: null,
            'responsavel_atual_id' => $validated['responsavel_atual_id'] ?: null,
            'responsavel_desde' => now(),
            'prazo_atribuicao' => $validated['prazo_atribuicao'] ?? null,
            'motivo_atribuicao' => $validated['motivo_atribuicao'] ?? null,
            'responsavel_ciente_em' => null, // Reseta para o novo responsável ver a notificação
        ]);
        
        // Registra no histórico
        $novoResponsavel = $validated['responsavel_atual_id'] ? UsuarioInterno::find($validated['responsavel_atual_id']) : null;
        
        $descricao = 'Processo atribuído';
        if ($nomeSetorNovo) {
            $descricao .= ' ao setor ' . $nomeSetorNovo;
        }
        if ($novoResponsavel) {
            $descricao .= ($nomeSetorNovo ? ' - ' : ' a ') . $novoResponsavel->nome;
        }
        if (!$validated['setor_atual'] && !$novoResponsavel) {
            $descricao = 'Atribuição do processo removida';
        }
        
        // Adiciona motivo se informado
        $motivoAtribuicao = $validated['motivo_atribuicao'] ?? null;
        if ($motivoAtribuicao) {
            $descricao .= '. Motivo: ' . $motivoAtribuicao;
        }
        
        // Adiciona prazo se informado
        $prazoAtribuicao = $validated['prazo_atribuicao'] ?? null;
        if ($prazoAtribuicao) {
            $descricao .= '. Prazo: ' . \Carbon\Carbon::parse($prazoAtribuicao)->format('d/m/Y');
        }
        
        ProcessoEvento::create([
            'processo_id' => $processo->id,
            'usuario_interno_id' => auth('interno')->id(),
            'tipo_evento' => 'processo_atribuido',
            'titulo' => 'Processo Atribuído',
            'descricao' => $descricao,
            'dados_adicionais' => [
                'setor_anterior' => $setorAnterior,
                'setor_anterior_nome' => $nomeSetorAnterior,
                'responsavel_anterior_id' => $responsavelAnterior ? $responsavelAnterior->id : null,
                'responsavel_anterior' => $responsavelAnterior ? $responsavelAnterior->nome : null,
                'setor_novo' => $validated['setor_atual'] ?? null,
                'setor_novo_nome' => $nomeSetorNovo,
                'responsavel_novo_id' => $novoResponsavel ? $novoResponsavel->id : null,
                'responsavel_novo' => $novoResponsavel ? $novoResponsavel->nome : null,
                'motivo' => $motivoAtribuicao,
                'prazo' => $prazoAtribuicao,
                'prazo_anterior' => $prazoAnterior ? $prazoAnterior->format('Y-m-d') : null,
            ],
        ]);
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Processo atribuído com sucesso!');
    }

    /**
     * Marca que o responsável está ciente da atribuição
     */
    public function marcarCiente(Request $request, $estabelecimentoId, $processoId)
    {
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $usuario = auth('interno')->user();
        
        // Só pode marcar ciente se for o responsável atual
        if ($processo->responsavel_atual_id !== $usuario->id) {
            return response()->json(['success' => false, 'message' => 'Você não é o responsável atual deste processo.'], 403);
        }
        
        $processo->update([
            'responsavel_ciente_em' => now(),
        ]);
        
        // Busca o último evento de atribuição para adicionar a ciência
        $ultimoEventoAtribuicao = ProcessoEvento::where('processo_id', $processo->id)
            ->where('tipo_evento', 'processo_atribuido')
            ->orderBy('created_at', 'desc')
            ->first();
        
        if ($ultimoEventoAtribuicao) {
            $dadosAdicionais = $ultimoEventoAtribuicao->dados_adicionais ?? [];
            $dadosAdicionais['ciente_em'] = now()->format('Y-m-d H:i:s');
            $dadosAdicionais['ciente_por_id'] = $usuario->id;
            $dadosAdicionais['ciente_por_nome'] = $usuario->nome;
            
            $ultimoEventoAtribuicao->update([
                'dados_adicionais' => $dadosAdicionais,
            ]);
        }
        
        return response()->json(['success' => true]);
    }

    /**
     * Atualiza o status de uma designação
     */
    public function atualizarDesignacao(Request $request, $estabelecimentoId, $processoId, $designacaoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $designacao = ProcessoDesignacao::where('processo_id', $processoId)
            ->findOrFail($designacaoId);
        
        $validated = $request->validate([
            'status' => 'required|in:pendente,em_andamento,concluida,cancelada',
            'observacoes_conclusao' => 'nullable|string|max:1000',
        ]);
        
        $designacao->status = $validated['status'];
        $designacao->observacoes_conclusao = $validated['observacoes_conclusao'] ?? null;
        
        if ($validated['status'] === 'concluida') {
            $designacao->concluida_em = now();
        }
        
        $designacao->save();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Status da designação atualizado!');
    }
    
    /**
     * Marca uma designação como concluída
     */
    public function concluirDesignacao($estabelecimentoId, $processoId, $designacaoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $designacao = ProcessoDesignacao::where('processo_id', $processoId)
            ->where('usuario_designado_id', auth('interno')->id())
            ->findOrFail($designacaoId);
        
        // Verifica se a designação já está concluída
        if ($designacao->status === 'concluida') {
            return redirect()
                ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
                ->with('warning', 'Esta tarefa já está marcada como concluída.');
        }
        
        // Atualiza o status para concluído
        $designacao->status = 'concluida';
        $designacao->concluida_em = now();
        $designacao->save();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Tarefa marcada como concluída com sucesso!');
    }

    /**
     * Cria um novo alerta para o processo
     */
    public function criarAlerta(Request $request, $estabelecimentoId, $processoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $validated = $request->validate([
            'descricao' => 'required|string|max:500',
            'data_alerta' => 'required|date|after_or_equal:today',
        ]);
        
        ProcessoAlerta::create([
            'processo_id' => $processo->id,
            'usuario_criador_id' => auth('interno')->id(),
            'descricao' => $validated['descricao'],
            'data_alerta' => $validated['data_alerta'],
            'status' => 'pendente',
        ]);
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Alerta criado com sucesso!');
    }

    /**
     * Marca um alerta como visualizado
     */
    public function visualizarAlerta($estabelecimentoId, $processoId, $alertaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $alerta = ProcessoAlerta::where('processo_id', $processo->id)
            ->findOrFail($alertaId);
        
        $alerta->marcarComoVisualizado();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Alerta marcado como visualizado!');
    }

    /**
     * Marca um alerta como concluído
     */
    public function concluirAlerta($estabelecimentoId, $processoId, $alertaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $alerta = ProcessoAlerta::where('processo_id', $processo->id)
            ->findOrFail($alertaId);
        
        $alerta->marcarComoConcluido();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Alerta marcado como concluído!');
    }

    /**
     * Exclui um alerta
     */
    public function excluirAlerta($estabelecimentoId, $processoId, $alertaId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);
        
        $alerta = ProcessoAlerta::where('processo_id', $processo->id)
            ->findOrFail($alertaId);
        
        $alerta->delete();
        
        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Alerta excluído com sucesso!');
    }

    /**
     * Finaliza o prazo de um documento digital (marca como respondido)
     */
    public function finalizarPrazoDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        // Verifica se o documento tem prazo
        if (!$documento->prazo_dias && !$documento->data_vencimento) {
            return back()->with('error', 'Este documento não possui prazo configurado.');
        }

        // Verifica se já está finalizado
        if ($documento->isPrazoFinalizado()) {
            return back()->with('warning', 'O prazo deste documento já foi finalizado.');
        }

        $motivo = $request->input('motivo', 'Resposta recebida e aceita');
        $documento->finalizarPrazo(auth('interno')->id(), $motivo);

        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Prazo do documento finalizado com sucesso!');
    }

    /**
     * Define manualmente o prazo de um documento digital já assinado.
     */
    public function definirPrazoDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        \Log::info('Iniciando definicao manual de prazo para documento digital', [
            'estabelecimento_id' => $estabelecimentoId,
            'processo_id' => $processoId,
            'documento_id' => $documentoId,
            'usuario_interno_id' => auth('interno')->id(),
            'payload' => $request->only(['prazo_dias', 'tipo_prazo']),
        ]);

        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::with('tipoDocumento')
            ->where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        $validated = $request->validate([
            'prazo_dias' => 'required|integer|min:1|max:3650',
            'tipo_prazo' => 'required|in:corridos,uteis',
        ]);

        if (!($documento->tipoDocumento?->tem_prazo)) {
            return back()->with('error', 'O tipo deste documento não permite configuração de prazo.');
        }

        if ($documento->status !== 'assinado' || !$documento->todasAssinaturasCompletas()) {
            return back()->with('error', 'Só é possível definir prazo manualmente após o documento estar totalmente assinado.');
        }

        if ($documento->prazo_dias || $documento->data_vencimento) {
            return back()->with('warning', 'Este documento já possui prazo configurado.');
        }

        try {
            DB::transaction(function () use ($documento, $processo, $request, $validated) {
            $documento->definirPrazoManualmente(
                (int) $validated['prazo_dias'],
                $validated['tipo_prazo'],
                (bool) ($documento->tipoDocumento?->prazo_notificacao ?? false)
            );

                try {
                    ProcessoEvento::create([
                        'processo_id' => $processo->id,
                        'usuario_interno_id' => auth('interno')->id(),
                        'tipo_evento' => 'movimentacao',
                        'titulo' => 'Prazo definido manualmente',
                        'descricao' => 'Prazo de ' . $documento->prazo_dias . ' dia(s) ' . ($documento->tipo_prazo === 'uteis' ? 'úteis' : 'corridos') . ' definido manualmente para o documento ' . ($documento->numero_documento ?? ('#' . $documento->id)) . '.',
                        'dados_adicionais' => [
                            'documento_digital_id' => $documento->id,
                            'prazo_dias' => $documento->prazo_dias,
                            'tipo_prazo' => $documento->tipo_prazo,
                            'data_vencimento' => optional($documento->data_vencimento)->format('Y-m-d'),
                            'prazo_iniciado_em' => optional($documento->prazo_iniciado_em)->toDateTimeString(),
                        ],
                        'ip_address' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ]);
                } catch (\Throwable $eventoException) {
                    \Log::warning('Prazo definido, mas falha ao registrar evento do processo', [
                        'processo_id' => $processo->id,
                        'documento_id' => $documento->id,
                        'erro' => $eventoException->getMessage(),
                    ]);
                }
            });
        } catch (\Throwable $exception) {
            \Log::error('Falha ao definir prazo manualmente para documento digital', [
                'estabelecimento_id' => $estabelecimentoId,
                'processo_id' => $processoId,
                'documento_id' => $documentoId,
                'usuario_interno_id' => auth('interno')->id(),
                'payload' => $request->only(['prazo_dias', 'tipo_prazo']),
                'documento_status' => $documento->status,
                'documento_prazo_dias' => $documento->prazo_dias,
                'documento_data_vencimento' => optional($documento->data_vencimento)->format('Y-m-d'),
                'documento_tipo_documento_id' => $documento->tipo_documento_id,
                'documento_tipo_tem_prazo' => $documento->tipoDocumento?->tem_prazo,
                'documento_tipo_prazo_notificacao' => $documento->tipoDocumento?->prazo_notificacao,
                'erro' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return back()->with('error', 'Erro ao definir prazo do documento. Verifique o log da aplicação para mais detalhes.');
        }

        \Log::info('Prazo manual definido com sucesso para documento digital', [
            'estabelecimento_id' => $estabelecimentoId,
            'processo_id' => $processoId,
            'documento_id' => $documento->id,
            'prazo_dias' => $documento->prazo_dias,
            'tipo_prazo' => $documento->tipo_prazo,
            'data_vencimento' => optional($documento->data_vencimento)->format('Y-m-d'),
            'prazo_iniciado_em' => optional($documento->prazo_iniciado_em)->toDateTimeString(),
        ]);

        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Prazo definido com sucesso. A contagem foi iniciada imediatamente para este documento.');
    }

    /**
     * Reabre o prazo de um documento digital
     */
    public function reabrirPrazoDocumento($estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);
        
        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        // Verifica se está finalizado
        if (!$documento->isPrazoFinalizado()) {
            return back()->with('warning', 'O prazo deste documento não está finalizado.');
        }

        $documento->reabrirPrazo();

        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Prazo do documento reaberto com sucesso!');
    }

    /**
     * Prorroga o prazo de um documento digital em até 30 dias no total
     */
    public function prorrogarPrazoDocumento(Request $request, $estabelecimentoId, $processoId, $documentoId)
    {
        $estabelecimento = Estabelecimento::findOrFail($estabelecimentoId);
        $this->validarPermissaoAcesso($estabelecimento);

        $processo = Processo::where('estabelecimento_id', $estabelecimentoId)
            ->findOrFail($processoId);

        $documento = DocumentoDigital::where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        $validated = $request->validate([
            'dias' => 'required|integer|min:1|max:30',
            'motivo' => 'required|string|min:10|max:500',
            'senha_assinatura' => 'required|string',
        ]);

        $usuario = auth('interno')->user();

        if (!$usuario->temSenhaAssinatura()) {
            return back()->with('error', 'Você precisa configurar sua senha de assinatura digital primeiro.');
        }

        if (!Hash::check($validated['senha_assinatura'], $usuario->senha_assinatura_digital)) {
            return back()->with('error', 'Senha de assinatura digital incorreta.');
        }

        if (!$documento->temPrazo() || !$documento->data_vencimento) {
            return back()->with('error', 'Este documento não possui prazo ativo para prorrogação.');
        }

        if ($documento->isPrazoFinalizado()) {
            return back()->with('warning', 'O prazo deste documento já foi finalizado.');
        }

        if (!$documento->podeProrrogarPrazo()) {
            return back()->with('warning', 'Este documento não pode mais ter o prazo prorrogado.');
        }

        $diasSolicitados = (int) $validated['dias'];

        if ($diasSolicitados > $documento->dias_prorrogacao_disponiveis) {
            return back()->with('error', 'A prorrogação máxima disponível para esta notificação é de ' . $documento->dias_prorrogacao_disponiveis . ' dia(s).');
        }

        $motivoProrrogacao = trim($validated['motivo']);

        $resultado = DB::transaction(function () use ($documento, $diasSolicitados, $motivoProrrogacao, $processo, $request, $usuario) {
            $resultadoProrrogacao = $documento->prorrogarPrazo($diasSolicitados, $usuario->id, $motivoProrrogacao);

            ProcessoEvento::create([
                'processo_id' => $processo->id,
                'usuario_interno_id' => $usuario->id,
                'tipo_evento' => 'prazo_prorrogado',
                'titulo' => 'Prazo Prorrogado',
                'descricao' => 'Prazo do documento ' . ($documento->numero_documento ?? ($documento->nome ?? 'Documento')) . ' prorrogado em ' . $diasSolicitados . ' dia(s)',
                'dados_adicionais' => [
                    'documento_digital_id' => $documento->id,
                    'numero_documento' => $documento->numero_documento,
                    'nome_documento' => $documento->nome ?? $documento->tipoDocumento->nome ?? 'Documento',
                    'prorrogado_por_nome' => $usuario->nome,
                    'motivo' => $motivoProrrogacao,
                    'dias_prorrogados' => $diasSolicitados,
                    'dias_prorrogados_total' => $resultadoProrrogacao['dias_total'],
                    'prazo_anterior' => $resultadoProrrogacao['data_anterior']->format('Y-m-d'),
                    'prazo' => $resultadoProrrogacao['data_nova']->format('Y-m-d'),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return $resultadoProrrogacao;
        });

        return redirect()
            ->route('admin.estabelecimentos.processos.show', [$estabelecimentoId, $processoId])
            ->with('success', 'Prazo do documento prorrogado até ' . $resultado['data_nova']->format('d/m/Y') . '.');
    }
}
