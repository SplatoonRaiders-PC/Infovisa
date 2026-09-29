<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Models\Processo;
use App\Models\ProcessoAlerta;
use App\Models\DocumentoResposta;
use App\Models\DocumentoItemAtendimento;
use Illuminate\Http\Request;

class ProcessoController extends Controller
{
    /**
     * Retorna IDs dos estabelecimentos do usuário (próprios e vinculados)
     */
    private function estabelecimentoIdsDoUsuario()
    {
        $usuarioId = auth('externo')->id();
        
        return Estabelecimento::where('usuario_externo_id', $usuarioId)
            ->orWhereHas('usuariosVinculados', function($q) use ($usuarioId) {
                $q->where('usuario_externo_id', $usuarioId);
            })
            ->pluck('id');
    }

    /**
     * Retorna estabelecimentos do usuário (próprios e vinculados)
     */
    private function estabelecimentosDoUsuario()
    {
        $usuarioId = auth('externo')->id();
        
        return Estabelecimento::where('usuario_externo_id', $usuarioId)
            ->orWhereHas('usuariosVinculados', function($q) use ($usuarioId) {
                $q->where('usuario_externo_id', $usuarioId);
            });
    }

    /**
     * Verifica se o usuário tem acesso de gestor ao estabelecimento do processo.
     * Se não tiver, retorna resposta de erro (JSON para AJAX, redirect para normal).
     * 
     * @param Processo $processo
     * @param bool $isAjax Se a requisição é AJAX
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|null
     */
    private function verificarAcessoGestorProcesso(Processo $processo, bool $isAjax = false)
    {
        $estabelecimento = $processo->estabelecimento;
        
        if ($estabelecimento && $estabelecimento->usuarioEhVisualizador()) {
            $mensagem = 'Acesso restrito: sua conta possui permissão apenas para visualização. Entre em contato com o responsável do estabelecimento para solicitar permissões de edição.';
            
            if ($isAjax) {
                return response()->json([
                    'success' => false,
                    'message' => $mensagem
                ], 403);
            }
            
            return redirect()->route('company.processos.show', $processo->id)
                ->with('error', $mensagem);
        }
        
        return null;
    }

    /**
     * Bloqueia ações de continuidade quando o estabelecimento não tem responsável legal
     */
    private function bloquearSeFaltarResponsavelLegal(Processo $processo, bool $isAjax = false)
    {
        $estabelecimento = $processo->estabelecimento;

        if ($estabelecimento && $estabelecimento->precisaCadastrarResponsavelLegal()) {
            $mensagem = 'Este estabelecimento precisa ter ao menos um Responsável Legal cadastrado para acessar o processo.';

            if ($isAjax) {
                return response()->json([
                    'success' => false,
                    'message' => $mensagem,
                ], 422);
            }

            return redirect()
                ->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', $mensagem);
        }

        return null;
    }

    /**
     * Bloqueia ações de continuidade quando o estabelecimento exige responsável técnico
     */
    private function bloquearSeFaltarResponsavelTecnico(Processo $processo, bool $isAjax = false)
    {
        $estabelecimento = $processo->estabelecimento;

        if ($estabelecimento && $estabelecimento->precisaCadastrarResponsavelTecnicoPorAtividade()) {
            $mensagem = 'Este estabelecimento precisa ter ao menos um Responsável Técnico cadastrado para continuar o processo.';

            if ($isAjax) {
                return response()->json([
                    'success' => false,
                    'message' => $mensagem,
                ], 422);
            }

            return redirect()
                ->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', $mensagem);
        }

        return null;
    }

    /**
     * Busca documentos obrigatórios para um processo baseado nas atividades exercidas do estabelecimento
     * ou diretamente pelo tipo de processo (para processos especiais como Projeto Arquitetônico e Análise de Rotulagem)
     */
    private function buscarDocumentosObrigatoriosParaProcesso($processo)
    {
        $estabelecimento = $processo->estabelecimento;
        $tipoProcesso = $processo->tipoProcesso;
        $tipoProcessoId = $tipoProcesso->id ?? null;
        
        if (!$tipoProcessoId) {
            return collect();
        }

        // PJ Unidade Móvel: usa tabela documento_unidade_movel em vez de listas_documento
        if ($tipoProcesso->codigo === 'credenciamento_movel') {
            return $this->buscarDocumentosObrigatoriosUnidadeMovel($processo);
        }

        // Verifica se é um processo especial (Projeto Arquitetônico ou Análise de Rotulagem)
        $isProcessoEspecial = $tipoProcesso && in_array($tipoProcesso->codigo, ['projeto_arquitetonico', 'analise_rotulagem']);

        // Pega as atividades exercidas do estabelecimento (apenas as marcadas)
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        
        // Para processos especiais, não precisa de atividades
        // Para processos normais, se não tem atividades, retorna vazio
        // (mas ainda considera documentos manuais definidos pela vigilância municipal)
        if (!$isProcessoEspecial && empty($atividadesExercidas)) {
            return $this->adicionarDocumentosManuaisAoChecklist(collect(), $processo);
        }

        $atividadeIds = collect();
        
        // Só busca atividades se não for processo especial e tiver atividades exercidas
        if (!$isProcessoEspecial && !empty($atividadesExercidas)) {
            // Extrai os códigos CNAE das atividades exercidas
            $codigosCnae = collect($atividadesExercidas)->map(function($atividade) {
                $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
            })->filter()->values()->toArray();

            if (!empty($codigosCnae)) {
                // Busca as atividades cadastradas que correspondem aos CNAEs exercidos
                $atividadeIds = \App\Models\Atividade::where('ativo', true)
                    ->where(function($query) use ($codigosCnae) {
                        foreach ($codigosCnae as $codigo) {
                            $query->orWhere('codigo_cnae', $codigo);
                        }
                    })
                    ->pluck('id');
            }
        }

        // Busca as listas de documentos aplicáveis para este tipo de processo
        $query = \App\Models\ListaDocumento::where('ativo', true)
            ->where('tipo_processo_id', $tipoProcessoId)
            ->with(['tiposDocumentoObrigatorio' => function($q) {
                $q->orderBy('lista_documento_tipo.ordem');
            }]);

        // Para processos especiais: busca listas SEM atividades vinculadas (vinculadas diretamente ao tipo de processo)
        // Para processos normais: busca listas COM atividades que correspondem às do estabelecimento
        if ($isProcessoEspecial) {
            // Busca listas que NÃO têm atividades vinculadas (listas de processos especiais)
            $query->whereDoesntHave('atividades');
        } else {
            // Busca listas que têm atividades correspondentes
            if ($atividadeIds->isEmpty()) {
                return $this->adicionarDocumentosManuaisAoChecklist(collect(), $processo);
            }
            $query->whereHas('atividades', function($q) use ($atividadeIds) {
                $q->whereIn('atividades.id', $atividadeIds);
            });
        }

        // Filtra por escopo baseado na competência do estabelecimento
        $isEstadual = $estabelecimento->isCompetenciaEstadual();
        $query->where(function($q) use ($estabelecimento, $isEstadual) {
            if ($isEstadual) {
                // Estabelecimento estadual: apenas listas estaduais
                $q->where('escopo', 'estadual');
            } else {
                // Estabelecimento municipal: listas estaduais + municipais do seu município
                $q->where('escopo', 'estadual');
                if ($estabelecimento->municipio_id) {
                    $q->orWhere(function($q2) use ($estabelecimento) {
                        $q2->where('escopo', 'municipal')
                           ->where('municipio_id', $estabelecimento->municipio_id);
                    });
                }
            }
        });

        $listas = $query->get();

        // Consolida os documentos de todas as listas aplicáveis
        $documentos = collect();
        
        // Identifica IDs de pastas que pertencem a unidades
        $pastasUnidadeIds = $processo->pastas()
            ->whereNotNull('unidade_id')
            ->pluck('id')
            ->toArray();
        
        // Busca documentos já enviados neste processo com seus status
        // Para os documentos base (checklist geral), considera apenas documentos
        // que NÃO pertencem a pastas de unidade (sem pasta ou pasta sem unidade)
        $documentosEnviadosInfo = $processo->documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->filter(function ($doc) use ($pastasUnidadeIds) {
                return empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasUnidadeIds);
            })
            ->groupBy('tipo_documento_obrigatorio_id')
            ->map(function($docs) {
                // Pega o documento mais recente
                $ultimo = $docs->sortByDesc('created_at')->first();
                return [
                    'status' => $ultimo->status_aprovacao,
                    'id' => $ultimo->id,
                ];
            });

        // Determina o escopo de competência e tipo de setor do estabelecimento
        $escopoCompetencia = $tipoProcesso->resolverEscopoCompetencia($estabelecimento);
        $tipoSetorEnum = $estabelecimento->tipo_setor;
        $tipoSetor = $tipoSetorEnum instanceof \App\Enums\TipoSetor ? $tipoSetorEnum->value : ($tipoSetorEnum ?? 'privado');

        // ADICIONA DOCUMENTOS COMUNS PRIMEIRO (filtrados por escopo, tipo_setor e tipo_processo)
        // Documentos comuns são aplicados automaticamente quando há listas configuradas
        // para o tipo de processo e atividades do estabelecimento
        $documentosComuns = \App\Models\TipoDocumentoObrigatorio::where('ativo', true)
            ->where('documento_comum', true)
            ->where(function($q) use ($tipoProcessoId) {
                // Filtra por tipo_processo_id: deve ser null (todos) ou igual ao tipo do processo
                $q->whereNull('tipo_processo_id')
                  ->orWhere('tipo_processo_id', $tipoProcessoId);
            })
            ->where(function($q) use ($escopoCompetencia) {
                $q->where('escopo_competencia', 'todos')
                  ->orWhere('escopo_competencia', $escopoCompetencia);
            })
            ->where(function($q) use ($tipoSetor) {
                $q->where('tipo_setor', 'todos')
                  ->orWhere('tipo_setor', $tipoSetor);
            })
            ->ordenado()
            ->get();
        
        foreach ($documentosComuns as $doc) {
            $infoEnviado = $documentosEnviadosInfo->get($doc->id);
            $statusEnvio = $infoEnviado['status'] ?? null;
            $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);
            
            $documentos->push([
                'id' => $doc->id,
                'nome' => $doc->nome,
                'descricao' => $doc->descricao,
                'obrigatorio' => true, // Documentos comuns são sempre obrigatórios
                'observacao' => null,
                'lista_nome' => 'Documentos Comuns',
                'ja_enviado' => $jaEnviado,
                'status_envio' => $statusEnvio,
                'documento_comum' => true, // Flag para identificar
            ]);
        }

        // ADICIONA DOCUMENTOS ESPECÍFICOS DAS LISTAS (filtrados por tipo_setor)
        foreach ($listas as $lista) {
            foreach ($lista->tiposDocumentoObrigatorio as $doc) {
                // NÃO filtra por escopo_competencia do documento aqui — se o admin colocou
                // o documento numa lista específica (municipal/estadual), a intenção é que
                // ele apareça nessa lista independente do campo escopo_competencia individual.
                // O escopo da LISTA já foi filtrado na query acima.
                // Mantém apenas o filtro de tipo_setor (público vs privado).
                $aplicaTipoSetor = $doc->tipo_setor === 'todos' || $doc->tipo_setor === $tipoSetor;

                if (!$aplicaTipoSetor) {
                    continue; // Pula documentos que não se aplicam ao tipo de setor
                }
                
                // Evita duplicatas pelo ID do tipo de documento
                if (!$documentos->contains('id', $doc->id)) {
                    $infoEnviado = $documentosEnviadosInfo->get($doc->id);
                    $statusEnvio = $infoEnviado['status'] ?? null;
                    
                    // Considera como "já enviado" se está pendente ou aprovado
                    // Se rejeitado, permite reenviar
                    $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);
                    
                    $documentos->push([
                        'id' => $doc->id,
                        'nome' => $doc->nome,
                        'descricao' => $doc->descricao,
                        'obrigatorio' => $doc->pivot->obrigatorio,
                        'observacao' => $doc->pivot->observacao,
                        'lista_nome' => $lista->nome,
                        'ja_enviado' => $jaEnviado,
                        'status_envio' => $statusEnvio,
                        'documento_comum' => false,
                    ]);
                } else {
                    // Se já existe, verifica se deve ser obrigatório (se qualquer lista marcar como obrigatório)
                    $documentos = $documentos->map(function($item) use ($doc) {
                        if ($item['id'] === $doc->id && $doc->pivot->obrigatorio) {
                            $item['obrigatorio'] = true;
                        }
                        return $item;
                    });
                }
            }
        }

        // ADICIONA DOCUMENTOS DEFINIDOS MANUALMENTE PELA VIGILÂNCIA MUNICIPAL
        $documentos = $this->adicionarDocumentosManuaisAoChecklist($documentos, $processo, $documentosEnviadosInfo);

        // Ordena: documentos comuns primeiro, depois obrigatórios, depois por nome
        return $documentos->sortBy([
            ['documento_comum', 'desc'], // Comuns primeiro
            ['obrigatorio', 'desc'],      // Depois obrigatórios
            ['nome', 'asc'],              // Por fim, ordem alfabética
        ])->values();
    }

    /**
     * Adiciona ao checklist os documentos obrigatórios definidos manualmente pela
     * vigilância sanitária municipal (município com modo "documentos manuais" habilitado).
     * Aplica-se apenas a processos de licenciamento de estabelecimentos municipais.
     */
    private function adicionarDocumentosManuaisAoChecklist($documentos, $processo, $documentosEnviadosInfo = null)
    {
        $estabelecimento = $processo->estabelecimento;
        $tipoProcesso = $processo->tipoProcesso;

        if (!$estabelecimento || !$tipoProcesso || $tipoProcesso->codigo !== 'licenciamento') {
            return $documentos->values();
        }

        if (!$estabelecimento->usaDocumentosManuais()) {
            return $documentos->values();
        }

        $documentosManuais = $estabelecimento->documentosManuais()
            ->where('ativo', true)
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get();

        if ($documentosManuais->isEmpty()) {
            return $documentos->values();
        }

        if ($documentosEnviadosInfo === null) {
            $pastasUnidadeIds = $processo->pastas()
                ->whereNotNull('unidade_id')
                ->pluck('id')
                ->toArray();

            $documentosEnviadosInfo = $processo->documentos
                ->whereNotNull('tipo_documento_obrigatorio_id')
                ->filter(function ($doc) use ($pastasUnidadeIds) {
                    return empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasUnidadeIds);
                })
                ->groupBy('tipo_documento_obrigatorio_id')
                ->map(function ($docs) {
                    $ultimo = $docs->sortByDesc('created_at')->first();
                    return [
                        'status' => $ultimo->status_aprovacao,
                        'id' => $ultimo->id,
                    ];
                });
        }

        // Modo manual: a lista é EXCLUSIVAMENTE os documentos definidos pela
        // vigilância municipal. Descarta os documentos automáticos (listas/comuns).
        $documentos = collect();

        foreach ($documentosManuais as $doc) {
            if ($documentos->contains('id', $doc->id)) {
                continue;
            }

            $infoEnviado = $documentosEnviadosInfo->get($doc->id);
            $statusEnvio = $infoEnviado['status'] ?? null;
            $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);

            $documentos->push([
                'id' => $doc->id,
                'nome' => $doc->nome,
                'descricao' => $doc->descricao,
                'obrigatorio' => true,
                'observacao' => null,
                'lista_nome' => 'Definidos pela Vigilância Sanitária',
                'ja_enviado' => $jaEnviado,
                'status_envio' => $statusEnvio,
                'documento_comum' => false,
            ]);
        }

        return $documentos->sortBy([
            ['documento_comum', 'desc'],
            ['obrigatorio', 'desc'],
            ['nome', 'asc'],
        ])->values();
    }

    /**
     * Busca documentos obrigatórios específicos para processos de Unidade Móvel.
     * Usa a tabela documento_unidade_movel em vez da lógica de listas_documento.
     */
    private function buscarDocumentosObrigatoriosUnidadeMovel($processo)
    {
        $estabelecimento = $processo->estabelecimento;

        // CNAEs do estabelecimento que são de unidade móvel
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        $codigosCnae = collect($atividadesExercidas)->map(function ($atividade) {
            $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
        })->filter()->values()->toArray();

        $docsConfig = \App\Models\DocumentoUnidadeMovel::paraEstesCnaes($codigosCnae);

        if ($docsConfig->isEmpty()) {
            return collect();
        }

        // Identifica pastas de município (protegidas)
        $pastasUnidadeIds = $processo->pastas()
            ->where('protegida', true)
            ->pluck('id')
            ->toArray();

        // Documentos já enviados (raiz — sem pasta de município)
        $documentosEnviadosInfo = $processo->documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->filter(fn($doc) => empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasUnidadeIds))
            ->groupBy('tipo_documento_obrigatorio_id')
            ->map(function ($docs) {
                $ultimo = $docs->sortByDesc('created_at')->first();
                return ['status' => $ultimo->status_aprovacao, 'id' => $ultimo->id];
            });

        $documentos = collect();

        foreach ($docsConfig->where('escopo', 'geral') as $docConf) {
            $tipoDoc = $docConf->tipoDocumento;
            if (!$tipoDoc) continue;

            $infoEnviado = $documentosEnviadosInfo->get($tipoDoc->id);
            $statusEnvio = $infoEnviado['status'] ?? null;
            $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);

            if (!$documentos->contains('id', $tipoDoc->id)) {
                $documentos->push([
                    'id' => $tipoDoc->id,
                    'nome' => $tipoDoc->nome,
                    'descricao' => $tipoDoc->descricao,
                    'obrigatorio' => $docConf->obrigatorio,
                    'observacao' => null,
                    'lista_nome' => 'Documentos Gerais - Unidade Móvel',
                    'ja_enviado' => $jaEnviado,
                    'status_envio' => $statusEnvio,
                    'documento_comum' => true,
                ]);
            }
        }

        return $documentos->sortBy([
            ['obrigatorio', 'desc'],
            ['nome', 'asc'],
        ])->values();
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
            ];
        }

        return $resultado;
    }

    /**
     * Lista todos os alertas dos processos do usuário
     */
    public function alertasIndex(Request $request)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        $processoIds = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->whereHas('tipoProcesso', fn($q) => $q->where('usuario_externo_pode_visualizar', true))
            ->pluck('id');
        
        $query = ProcessoAlerta::whereIn('processo_id', $processoIds)
            ->with(['processo.estabelecimento', 'processo.tipoProcesso', 'usuarioCriador']);
        
        // Filtro por status
        if ($request->filled('status')) {
            if ($request->status === 'pendente') {
                $query->where('status', '!=', 'concluido');
            } elseif ($request->status === 'concluido') {
                $query->where('status', 'concluido');
            }
        }
        
        // Filtro por estabelecimento
        if ($request->filled('estabelecimento_id')) {
            $query->whereHas('processo', function($q) use ($request) {
                $q->where('estabelecimento_id', $request->estabelecimento_id);
            });
        }
        
        // Ordenação: vencidos primeiro, depois por data
        $alertas = $query->orderByRaw("CASE WHEN status != 'concluido' AND data_alerta < CURRENT_DATE THEN 0 ELSE 1 END")
            ->orderBy('data_alerta', 'asc')
            ->paginate(15);
        
        // Estatísticas
        $totalAlertas = ProcessoAlerta::whereIn('processo_id', $processoIds)->count();
        $alertasPendentes = ProcessoAlerta::whereIn('processo_id', $processoIds)
            ->where('status', '!=', 'concluido')
            ->count();
        $alertasVencidos = ProcessoAlerta::whereIn('processo_id', $processoIds)
            ->where('status', '!=', 'concluido')
            ->where('data_alerta', '<', now()->toDateString())
            ->count();
        $alertasConcluidos = ProcessoAlerta::whereIn('processo_id', $processoIds)
            ->where('status', 'concluido')
            ->count();
        
        $estatisticas = [
            'total' => $totalAlertas,
            'pendentes' => $alertasPendentes,
            'vencidos' => $alertasVencidos,
            'concluidos' => $alertasConcluidos,
        ];
        
        // Lista de estabelecimentos para filtro
        $estabelecimentos = $this->estabelecimentosDoUsuario()
            ->orderBy('nome_fantasia')
            ->get();
        
        // Documentos pendentes de visualização
        $documentosPendentes = \App\Models\DocumentoDigital::whereIn('processo_id', $processoIds)
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->whereDoesntHave('visualizacoes')
            ->with(['processo.estabelecimento', 'tipoDocumento'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Documentos rejeitados que precisam de correção
        $documentosRejeitados = \App\Models\ProcessoDocumento::whereIn('processo_id', $processoIds)
            ->where('status_aprovacao', 'rejeitado')
            ->with(['processo.estabelecimento', 'tipoDocumentoObrigatorio'])
            ->orderBy('updated_at', 'desc')
            ->get();
        
        // Documentos com prazo pendente (notificações que precisam de resposta)
        $documentosComPrazo = \App\Models\DocumentoDigital::whereIn('processo_id', $processoIds)
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->where('prazo_notificacao', true)
            ->whereNotNull('prazo_iniciado_em')
            ->whereNull('prazo_finalizado_em')
            ->with(['processo.estabelecimento', 'tipoDocumento'])
            ->orderBy('data_vencimento', 'asc')
            ->get()
            ->filter(fn ($doc) => $doc->todasAssinaturasCompletas());
        
        return view('company.alertas.index', compact('alertas', 'estatisticas', 'estabelecimentos', 'documentosPendentes', 'documentosRejeitados', 'documentosComPrazo'));
    }

    public function index(Request $request)
    {
        // IDs dos estabelecimentos do usuário
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $query = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with(['estabelecimento', 'tipoProcesso'])
            ->whereHas('tipoProcesso', function($q) {
                $q->where('usuario_externo_pode_visualizar', true);
            });
        
        // Filtro por status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Filtro por estabelecimento
        if ($request->filled('estabelecimento_id')) {
            $query->where('estabelecimento_id', $request->estabelecimento_id);
        }
        
        // Busca
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('numero_processo', 'like', "%{$search}%");
            });
        }
        
        $processos = $query->orderBy('created_at', 'desc')->paginate(10);
        
        // Estatísticas (apenas processos visíveis para usuário externo)
        $baseQuery = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->whereHas('tipoProcesso', fn($q) => $q->where('usuario_externo_pode_visualizar', true));
        $estatisticas = [
            'total' => (clone $baseQuery)->count(),
            'em_andamento' => (clone $baseQuery)->where('status', 'em_andamento')->count(),
            'concluidos' => (clone $baseQuery)->where('status', 'concluido')->count(),
            'arquivados' => (clone $baseQuery)->where('status', 'arquivado')->count(),
        ];
        
        // Lista de estabelecimentos para filtro
        $estabelecimentos = $this->estabelecimentosDoUsuario()
            ->orderBy('nome_fantasia')
            ->get();
        
        return view('company.processos.index', compact('processos', 'estatisticas', 'estabelecimentos'));
    }
    
    public function show($id)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with(['estabelecimento.responsaveisLegais', 'tipoProcesso', 'documentos.usuarioExterno', 'alertas', 'pastas', 'unidades'])
            ->findOrFail($id);
        
        // Bloquear acesso se o tipo de processo não permite visualização por usuário externo
        if (!$processo->tipoProcesso->usuario_externo_pode_visualizar) {
            abort(403, 'Este tipo de processo não está disponível para visualização.');
        }
        
        // Documentos separados por status
        $documentosAprovados = $processo->documentos->where('status_aprovacao', 'aprovado');
        $documentosPendentes = $processo->documentos->where('status_aprovacao', 'pendente');
        
        // IDs de tipo_documento_obrigatorio que já têm documento pendente ou aprovado
        $tiposComDocumentoPendenteOuAprovado = $processo->documentos
            ->whereIn('status_aprovacao', ['pendente', 'aprovado'])
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->pluck('tipo_documento_obrigatorio_id')
            ->toArray();
        
        // Documentos rejeitados que ainda não foram substituídos (não têm correção pendente)
        $documentosRejeitados = $processo->documentos->where('status_aprovacao', 'rejeitado')
            ->filter(function ($doc) use ($processo, $tiposComDocumentoPendenteOuAprovado) {
                // Se tem tipo_documento_obrigatorio_id e já existe pendente/aprovado para esse tipo, não mostra
                if ($doc->tipo_documento_obrigatorio_id && in_array($doc->tipo_documento_obrigatorio_id, $tiposComDocumentoPendenteOuAprovado)) {
                    return false;
                }
                // Verifica se existe algum documento que substitui este (método antigo)
                return !$processo->documentos->where('documento_substituido_id', $doc->id)->count();
            });
        
        // Documentos digitais da vigilância (assinados e não sigilosos)
        // Inclui documentos de lote (OS com múltiplos estabelecimentos) via processos_ids
        $documentosVigilancia = \App\Models\DocumentoDigital::where(function ($q) use ($processo) {
                $q->where('processo_id', $processo->id)
                  ->orWhereJsonContains('processos_ids', $processo->id)
                  ->orWhereJsonContains('processos_ids', (string) $processo->id);
            })
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->with([
                'tipoDocumento',
                'usuarioCriador',
                'assinaturas',
                'respostas.usuarioExterno',
                'respostas.itemAtendimento',
                'itensAtendimento.respostaAtual.usuarioExterno',
                'itensAtendimento.respostaAtual.avaliadoPor',
            ])
            ->get()
            ->unique('id')
            ->filter(function ($doc) {
                // Só mostra documentos com todas as assinaturas completas
                return $doc->todasAssinaturasCompletas();
            });

        // Anexos PDF inseridos pela Vigilância no processo
        // (não inclui documentos digitais/ordens de serviço e mantém regra de mostrar apenas PDF)
        $anexosInternosPdf = $processo->documentos
            ->filter(function ($doc) {
                $extensao = strtolower($doc->extensao ?? pathinfo($doc->nome_arquivo ?? '', PATHINFO_EXTENSION));

                return $doc->tipo_usuario === 'interno'
                    && $extensao === 'pdf'
                    && $doc->tipo_documento !== 'documento_digital'
                    && $doc->tipo_documento !== 'ordem_servico';
            });

        // Verifica se algum documento de notificação precisa ter o prazo iniciado automaticamente (§1º - 5 dias úteis)
        foreach ($documentosVigilancia as $doc) {
            if ($doc->prazo_notificacao && !$doc->prazo_iniciado_em) {
                $doc->verificarInicioAutomaticoPrazo();
            }
        }
        
        // Mescla documentos da vigilância e aprovados, ordenando por data mais recente
        $todosDocumentos = collect();
        
        // Adiciona documentos da vigilância com tipo identificador
        foreach ($documentosVigilancia as $doc) {
            $todosDocumentos->push([
                'tipo' => 'vigilancia',
                'documento' => $doc,
                'data' => $doc->data_disponibilizacao,
                'pasta_id' => $doc->pasta_id,
                'unidade_id' => $doc->unidade_id ?? null,
            ]);
        }
        
        // Adiciona documentos aprovados com tipo identificador
        foreach ($documentosAprovados as $doc) {
            $todosDocumentos->push([
                'tipo' => 'aprovado',
                'documento' => $doc,
                'data' => $doc->created_at,
                'pasta_id' => $doc->pasta_id,
                'unidade_id' => $doc->unidade_id ?? null,
            ]);
        }

        // Adiciona anexos internos em PDF com tipo identificador
        foreach ($anexosInternosPdf as $doc) {
            $todosDocumentos->push([
                'tipo' => 'anexo_interno',
                'documento' => $doc,
                'data' => $doc->created_at,
                'pasta_id' => $doc->pasta_id,
                'unidade_id' => $doc->unidade_id ?? null,
            ]);
        }
        
        // Ordena por pasta (agrupado) e por data decrescente (mais recente primeiro)
        // Itens sem pasta ficam no topo, seguidos das pastas na ordem configurada
        $ordemPastas = $processo->pastas()
            ->orderBy('ordem')
            ->orderBy('nome')
            ->pluck('id')
            ->values()
            ->flip();

        $todosDocumentos = $todosDocumentos
            ->sort(function ($itemA, $itemB) use ($ordemPastas) {
                $pastaIdA = $itemA['pasta_id'] ?? null;
                $pastaIdB = $itemB['pasta_id'] ?? null;

                $ordemA = $pastaIdA ? ($ordemPastas[$pastaIdA] ?? 999999) : -1;
                $ordemB = $pastaIdB ? ($ordemPastas[$pastaIdB] ?? 999999) : -1;

                if ($ordemA !== $ordemB) {
                    return $ordemA <=> $ordemB;
                }

                return $itemB['data'] <=> $itemA['data'];
            })
            ->values();
        
        // Alertas do processo
        $alertas = $processo->alertas()->orderBy('data_alerta', 'asc')->get();
        
        // Pastas do processo
        $pastas = $processo->pastas()->orderBy('ordem')->get();

        // Busca documentos obrigatórios baseados nas atividades exercidas
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
                    // Para cada pasta de unidade, replica os docs obrigatórios com status de envio específico
                    $docsUnidade = $documentosObrigatorios->map(function ($doc) use ($processo, $pasta) {
                        // Busca se já foi enviado para ESTA pasta
                        $docEnviado = $processo->documentos
                            ->where('tipo_documento_obrigatorio_id', $doc['id'])
                            ->where('pasta_id', $pasta->id)
                            ->sortByDesc('created_at')
                            ->first();

                        $statusEnvio = $docEnviado ? $docEnviado->status_aprovacao : null;
                        $jaEnviado = in_array($statusEnvio, ['pendente', 'aprovado']);

                        return array_merge($doc, [
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
                    ];
                }
            }
        }
        
        // Busca documentos de ajuda vinculados ao tipo de processo
        $documentosAjuda = \App\Models\DocumentoAjuda::ativos()
            ->ordenado()
            ->paraTipoProcesso($processo->tipo)
            ->visiveisParaProcesso($processo)
            ->get();
        
        // Verifica se o estabelecimento precisa cadastrar equipamentos de imagem para este tipo de processo
        $precisaCadastrarEquipamentos = $processo->estabelecimento->precisaCadastrarEquipamentosImagemParaProcesso($processo->tipo);
        $precisaCadastrarResponsavelLegal = $processo->estabelecimento->precisaCadastrarResponsavelLegal();
        $precisaCadastrarResponsavelTecnico = $processo->estabelecimento->precisaCadastrarResponsavelTecnicoPorAtividade();

        // Unidades disponíveis para adicionar (todas do tipo de processo)
        $unidadesDisponiveis = collect();
        $tipoProcessoTemUnidades = false;
        if ($processo->tipoProcesso && $processo->tipoProcesso->unidades()->ativas()->count() > 0) {
            $tipoProcessoTemUnidades = true;
            $unidadesDisponiveis = $processo->tipoProcesso->unidades()
                ->ativas()
                ->ordenadas()
                ->get();
        }

        // Calcula prazo geral da fila pública
        $avisoFilaPublica = null;
        $avisoFilaPublicaPorUnidade = collect();
        if ($processo->status !== 'arquivado' &&
            $processo->tipoProcesso &&
            $processo->tipoProcesso->exibir_fila_publica &&
            $processo->tipoProcesso->prazo_fila_publica > 0) {

            // Verifica docs obrigatórios base
            $docsObrigBase = $documentosObrigatorios->where('obrigatorio', true);
            $todosAprovadosBase = true;
            $dataDocCompletos = null;

            if ($docsObrigBase->isEmpty()) {
                $todosAprovadosBase = true;
                $dataDocCompletos = $processo->created_at;
            } else {
                foreach ($docsObrigBase as $docObrig) {
                    if ($docObrig['status_envio'] !== 'aprovado') {
                        $todosAprovadosBase = false;
                        break;
                    }
                    $docP = $processo->documentos
                        ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                        ->where('status_aprovacao', 'aprovado')
                        ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                        ->first();
                    $dataRef = $docP?->aprovado_em ?? $docP?->updated_at;
                    if ($dataRef && (!$dataDocCompletos || $dataRef > $dataDocCompletos)) {
                        $dataDocCompletos = $dataRef;
                    }
                }
            }

            if ($todosAprovadosBase && $dataDocCompletos) {
                $grupoRisco = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                $prazo = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRisco);
                $dataRefPrazo = $processo->getDataReferenciaFilaPublica($dataDocCompletos);
                $dataLimite = $processo->calcularDataLimiteFilaPublica($dataDocCompletos, $prazo);
                $diasRestantes = (int) round(\Carbon\Carbon::now()->diffInDays($dataLimite, false));

                $avisoFilaPublica = [
                    'prazo' => $prazo,
                    'data_documentos_completos' => $dataDocCompletos,
                    'data_referencia_prazo' => $dataRefPrazo,
                    'dias_restantes' => $diasRestantes,
                    'atrasado' => $diasRestantes < 0,
                    'pausado' => $processo->status === 'parado',
                    'prazo_reiniciado' => $processo->prazoFilaPublicaFoiReiniciado($dataDocCompletos),
                ];
            }

            // Calcula prazo por unidade
            if ($documentosObrigatoriosPorUnidade instanceof \Illuminate\Support\Collection && $documentosObrigatoriosPorUnidade->isNotEmpty()) {
                foreach ($documentosObrigatoriosPorUnidade as $pastaId => $info) {
                    // Pula pastas concluídas/deferidas
                    $pastaInfo = $info['pasta'] ?? null;
                    if ($pastaInfo && ($pastaInfo->status ?? 'ativo') === 'concluida') {
                        continue;
                    }

                    $docsObrigU = $info['documentos']->where('obrigatorio', true);
                    if ($docsObrigU->isEmpty()) continue;

                    $todosAprovU = true;
                    $dataUltimoAprovU = null;

                    foreach ($docsObrigU as $docObrig) {
                        if ($docObrig['status_envio'] !== 'aprovado') {
                            $todosAprovU = false;
                            break;
                        }
                        $docP = $processo->documentos
                            ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                            ->where('pasta_id', $pastaId)
                            ->where('status_aprovacao', 'aprovado')
                            ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                            ->first();
                        $dataRef = $docP?->aprovado_em ?? $docP?->updated_at;
                        if ($dataRef && (!$dataUltimoAprovU || $dataRef > $dataUltimoAprovU)) {
                            $dataUltimoAprovU = $dataRef;
                        }
                    }

                    $pastaModelU = $pastaInfo instanceof \App\Models\ProcessoPasta ? $pastaInfo : \App\Models\ProcessoPasta::find($pastaId);

                    if ($todosAprovU && $dataUltimoAprovU && $pastaModelU) {
                        $grupoRiscoU = $processo->estabelecimento ? $processo->estabelecimento->getGrupoRisco() : null;
                        $prazoU = $processo->tipoProcesso->getPrazoFilaPublicaPorRisco($grupoRiscoU);

                        // Considera parada/reinício do processo e da própria unidade
                        $prazoCalculadoU = $processo->calcularPrazoFilaPublicaUnidade($pastaModelU, $dataUltimoAprovU, (int) $prazoU);

                        $avisoFilaPublicaPorUnidade[$pastaId] = array_merge($prazoCalculadoU, [
                            'nome' => $info['nome'],
                            'prazo' => $prazoU,
                            'data_documentos_completos' => $dataUltimoAprovU,
                        ]);
                    }
                }
            }
        }
        
        return view('company.processos.show', compact(
            'processo',
            'documentosAprovados',
            'documentosPendentes',
            'documentosRejeitados',
            'documentosVigilancia',
            'todosDocumentos',
            'alertas',
            'documentosObrigatorios',
            'documentosObrigatoriosPorUnidade',
            'pastas',
            'documentosAjuda',
            'precisaCadastrarEquipamentos',
            'precisaCadastrarResponsavelLegal',
            'precisaCadastrarResponsavelTecnico',
            'unidadesDisponiveis',
            'tipoProcessoTemUnidades',
            'avisoFilaPublica',
            'avisoFilaPublicaPorUnidade'
        ));
    }

    /**
     * Adicionar nova unidade ao processo em andamento
     */
    public function adicionarUnidade(Request $request, $id)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with(['tipoProcesso'])
            ->findOrFail($id);

        // Permite em processos abertos ou parados (ex.: unidade indeferida aguardando nova unidade)
        if (!in_array($processo->status, ['aberto', 'parado'])) {
            return back()->with('error', 'Só é possível adicionar unidades em processos abertos ou parados.');
        }

        // Verifica se o tipo de processo tem unidades
        $unidadesDoTipo = $processo->tipoProcesso->unidades()->ativas()->pluck('unidades.id')->toArray();
        if (empty($unidadesDoTipo)) {
            return back()->with('error', 'Este tipo de processo não possui unidades configuradas.');
        }

        $request->validate([
            'unidade_id' => 'required|exists:unidades,id',
            'nome_unidade' => 'nullable|string|max:255',
        ]);

        $unidadeId = $request->unidade_id;

        // Verifica se a unidade pertence ao tipo de processo
        if (!in_array((int) $unidadeId, $unidadesDoTipo)) {
            return back()->with('error', 'Esta unidade não está disponível para este tipo de processo.');
        }

        // Vincula a unidade (permite duplicatas - syncWithoutDetaching não duplica no pivot)
        $processo->unidades()->syncWithoutDetaching([$unidadeId]);

        // Conta quantas pastas desta unidade já existem para nomear incrementalmente
        $unidade = \App\Models\Unidade::find($unidadeId);
        $pastasExistentes = $processo->pastas()->where('unidade_id', $unidadeId)->count();
        $nomePasta = $unidade->nome;
        if ($pastasExistentes > 0) {
            $nomePasta = $unidade->nome . ' (' . ($pastasExistentes + 1) . ')';
        }
        // Nome informado pelo usuário (ex.: "UTI Pediátrica") tem prioridade sobre o automático
        if (filled($request->nome_unidade)) {
            $nomePasta = trim($request->nome_unidade);
        }

        // Cria a pasta automática
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

        return back()->with('success', 'Unidade "' . $nomePasta . '" adicionada com sucesso! Envie os documentos obrigatórios.');
    }

    public function uploadDocumento(Request $request, $id)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($id);

        // Bloquear se tipo de processo não permite visualização por usuário externo
        if (!$processo->tipoProcesso->usuario_externo_pode_visualizar) {
            abort(403, 'Este tipo de processo não está disponível para visualização.');
        }

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo, $request->ajax())) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo, $request->ajax())) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo, $request->ajax())) {
            return $redirect;
        }

        $request->validate([
            'arquivo' => 'required|file|max:30720|mimes:pdf',
            'observacoes' => 'nullable|string|max:500',
            'tipo_documento_obrigatorio_id' => 'nullable|exists:tipos_documento_obrigatorio,id',
            'documento_id' => 'nullable|integer|exists:processo_documentos,id',
            'unidade_id' => 'nullable|exists:unidades,id',
            'pasta_id_unidade' => 'nullable|integer|exists:processo_pastas,id',
        ], [
            'arquivo.required' => 'Selecione um arquivo para enviar.',
            'arquivo.max' => 'O arquivo não pode ter mais de 30MB.',
            'arquivo.mimes' => 'Apenas arquivos PDF são permitidos.',
        ]);

        $arquivo = $request->file('arquivo');
        $nomeOriginalUpload = $arquivo->getClientOriginalName();
        $extensao = $arquivo->getClientOriginalExtension();
        $tamanho = $arquivo->getSize();

        // Busca documento rejeitado antecipadamente (reenvio via modal)
        $documentoParaSubstituir = null;
        if ($request->documento_id) {
            $documentoParaSubstituir = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
                ->where('id', $request->documento_id)
                ->where('status_aprovacao', 'rejeitado')
                ->first();
        }

        // Busca o nome do tipo de documento se informado (ou herdado do documento rejeitado)
        $tipoDocumentoId = $request->tipo_documento_obrigatorio_id
            ?: $documentoParaSubstituir?->tipo_documento_obrigatorio_id;
        $observacoes = $request->observacoes;
        $nomeDocumento = null;
        
        if ($tipoDocumentoId) {
            $tipoDoc = \App\Models\TipoDocumentoObrigatorio::find($tipoDocumentoId);
            if ($tipoDoc) {
                $nomeDocumento = $tipoDoc->nome;
                if (!$observacoes) {
                    $observacoes = $tipoDoc->nome;
                }
            }
        }

        // Define o nome do arquivo: se for documento obrigatório, usa o nome da lista
        // Se for reenvio de rejeitado, preserva o nome original do documento
        // Caso contrário, usa o nome original do arquivo enviado
        if ($nomeDocumento) {
            // Remove caracteres especiais e espaços do nome do documento
            $nomeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomeDocumento);
            $nomeArquivo = $nomeBase . '_' . time() . '.' . strtolower($extensao);
            // Limita o nome_original a 990 caracteres para segurança (campo é varchar 1000)
            $nomeOriginal = substr($nomeDocumento . '.' . strtolower($extensao), 0, 990);
        } elseif ($documentoParaSubstituir?->nome_original) {
            $nomeArquivo = time() . '_' . uniqid() . '.' . $extensao;
            $nomeOriginal = $documentoParaSubstituir->nome_original;
        } else {
            $nomeArquivo = time() . '_' . uniqid() . '.' . $extensao;
            $nomeOriginal = $nomeOriginalUpload;
        }
        
        // Salva o arquivo
        $caminho = $arquivo->storeAs(
            'processos/' . $processo->id . '/documentos',
            $nomeArquivo,
            'public'
        );

        // Verifica se é um documento obrigatório e se já existe um rejeitado do mesmo tipo
        $documentoExistente = null;
        if ($tipoDocumentoId) {
            $documentoExistente = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
                ->where('tipo_documento_obrigatorio_id', $tipoDocumentoId)
                ->where('status_aprovacao', 'rejeitado')
                ->first();
        }
        
        // Se foi passado documento_id para substituir, usa o documento rejeitado específico
        if ($documentoParaSubstituir) {
            $documentoExistente = $documentoParaSubstituir;
        }

        if ($documentoExistente) {
            // Guarda histórico da rejeição anterior
            $historicoRejeicao = $documentoExistente->historico_rejeicao ?? [];
            $historicoRejeicao[] = [
                'arquivo_anterior' => $documentoExistente->nome_original,
                'motivo' => $documentoExistente->motivo_rejeicao,
                'rejeitado_em' => $documentoExistente->updated_at->toISOString(),
            ];
            
            // Remove o arquivo antigo do storage
            if ($documentoExistente->caminho && \Storage::disk('public')->exists($documentoExistente->caminho)) {
                \Storage::disk('public')->delete($documentoExistente->caminho);
            }
            
            // Atualiza o documento existente com o novo arquivo
            $documentoExistente->update([
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeOriginal,
                'caminho' => $caminho,
                'extensao' => strtolower($extensao),
                'tamanho' => $tamanho,
                'status_aprovacao' => 'pendente',
                'motivo_rejeicao' => null,
                'historico_rejeicao' => $historicoRejeicao,
            ]);
            
            $documento = $documentoExistente;
        } else {
            // Determina a pasta: usa pasta_id_unidade se informado, senão busca pela unidade_id
            $pastaUnidadeId = null;
            if ($request->pasta_id_unidade) {
                $pastaUnidadeId = (int) $request->pasta_id_unidade;
                // Busca o unidade_id da pasta
                $pastaObj = \App\Models\ProcessoPasta::find($pastaUnidadeId);
                $unidadeIdDoc = $pastaObj?->unidade_id;
            } elseif ($request->unidade_id) {
                $pastaUnidade = \App\Models\ProcessoPasta::where('processo_id', $processo->id)
                    ->where('unidade_id', $request->unidade_id)
                    ->first();
                $pastaUnidadeId = $pastaUnidade?->id;
                $unidadeIdDoc = $request->unidade_id;
            } else {
                $unidadeIdDoc = null;
            }

            // Cria o registro do documento com status pendente
            $documento = \App\Models\ProcessoDocumento::create([
                'processo_id' => $processo->id,
                'usuario_externo_id' => $usuarioId,
                'tipo_usuario' => 'externo',
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeOriginal,
                'caminho' => $caminho,
                'extensao' => strtolower($extensao),
                'tamanho' => $tamanho,
                'tipo_documento' => $tipoDocumentoId ? 'documento_obrigatorio' : 'arquivo_externo',
                'tipo_documento_obrigatorio_id' => $tipoDocumentoId,
                'observacoes' => $observacoes,
                'status_aprovacao' => 'pendente',
                'unidade_id' => $unidadeIdDoc,
                'pasta_id' => $pastaUnidadeId,
            ]);
        }

        // Se for requisição AJAX, retorna JSON
        if ($request->ajax() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Arquivo enviado com sucesso!',
                'documento' => [
                    'id' => $documento->id,
                    'nome_original' => $documento->nome_original,
                    'extensao' => $documento->extensao,
                    'tamanho_formatado' => $documento->tamanho_formatado,
                    'icone' => $documento->icone,
                    'created_at' => $documento->created_at ? $documento->created_at->format('d/m/Y H:i') : null,
                    'tipo_documento_obrigatorio_id' => $tipoDocumentoId,
                    'visualizar_url' => route('company.processos.documento.visualizar', [$processo->id, $documento->id]),
                    'download_url' => route('company.processos.download', [$processo->id, $documento->id]),
                    'delete_url' => route('company.processos.documento.delete', [$processo->id, $documento->id]),
                    'pode_excluir' => $documento->usuario_externo_id === $usuarioId,
                ]
            ]);
        }

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', 'Arquivo enviado com sucesso! Aguarde a aprovação da Vigilância Sanitária.');
    }

    public function downloadDocumento($processoId, $documentoId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with(['estabelecimento', 'tipoProcesso'])
            ->findOrFail($processoId);

        $documento = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        $path = $this->resolverCaminhoDocumentoProcesso($documento);
        
        if (!file_exists($path)) {
            return back()->with('error', 'Arquivo não encontrado.');
        }

        return response()->download($path, $documento->nome_original);
    }

    public function visualizarDocumento($processoId, $documentoId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);

        $documento = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
            ->findOrFail($documentoId);

        $path = $this->resolverCaminhoDocumentoProcesso($documento);
        
        if (!file_exists($path)) {
            abort(404, 'Arquivo não encontrado.');
        }

        $mimeType = mime_content_type($path);
        
        return response()->file($path, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . $documento->nome_original . '"'
        ]);
    }

    private function resolverCaminhoDocumentoProcesso($documento)
    {
        // Documento aprovado com versão carimbada (QR Code de validação): serve a versão carimbada
        if ($documento->isAprovado() && $documento->temVersaoCarimbada()) {
            $caminhoCarimbado = app(\App\Services\CarimboValidacaoService::class)->resolverCaminhoCarimbado($documento);
            if ($caminhoCarimbado) {
                return $caminhoCarimbado;
            }
        }

        $caminho = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, (string) $documento->caminho);

        if ($documento->tipo_usuario === 'interno') {
            $pathInterno = storage_path('app' . DIRECTORY_SEPARATOR . $caminho);
            if (file_exists($pathInterno)) {
                return $pathInterno;
            }
        }

        $pathPublico = storage_path('app' . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $caminho);
        if (file_exists($pathPublico)) {
            return $pathPublico;
        }

        return storage_path('app' . DIRECTORY_SEPARATOR . $caminho);
    }

    public function deleteDocumento($processoId, $documentoId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($processoId);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo)) {
            return $redirect;
        }

        // Só pode excluir documentos pendentes que foram enviados pelo próprio usuário
        $documento = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
            ->where('usuario_externo_id', $usuarioId)
            ->where('status_aprovacao', 'pendente')
            ->findOrFail($documentoId);

        // Remove o arquivo físico
        $path = storage_path('app/public/' . $documento->caminho);
        if (file_exists($path)) {
            unlink($path);
        }

        // Remove o registro
        $documento->delete();

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', 'Arquivo excluído com sucesso!');
    }

    /**
     * Reenvia um documento que foi rejeitado
     * Substitui o arquivo do documento rejeitado mantendo o histórico de rejeição
     */
    public function reenviarDocumento(Request $request, $processoId, $documentoId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($processoId);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo)) {
            return $redirect;
        }

        // Busca o documento rejeitado
        $documentoRejeitado = \App\Models\ProcessoDocumento::where('processo_id', $processo->id)
            ->where('status_aprovacao', 'rejeitado')
            ->findOrFail($documentoId);

        $request->validate([
            'arquivo' => 'required|file|max:30720|mimes:pdf',
            'observacoes' => 'nullable|string|max:500',
        ], [
            'arquivo.required' => 'Selecione um arquivo para enviar.',
            'arquivo.max' => 'O arquivo não pode ter mais de 30MB.',
            'arquivo.mimes' => 'Apenas arquivos PDF são permitidos.',
        ]);

        $arquivo = $request->file('arquivo');
        $extensao = $arquivo->getClientOriginalExtension();
        $tamanho = $arquivo->getSize();

        // Preserva o nome do documento obrigatório (não usa o nome do PDF enviado)
        $nomeDocumento = null;
        if ($documentoRejeitado->tipo_documento_obrigatorio_id) {
            $tipoDoc = \App\Models\TipoDocumentoObrigatorio::find($documentoRejeitado->tipo_documento_obrigatorio_id);
            if ($tipoDoc) {
                $nomeDocumento = $tipoDoc->nome;
            }
        }

        if ($nomeDocumento) {
            $nomeBase = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomeDocumento);
            $nomeArquivo = $nomeBase . '_' . time() . '.' . strtolower($extensao);
            $nomeOriginal = substr($nomeDocumento . '.' . strtolower($extensao), 0, 990);
        } else {
            $nomeArquivo = time() . '_' . uniqid() . '.' . $extensao;
            $nomeOriginal = $documentoRejeitado->nome_original;
        }
        
        // Remove o arquivo antigo se existir
        if ($documentoRejeitado->caminho && \Storage::disk('public')->exists($documentoRejeitado->caminho)) {
            \Storage::disk('public')->delete($documentoRejeitado->caminho);
        }
        
        // Salva o novo arquivo
        $caminho = $arquivo->storeAs(
            'processos/' . $processo->id . '/documentos',
            $nomeArquivo,
            'public'
        );

        // Guarda o histórico de rejeição antes de atualizar
        $historicoRejeicao = $documentoRejeitado->historico_rejeicao ?? [];
        $historicoRejeicao[] = [
            'motivo' => $documentoRejeitado->motivo_rejeicao,
            'arquivo_anterior' => $documentoRejeitado->nome_original,
            'rejeitado_em' => $documentoRejeitado->updated_at->toISOString(),
            'rejeitado_por' => $documentoRejeitado->aprovado_por,
        ];

        // Atualiza o documento existente com o novo arquivo
        $documentoRejeitado->update([
            'nome_arquivo' => $nomeArquivo,
            'nome_original' => $nomeOriginal,
            'caminho' => $caminho,
            'extensao' => strtolower($extensao),
            'tamanho' => $tamanho,
            'observacoes' => $request->observacoes,
            'status_aprovacao' => 'pendente',
            'motivo_rejeicao' => null,
            'aprovado_por' => null,
            'aprovado_em' => null,
            'tentativas_envio' => $documentoRejeitado->tentativas_envio + 1,
            'historico_rejeicao' => $historicoRejeicao,
        ]);

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', 'Arquivo reenviado com sucesso! Aguarde a aprovação da Vigilância Sanitária.');
    }

    /**
     * Visualiza um documento digital da vigilância
     * Registra a visualização para início da contagem de prazo (§1º)
     */
    public function visualizarDocumentoDigital($processoId, $documentoId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->findOrFail($documentoId);

        // Verifica se todas as assinaturas estão completas
        if (!$documento->todasAssinaturasCompletas()) {
            abort(403, 'Documento ainda não está disponível.');
        }

        // Registra a visualização (isso também inicia o prazo se for documento de notificação)
        $documento->registrarVisualizacao(
            $usuarioId,
            request()->ip(),
            request()->userAgent()
        );

        // Retorna o PDF
        if (!$documento->arquivo_pdf || !file_exists(storage_path('app/public/' . $documento->arquivo_pdf))) {
            abort(404, 'Arquivo não encontrado.');
        }

        $path = storage_path('app/public/' . $documento->arquivo_pdf);
        
        return response()->file($path, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $documento->numero_documento . '.pdf"'
        ]);
    }

    /**
     * Download de documento digital da vigilância
     */
    public function downloadDocumentoDigital($processoId, $documentoId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->findOrFail($documentoId);

        // Verifica se todas as assinaturas estão completas
        if (!$documento->todasAssinaturasCompletas()) {
            abort(403, 'Documento ainda não está disponível.');
        }

        // Registra a visualização
        $documento->registrarVisualizacao(
            $usuarioId,
            request()->ip(),
            request()->userAgent()
        );

        if (!$documento->arquivo_pdf || !file_exists(storage_path('app/public/' . $documento->arquivo_pdf))) {
            return back()->with('error', 'Arquivo não encontrado.');
        }

        $path = storage_path('app/public/' . $documento->arquivo_pdf);
        $nomeArquivo = $documento->numero_documento . '_' . ($documento->tipoDocumento->nome ?? 'documento') . '.pdf';
        
        return response()->download($path, $nomeArquivo);
    }

    /**
     * Marca um alerta do processo como concluído/resolvido
     */
    public function concluirAlerta($processoId, $alertaId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($processoId);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo)) {
            return $redirect;
        }

        $alerta = ProcessoAlerta::where('processo_id', $processo->id)
            ->where('status', '!=', 'concluido')
            ->findOrFail($alertaId);

        $alerta->marcarComoConcluido();

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', 'Alerta marcado como resolvido!');
    }

    /**
     * Envia uma resposta a um documento digital (ex: resposta a notificação sanitária)
     * Se existir uma resposta rejeitada, substitui mantendo o histórico
     */
    public function enviarRespostaDocumento(Request $request, $processoId, $documentoId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($processoId);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo)) {
            return $redirect;
        }

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->where('status', 'assinado')
            ->where('sigiloso', false)
            ->findOrFail($documentoId);

        // Verifica se o tipo de documento permite resposta
        if (!$documento->permiteResposta()) {
            return back()->with('error', 'Este tipo de documento não permite envio de resposta.');
        }

        if ($documento->prazo_notificacao && !$documento->prazo_iniciado_em) {
            return back()->with('error', 'Visualize o documento antes de enviar a resposta. Após abrir o PDF, volte para a página do processo e tente novamente.');
        }

        $request->validate([
            'arquivo' => 'required|file|max:30720|mimes:pdf',
            'observacoes' => 'nullable|string|max:1000',
            'tipo_documento_resposta_id' => 'nullable|exists:tipo_documento_respostas,id',
            'documento_item_atendimento_id' => 'nullable|integer|exists:documento_itens_atendimento,id',
        ], [
            'arquivo.required' => 'Selecione um arquivo PDF para enviar.',
            'arquivo.max' => 'O arquivo não pode ter mais de 30MB.',
            'arquivo.mimes' => 'Apenas arquivos PDF são permitidos.',
        ]);

        $tipoRespostaId = $request->input('tipo_documento_resposta_id');
        $itemAtendimentoId = $request->integer('documento_item_atendimento_id') ?: null;
        $itemAtendimento = null;

        if ($itemAtendimentoId) {
            $itemAtendimento = DocumentoItemAtendimento::where('documento_digital_id', $documento->id)
                ->findOrFail($itemAtendimentoId);

            $jaRespondido = DocumentoResposta::where('documento_item_atendimento_id', $itemAtendimento->id)
                ->whereIn('status', ['pendente', 'aprovado'])
                ->exists();

            if ($jaRespondido) {
                return back()->with('error', 'Este item já possui um arquivo enviado. Aguarde a análise da Vigilância Sanitária.');
            }

            $tipoRespostaId = null;
        } elseif ($documento->tipoDocumento?->exige_itens_atendimento && $documento->itensAtendimento()->exists()) {
            return back()->with('error', 'Envie a resposta diretamente no item que deseja atender.');
        }

        $arquivo = $request->file('arquivo');
        $nomeOriginal = $arquivo->getClientOriginalName();

        // Se tem tipo de resposta definido, usa o nome do tipo como nome do arquivo
        if ($tipoRespostaId) {
            $tipoResposta = \App\Models\TipoDocumentoResposta::find($tipoRespostaId);
            if ($tipoResposta) {
                $nomeOriginal = $tipoResposta->nome . '.pdf';
            }
        }
        $extensao = $arquivo->getClientOriginalExtension();
        $tamanho = $arquivo->getSize();
        $nomeArquivo = time() . '_resposta_' . uniqid() . '.' . $extensao;
        
        // Salva o arquivo
        $caminho = $arquivo->storeAs(
            'processos/' . $processo->id . '/respostas',
            $nomeArquivo,
            'public'
        );

        // Verifica se existe uma resposta rejeitada para substituir
        $respostaRejeitada = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->where('status', 'rejeitado')
            ->when($itemAtendimento, function ($query) use ($itemAtendimento) {
                $query->where('documento_item_atendimento_id', $itemAtendimento->id);
            }, function ($query) use ($usuarioId, $tipoRespostaId) {
                $query->whereNull('documento_item_atendimento_id')
                    ->where('usuario_externo_id', $usuarioId);

                if ($tipoRespostaId) {
                    $query->where('tipo_documento_resposta_id', $tipoRespostaId);
                }
            })
            ->orderBy('created_at', 'desc')
            ->first();

        if ($respostaRejeitada) {
            // Guarda o histórico de rejeição antes de atualizar
            $historicoRejeicao = $respostaRejeitada->historico_rejeicao ?? [];
            $historicoRejeicao[] = [
                'motivo' => $respostaRejeitada->motivo_rejeicao,
                'arquivo_anterior' => $respostaRejeitada->nome_original,
                'rejeitado_em' => $respostaRejeitada->avaliado_em ? $respostaRejeitada->avaliado_em->toISOString() : now()->toISOString(),
                'rejeitado_por' => $respostaRejeitada->avaliado_por,
            ];

            // Remove o arquivo antigo se existir
            if ($respostaRejeitada->caminho && \Storage::disk('public')->exists($respostaRejeitada->caminho)) {
                \Storage::disk('public')->delete($respostaRejeitada->caminho);
            }

            // Atualiza a resposta existente com o novo arquivo
            $respostaRejeitada->update([
                'usuario_externo_id' => $usuarioId,
                'documento_item_atendimento_id' => $itemAtendimento?->id,
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeOriginal,
                'caminho' => $caminho,
                'extensao' => strtolower($extensao),
                'tamanho' => $tamanho,
                'observacoes' => $request->observacoes,
                'status' => 'pendente',
                'motivo_rejeicao' => null,
                'avaliado_por' => null,
                'avaliado_em' => null,
                'historico_rejeicao' => $historicoRejeicao,
                'prazo_analise_iniciado_em' => null,
                'prazo_analise_dias' => null,
                'prazo_analise_data_limite' => null,
                'prazo_analise_prorrogado_dias' => 0,
                'prazo_analise_prorrogado_em' => null,
                'prazo_analise_prorrogado_por' => null,
                'prazo_analise_prorrogado_motivo' => null,
            ]);
            $respostaRejeitada->iniciarPrazoAnalise();
        } else {
            // Cria o registro da resposta
            DocumentoResposta::create([
                'documento_digital_id' => $documento->id,
                'documento_item_atendimento_id' => $itemAtendimento?->id,
                'usuario_externo_id' => $usuarioId,
                'tipo_documento_resposta_id' => $tipoRespostaId,
                'nome_arquivo' => $nomeArquivo,
                'nome_original' => $nomeOriginal,
                'caminho' => $caminho,
                'extensao' => strtolower($extensao),
                'tamanho' => $tamanho,
                'observacoes' => $request->observacoes,
                'status' => 'pendente',
            ]);
        }

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', $itemAtendimento
                ? 'Resposta do item enviada com sucesso! Aguarde a análise da Vigilância Sanitária.'
                : 'Resposta enviada com sucesso! Aguarde a análise da Vigilância Sanitária.');
    }

    /**
     * Download de uma resposta a documento digital
     */
    public function downloadRespostaDocumento($processoId, $documentoId, $respostaId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
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
     * Visualiza uma resposta a documento digital
     */
    public function visualizarRespostaDocumento($processoId, $documentoId, $respostaId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
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
     * Exclui uma resposta a documento digital (apenas se pendente)
     */
    public function excluirRespostaDocumento($processoId, $documentoId, $respostaId)
    {
        $usuarioId = auth('externo')->id();
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with('estabelecimento')
            ->findOrFail($processoId);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestorProcesso($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelLegal($processo)) {
            return $redirect;
        }

        if ($redirect = $this->bloquearSeFaltarResponsavelTecnico($processo)) {
            return $redirect;
        }

        $documento = \App\Models\DocumentoDigital::vinculadoAoProcesso($processo->id)
            ->findOrFail($documentoId);

        $resposta = DocumentoResposta::where('documento_digital_id', $documento->id)
            ->where('usuario_externo_id', $usuarioId)
            ->where('status', 'pendente')
            ->findOrFail($respostaId);

        // Remove o arquivo físico
        if ($resposta->caminho && \Illuminate\Support\Facades\Storage::disk('public')->exists($resposta->caminho)) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($resposta->caminho);
        }

        $resposta->delete();

        return redirect()->route('company.processos.show', $processo->id)
            ->with('success', 'Resposta excluída com sucesso!');
    }

    /**
     * Gera o PDF do protocolo de abertura do processo
     */
    public function protocoloAbertura($id)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->with(['estabelecimento.municipioRelacionado', 'tipoProcesso'])
            ->findOrFail($id);

        $estabelecimento = $processo->estabelecimento;

        // Busca a logomarca baseada na competência do processo
        // Processos estaduais → logomarca estadual (configuração do sistema)
        // Processos municipais → logomarca do município do estabelecimento
        $logomarca = null;
        $isEstadual = $estabelecimento->isCompetenciaEstadual();

        if (!$isEstadual) {
            // Competência municipal: usa logomarca do município
            $municipioObj = $estabelecimento->municipioRelacionado;
            if ($municipioObj && $municipioObj->logomarca) {
                $logomarca = $municipioObj->logomarca;
            }
        }

        // Fallback ou competência estadual: usa logomarca do sistema (estadual)
        if (!$logomarca) {
            $config = \App\Models\ConfiguracaoSistema::first();
            if ($config && $config->logomarca) {
                $logomarca = $config->logomarca;
            }
        }

        $data = [
            'processo' => $processo,
            'estabelecimento' => $estabelecimento,
            'logomarca' => $logomarca,
        ];

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('company.processos.protocolo-pdf', $data)
            ->setPaper('a4', 'portrait')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10);

        $nomeArquivo = 'Protocolo_' . str_replace('/', '-', $processo->numero_processo) . '.pdf';

        return $pdf->stream($nomeArquivo);
    }

    /**
     * Visualiza um documento de ajuda vinculado ao tipo de processo
     */
    public function visualizarDocumentoAjuda($processoId, $documentoId)
    {
        $estabelecimentoIds = $this->estabelecimentoIdsDoUsuario();
        
        // Verifica se o processo pertence ao usuário
        $processo = Processo::whereIn('estabelecimento_id', $estabelecimentoIds)
            ->findOrFail($processoId);
        
        // Busca o documento de ajuda
        $documento = \App\Models\DocumentoAjuda::ativos()
            ->paraTipoProcesso($processo->tipo)
            ->visiveisParaProcesso($processo)
            ->findOrFail($documentoId);
        
        // Verifica se o arquivo existe
        if (!\Illuminate\Support\Facades\Storage::disk('local')->exists($documento->arquivo)) {
            abort(404, 'Arquivo não encontrado.');
        }
        
        $caminho = \Illuminate\Support\Facades\Storage::disk('local')->path($documento->arquivo);
        
        return response()->file($caminho, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $documento->nome_original . '"',
        ]);
    }
}
