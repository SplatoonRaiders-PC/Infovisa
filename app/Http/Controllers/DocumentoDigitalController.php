<?php

namespace App\Http\Controllers;

use App\Enums\NivelAcesso;
use App\Models\DocumentoDigital;
use App\Models\DocumentoExigenciaColaborador;
use App\Models\DocumentoItemAtendimento;
use App\Models\DocumentoDigitalVersao;
use App\Models\DocumentoAssinatura;
use App\Models\ConfiguracaoSistema;
use App\Models\TipoDocumento;
use App\Models\ModeloDocumento;
use App\Models\ProcessoPasta;
use App\Models\UsuarioInterno;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Barryvdh\DomPDF\Facade\Pdf;

class DocumentoDigitalController extends Controller
{
    /**
     * Lista documentos digitais do usuário logado com filtros
     */
    public function index(Request $request)
    {
        $usuarioLogado = auth('interno')->user();
        $filtroStatus = $request->get('status', 'todos');
        $escopoSolicitado = $request->get('escopo', 'meus');
        $podeVerDocumentosDoSetor = ($usuarioLogado->isGestor() || $usuarioLogado->isAdmin()) && !empty($usuarioLogado->setor);
        $escopo = $podeVerDocumentosDoSetor && $escopoSolicitado === 'setor' ? 'setor' : 'meus';

        $query = $this->montarQueryDocumentosIndex($usuarioLogado, $escopo);
        
        // Aplicar filtro de status
        if ($filtroStatus !== 'todos') {
            switch ($filtroStatus) {
                case 'rascunho':
                    $query->where('status', 'rascunho');

                    if ($escopo === 'meus') {
                        $query->where('usuario_criador_id', $usuarioLogado->id);
                    }
                    break;
                    
                case 'aguardando_minha_assinatura':
                    if ($escopo === 'setor') {
                        $query->where('status', 'aguardando_assinatura');
                    } else {
                        $query->where('status', 'aguardando_assinatura')
                            ->whereHas('assinaturas', function($q) use ($usuarioLogado) {
                                $q->where('usuario_interno_id', $usuarioLogado->id)
                                  ->where('status', 'pendente');
                            });
                    }
                    break;
                    
                case 'assinados_por_mim':
                    if ($escopo === 'setor') {
                        $query->where('status', 'assinado');
                    } else {
                        $query->whereHas('assinaturas', function($q) use ($usuarioLogado) {
                            $q->where('usuario_interno_id', $usuarioLogado->id)
                              ->where('status', 'assinado');
                        });
                    }
                    break;
                    
                case 'aguardando_assinatura':
                    $query->where('status', 'aguardando_assinatura');
                    break;
                    
                case 'assinado':
                    $query->where('status', 'assinado');
                    break;
                    
                case 'com_prazos':
                    $query->whereNotNull('data_vencimento')
                          ->orderBy('data_vencimento', 'asc');
                    
                    // Filtro adicional por tipo de documento
                    if ($request->has('tipo_documento_id') && $request->get('tipo_documento_id') != '') {
                        $query->where('tipo_documento_id', $request->get('tipo_documento_id'));
                    }
                    break;
            }
        }
        
        $documentos = $query->orderBy('created_at', 'desc')->paginate(10);
        
        // Busca todos os tipos de documento para o filtro
        $tiposDocumento = \App\Models\TipoDocumento::where('ativo', true)
            ->orderBy('nome')
            ->get();
        
        // Estatísticas para badges (sem eager loading, apenas count)
        $statsBaseQuery = function() use ($usuarioLogado, $escopo) {
            $q = DocumentoDigital::query();
            if ($escopo === 'setor') {
                $tecnicosIds = $this->buscarTecnicosDoSetorIds($usuarioLogado);
                return empty($tecnicosIds) ? $q->whereRaw('1 = 0') : $q->whereIn('usuario_criador_id', $tecnicosIds);
            }
            return $q->where(function($sq) use ($usuarioLogado) {
                $sq->where('usuario_criador_id', $usuarioLogado->id)
                   ->orWhereHas('assinaturas', fn($aq) => $aq->where('usuario_interno_id', $usuarioLogado->id));
            });
        };
        $stats = [
            'rascunhos' => $statsBaseQuery()->where('status', 'rascunho')->when($escopo === 'meus', fn($q) => $q->where('usuario_criador_id', $usuarioLogado->id))->count(),
            'aguardando_minha_assinatura' => $escopo === 'setor'
                ? $statsBaseQuery()->where('status', 'aguardando_assinatura')->count()
                : $statsBaseQuery()->where('status', 'aguardando_assinatura')->whereHas('assinaturas', fn($q) => $q->where('usuario_interno_id', $usuarioLogado->id)->where('status', 'pendente'))->count(),
            'assinados_por_mim' => $escopo === 'setor'
                ? $statsBaseQuery()->where('status', 'assinado')->count()
                : $statsBaseQuery()->whereHas('assinaturas', fn($q) => $q->where('usuario_interno_id', $usuarioLogado->id)->where('status', 'assinado'))->count(),
            'com_prazos' => $statsBaseQuery()->whereNotNull('data_vencimento')->count(),
        ];

        return view('documentos.index', compact('documentos', 'filtroStatus', 'stats', 'tiposDocumento', 'escopo', 'podeVerDocumentosDoSetor'));
    }

    private function montarQueryDocumentosIndex(UsuarioInterno $usuarioLogado, string $escopo)
    {
        $query = DocumentoDigital::with(['tipoDocumento', 'usuarioCriador', 'processo.estabelecimento', 'assinaturas.usuarioInterno']);

        if ($escopo === 'setor') {
            $tecnicosDoSetorIds = $this->buscarTecnicosDoSetorIds($usuarioLogado);

            if (empty($tecnicosDoSetorIds)) {
                return $query->whereRaw('1 = 0');
            }

            return $query->whereIn('usuario_criador_id', $tecnicosDoSetorIds);
        }

        return $query->where(function($q) use ($usuarioLogado) {
            $q->where('usuario_criador_id', $usuarioLogado->id)
              ->orWhereHas('assinaturas', function($query) use ($usuarioLogado) {
                  $query->where('usuario_interno_id', $usuarioLogado->id);
              });
        });
    }

    private function buscarTecnicosDoSetorIds(UsuarioInterno $usuarioLogado): array
    {
        if (!$usuarioLogado->setor) {
            return [];
        }

        $query = UsuarioInterno::where('setor', $usuarioLogado->setor)
            ->where('ativo', true);

        if ($usuarioLogado->isGestor()) {
            if ($usuarioLogado->isMunicipal()) {
                $query->where('nivel_acesso', NivelAcesso::TecnicoMunicipal->value)
                    ->where('municipio_id', $usuarioLogado->municipio_id);
            } elseif ($usuarioLogado->isEstadual()) {
                $query->where('nivel_acesso', NivelAcesso::TecnicoEstadual->value);
            }
        } elseif ($usuarioLogado->isAdmin()) {
            $query->whereIn('nivel_acesso', [
                NivelAcesso::TecnicoEstadual->value,
                NivelAcesso::TecnicoMunicipal->value,
            ]);

            if ($usuarioLogado->municipio_id) {
                $query->where('municipio_id', $usuarioLogado->municipio_id);
            }
        } else {
            return [];
        }

        return $query->pluck('id')->all();
    }

    /**
     * Exibe formulário para criar novo documento
     */
    public function create(Request $request)
    {
        $tiposDocumentoQuery = TipoDocumento::where('ativo', true)
            ->visivelParaUsuario()
            ->with('subcategoriasAtivas')
            ->orderBy('nome');

        // Se vem do estabelecimento (sem processo), mostra apenas tipos com abertura automática de processo
        if ($request->filled('estabelecimento_id') && !$request->filled('processo_id') && empty($request->input('processos_ids'))) {
            $tiposDocumentoQuery->where('abrir_processo_automaticamente', true);
        }

        $tiposDocumento = $tiposDocumentoQuery->get();

        // Usuarios disponiveis para assinatura (municipio + estadual)
        $usuarioLogado = auth('interno')->user();
        $usuariosInternos = UsuarioInterno::paraSelecaoAssinantes($usuarioLogado)->orderBy('nome')->get();

        $processoId = $request->get('processo_id');
        $processo = null;

        $processosIds = $request->input('processos_ids', []);
        if (is_string($processosIds)) {
            $processosIds = array_filter(array_map('trim', explode(',', $processosIds)));
        }
        $processosIds = collect($processosIds)
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $processoAtividadeOsId = $this->processoIdDaAtividadeOs(
            $request->get('os_id'),
            $request->get('atividade_index')
        );

        if ($processoAtividadeOsId) {
            $processosIds = [$processoAtividadeOsId];
            $processoId = $processoAtividadeOsId;
        }

        $processosSelecionados = collect();
        if (!empty($processosIds)) {
            $processosSelecionados = \App\Models\Processo::with(['estabelecimento.municipioRelacionado', 'estabelecimento.usuariosVinculados'])
                ->whereIn('id', $processosIds)
                ->get();
        }

        if ($processosSelecionados->isNotEmpty()) {
            $processo = $processosSelecionados->first();
        } elseif ($processoId) {
            $processo = \App\Models\Processo::with(['estabelecimento.municipioRelacionado', 'estabelecimento.usuariosVinculados'])->find($processoId);
            if ($processo) {
                $processosSelecionados = collect([$processo]);
                $processosIds = [$processo->id];
            }
        }

        $processosSemUsuarioExterno = $this->filtrarProcessosSemUsuarioExterno($processosSelecionados);
        $processosSemUsuarioExternoCount = $processosSemUsuarioExterno->count();

        $pastasProcesso = collect();
        if ($processo && $processosSelecionados->count() === 1) {
            $pastasProcesso = $processo->pastas()
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get();
        }

        // Determina qual logomarca usar
        $logomarca = $this->determinarLogomarca($processo, $usuarioLogado);

        $osId = $request->get('os_id');
        $atividadeIndex = $request->get('atividade_index');
        $assinaturasPreSelecionadas = [];

        if ($osId && $atividadeIndex !== null) {
            $ordemServico = \App\Models\OrdemServico::select(['id', 'atividades_tecnicos'])->find($osId);
            $atividades = $ordemServico?->atividades_tecnicos ?? [];

            if (isset($atividades[$atividadeIndex]) && is_array($atividades[$atividadeIndex])) {
                $assinaturasPreSelecionadas = collect($atividades[$atividadeIndex]['tecnicos'] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)
                    ->unique()
                    ->values()
                    ->all();
            }
        }

        // Estabelecimento direto (sem processo - para criação de documento com processo automático)
        $estabelecimento = null;
        $estabelecimentoId = $request->get('estabelecimento_id');
        if ($estabelecimentoId && !$processo) {
            $estabelecimento = \App\Models\Estabelecimento::with(['municipioRelacionado', 'usuariosVinculados'])->find($estabelecimentoId);
        }

        // Verifica se o usuário pode marcar documentos como sigiloso
        $niveisPermitidosSigiloso = json_decode(ConfiguracaoSistema::obter('niveis_permitidos_sigiloso', '["administrador","gestor_estadual","gestor_municipal"]'), true) ?? [];
        $podeMarcarSigiloso = $usuarioLogado->isAdmin() || in_array($usuarioLogado->nivel_acesso->value, $niveisPermitidosSigiloso);

        return view('documentos.create', compact('tiposDocumento', 'usuariosInternos', 'processo', 'logomarca', 'processosSelecionados', 'processosIds', 'osId', 'atividadeIndex', 'assinaturasPreSelecionadas', 'pastasProcesso', 'processosSemUsuarioExterno', 'processosSemUsuarioExternoCount', 'estabelecimento', 'podeMarcarSigiloso'));
    }

    /**
     * Exibe formulário para criar documento FÍSICO (auto entregue em loco)
     */
    public function createFisico(Request $request)
    {
        $usuarioLogado = auth('interno')->user();

        // Tipos de documento (mesma regra do digital)
        $tiposDocumentoQuery = TipoDocumento::where('ativo', true)
            ->visivelParaUsuario()
            ->orderBy('nome');

        // Se vem do estabelecimento (sem processo), mostra apenas tipos com abertura automática de processo
        if ($request->filled('estabelecimento_id') && !$request->filled('processo_id')) {
            $tiposDocumentoQuery->where('abrir_processo_automaticamente', true);
        }

        $tiposDocumento = $tiposDocumentoQuery->get();

        // Estabelecimento
        $estabelecimento = null;
        $estabelecimentoId = $request->get('estabelecimento_id');
        if ($estabelecimentoId) {
            $estabelecimento = \App\Models\Estabelecimento::with(['municipioRelacionado'])->find($estabelecimentoId);
        }

        // Processo (se vier especificado)
        $processo = null;
        $processoId = $request->get('processo_id');
        if ($processoId) {
            $processo = \App\Models\Processo::with('estabelecimento')->find($processoId);
        }

        // Pastas do processo
        $pastasProcesso = collect();
        if ($processo) {
            $pastasProcesso = $processo->pastas()->orderBy('ordem')->orderBy('nome')->get();
        }

        // Verifica se pode marcar como sigiloso
        $niveisPermitidosSigiloso = json_decode(ConfiguracaoSistema::obter('niveis_permitidos_sigiloso', '["administrador","gestor_estadual","gestor_municipal"]'), true) ?? [];
        $podeMarcarSigiloso = $usuarioLogado->isAdmin() || in_array($usuarioLogado->nivel_acesso->value, $niveisPermitidosSigiloso);

        return view('documentos.create-fisico', compact('tiposDocumento', 'estabelecimento', 'processo', 'pastasProcesso', 'podeMarcarSigiloso'));
    }

    /**
     * Salva um documento FÍSICO (upload de PDF + dados)
     */
    public function storeFisico(Request $request)
    {
        $request->validate([
            'tipo_documento_id' => 'required|exists:tipo_documentos,id',
            'arquivo_fisico_pdf' => 'required|file|mimes:pdf|max:20480',
            'data_entrega_fisica' => 'required|date',
            'processo_id' => 'nullable|exists:processos,id',
            'estabelecimento_id' => 'nullable|exists:estabelecimentos,id',
            'pasta_id' => 'nullable|integer',
            'sigiloso' => 'boolean',
            'prazo_dias' => 'nullable|integer|min:1',
            'tipo_prazo' => 'nullable|in:corridos,uteis',
            'observacoes' => 'nullable|string|max:2000',
        ]);

        try {
            DB::beginTransaction();

            $tipoDocumento = TipoDocumento::findOrFail($request->tipo_documento_id);
            $usuarioLogado = auth('interno')->user();

            // Determina processo (pode vir direto ou abrir automaticamente do estabelecimento)
            $processo = null;
            if ($request->processo_id) {
                $processo = \App\Models\Processo::find($request->processo_id);
            } elseif ($request->estabelecimento_id && $tipoDocumento->abrir_processo_automaticamente) {
                // Se o tipo de documento abre processo automaticamente, cria o processo
                $estabelecimento = \App\Models\Estabelecimento::findOrFail($request->estabelecimento_id);
                $tipoProcessoCodigo = $tipoDocumento->tipo_processo_codigo;
                $tipoProcesso = $tipoProcessoCodigo
                    ? \App\Models\TipoProcesso::where('codigo', $tipoProcessoCodigo)->first()
                    : null;

                if ($tipoProcesso) {
                    $ano = date('Y');
                    $dadosNumero = \App\Models\Processo::gerarNumeroProcesso($ano);
                    $processo = \App\Models\Processo::create([
                        'estabelecimento_id' => $estabelecimento->id,
                        'tipo' => $tipoProcesso->codigo,
                        'tipo_processo_id' => $tipoProcesso->id,
                        'ano' => $ano,
                        'numero_sequencial' => $dadosNumero['numero_sequencial'],
                        'numero_processo' => $dadosNumero['numero_processo'],
                        'status' => 'aberto',
                        'usuario_id' => $usuarioLogado->id,
                    ]);
                }
            }

            // Upload do PDF
            $arquivoPath = $request->file('arquivo_fisico_pdf')
                ->store('documentos-fisicos/' . date('Y/m'), 'public');

            // Calcula data de vencimento (se houver prazo)
            $dataVencimento = null;
            if ($request->prazo_dias && $request->prazo_dias > 0) {
                $dataEntrega = \Carbon\Carbon::parse($request->data_entrega_fisica);
                // O prazo começa NO DIA ÚTIL SEGUINTE à entrega
                $dataInicio = $dataEntrega->copy()->addWeekday();
                $tipoPrazo = $request->tipo_prazo ?? 'corridos';
                $prazoDias = (int) $request->prazo_dias;

                $dataVencimento = $tipoPrazo === 'uteis'
                    ? $dataInicio->copy()->addWeekdays($prazoDias)
                    : $dataInicio->copy()->addDays($prazoDias);
            }

            // Cria o documento
            $documento = DocumentoDigital::create([
                'tipo_documento_id' => $request->tipo_documento_id,
                'processo_id' => $processo?->id,
                'pasta_id' => $request->pasta_id ?: null,
                'usuario_criador_id' => $usuarioLogado->id,
                'numero_documento' => DocumentoDigital::gerarNumeroDocumento(),
                'nome' => $tipoDocumento->nome,
                'conteudo' => $request->observacoes ?? '',
                'sigiloso' => $request->sigiloso ?? false,
                'status' => 'assinado', // Físico já está pronto, sem fluxo de assinatura digital
                'tipo_origem' => 'fisico',
                'arquivo_fisico_pdf' => $arquivoPath,
                'data_entrega_fisica' => $request->data_entrega_fisica,
                'arquivo_pdf' => $arquivoPath, // Reutiliza o mesmo PDF
                'finalizado_em' => now(),
                'codigo_autenticidade' => DocumentoDigital::gerarCodigoAutenticidade(),
                'prazo_dias' => $request->prazo_dias,
                'tipo_prazo' => $request->tipo_prazo,
                'data_vencimento' => $dataVencimento,
                'prazo_notificacao' => $tipoDocumento->prazo_notificacao ?? false,
                // Inicia o prazo automaticamente já que o documento foi entregue
                'prazo_iniciado_em' => $request->prazo_dias ? \Carbon\Carbon::parse($request->data_entrega_fisica)->addWeekday() : null,
                'prazo_iniciado_por' => $request->prazo_dias ? $usuarioLogado->id : null,
            ]);

            // Registra evento no processo
            if ($processo) {
                \App\Models\ProcessoEvento::registrarDocumentoDigitalCriado($processo, $documento);
            }

            DB::commit();

            return redirect()
                ->route('admin.documentos.show', $documento->id)
                ->with('success', 'Documento físico criado com sucesso!');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erro ao criar documento físico', [
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return back()->withInput()->withErrors([
                'erro' => 'Erro ao criar documento físico: ' . $e->getMessage(),
            ]);
        }
    }

    private function filtrarProcessosSemUsuarioExterno($processos)
    {
        return collect($processos)
            ->filter(function ($processo) {
                $estabelecimento = $processo?->estabelecimento;

                return $estabelecimento && !$estabelecimento->possuiUsuariosExternosVinculados();
            })
            ->values();
    }

    /**
     * Determina qual logomarca usar no documento baseado na competência e município
     * 
     * REGRAS:
     * 1. Se estabelecimento é de COMPETÊNCIA ESTADUAL -> sempre usa logomarca estadual
     * 2. Se estabelecimento é MUNICIPAL e município tem logomarca -> usa logomarca do município
     * 3. Se estabelecimento é MUNICIPAL mas município NÃO tem logomarca -> usa logomarca estadual (fallback)
     * 4. Se não houver processo -> usa logomarca do usuário logado
     */
    private function determinarLogomarca($processo, $usuarioLogado)
    {
        // Se não houver processo, usa logomarca do usuário
        if (!$processo || !$processo->estabelecimento) {
            return $usuarioLogado->getLogomarcaDocumento();
        }

        $estabelecimento = $processo->estabelecimento;
        
        // 1. Se estabelecimento é de COMPETÊNCIA ESTADUAL -> sempre usa logomarca estadual
        if ($estabelecimento->isCompetenciaEstadual()) {
            return \App\Models\ConfiguracaoSistema::logomarcaEstadual();
        }
        
        // 2. Se estabelecimento é MUNICIPAL e tem município vinculado
        if ($estabelecimento->municipio_id) {
            $municipio = $estabelecimento->municipioRelacionado;
            
            // Se município tem logomarca cadastrada, usa ela
            if ($municipio && $municipio->logomarca) {
                return $municipio->logomarca;
            }
        }
        
        // 3. Fallback: município sem logomarca ou sem município vinculado -> usa logomarca estadual
        return \App\Models\ConfiguracaoSistema::logomarcaEstadual();
    }

    /**
     * Busca modelos por tipo de documento (AJAX)
     */
    public function buscarModelos(Request $request, $tipoId)
    {
        $usuario = auth('interno')->user();

        $query = ModeloDocumento::query();

        $processoId = $request->integer('processo_id');
        if ($processoId > 0) {
            $processo = \App\Models\Processo::with('estabelecimento')->find($processoId);
            $estabelecimento = $processo?->estabelecimento;

            if ($estabelecimento && !$estabelecimento->isCompetenciaEstadual() && $estabelecimento->municipio_id) {
                $query->doMunicipio($estabelecimento->municipio_id);
            } else {
                $query->estaduais();
            }
        } else {
            $query->disponiveisParaUsuario($usuario);
        }

        // Filtra por subcategoria quando informada.
        // Regra: inclui modelos da subcategoria específica + modelos "genéricos" (subcategoria_id NULL).
        $subcategoriaId = $request->integer('subcategoria_id');
        if ($subcategoriaId > 0) {
            $query->where(function ($q) use ($subcategoriaId) {
                $q->where('subcategoria_id', $subcategoriaId)
                    ->orWhereNull('subcategoria_id');
            });
        }

        $modelos = $query
            ->where('tipo_documento_id', $tipoId)
            ->where('ativo', true)
            ->orderBy('ordem')
            ->get(['id', 'descricao', 'conteudo', 'subcategoria_id']);

        return response()->json($modelos);
    }

    /**
     * Busca informações de prazo do tipo de documento (AJAX)
     */
    public function buscarPrazoTipo($tipoId)
    {
        $tipo = TipoDocumento::findOrFail($tipoId);

        return response()->json([
            'tem_prazo' => $tipo->tem_prazo,
            'prazo_padrao_dias' => $tipo->prazo_padrao_dias,
            'tipo_prazo' => $tipo->tipo_prazo ?? 'corridos',
        ]);
    }

    /**
     * Salva novo documento
     */
    public function store(Request $request)
    {
        $request->validate([
            'tipo_documento_id' => 'required|exists:tipo_documentos,id',
            'subcategoria_id' => 'nullable|exists:tipo_documento_subcategorias,id',
            'conteudo' => 'required',
            'sigiloso' => 'boolean',
            'assinaturas' => 'required|array|min:1',
            'assinaturas.*' => 'exists:usuarios_internos,id',
            'prazo_dias' => 'nullable|integer|min:1',
            'tipo_prazo' => 'nullable|in:corridos,uteis',
            'confirmar_sem_usuario_externo' => 'nullable|boolean',
            'processo_id' => 'nullable|exists:processos,id',
            'processos_ids' => 'nullable|array',
            'processos_ids.*' => 'exists:processos,id',
            'pasta_id' => 'nullable|integer',
            'os_id' => 'nullable|exists:ordens_servico,id',
            'atividade_index' => 'nullable|integer|min:0',
            'itens_atendimento' => 'nullable|array|max:100',
            'itens_atendimento.*.area' => 'nullable|string|max:255',
            'itens_atendimento.*.descricao' => 'nullable|string|max:2000',
            'itens_atendimento.*.embasamento_legal' => 'nullable|string|max:5000',
            'colaboradores_exigencias' => 'nullable|array|max:50',
            'colaboradores_exigencias.*.usuario_interno_id' => 'nullable|exists:usuarios_internos,id',
            'colaboradores_exigencias.*.area' => 'nullable|string|max:255',
            'colaboradores_exigencias.*.prazo_interno' => 'nullable|date',
        ]);

        $tipoDocumento = TipoDocumento::findOrFail($request->tipo_documento_id);
        $itensAtendimento = $this->normalizarItensAtendimento($request, $tipoDocumento);
        $colaboradoresExigencias = $this->normalizarColaboradoresExigencias($request, $tipoDocumento);

        // Garante que a subcategoria pertence ao tipo selecionado
        $subcategoriaId = $request->input('subcategoria_id');
        if ($subcategoriaId) {
            $pertence = \App\Models\TipoDocumentoSubcategoria::where('id', $subcategoriaId)
                ->where('tipo_documento_id', $request->input('tipo_documento_id'))
                ->exists();
            if (!$pertence) {
                throw ValidationException::withMessages([
                    'subcategoria_id' => 'Subcategoria inválida para o tipo selecionado.',
                ]);
            }
        }

        $conteudoNormalizado = $this->preservarEspacamentoConteudoHtml(
            DocumentoDigital::externalizarImagensBase64($request->conteudo)
        );

        $retornoParaFinalizacaoAtividade = $request->filled('os_id') && $request->filled('atividade_index');

        $processosIds = collect($request->input('processos_ids', []))
            ->map(fn($id) => (int) $id)
            ->filter(fn($id) => $id > 0)
            ->unique()
            ->values();

        if ($processosIds->isEmpty() && $request->processo_id) {
            $processosIds = collect([(int) $request->processo_id]);
        }

        $processosIds = $this->ajustarProcessosIdsParaAtividadeOs($request, $processosIds);

        $pastaId = null;
        if ($request->filled('pasta_id')) {
            if ($processosIds->count() !== 1) {
                throw ValidationException::withMessages([
                    'pasta_id' => 'Selecione uma pasta apenas em criação para um único processo.',
                ]);
            }

            $processoPastaId = (int) $processosIds->first();
            $pasta = ProcessoPasta::where('id', (int) $request->pasta_id)
                ->where('processo_id', $processoPastaId)
                ->first();

            if (!$pasta) {
                throw ValidationException::withMessages([
                    'pasta_id' => 'A pasta selecionada não pertence ao processo informado.',
                ]);
            }

            $pastaId = $pasta->id;
        }

        try {
            DB::beginTransaction();

            // Busca o tipo de documento para pegar o nome
            $processosDestino = $processosIds->isNotEmpty()
                ? \App\Models\Processo::with([
                    'estabelecimento.responsaveisLegais',
                    'estabelecimento.responsaveisTecnicos',
                    'estabelecimento.municipioRelacionado',
                    'estabelecimento.usuariosVinculados',
                ])
                    ->whereIn('id', $processosIds)
                    ->get()
                : collect();

            $processosSemUsuarioExterno = $this->filtrarProcessosSemUsuarioExterno($processosDestino);

            if ($request->filled('prazo_dias') && $processosSemUsuarioExterno->isNotEmpty() && !$request->boolean('confirmar_sem_usuario_externo')) {
                $mensagem = $processosSemUsuarioExterno->count() === 1
                    ? 'Não existe usuário vinculado a esse estabelecimento para visualizar este documento com prazo. Confirme que deseja criar o documento mesmo assim.'
                    : 'Existem processos selecionados sem usuário vinculado ao estabelecimento para visualizar o documento com prazo. Confirme que deseja criar os documentos mesmo assim.';

                throw ValidationException::withMessages([
                    'prazo_dias' => $mensagem,
                ]);
            }
            
            // Calcula data de vencimento se prazo foi informado
            $dataVencimento = null;
            $tipoPrazo = $request->tipo_prazo ?? 'corridos';
            
            if ($request->prazo_dias) {
                $dataInicio = now();
                $diasPrazo = (int) $request->prazo_dias;
                
                if ($tipoPrazo === 'corridos') {
                    // Dias corridos: simplesmente adiciona os dias
                    $dataVencimento = $dataInicio->addDays($diasPrazo)->format('Y-m-d');
                } else {
                    // Dias úteis: adiciona apenas dias úteis (segunda a sexta)
                    $diasAdicionados = 0;
                    $dataAtual = $dataInicio->copy();
                    
                    while ($diasAdicionados < $diasPrazo) {
                        $dataAtual->addDay();
                        // 0 = Domingo, 6 = Sábado
                        if ($dataAtual->dayOfWeek !== 0 && $dataAtual->dayOfWeek !== 6) {
                            $diasAdicionados++;
                        }
                    }
                    
                    $dataVencimento = $dataAtual->format('Y-m-d');
                }
            }
            
            $isLote = $processosIds->count() > 1;

            // ===================================================
            // LOTE (multi-processo): cria UM documento vinculado a
            // todos os processos. O fan-out acontece na assinatura.
            // ===================================================
            if ($isLote) {
                $primeiroProcesso = $processosDestino->first();

                $documento = DocumentoDigital::create([
                    'tipo_documento_id' => $request->tipo_documento_id,
                    'subcategoria_id'   => $subcategoriaId ?: null,
                    'processo_id'       => $primeiroProcesso?->id,
                    'pasta_id'          => $pastaId,
                    'processos_ids'     => $processosIds->all(),
                    'os_id'             => $request->os_id,
                    'atividade_index'   => $request->atividade_index,
                    'usuario_criador_id' => Auth::guard('interno')->user()->id,
                    'numero_documento'  => DocumentoDigital::gerarNumeroDocumento(),
                    'nome'              => $tipoDocumento->nome,
                    'conteudo'          => $conteudoNormalizado, // sem substituição — será feita no fan-out
                    'sigiloso'          => $request->sigiloso ?? false,
                    'status'            => $request->acao === 'finalizar' ? 'aguardando_assinatura' : 'rascunho',
                    'prazo_dias'        => $request->prazo_dias,
                    'tipo_prazo'        => $tipoPrazo,
                    'data_vencimento'   => $dataVencimento,
                    'prazo_notificacao' => $tipoDocumento->prazo_notificacao ?? false,
                ]);

                $this->sincronizarItensAtendimento($documento, $itensAtendimento);
                $this->sincronizarColaboradoresExigencias($documento, $colaboradoresExigencias);

                foreach ($request->assinaturas as $index => $usuarioId) {
                    DocumentoAssinatura::create([
                        'documento_digital_id' => $documento->id,
                        'usuario_interno_id'   => $usuarioId,
                        'ordem'                => $index + 1,
                        'obrigatoria'          => true,
                        'status'               => 'pendente',
                    ]);
                }

                $documento->salvarVersao(
                    Auth::guard('interno')->user()->id,
                    $conteudoNormalizado,
                    null
                );

                DB::commit();

                $msgProcessos = $processosDestino->pluck('numero_processo')->implode(', ');

                if ($retornoParaFinalizacaoAtividade) {
                    return redirect()->route('admin.ordens-servico.show-finalizar-atividade', [
                        'ordemServico' => $request->os_id,
                        'atividadeIndex' => $request->atividade_index,
                    ])->with('success', "Documento criado para {$processosDestino->count()} processos ({$msgProcessos}). " .
                        ($documento->status === 'rascunho'
                            ? 'Está como rascunho e você voltou para a finalização da atividade.'
                            : 'Documento finalizado e você voltou para a finalização da atividade.'));
                }

                if ($request->os_id) {
                    return redirect()->route('admin.ordens-servico.show', $request->os_id)
                        ->with('success', "Documento criado para {$processosDestino->count()} processos ({$msgProcessos}). " .
                            ($documento->status === 'rascunho'
                                ? 'Está como rascunho — finalize e assine para distribuir aos processos.'
                                : 'Aguardando assinatura — ao assinar, será distribuído aos processos.'));
                }

                return redirect()->route('admin.documentos.show', $documento->id)
                    ->with('success', "Documento em lote criado para {$processosDestino->count()} processos. " .
                        ($documento->status === 'rascunho'
                            ? 'Edite e finalize quando estiver pronto.'
                            : 'Aguardando assinatura.'));
            }

            // ===================================================
            // ÚNICO (1 processo ou nenhum): fluxo normal
            // ===================================================
            $documentosCriados = collect();

            $destinos = $processosDestino->isNotEmpty() ? $processosDestino : collect([null]);

            foreach ($destinos as $processoDestino) {
                // Auto-criar processo se o tipo de documento exige e não tem processo vinculado
                if (!$processoDestino && $tipoDocumento->abrir_processo_automaticamente && $tipoDocumento->tipo_processo_codigo) {
                    $estabelecimentoId = $request->input('estabelecimento_id');
                    if ($estabelecimentoId) {
                        $estabelecimentoAuto = \App\Models\Estabelecimento::find($estabelecimentoId);
                        if ($estabelecimentoAuto) {
                            $tipoProcessoAuto = \App\Models\TipoProcesso::where('codigo', $tipoDocumento->tipo_processo_codigo)->where('ativo', true)->first();
                            if ($tipoProcessoAuto) {
                                $ano = date('Y');
                                $dadosNumero = \App\Models\Processo::gerarNumeroProcesso($ano);
                                $dadosProcesso = [
                                    'estabelecimento_id' => $estabelecimentoAuto->id,
                                    'tipo' => $tipoProcessoAuto->codigo,
                                    'ano' => $dadosNumero['ano'],
                                    'numero_sequencial' => $dadosNumero['numero_sequencial'],
                                    'numero_processo' => $dadosNumero['numero_processo'],
                                    'status' => 'aberto',
                                ];
                                $setorInicial = $tipoProcessoAuto->resolverSetorInicial($estabelecimentoAuto);
                                if ($setorInicial) {
                                    $dadosProcesso['setor_atual'] = $setorInicial->codigo;
                                }
                                $processoDestino = \App\Models\Processo::create($dadosProcesso);
                                \Log::info('Processo criado automaticamente ao criar documento', [
                                    'processo_id' => $processoDestino->id,
                                    'numero' => $processoDestino->numero_processo,
                                    'tipo_documento' => $tipoDocumento->nome,
                                    'estabelecimento_id' => $estabelecimentoAuto->id,
                                ]);
                            }
                        }
                    }
                }

                $estabelecimento = $processoDestino?->estabelecimento;
                $conteudoProcessado = $this->preservarEspacamentoConteudoHtml(
                    $this->substituirVariaveis($conteudoNormalizado, $estabelecimento, $processoDestino)
                );

                $documento = DocumentoDigital::create([
                    'tipo_documento_id' => $request->tipo_documento_id,
                    'subcategoria_id' => $subcategoriaId ?: null,
                    'processo_id' => $processoDestino?->id,
                    'pasta_id' => $pastaId,
                    'os_id' => $request->os_id,
                    'atividade_index' => $request->atividade_index,
                    'usuario_criador_id' => Auth::guard('interno')->user()->id,
                    'numero_documento' => DocumentoDigital::gerarNumeroDocumento(),
                    'nome' => $tipoDocumento->nome,
                    'conteudo' => $conteudoProcessado,
                    'sigiloso' => $request->sigiloso ?? false,
                    'status' => $request->acao === 'finalizar' ? 'aguardando_assinatura' : 'rascunho',
                    'prazo_dias' => $request->prazo_dias,
                    'tipo_prazo' => $tipoPrazo,
                    'data_vencimento' => $dataVencimento,
                    'prazo_notificacao' => $tipoDocumento->prazo_notificacao ?? false,
                ]);

                $this->sincronizarItensAtendimento($documento, $itensAtendimento);
                $this->sincronizarColaboradoresExigencias($documento, $colaboradoresExigencias);

                foreach ($request->assinaturas as $index => $usuarioId) {
                    DocumentoAssinatura::create([
                        'documento_digital_id' => $documento->id,
                        'usuario_interno_id' => $usuarioId,
                        'ordem' => $index + 1,
                        'obrigatoria' => true,
                        'status' => 'pendente',
                    ]);
                }

                $documento->salvarVersao(
                    Auth::guard('interno')->user()->id,
                    $conteudoProcessado,
                    null
                );

                if ($request->acao === 'finalizar' && $processoDestino?->id) {
                    $this->gerarESalvarPDF($documento, $processoDestino->id);
                }

                if ($processoDestino?->id) {
                    \App\Models\ProcessoEvento::registrarDocumentoDigitalCriado($processoDestino, $documento);
                }

                $documentosCriados->push($documento);
            }

            DB::commit();

            if ($retornoParaFinalizacaoAtividade) {
                return redirect()->route('admin.ordens-servico.show-finalizar-atividade', [
                    'ordemServico' => $request->os_id,
                    'atividadeIndex' => $request->atividade_index,
                ])->with('success', $documentosCriados->count() > 1
                    ? "{$documentosCriados->count()} documentos criados com sucesso para os processos selecionados!"
                    : 'Documento criado com sucesso!');
            }

            if ($request->os_id) {
                return redirect()->route('admin.ordens-servico.show', $request->os_id)
                    ->with('success', $documentosCriados->count() > 1
                        ? "{$documentosCriados->count()} documentos criados com sucesso para os processos selecionados!"
                        : 'Documento criado com sucesso!');
            }

            // Se veio de um processo único, redireciona de volta para o processo
            if ($processosDestino->count() === 1) {
                $processo = $processosDestino->first();
                return redirect()->route('admin.estabelecimentos.processos.show', [$processo->estabelecimento_id, $processo->id])
                    ->with('success', 'Documento criado com sucesso!' . ($request->acao === 'finalizar' ? ' PDF gerado e anexado ao processo.' : ''));
            }

            if ($processosDestino->count() > 1) {
                return redirect()->route('admin.documentos.index')
                    ->with('success', "{$documentosCriados->count()} documentos criados com sucesso para os processos selecionados!");
            }

            return redirect()->route('admin.documentos.show', $documentosCriados->first()->id)
                ->with('success', 'Documento criado com sucesso!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Erro ao criar documento: ' . $e->getMessage());
        }
    }

    /**
     * Exibe documento
     */
    public function show($id)
    {
        $documento = DocumentoDigital::with(['tipoDocumento', 'usuarioCriador', 'processo.estabelecimento', 'assinaturas.usuarioInterno'])
            ->findOrFail($id);

        $this->ajustarEscopoDocumentoAtividadeOs($documento);

        $processoContexto = $this->resolverProcessoContextoDocumento($documento);

        return view('documentos.show', compact('documento', 'processoContexto'));
    }

    private function processoIdDaAtividadeOs($osId, $atividadeIndex): ?int
    {
        if (!$osId || $atividadeIndex === null || $atividadeIndex === '') {
            return null;
        }

        $ordemServico = \App\Models\OrdemServico::with('estabelecimentos')->find((int) $osId);
        if (!$ordemServico) {
            return null;
        }

        $atividades = $ordemServico->atividades_tecnicos ?? [];
        $atividadeIndex = (int) $atividadeIndex;

        if (!isset($atividades[$atividadeIndex]) || !is_array($atividades[$atividadeIndex])) {
            return null;
        }

        $estabelecimentoId = (int) ($atividades[$atividadeIndex]['estabelecimento_id'] ?? 0);
        if ($estabelecimentoId <= 0) {
            return null;
        }

        $estabelecimento = $ordemServico->estabelecimentos->firstWhere('id', $estabelecimentoId);
        $processoId = (int) ($estabelecimento?->pivot?->processo_id ?? 0);

        if ($processoId <= 0 && (int) $ordemServico->estabelecimento_id === $estabelecimentoId) {
            $processoId = (int) $ordemServico->processo_id;
        }

        return $processoId > 0 ? $processoId : null;
    }

    private function ajustarProcessosIdsParaAtividadeOs(Request $request, $processosIds)
    {
        $processoId = $this->processoIdDaAtividadeOs(
            $request->input('os_id'),
            $request->input('atividade_index')
        );

        if ($processoId) {
            return collect([$processoId]);
        }

        return $processosIds instanceof \Illuminate\Support\Collection
            ? $processosIds
            : collect($processosIds);
    }

    private function ajustarEscopoDocumentoAtividadeOs(DocumentoDigital $documento): void
    {
        if (!$documento->os_id || $documento->atividade_index === null) {
            return;
        }

        if (!in_array($documento->status, ['rascunho', 'aguardando_assinatura'], true)) {
            return;
        }

        $processoId = $this->processoIdDaAtividadeOs($documento->os_id, $documento->atividade_index);
        if (!$processoId) {
            return;
        }

        $processosAtuais = collect($documento->processos_ids ?: [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $precisaCorrigir = (int) $documento->processo_id !== $processoId
            || $processosAtuais->count() !== 1
            || !$processosAtuais->contains($processoId);

        if (!$precisaCorrigir) {
            return;
        }

        $documento->forceFill([
            'processo_id' => $processoId,
            'processos_ids' => [$processoId],
        ])->save();

        $documento->load([
            'processo.estabelecimento',
            'tipoDocumento',
            'usuarioCriador',
            'assinaturas.usuarioInterno',
        ]);
    }

    private function resolverProcessoContextoDocumento(DocumentoDigital $documento, ?int $processoId = null): ?\App\Models\Processo
    {
        $processoId = $processoId ?: (int) request()->query('processo_id', 0);

        if ($processoId <= 0) {
            return null;
        }

        $processosPermitidos = collect($documento->processos_ids ?: [$documento->processo_id])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if (!$processosPermitidos->contains($processoId)) {
            return null;
        }

        return \App\Models\Processo::with([
            'tipoProcesso',
            'estabelecimento.responsaveisLegais',
            'estabelecimento.responsaveisTecnicos',
            'estabelecimento.municipio',
            'estabelecimento.municipioRelacionado',
        ])->find($processoId);
    }

    private function documentoComContextoProcesso(DocumentoDigital $documento, ?\App\Models\Processo $processoContexto): DocumentoDigital
    {
        if (!$processoContexto || !$processoContexto->estabelecimento) {
            return $documento;
        }

        $conteudo = $documento->conteudo ?? '';

        if ($documento->isLote()) {
            $documento->loadMissing([
                'processo.estabelecimento.responsaveisLegais',
                'processo.estabelecimento.responsaveisTecnicos',
                'processo.estabelecimento.municipio',
                'processo.estabelecimento.municipioRelacionado',
            ]);

            $conteudo = $this->substituirValoresRenderizadosDoContexto(
                $conteudo,
                $documento->processo,
                $processoContexto
            );
        }

        $conteudo = $this->substituirVariaveis($conteudo, $processoContexto->estabelecimento, $processoContexto);

        $documentoContextualizado = clone $documento;
        $documentoContextualizado->conteudo = $conteudo;
        $documentoContextualizado->processo_id = $processoContexto->id;
        $documentoContextualizado->setRelation('processo', $processoContexto);

        foreach (['tipoDocumento', 'usuarioCriador', 'assinaturas', 'itensAtendimento'] as $relacao) {
            if ($documento->relationLoaded($relacao)) {
                $documentoContextualizado->setRelation($relacao, $documento->getRelation($relacao));
            }
        }

        return $documentoContextualizado;
    }

    private function substituirValoresRenderizadosDoContexto(string $conteudo, ?\App\Models\Processo $processoOrigem, \App\Models\Processo $processoDestino): string
    {
        $estabelecimentoOrigem = $processoOrigem?->estabelecimento;
        $estabelecimentoDestino = $processoDestino->estabelecimento;

        if (!$estabelecimentoOrigem || !$estabelecimentoDestino || (int) $estabelecimentoOrigem->id === (int) $estabelecimentoDestino->id) {
            return $conteudo;
        }

        $pares = [];
        $adicionarPar = function ($origem, $destino) use (&$pares) {
            $origem = trim((string) $origem);
            $destino = trim((string) $destino);

            if ($origem === '' || $destino === '' || $origem === $destino) {
                return;
            }

            $variantes = [
                [$origem, $destino],
                [strtoupper($origem), strtoupper($destino)],
                [\Illuminate\Support\Str::ascii($origem), \Illuminate\Support\Str::ascii($destino)],
                [strtoupper(\Illuminate\Support\Str::ascii($origem)), strtoupper(\Illuminate\Support\Str::ascii($destino))],
            ];

            foreach ($variantes as [$de, $para]) {
                $de = trim((string) $de);
                $para = trim((string) $para);

                if ($de !== '' && $para !== '' && strlen($de) >= 3 && $de !== $para) {
                    $pares[$de] = $para;
                }
            }
        };

        $adicionarPar($processoOrigem?->numero_processo, $processoDestino->numero_processo);
        $adicionarPar($estabelecimentoOrigem->razao_social, $estabelecimentoDestino->razao_social);
        $adicionarPar($estabelecimentoOrigem->nome_fantasia, $estabelecimentoDestino->nome_fantasia);
        $adicionarPar($estabelecimentoOrigem->cnpj_formatado ?? $estabelecimentoOrigem->cnpj, $estabelecimentoDestino->cnpj_formatado ?? $estabelecimentoDestino->cnpj);
        $adicionarPar($estabelecimentoOrigem->cnpj, $estabelecimentoDestino->cnpj);
        $adicionarPar($estabelecimentoOrigem->cpf_formatado ?? $estabelecimentoOrigem->cpf, $estabelecimentoDestino->cpf_formatado ?? $estabelecimentoDestino->cpf);
        $adicionarPar($estabelecimentoOrigem->cep, $estabelecimentoDestino->cep);
        $adicionarPar($estabelecimentoOrigem->telefone_formatado ?? $estabelecimentoOrigem->telefone, $estabelecimentoDestino->telefone_formatado ?? $estabelecimentoDestino->telefone);
        $adicionarPar($estabelecimentoOrigem->telefone, $estabelecimentoDestino->telefone);
        $adicionarPar($estabelecimentoOrigem->email, $estabelecimentoDestino->email);
        $adicionarPar($estabelecimentoOrigem->cidade, $estabelecimentoDestino->cidade);
        $adicionarPar(
            trim(($estabelecimentoOrigem->cidade ?? '') . '/' . ($estabelecimentoOrigem->estado ?? ''), '/'),
            trim(($estabelecimentoDestino->cidade ?? '') . '/' . ($estabelecimentoDestino->estado ?? ''), '/')
        );
        $adicionarPar(
            trim(($estabelecimentoOrigem->cidade ?? '') . '-' . ($estabelecimentoOrigem->estado ?? ''), '-'),
            trim(($estabelecimentoDestino->cidade ?? '') . '-' . ($estabelecimentoDestino->estado ?? ''), '-')
        );
        $adicionarPar($estabelecimentoOrigem->municipioRelacionado?->nome, $estabelecimentoDestino->municipioRelacionado?->nome);
        $adicionarPar($estabelecimentoOrigem->municipio?->nome, $estabelecimentoDestino->municipio?->nome);
        $adicionarPar(
            trim(($estabelecimentoOrigem->endereco ?? '') . ', ' . ($estabelecimentoOrigem->numero ?? ''), ' ,'),
            trim(($estabelecimentoDestino->endereco ?? '') . ', ' . ($estabelecimentoDestino->numero ?? ''), ' ,')
        );
        $adicionarPar($estabelecimentoOrigem->endereco, $estabelecimentoDestino->endereco);

        uksort($pares, fn ($a, $b) => strlen($b) <=> strlen($a));

        return str_replace(array_keys($pares), array_values($pares), $conteudo);
    }

    /**
     * Upload de imagem do editor TinyMCE (evita embutir base64 no HTML).
     */
    public function uploadImagem(Request $request)
    {
        $request->validate([
            'file' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:10240',
        ], [
            'file.required' => 'Nenhuma imagem foi enviada.',
            'file.image' => 'O arquivo deve ser uma imagem.',
            'file.max' => 'A imagem não pode ter mais de 10 MB.',
        ]);

        $path = $request->file('file')->store('documentos/imagens/' . date('Y/m'), 'public');

        return response()->json([
            'location' => \Storage::disk('public')->url($path),
        ]);
    }

    /**
     * Exibe formulário de edição (apenas para rascunhos)
     */
    public function edit($id)
    {
        // Log para debug de redirecionamento
        \Log::info('DocumentoDigitalController@edit chamado', [
            'documento_id' => $id,
            'usuario_autenticado' => auth('interno')->check(),
            'usuario_id' => auth('interno')->id(),
            'url_atual' => request()->url(),
        ]);

        $documento = DocumentoDigital::findOrFail($id);
        $documento->podarVersoesAntigas(10);

        $documento->load([
            'tipoDocumento',
            'processo.estabelecimento',
            'assinaturas',
            'versoes' => function ($query) {
                $query->select('id', 'documento_digital_id', 'usuario_interno_id', 'versao', 'created_at')
                    ->orderByDesc('versao')
                    ->limit(10)
                    ->with(['usuarioInterno' => function ($q) {
                        $q->withTrashed()->select('id', 'nome');
                    }]);
            },
            'itensAtendimento',
        ]);

        $totalVersoes = $documento->versoes->count();

        // Permite editar se for rascunho OU se estiver aguardando assinatura mas ninguém assinou ainda
        if (!$documento->podeEditar()) {
            \Log::warning('Tentativa de editar documento que já possui assinaturas', [
                'documento_id' => $id,
                'status' => $documento->status
            ]);
            
            // Redirect específico ao invés de back() para evitar loops
            return redirect()->route('admin.documentos.show', $documento->id)
                ->with('error', 'Este documento já possui assinaturas e não pode mais ser editado.');
        }

        $tiposDocumento = TipoDocumento::ativo()->visivelParaUsuario()->orderBy('nome')->get();
        
        $usuarioLogado = auth('interno')->user();
        $usuariosInternos = UsuarioInterno::paraSelecaoAssinantes($usuarioLogado)->ordenado()->get();
        $processo = $documento->processo;

        $pastasProcesso = collect();
        if ($processo && !$documento->isLote()) {
            $pastasProcesso = $processo->pastas()
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get();
        }

        // Verifica se o usuário pode marcar documentos como sigiloso
        $niveisPermitidosSigiloso = json_decode(ConfiguracaoSistema::obter('niveis_permitidos_sigiloso', '["administrador","gestor_estadual","gestor_municipal"]'), true) ?? [];
        $nivelAcesso = $usuarioLogado->nivel_acesso?->value;
        $podeMarcarSigiloso = $usuarioLogado->isAdmin() || ($nivelAcesso && in_array($nivelAcesso, $niveisPermitidosSigiloso));

        return view('documentos.edit', compact('documento', 'tiposDocumento', 'usuariosInternos', 'processo', 'pastasProcesso', 'podeMarcarSigiloso', 'totalVersoes'));
    }

    /**
     * Atualiza documento (apenas rascunhos)
     */
    public function update(Request $request, $id)
    {
        $documento = DocumentoDigital::findOrFail($id);

        // Permite editar se for rascunho OU se estiver aguardando assinatura mas ninguém assinou ainda
        if (!$documento->podeEditar()) {
            return back()->with('error', 'Este documento já possui assinaturas e não pode mais ser editado.');
        }

        $request->validate([
            'tipo_documento_id' => 'required|exists:tipo_documentos,id',
            'conteudo' => 'required',
            'sigiloso' => 'boolean',
            'assinaturas' => 'required|array|min:1',
            'assinaturas.*' => 'exists:usuarios_internos,id',
            'pasta_id' => 'nullable|integer',
            'prazo_dias' => 'nullable|integer|min:1',
            'tipo_prazo' => 'nullable|in:corridos,uteis',
            'itens_atendimento' => 'nullable|array|max:100',
            'itens_atendimento.*.area' => 'nullable|string|max:255',
            'itens_atendimento.*.descricao' => 'nullable|string|max:2000',
            'itens_atendimento.*.embasamento_legal' => 'nullable|string|max:5000',
            'colaboradores_exigencias' => 'nullable|array|max:50',
            'colaboradores_exigencias.*.usuario_interno_id' => 'nullable|exists:usuarios_internos,id',
            'colaboradores_exigencias.*.area' => 'nullable|string|max:255',
            'colaboradores_exigencias.*.prazo_interno' => 'nullable|date',
        ]);

        $tipoDocumento = TipoDocumento::findOrFail($request->tipo_documento_id);
        $itensAtendimento = $this->normalizarItensAtendimento($request, $tipoDocumento);
        $colaboradoresExigencias = $this->normalizarColaboradoresExigencias($request, $tipoDocumento);

        $conteudoNormalizado = $this->preservarEspacamentoConteudoHtml(
            DocumentoDigital::externalizarImagensBase64($request->conteudo)
        );

        $pastaId = null;
        if ($request->has('pasta_id')) {
            if ($documento->isLote()) {
                throw ValidationException::withMessages([
                    'pasta_id' => 'Documento em lote não permite definição de pasta.',
                ]);
            }

            if ($request->filled('pasta_id')) {
                if (!$documento->processo_id) {
                    throw ValidationException::withMessages([
                        'pasta_id' => 'Este documento não está vinculado a um processo para definir pasta.',
                    ]);
                }

                $pastaValida = ProcessoPasta::where('id', (int) $request->pasta_id)
                    ->where('processo_id', $documento->processo_id)
                    ->exists();

                if (!$pastaValida) {
                    throw ValidationException::withMessages([
                        'pasta_id' => 'A pasta selecionada não pertence ao processo deste documento.',
                    ]);
                }

                $pastaId = (int) $request->pasta_id;
            }
        }

        if ($documento->status !== 'rascunho' && $request->acao !== 'finalizar') {
            return back()->withErrors([
                'acao' => 'Este documento já foi finalizado para assinatura. Ao editar, ele só pode ser salvo mantendo o fluxo de assinatura.',
            ])->withInput();
        }

        try {
            DB::beginTransaction();

            // Busca o tipo de documento para pegar prazo_notificacao
            $dadosAtualizacao = [
                'tipo_documento_id' => $request->tipo_documento_id,
                'conteudo' => $conteudoNormalizado,
                'sigiloso' => $request->sigiloso ?? false,
                'status' => $request->acao === 'finalizar' ? 'aguardando_assinatura' : 'rascunho',
                'prazo_notificacao' => $tipoDocumento->prazo_notificacao ?? false, // Herda do tipo de documento
            ];

            if ($request->has('pasta_id')) {
                $dadosAtualizacao['pasta_id'] = $pastaId;
            }

            // Atualiza prazo se informado
            if ($request->has('prazo_dias') && $request->prazo_dias) {
                $dadosAtualizacao['prazo_dias'] = (int) $request->prazo_dias;
                $dadosAtualizacao['tipo_prazo'] = $request->tipo_prazo ?? 'corridos';
            }

            $documento->update($dadosAtualizacao);
            $this->sincronizarItensAtendimento($documento, $itensAtendimento);
            $this->sincronizarColaboradoresExigencias($documento, $colaboradoresExigencias);

            // Atualiza assinaturas (delete em massa não dispara evento: limpa o robô de pendências aqui)
            \App\Support\AssistentePendencias::limpar($documento->assinaturas()->pluck('usuario_interno_id'));
            $documento->assinaturas()->delete();
            foreach ($request->assinaturas as $index => $usuarioId) {
                DocumentoAssinatura::create([
                    'documento_digital_id' => $documento->id,
                    'usuario_interno_id' => $usuarioId,
                    'ordem' => $index + 1,
                    'obrigatoria' => true,
                    'status' => 'pendente',
                ]);
            }

            // SEMPRE salva nova versão quando salvar como rascunho
            // Isso garante que cada salvamento seja registrado no histórico
            $documento->salvarVersao(
                Auth::guard('interno')->user()->id,
                $conteudoNormalizado,
                null
            );

            // Se finalizar, gera PDF e salva no processo
            // (para documentos em lote, o PDF é gerado na assinatura/fan-out)
            if ($request->acao === 'finalizar' && $documento->processo_id && !$documento->isLote()) {
                $this->gerarESalvarPDF($documento, $documento->processo_id);
            }

            DB::commit();

            // Redireciona de volta
            if ($documento->isLote()) {
                return redirect()->route('admin.documentos.show', $documento->id)
                    ->with('success', 'Documento em lote atualizado com sucesso!' .
                        ($request->acao === 'finalizar'
                            ? ' Aguardando assinaturas para distribuição aos ' . count($documento->processos_ids) . ' processos.'
                            : ''));
            }

            // Redireciona de volta para o processo
            if ($documento->processo_id) {
                $processo = \App\Models\Processo::find($documento->processo_id);
                return redirect()->route('admin.estabelecimentos.processos.show', [$processo->estabelecimento_id, $processo->id])
                    ->with('success', 'Documento atualizado com sucesso!' . ($request->acao === 'finalizar' ? ' PDF gerado e anexado ao processo.' : ''));
            }

            return redirect()->route('admin.documentos.show', $documento->id)
                ->with('success', 'Documento atualizado com sucesso!');

        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Erro ao atualizar documento: ' . $e->getMessage());
        }
    }

    /**
     * Limpa e valida a lista de providências exigidas pelo tipo de documento.
     */
    private function normalizarItensAtendimento(Request $request, TipoDocumento $tipoDocumento): array
    {
        if (!$tipoDocumento->exige_itens_atendimento) {
            return [];
        }

        $itens = collect($request->input('itens_atendimento', []))
            ->map(function ($item) {
                return [
                    'area' => trim((string) ($item['area'] ?? '')),
                    'descricao' => trim((string) ($item['descricao'] ?? '')),
                    'embasamento_legal' => trim((string) ($item['embasamento_legal'] ?? '')),
                ];
            })
            ->filter(fn ($item) => $item['descricao'] !== '' || $item['embasamento_legal'] !== '' || $item['area'] !== '')
            ->values();

        if ($itens->contains(fn ($item) => $item['descricao'] === '')) {
            throw ValidationException::withMessages([
                'itens_atendimento' => 'Informe a providência em todos os itens preenchidos.',
            ]);
        }

        if ($request->input('acao') === 'finalizar' && $itens->isEmpty()) {
            throw ValidationException::withMessages([
                'itens_atendimento' => 'Adicione pelo menos um item a ser atendido antes de finalizar o documento.',
            ]);
        }

        return $itens->all();
    }

    private function sincronizarItensAtendimento(DocumentoDigital $documento, array $itens): void
    {
        $documento->itensAtendimento()->delete();

        foreach ($itens as $indice => $item) {
            DocumentoItemAtendimento::create([
                'documento_digital_id' => $documento->id,
                'ordem' => $indice + 1,
                'area' => $item['area'] !== '' ? $item['area'] : null,
                'descricao' => $item['descricao'],
                'embasamento_legal' => $item['embasamento_legal'] !== '' ? $item['embasamento_legal'] : null,
            ]);
        }
    }

    private function normalizarColaboradoresExigencias(Request $request, TipoDocumento $tipoDocumento): array
    {
        if (!$tipoDocumento->exige_itens_atendimento) {
            return [];
        }

        return collect($request->input('colaboradores_exigencias', []))
            ->map(function ($item) {
                return [
                    'usuario_interno_id' => (int) ($item['usuario_interno_id'] ?? 0),
                    'area' => trim((string) ($item['area'] ?? '')),
                    'prazo_interno' => $item['prazo_interno'] ?? null,
                ];
            })
            ->filter(fn ($item) => $item['usuario_interno_id'] > 0 && $item['area'] !== '')
            ->unique(fn ($item) => $item['usuario_interno_id'] . '|' . mb_strtoupper($item['area']))
            ->values()
            ->all();
    }

    private function sincronizarColaboradoresExigencias(DocumentoDigital $documento, array $colaboradores): void
    {
        $existentes = $documento->exigenciaColaboradores()
            ->get()
            ->keyBy(fn ($colaborador) => $colaborador->usuario_interno_id . '|' . mb_strtoupper((string) $colaborador->area));

        $documento->exigenciaColaboradores()->delete();

        foreach ($colaboradores as $colaborador) {
            $chave = $colaborador['usuario_interno_id'] . '|' . mb_strtoupper($colaborador['area']);
            $existente = $existentes->get($chave);

            DocumentoExigenciaColaborador::create([
                'documento_digital_id' => $documento->id,
                'usuario_interno_id' => $colaborador['usuario_interno_id'],
                'atribuido_por' => Auth::guard('interno')->id(),
                'area' => mb_strtoupper($colaborador['area']),
                'prazo_interno' => $colaborador['prazo_interno'] ?: null,
                'status' => $existente?->status ?? 'pendente',
                'concluido_em' => $existente?->concluido_em,
            ]);
        }
    }

    /**
     * Preserva espaçamentos manuais em conteúdo HTML transformando sequências de espaços em NBSP.
     */
    private function preservarEspacamentoConteudoHtml(?string $conteudo): string
    {
        if ($conteudo === null || $conteudo === '') {
            return '';
        }

        $internalErrors = libxml_use_internal_errors(true);
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $wrapperId = 'documento-normalizado';
        $html = '<div id="' . $wrapperId . '">' . $conteudo . '</div>';
        $flags = LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;

        $carregado = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, $flags);
        $erros = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($internalErrors);

        if (!$carregado || !empty($erros)) {
            return DocumentoDigital::preservarLayoutTabelasComImagens($conteudo);
        }

        $xpath = new \DOMXPath($dom);
        $wrapper = $xpath->query('//*[@id="' . $wrapperId . '"]')->item(0);

        if (!$wrapper) {
            return DocumentoDigital::preservarLayoutTabelasComImagens($conteudo);
        }

        $nbsp = html_entity_decode('&nbsp;', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->normalizarEspacosEmNosTexto($wrapper, $nbsp);

        return DocumentoDigital::preservarLayoutTabelasComImagens($this->obterHtmlInterno($wrapper));
    }

    private function normalizarEspacosEmNosTexto(\DOMNode $node, string $nbsp): void
    {
        if (!$node->hasChildNodes()) {
            return;
        }

        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $nomePai = strtolower($child->parentNode?->nodeName ?? '');

                if (in_array($nomePai, ['pre', 'code', 'script', 'style', 'textarea'], true)) {
                    continue;
                }

                $texto = $child->nodeValue ?? '';
                if ($texto === '') {
                    continue;
                }

                $texto = str_replace("\t", str_repeat($nbsp, 4), $texto);
                $texto = preg_replace_callback('/ {2,}/u', static function (array $match) use ($nbsp) {
                    return str_repeat($nbsp, strlen($match[0]));
                }, $texto);

                if ($texto !== null) {
                    $child->nodeValue = $texto;
                }

                continue;
            }

            if ($child->nodeType === XML_ELEMENT_NODE) {
                $this->normalizarEspacosEmNosTexto($child, $nbsp);
            }
        }
    }

    private function obterHtmlInterno(\DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    /**
     * Gera PDF do documento para download com cabeçalho do estabelecimento
     */
    public function gerarPdf($id)
    {
        $documento = DocumentoDigital::with([
            'tipoDocumento',
            'processo.tipoProcesso',
            'processo.estabelecimento.responsaveisLegais',
            'processo.estabelecimento.responsaveisTecnicos',
            'processo.estabelecimento.municipioRelacionado',
        ])->findOrFail($id);

        $this->ajustarEscopoDocumentoAtividadeOs($documento);

        $processoContexto = $this->resolverProcessoContextoDocumento($documento);

        if ($documento->status === 'assinado' && $documento->isLote() && $processoContexto) {
            return $this->renderizarPdfAssinadoContextualizado($documento, $processoContexto, true);
        }

        // Se já tem arquivo final assinado salvo, baixa ele. Documentos ainda não assinados
        // são renderizados novamente para refletir ajustes nos itens de atendimento.
        if ($documento->status === 'assinado' && $documento->arquivo_pdf && \Storage::disk('public')->exists($documento->arquivo_pdf)) {
            return \Storage::disk('public')->download($documento->arquivo_pdf, $documento->numero_documento . '.pdf');
        }

        // Se o documento está assinado mas não tem PDF (falha na geração anterior), tenta regenerar
        if ($documento->status === 'assinado') {
            try {
                $assinaturaController = app(\App\Http\Controllers\AssinaturaDigitalController::class);
                $assinaturaController->gerarPdfAssinado($documento);
                
                $documento->refresh();
                
                if ($documento->arquivo_pdf && \Storage::disk('public')->exists($documento->arquivo_pdf)) {
                    return \Storage::disk('public')->download($documento->arquivo_pdf, $documento->numero_documento . '.pdf');
                }
            } catch (\Throwable $e) {
                \Log::error('Erro ao regenerar PDF assinado no download', [
                    'documento_id' => $documento->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        // Gera PDF com cabeçalho usando o template pdf-preview
        $processo = $processoContexto ?? $documento->processo;
        $estabelecimento = $processo ? $processo->estabelecimento : null;
        $usuarioLogado = \Auth::guard('interno')->user();
        $logomarca = $this->determinarLogomarca($processo, $usuarioLogado);
        $documentoParaPdf = $this->documentoComContextoProcesso($documento, $processoContexto);

        $pdf = Pdf::loadView('documentos.pdf-preview', [
            'documento' => $documentoParaPdf,
            'processo' => $processo,
            'estabelecimento' => $estabelecimento,
            'logomarca' => $logomarca,
        ])
            ->setPaper('a4')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10);
        
        return $pdf->download($documento->numero_documento . '.pdf');
    }

    /**
     * Gera PDF do documento para visualização (stream) com cabeçalho do estabelecimento
     */
    public function visualizarPdf($id)
    {
        $documento = DocumentoDigital::with([
            'tipoDocumento',
            'processo.tipoProcesso',
            'processo.estabelecimento.responsaveisLegais',
            'processo.estabelecimento.responsaveisTecnicos',
            'processo.estabelecimento.municipioRelacionado',
        ])->findOrFail($id);

        $this->ajustarEscopoDocumentoAtividadeOs($documento);

        $processoContexto = $this->resolverProcessoContextoDocumento($documento);

        if ($documento->status === 'assinado' && $documento->isLote() && $processoContexto) {
            return $this->renderizarPdfAssinadoContextualizado($documento, $processoContexto, false);
        }

        // Se já tem arquivo PDF final salvo (documento assinado), exibe ele. Documentos
        // pendentes são renderizados novamente para evitar preview desatualizado.
        if ($documento->status === 'assinado' && $documento->arquivo_pdf && \Storage::disk('public')->exists($documento->arquivo_pdf)) {
            return response()->file(\Storage::disk('public')->path($documento->arquivo_pdf), [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $documento->numero_documento . '.pdf"'
            ]);
        }

        // Se o documento está assinado mas não tem PDF (falha na geração anterior), tenta regenerar
        if ($documento->status === 'assinado') {
            try {
                $assinaturaController = app(\App\Http\Controllers\AssinaturaDigitalController::class);
                $nomeArquivo = $assinaturaController->gerarPdfAssinado($documento);
                
                // Recarrega o documento para pegar o arquivo_pdf atualizado
                $documento->refresh();
                
                if ($documento->arquivo_pdf && \Storage::disk('public')->exists($documento->arquivo_pdf)) {
                    return response()->file(\Storage::disk('public')->path($documento->arquivo_pdf), [
                        'Content-Type' => 'application/pdf',
                        'Content-Disposition' => 'inline; filename="' . $documento->numero_documento . '.pdf"'
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::error('Erro ao regenerar PDF assinado na visualização', [
                    'documento_id' => $documento->id,
                    'erro' => $e->getMessage(),
                ]);
            }
        }

        // Gera preview com cabeçalho usando o template pdf-preview
        $processo = $processoContexto ?? $documento->processo;
        $estabelecimento = $processo ? $processo->estabelecimento : null;
        $usuarioLogado = \Auth::guard('interno')->user();
        $logomarca = $this->determinarLogomarca($processo, $usuarioLogado);
        $documentoParaPdf = $this->documentoComContextoProcesso($documento, $processoContexto);

        $pdf = Pdf::loadView('documentos.pdf-preview', [
            'documento' => $documentoParaPdf,
            'processo' => $processo,
            'estabelecimento' => $estabelecimento,
            'logomarca' => $logomarca,
        ])
            ->setPaper('a4')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 10)
            ->setOption('margin-right', 10);
        
        return $pdf->stream($documento->numero_documento . '.pdf');
    }

    private function renderizarPdfAssinadoContextualizado(DocumentoDigital $documento, \App\Models\Processo $processoContexto, bool $download = false)
    {
        if (empty($documento->codigo_autenticidade)) {
            $documento->codigo_autenticidade = DocumentoDigital::gerarCodigoAutenticidade();
            $documento->save();
        }

        $documento->loadMissing([
            'assinaturas' => function ($query) {
                $query->where('status', 'assinado')->orderBy('ordem');
            },
            'assinaturas.usuarioInterno',
        ]);

        $estabelecimento = $processoContexto->estabelecimento;
        $usuarioLogado = \Auth::guard('interno')->user();
        $logomarca = $this->determinarLogomarca($processoContexto, $usuarioLogado);
        $documentoParaPdf = $this->documentoComContextoProcesso($documento, $processoContexto);

        $urlAutenticidade = route('verificar.autenticidade', ['codigo' => $documento->codigo_autenticidade]);
        $qrCode = new \Endroid\QrCode\QrCode($urlAutenticidade);
        $writer = new \Endroid\QrCode\Writer\PngWriter();
        $qrCodeBase64 = base64_encode($writer->write($qrCode)->getString());

        $pdf = Pdf::loadView('documentos.pdf-assinado', [
            'documento' => $documentoParaPdf,
            'estabelecimento' => $estabelecimento,
            'processo' => $processoContexto,
            'assinaturas' => $documento->assinaturas,
            'urlAutenticidade' => $urlAutenticidade,
            'codigoAutenticidade' => $documento->codigo_autenticidade,
            'qrCodeBase64' => $qrCodeBase64,
            'logomarca' => $logomarca,
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('margin-top', 10)
            ->setOption('margin-bottom', 10)
            ->setOption('margin-left', 15)
            ->setOption('margin-right', 15);

        return $download
            ? $pdf->download($documento->numero_documento . '.pdf')
            : $pdf->stream($documento->numero_documento . '.pdf');
    }

    /**
     * Exclui documento digital (requer senha de assinatura)
     */
    public function destroy(Request $request, $id)
    {
        try {
            $documento = DocumentoDigital::findOrFail($id);
            $usuario = Auth::guard('interno')->user();
            
            // Valida senha de assinatura
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

            // Não permite excluir documento que já está totalmente assinado/finalizado
            // EXCEÇÃO: Administradores podem excluir documentos assinados
            if ($documento->status === 'assinado' && $documento->todasAssinaturasCompletas() && !$usuario->isAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este documento já está totalmente assinado e não pode ser excluído.'
                ], 400);
            }
            
            // ✅ REGISTRAR EVENTO NO HISTÓRICO ANTES DE EXCLUIR
            if ($documento->processo_id) {
                $processo = \App\Models\Processo::find($documento->processo_id);
                if ($processo) {
                    $eraAssinado = $documento->status === 'assinado' && $documento->todasAssinaturasCompletas();
                    \App\Models\ProcessoEvento::create([
                        'processo_id' => $processo->id,
                        'usuario_interno_id' => $usuario->id,
                        'tipo_evento' => 'documento_digital_excluido',
                        'titulo' => $eraAssinado ? 'Documento Digital Assinado Excluído (Admin)' : 'Documento Digital Excluído',
                        'descricao' => 'Documento digital excluído: ' . ($documento->nome ?? $documento->tipoDocumento->nome ?? 'N/D') . ' - ' . $documento->numero_documento . ($eraAssinado ? ' [DOCUMENTO JÁ ASSINADO - Exclusão administrativa]' : ''),
                        'dados_adicionais' => [
                            'nome_arquivo' => $documento->numero_documento,
                            'tipo_documento' => $documento->tipoDocumento->nome ?? 'N/D',
                            'excluido_por' => $usuario->nome,
                            'documento_estava_assinado' => $eraAssinado,
                        ]
                    ]);
                }
            }

            // Remove arquivo PDF se existir
            if ($documento->arquivo_pdf && \Storage::disk('public')->exists($documento->arquivo_pdf)) {
                \Storage::disk('public')->delete($documento->arquivo_pdf);
            }
            
            // Remove assinaturas (delete em massa não dispara evento: limpa o robô de pendências aqui)
            \App\Support\AssistentePendencias::limpar($documento->assinaturas()->pluck('usuario_interno_id'));
            $documento->assinaturas()->delete();

            // Remove documento
            $documento->delete();
            
            return response()->json(['success' => true, 'message' => 'Documento excluído com sucesso!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao excluir documento: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Move documento para pasta
     */
    public function moverPasta(Request $request, $id)
    {
        try {
            $documento = DocumentoDigital::findOrFail($id);
            $documento->update(['pasta_id' => $request->pasta_id]);
            
            return response()->json(['success' => true, 'message' => 'Documento movido com sucesso!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao mover documento: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Renomeia documento
     */
    public function renomear(Request $request, $id)
    {
        try {
            $documento = DocumentoDigital::findOrFail($id);
            $documento->update(['nome' => $request->nome]);
            
            return response()->json(['success' => true, 'message' => 'Documento renomeado com sucesso!']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erro ao renomear documento: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Assina documento
     */
    public function assinar(Request $request, $id)
    {
        $documento = DocumentoDigital::findOrFail($id);
        $usuarioId = Auth::guard('interno')->user()->id;

        $assinatura = DocumentoAssinatura::where('documento_digital_id', $id)
            ->where('usuario_interno_id', $usuarioId)
            ->where('status', 'pendente')
            ->firstOrFail();

        $assinatura->update([
            'status' => 'assinado',
            'assinado_em' => now(),
            'hash_assinatura' => hash('sha256', $documento->id . $usuarioId . now()),
        ]);

        $this->concluirDocumentoSeAssinaturasCompletas($documento);

        return back()->with('success', 'Documento assinado com sucesso!' .
            ($documento->isLote() && $documento->status === 'assinado'
                ? ' O documento foi distribuído para todos os processos vinculados.'
                : ''));
    }

    /**
     * Distribui um documento em lote para todos os processos vinculados.
     * Cria uma cópia assinada para cada processo com substituição de variáveis e PDF.
     * Chamado internamente e também pelo AssinaturaDigitalController após todas as assinaturas.
     */
    public function executarDistribuicaoLote(DocumentoDigital $documentoOriginal)
    {
        $processosIds = $documentoOriginal->processos_ids ?? [];
        if (empty($processosIds)) {
            return;
        }

        // Nova abordagem: NÃO criar cópias por estabelecimento.
        // O documento original aparece em todos os processos via processos_ids.
        // Apenas gera o PDF do documento original com as assinaturas.
        $assinaturaController = app(\App\Http\Controllers\AssinaturaDigitalController::class);
        $assinaturaController->gerarPdfAssinado($documentoOriginal);

        // Registra evento em cada processo vinculado
        $processos = \App\Models\Processo::whereIn('id', $processosIds)->get();
        foreach ($processos as $processo) {
            \App\Models\ProcessoEvento::registrarDocumentoDigitalCriado($processo, $documentoOriginal);
        }

        \Log::info('Documento de lote finalizado (sem fan-out)', [
            'documento_id' => $documentoOriginal->id,
            'numero_documento' => $documentoOriginal->numero_documento,
            'processos_count' => $processos->count(),
            'processos_ids' => $processosIds,
        ]);
    }

    /**
     * Retorna o conteúdo de uma versão específica (carregamento sob demanda)
     */
    public function obterVersaoConteudo($documentoId, $versaoId)
    {
        $versao = DocumentoDigitalVersao::where('documento_digital_id', $documentoId)
            ->select('id', 'versao', 'conteudo')
            ->findOrFail($versaoId);

        return response()->json([
            'success' => true,
            'versao' => $versao->versao,
            'conteudo' => $versao->conteudo,
        ]);
    }

    /**
     * Restaura uma versão anterior do documento
     */
    public function restaurarVersao($documentoId, $versaoId)
    {
        try {
            $documento = DocumentoDigital::findOrFail($documentoId);
            
            // Permite restaurar versão se for rascunho OU aguardando assinatura sem assinaturas realizadas
            if (!$documento->podeEditar()) {
                return back()->with('error', 'Este documento já possui assinaturas e não pode ter versões restauradas.');
            }
            
            $versao = \App\Models\DocumentoDigitalVersao::where('documento_digital_id', $documentoId)
                ->findOrFail($versaoId);
            
            // Apenas restaura o conteúdo, SEM criar nova versão
            // A versão será criada quando o usuário salvar como rascunho
            $documento->update([
                'conteudo' => $versao->conteudo
            ]);
            
            return back()->with('success', 'Versão ' . $versao->versao . ' restaurada com sucesso! Salve como rascunho para registrar a alteração.');
            
        } catch (\Exception $e) {
            \Log::error('Erro ao restaurar versão: ' . $e->getMessage());
            return back()->with('error', 'Erro ao restaurar versão.');
        }
    }

    /**
     * Gera PDF e salva como arquivo no processo
     */
    private function gerarESalvarPDF($documento, $processoId)
    {
        try {
            // Verifica se já existe um PDF gerado para este documento
            if ($documento->arquivo_pdf) {
                \Log::info('PDF já existe para o documento: ' . $documento->numero_documento);
                return;
            }
            
            // Gera o PDF (imagens em storage convertidas para data-URI)
            $pdf = Pdf::loadHTML($documento->conteudoParaPdf())
                ->setPaper('a4')
                ->setOption('margin-top', 20)
                ->setOption('margin-bottom', 20)
                ->setOption('margin-left', 20)
                ->setOption('margin-right', 20);
            
            // Nome do arquivo
            $nomeArquivo = $documento->numero_documento . '.pdf';
            $nomeArquivoSalvo = time() . '_' . $nomeArquivo;
            
            // Salva o PDF no storage
            $caminho = 'processos/' . $processoId . '/' . $nomeArquivoSalvo;
            \Storage::disk('public')->put($caminho, $pdf->output());
            
            // Verifica se já existe um registro de ProcessoDocumento para este documento digital
            $documentoExistente = \App\Models\ProcessoDocumento::where('processo_id', $processoId)
                ->where('observacoes', 'Documento Digital: ' . $documento->numero_documento)
                ->first();
            
            if (!$documentoExistente) {
                // Cria registro no banco
                \App\Models\ProcessoDocumento::create([
                    'processo_id' => $processoId,
                    'usuario_id' => Auth::guard('interno')->user()->id,
                    'tipo_usuario' => 'interno',
                    'nome_arquivo' => $nomeArquivoSalvo,
                    'nome_original' => $nomeArquivo,
                    'caminho' => $caminho,
                    'extensao' => 'pdf',
                    'tamanho' => strlen($pdf->output()),
                    'tipo_documento' => 'documento_digital', // Marca como documento digital
                    'observacoes' => 'Documento Digital: ' . $documento->numero_documento,
                ]);
            }
            
            // Atualiza o documento digital com o caminho do PDF
            $documento->update(['arquivo_pdf' => $caminho]);
            
        } catch (\Exception $e) {
            \Log::error('Erro ao gerar PDF: ' . $e->getMessage());
        }
    }

    /**
     * Gerencia assinantes do documento (adicionar/remover)
     */
    public function gerenciarAssinantes(Request $request, $id)
    {
        try {
            $documento = DocumentoDigital::with('assinaturas')->findOrFail($id);

            // Verifica se alguma assinatura já foi feita
            $temAssinaturaFeita = $documento->assinaturas->where('status', 'assinado')->count() > 0;
            
            // Não permite alterar assinantes após qualquer assinatura feita
            if ($temAssinaturaFeita) {
                return redirect()
                    ->back()
                    ->with('error', 'Não é possível alterar assinantes após uma assinatura ter sido feita.');
            }

            // Verifica se o documento já foi assinado completamente
            if ($documento->status === 'assinado') {
                return redirect()
                    ->back()
                    ->with('error', 'Não é possível alterar assinantes de um documento já assinado completamente.');
            }

            $assinantesNovos = $request->input('assinantes', []);
            $assinantesAtuais = $documento->assinaturas->pluck('usuario_interno_id')->toArray();

            // Remove assinantes que não estão mais na lista
            $assinantesRemover = array_diff($assinantesAtuais, $assinantesNovos);
            if (!empty($assinantesRemover)) {
                \App\Support\AssistentePendencias::limpar($assinantesRemover);
                DocumentoAssinatura::where('documento_digital_id', $id)
                    ->whereIn('usuario_interno_id', $assinantesRemover)
                    ->delete();
            }

            // Adiciona novos assinantes
            $assinantesAdicionar = array_diff($assinantesNovos, $assinantesAtuais);
            $ordem = $documento->assinaturas()->max('ordem') ?? 0;
            
            foreach ($assinantesAdicionar as $usuarioId) {
                $ordem++;
                DocumentoAssinatura::create([
                    'documento_digital_id' => $id,
                    'usuario_interno_id' => $usuarioId,
                    'ordem' => $ordem,
                    'obrigatoria' => true,
                    'status' => 'pendente',
                ]);
            }

            // Reordena as assinaturas
            $assinaturas = DocumentoAssinatura::where('documento_digital_id', $id)
                ->orderBy('ordem')
                ->get();
            
            $novaOrdem = 1;
            foreach ($assinaturas as $assinatura) {
                $assinatura->ordem = $novaOrdem++;
                $assinatura->save();
            }

            return redirect()
                ->back()
                ->with('success', 'Assinantes atualizados com sucesso!');

        } catch (\Exception $e) {
            \Log::error('Erro ao gerenciar assinantes: ' . $e->getMessage());
            return redirect()
                ->back()
                ->with('error', 'Erro ao gerenciar assinantes: ' . $e->getMessage());
        }
    }

    /**
     * Remove um assinante específico
     */
    public function removerAssinante($id)
    {
        try {
            DB::beginTransaction();

            $assinatura = DocumentoAssinatura::with('documentoDigital.assinaturas')->findOrFail($id);
            $documento = $assinatura->documentoDigital;
            $usuario = Auth::guard('interno')->user();
            $usuarioEhAdmin = $usuario && $usuario->isAdmin();

            // Verifica se alguma assinatura já foi feita
            $temAssinaturaFeita = $documento->assinaturas->where('status', 'assinado')->count() > 0;
            
            // Não permite remover assinantes após qualquer assinatura feita para usuários não-admin
            if (!$usuarioEhAdmin && $temAssinaturaFeita) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível remover assinantes após uma assinatura ter sido feita.'
                ], 400);
            }

            // Verifica se o documento já foi assinado completamente
            if ($documento->status === 'assinado') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível remover assinantes de um documento já assinado completamente.'
                ], 400);
            }

            // Verifica se a assinatura já foi feita
            if ($assinatura->status === 'assinado') {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível remover um assinante que já assinou o documento.'
                ], 400);
            }

            $assinatura->delete();

            // Reordena as assinaturas restantes
            $assinaturas = DocumentoAssinatura::where('documento_digital_id', $documento->id)
                ->orderBy('ordem')
                ->get();
            
            $novaOrdem = 1;
            foreach ($assinaturas as $ass) {
                $ass->ordem = $novaOrdem++;
                $ass->save();
            }

            // Se, após remover pendência, todas assinaturas obrigatórias estiverem completas,
            // finaliza o documento e segue o fluxo normal de geração/distribuição de PDF.
            $documento->refresh();
            $documento->load('assinaturas');
            $statusAnterior = $documento->status;
            $this->concluirDocumentoSeAssinaturasCompletas($documento);

            DB::commit();

            $mensagem = 'Assinante removido com sucesso!';
            if ($statusAnterior !== 'assinado' && $documento->status === 'assinado') {
                $mensagem .= ' Como não há mais pendências obrigatórias, o documento foi finalizado e o PDF foi gerado no fluxo normal.';
            }

            return response()->json([
                'success' => true,
                'message' => $mensagem
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erro ao remover assinante: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover assinante: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Finaliza o documento quando todas assinaturas obrigatórias estiverem completas
     * e executa o fluxo padrão de PDF/distribuição.
     */
    private function concluirDocumentoSeAssinaturasCompletas(DocumentoDigital $documento): void
    {
        if ($documento->status === 'assinado') {
            return;
        }

        if (!$documento->todasAssinaturasCompletas()) {
            return;
        }

        $documento->update([
            'status' => 'assinado',
            'finalizado_em' => now(),
        ]);

        if (!$documento->codigo_autenticidade) {
            $documento->codigo_autenticidade = DocumentoDigital::gerarCodigoAutenticidade();
            $documento->save();
        }

        if ($documento->isLote()) {
            $this->executarDistribuicaoLote($documento);
            return;
        }

        if ($documento->processo_id) {
            $assinaturaController = app(\App\Http\Controllers\AssinaturaDigitalController::class);
            $assinaturaController->gerarPdfAssinado($documento);
        }
    }

    /**
     * Registra que o usuário está editando o documento
     * Usado para evitar conflitos de edição simultânea
     */
    public function registrarEdicao($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_edicao_{$id}";
        
        // Verifica se outro usuário está editando
        $edicaoAtual = Cache::get($cacheKey);
        
        if ($edicaoAtual && $edicaoAtual['usuario_id'] !== $usuario->id) {
            // Outro usuário está editando - verifica se ainda está ativo (menos de 30 segundos)
            $ultimaAtividade = \Carbon\Carbon::parse($edicaoAtual['ultima_atividade']);
            if ($ultimaAtividade->diffInSeconds(now()) < 30) {
                return response()->json([
                    'success' => false,
                    'editando' => true,
                    'usuario_nome' => $edicaoAtual['usuario_nome'],
                    'message' => 'Outro usuário está editando este documento.'
                ]);
            }
        }
        
        // Registra a edição do usuário atual com TTL de 35 segundos
        Cache::put($cacheKey, [
            'usuario_id' => $usuario->id,
            'usuario_nome' => $usuario->nome,
            'ultima_atividade' => now()->toISOString(),
        ], 35);
        
        return response()->json([
            'success' => true,
            'editando' => false,
            'message' => 'Edição registrada.'
        ]);
    }

    /**
     * Verifica se outro usuário está editando o documento
     */
    public function verificarEdicao($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_edicao_{$id}";
        
        $edicaoAtual = Cache::get($cacheKey);
        
        if (!$edicaoAtual) {
            return response()->json([
                'editando' => false,
                'usuario_nome' => null
            ]);
        }
        
        // Se é o próprio usuário, não está bloqueado
        if ($edicaoAtual['usuario_id'] === $usuario->id) {
            return response()->json([
                'editando' => false,
                'usuario_nome' => null
            ]);
        }
        
        // Verifica se a edição ainda está ativa (menos de 30 segundos)
        $ultimaAtividade = \Carbon\Carbon::parse($edicaoAtual['ultima_atividade']);
        if ($ultimaAtividade->diffInSeconds(now()) >= 30) {
            // Edição expirou
            return response()->json([
                'editando' => false,
                'usuario_nome' => null
            ]);
        }
        
        return response()->json([
            'editando' => true,
            'usuario_nome' => $edicaoAtual['usuario_nome'],
            'ultima_atividade' => $edicaoAtual['ultima_atividade']
        ]);
    }

    /**
     * Libera a edição do documento (quando o usuário sai ou salva)
     */
    public function liberarEdicao($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_edicao_{$id}";
        
        $edicaoAtual = Cache::get($cacheKey);
        
        // Só libera se for o próprio usuário
        if ($edicaoAtual && $edicaoAtual['usuario_id'] === $usuario->id) {
            Cache::forget($cacheKey);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Edição liberada.'
        ]);
    }

    /**
     * Inicia edição colaborativa do documento
     */
    public function iniciarEdicao($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_editores_{$id}";
        
        // Busca editores atuais
        $editores = Cache::get($cacheKey, []);
        
        // Remove editores inativos (mais de 30 segundos)
        $editores = array_filter($editores, function($editor) {
            $ultimaAtividade = \Carbon\Carbon::parse($editor['ultima_atividade']);
            return $ultimaAtividade->diffInSeconds(now()) < 30;
        });
        
        // Gera ID único para esta sessão de edição
        $edicaoId = uniqid('edicao_');
        
        // Adiciona ou atualiza o editor atual
        $editores[$usuario->id] = [
            'usuario_id' => $usuario->id,
            'nome' => $usuario->nome,
            'edicao_id' => $edicaoId,
            'iniciado_em' => now()->format('H:i'),
            'ultima_atividade' => now()->toISOString(),
        ];
        
        // Salva no cache com TTL de 60 segundos
        Cache::put($cacheKey, $editores, 60);
        
        // Busca outros editores (excluindo o atual)
        $outrosEditores = array_filter($editores, function($editor) use ($usuario) {
            return $editor['usuario_id'] !== $usuario->id;
        });
        
        return response()->json([
            'success' => true,
            'edicao_id' => $edicaoId,
            'outros_editores' => array_values($outrosEditores)
        ]);
    }

    /**
     * Salva automaticamente o conteúdo do documento
     */
    public function salvarAuto(Request $request, $id)
    {
        try {
            $documento = DocumentoDigital::findOrFail($id);
            $usuario = auth('interno')->user();
            
            // Permite salvar se for rascunho OU aguardando assinatura sem assinaturas realizadas
            if (!$documento->podeEditar()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este documento já possui assinaturas e não pode mais ser editado.'
                ], 400);
            }
            
            $conteudo = DocumentoDigital::externalizarImagensBase64($request->input('conteudo'));
            
            // Atualiza o documento
            $documento->update([
                'conteudo' => $conteudo,
                'ultimo_editor_id' => $usuario->id,
                'ultima_edicao_em' => now(),
            ]);
            
            // Incrementa versão interna (para controle de conflitos)
            $versao = Cache::increment("documento_versao_{$id}", 1) ?: 1;
            
            // Atualiza atividade do editor
            $cacheKey = "documento_editores_{$id}";
            $editores = Cache::get($cacheKey, []);
            
            if (isset($editores[$usuario->id])) {
                $editores[$usuario->id]['ultima_atividade'] = now()->toISOString();
                Cache::put($cacheKey, $editores, 60);
            }
            
            // Busca editores ativos
            $editoresAtivos = array_filter($editores, function($editor) use ($usuario) {
                $ultimaAtividade = \Carbon\Carbon::parse($editor['ultima_atividade']);
                return $ultimaAtividade->diffInSeconds(now()) < 30 && $editor['usuario_id'] !== $usuario->id;
            });
            
            return response()->json([
                'success' => true,
                'versao' => $versao,
                'editores_ativos' => array_values($editoresAtivos)
            ]);
            
        } catch (\Exception $e) {
            \Log::error('Erro ao salvar documento automaticamente: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Retorna lista de editores ativos do documento
     */
    public function editoresAtivos($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_editores_{$id}";
        
        $editores = Cache::get($cacheKey, []);
        
        // Remove editores inativos
        $editores = array_filter($editores, function($editor) {
            $ultimaAtividade = \Carbon\Carbon::parse($editor['ultima_atividade']);
            return $ultimaAtividade->diffInSeconds(now()) < 30;
        });
        
        // Atualiza o cache
        Cache::put($cacheKey, $editores, 60);
        
        return response()->json([
            'success' => true,
            'editores' => array_values($editores)
        ]);
    }

    /**
     * Obtém conteúdo atual do documento
     */
    public function obterConteudo($id)
    {
        try {
            $documento = DocumentoDigital::findOrFail($id);
            $versao = Cache::get("documento_versao_{$id}", 1);
            
            return response()->json([
                'success' => true,
                'conteudo' => $documento->conteudo,
                'versao' => $versao
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Documento não encontrado.'
            ], 404);
        }
    }

    /**
     * Finaliza a edição do documento (remove editor da lista)
     */
    public function finalizarEdicao($id)
    {
        $usuario = auth('interno')->user();
        $cacheKey = "documento_editores_{$id}";
        
        $editores = Cache::get($cacheKey, []);
        
        // Remove o usuário atual da lista de editores
        if (isset($editores[$usuario->id])) {
            unset($editores[$usuario->id]);
            Cache::put($cacheKey, $editores, 60);
        }
        
        // Também limpa o registro de edição simples
        $cacheKeySimples = "documento_edicao_{$id}";
        $edicaoAtual = Cache::get($cacheKeySimples);
        if ($edicaoAtual && $edicaoAtual['usuario_id'] === $usuario->id) {
            Cache::forget($cacheKeySimples);
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Edição finalizada.'
        ]);
    }

    /**
     * Substitui variáveis no conteúdo do documento
     * 
     * @param string $conteudo O conteúdo do documento com variáveis
     * @param mixed $estabelecimento O estabelecimento relacionado (pode ser null)
     * @param mixed $processo O processo relacionado (pode ser null)
     * @return string O conteúdo com as variáveis substituídas
     */
    public function substituirVariaveis($conteudo, $estabelecimento = null, $processo = null)
    {
        // Se não houver conteúdo, retorna vazio
        if (empty($conteudo)) {
            return $conteudo;
        }

        $variaveis = [
            // Data
            '{data_atual}' => now()->format('d/m/Y'),
            '{data_extenso}' => now()->translatedFormat('d \d\e F \d\e Y'),
            '{data_extenso_maiusculo}' => strtoupper(now()->translatedFormat('d \d\e F \d\e Y')),
            '{data_atual_extenso}' => now()->translatedFormat('d \d\e F \d\e Y'),
            '{ano_atual}' => now()->format('Y'),
        ];

        // Variáveis do estabelecimento
        if ($estabelecimento) {
            $estabelecimento->loadMissing([
                'responsaveisLegais',
                'responsaveisTecnicos',
                'municipioRelacionado',
            ]);

            $variaveis['{estabelecimento_nome}'] = $estabelecimento->nome_fantasia ?? $estabelecimento->razao_social ?? '';
            $variaveis['{estabelecimento_razao_social}'] = $estabelecimento->razao_social ?? '';
            $variaveis['{estabelecimento_cnpj}'] = $estabelecimento->cnpj_formatado ?? $estabelecimento->cnpj ?? '';
            $variaveis['{estabelecimento_cpf}'] = $estabelecimento->cpf_formatado ?? $estabelecimento->cpf ?? '';
            $variaveis['{estabelecimento_endereco}'] = trim(($estabelecimento->endereco ?? '') . ', ' . ($estabelecimento->numero ?? ''));
            $variaveis['{estabelecimento_bairro}'] = $estabelecimento->bairro ?? '';
            $variaveis['{estabelecimento_cidade}'] = $estabelecimento->cidade ?? '';
            $variaveis['{estabelecimento_estado}'] = $estabelecimento->estado ?? '';
            $variaveis['{estabelecimento_cep}'] = $estabelecimento->cep ?? '';
            $variaveis['{estabelecimento_telefone}'] = $estabelecimento->telefone_formatado ?? $estabelecimento->telefone ?? '';
            $variaveis['{estabelecimento_email}'] = $estabelecimento->email ?? '';
            $variaveis['{municipio}'] = $estabelecimento->cidade ?? $estabelecimento->municipioRelacionado?->nome ?? '';
            
            $responsavelLegal = $estabelecimento->responsaveisLegais?->first();
            $responsavelTecnico = $estabelecimento->responsaveisTecnicos?->first();

            $cpfResponsavel = fn ($responsavel) => $responsavel
                ? ($responsavel->cpf_formatado ?? $responsavel->cpf ?? 'Não cadastrado')
                : 'Não cadastrado';

            // Variáveis explícitas dos responsáveis atuais cadastrados no estabelecimento.
            $variaveis['{responsavel_legal_nome}'] = $responsavelLegal?->nome ?? 'Não cadastrado';
            $variaveis['{responsavel_legal_cpf}'] = $cpfResponsavel($responsavelLegal);
            $variaveis['{responsavel_legal_email}'] = $responsavelLegal?->email ?? 'Não cadastrado';
            $variaveis['{responsavel_legal_telefone}'] = $responsavelLegal?->telefone ?? 'Não cadastrado';

            $variaveis['{responsavel_tecnico_nome}'] = $responsavelTecnico?->nome ?? 'Não cadastrado';
            $variaveis['{responsavel_tecnico_cpf}'] = $cpfResponsavel($responsavelTecnico);
            $variaveis['{responsavel_tecnico_email}'] = $responsavelTecnico?->email ?? 'Não cadastrado';
            $variaveis['{responsavel_tecnico_telefone}'] = $responsavelTecnico?->telefone ?? 'Não cadastrado';
            $variaveis['{responsavel_tecnico_conselho}'] = $responsavelTecnico?->numero_conselho ?? 'Não cadastrado';

            // Compatibilidade com modelos antigos: estas variáveis continuam representando o responsável técnico.
            $variaveis['{responsavel_nome}'] = $variaveis['{responsavel_tecnico_nome}'];
            $variaveis['{responsavel_cpf}'] = $variaveis['{responsavel_tecnico_cpf}'];
            $variaveis['{responsavel_email}'] = $variaveis['{responsavel_tecnico_email}'];
            $variaveis['{responsavel_telefone}'] = $variaveis['{responsavel_tecnico_telefone}'];
            $variaveis['{responsavel_conselho}'] = $variaveis['{responsavel_tecnico_conselho}'];
            
            // Atividades do estabelecimento - busca todas as atividades disponíveis
            $atividadesTexto = $this->formatarAtividadesEstabelecimento($estabelecimento);
            $variaveis['{atividades}'] = $atividadesTexto;
        } else {
            // Valores padrão quando não há estabelecimento
            $variaveis['{estabelecimento_nome}'] = '';
            $variaveis['{estabelecimento_razao_social}'] = '';
            $variaveis['{estabelecimento_cnpj}'] = '';
            $variaveis['{estabelecimento_cpf}'] = '';
            $variaveis['{estabelecimento_endereco}'] = '';
            $variaveis['{estabelecimento_bairro}'] = '';
            $variaveis['{estabelecimento_cidade}'] = '';
            $variaveis['{estabelecimento_estado}'] = '';
            $variaveis['{estabelecimento_cep}'] = '';
            $variaveis['{estabelecimento_telefone}'] = '';
            $variaveis['{estabelecimento_email}'] = '';
            $variaveis['{municipio}'] = '';
            $variaveis['{responsavel_legal_nome}'] = 'Não cadastrado';
            $variaveis['{responsavel_legal_cpf}'] = 'Não cadastrado';
            $variaveis['{responsavel_legal_email}'] = 'Não cadastrado';
            $variaveis['{responsavel_legal_telefone}'] = 'Não cadastrado';
            $variaveis['{responsavel_tecnico_nome}'] = 'Não cadastrado';
            $variaveis['{responsavel_tecnico_cpf}'] = 'Não cadastrado';
            $variaveis['{responsavel_tecnico_email}'] = 'Não cadastrado';
            $variaveis['{responsavel_tecnico_telefone}'] = 'Não cadastrado';
            $variaveis['{responsavel_tecnico_conselho}'] = 'Não cadastrado';
            $variaveis['{responsavel_nome}'] = '';
            $variaveis['{responsavel_cpf}'] = '';
            $variaveis['{responsavel_email}'] = '';
            $variaveis['{responsavel_telefone}'] = '';
            $variaveis['{responsavel_conselho}'] = '';
            $variaveis['{atividades}'] = '';
        }

        // Variáveis do processo
        if ($processo) {
            $variaveis['{processo_numero}'] = $processo->numero_processo ?? '';
            $variaveis['{processo_tipo}'] = $processo->tipo ?? '';
            $variaveis['{processo_status}'] = $processo->status_formatado ?? $processo->status ?? '';
            $variaveis['{processo_data_criacao}'] = $processo->created_at?->format('d/m/Y') ?? '';
            $variaveis['{processo_data_criacao_extenso}'] = $processo->created_at?->translatedFormat('d \d\e F \d\e Y') ?? '';
        } else {
            $variaveis['{processo_numero}'] = '';
            $variaveis['{processo_tipo}'] = '';
            $variaveis['{processo_status}'] = '';
            $variaveis['{processo_data_criacao}'] = '';
            $variaveis['{processo_data_criacao_extenso}'] = '';
        }

        // Substitui todas as variáveis
        return str_replace(array_keys($variaveis), array_values($variaveis), $conteudo);
    }

    /**
     * Formata as atividades do estabelecimento para exibição no documento
     * Busca atividades exercidas ou, se não houver, usa CNAE principal e secundários
     * 
     * @param mixed $estabelecimento
     * @return string
     */
    private function formatarAtividadesEstabelecimento($estabelecimento)
    {
        if (!$estabelecimento) {
            return '';
        }

        $listaAtividades = [];

        // 1. Primeiro tenta usar atividades_exercidas (atividades selecionadas pelo usuário)
        // Filtra apenas atividades com código CNAE válido (numérico, ex: 86.40-2-02 ou 8640202)
        if ($estabelecimento->atividades_exercidas && is_array($estabelecimento->atividades_exercidas) && count($estabelecimento->atividades_exercidas) > 0) {
            foreach ($estabelecimento->atividades_exercidas as $atividade) {
                if (is_array($atividade)) {
                    $codigo = $atividade['codigo'] ?? '';
                    $descricao = $atividade['descricao'] ?? $atividade['nome'] ?? '';
                    $principal = isset($atividade['principal']) && $atividade['principal'];
                    
                    // Verifica se o código é um CNAE válido (deve conter apenas números e formatação)
                    // Ignora códigos como "PROJ_ARQ", "DOC_123", etc.
                    $codigoLimpo = preg_replace('/[^0-9]/', '', $codigo);
                    $isCodigoCnaeValido = !empty($codigoLimpo) && strlen($codigoLimpo) >= 5 && strlen($codigoLimpo) <= 7;
                    
                    // Só inclui se tiver código CNAE válido
                    if ($isCodigoCnaeValido && ($descricao || $codigo)) {
                        $texto = '<div style="margin-bottom: 10px; display: flex; align-items: baseline;">';
                        if ($codigo) {
                            // Formata o código CNAE (ex: 4711301 -> 47.11-3-01)
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
                } elseif (is_string($atividade) && !empty($atividade)) {
                    // Verifica se é um código CNAE válido
                    $codigoLimpo = preg_replace('/[^0-9]/', '', $atividade);
                    if (!empty($codigoLimpo) && strlen($codigoLimpo) >= 5) {
                        $listaAtividades[] = '<div style="margin-bottom: 10px;">' . $atividade . '</div>';
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
                    } elseif (is_string($cnae) && !empty($cnae)) {
                        $codigoFormatado = $this->formatarCodigoCnae($cnae);
                        $listaAtividades[] = "<div style=\"margin-bottom: 10px;\"><span style=\"font-weight: bold;\">{$codigoFormatado}</span></div>";
                    }
                }
            }
        }

        return implode("", $listaAtividades);
    }

    /**
     * Formata código CNAE no padrão XX.XX-X-XX
     * 
     * @param string $codigo
     * @return string
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
        
        // Retorna como está se não tiver 7 dígitos
        return $codigo;
    }

}
