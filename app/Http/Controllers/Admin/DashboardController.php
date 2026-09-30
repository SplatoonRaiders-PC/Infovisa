<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\NivelAcesso;
use App\Models\UsuarioExterno;
use App\Models\UsuarioInterno;
use App\Models\Estabelecimento;
use App\Models\Processo;
use App\Models\DocumentoAssinatura;
use App\Models\DocumentoDigital;
use App\Models\DocumentoExigenciaColaborador;
use App\Models\ProcessoDesignacao;
use App\Models\OrdemServico;
use App\Models\ProcessoDocumento;
use App\Models\DocumentoResposta;
use App\Models\Aviso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * Calcula informações de documentos obrigatórios para um processo
     * Retorna total esperado (incluindo não enviados), aprovados e pendentes
     */
    private function calcularInfoDocumentos($processo)
    {
        try {
            // Busca o total de documentos obrigatórios esperados para este processo
            $documentosObrigatorios = $this->buscarDocumentosObrigatoriosParaProcesso($processo);
            
            // Filtra apenas os obrigatórios
            $apenasObrigatorios = $documentosObrigatorios->where('obrigatorio', true);
            
            $total = $apenasObrigatorios->count();
            $aprovados = $apenasObrigatorios->where('status', 'aprovado')->count();
            $pendentes = $apenasObrigatorios->where('status', 'pendente')->count();

            return [
                'total' => $total,
                'enviados' => $aprovados,
                'pendentes_aprovacao' => $pendentes
            ];
        } catch (\Exception $e) {
            // Em caso de erro, retorna valores padrão (mas registra para diagnóstico)
            \Log::warning('Dashboard: falha ao calcular documentos obrigatórios do processo', [
                'processo_id' => $processo->id ?? null,
                'erro' => $e->getMessage(),
            ]);

            return ['total' => 0, 'enviados' => 0, 'pendentes_aprovacao' => 0];
        }
    }
    
    /**
     * Busca os documentos obrigatórios esperados para um processo
     * Baseado nas atividades do estabelecimento e tipo de processo
     */
    private function buscarDocumentosObrigatoriosParaProcesso($processo)
    {
        $estabelecimento = $processo->estabelecimento;
        $tipoProcesso = $processo->tipoProcesso;
        $tipoProcessoId = $tipoProcesso->id ?? null;
        
        if (!$tipoProcessoId || !$estabelecimento) {
            return collect();
        }

        // PJ Unidade Móvel: delega para o model
        if ($tipoProcesso->codigo === 'credenciamento_movel') {
            return $processo->getDocumentosObrigatoriosChecklist();
        }

        // Verifica se é um processo especial (Projeto Arquitetônico ou Análise de Rotulagem)
        $isProcessoEspecial = $tipoProcesso && in_array($tipoProcesso->codigo, ['projeto_arquitetonico', 'analise_rotulagem']);

        // Pega as atividades exercidas do estabelecimento
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        
        if (!$isProcessoEspecial && empty($atividadesExercidas)) {
            return collect();
        }

        $atividadeIds = collect();
        
        if (!$isProcessoEspecial && !empty($atividadesExercidas)) {
            $codigosCnae = collect($atividadesExercidas)->map(function($atividade) {
                $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
            })->filter()->values()->toArray();

            if (!empty($codigosCnae)) {
                $atividadeIds = \App\Models\Atividade::where('ativo', true)
                    ->where(function($query) use ($codigosCnae) {
                        foreach ($codigosCnae as $codigo) {
                            $query->orWhere('codigo_cnae', $codigo);
                        }
                    })
                    ->pluck('id');
            }
        }

        // Busca as listas de documentos aplicáveis
        $query = \App\Models\ListaDocumento::where('ativo', true)
            ->where('tipo_processo_id', $tipoProcessoId)
            ->with(['tiposDocumentoObrigatorio' => function($q) {
                $q->orderBy('lista_documento_tipo.ordem');
            }]);

        if ($isProcessoEspecial) {
            $query->whereDoesntHave('atividades');
        } else {
            if ($atividadeIds->isEmpty()) {
                return collect();
            }
            $query->whereHas('atividades', function($q) use ($atividadeIds) {
                $q->whereIn('atividades.id', $atividadeIds);
            });
        }

        $query->where(function($q) use ($estabelecimento) {
            $q->where('escopo', 'estadual');
            if ($estabelecimento->municipio_id) {
                $q->orWhere(function($q2) use ($estabelecimento) {
                    $q2->where('escopo', 'municipal')
                       ->where('municipio_id', $estabelecimento->municipio_id);
                });
            }
        });

        $listas = $query->get();

        $documentos = collect();
        
        // Busca documentos já enviados
        $documentosEnviadosInfo = $processo->documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->groupBy('tipo_documento_obrigatorio_id')
            ->map(function($docs) {
                $docRecente = $docs->sortByDesc('created_at')->first();
                return [
                    'status' => $docRecente->status_aprovacao,
                    'documento' => $docRecente,
                ];
            });
        
        $escopoCompetencia = $tipoProcesso->resolverEscopoCompetencia($estabelecimento);
        $tipoSetorEnum = $estabelecimento->tipo_setor;
        $tipoSetor = $tipoSetorEnum instanceof \App\Enums\TipoSetor ? $tipoSetorEnum->value : ($tipoSetorEnum ?? 'privado');
        
        // Documentos comuns
        $documentosComuns = \App\Models\TipoDocumentoObrigatorio::where('ativo', true)
            ->where('documento_comum', true)
            ->where(function($q) use ($tipoProcessoId) {
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
            $documentos->push([
                'id' => $doc->id,
                'nome' => $doc->nome,
                'obrigatorio' => true,
                'status' => $infoEnviado['status'] ?? null,
            ]);
        }
        
        // Documentos das listas
        foreach ($listas as $lista) {
            foreach ($lista->tiposDocumentoObrigatorio as $doc) {
                // Filtra apenas por tipo_setor (escopo da lista já foi filtrado acima)
                $aplicaTipoSetor = $doc->tipo_setor === 'todos' || $doc->tipo_setor === $tipoSetor;
                
                if (!$aplicaTipoSetor) {
                    continue;
                }
                
                if (!$documentos->contains('id', $doc->id)) {
                    $infoEnviado = $documentosEnviadosInfo->get($doc->id);
                    $documentos->push([
                        'id' => $doc->id,
                        'nome' => $doc->nome,
                        'obrigatorio' => $doc->pivot->obrigatorio,
                        'status' => $infoEnviado['status'] ?? null,
                    ]);
                } else {
                    $documentos = $documentos->map(function($item) use ($doc) {
                        if ($item['id'] === $doc->id && $doc->pivot->obrigatorio) {
                            $item['obrigatorio'] = true;
                        }
                        return $item;
                    });
                }
            }
        }
        
        return $documentos;
    }

    /**
     * Busca os processos visíveis no dashboard por responsabilidade direta ou do setor,
     * restritos à esfera do usuário (estado vê estadual; município vê só o próprio município).
     */
    private function buscarProcessosSobResponsabilidadeDashboard($usuario)
    {
        $setoresUsuario = $usuario->getSetoresCodigos();
        $query = Processo::with(['estabelecimento', 'tipoProcesso', 'responsavelAtual', 'ultimoEventoAtribuicao'])
            ->whereNotIn('status', ['arquivado', 'concluido']);

        $query->where(function($q) use ($usuario, $setoresUsuario) {
            $q->where('responsavel_atual_id', $usuario->id);

            if (!empty($setoresUsuario)) {
                $q->orWhereIn('setor_atual', $setoresUsuario);
            }
        });

        $this->aplicarFiltroSqlEscopoEstabelecimento($query, $usuario);

        $processos = $this->filtrarProcessosPorEscopo($query->get(), $usuario);

        return $processos->sortBy([
            fn($p) => $p->responsavel_atual_id == $usuario->id ? 0 : 1,
            fn($p) => $p->responsavel_desde ? -$p->responsavel_desde->timestamp : 0,
        ])->values();
    }

    /**
     * Pré-filtro SQL de competência/município. O filtro definitivo é em memória
     * (pactuação / tipo de processo) via Processo::pertenceAoEscopoDoUsuario().
     */
    private function aplicarFiltroSqlEscopoEstabelecimento($query, $usuario, string $relation = 'estabelecimento'): void
    {
        if ($usuario->isAdmin()) {
            return;
        }

        if ($usuario->isEstadual()) {
            $query->whereHas($relation, function ($q) {
                $q->where(function ($sub) {
                    $sub->where('competencia_manual', 'estadual')
                        ->orWhereNull('competencia_manual');
                });
            });
            return;
        }

        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id) {
                $query->whereRaw('1 = 0');
                return;
            }

            $cepsFiltro = $usuario->getCepsFiltro();
            $bairrosFiltro = $usuario->getBairrosFiltro();
            $query->whereHas($relation, function ($q) use ($usuario, $cepsFiltro, $bairrosFiltro) {
                $q->where('municipio_id', $usuario->municipio_id)
                    ->where(function ($sub) {
                        $sub->whereNull('competencia_manual')
                            ->orWhere('competencia_manual', 'municipal');
                    });

                if (!empty($cepsFiltro) || !empty($bairrosFiltro)) {
                    $q->filtroTerritorial($cepsFiltro, $bairrosFiltro);
                }
            });
        }
    }

    private function filtrarProcessosPorEscopo($processos, $usuario)
    {
        if ($usuario->isAdmin()) {
            return collect($processos)->values();
        }

        return collect($processos)->filter(function ($processo) use ($usuario) {
            try {
                return $processo && $processo->pertenceAoEscopoDoUsuario($usuario);
            } catch (\Exception $e) {
                return false;
            }
        })->values();
    }

    private function filtrarDocumentosPorEscopoProcesso($documentos, $usuario)
    {
        if ($usuario->isAdmin()) {
            return collect($documentos)->values();
        }

        return collect($documentos)->filter(function ($documento) use ($usuario) {
            try {
                $processo = $documento->processo ?? null;
                return $processo && $processo->pertenceAoEscopoDoUsuario($usuario);
            } catch (\Exception $e) {
                return false;
            }
        })->values();
    }

    private function filtrarAssinaturasPorEscopoDocumento($assinaturas, $usuario)
    {
        if ($usuario->isAdmin()) {
            return collect($assinaturas)->values();
        }

        return collect($assinaturas)->filter(function ($assinatura) use ($usuario) {
            return $this->documentoDigitalNoEscopoDoUsuario($assinatura->documentoDigital, $usuario);
        })->values();
    }

    private function documentoDigitalNoEscopoDoUsuario($documentoDigital, $usuario): bool
    {
        if (!$documentoDigital) {
            return false;
        }

        if ($usuario->isAdmin()) {
            return true;
        }

        if (!empty($documentoDigital->processos_ids)) {
            $processos = Processo::with(['estabelecimento', 'tipoProcesso'])
                ->whereIn('id', $documentoDigital->processos_ids)
                ->get();

            return $this->filtrarProcessosPorEscopo($processos, $usuario)->isNotEmpty();
        }

        $processo = $documentoDigital->relationLoaded('processo')
            ? $documentoDigital->processo
            : $documentoDigital->processo()->with(['estabelecimento', 'tipoProcesso'])->first();

        if ($processo) {
            try {
                return $processo->pertenceAoEscopoDoUsuario($usuario);
            } catch (\Exception $e) {
                return false;
            }
        }

        $ordemServico = $documentoDigital->relationLoaded('ordemServico')
            ? $documentoDigital->ordemServico
            : $documentoDigital->ordemServico()->first();

        if ($ordemServico) {
            return $this->ordemServicoNoEscopoDoUsuario($ordemServico, $usuario);
        }

        return false;
    }

    private function ordemServicoNoEscopoDoUsuario($os, $usuario): bool
    {
        if ($usuario->isAdmin()) {
            return true;
        }

        if ($usuario->isEstadual()) {
            return ($os->competencia ?? 'estadual') !== 'municipal';
        }

        if ($usuario->isMunicipal()) {
            return $os->competencia === 'municipal'
                && $usuario->municipio_id
                && (int) $os->municipio_id === (int) $usuario->municipio_id;
        }

        return true;
    }

    /**
     * Retorna tarefas de documentos com prazo vencido ou a vencer em até 5 dias.
     */
    private function buscarTarefasDocumentosComPrazo($usuario)
    {
        $processosPorId = collect();

        $query = DocumentoDigital::with(['tipoDocumento', 'processo.estabelecimento', 'assinaturas'])
            ->where('status', 'assinado')
            ->whereNotNull('data_vencimento')
            ->whereNull('prazo_finalizado_em')
            ->whereDate('data_vencimento', '<=', now()->copy()->addDays(5)->toDateString())
            ->orderBy('data_vencimento', 'asc');

        if ($usuario->isAdmin()) {
            // Admin vê todos para controle.
        } elseif ($usuario->isGestor()) {
            $processos = $this->buscarProcessosSobResponsabilidadeDashboard($usuario);

            if ($processos->isEmpty()) {
                return collect();
            }

            $processosPorId = $processos->keyBy('id');
            $query->whereIn('processo_id', $processosPorId->keys());
        } else {
            $query->whereHas('assinaturas', function ($assinaturasQuery) use ($usuario) {
                $assinaturasQuery->where('usuario_interno_id', $usuario->id)
                    ->where('status', 'assinado');
            });
        }

        return $query
            ->get()
            ->filter(function($documento) use ($processosPorId, $usuario) {
                if (!$documento->temPrazo() || !$documento->todasAssinaturasCompletas() || !$documento->processo) {
                    return false;
                }

                if ($usuario->isAdmin()) {
                    return true;
                }

                if ($usuario->isGestor()) {
                    return $processosPorId->has($documento->processo_id);
                }

                try {
                    if (!$documento->processo->pertenceAoEscopoDoUsuario($usuario)) {
                        return false;
                    }
                } catch (\Exception $e) {
                    return false;
                }

                return $documento->assinaturas->contains(function ($assinatura) use ($usuario) {
                    return (int) $assinatura->usuario_interno_id === (int) $usuario->id
                        && $assinatura->status === 'assinado';
                });
            })
            ->map(function($documento) use ($processosPorId, $usuario) {
                $processo = $documento->processo;
                $grupo = 'para_mim';

                if ($usuario->isAdmin()) {
                    $grupo = 'setor';
                } elseif ($usuario->isGestor()) {
                    $processoDashboard = $processosPorId->get($documento->processo_id);
                    $grupo = $processoDashboard && $processoDashboard->responsavel_atual_id == $usuario->id ? 'para_mim' : 'setor';
                }

                $nomeDocumento = $documento->tipoDocumento->nome ?? ($documento->nome ?: 'Documento com prazo');
                $estabelecimento = $processo->estabelecimento->nome_fantasia
                    ?? $processo->estabelecimento->razao_social
                    ?? 'Estabelecimento';

                return [
                    'tipo' => 'prazo_documento',
                    'id' => $documento->id,
                    'processo_id' => $processo->id,
                    'estabelecimento_id' => $processo->estabelecimento_id,
                    'titulo' => $nomeDocumento,
                    'subtitulo' => $estabelecimento . ' • ' . $processo->numero_processo,
                    'numero_processo' => $processo->numero_processo,
                    'tipo_documento' => $nomeDocumento,
                    'tipo_processo' => $processo->tipo_nome ?? ucfirst($processo->tipo ?? 'Processo'),
                    'url' => route('admin.estabelecimentos.processos.show', [$processo->estabelecimento_id, $processo->id]),
                    'dias_restantes' => $documento->dias_faltando,
                    'atrasado' => $documento->vencido,
                    'prazo_texto' => $documento->texto_status_prazo,
                    'data_vencimento' => optional($documento->data_vencimento)->format('d/m/Y'),
                    'ordem' => 1,
                    'data' => optional($documento->data_vencimento)->format('d/m/Y'),
                    'created_at' => $documento->data_vencimento
                        ? $documento->data_vencimento->copy()->startOfDay()
                        : $documento->created_at,
                    'grupo' => $grupo,
                ];
            })
            ->values();
    }

    /**
     * Aplica a regra padrão de visibilidade por setor do processo ao query builder.
     * Um processo é "do setor do usuário" quando:
     *  - ele é o responsável direto (responsavel_atual_id), OU
     *  - o setor_atual pertence a um dos setores do usuário, OU
     *  - o setor responsável pela análise inicial do tipo de processo pertence ao usuário:
     *      - Para usuários estaduais: tipo_processos.tipo_setor_id (setor padrão estadual)
     *      - Para usuários municipais: tipo_processo_setor_municipio (setor configurado
     *        para o município do usuário em /configuracoes/tipos-processo/{id}/edit)
     *
     * $closureRelation é a relação no modelo pai até chegar em "processo" (ex.: '' para ProcessoDocumento,
     * 'documentoDigital.' para DocumentoResposta).
     */
    private function aplicarFiltroSetorProcesso($query, $usuario, string $processoPath = ''): void
    {
        $setoresUsuario = $usuario->getSetoresCodigos();
        $relation = $processoPath ? rtrim($processoPath, '.') : 'processo';
        // Quando a relação final é o próprio "processo", usamos whereHas('processo', ...)
        // Quando vem de aninhamento (ex: documentoDigital.processo), monta corretamente
        $relacaoProcesso = $processoPath
            ? $processoPath . 'processo'
            : 'processo';

        $query->whereHas($relacaoProcesso, function ($p) use ($usuario, $setoresUsuario) {
            $p->where(function ($q) use ($usuario, $setoresUsuario) {
                // 1) Responsável direto
                $q->where('responsavel_atual_id', $usuario->id);

                if (!empty($setoresUsuario)) {
                    // 2) Setor atual do processo
                    $q->orWhereIn('setor_atual', $setoresUsuario);

                    // 3) Setor responsável pela análise inicial do tipo de processo
                    $q->orWhereHas('tipoProcesso', function ($tp) use ($setoresUsuario, $usuario) {
                        $tp->where(function ($tpq) use ($setoresUsuario, $usuario) {
                            // Setor estadual padrão (tipo_processos.tipo_setor_id)
                            $tpq->whereHas('tipoSetor', function ($ts) use ($setoresUsuario) {
                                $ts->whereIn('codigo', $setoresUsuario);
                            });

                            // Setor municipal configurado por município (tipo_processo_setor_municipio)
                            if ($usuario->isMunicipal() && $usuario->municipio_id) {
                                $tpq->orWhereHas('setoresMunicipais', function ($sm) use ($setoresUsuario, $usuario) {
                                    $sm->where('municipio_id', $usuario->municipio_id)
                                        ->whereHas('tipoSetor', function ($ts) use ($setoresUsuario) {
                                            $ts->whereIn('codigo', $setoresUsuario);
                                        });
                                });
                            }
                        });
                    });
                }
            });
        });
    }

    /**
     * Aplica o filtro de visibilidade de documentos pendentes de aprovação (ProcessoDocumento)
     * no query builder. Tanto documentos obrigatórios quanto os de fora da lista obrigatória
     * seguem a mesma regra: setor atual do processo OU setor responsável pela análise inicial
     * do tipo de processo (considerando configuração municipal quando aplicável).
     */
    private function aplicarFiltroVisibilidadeDocumentosPendentes($query, $usuario): void
    {
        if ($usuario->isAdmin()) {
            return;
        }

        $this->aplicarFiltroSetorProcesso($query, $usuario);
    }

    /**
     * Verifica se o usuário tem visibilidade em um processo pelas regras de setor:
     *  - Responsável direto, OU
     *  - Setor atual do processo ∈ setores do usuário, OU
     *  - Setor de análise inicial do tipo de processo ∈ setores do usuário
     *    (inclusive configuração municipal por município).
     */
    private function usuarioVeProcessoPorSetor($processo, $usuario): bool
    {
        if (!$processo) {
            return false;
        }

        if ((int) $processo->responsavel_atual_id === (int) $usuario->id) {
            return true;
        }

        $setoresUsuario = $usuario->getSetoresCodigos();
        if (empty($setoresUsuario)) {
            return false;
        }

        if ($processo->setor_atual && in_array($processo->setor_atual, $setoresUsuario, true)) {
            return true;
        }

        $tipoProcesso = $processo->relationLoaded('tipoProcesso')
            ? $processo->tipoProcesso
            : $processo->tipoProcesso()->with(['tipoSetor', 'setoresMunicipais.tipoSetor'])->first();

        if (!$tipoProcesso) {
            return false;
        }

        // Setor estadual padrão do tipo de processo
        $setorEstadual = $tipoProcesso->relationLoaded('tipoSetor')
            ? $tipoProcesso->tipoSetor
            : $tipoProcesso->tipoSetor()->first();

        if ($setorEstadual && in_array($setorEstadual->codigo, $setoresUsuario, true)) {
            return true;
        }

        // Setor municipal configurado por município
        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $setoresMun = $tipoProcesso->relationLoaded('setoresMunicipais')
                ? $tipoProcesso->setoresMunicipais
                : $tipoProcesso->setoresMunicipais()->with('tipoSetor')->get();

            foreach ($setoresMun as $mapeamento) {
                if ((int) $mapeamento->municipio_id !== (int) $usuario->municipio_id) {
                    continue;
                }
                $ts = $mapeamento->relationLoaded('tipoSetor')
                    ? $mapeamento->tipoSetor
                    : $mapeamento->tipoSetor()->first();

                if ($ts && in_array($ts->codigo, $setoresUsuario, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function aplicarFiltroVisibilidadeRespostasPendentes($query, $usuario): void
    {
        if ($usuario->isAdmin()) {
            return;
        }

        $query->where(function ($mainQuery) use ($usuario) {
            // Regra padrão por setor do processo
            $this->aplicarFiltroSetorProcesso($mainQuery, $usuario, 'documentoDigital.');

            // Assinantes do documento também enxergam a resposta
            $mainQuery->orWhereHas('documentoDigital.assinaturas', function ($signQuery) use ($usuario) {
                $signQuery->where('usuario_interno_id', $usuario->id)
                    ->where('status', 'assinado');
            });
        });
    }

    private function filtrarRespostasPendentesVisiveis($respostas, $usuario)
    {
        if ($usuario->isAdmin()) {
            return $respostas->values();
        }

        return $respostas->filter(function ($resposta) use ($usuario) {
            $documentoDigital = $resposta->documentoDigital;
            $processo = $documentoDigital?->processo;

            if (!$documentoDigital || !$processo) {
                return false;
            }

            try {
                if (!$processo->pertenceAoEscopoDoUsuario($usuario)) {
                    return false;
                }
            } catch (\Exception $e) {
                return false;
            }

            $assinaturas = $documentoDigital->relationLoaded('assinaturas')
                ? $documentoDigital->assinaturas
                : $documentoDigital->assinaturas()->get();

            $assinouDocumento = $assinaturas->contains(function ($assinatura) use ($usuario) {
                return (int) $assinatura->usuario_interno_id === (int) $usuario->id
                    && $assinatura->status === 'assinado';
            });

            if ($assinouDocumento) {
                return true;
            }

            // Regra de setor: responsável direto OU setor atual OU setor de análise inicial
            if (!$this->usuarioVeProcessoPorSetor($processo, $usuario)) {
                return false;
            }

            return true;
        })->values();
    }

    /**
     * Exibe o dashboard do administrador
     */
    public function index()
    {
        $usuario = Auth::guard('interno')->user();
        $setoresUsuario = $usuario->getSetoresCodigos();

        // Aniversariantes do mês (escopo por perfil do usuário logado)
        $mesAtual = now()->month;
        $hojeMd = now()->format('m-d');

        $aniversariantesQuery = UsuarioInterno::query()
            ->where('ativo', true)
            ->whereNotNull('data_nascimento');

        $escopoAniversariantes = 'Geral';

        if ($usuario->isEstadual()) {
            $aniversariantesQuery->whereIn('nivel_acesso', [
                NivelAcesso::GestorEstadual->value,
                NivelAcesso::TecnicoEstadual->value,
                NivelAcesso::Administrador->value,
            ]);
            $escopoAniversariantes = 'Estadual';
        } elseif ($usuario->isMunicipal()) {
            $aniversariantesQuery->where(function ($q) use ($usuario) {
                $q->where(function ($q2) use ($usuario) {
                    $q2->whereIn('nivel_acesso', [
                        NivelAcesso::GestorMunicipal->value,
                        NivelAcesso::TecnicoMunicipal->value,
                    ])->where('municipio_id', $usuario->municipio_id);
                })->orWhere('nivel_acesso', NivelAcesso::Administrador->value);
            });
            $escopoAniversariantes = $usuario->municipio ?? 'Município';
        }

        $aniversariantes_mes = $aniversariantesQuery
            ->whereMonth('data_nascimento', $mesAtual)
            ->orderByRaw('EXTRACT(DAY FROM data_nascimento) ASC')
            ->orderBy('nome', 'ASC')
            ->get(['id', 'nome', 'data_nascimento', 'nivel_acesso', 'municipio_id']);

        $aniversariantes_mes->transform(function ($u) use ($hojeMd) {
            $u->dia_aniversario = $u->data_nascimento ? $u->data_nascimento->format('d/m') : null;
            $u->eh_hoje = $u->data_nascimento ? $u->data_nascimento->format('m-d') === $hojeMd : false;
            return $u;
        });

        $eh_aniversariante_hoje = $usuario->data_nascimento
            ? $usuario->data_nascimento->format('m-d') === $hojeMd
            : false;
        
        // Conta estabelecimentos pendentes baseado no perfil do usuário
        $estabelecimentosPendentesQuery = Estabelecimento::pendentes()->with('usuarioExterno');

        if ($usuario->isMunicipal()) {
            if ($usuario->municipio_id) {
                $estabelecimentosPendentesQuery->where('municipio_id', $usuario->municipio_id);

                $cepsFiltro = $usuario->getCepsFiltro();
                $bairrosFiltro = $usuario->getBairrosFiltro();
                if (!empty($cepsFiltro) || !empty($bairrosFiltro)) {
                    $estabelecimentosPendentesQuery->filtroTerritorial($cepsFiltro, $bairrosFiltro);
                }
            } else {
                $estabelecimentosPendentesQuery->whereRaw('1 = 0');
            }
        }

        $estabelecimentosPendentes = $estabelecimentosPendentesQuery->get();
        
        // Filtra por competência
        if ($usuario->isAdmin()) {
            // Admin vê todos
            $estabelecimentosPendentesCount = $estabelecimentosPendentes->count();
        } elseif ($usuario->isEstadual()) {
            // Estadual vê apenas de competência estadual
            $estabelecimentosPendentes = $estabelecimentosPendentes->filter(function($e) {
                try { return $e->isCompetenciaEstadual(); } catch (\Exception $ex) { return false; }
            });
            $estabelecimentosPendentesCount = $estabelecimentosPendentes->count();
        } elseif ($usuario->isMunicipal()) {
            // Municipal vê apenas de competência municipal do seu município
            $municipioId = $usuario->municipio_id;
            $estabelecimentosPendentes = $estabelecimentosPendentes->filter(function($e) use ($municipioId) {
                try { return $e->municipio_id == $municipioId && $e->isCompetenciaMunicipal(); } catch (\Exception $ex) { return false; }
            });
            $estabelecimentosPendentesCount = $estabelecimentosPendentes->count();
        } else {
            $estabelecimentosPendentesCount = 0;
        }
        
        $stats = [
            'usuarios_externos' => UsuarioExterno::count(),
            'usuarios_externos_ativos' => UsuarioExterno::where('ativo', true)->count(),
            'usuarios_externos_pendentes' => UsuarioExterno::whereNull('email_verified_at')->count(),
            'usuarios_internos' => UsuarioInterno::count(),
            'usuarios_internos_ativos' => UsuarioInterno::where('ativo', true)->count(),
            'administradores' => UsuarioInterno::administradores()->count(),
            'estabelecimentos_pendentes' => $estabelecimentosPendentesCount,
        ];

        $usuarios_externos_recentes = UsuarioExterno::latest()
            ->take(5)
            ->get();

        $usuarios_internos_recentes = UsuarioInterno::latest()
            ->take(5)
            ->get();

        // Buscar os 5 últimos estabelecimentos pendentes (já filtrados por competência)
        $estabelecimentos_pendentes = $estabelecimentosPendentes->sortByDesc('created_at')->take(5);

        // Solicitações do módulo Unidade Móvel pendentes de aprovação (estabelecimentos já aprovados)
        $solicitacoesUnidadeMovel = Estabelecimento::where('status_unidade_movel', 'pendente')
            ->with('municipiosAtuacao')
            ->get();

        if ($usuario->isAdmin()) {
            // Admin vê todas
        } elseif ($usuario->isEstadual()) {
            $solicitacoesUnidadeMovel = $solicitacoesUnidadeMovel->filter(function ($e) {
                return $e->municipiosAtuacao->contains(fn ($m) => $m->competencia === 'estadual');
            });
        } elseif ($usuario->isMunicipal()) {
            $municipioIdUsuario = $usuario->municipio_id;
            $solicitacoesUnidadeMovel = $solicitacoesUnidadeMovel->filter(function ($e) use ($municipioIdUsuario) {
                return $e->municipiosAtuacao->contains(fn ($m) => $m->municipio_id == $municipioIdUsuario
                    && $m->competencia === 'municipal' && $m->usa_infovisa);
            });
        } else {
            $solicitacoesUnidadeMovel = collect();
        }

        $solicitacoes_unidade_movel = $solicitacoesUnidadeMovel->sortByDesc('created_at')->values();
        $stats['solicitacoes_unidade_movel'] = $solicitacoes_unidade_movel->count();

        // Buscar processos que o usuário está acompanhando
        $usuarioId = $usuario->id;
        $processos_acompanhados = Processo::whereHas('acompanhamentos', function($query) use ($usuarioId) {
                $query->where('usuario_interno_id', $usuarioId);
            })
            ->with(['estabelecimento', 'usuario', 'tipoProcesso', 'acompanhamentos' => function($query) use ($usuarioId) {
                $query->where('usuario_interno_id', $usuarioId);
            }])
            ->orderBy('updated_at', 'desc')
            ->get();

        $processos_acompanhados = $this->filtrarProcessosPorEscopo($processos_acompanhados, $usuario)
            ->take(10);

        // Buscar documentos pendentes de assinatura do usuário (excluindo rascunhos)
        $assinaturasPendentes = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', function($query) {
                $query->where('status', '!=', 'rascunho');
            })
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();

        $assinaturasPendentes = $this->filtrarAssinaturasPorEscopoDocumento($assinaturasPendentes, $usuario);

        $stats['documentos_pendentes_assinatura'] = $assinaturasPendentes->count();

        $documentos_pendentes_assinatura = $assinaturasPendentes->take(5);

        // Buscar documentos em rascunho que têm o usuário como assinante
        $rascunhosPendentes = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', function($query) {
                $query->where('status', 'rascunho');
            })
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();

        $rascunhosPendentes = $this->filtrarAssinaturasPorEscopoDocumento($rascunhosPendentes, $usuario);

        $stats['documentos_rascunho_pendentes'] = $rascunhosPendentes->count();

        $documentos_rascunho_pendentes = $rascunhosPendentes->take(5);

        // Buscar processos designados DIRETAMENTE para o usuário (pendentes e em andamento)
        // Exclui designações apenas por setor
        $designacoesBase = ProcessoDesignacao::where('usuario_designado_id', $usuario->id)
            ->whereIn('status', ['pendente', 'em_andamento']);

        $processos_designados = $designacoesBase
            ->with(['processo.estabelecimento', 'processo.tipoProcesso', 'usuarioDesignador'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($designacao) use ($usuario) {
                return $designacao->processo
                    && $this->filtrarProcessosPorEscopo(collect([$designacao->processo]), $usuario)->isNotEmpty();
            })
            ->values();

        $stats['processos_designados_pendentes'] = $processos_designados->count();

        $processos_designados = $processos_designados->take(10);

        // Buscar Ordens de Serviço em andamento do usuário
        // Dashboard mostra APENAS OSs onde o usuário é técnico atribuído
        // Busca OSs onde o usuário está na lista de técnicos
        $minhasOrdensServico = OrdemServico::with(['estabelecimento', 'municipio'])
            ->whereIn('status', ['aberta', 'em_andamento'])
            ->get()
            ->filter(function($os) use ($usuario) {
                return $os->tecnicos_ids && in_array($usuario->id, $os->tecnicos_ids)
                    && $this->ordemServicoNoEscopoDoUsuario($os, $usuario);
            });

        $ordens_servico_andamento = $minhasOrdensServico
            ->sortBy('data_fim')
            ->take(10);

        $stats['ordens_servico_andamento'] = $minhasOrdensServico->count();

        // Buscar processos atribuídos ao usuário ou ao seu setor (tramitados)
        // Estadual: só competência estadual. Municipal: só competência municipal do próprio município.
        $processos_atribuidos_todos = $this->buscarProcessosSobResponsabilidadeDashboard($usuario);

        $processos_atribuidos = $processos_atribuidos_todos->take(10)->values();

        $stats['processos_atribuidos'] = $processos_atribuidos_todos->count();

        // Buscar documentos assinados pelo usuário que vencem em até 5 dias
        // Exclui documentos que já foram marcados como "respondido" (prazo finalizado)
        $documentos_vencendo = DocumentoDigital::whereHas('assinaturas', function($query) use ($usuario) {
                $query->where('usuario_interno_id', $usuario->id)
                      ->where('status', 'assinado');
            })
            ->whereNotNull('data_vencimento')
            ->whereNull('prazo_finalizado_em') // Exclui documentos já respondidos
            ->where('data_vencimento', '>=', now()->startOfDay())
            ->where('data_vencimento', '<=', now()->addDays(5)->endOfDay())
            ->with(['tipoDocumento', 'processo.estabelecimento', 'processo.tipoProcesso', 'ordemServico'])
            ->orderBy('data_vencimento', 'asc')
            ->get()
            ->filter(fn ($documento) => $this->documentoDigitalNoEscopoDoUsuario($documento, $usuario))
            ->values();
            
        $stats['documentos_vencendo'] = $documentos_vencendo->count();

        // Buscar documentos pendentes de aprovação enviados por empresas
        // REGRA DE VISIBILIDADE UNIFICADA (obrigatórios e fora da lista):
        // Responsável direto, OU setor_atual do processo, OU setor responsável pela análise inicial
        // do tipo de processo (tipo_processos.tipo_setor_id para estadual, tipo_processo_setor_municipio
        // para municipal do município do usuário).
        $documentos_pendentes_aprovacao_query = ProcessoDocumento::where('status_aprovacao', 'pendente')
            ->where('tipo_usuario', 'externo')
            ->with(['processo.estabelecimento', 'processo.tipoProcesso', 'usuarioExterno']);
        
        // DocumentoResposta: respostas a documentos com prazo (segue regra do setor atual)
        $respostas_pendentes_aprovacao_query = DocumentoResposta::where('status', 'pendente')
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.assinaturas', 'usuarioExterno']);

        // Filtrar por setor/responsável do processo + competência do usuário
        if ($usuario->isAdmin()) {
            // Admin vê todos
        } else {
            $this->aplicarFiltroVisibilidadeDocumentosPendentes($documentos_pendentes_aprovacao_query, $usuario);

            $this->aplicarFiltroVisibilidadeRespostasPendentes($respostas_pendentes_aprovacao_query, $usuario);

            $this->aplicarFiltroSqlEscopoEstabelecimento($documentos_pendentes_aprovacao_query, $usuario, 'processo.estabelecimento');
            $this->aplicarFiltroSqlEscopoEstabelecimento($respostas_pendentes_aprovacao_query, $usuario, 'documentoDigital.processo.estabelecimento');
        }

        $documentos_pendentes_aprovacao = $this->filtrarDocumentosPorEscopoProcesso(
            $documentos_pendentes_aprovacao_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );
        $respostas_pendentes_aprovacao = $this->filtrarRespostasPendentesVisiveis(
            $respostas_pendentes_aprovacao_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );
        
        $stats['documentos_pendentes_aprovacao'] = $documentos_pendentes_aprovacao->count();
        $stats['respostas_pendentes_aprovacao'] = $respostas_pendentes_aprovacao->count();
        $stats['total_pendentes_aprovacao'] = $stats['documentos_pendentes_aprovacao'] + $stats['respostas_pendentes_aprovacao'];

        $documentos_pendentes_aprovacao = $documentos_pendentes_aprovacao->take(10)->values();
        $respostas_pendentes_aprovacao = $respostas_pendentes_aprovacao->take(10)->values();

        // Buscar atalhos rápidos do usuário
        $atalhos_rapidos = \App\Models\AtalhoRapido::where('usuario_interno_id', $usuario->id)
            ->orderBy('ordem')
            ->get();

        // Buscar avisos ativos para o nível de acesso do usuário
        $avisos_sistema = Aviso::ativos()
            ->paraNivel($usuario->nivel_acesso->value)
            ->orderBy('tipo', 'desc') // urgente primeiro
            ->orderBy('created_at', 'desc')
            ->get();

        // Contadores separados: "Para Mim" vs "Meu Setor"
        // "Para Mim" = apenas ações pessoais diretas (OS + Assinaturas)
        $stats['exigencias_colaborativas'] = DocumentoExigenciaColaborador::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn ($query) => $query->where('status', 'rascunho'))
            ->count();

        $stats['para_mim_total'] = ($stats['documentos_pendentes_assinatura'] ?? 0) 
            + ($stats['ordens_servico_andamento'] ?? 0)
            + ($stats['exigencias_colaborativas'] ?? 0);
        
        $stats['processos_do_setor'] = 0;
        if (!empty($setoresUsuario)) {
            $stats['processos_do_setor'] = $processos_atribuidos_todos
                ->filter(fn ($p) => in_array($p->setor_atual, $setoresUsuario, true))
                ->count();
        }
        
        // "Meu Setor" = aprovações pendentes + processos no setor
        $stats['setor_total'] = ($stats['total_pendentes_aprovacao'] ?? 0)
            + ($stats['processos_do_setor'] ?? 0);

        // Estabelecimentos com CNAE alterado na Receita (respeita a competência do usuário)
        $alertasCnaeQuery = \App\Models\EstabelecimentoVerificacaoCnae::pendentes()->paraUsuario($usuario);
        $alertas_cnae_total = (clone $alertasCnaeQuery)->count();
        $alertas_cnae_mudam_competencia = $alertas_cnae_total > 0
            ? (clone $alertasCnaeQuery)->where('altera_competencia', true)->count()
            : 0;
        $alertas_cnae = $alertas_cnae_total > 0
            ? (clone $alertasCnaeQuery)->with('estabelecimento:id,nome_fantasia,razao_social,nome_completo,cnpj,cidade')->prioridade()->limit(8)->get()
            : collect();

        return view('admin.dashboard', compact(
            'alertas_cnae',
            'alertas_cnae_total',
            'alertas_cnae_mudam_competencia',
            'stats',
            'usuarios_externos_recentes',
            'usuarios_internos_recentes',
            'estabelecimentos_pendentes',
            'solicitacoes_unidade_movel',
            'processos_acompanhados',
            'processos_atribuidos',
            'documentos_pendentes_assinatura',
            'documentos_rascunho_pendentes',
            'processos_designados',
            'ordens_servico_andamento',
            'documentos_vencendo',
            'documentos_pendentes_aprovacao',
            'respostas_pendentes_aprovacao',
            'atalhos_rapidos',
            'avisos_sistema',
            'aniversariantes_mes',
            'eh_aniversariante_hoje',
            'escopoAniversariantes'
        ));
    }

    /**
     * Lista completa de estabelecimentos com CNAE alterado na Receita
     * (respeita a competência do usuário: estadual, municipal ou administrador)
     */
    public function atividadesAlteradas(Request $request)
    {
        $usuario = Auth::guard('interno')->user();
        $filtro = $request->input('filtro');

        $query = \App\Models\EstabelecimentoVerificacaoCnae::pendentes()
            ->paraUsuario($usuario)
            ->with('estabelecimento:id,nome_fantasia,razao_social,nome_completo,cnpj,cidade');

        $total = (clone $query)->count();
        $totalMudamCompetencia = (clone $query)->where('altera_competencia', true)->count();

        if ($filtro === 'competencia') {
            $query->where('altera_competencia', true);
        }

        $alertas = $query->prioridade()->paginate(30)->withQueryString();
        $ultimaVerificacao = \App\Models\EstabelecimentoVerificacaoCnae::max('verificado_em');

        return view('admin.dashboard.atividades-alteradas', compact('alertas', 'total', 'totalMudamCompetencia', 'filtro', 'ultimaVerificacao'));
    }

    /**
     * Retorna tarefas paginadas via AJAX
     */
    public function tarefasPaginadas(Request $request)
    {
        $usuario = Auth::guard('interno')->user();
        $page = $request->get('page', 1);
        $perPage = max(1, min((int) $request->get('per_page', 20), 200));
        $todasTarefas = $this->coletarTarefasUsuario($usuario);

        $total = $todasTarefas->count();
        $lastPage = ceil($total / $perPage);
        $tarefasPaginadas = $todasTarefas->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $tarefasPaginadas,
            'current_page' => (int) $page,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Todas as tarefas de "Minhas demandas" do usuário (OS, assinaturas, rascunhos, exigências,
     * prazos de documentos, respostas e aprovações), atrasadas primeiro.
     * Usado pelo dashboard e pelo Assistente de Pendências do Chat Interno.
     */
    public function coletarTarefasUsuario(UsuarioInterno $usuario): \Illuminate\Support\Collection
    {
        $tarefasPrazo = $this->buscarTarefasDocumentosComPrazo($usuario);
        $tarefasExigenciasColaborativas = $this->buscarTarefasExigenciasColaborativas($usuario);

        // Buscar documentos pendentes de assinatura
        $assinaturas = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn($q) => $q->where('status', '!=', 'rascunho'))
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();
        $assinaturas = $this->filtrarAssinaturasPorEscopoDocumento($assinaturas, $usuario);

        $rascunhosPendentes = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn($q) => $q->where('status', 'rascunho'))
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();
        $rascunhosPendentes = $this->filtrarAssinaturasPorEscopoDocumento($rascunhosPendentes, $usuario);

        // Buscar OSs em andamento do usuário (como técnico)
        // + OSs onde o usuário é gestor e ainda não assinou (qualquer status ativo)
        $ordensServico = OrdemServico::with(['estabelecimento'])
            ->whereIn('status', ['aberta', 'em_andamento'])
            ->get()
            ->filter(fn($os) => $os->tecnicos_ids && in_array($usuario->id, $os->tecnicos_ids) && $this->ordemServicoNoEscopoDoUsuario($os, $usuario))
            ->merge(
                OrdemServico::with(['estabelecimento'])
                    ->where('gestor_assinatura_id', $usuario->id)
                    ->whereNull('gestor_assinado_em')
                    ->whereNotIn('status', ['cancelada'])
                    ->get()
                    ->filter(fn($os) => $this->ordemServicoNoEscopoDoUsuario($os, $usuario))
            )
            ->unique('id')
            ->sortBy('data_fim');

        // Buscar documentos pendentes de aprovação
        // REGRA DE VISIBILIDADE UNIFICADA (obrigatórios e fora da lista):
        // Responsável direto, OU setor_atual do processo, OU setor responsável pela análise inicial
        // do tipo de processo (tipo_processos.tipo_setor_id para estadual, tipo_processo_setor_municipio
        // para municipal do município do usuário).
        $documentos_pendentes_query = ProcessoDocumento::where('status_aprovacao', 'pendente')
            ->where('tipo_usuario', 'externo')
            ->with(['processo.estabelecimento', 'processo.tipoProcesso']);

        $respostas_pendentes_query = DocumentoResposta::where('status', 'pendente')
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'documentoDigital.assinaturas']);

        // Filtrar por setor/responsável do processo + competência
        if (!$usuario->isAdmin()) {
            $this->aplicarFiltroVisibilidadeDocumentosPendentes($documentos_pendentes_query, $usuario);
            $this->aplicarFiltroVisibilidadeRespostasPendentes($respostas_pendentes_query, $usuario);
            $this->aplicarFiltroSqlEscopoEstabelecimento($documentos_pendentes_query, $usuario, 'processo.estabelecimento');
            $this->aplicarFiltroSqlEscopoEstabelecimento($respostas_pendentes_query, $usuario, 'documentoDigital.processo.estabelecimento');
        }

        $documentos_pendentes = $this->filtrarDocumentosPorEscopoProcesso(
            $documentos_pendentes_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );
        $respostas_pendentes = $this->filtrarRespostasPendentesVisiveis(
            $respostas_pendentes_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );

        // Agrupar documentos por processo
        $tarefasArray = [];
        foreach($documentos_pendentes as $doc) {
            $processo = $doc->processo;
            if (!$processo) {
                continue;
            }

            $key = 'processo_' . $doc->processo_id;
            $tipoProcesso = $processo->tipo ?? null;
            $tipoProcessoNome = $processo->tipo_nome ?? ucfirst($tipoProcesso ?? 'Processo');
            // Prazo de 5 dias aplica-se APENAS a processos de licenciamento
            $isLicenciamento = $tipoProcesso === 'licenciamento';
            
            if (!isset($tarefasArray[$key])) {
                $diasPendente = (int) $doc->created_at->diffInDays(now());
                $tarefasArray[$key] = [
                    'tipo' => 'aprovacao',
                    'processo_id' => $doc->processo_id,
                    'estabelecimento_id' => $processo->estabelecimento_id,
                    'estabelecimento' => $processo->estabelecimento->nome_fantasia ?? $processo->estabelecimento->razao_social ?? 'Estabelecimento',
                    'numero_processo' => $processo->numero_processo,
                    'tipo_processo' => $tipoProcessoNome,
                    'is_licenciamento' => $isLicenciamento,
                    'primeiro_arquivo' => $doc->nome_original,
                    'total' => 1,
                    'dias_pendente' => $diasPendente,
                    'atrasado' => $isLicenciamento && $diasPendente > 5, // Só atrasado se for licenciamento
                    'created_at' => $doc->created_at,
                ];
            } else {
                $tarefasArray[$key]['total']++;
                // Usar o documento mais RECENTE para calcular o prazo (cada novo documento reinicia o prazo)
                if ($doc->created_at > $tarefasArray[$key]['created_at']) {
                    $tarefasArray[$key]['created_at'] = $doc->created_at;
                    $tarefasArray[$key]['primeiro_arquivo'] = $doc->nome_original;
                    $diasPendente = (int) $doc->created_at->diffInDays(now());
                    $tarefasArray[$key]['dias_pendente'] = $diasPendente;
                    $tarefasArray[$key]['atrasado'] = $isLicenciamento && $diasPendente > 5;
                }
            }
        }

        // Respostas são tratadas separadamente para mostrar o tipo de documento original
        foreach($respostas_pendentes as $resposta) {
            $documentoDigital = $resposta->documentoDigital;
            $processo = $documentoDigital?->processo;

            if (!$documentoDigital || !$processo) {
                continue;
            }

            $key = 'resposta_' . $documentoDigital->processo_id;
            $tipoDocumento = $documentoDigital->tipoDocumento->nome ?? 'Documento';
            $tipoProcesso = $processo->tipo ?? null;
            $tipoProcessoNome = $processo->tipo_nome ?? ucfirst($tipoProcesso ?? 'Processo');
            // Prazo de 5 dias aplica-se APENAS a processos de licenciamento
            $isLicenciamento = $tipoProcesso === 'licenciamento';

            // Prazo de análise específico desta resposta (5 dias por padrão, vem do banco)
            $diasRestantesAnalise = $resposta->dias_restantes_analise; // negativo = vencido
            $prazoAnaliseVencido = $resposta->isPrazoAnaliseVencido();
            $dataLimiteAnalise = $resposta->prazo_analise_data_limite;

            // Verifica se o usuário logado assinou o documento desta resposta
            $assinouDocumento = false;
            $assinaturasDoc = $documentoDigital->relationLoaded('assinaturas')
                ? $documentoDigital->assinaturas
                : $documentoDigital->assinaturas()->get();
            foreach ($assinaturasDoc as $ass) {
                if ((int) $ass->usuario_interno_id === (int) $usuario->id && $ass->status === 'assinado') {
                    $assinouDocumento = true;
                    break;
                }
            }

            if (!isset($tarefasArray[$key])) {
                $diasPendente = (int) $resposta->created_at->diffInDays(now());
                $tarefasArray[$key] = [
                    'tipo' => 'resposta',
                    'documento_digital_id' => $documentoDigital->id,
                    'processo_id' => $documentoDigital->processo_id,
                    'estabelecimento_id' => $processo->estabelecimento_id,
                    'estabelecimento' => $processo->estabelecimento->nome_fantasia ?? 'Estabelecimento',
                    'numero_processo' => $processo->numero_processo,
                    'tipo_processo' => $tipoProcessoNome,
                    'is_licenciamento' => $isLicenciamento,
                    'tipo_documento' => $tipoDocumento,
                    'primeiro_arquivo' => $resposta->nome_original,
                    'total' => 1,
                    'dias_pendente' => $diasPendente,
                    'atrasado' => $prazoAnaliseVencido, // agora usa o prazo de análise real
                    'dias_restantes_analise' => $diasRestantesAnalise,
                    'prazo_analise_data_limite' => $dataLimiteAnalise,
                    'assinou_documento' => $assinouDocumento,
                    'created_at' => $resposta->created_at,
                ];
            } else {
                $tarefasArray[$key]['total']++;
                // Mantém flag se qualquer resposta no grupo tiver o usuário como assinante
                if ($assinouDocumento) {
                    $tarefasArray[$key]['assinou_documento'] = true;
                }
                // Usar a resposta mais RECENTE para calcular o prazo (cada nova resposta reinicia o prazo)
                if ($resposta->created_at > $tarefasArray[$key]['created_at']) {
                    $tarefasArray[$key]['documento_digital_id'] = $documentoDigital->id;
                    $tarefasArray[$key]['created_at'] = $resposta->created_at;
                    $tarefasArray[$key]['primeiro_arquivo'] = $resposta->nome_original;
                    $diasPendente = (int) $resposta->created_at->diffInDays(now());
                    $tarefasArray[$key]['dias_pendente'] = $diasPendente;
                    $tarefasArray[$key]['atrasado'] = $prazoAnaliseVencido;
                    $tarefasArray[$key]['dias_restantes_analise'] = $diasRestantesAnalise;
                    $tarefasArray[$key]['prazo_analise_data_limite'] = $dataLimiteAnalise;
                }
            }
        }

        // Combinar todas as tarefas
        $todasTarefas = collect();

        // 1º PRIORIDADE: Ordens de Serviço em aberto (aparecem primeiro no topo)
        foreach($ordensServico as $os) {
            $diasRestantes = $os->data_fim ? now()->startOfDay()->diffInDays($os->data_fim->startOfDay(), false) : null;
            $isVencido = $diasRestantes !== null && $diasRestantes < 0;
            $tiposAcao = $os->tiposAcao();
            
            // Prazo de 15 dias após data_fim para finalizar a OS
            $prazoFinalizacao = $os->data_fim ? $os->data_fim->copy()->addDays(15) : null;
            $diasParaFinalizar = $prazoFinalizacao ? now()->startOfDay()->diffInDays($prazoFinalizacao->startOfDay(), false) : null;
            $emFinalizacao = $isVencido && $diasParaFinalizar !== null && $diasParaFinalizar >= 0; // Passou data_fim mas ainda dentro dos 15 dias
            $finalizacaoAtrasada = $diasParaFinalizar !== null && $diasParaFinalizar < 0; // Passou os 15 dias
            $aguardandoAssinaturaGestor = $os->aguardandoAssinaturaGestor();
            
            $todasTarefas->push([
                'tipo' => 'os',
                'id' => $os->id,
                'numero' => $os->numero,
                'titulo' => 'OS #' . $os->numero,
                'subtitulo' => ($os->estabelecimento->nome_fantasia ?? 'Sem estabelecimento') . 
                    ($tiposAcao && $tiposAcao->count() > 0 ? ' • ' . $tiposAcao->first()->descricao : ''),
                'url' => route('admin.ordens-servico.show', $os),
                'dias_restantes' => $diasRestantes,
                'atrasado' => $finalizacaoAtrasada,
                'em_finalizacao' => $emFinalizacao,
                'dias_para_finalizar' => $diasParaFinalizar,
                'data_fim_formatada' => $os->data_fim ? $os->data_fim->format('d/m/Y') : null,
                'prazo_finalizacao_formatado' => $prazoFinalizacao ? $prazoFinalizacao->format('d/m/Y') : null,
                'aguardando_assinatura_gestor' => $aguardandoAssinaturaGestor,
                'ordem' => $aguardandoAssinaturaGestor ? 1 : 0, // OS p/ assinar ficam junto com assinaturas
            ]);
        }

        // 2º PRIORIDADE: Documentos pendentes de assinatura
        foreach($assinaturas as $ass) {
            $doc = $ass->documentoDigital;
            $isLote = $doc && !empty($doc->processos_ids) && count($doc->processos_ids) > 1;
            $todasTarefas->push([
                'tipo' => 'assinatura',
                'id' => $doc->id,
                'titulo' => ($doc->tipoDocumento->nome ?? 'Documento') . ($isLote ? ' (Lote)' : ''),
                'subtitulo' => $isLote
                    ? 'Lote p/ ' . count($doc->processos_ids) . ' processos • ' . $ass->created_at->locale('pt_BR')->diffForHumans()
                    : 'Assinatura • ' . $ass->created_at->locale('pt_BR')->diffForHumans(),
                'url' => route('admin.assinatura.assinar', $doc->id),
                'badge' => null,
                'atrasado' => false,
                'is_lote' => $isLote,
                'dias_pendente' => (int) $ass->created_at->diffInDays(now()),
                'ordem' => 1, // SEGUNDA PRIORIDADE - Assinaturas
            ]);
        }

        foreach($rascunhosPendentes as $ass) {
            $doc = $ass->documentoDigital;
            $subtituloRascunho = $doc->processo->estabelecimento->nome_fantasia
                ?? $doc->processo->estabelecimento->razao_social
                ?? ($doc->ordemServico ? 'OS #' . $doc->ordemServico->numero : 'Rascunho');

            $todasTarefas->push([
                'tipo' => 'rascunho',
                'id' => $doc->id,
                'titulo' => $doc->tipoDocumento->nome ?? 'Documento',
                'subtitulo' => $subtituloRascunho,
                'url' => route('admin.documentos.show', $doc->id),
                'badge' => 'Rascunho',
                'atrasado' => false,
                'is_lote' => false,
                'dias_pendente' => (int) $ass->created_at->diffInDays(now()),
                'ordem' => 1,
            ]);
        }

        // 2.5º PRIORIDADE: Documentos em lote (rascunho) criados pelo usuário
        $documentosLoteRascunho = \App\Models\DocumentoDigital::where('usuario_criador_id', $usuario->id)
            ->where('status', 'rascunho')
            ->whereNotNull('processos_ids')
            ->with('tipoDocumento')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn($d) => !empty($d->processos_ids) && count($d->processos_ids) > 1 && $this->documentoDigitalNoEscopoDoUsuario($d, $usuario));

        foreach ($documentosLoteRascunho as $docLote) {
            $todasTarefas->push([
                'tipo' => 'rascunho_lote',
                'id' => $docLote->id,
                'titulo' => ($docLote->tipoDocumento->nome ?? 'Documento') . ' (Lote)',
                'subtitulo' => 'Rascunho p/ ' . count($docLote->processos_ids) . ' processos • ' . $docLote->created_at->locale('pt_BR')->diffForHumans(),
                'url' => route('admin.documentos.edit', $docLote->id),
                'badge' => 'Rascunho',
                'atrasado' => false,
                'is_lote' => true,
                'dias_pendente' => (int) $docLote->created_at->diffInDays(now()),
                'ordem' => 1, // Mesma prioridade que assinaturas
            ]);
        }

        foreach ($tarefasExigenciasColaborativas as $tarefaExigencia) {
            $todasTarefas->push($tarefaExigencia);
        }

        foreach ($tarefasPrazo as $tarefaPrazo) {
            $todasTarefas->push($tarefaPrazo);
        }

        // 3º PRIORIDADE: Aprovações e respostas agrupadas por processo
        $tarefasOrdenadas = collect($tarefasArray)->sortByDesc('dias_pendente');
        foreach($tarefasOrdenadas as $tarefa) {
            // Prazo só existe para licenciamento
            $diasRestantes = $tarefa['is_licenciamento'] ? (5 - $tarefa['dias_pendente']) : null;
            
            // Diferencia respostas de aprovações normais
            if ($tarefa['tipo'] === 'resposta') {
                $urlResposta = route('admin.estabelecimentos.processos.show', [
                    $tarefa['estabelecimento_id'],
                    $tarefa['processo_id'],
                    'documento_digital' => $tarefa['documento_digital_id'] ?? null,
                ]);

                if (!empty($tarefa['documento_digital_id'])) {
                    $urlResposta .= '#documento-digital-' . $tarefa['documento_digital_id'];
                }

                $todasTarefas->push([
                    'tipo' => 'resposta',
                    'documento_digital_id' => $tarefa['documento_digital_id'] ?? null,
                    'processo_id' => $tarefa['processo_id'],
                    'estabelecimento_id' => $tarefa['estabelecimento_id'],
                    'titulo' => 'Resposta - ' . ($tarefa['tipo_documento'] ?? 'Documento'),
                    'subtitulo' => $tarefa['estabelecimento'] . ' • ' . $tarefa['numero_processo'],
                    'url' => $urlResposta,
                    'total' => $tarefa['total'],
                    'dias_restantes' => $tarefa['dias_restantes_analise'] ?? $diasRestantes,
                    'atrasado' => $tarefa['atrasado'],
                    'dias_pendente' => $tarefa['dias_pendente'],
                    'prazo_analise_data_limite' => isset($tarefa['prazo_analise_data_limite']) && $tarefa['prazo_analise_data_limite']
                        ? \Carbon\Carbon::parse($tarefa['prazo_analise_data_limite'])->format('d/m/Y')
                        : null,
                    'assinou_documento' => (bool) ($tarefa['assinou_documento'] ?? false),
                    'is_licenciamento' => $tarefa['is_licenciamento'],
                    'tipo_processo' => $tarefa['tipo_processo'],
                    'ordem' => 2, // Respostas têm prioridade maior que aprovações normais
                ]);
            } else {
                $todasTarefas->push([
                    'tipo' => 'aprovacao',
                    'processo_id' => $tarefa['processo_id'],
                    'estabelecimento_id' => $tarefa['estabelecimento_id'],
                    'titulo' => \Str::limit($tarefa['primeiro_arquivo'], 30),
                    'subtitulo' => $tarefa['estabelecimento'] . ' • ' . $tarefa['numero_processo'],
                    'url' => route('admin.estabelecimentos.processos.show', [$tarefa['estabelecimento_id'], $tarefa['processo_id']]),
                    'total' => $tarefa['total'],
                    'dias_restantes' => $diasRestantes,
                    'atrasado' => $tarefa['atrasado'],
                    'dias_pendente' => $tarefa['dias_pendente'],
                    'is_licenciamento' => $tarefa['is_licenciamento'],
                    'tipo_processo' => $tarefa['tipo_processo'],
                    'ordem' => 3, // Aprovações de documentos por último
                ]);
            }
        }

        // Ordenar: atrasados primeiro, depois por ordem
        return $todasTarefas->sortBy([
            ['atrasado', 'desc'],
            ['ordem', 'asc'],
        ])->values();
    }

    /**
     * Retorna processos atribuídos/tramitados para o usuário paginados via AJAX
     */
    public function processosAtribuidosPaginados(Request $request)
    {
        $usuario = Auth::guard('interno')->user();
        $page = $request->get('page', 1);
        $perPage = (int) $request->get('per_page', 8);
        $escopo = $request->get('escopo', 'todos');
        $setoresUsuario = $usuario->getSetoresCodigos();

        $processos = $this->buscarProcessosSobResponsabilidadeDashboard($usuario);

        if ($escopo === 'meu_direto') {
            $processos = $processos->filter(fn($p) => $p->responsavel_atual_id == $usuario->id)->values();
        } elseif ($escopo === 'setor') {
            if (!empty($setoresUsuario)) {
                $processos = $processos->filter(fn($p) => in_array($p->setor_atual, $setoresUsuario))->values();
            } else {
                $processos = collect();
            }
        }

        if ($escopo === 'setor') {
            $processos = $processos->sort(function ($a, $b) use ($usuario, $setoresUsuario) {
                $aTramitadoParaSetor = in_array($a->setor_atual, $setoresUsuario) && $a->responsavel_atual_id === null;
                $bTramitadoParaSetor = in_array($b->setor_atual, $setoresUsuario) && $b->responsavel_atual_id === null;

                if ($aTramitadoParaSetor !== $bTramitadoParaSetor) {
                    return $aTramitadoParaSetor ? -1 : 1;
                }

                $aTramitacao = $a->data_tramitacao_efetiva?->timestamp ?? 0;
                $bTramitacao = $b->data_tramitacao_efetiva?->timestamp ?? 0;

                if ($aTramitacao !== $bTramitacao) {
                    return $bTramitacao <=> $aTramitacao;
                }

                $aCiencia = $a->responsavel_ciente_em_efetivo?->timestamp ?? 0;
                $bCiencia = $b->responsavel_ciente_em_efetivo?->timestamp ?? 0;

                if ($aCiencia !== $bCiencia) {
                    return $bCiencia <=> $aCiencia;
                }

                return strnatcasecmp($a->numero_processo ?? '', $b->numero_processo ?? '');
            })->values();
        } else {
            // Fora do card do setor, mantém prioridade para processos diretamente atribuídos ao usuário.
            $processos = $processos->sort(function ($a, $b) use ($usuario) {
                $aMeuDireto = $a->responsavel_atual_id == $usuario->id;
                $bMeuDireto = $b->responsavel_atual_id == $usuario->id;

                if ($aMeuDireto !== $bMeuDireto) {
                    return $aMeuDireto ? -1 : 1;
                }

                $aData = ($a->responsavel_ciente_em_efetivo ?? $a->data_tramitacao_efetiva)?->timestamp ?? 0;
                $bData = ($b->responsavel_ciente_em_efetivo ?? $b->data_tramitacao_efetiva)?->timestamp ?? 0;

                if ($aData !== $bData) {
                    return $bData <=> $aData;
                }

                return strnatcasecmp($a->numero_processo ?? '', $b->numero_processo ?? '');
            })->values();
        }

        $total = $processos->count();
        $totalMeuDireto = $processos->filter(fn($p) => $p->responsavel_atual_id == $usuario->id)->count();
        $totalDoSetor = !empty($setoresUsuario)
            ? $processos->filter(fn($p) => in_array($p->setor_atual, $setoresUsuario))->count()
            : 0;
        $lastPage = ceil($total / $perPage) ?: 1;
        $processosPaginados = $processos->forPage($page, $perPage)->values();

        $data = $processosPaginados->map(function($proc) use ($usuario, $setoresUsuario) {
            // Calcula status do prazo
            $prazoInfo = null;
            if ($proc->prazo_atribuicao) {
                $prazo = \Carbon\Carbon::parse($proc->prazo_atribuicao);
                $hoje = \Carbon\Carbon::today();
                $diasRestantes = $hoje->diffInDays($prazo, false);
                
                $prazoInfo = [
                    'data' => $prazo->format('d/m/Y'),
                    'vencido' => $diasRestantes < 0,
                    'proximo' => $diasRestantes >= 0 && $diasRestantes <= 3,
                    'dias_restantes' => $diasRestantes,
                ];
            }
            
            // Verifica se é processo direto do usuário ou apenas do setor
            $isMeuDireto = $proc->responsavel_atual_id == $usuario->id;
            $isDoSetor = !empty($setoresUsuario) && in_array($proc->setor_atual, $setoresUsuario);
            $tramitadoParaSetor = $isDoSetor && !$proc->responsavel_atual_id;
            $dataRecebimento = $proc->responsavel_ciente_em_efetivo;
            $dataTramitacao = $proc->data_tramitacao_efetiva;
            $ultimoEventoAtribuicao = $proc->ultimoEventoAtribuicao;
            $motivoAtribuicao = data_get($ultimoEventoAtribuicao?->dados_adicionais, 'motivo')
                ?? $proc->motivo_atribuicao;
            
            // Calcula informações de documentos
            $infoDocumentos = $this->calcularInfoDocumentos($proc);
            
            return [
                'id' => $proc->id,
                'numero_processo' => $proc->numero_processo,
                'estabelecimento_id' => $proc->estabelecimento_id,
                'estabelecimento' => $proc->estabelecimento->nome_fantasia ?? $proc->estabelecimento->razao_social ?? '-',
                'status' => $proc->status,
                'status_nome' => $proc->status_nome,
                'is_meu_direto' => $isMeuDireto,
                'is_do_setor' => $isDoSetor,
                'tramitado_para_setor' => $tramitadoParaSetor,
                'setor_atual' => $proc->setor_atual,
                'recebido_em' => $dataRecebimento ? $dataRecebimento->format('d/m/Y H:i') : null,
                'recebido_em_humano' => $dataRecebimento ? $dataRecebimento->locale('pt_BR')->diffForHumans() : null,
                'tramitado_em' => $dataTramitacao ? $dataTramitacao->format('d/m/Y H:i') : null,
                'tramitado_em_humano' => $dataTramitacao ? $dataTramitacao->locale('pt_BR')->diffForHumans() : null,
                'aguardando_ciencia' => $proc->responsavel_atual_id !== null && $dataRecebimento === null && $dataTramitacao !== null,
                'motivo_atribuicao' => $motivoAtribuicao,
                'prazo' => $prazoInfo,
                'docs_total' => $infoDocumentos['total'],
                'docs_enviados' => $infoDocumentos['enviados'],
                'docs_pendentes' => $infoDocumentos['pendentes_aprovacao'],
                'url' => route('admin.estabelecimentos.processos.show', [$proc->estabelecimento_id, $proc->id]),
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => (int) $page,
            'last_page' => $lastPage,
            'total' => $total,
            'escopo' => $escopo,
            'total_meu_direto' => $totalMeuDireto,
            'total_do_setor' => $totalDoSetor,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Exibe página com todos os processos sob responsabilidade direta do usuário.
     */
    public function processosSobMinhaResponsabilidade()
    {
        return view('admin.dashboard.processos-responsabilidade');
    }

    /**
     * Exibe página com todas as tarefas
     */
    public function todasTarefas()
    {
        return view('admin.dashboard.tarefas');
    }

    /**
     * Retorna todas as tarefas paginadas via AJAX (para página completa)
     */
    public function todasTarefasPaginadas(Request $request)
    {
        $usuario = Auth::guard('interno')->user();
        $page = $request->get('page', 1);
        $perPage = $request->get('per_page', 20);
        $filtro = $request->get('filtro', 'todos'); // todos, para_mim, aprovacao, resposta, assinatura, os
        $tarefasPrazo = $this->buscarTarefasDocumentosComPrazo($usuario);
        $tarefasExigenciasColaborativas = $this->buscarTarefasExigenciasColaborativas($usuario);

        // Buscar documentos pendentes de assinatura
        $assinaturas = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn($q) => $q->where('status', '!=', 'rascunho'))
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();
        $assinaturas = $this->filtrarAssinaturasPorEscopoDocumento($assinaturas, $usuario);

        $rascunhosPendentes = DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn($q) => $q->where('status', 'rascunho'))
            ->with(['documentoDigital.tipoDocumento', 'documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.ordemServico'])
            ->orderBy('created_at', 'desc')
            ->get();
        $rascunhosPendentes = $this->filtrarAssinaturasPorEscopoDocumento($rascunhosPendentes, $usuario);

        // Buscar OSs em andamento do usuário (como técnico)
        // + OSs onde o usuário é gestor e ainda não assinou (qualquer status ativo)
        $ordensServico = OrdemServico::with(['estabelecimento'])
            ->whereIn('status', ['aberta', 'em_andamento'])
            ->get()
            ->filter(fn($os) => $os->tecnicos_ids && in_array($usuario->id, $os->tecnicos_ids) && $this->ordemServicoNoEscopoDoUsuario($os, $usuario))
            ->merge(
                OrdemServico::with(['estabelecimento'])
                    ->where('gestor_assinatura_id', $usuario->id)
                    ->whereNull('gestor_assinado_em')
                    ->whereNotIn('status', ['cancelada'])
                    ->get()
                    ->filter(fn($os) => $this->ordemServicoNoEscopoDoUsuario($os, $usuario))
            )
            ->unique('id')
            ->sortBy('data_fim');

        // Buscar documentos pendentes de aprovação
        // REGRA DE VISIBILIDADE UNIFICADA (obrigatórios e fora da lista):
        // Responsável direto, OU setor_atual do processo, OU setor responsável pela análise inicial
        // do tipo de processo (tipo_processos.tipo_setor_id para estadual, tipo_processo_setor_municipio
        // para municipal do município do usuário).
        $documentos_pendentes_query = ProcessoDocumento::where('status_aprovacao', 'pendente')
            ->where('tipo_usuario', 'externo')
            ->with(['processo.estabelecimento', 'processo.tipoProcesso']);

        $respostas_pendentes_query = DocumentoResposta::where('status', 'pendente')
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'documentoDigital.assinaturas']);

        // Filtrar por setor/responsável do processo + competência
        if (!$usuario->isAdmin()) {
            $this->aplicarFiltroVisibilidadeDocumentosPendentes($documentos_pendentes_query, $usuario);
            $this->aplicarFiltroVisibilidadeRespostasPendentes($respostas_pendentes_query, $usuario);
            $this->aplicarFiltroSqlEscopoEstabelecimento($documentos_pendentes_query, $usuario, 'processo.estabelecimento');
            $this->aplicarFiltroSqlEscopoEstabelecimento($respostas_pendentes_query, $usuario, 'documentoDigital.processo.estabelecimento');
        }

        $documentos_pendentes = $this->filtrarDocumentosPorEscopoProcesso(
            $documentos_pendentes_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );
        $respostas_pendentes = $this->filtrarRespostasPendentesVisiveis(
            $respostas_pendentes_query->orderBy('created_at', 'desc')->get(),
            $usuario
        );

        // Agrupar documentos por processo
        $tarefasArray = [];
        foreach($documentos_pendentes as $doc) {
            $processo = $doc->processo;
            if (!$processo) {
                continue;
            }

            $key = 'processo_' . $doc->processo_id;
            $tipoProcesso = $processo->tipo ?? null;
            $tipoProcessoNome = $processo->tipo_nome ?? ucfirst($tipoProcesso ?? 'Processo');
            $isLicenciamento = $tipoProcesso === 'licenciamento';
            
            if (!isset($tarefasArray[$key])) {
                $diasPendente = (int) $doc->created_at->diffInDays(now());
                $tarefasArray[$key] = [
                    'tipo' => 'aprovacao',
                    'processo_id' => $doc->processo_id,
                    'estabelecimento_id' => $processo->estabelecimento_id,
                    'estabelecimento' => $processo->estabelecimento->nome_fantasia ?? $processo->estabelecimento->razao_social ?? 'Estabelecimento',
                    'numero_processo' => $processo->numero_processo,
                    'tipo_processo' => $tipoProcessoNome,
                    'is_licenciamento' => $isLicenciamento,
                    'primeiro_arquivo' => $doc->nome_original,
                    'total' => 1,
                    'dias_pendente' => $diasPendente,
                    'atrasado' => $isLicenciamento && $diasPendente > 5,
                    'created_at' => $doc->created_at,
                ];
            } else {
                $tarefasArray[$key]['total']++;
                // Usar o documento mais RECENTE para calcular o prazo (cada novo documento reinicia o prazo)
                if ($doc->created_at > $tarefasArray[$key]['created_at']) {
                    $tarefasArray[$key]['created_at'] = $doc->created_at;
                    $tarefasArray[$key]['primeiro_arquivo'] = $doc->nome_original;
                    $diasPendente = (int) $doc->created_at->diffInDays(now());
                    $tarefasArray[$key]['dias_pendente'] = $diasPendente;
                    $tarefasArray[$key]['atrasado'] = $isLicenciamento && $diasPendente > 5;
                }
            }
        }

        // Respostas são tratadas separadamente
        foreach($respostas_pendentes as $resposta) {
            $documentoDigital = $resposta->documentoDigital;
            $processo = $documentoDigital?->processo;

            if (!$documentoDigital || !$processo) {
                continue;
            }

            $key = 'resposta_' . $documentoDigital->processo_id;
            $tipoDocumento = $documentoDigital->tipoDocumento->nome ?? 'Documento';
            $tipoProcesso = $processo->tipo ?? null;
            $tipoProcessoNome = $processo->tipo_nome ?? ucfirst($tipoProcesso ?? 'Processo');
            $isLicenciamento = $tipoProcesso === 'licenciamento';

            // Prazo de análise específico desta resposta
            $diasRestantesAnalise = $resposta->dias_restantes_analise;
            $prazoAnaliseVencido = $resposta->isPrazoAnaliseVencido();
            $dataLimiteAnalise = $resposta->prazo_analise_data_limite;

            // Verifica se o usuário logado assinou o documento desta resposta
            $assinouDocumento = false;
            $assinaturasDoc = $documentoDigital->relationLoaded('assinaturas')
                ? $documentoDigital->assinaturas
                : $documentoDigital->assinaturas()->get();
            foreach ($assinaturasDoc as $ass) {
                if ((int) $ass->usuario_interno_id === (int) $usuario->id && $ass->status === 'assinado') {
                    $assinouDocumento = true;
                    break;
                }
            }

            if (!isset($tarefasArray[$key])) {
                $diasPendente = (int) $resposta->created_at->diffInDays(now());
                $tarefasArray[$key] = [
                    'tipo' => 'resposta',
                    'documento_digital_id' => $documentoDigital->id,
                    'processo_id' => $documentoDigital->processo_id,
                    'estabelecimento_id' => $processo->estabelecimento_id,
                    'estabelecimento' => $processo->estabelecimento->nome_fantasia ?? 'Estabelecimento',
                    'numero_processo' => $processo->numero_processo,
                    'tipo_processo' => $tipoProcessoNome,
                    'is_licenciamento' => $isLicenciamento,
                    'tipo_documento' => $tipoDocumento,
                    'primeiro_arquivo' => $resposta->nome_original,
                    'total' => 1,
                    'dias_pendente' => $diasPendente,
                    'atrasado' => $prazoAnaliseVencido,
                    'dias_restantes_analise' => $diasRestantesAnalise,
                    'prazo_analise_data_limite' => $dataLimiteAnalise,
                    'assinou_documento' => $assinouDocumento,
                    'created_at' => $resposta->created_at,
                ];
            } else {
                $tarefasArray[$key]['total']++;
                if ($assinouDocumento) {
                    $tarefasArray[$key]['assinou_documento'] = true;
                }
                // Usar a resposta mais RECENTE para calcular o prazo (cada nova resposta reinicia o prazo)
                if ($resposta->created_at > $tarefasArray[$key]['created_at']) {
                    $tarefasArray[$key]['documento_digital_id'] = $documentoDigital->id;
                    $tarefasArray[$key]['created_at'] = $resposta->created_at;
                    $tarefasArray[$key]['primeiro_arquivo'] = $resposta->nome_original;
                    $diasPendente = (int) $resposta->created_at->diffInDays(now());
                    $tarefasArray[$key]['dias_pendente'] = $diasPendente;
                    $tarefasArray[$key]['atrasado'] = $prazoAnaliseVencido;
                    $tarefasArray[$key]['dias_restantes_analise'] = $diasRestantesAnalise;
                    $tarefasArray[$key]['prazo_analise_data_limite'] = $dataLimiteAnalise;
                }
            }
        }

        // Combinar TODAS as tarefas (sem filtro, para calcular contadores corretos)
        $todasTarefasCompleta = collect();

        // 1º PRIORIDADE: Ordens de Serviço em aberto
        foreach($ordensServico as $os) {
            $diasRestantes = $os->data_fim ? now()->startOfDay()->diffInDays($os->data_fim->startOfDay(), false) : null;
            $isVencido = $diasRestantes !== null && $diasRestantes < 0;
            $tiposAcao = $os->tiposAcao();
            $prazoFinalizacao = $os->data_fim ? $os->data_fim->copy()->addDays(15) : null;
            $diasParaFinalizar = $prazoFinalizacao ? now()->startOfDay()->diffInDays($prazoFinalizacao->startOfDay(), false) : null;
            $emFinalizacao = $isVencido && $diasParaFinalizar !== null && $diasParaFinalizar >= 0;
            $finalizacaoAtrasada = $diasParaFinalizar !== null && $diasParaFinalizar < 0;
            $aguardandoAssinaturaGestor = $os->aguardandoAssinaturaGestor();
            
            $todasTarefasCompleta->push([
                'tipo' => 'os',
                'id' => $os->id,
                'numero' => $os->numero,
                'titulo' => 'OS #' . $os->numero,
                'subtitulo' => $os->estabelecimento->nome_fantasia ?? 'Sem estabelecimento',
                'tipo_acao' => $tiposAcao && $tiposAcao->count() > 0 ? $tiposAcao->first()->descricao : null,
                'url' => route('admin.ordens-servico.show', $os),
                'dias_restantes' => $diasRestantes,
                'atrasado' => $finalizacaoAtrasada,
                'em_finalizacao' => $emFinalizacao,
                'dias_para_finalizar' => $diasParaFinalizar,
                'data_fim_formatada' => $os->data_fim ? $os->data_fim->format('d/m/Y') : null,
                'prazo_finalizacao_formatado' => $prazoFinalizacao ? $prazoFinalizacao->format('d/m/Y') : null,
                'aguardando_assinatura_gestor' => $aguardandoAssinaturaGestor,
                'ordem' => $aguardandoAssinaturaGestor ? 1 : 0,
                'data' => $os->created_at->format('d/m/Y H:i'),
                'created_at' => $os->created_at,
                'grupo' => 'para_mim',
            ]);
        }

        // 2º PRIORIDADE: Documentos pendentes de assinatura
        foreach($assinaturas as $ass) {
            $doc = $ass->documentoDigital;
            $isLote = $doc && !empty($doc->processos_ids) && count($doc->processos_ids) > 1;
            $todasTarefasCompleta->push([
                'tipo' => 'assinatura',
                'id' => $doc->id,
                'titulo' => ($doc->tipoDocumento->nome ?? 'Documento') . ($isLote ? ' (Lote)' : ''),
                'subtitulo' => $isLote
                    ? 'Lote p/ ' . count($doc->processos_ids) . ' processos'
                    : ($doc->processo->estabelecimento->nome_fantasia ??
                       $doc->processo->estabelecimento->razao_social ?? 'Estabelecimento'),
                'numero_processo' => $doc->processo->numero_processo ?? null,
                'url' => route('admin.assinatura.assinar', $doc->id),
                'badge' => null,
                'atrasado' => false,
                'is_lote' => $isLote,
                'ordem' => 1,
                'data' => $ass->created_at->format('d/m/Y H:i'),
                'created_at' => $ass->created_at,
                'grupo' => 'para_mim',
            ]);
        }

        foreach($rascunhosPendentes as $ass) {
            $doc = $ass->documentoDigital;
            $subtituloRascunho = $doc->processo->estabelecimento->nome_fantasia
                ?? $doc->processo->estabelecimento->razao_social
                ?? ($doc->ordemServico ? 'OS #' . $doc->ordemServico->numero : 'Rascunho');

            $todasTarefasCompleta->push([
                'tipo' => 'rascunho',
                'id' => $doc->id,
                'titulo' => $doc->tipoDocumento->nome ?? 'Documento',
                'subtitulo' => $subtituloRascunho,
                'numero_processo' => $doc->processo->numero_processo ?? null,
                'url' => route('admin.documentos.show', $doc->id),
                'badge' => 'Rascunho',
                'atrasado' => false,
                'is_lote' => false,
                'ordem' => 1,
                'data' => $ass->created_at->format('d/m/Y H:i'),
                'created_at' => $ass->created_at,
                'grupo' => 'para_mim',
            ]);
        }

        // 2.5º PRIORIDADE: Documentos em lote (rascunho) criados pelo usuário
        $documentosLoteRascunhoTodos = \App\Models\DocumentoDigital::where('usuario_criador_id', $usuario->id)
            ->where('status', 'rascunho')
            ->whereNotNull('processos_ids')
            ->with('tipoDocumento')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn($d) => !empty($d->processos_ids) && count($d->processos_ids) > 1 && $this->documentoDigitalNoEscopoDoUsuario($d, $usuario));

        foreach ($documentosLoteRascunhoTodos as $docLote) {
            $todasTarefasCompleta->push([
                'tipo' => 'rascunho_lote',
                'id' => $docLote->id,
                'titulo' => ($docLote->tipoDocumento->nome ?? 'Documento') . ' (Lote)',
                'subtitulo' => 'Rascunho p/ ' . count($docLote->processos_ids) . ' processos',
                'url' => route('admin.documentos.edit', $docLote->id),
                'badge' => 'Rascunho',
                'atrasado' => false,
                'is_lote' => true,
                'ordem' => 1,
                'data' => $docLote->created_at->format('d/m/Y H:i'),
                'created_at' => $docLote->created_at,
                'grupo' => 'para_mim',
            ]);
        }

        foreach ($tarefasExigenciasColaborativas as $tarefaExigencia) {
            $todasTarefasCompleta->push($tarefaExigencia);
        }

        foreach ($tarefasPrazo as $tarefaPrazo) {
            $todasTarefasCompleta->push($tarefaPrazo);
        }

        // 3º PRIORIDADE: Aprovações e respostas agrupadas por processo
        $tarefasOrdenadas = collect($tarefasArray)->sortByDesc('dias_pendente');
        foreach($tarefasOrdenadas as $tarefa) {
            $diasRestantes = $tarefa['is_licenciamento'] ? (5 - $tarefa['dias_pendente']) : null;
            
            if ($tarefa['tipo'] === 'resposta') {
                $urlResposta = route('admin.estabelecimentos.processos.show', [
                    $tarefa['estabelecimento_id'],
                    $tarefa['processo_id'],
                    'documento_digital' => $tarefa['documento_digital_id'] ?? null,
                ]);

                if (!empty($tarefa['documento_digital_id'])) {
                    $urlResposta .= '#documento-digital-' . $tarefa['documento_digital_id'];
                }

                $todasTarefasCompleta->push([
                    'tipo' => 'resposta',
                    'documento_digital_id' => $tarefa['documento_digital_id'] ?? null,
                    'processo_id' => $tarefa['processo_id'],
                    'estabelecimento_id' => $tarefa['estabelecimento_id'],
                    'titulo' => 'Resposta - ' . ($tarefa['tipo_documento'] ?? 'Documento'),
                    'subtitulo' => $tarefa['estabelecimento'],
                    'numero_processo' => $tarefa['numero_processo'],
                    'url' => $urlResposta,
                    'total' => $tarefa['total'],
                    'dias_restantes' => $tarefa['dias_restantes_analise'] ?? $diasRestantes,
                    'atrasado' => $tarefa['atrasado'],
                    'dias_pendente' => $tarefa['dias_pendente'],
                    'prazo_analise_data_limite' => isset($tarefa['prazo_analise_data_limite']) && $tarefa['prazo_analise_data_limite']
                        ? \Carbon\Carbon::parse($tarefa['prazo_analise_data_limite'])->format('d/m/Y')
                        : null,
                    'assinou_documento' => (bool) ($tarefa['assinou_documento'] ?? false),
                    'is_licenciamento' => $tarefa['is_licenciamento'],
                    'tipo_processo' => $tarefa['tipo_processo'],
                    'ordem' => 2,
                    'data' => $tarefa['created_at']->format('d/m/Y H:i'),
                    'created_at' => $tarefa['created_at'],
                    'grupo' => ($tarefa['assinou_documento'] ?? false) ? 'para_mim' : 'setor',
                ]);
            } else {
                $todasTarefasCompleta->push([
                    'tipo' => 'aprovacao',
                    'processo_id' => $tarefa['processo_id'],
                    'estabelecimento_id' => $tarefa['estabelecimento_id'],
                    'titulo' => \Str::limit($tarefa['primeiro_arquivo'], 50),
                    'subtitulo' => $tarefa['estabelecimento'],
                    'numero_processo' => $tarefa['numero_processo'],
                    'url' => route('admin.estabelecimentos.processos.show', [$tarefa['estabelecimento_id'], $tarefa['processo_id']]),
                    'total' => $tarefa['total'],
                    'dias_restantes' => $diasRestantes,
                    'atrasado' => $tarefa['atrasado'],
                    'dias_pendente' => $tarefa['dias_pendente'],
                    'is_licenciamento' => $tarefa['is_licenciamento'],
                    'tipo_processo' => $tarefa['tipo_processo'],
                    'ordem' => 3,
                    'data' => $tarefa['created_at']->format('d/m/Y H:i'),
                    'created_at' => $tarefa['created_at'],
                    'grupo' => 'setor',
                ]);
            }
        }

        // Contadores GLOBAIS (sempre completos, independente do filtro ativo)
        $osCount = $todasTarefasCompleta->where('tipo', 'os')->count();
        $assinaturaCount = $todasTarefasCompleta->where('tipo', 'assinatura')->count();
        $rascunhoCount = $todasTarefasCompleta->where('tipo', 'rascunho')->count();
        $rascunhoLoteCount = $todasTarefasCompleta->where('tipo', 'rascunho_lote')->count();
        $exigenciaCount = $todasTarefasCompleta->where('tipo', 'exigencia')->count();
        $aprovacaoCount = $todasTarefasCompleta->where('tipo', 'aprovacao')->count();
        $respostaCount = $todasTarefasCompleta->where('tipo', 'resposta')->count();
        // Respostas onde o usuário é assinante do documento (aparecem em "Minhas demandas")
        $respostaAssinanteCount = $todasTarefasCompleta->filter(fn($t) => $t['tipo'] === 'resposta' && ($t['assinou_documento'] ?? false))->count();
        $prazoDocumentoCount = $todasTarefasCompleta->where('tipo', 'prazo_documento')->count();
        $prazoParaMimCount = $todasTarefasCompleta->where('tipo', 'prazo_documento')->where('grupo', 'para_mim')->count();
        $prazoSetorCount = $todasTarefasCompleta->where('tipo', 'prazo_documento')->where('grupo', 'setor')->count();
        $contadores = [
            'total' => $todasTarefasCompleta->count(),
            'aprovacao' => $aprovacaoCount,
            'resposta' => $respostaCount,
            'resposta_assinante' => $respostaAssinanteCount,
            'assinatura' => $assinaturaCount,
            'rascunho' => $rascunhoCount,
            'rascunho_lote' => $rascunhoLoteCount,
            'exigencia' => $exigenciaCount,
            'os' => $osCount,
            'prazo_documento' => $prazoDocumentoCount,
            'para_mim' => $osCount + $assinaturaCount + $rascunhoCount + $rascunhoLoteCount + $exigenciaCount + $prazoParaMimCount + $respostaAssinanteCount,
            'setor' => $aprovacaoCount + $respostaCount + $prazoSetorCount,
        ];

        // Aplicar filtro
        $todasTarefas = match($filtro) {
            'para_mim' => $todasTarefasCompleta->filter(fn($t) => in_array($t['tipo'], ['os', 'assinatura', 'rascunho', 'rascunho_lote', 'exigencia'], true)
                || ($t['tipo'] === 'prazo_documento' && ($t['grupo'] ?? null) === 'para_mim')
                || ($t['tipo'] === 'resposta' && ($t['assinou_documento'] ?? false))),
            'setor' => $todasTarefasCompleta->filter(fn($t) => in_array($t['tipo'], ['aprovacao', 'resposta'], true) || ($t['tipo'] === 'prazo_documento' && ($t['grupo'] ?? null) === 'setor')),
            'os' => $todasTarefasCompleta->where('tipo', 'os'),
            'assinatura' => $todasTarefasCompleta->filter(fn($t) =>
                in_array($t['tipo'], ['assinatura', 'rascunho', 'rascunho_lote'], true)
                || ($t['tipo'] === 'os' && ($t['aguardando_assinatura_gestor'] ?? false))
            ),
            'aprovacao' => $todasTarefasCompleta->where('tipo', 'aprovacao'),
            'resposta' => $todasTarefasCompleta->where('tipo', 'resposta'),
            'resposta_assinante' => $todasTarefasCompleta->filter(fn($t) => $t['tipo'] === 'resposta' && ($t['assinou_documento'] ?? false)),
            'exigencia' => $todasTarefasCompleta->where('tipo', 'exigencia'),
            'prazo_documento' => $todasTarefasCompleta->where('tipo', 'prazo_documento'),
            default => $todasTarefasCompleta,
        };

        // Ordenar: atrasados primeiro, depois por ordem
        $todasTarefas = $todasTarefas->sortBy([
            ['atrasado', 'desc'],
            ['ordem', 'asc'],
            ['created_at', 'desc'],
        ]);

        $total = $todasTarefas->count();
        $lastPage = max(1, ceil($total / $perPage));
        $tarefasPaginadas = $todasTarefas->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $tarefasPaginadas,
            'current_page' => (int) $page,
            'last_page' => $lastPage,
            'total' => $total,
            'per_page' => $perPage,
            'contadores' => $contadores,
        ]);
    }

    private function buscarTarefasExigenciasColaborativas(UsuarioInterno $usuario)
    {
        return DocumentoExigenciaColaborador::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', fn ($query) => $query->where('status', 'rascunho'))
            ->with([
                'documentoDigital.tipoDocumento',
                'documentoDigital.processo.estabelecimento',
                'documentoDigital.processo.tipoProcesso',
                'documentoDigital.usuarioCriador',
            ])
            ->orderByRaw('prazo_interno IS NULL')
            ->orderBy('prazo_interno')
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(fn ($colaborador) => $colaborador->documentoDigital)
            ->map(function ($colaborador) {
                $documento = $colaborador->documentoDigital;
                $processo = $documento->processo;
                $estabelecimento = $processo?->estabelecimento;
                $diasRestantes = $colaborador->prazo_interno
                    ? now()->startOfDay()->diffInDays($colaborador->prazo_interno->startOfDay(), false)
                    : null;

                return [
                    'tipo' => 'exigencia',
                    'id' => $colaborador->id,
                    'documento_digital_id' => $documento->id,
                    'titulo' => 'Elaborar exigências',
                    'subtitulo' => trim(($colaborador->area ? $colaborador->area . ' • ' : '') . ($estabelecimento->nome_fantasia ?? $estabelecimento->razao_social ?? 'Documento em elaboração')),
                    'numero_processo' => $processo->numero_processo ?? null,
                    'tipo_processo' => $processo->tipo_nome ?? null,
                    'area' => $colaborador->area,
                    'url' => route('admin.documentos.edit', $documento->id) . '#itens-atendimento-editor',
                    'badge' => 'Preencher',
                    'atrasado' => $diasRestantes !== null && $diasRestantes < 0,
                    'dias_restantes' => $diasRestantes,
                    'prazo_interno' => $colaborador->prazo_interno?->format('d/m/Y'),
                    'data' => $colaborador->created_at->format('d/m/Y H:i'),
                    'created_at' => $colaborador->prazo_interno ?? $colaborador->created_at,
                    'ordem' => 1,
                    'grupo' => 'para_mim',
                ];
            })
            ->values();
    }

    /**
     * Retorna ordens de serviço com atividades não encerradas após 15 dias do prazo
     * Apenas para gestores e administradores
     */
    public function ordensServicoVencidas()
    {
        $usuario = Auth::guard('interno')->user();
        
        // Verifica se é gestor ou admin
        if (!$usuario->isGestor() && !$usuario->isAdmin()) {
            return response()->json([]);
        }

        // Busca OSs em andamento que passaram 15 dias da data_fim
        $dataLimite = now()->subDays(15);
        
        $query = OrdemServico::where('status', 'em_andamento')
            ->whereNotNull('data_fim')
            ->where('data_fim', '<', $dataLimite)
            ->with(['estabelecimento', 'processo', 'municipio']);

        // Filtrar por competência
        if ($usuario->isEstadual()) {
            $query->where('competencia', 'estadual');
        } elseif ($usuario->isMunicipal() && $usuario->municipio_id) {
            $query->where('municipio_id', $usuario->municipio_id)
                ->where('competencia', 'municipal');
        }

        $ordensVencidas = $query->orderBy('data_fim', 'asc')->get();

        // Se for gestor (não admin), filtrar apenas OS cujos técnicos pertencem ao setor do gestor
        if ($usuario->isGestor() && !$usuario->isAdmin()) {
            $setorGestor = $usuario->getSetoresCodigos();
            
            if (!empty($setorGestor)) {
                // Buscar IDs dos técnicos que pertencem aos mesmos setores do gestor
                $tecnicosDoSetor = UsuarioInterno::whereIn('setor', $setorGestor)
                    ->where('ativo', true)
                    ->pluck('id')
                    ->toArray();

                $ordensVencidas = $ordensVencidas->filter(function($os) use ($tecnicosDoSetor) {
                    // Coletar todos os IDs de técnicos da OS
                    $tecnicosOs = [];
                    
                    if ($os->atividades_tecnicos) {
                        foreach ($os->atividades_tecnicos as $atividade) {
                            if (isset($atividade['tecnicos']) && is_array($atividade['tecnicos'])) {
                                $tecnicosOs = array_merge($tecnicosOs, $atividade['tecnicos']);
                            }
                        }
                    }
                    
                    if (empty($tecnicosOs) && $os->tecnicos_ids) {
                        $tecnicosOs = $os->tecnicos_ids;
                    }

                    // Se a OS não tem técnicos, ainda mostrar para o gestor
                    if (empty($tecnicosOs)) {
                        return true;
                    }

                    // Verificar se pelo menos um técnico da OS pertence ao setor do gestor
                    return !empty(array_intersect($tecnicosOs, $tecnicosDoSetor));
                });
            }
        }

        // Mapear dados com informações dos técnicos que não finalizaram
        $dados = $ordensVencidas->values()->map(function($os) {
            // Buscar técnicos usando o accessor
            $tecnicos = $os->getTodosTenicosAttribute();
            $tecnicosNomes = $tecnicos->pluck('nome')->toArray();
            
            // Calcular dias de atraso (inteiro, sempre positivo)
            $diasAtraso = (int) abs(now()->diffInDays(\Carbon\Carbon::parse($os->data_fim)));
            
            return [
                'id' => $os->id,
                'numero' => $os->numero,
                'estabelecimento' => $os->estabelecimento->nome_fantasia ?? $os->estabelecimento->razao_social ?? 'Sem estabelecimento',
                'estabelecimento_id' => $os->estabelecimento_id,
                'processo_numero' => $os->processo->numero_processo ?? null,
                'processo_id' => $os->processo_id,
                'data_fim' => $os->data_fim->format('d/m/Y'),
                'dias_atraso' => $diasAtraso,
                'tecnicos' => $tecnicosNomes,
                'tecnicos_count' => count($tecnicosNomes),
                'url' => route('admin.ordens-servico.show', $os->id),
            ];
        });

        return response()->json($dados);
    }

    /**
     * Página "Minhas Pendências" — mostra todas as pendências do usuário logado
     */
    public function minhasPendencias()
    {
        $usuario = Auth::guard('interno')->user();

        // 1. Assinaturas pendentes (excluindo rascunhos e documentos deletados)
        $assinaturas = \App\Models\DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', function($q) {
                $q->where('status', '!=', 'rascunho');
            })
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'documentoDigital.ordemServico'])
            ->get()
            ->pipe(fn ($assinaturas) => $this->filtrarAssinaturasPorEscopoDocumento($assinaturas, $usuario))
            ->map(function ($ass) {
                $doc = $ass->documentoDigital;
                $processo = $doc?->processo;
                $estab = $processo?->estabelecimento;
                return [
                    'tipo_documento' => $doc?->tipoDocumento?->nome ?? $doc?->nome ?? 'Documento',
                    'numero_documento' => $doc?->numero_documento,
                    'processo_numero' => $processo?->numero_processo,
                    'estabelecimento' => $estab?->nome_fantasia ?? $estab?->razao_social ?? '-',
                    'criado_em' => $doc?->created_at,
                    'dias_pendente' => $doc?->created_at ? (int) $doc->created_at->diffInDays(now()) : 0,
                    'url' => $estab && $processo
                        ? route('admin.estabelecimentos.processos.show', [$estab->id, $processo->id]) . '#documento-digital-' . $doc->id
                        : '#',
                ];
            })->sortByDesc('dias_pendente')->values();

        // 2. Documentos em rascunho (pendentes de edição/envio)
        $rascunhos = \App\Models\DocumentoAssinatura::where('usuario_interno_id', $usuario->id)
            ->where('status', 'pendente')
            ->whereHas('documentoDigital', function($q) {
                $q->where('status', 'rascunho');
            })
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'documentoDigital.ordemServico'])
            ->get()
            ->pipe(fn ($rascunhos) => $this->filtrarAssinaturasPorEscopoDocumento($rascunhos, $usuario))
            ->map(function ($ass) {
                $doc = $ass->documentoDigital;
                $processo = $doc?->processo;
                $estab = $processo?->estabelecimento;
                return [
                    'tipo_documento' => $doc?->tipoDocumento?->nome ?? $doc?->nome ?? 'Documento',
                    'numero_documento' => $doc?->numero_documento,
                    'processo_numero' => $processo?->numero_processo,
                    'estabelecimento' => $estab?->nome_fantasia ?? $estab?->razao_social ?? '-',
                    'criado_em' => $doc?->created_at,
                    'dias_pendente' => $doc?->created_at ? (int) $doc->created_at->diffInDays(now()) : 0,
                    'url' => $estab && $processo
                        ? route('admin.estabelecimentos.processos.show', [$estab->id, $processo->id]) . '#documento-digital-' . $doc->id
                        : '#',
                ];
            })->sortByDesc('dias_pendente')->values();

        // 3. Processos sob responsabilidade (abertos ou parados)
        $processos = Processo::where('responsavel_atual_id', $usuario->id)
            ->whereIn('status', ['aberto', 'parado'])
            ->with(['estabelecimento', 'tipoProcesso'])
            ->orderByDesc('created_at')
            ->get()
            ->pipe(fn ($processos) => $this->filtrarProcessosPorEscopo($processos, $usuario))
            ->map(function ($p) {
                return [
                    'numero_processo' => $p->numero_processo,
                    'tipo' => $p->tipoProcesso?->nome ?? ucfirst($p->tipo),
                    'status' => $p->status,
                    'estabelecimento' => $p->estabelecimento?->nome_fantasia ?? $p->estabelecimento?->razao_social ?? '-',
                    'criado_em' => $p->created_at,
                    'dias_aberto' => (int) $p->created_at->diffInDays(now()),
                    'url' => route('admin.estabelecimentos.processos.show', [$p->estabelecimento_id, $p->id]),
                ];
            });

        // 3. Respostas pendentes de análise (documentos que o usuário assinou)
        $respostas = \App\Models\DocumentoResposta::where('status', 'pendente')
            ->whereHas('documentoDigital', function ($q) {
                $q->whereHas('processo'); // exclui documentos cujo processo foi deletado
            })
            ->whereHas('documentoDigital.assinaturas', function ($q) use ($usuario) {
                $q->where('usuario_interno_id', $usuario->id)
                  ->where('status', 'assinado');
            })
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso', 'documentoDigital.tipoDocumento', 'documentoDigital.assinaturas'])
            ->get()
            ->pipe(fn ($respostas) => $this->filtrarRespostasPendentesVisiveis($respostas, $usuario))
            ->map(function ($resp) {
                $doc = $resp->documentoDigital;
                $processo = $doc?->processo;
                $estab = $processo?->estabelecimento;
                return [
                    'arquivo' => $resp->nome_original,
                    'tipo_documento' => $doc?->tipoDocumento?->nome ?? 'Documento',
                    'numero_documento' => $doc?->numero_documento,
                    'processo_numero' => $processo?->numero_processo,
                    'estabelecimento' => $estab?->nome_fantasia ?? $estab?->razao_social ?? '-',
                    'data_resposta' => $resp->created_at,
                    'prazo_analise' => $resp->prazo_analise_data_limite,
                    'dias_restantes' => $resp->dias_restantes_analise,
                    'atrasado' => $resp->isPrazoAnaliseVencido(),
                    'url' => $estab && $processo
                        ? route('admin.estabelecimentos.processos.show', [$estab->id, $processo->id]) . '#documento-digital-' . $doc->id
                        : '#',
                ];
            })->sortBy('dias_restantes')->values();

        // 4. Ordens de Serviço com atividades pendentes
        $ordensServico = OrdemServico::where('status', 'em_andamento')
            ->with(['estabelecimento'])
            ->get()
            ->filter(function ($os) use ($usuario) {
                return count($os->getAtividadesPendentesParaTecnico($usuario->id)) > 0
                    && $this->ordemServicoNoEscopoDoUsuario($os, $usuario);
            })
            ->map(function ($os) use ($usuario) {
                $atividadesPendentes = $os->getAtividadesPendentesParaTecnico($usuario->id);
                return [
                    'numero' => $os->numero,
                    'estabelecimento' => $os->estabelecimento?->nome_fantasia ?? $os->estabelecimento?->razao_social ?? '-',
                    'data_abertura' => $os->data_abertura,
                    'data_fim' => $os->data_fim,
                    'atividades_pendentes' => count($atividadesPendentes),
                    'nomes_atividades' => collect($atividadesPendentes)->pluck('nome_atividade')->filter()->take(3)->implode(', '),
                    'url' => route('admin.ordens-servico.show', $os->id),
                ];
            })->values();

        return view('admin.minhas-pendencias', compact('assinaturas', 'rascunhos', 'processos', 'respostas', 'ordensServico'));
    }

    /**
     * Retorna respostas de documentos com prazo de análise VENCIDO
     * (respostas pendentes que os técnicos não analisaram dentro do prazo).
     *
     * Visibilidade:
     *  - Admin: vê todas
     *  - Gestor Estadual: respostas de processos com competência estadual cujos
     *    técnicos/responsáveis pertencem aos setores do gestor
     *  - Gestor Municipal: mesma lógica, limitado ao município do gestor
     */
    public function respostasAtrasadasParaAnalise()
    {
        try {
            return $this->buildRespostasAtrasadasParaAnaliseResponse();
        } catch (\Throwable $e) {
            \Log::error('Erro ao carregar respostas atrasadas para análise', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([]);
        }
    }

    private function buildRespostasAtrasadasParaAnaliseResponse()
    {
        $usuario = Auth::guard('interno')->user();

        if (!$usuario->isGestor() && !$usuario->isAdmin()) {
            return response()->json([]);
        }

        $hoje = now()->startOfDay();

        $query = DocumentoResposta::where('status', 'pendente')
            ->whereNotNull('prazo_analise_data_limite')
            ->where('prazo_analise_data_limite', '<', $hoje)
            ->whereHas('documentoDigital')
            ->with([
                'documentoDigital.tipoDocumento',
                'documentoDigital.processo.estabelecimento',
                'documentoDigital.processo.tipoProcesso',
                'documentoDigital.processo.responsavelAtual',
                'documentoDigital.assinaturas.usuarioInterno',
                'usuarioExterno',
            ]);

        $this->aplicarFiltroSqlEscopoEstabelecimento($query, $usuario, 'documentoDigital.processo.estabelecimento');

        $respostas = $query->orderBy('prazo_analise_data_limite', 'asc')->get();
        $respostas = $respostas->filter(function ($resposta) use ($usuario) {
            try {
                $processo = $resposta->documentoDigital?->processo;
                return $processo && $processo->pertenceAoEscopoDoUsuario($usuario);
            } catch (\Exception $e) {
                return false;
            }
        })->values();

        // Para gestor (não admin): filtrar respostas cujos técnicos pertencem ao setor do gestor
        // ou o processo está no setor do gestor
        if ($usuario->isGestor() && !$usuario->isAdmin()) {
            $setoresGestor = $usuario->getSetoresCodigos();

            if (!empty($setoresGestor)) {
                $tecnicosDoSetor = UsuarioInterno::whereIn('setor', $setoresGestor)
                    ->where('ativo', true)
                    ->pluck('id')
                    ->toArray();

                $respostas = $respostas->filter(function($resposta) use ($setoresGestor, $tecnicosDoSetor) {
                    $documentoDigital = $resposta->documentoDigital;
                    $processo = $documentoDigital?->processo;

                    if (!$documentoDigital || !$processo) {
                        return false;
                    }

                    // 1) Processo está atualmente no setor do gestor
                    if ($processo->setor_atual && in_array($processo->setor_atual, $setoresGestor, true)) {
                        return true;
                    }

                    // 2) Responsável atual do processo é do setor do gestor
                    if ($processo->responsavel_atual_id && in_array($processo->responsavel_atual_id, $tecnicosDoSetor, true)) {
                        return true;
                    }

                    // 3) Algum assinante do documento pertence ao setor do gestor
                    $assinaturas = $documentoDigital->relationLoaded('assinaturas')
                        ? $documentoDigital->assinaturas
                        : $documentoDigital->assinaturas()->get();

                    foreach ($assinaturas as $ass) {
                        if ($ass->status === 'assinado' && in_array($ass->usuario_interno_id, $tecnicosDoSetor, true)) {
                            return true;
                        }
                    }

                    return false;
                });
            }
        }

        $dados = $respostas->values()->map(function($resposta) {
            $documentoDigital = $resposta->documentoDigital;
            if (!$documentoDigital) {
                return null;
            }

            $processo = $documentoDigital->processo;
            $estabelecimento = $processo?->estabelecimento;

            // Coleta nomes dos técnicos/responsáveis para exibir "quem deveria ter analisado"
            $tecnicosResponsaveis = collect();

            if ($processo?->responsavelAtual) {
                $tecnicosResponsaveis->push($processo->responsavelAtual->nome);
            }

            $assinaturas = $documentoDigital->relationLoaded('assinaturas')
                ? $documentoDigital->assinaturas
                : $documentoDigital->assinaturas()->with('usuarioInterno')->get();

            foreach ($assinaturas as $ass) {
                if ($ass->status === 'assinado' && $ass->usuarioInterno) {
                    $tecnicosResponsaveis->push($ass->usuarioInterno->nome);
                }
            }

            $tecnicosNomes = $tecnicosResponsaveis->unique()->values()->take(3)->toArray();

            $diasRestantes = $resposta->dias_restantes_analise;
            $diasAtraso = $diasRestantes !== null ? abs((int) $diasRestantes) : 0;

            return [
                'id' => $resposta->id,
                'documento_digital_id' => $documentoDigital->id,
                'tipo_documento' => $documentoDigital->tipoDocumento->nome ?? 'Documento',
                'numero_documento' => $documentoDigital->numero_documento ?? '',
                'processo_id' => $processo->id ?? null,
                'processo_numero' => $processo->numero_processo ?? null,
                'estabelecimento' => $estabelecimento->nome_fantasia ?? $estabelecimento->razao_social ?? 'Sem estabelecimento',
                'estabelecimento_id' => $estabelecimento->id ?? null,
                'data_resposta' => $resposta->created_at->format('d/m/Y'),
                'data_limite_analise' => $resposta->prazo_analise_data_limite?->format('d/m/Y'),
                'dias_atraso' => $diasAtraso,
                'tecnicos' => $tecnicosNomes,
                'url' => $estabelecimento && $processo
                    ? route('admin.estabelecimentos.processos.show', [$estabelecimento->id, $processo->id]) . '#documento-digital-' . $documentoDigital->id
                    : '#',
            ];
        })->filter()->values();

        return response()->json($dados);
    }
}
