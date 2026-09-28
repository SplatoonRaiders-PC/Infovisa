<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Processo extends Model
{
    use SoftDeletes;

    protected $table = 'processos';

    protected $fillable = [
        'estabelecimento_id',
        'usuario_id',
        'usuario_externo_id',
        'aberto_por_externo',
        'tipo',
        'ano',
        'numero_sequencial',
        'numero_processo',
        'status',
        'setor_atual',
        'responsavel_atual_id',
        'responsavel_desde',
        'prazo_atribuicao',
        'responsavel_ciente_em',
        'motivo_atribuicao',
        'setor_antes_arquivar',
        'responsavel_antes_arquivar_id',
        'observacoes',
        'motivo_arquivamento',
        'data_arquivamento',
        'usuario_arquivamento_id',
        'motivo_parada',
        'data_parada',
        'usuario_parada_id',
        'tempo_total_parado_segundos',
        'prazo_fila_publica_reiniciado_em',
    ];

    protected $casts = [
        'ano' => 'integer',
        'numero_sequencial' => 'integer',
        'data_arquivamento' => 'datetime',
        'data_parada' => 'datetime',
        'tempo_total_parado_segundos' => 'integer',
        'prazo_fila_publica_reiniciado_em' => 'datetime',
        'responsavel_desde' => 'datetime',
        'prazo_atribuicao' => 'date',
        'responsavel_ciente_em' => 'datetime',
    ];

    /**
     * Tipos de processo disponíveis
     */
    public static function tipos(): array
    {
        return [
            'licenciamento' => 'Licenciamento',
            'analise_rotulagem' => 'Análise de Rotulagem',
            'projeto_arquitetonico' => 'Projeto Arquitetônico',
            'administrativo' => 'Administrativo',
            'descentralizacao' => 'Descentralização',
        ];
    }

    /**
     * Status disponíveis
     */
    public static function statusDisponiveis(): array
    {
        return [
            'aberto' => 'Aberto',
            'parado' => 'Parado',
            'arquivado' => 'Arquivado',
        ];
    }

    public function getSegundosParadaAtual(): int
    {
        if (!$this->data_parada) {
            return 0;
        }

        return max(0, now()->getTimestamp() - $this->data_parada->getTimestamp());
    }

    public function getPrazoFilaPublicaReiniciadoEmEfetivo(): ?Carbon
    {
        if ($this->prazo_fila_publica_reiniciado_em) {
            return $this->prazo_fila_publica_reiniciado_em->copy();
        }

        $ultimoReinicio = $this->eventos()
            ->where('tipo_evento', 'processo_reiniciado')
            ->latest('created_at')
            ->value('created_at');

        return $ultimoReinicio ? Carbon::parse($ultimoReinicio) : null;
    }

    public function getDataReferenciaFilaPublica($dataReferencia): Carbon
    {
        $dataBase = $dataReferencia instanceof Carbon
            ? $dataReferencia->copy()
            : Carbon::parse($dataReferencia);

        $dataReinicio = $this->getPrazoFilaPublicaReiniciadoEmEfetivo();

        if ($dataReinicio && $dataReinicio->greaterThan($dataBase)) {
            return $dataReinicio;
        }

        return $dataBase;
    }

    public function prazoFilaPublicaFoiReiniciado($dataReferencia): bool
    {
        $dataBase = $dataReferencia instanceof Carbon
            ? $dataReferencia->copy()
            : Carbon::parse($dataReferencia);

        $dataReinicio = $this->getPrazoFilaPublicaReiniciadoEmEfetivo();

        return $dataReinicio !== null && $dataReinicio->greaterThan($dataBase);
    }

    /**
     * Tempo parado já encerrado (sem a parada em andamento).
     */
    public function getTempoParadoAcumulado(): int
    {
        if (!$this->prazo_fila_publica_reiniciado_em && $this->getPrazoFilaPublicaReiniciadoEmEfetivo()) {
            return 0;
        }

        return (int) ($this->tempo_total_parado_segundos ?? 0);
    }

    public function getTempoTotalParadoConsiderandoParadaAtual(): int
    {
        $tempoTotal = $this->getTempoParadoAcumulado();

        if ($this->status === 'parado' && $this->data_parada) {
            $tempoTotal += $this->getSegundosParadaAtual();
        }

        return $tempoTotal;
    }

    /**
     * Prazo da fila pública de uma pasta/unidade.
     *
     * Além das paradas do processo inteiro, considera as paradas da própria
     * pasta/unidade (o prazo congela enquanto ela estiver parada) e o reinício
     * do prazo feito só na unidade.
     */
    public function calcularPrazoFilaPublicaUnidade(ProcessoPasta $pasta, $dataDocumentosCompletos, int $prazoDias): array
    {
        $agora = now();
        $dataBase = $dataDocumentosCompletos instanceof Carbon
            ? $dataDocumentosCompletos->copy()
            : Carbon::parse($dataDocumentosCompletos);

        // Referência: a mais recente entre docs completos, reinício do processo e reinício da unidade
        $referencia = $this->getDataReferenciaFilaPublica($dataBase);
        $reinicioUnidade = $pasta->prazo_fila_publica_reiniciado_em;
        $reiniciadoNaUnidade = $reinicioUnidade && $reinicioUnidade->greaterThan($referencia);
        if ($reiniciadoNaUnidade) {
            $referencia = $reinicioUnidade->copy();
        }

        // Vínculo da unidade (pivot) — usado quando a parada foi feita pela unidade e não pela pasta
        $pivot = null;
        if ($pasta->unidade_id) {
            $unidades = $this->relationLoaded('unidades') ? $this->unidades : $this->unidades()->get();
            $pivot = $unidades->firstWhere('id', $pasta->unidade_id)?->pivot;
        }

        // Paradas já encerradas
        $segundosParados = (int) ($pasta->tempo_total_parado_segundos ?? 0)
            + (int) ($pivot->tempo_total_parado_segundos ?? 0)
            + ($reiniciadoNaUnidade ? 0 : $this->getTempoParadoAcumulado());

        // Parada em andamento: conta uma vez só, mesmo que processo e unidade estejam parados juntos
        $iniciosParada = collect([
            $this->status === 'parado' ? $this->data_parada : null,
            $pasta->isParada() ? $pasta->data_parada : null,
            ($pivot && $pivot->status === 'parado' && $pivot->data_parada) ? Carbon::parse($pivot->data_parada) : null,
        ])->filter();

        $pausado = $this->status === 'parado' || $pasta->isParada() || ($pivot && $pivot->status === 'parado');

        if ($iniciosParada->isNotEmpty()) {
            $inicioParada = $iniciosParada->min();
            if ($inicioParada->lessThan($referencia)) {
                $inicioParada = $referencia->copy();
            }
            $segundosParados += max(0, $agora->getTimestamp() - $inicioParada->getTimestamp());
        }

        $dataLimite = $referencia->copy()->addDays($prazoDias)->addSeconds($segundosParados);
        $diasRestantes = (int) round($agora->diffInDays($dataLimite, false));

        return [
            'data_referencia_prazo' => $referencia,
            'data_limite' => $dataLimite,
            'dias_restantes' => $diasRestantes,
            'atrasado' => $diasRestantes < 0,
            'pausado' => $pausado,
            'prazo_reiniciado' => $reiniciadoNaUnidade || $this->prazoFilaPublicaFoiReiniciado($dataBase),
        ];
    }

    /**
     * Data em que a documentação obrigatória de uma pasta/unidade ficou completa
     * (maior data de aprovação), ou null se ainda falta algum documento aprovado.
     * Mesma regra do checklist por unidade da tela do processo.
     */
    public function getDataDocumentacaoCompletaPasta(ProcessoPasta $pasta, ?Collection $checklist = null): ?Carbon
    {
        $checklist ??= $this->getDocumentosObrigatoriosChecklist();
        $obrigatorios = $checklist->where('obrigatorio', true);

        if ($obrigatorios->isEmpty()) {
            return null;
        }

        $dataCompleta = null;
        foreach ($obrigatorios as $docObrig) {
            $docsDoTipo = $this->documentos
                ->where('tipo_documento_obrigatorio_id', $docObrig['id'])
                ->where('pasta_id', $pasta->id);

            // Vale o envio mais recente (se foi reenviado e está pendente/rejeitado, não está completo)
            if ($docsDoTipo->sortByDesc('created_at')->first()?->status_aprovacao !== 'aprovado') {
                return null;
            }

            $aprovado = $docsDoTipo->where('status_aprovacao', 'aprovado')
                ->sortByDesc(fn ($d) => $d->aprovado_em ?? $d->updated_at)
                ->first();
            $dataAprovacao = $aprovado?->aprovado_em ?? $aprovado?->updated_at;
            $dataAprovacao = $dataAprovacao ? Carbon::parse($dataAprovacao) : null;

            if ($dataAprovacao && (!$dataCompleta || $dataAprovacao->greaterThan($dataCompleta))) {
                $dataCompleta = $dataAprovacao;
            }
        }

        return $dataCompleta;
    }

    public function calcularDataLimiteFilaPublica($dataReferencia, int $prazoDias): Carbon
    {
        return $this->getDataReferenciaFilaPublica($dataReferencia)
            ->addDays($prazoDias)
            ->addSeconds($this->getTempoTotalParadoConsiderandoParadaAtual());
    }

    /**
     * Gera o próximo número de processo para o ano atual
     * IMPORTANTE: Esta função DEVE ser chamada dentro de uma DB::transaction()
     */
    public static function gerarNumeroProcesso(int $ano = null): array
    {
        $ano = $ano ?? date('Y');
        
        // Busca o último número sequencial do ano com lock para evitar duplicação
        // O lockForUpdate() garante que nenhuma outra transação leia este registro até o commit
        $ultimoProcesso = self::withTrashed()
            ->where('ano', $ano)
            ->orderBy('numero_sequencial', 'desc')
            ->lockForUpdate()
            ->first();
        
        $numeroSequencial = $ultimoProcesso ? $ultimoProcesso->numero_sequencial + 1 : 1;
        
        // Formata com 5 dígitos: 2025/00001
        $numeroProcesso = sprintf('%d/%05d', $ano, $numeroSequencial);
        
        // Verifica se o número já existe (segurança extra)
        $tentativas = 0;
        while (self::withTrashed()->where('numero_processo', $numeroProcesso)->exists() && $tentativas < 100) {
            $numeroSequencial++;
            $numeroProcesso = sprintf('%d/%05d', $ano, $numeroSequencial);
            $tentativas++;
        }
        
        if ($tentativas >= 100) {
            throw new \Exception('Não foi possível gerar um número de processo único após 100 tentativas.');
        }
        
        return [
            'ano' => $ano,
            'numero_sequencial' => $numeroSequencial,
            'numero_processo' => $numeroProcesso,
        ];
    }

    /**
     * Relacionamento com estabelecimento
     */
    public function estabelecimento()
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    /**
     * Relacionamento com usuário interno que criou
     */
    public function usuario()
    {
        return $this->belongsTo(UsuarioInterno::class, 'usuario_id');
    }

    /**
     * Relacionamento com responsável atual do processo
     */
    public function responsavelAtual()
    {
        return $this->belongsTo(UsuarioInterno::class, 'responsavel_atual_id');
    }

    /**
     * Relacionamento com responsável anterior ao arquivamento
     */
    public function responsavelAntesArquivar()
    {
        return $this->belongsTo(UsuarioInterno::class, 'responsavel_antes_arquivar_id');
    }

    /**
     * Relacionamento com usuário externo que criou
     */
    public function usuarioExterno()
    {
        return $this->belongsTo(UsuarioExterno::class, 'usuario_externo_id');
    }

    /**
     * Relacionamento com tipo de processo
     */
    public function tipoProcesso()
    {
        return $this->belongsTo(TipoProcesso::class, 'tipo', 'codigo');
    }

    public function unidades()
    {
        return $this->belongsToMany(Unidade::class, 'processo_unidades')
            ->withPivot(['status', 'motivo_parada', 'data_parada', 'usuario_parada_id', 'tempo_total_parado_segundos'])
            ->withTimestamps();
    }

    public function resolverEscopoCompetencia(): ?string
    {
        $estabelecimento = $this->relationLoaded('estabelecimento')
            ? $this->estabelecimento
            : $this->estabelecimento()->first();

        if (!$estabelecimento) {
            return null;
        }

        $tipoProcesso = $this->relationLoaded('tipoProcesso')
            ? $this->tipoProcesso
            : $this->tipoProcesso()->first();

        return $tipoProcesso
            ? $tipoProcesso->resolverEscopoCompetencia($estabelecimento)
            : ($estabelecimento->isCompetenciaEstadual() ? 'estadual' : 'municipal');
    }

    /**
     * Visibilidade por esfera: estadual vê só competência estadual;
     * municipal vê só competência municipal do próprio município.
     */
    public function pertenceAoEscopoDoUsuario($usuario): bool
    {
        if (!$usuario || $usuario->isAdmin()) {
            return true;
        }

        $estabelecimento = $this->relationLoaded('estabelecimento')
            ? $this->estabelecimento
            : $this->estabelecimento()->first();

        if (!$estabelecimento) {
            return false;
        }

        $escopo = $this->resolverEscopoCompetencia();

        if ($usuario->isEstadual()) {
            return $escopo === 'estadual';
        }

        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id || (int) $estabelecimento->municipio_id !== (int) $usuario->municipio_id) {
                return false;
            }

            if ($escopo !== 'municipal') {
                return false;
            }

            $cepsFiltro = $usuario->getCepsFiltro();
            $bairrosFiltro = $usuario->getBairrosFiltro();
            if (!empty($cepsFiltro) || !empty($bairrosFiltro)) {
                $cep = preg_replace('/[^0-9]/', '', (string) ($estabelecimento->cep ?? ''));
                $bairro = mb_strtoupper((string) ($estabelecimento->bairro ?? ''));
                $complemento = mb_strtoupper((string) ($estabelecimento->complemento ?? ''));
                $endereco = mb_strtoupper((string) ($estabelecimento->endereco ?? ''));

                foreach ($cepsFiltro as $prefixo) {
                    $prefixo = preg_replace('/[^0-9]/', '', (string) $prefixo);
                    if ($prefixo !== '' && str_starts_with($cep, $prefixo)) {
                        return true;
                    }
                }

                foreach ($bairrosFiltro as $filtro) {
                    $filtro = mb_strtoupper(trim((string) $filtro));
                    if ($filtro !== '' && (
                        str_contains($bairro, $filtro)
                        || str_contains($complemento, $filtro)
                        || str_contains($endereco, $filtro)
                    )) {
                        return true;
                    }
                }

                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * Relacionamento com documentos
     */
    public function documentos()
    {
        return $this->hasMany(ProcessoDocumento::class);
    }

    /**
     * Busca os documentos obrigatórios e seus status para este processo.
     */
    public function getDocumentosObrigatoriosChecklist(): Collection
    {
        static $cacheAtividadeIds = [];
        static $cacheListas = [];
        static $cacheDocumentosComuns = [];

        $estabelecimento = $this->estabelecimento;
        $tipoProcesso = $this->tipoProcesso;
        $tipoProcessoId = $tipoProcesso->id ?? null;

        if (!$tipoProcessoId || !$estabelecimento) {
            return collect();
        }

        // PJ Unidade Móvel: lógica própria via documento_unidade_movel
        if ($tipoProcesso->codigo === 'credenciamento_movel') {
            return $this->getDocumentosObrigatoriosUnidadeMovel();
        }

        $isProcessoEspecial = $tipoProcesso && in_array($tipoProcesso->codigo, ['projeto_arquitetonico', 'analise_rotulagem']);
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];

        if (!$isProcessoEspecial && empty($atividadesExercidas)) {
            return $this->adicionarDocumentosManuaisChecklist(collect());
        }

        $atividadeIds = collect();

        if (!$isProcessoEspecial && !empty($atividadesExercidas)) {
            $codigosCnae = collect($atividadesExercidas)
                ->map(function ($atividade) {
                    $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                    return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
                })
                ->filter()
                ->values()
                ->toArray();

            if (!empty($codigosCnae)) {
                $sortedCnae = $codigosCnae;
                sort($sortedCnae);
                $cnaeKey = implode(',', $sortedCnae);
                if (!array_key_exists($cnaeKey, $cacheAtividadeIds)) {
                    $cacheAtividadeIds[$cnaeKey] = Atividade::where('ativo', true)
                        ->where(function ($query) use ($codigosCnae) {
                            foreach ($codigosCnae as $codigo) {
                                $query->orWhere('codigo_cnae', $codigo);
                            }
                        })
                        ->pluck('id');
                }
                $atividadeIds = $cacheAtividadeIds[$cnaeKey];
            }
        }

        $sortedIds = $atividadeIds->sort()->values()->toArray();
        $listasKey = $tipoProcessoId . '|' . ($isProcessoEspecial ? 'especial' : implode(',', $sortedIds)) . '|' . ($estabelecimento->municipio_id ?? '');

        if (!array_key_exists($listasKey, $cacheListas)) {
            if (!$isProcessoEspecial && $atividadeIds->isEmpty()) {
                $cacheListas[$listasKey] = null;
            } else {
                $query = ListaDocumento::where('ativo', true)
                    ->where('tipo_processo_id', $tipoProcessoId)
                    ->with(['tiposDocumentoObrigatorio' => function ($query) {
                        $query->orderBy('lista_documento_tipo.ordem');
                    }]);

                if ($isProcessoEspecial) {
                    $query->whereDoesntHave('atividades');
                } else {
                    $query->whereHas('atividades', function ($query) use ($atividadeIds) {
                        $query->whereIn('atividades.id', $atividadeIds);
                    });
                }

                $isEstadual = $estabelecimento->isCompetenciaEstadual();
                $query->where(function ($query) use ($estabelecimento, $isEstadual) {
                    if ($isEstadual) {
                        $query->where('escopo', 'estadual');
                    } else {
                        $query->where('escopo', 'estadual');
                        if ($estabelecimento->municipio_id) {
                            $query->orWhere(function ($nestedQuery) use ($estabelecimento) {
                                $nestedQuery->where('escopo', 'municipal')
                                    ->where('municipio_id', $estabelecimento->municipio_id);
                            });
                        }
                    }
                });

                $cacheListas[$listasKey] = $query->get();
            }
        }

        if ($cacheListas[$listasKey] === null) {
            return $this->adicionarDocumentosManuaisChecklist(collect());
        }

        $listas = $cacheListas[$listasKey];
        $documentos = collect();

        // Identifica IDs de pastas que pertencem a unidades
        $pastasUnidadeIds = $this->pastas()
            ->whereNotNull('unidade_id')
            ->pluck('id')
            ->toArray();

        // Identifica IDs de pastas concluídas/deferidas (não devem contar para o checklist)
        $pastasConcluidasIds = $this->pastas()
            ->where('status', 'concluida')
            ->pluck('id')
            ->toArray();

        // Para os documentos base (checklist geral), considera apenas documentos
        // que NÃO pertencem a pastas de unidade e NÃO pertencem a pastas concluídas
        $documentosEnviadosInfo = $this->documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->filter(function ($doc) use ($pastasUnidadeIds, $pastasConcluidasIds) {
                if (!empty($doc->pasta_id) && in_array($doc->pasta_id, $pastasConcluidasIds)) {
                    return false;
                }
                return empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasUnidadeIds);
            })
            ->groupBy('tipo_documento_obrigatorio_id')
            ->map(function ($docs) {
                $docRecente = $docs->sortByDesc('created_at')->first();

                return [
                    'status' => $docRecente->status_aprovacao,
                    'documento' => $docRecente,
                ];
            });

        $escopoCompetencia = $tipoProcesso->resolverEscopoCompetencia($estabelecimento);
        $tipoSetorEnum = $estabelecimento->tipo_setor;
        $tipoSetor = $tipoSetorEnum instanceof \App\Enums\TipoSetor ? $tipoSetorEnum->value : ($tipoSetorEnum ?? 'privado');

        $comunsKey = $tipoProcessoId . '|' . $escopoCompetencia . '|' . $tipoSetor;
        if (!array_key_exists($comunsKey, $cacheDocumentosComuns)) {
            $cacheDocumentosComuns[$comunsKey] = TipoDocumentoObrigatorio::where('ativo', true)
                ->where('documento_comum', true)
                ->where(function ($query) use ($tipoProcessoId) {
                    $query->whereNull('tipo_processo_id')
                        ->orWhere('tipo_processo_id', $tipoProcessoId);
                })
                ->where(function ($query) use ($escopoCompetencia) {
                    $query->where('escopo_competencia', 'todos')
                        ->orWhere('escopo_competencia', $escopoCompetencia);
                })
                ->where(function ($query) use ($tipoSetor) {
                    $query->where('tipo_setor', 'todos')
                        ->orWhere('tipo_setor', $tipoSetor);
                })
                ->ordenado()
                ->get();
        }
        $documentosComuns = $cacheDocumentosComuns[$comunsKey];

        foreach ($documentosComuns as $doc) {
            $infoEnviado = $documentosEnviadosInfo->get($doc->id);

            $documentos->push([
                'id' => $doc->id,
                'nome' => $doc->nome,
                'descricao' => $doc->descricao,
                'obrigatorio' => true,
                'ordem' => 0,
                'observacao' => null,
                'lista_nome' => 'Documentos Comuns',
                'status' => $infoEnviado['status'] ?? null,
                'documento_enviado' => $infoEnviado['documento'] ?? null,
                'documento_comum' => true,
            ]);
        }

        foreach ($listas as $lista) {
            foreach ($lista->tiposDocumentoObrigatorio as $doc) {
                // NÃO filtra por escopo_competencia do documento — o escopo da LISTA
                // já foi filtrado acima. Se o admin vinculou o documento à lista, é intencional.
                $aplicaTipoSetor = $doc->tipo_setor === 'todos' || $doc->tipo_setor === $tipoSetor;

                if (!$aplicaTipoSetor) {
                    continue;
                }

                if (!$documentos->contains('id', $doc->id)) {
                    $infoEnviado = $documentosEnviadosInfo->get($doc->id);

                    $documentos->push([
                        'id' => $doc->id,
                        'nome' => $doc->nome,
                        'descricao' => $doc->descricao,
                        'obrigatorio' => $doc->pivot->obrigatorio,
                        'ordem' => $doc->pivot->ordem,
                        'observacao' => $doc->pivot->observacao,
                        'lista_nome' => $lista->nome,
                        'status' => $infoEnviado['status'] ?? null,
                        'documento_enviado' => $infoEnviado['documento'] ?? null,
                        'documento_comum' => false,
                    ]);
                } else {
                    $documentos = $documentos->map(function ($item) use ($doc) {
                        if ($item['id'] === $doc->id && $doc->pivot->obrigatorio) {
                            $item['obrigatorio'] = true;
                        }

                        return $item;
                    });
                }
            }
        }

        $documentos = $this->adicionarDocumentosManuaisChecklist($documentos, $documentosEnviadosInfo);

        return $documentos->sortBy([
            ['documento_comum', 'desc'],
            ['obrigatorio', 'desc'],
            ['nome', 'asc'],
        ])->values();
    }

    /**
     * Adiciona ao checklist os documentos obrigatórios definidos manualmente pela
     * vigilância sanitária municipal (município com modo "documentos manuais" habilitado).
     * Aplica-se apenas a processos de licenciamento de estabelecimentos municipais.
     */
    private function adicionarDocumentosManuaisChecklist(Collection $documentos, $documentosEnviadosInfo = null): Collection
    {
        $estabelecimento = $this->estabelecimento;
        $tipoProcesso = $this->tipoProcesso;

        if (!$estabelecimento || !$tipoProcesso || $tipoProcesso->codigo !== 'licenciamento') {
            return $documentos->values();
        }

        if (!$estabelecimento->usaDocumentosManuais()) {
            return $documentos->values();
        }

        $documentosManuais = $estabelecimento->documentosManuais()->where('ativo', true)->orderBy('ordem')->orderBy('nome')->get();

        if ($documentosManuais->isEmpty()) {
            return $documentos->values();
        }

        if ($documentosEnviadosInfo === null) {
            $pastasUnidadeIds = $this->pastas()->whereNotNull('unidade_id')->pluck('id')->toArray();
            $pastasConcluidasIds = $this->pastas()->where('status', 'concluida')->pluck('id')->toArray();

            $documentosEnviadosInfo = $this->documentos
                ->whereNotNull('tipo_documento_obrigatorio_id')
                ->filter(function ($doc) use ($pastasUnidadeIds, $pastasConcluidasIds) {
                    if (!empty($doc->pasta_id) && in_array($doc->pasta_id, $pastasConcluidasIds)) {
                        return false;
                    }
                    return empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasUnidadeIds);
                })
                ->groupBy('tipo_documento_obrigatorio_id')
                ->map(function ($docs) {
                    $docRecente = $docs->sortByDesc('created_at')->first();
                    return [
                        'status' => $docRecente->status_aprovacao,
                        'documento' => $docRecente,
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

            $documentos->push([
                'id' => $doc->id,
                'nome' => $doc->nome,
                'descricao' => $doc->descricao,
                'obrigatorio' => true,
                'ordem' => $doc->ordem ?? 0,
                'observacao' => null,
                'lista_nome' => 'Definidos pela Vigilância Sanitária',
                'status' => $infoEnviado['status'] ?? null,
                'documento_enviado' => $infoEnviado['documento'] ?? null,
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
     * Documentos obrigatórios para processos de Credenciamento de Unidade Móvel.
     */
    private function getDocumentosObrigatoriosUnidadeMovel(): Collection
    {
        $estabelecimento = $this->estabelecimento;
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        $codigosCnae = collect($atividadesExercidas)->map(function ($atividade) {
            $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
        })->filter()->values()->toArray();

        $docsConfig = \App\Models\DocumentoUnidadeMovel::paraEstesCnaes($codigosCnae);

        if ($docsConfig->isEmpty()) {
            return collect();
        }

        $pastasProtegidas = $this->pastas()->where('protegida', true)->pluck('id')->toArray();

        $documentosEnviadosInfo = $this->documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->filter(fn($doc) => empty($doc->pasta_id) || !in_array($doc->pasta_id, $pastasProtegidas))
            ->groupBy('tipo_documento_obrigatorio_id')
            ->map(function ($docs) {
                $ultimo = $docs->sortByDesc('created_at')->first();
                return ['status' => $ultimo->status_aprovacao, 'documento' => $ultimo];
            });

        $documentos = collect();

        foreach ($docsConfig->where('escopo', 'geral') as $docConf) {
            $tipoDoc = $docConf->tipoDocumento;
            if (!$tipoDoc || $documentos->contains('id', $tipoDoc->id)) continue;

            $infoEnviado = $documentosEnviadosInfo->get($tipoDoc->id);
            $documentos->push([
                'id' => $tipoDoc->id,
                'nome' => $tipoDoc->nome,
                'descricao' => $tipoDoc->descricao,
                'obrigatorio' => $docConf->obrigatorio,
                'ordem' => $docConf->ordem,
                'observacao' => null,
                'lista_nome' => 'Documentos Gerais - Unidade Móvel',
                'status' => $infoEnviado['status'] ?? null,
                'documento_enviado' => $infoEnviado['documento'] ?? null,
                'documento_comum' => true,
            ]);
        }

        return $documentos->sortBy([
            ['obrigatorio', 'desc'],
            ['ordem', 'asc'],
            ['nome', 'asc'],
        ])->values();
    }

    /**
     * Relacionamento com pastas do processo
     */
    public function pastas()
    {
        return $this->hasMany(ProcessoPasta::class);
    }

    /**
     * Relacionamento com acompanhamentos
     */
    public function acompanhamentos()
    {
        return $this->hasMany(ProcessoAcompanhamento::class);
    }

    /**
     * Relacionamento com usuários que acompanham
     */
    public function usuariosAcompanhando()
    {
        return $this->belongsToMany(UsuarioInterno::class, 'processo_acompanhamentos', 'processo_id', 'usuario_interno_id')
            ->withTimestamps();
    }

    /**
     * Relacionamento com eventos do processo (histórico)
     */
    public function eventos()
    {
        return $this->hasMany(ProcessoEvento::class)->orderBy('created_at', 'desc');
    }

    /**
     * Último evento de atribuição do processo.
     */
    public function ultimoEventoAtribuicao()
    {
        return $this->hasOne(ProcessoEvento::class)
            ->where('tipo_evento', 'processo_atribuido')
            ->latestOfMany();
    }

    /**
     * Retorna a data de ciência salva no processo ou, em fallback, no último evento de atribuição.
     */
    public function getResponsavelCienteEmEfetivoAttribute(): ?Carbon
    {
        if ($this->responsavel_ciente_em) {
            return $this->responsavel_ciente_em->copy();
        }

        $eventoAtribuicao = $this->relationLoaded('ultimoEventoAtribuicao')
            ? $this->ultimoEventoAtribuicao
            : $this->ultimoEventoAtribuicao()->first();

        $cienteEm = data_get($eventoAtribuicao?->dados_adicionais, 'ciente_em');

        return $cienteEm ? Carbon::parse($cienteEm) : null;
    }

    /**
     * Retorna a data efetiva da última tramitação/atribuição do processo.
     */
    public function getDataTramitacaoEfetivaAttribute(): ?Carbon
    {
        if ($this->responsavel_desde) {
            return $this->responsavel_desde->copy();
        }

        $eventoAtribuicao = $this->relationLoaded('ultimoEventoAtribuicao')
            ? $this->ultimoEventoAtribuicao
            : $this->ultimoEventoAtribuicao()->first();

        if ($eventoAtribuicao?->created_at) {
            return $eventoAtribuicao->created_at->copy();
        }

        return $this->updated_at?->copy() ?? $this->created_at?->copy();
    }

    /**
     * Relacionamento com usuário que arquivou o processo
     */
    public function usuarioArquivamento()
    {
        return $this->belongsTo(UsuarioInterno::class, 'usuario_arquivamento_id');
    }

    /**
     * Relacionamento com usuário que parou o processo
     */
    public function usuarioParada()
    {
        return $this->belongsTo(UsuarioInterno::class, 'usuario_parada_id');
    }

    /**
     * Relacionamento com designações do processo
     */
    public function designacoes()
    {
        return $this->hasMany(ProcessoDesignacao::class)->orderBy('created_at', 'desc');
    }

    /**
     * Relacionamento com designações pendentes
     */
    public function designacoesPendentes()
    {
        return $this->hasMany(ProcessoDesignacao::class)->where('status', 'pendente')->orderBy('created_at', 'desc');
    }

    /**
     * Relacionamento com alertas do processo
     */
    public function alertas()
    {
        return $this->hasMany(ProcessoAlerta::class)->orderBy('data_alerta', 'asc');
    }

    /**
     * Relacionamento com alertas pendentes
     */
    public function alertasPendentes()
    {
        return $this->hasMany(ProcessoAlerta::class)->where('status', 'pendente')->orderBy('data_alerta', 'asc');
    }

    /**
     * Verifica se um usuário está acompanhando o processo
     */
    public function estaAcompanhadoPor($usuarioId): bool
    {
        return $this->acompanhamentos()
            ->where('usuario_interno_id', $usuarioId)
            ->exists();
    }

    /**
     * Accessor para nome do tipo formatado
     */
    public function getTipoNomeAttribute(): string
    {
        // Tenta buscar da tabela tipo_processos
        if ($this->tipoProcesso) {
            return $this->tipoProcesso->nome;
        }
        
        // Fallback para array estático (compatibilidade)
        return self::tipos()[$this->tipo] ?? $this->tipo;
    }

    /**
     * Accessor para nome do status formatado
     */
    public function getStatusNomeAttribute(): string
    {
        return self::statusDisponiveis()[$this->status] ?? $this->status;
    }

    /**
     * Accessor para cor do status (para badges)
     */
    public function getStatusCorAttribute(): string
    {
        return match($this->status) {
            'aberto' => 'blue',
            'em_analise' => 'yellow',
            'pendente' => 'orange',
            'aprovado' => 'green',
            'indeferido' => 'red',
            'parado' => 'red',
            'arquivado' => 'gray',
            default => 'gray',
        };
    }

    /**
     * Scope para filtrar por estabelecimento
     */
    public function scopeDoEstabelecimento($query, $estabelecimentoId)
    {
        return $query->where('estabelecimento_id', $estabelecimentoId);
    }

    /**
     * Scope para filtrar por tipo
     */
    public function scopePorTipo($query, $tipo)
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Scope para filtrar por status
     */
    public function scopePorStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para filtrar processos do setor do usuário
     */
    public function scopeDoMeuSetor($query, $setor)
    {
        return $query->where('setor_atual', $setor);
    }

    /**
     * Scope para filtrar processos sob minha responsabilidade
     */
    public function scopeMeusProcessos($query, $usuarioId)
    {
        return $query->where('responsavel_atual_id', $usuarioId);
    }

    /**
     * Atribui o processo a um setor e/ou responsável
     */
    public function atribuirPara($setor = null, $responsavelId = null): void
    {
        $this->update([
            'setor_atual' => $setor,
            'responsavel_atual_id' => $responsavelId,
            'responsavel_desde' => now(),
        ]);
    }

    /**
     * Retorna texto formatado de quem está com o processo
     */
    public function getComQuemAttribute(): string
    {
        $partes = [];

        if ($this->status === 'arquivado') {
            if ($this->setor_antes_arquivar) {
                $partes[] = $this->setor_antes_arquivar_nome ?? $this->setor_antes_arquivar;
            }

            if ($this->responsavelAntesArquivar) {
                $partes[] = $this->responsavelAntesArquivar->nome;
            }
        } else {
            if ($this->setor_atual) {
                $partes[] = $this->setor_atual_nome ?? $this->setor_atual;
            }

            if ($this->responsavelAtual) {
                $partes[] = $this->responsavelAtual->nome;
            }
        }

        if (empty($partes)) {
            return $this->status === 'arquivado' ? 'Arquivado' : 'Não atribuído';
        }

        return implode(' - ', $partes);
    }

    /**
     * Retorna o nome do setor atual (busca do TipoSetor)
     */
    public function getSetorAtualNomeAttribute(): ?string
    {
        if (!$this->setor_atual) {
            return null;
        }
        
        $tipoSetor = \App\Models\TipoSetor::where('codigo', $this->setor_atual)->first();
        return $tipoSetor ? $tipoSetor->nome : $this->setor_atual;
    }

    /**
     * Retorna o nome do setor salvo antes do arquivamento
     */
    public function getSetorAntesArquivarNomeAttribute(): ?string
    {
        if (!$this->setor_antes_arquivar) {
            return null;
        }

        $tipoSetor = \App\Models\TipoSetor::where('codigo', $this->setor_antes_arquivar)->first();
        return $tipoSetor ? $tipoSetor->nome : $this->setor_antes_arquivar;
    }

    /**
     * Verifica se o processo está com determinado setor
     */
    public function estaComSetor($setor): bool
    {
        return $this->setor_atual === $setor;
    }

    /**
     * Verifica se o processo está com determinado usuário
     */
    public function estaComUsuario($usuarioId): bool
    {
        return $this->responsavel_atual_id === $usuarioId;
    }
}
