<?php

namespace App\Http\Controllers;

use App\Models\Estabelecimento;
use App\Models\EstabelecimentoHistorico;
use App\Models\Pactuacao;
use App\Models\UsuarioExterno;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Http;

class EstabelecimentoController extends Controller
{
    private function filtrarEstabelecimentosPorEscopo($estabelecimentos, $usuario)
    {
        if (!$usuario || $usuario->isAdmin()) {
            return $estabelecimentos;
        }

        return $estabelecimentos->filter(function ($estabelecimento) use ($usuario) {
            if ($usuario->isEstadual()) {
                return $estabelecimento->possuiEscopoCompetencia('estadual');
            }

            if ($usuario->isMunicipal()) {
                return (int) $estabelecimento->municipio_id === (int) $usuario->municipio_id
                    && $estabelecimento->possuiEscopoCompetencia('municipal');
            }

            return false;
        })->values();
    }

    private function autorizarAcessoEstabelecimentoInterno(Estabelecimento $estabelecimento, string $acao): void
    {
        if (!auth('interno')->check()) {
            return;
        }

        $usuario = auth('interno')->user();

        if ($usuario->isAdmin()) {
            return;
        }

        if ($usuario->isEstadual()) {
            if (!$estabelecimento->possuiEscopoCompetencia('estadual')) {
                abort(403, "Acesso negado. Este estabelecimento não possui escopo estadual disponível e você não tem permissão para {$acao}.");
            }

            return;
        }

        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id || (int) $estabelecimento->municipio_id !== (int) $usuario->municipio_id) {
                abort(403, 'Acesso negado. Este estabelecimento pertence a outro município.');
            }

            if (!$estabelecimento->possuiEscopoCompetencia('municipal')) {
                abort(403, "Acesso negado. Este estabelecimento não possui escopo municipal disponível e você não tem permissão para {$acao}.");
            }
        }
    }

    private function contarEstabelecimentosPorEscopo($query, $usuario): int
    {
        $this->aplicarFiltroTerritorialEstabelecimentos($query, $usuario);

        return $this->filtrarEstabelecimentosPorEscopo($query->get(), $usuario)->count();
    }

    private function aplicarFiltroTerritorialEstabelecimentos($query, $usuario): void
    {
        if (!$usuario || $usuario->isAdmin()) {
            return;
        }

        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id) {
                $query->whereRaw('1=0');
                return;
            }

            $query->where('municipio_id', $usuario->municipio_id);

            $cepsFiltro = $usuario->getCepsFiltro();
            $bairrosFiltro = $usuario->getBairrosFiltro();
            if (!empty($cepsFiltro) || !empty($bairrosFiltro)) {
                $query->filtroTerritorial($cepsFiltro, $bairrosFiltro);
            }
        }
    }

    private function paginarColecao($items, int $perPage, Request $request, string $pageName = 'page'): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage($pageName);
        $collection = collect($items)->values();

        return new LengthAwarePaginator(
            $collection->forPage($page, $perPage),
            $collection->count(),
            $perPage,
            $page,
            [
                'path' => url($request->path()),
                'pageName' => $pageName,
                'query' => $request->except($pageName),
            ]
        );
    }

    /**
     * Busca CNAEs pactuados por código ou descrição (autocomplete para inclusão manual)
     */
    public function buscarCnaesPactuacao(Request $request)
    {
        if (!auth('interno')->check() || !auth('interno')->user()->isAdmin()) {
            abort(403, 'Apenas administradores podem buscar CNAEs manualmente.');
        }

        $query = trim((string) $request->input('q', ''));

        if (mb_strlen($query) < 3) {
            return response()->json([]);
        }

        $queryLimpo = preg_replace('/[^0-9]/', '', $query);

        $resultados = Pactuacao::where('ativo', true)
            ->where(function ($q) use ($query, $queryLimpo) {
                $q->where('cnae_descricao', 'ilike', '%' . $query . '%');

                if (!empty($queryLimpo)) {
                    $q->orWhereRaw("regexp_replace(cnae_codigo, '[^0-9]', '', 'g') like ?", ["%{$queryLimpo}%"]);
                }
            })
            ->select('cnae_codigo as codigo', 'cnae_descricao as descricao')
            ->distinct()
            ->orderBy('cnae_codigo')
            ->limit(30)
            ->get();

        return response()->json($resultados);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $usuarioInterno = auth('interno')->user();

        $query = Estabelecimento::query();

        if (auth('externo')->check()) {
            $query->doUsuario(auth('externo')->id());
        }

        if (auth('interno')->check()) {
            $query->paraUsuario($usuarioInterno);
            $query->aprovados();

            $this->aplicarFiltroTerritorialEstabelecimentos($query, $usuarioInterno);
        }

        // Filtro de município
        if ($request->filled('municipio')) {
            $query->porMunicipio($request->municipio);
        }

        // Filtro de busca
        if ($request->filled('search')) {
            $search = $request->search;
            $searchLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $search);
            
            $query->where(function ($q) use ($search, $searchLimpo) {
                $q->where('nome_fantasia', 'ilike', '%' . $search . '%')
                  ->orWhere('razao_social', 'ilike', '%' . $search . '%')
                  ->orWhere('cidade', 'ilike', '%' . $search . '%');
                
                if (!empty($searchLimpo)) {
                    $q->orWhere('cnpj', 'like', "%{$searchLimpo}%")
                      ->orWhere('cpf', 'like', "%{$searchLimpo}%");
                }
            });
        }

        // Filtro por Grupo de Risco (precisa ser em memória pois é calculado)
        $aplicarFiltroEscopo = auth('interno')->check() && $usuarioInterno && !$usuarioInterno->isAdmin();

        if ($request->filled('risco')) {
            $riscoFiltro = $request->risco;
            $estabelecimentosFiltrados = $query->with(['usuarioExterno', 'aprovadoPor', 'municipio', 'processos.tipoProcesso'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(fn ($e) => $e->getGrupoRisco() === $riscoFiltro);
            
            if ($aplicarFiltroEscopo) {
                $estabelecimentosFiltrados = $this->filtrarEstabelecimentosPorEscopo($estabelecimentosFiltrados, $usuarioInterno);
            }
            
            $estabelecimentos = $this->paginarColecao($estabelecimentosFiltrados->values(), 10, $request);
        } else {
            if ($aplicarFiltroEscopo) {
                // Precisa filtrar em memória por competência (depende de pactuação)
                $estabelecimentosFiltrados = $query->with(['usuarioExterno', 'aprovadoPor', 'municipio', 'processos.tipoProcesso'])
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                $estabelecimentosFiltrados = $this->filtrarEstabelecimentosPorEscopo($estabelecimentosFiltrados, $usuarioInterno);
                
                $estabelecimentos = $this->paginarColecao($estabelecimentosFiltrados->values(), 10, $request);
            } else {
                // Admin: paginação direto no banco
                $estabelecimentos = $query->with(['usuarioExterno', 'aprovadoPor', 'municipio'])
                    ->orderBy('created_at', 'desc')
                    ->paginate(10)
                    ->withQueryString();
            }
        }

        // Estatísticas
        if ($aplicarFiltroEscopo) {
            // Para usuários não-admin, conta baseado nos estabelecimentos filtrados
            $todosParaStats = Estabelecimento::query();
            if (auth('interno')->check()) {
                $todosParaStats->paraUsuario($usuarioInterno);
                $this->aplicarFiltroTerritorialEstabelecimentos($todosParaStats, $usuarioInterno);
            }
            $todosEstabs = $todosParaStats->with('processos.tipoProcesso')->get();
            $filtrados = $this->filtrarEstabelecimentosPorEscopo($todosEstabs, $usuarioInterno);
            
            $estatisticas = [
                'total' => $filtrados->where('status', 'aprovado')->count(),
                'pendentes' => $filtrados->where('status', 'pendente')->count(),
                'aprovados' => $filtrados->where('status', 'aprovado')->where('ativo', true)->count(),
                'rejeitados' => $filtrados->where('status', 'rejeitado')->count(),
                'desativados' => $filtrados->where('ativo', false)->count(),
            ];
        } else {
            $baseQuery = function() use ($usuarioInterno) {
                $q = Estabelecimento::query();
                if (auth('interno')->check()) {
                    $q->paraUsuario($usuarioInterno);
                    $this->aplicarFiltroTerritorialEstabelecimentos($q, $usuarioInterno);
                }
                return $q;
            };
            
            // Uma consulta para as contagens, preservando o mesmo escopo de acesso.
            $contagens = $baseQuery()
                ->selectRaw("status, ativo, COUNT(*) as quantidade")
                ->groupBy('status', 'ativo')
                ->get();
            $estatisticas = [
                'total' => $contagens->where('status', 'aprovado')->sum('quantidade'),
                'pendentes' => $contagens->where('status', 'pendente')->sum('quantidade'),
                'aprovados' => $contagens->where('status', 'aprovado')->where('ativo', true)->sum('quantidade'),
                'rejeitados' => $contagens->where('status', 'rejeitado')->sum('quantidade'),
                'desativados' => $contagens->where('ativo', false)->sum('quantidade'),
            ];
        }

        return view('estabelecimentos.index', compact('estabelecimentos', 'estatisticas'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        // Redireciona para escolha do tipo
        return redirect()->route('admin.estabelecimentos.create.juridica');
    }

    /**
     * Show the form for creating a new Pessoa Jurídica.
     */
    public function createJuridica()
    {
        return view('estabelecimentos.create-juridica');
    }

    /**
     * Show the form for creating a new Pessoa Física.
     */
    public function createFisica()
    {
        return view('estabelecimentos.create-fisica');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Debug temporário
        \Log::info('Dados recebidos:', $request->all());
        
        $rules = [
            'tipo_pessoa' => 'required|in:juridica,fisica',
            'tipo_setor' => 'required|in:publico,privado',
            'nome_fantasia' => 'required|string|max:255',
            'endereco' => 'required|string|max:255', // Este é o logradouro
            'numero' => 'required|string|max:20',
            'complemento' => 'nullable|string|max:100',
            'bairro' => 'required|string|max:100',
            'cidade' => 'required|string|max:100',
            'estado' => 'required|string|size:2',
            'cep' => 'required|string|size:8',
            'telefone' => 'required|string|max:15', // Agora é obrigatório
            'email' => 'required|email|max:255', // Agora é obrigatório
            // Campos da API
            'natureza_juridica' => 'nullable|string',
            'porte' => 'nullable|string',
            'situacao_cadastral' => 'nullable|string',
            'descricao_situacao_cadastral' => 'nullable|string',
            'data_situacao_cadastral' => 'nullable|date',
            'data_inicio_atividade' => 'nullable|date',
            'cnae_fiscal' => 'nullable|string',
            'cnae_fiscal_descricao' => 'nullable|string',
            'capital_social' => 'nullable|numeric',
            'logradouro' => 'nullable|string',
            'codigo_municipio_ibge' => 'nullable|string',
            'motivo_situacao_cadastral' => 'nullable|string',
            'descricao_motivo_situacao_cadastral' => 'nullable|string',
            'atividades_exercidas' => 'nullable|string', // JSON das atividades selecionadas
            'respostas_questionario' => 'nullable|string', // JSON das respostas dos questionários
            'respostas_questionario2' => 'nullable|string', // JSON das respostas da segunda pergunta
        ];

        // Validações específicas por tipo de pessoa
        if ($request->tipo_pessoa === 'juridica') {
            $rules['cnpj'] = 'required|string|size:14';
            $rules['razao_social'] = 'required|string|max:255';
            
            // Para estabelecimentos privados, CNPJ deve ser único
            // Para estabelecimentos públicos, CNPJ pode ser repetido
            if ($request->tipo_setor === 'privado') {
                $rules['cnpj'] .= '|unique:estabelecimentos,cnpj';
            }
        } else {
            $rules['cpf'] = 'required|string|size:11|unique:estabelecimentos,cpf';
            $rules['nome_completo'] = 'required|string|max:255';
            $rules['rg'] = 'required|string|max:20';
            $rules['orgao_emissor'] = 'required|string|max:20';
            $rules['data_inicio_funcionamento'] = 'required|date';
            // Para pessoa física, atividades_exercidas é obrigatório
            $rules['atividades_exercidas'] = 'required|string';
        }

        $validated = $request->validate($rules, [
            'atividades_exercidas.required' => 'Você deve adicionar pelo menos uma Atividade Econômica (CNAE).',
        ]);

        // Valida se há pelo menos uma atividade para pessoa física
        if ($request->tipo_pessoa === 'fisica') {
            $atividades = json_decode($request->atividades_exercidas, true);
            if (empty($atividades) || !is_array($atividades) || count($atividades) === 0) {
                return back()->withErrors([
                    'atividades_exercidas' => 'Você deve adicionar pelo menos uma Atividade Econômica (CNAE).'
                ])->withInput();
            }
        }

        // Processa campos JSON se existirem
        if ($request->filled('cnaes_secundarios')) {
            $validated['cnaes_secundarios'] = json_decode($request->cnaes_secundarios, true);
        }
        
        if ($request->filled('qsa')) {
            $validated['qsa'] = json_decode($request->qsa, true);
        }

        // Processa atividades exercidas selecionadas pelo usuário
        if ($request->filled('atividades_exercidas')) {
            $atividadesExercidas = json_decode($request->atividades_exercidas, true);
            \Log::info('Atividades recebidas:', ['atividades' => $atividadesExercidas]);
            $validated['atividades_exercidas'] = $atividadesExercidas;
        } else {
            \Log::warning('Nenhuma atividade recebida no request');
        }

        // Processa respostas dos questionários
        if ($request->filled('respostas_questionario')) {
            $respostasQuestionario = json_decode($request->respostas_questionario, true);
            \Log::info('Respostas questionário recebidas:', ['respostas' => $respostasQuestionario]);
            $validated['respostas_questionario'] = $respostasQuestionario;
        }

        // Processa respostas da segunda pergunta dos questionários
        if ($request->filled('respostas_questionario2')) {
            $respostasQuestionario2 = json_decode($request->respostas_questionario2, true);
            \Log::info('Respostas questionário 2 recebidas:', ['respostas2' => $respostasQuestionario2]);
            $validated['respostas_questionario2'] = $respostasQuestionario2;
        }

        // Define o usuário responsável e status inicial
        if (auth('interno')->check()) {
            // Para usuários internos (admin), o estabelecimento não precisa estar vinculado a um usuário externo
            $validated['usuario_externo_id'] = null;
            // Admin pode criar já aprovado
            $validated['status'] = $request->input('status', 'aprovado');
            if ($validated['status'] === 'aprovado') {
                $validated['aprovado_por'] = auth('interno')->id();
                $validated['aprovado_em'] = now();
            }
        } else {
            // Para usuários externos, vincula ao usuário logado e cria como pendente
            $validated['usuario_externo_id'] = auth('externo')->id();
            $validated['status'] = 'pendente';
        }

        // Define o município baseado na cidade
        $validated['municipio'] = $validated['cidade'];
        
        // Normaliza o município e obtém o ID
        $nomeMunicipio = $validated['cidade'];
        $codigoIbge = $validated['codigo_municipio_ibge'] ?? null;
        
        if ($nomeMunicipio) {
            // Remove " - TO" ou "/TO" do nome se existir
            $nomeMunicipio = preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $nomeMunicipio);
            
            $municipioId = \App\Helpers\MunicipioHelper::normalizarEObterIdPorNome($nomeMunicipio, $codigoIbge);
            if ($municipioId) {
                $validated['municipio_id'] = $municipioId;
                $validated['municipio'] = $nomeMunicipio;
            }
        }

        // VALIDAÇÃO: Usuários municipais só podem cadastrar estabelecimentos do seu município 
        if (auth('interno')->check()) {
            $usuario = auth('interno')->user();
            
            if ($usuario->isMunicipal()) {
                // Verifica se o usuário tem município vinculado
                if (!$usuario->municipio_id) {
                    return back()->withErrors([
                        'cidade' => 'Seu usuário não possui município vinculado. Entre em contato com o administrador.'
                    ])->withInput();
                }
                
                // Verifica se o município do estabelecimento é o mesmo do usuário
                if (isset($validated['municipio_id']) && $validated['municipio_id'] != $usuario->municipio_id) {
                    $municipioUsuario = $usuario->municipioRelacionado->nome ?? 'seu município';
                    return back()->withErrors([
                        'cidade' => "Você só pode cadastrar estabelecimentos do município de {$municipioUsuario}. O estabelecimento informado pertence a {$nomeMunicipio}."
                    ])->withInput();
                }
                
                // Se não conseguiu identificar o município do estabelecimento
                if (!isset($validated['municipio_id'])) {
                    return back()->withErrors([
                        'cidade' => 'Não foi possível identificar o município do estabelecimento. Verifique se a cidade está correta.'
                    ])->withInput();
                }
            }
        }

        // Remove formatação de campos numéricos
        if (isset($validated['cnpj'])) {
            $validated['cnpj'] = preg_replace('/[^0-9]/', '', $validated['cnpj']);
        }
        
        if (isset($validated['cpf'])) {
            $validated['cpf'] = preg_replace('/[^0-9]/', '', $validated['cpf']);
        }
        
        if (isset($validated['cep'])) {
            $validated['cep'] = preg_replace('/[^0-9]/', '', $validated['cep']);
        }
        
        // Mapeia data_inicio_funcionamento para data_inicio_atividade (pessoa física)
        if (isset($validated['data_inicio_funcionamento'])) {
            $validated['data_inicio_atividade'] = $validated['data_inicio_funcionamento'];
            unset($validated['data_inicio_funcionamento']);
        }

        // Validação de duplicidade para estabelecimentos públicos (cnpj + nome_fantasia)
        if ($request->tipo_setor === 'publico' && isset($validated['cnpj'])) {
            $duplicado = Estabelecimento::where('cnpj', $validated['cnpj'])
                ->where('nome_fantasia', $validated['nome_fantasia'])
                ->where('tipo_setor', 'publico')
                ->first();

            if ($duplicado) {
                return back()->withErrors([
                    'cnpj' => 'Já existe um estabelecimento público cadastrado com este CNPJ e Nome Fantasia (' . $validated['nome_fantasia'] . '). Verifique se o estabelecimento já não está cadastrado no sistema.'
                ])->withInput();
            }
        }

        try {
            $estabelecimento = Estabelecimento::create($validated);
        } catch (\Illuminate\Database\QueryException $e) {
            // Trata erro de violação de unicidade (código 23505 no PostgreSQL)
            if ($e->getCode() === '23505') {
                return back()->withErrors([
                    'cnpj' => 'Este estabelecimento já está cadastrado no sistema. Verifique o CNPJ/CPF e o Nome Fantasia informados.'
                ])->withInput();
            }
            throw $e;
        }

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'criado',
            null,
            $estabelecimento->status,
            'Estabelecimento cadastrado'
        );

        return redirect()
            ->route('admin.estabelecimentos.index')
            ->with('success', 'Estabelecimento cadastrado com sucesso!');
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $estabelecimento = Estabelecimento::with(['municipiosAtuacao', 'aprovadoPor', 'municipio'])->findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'acessá-lo');

        $competenciaEstadual = $estabelecimento->isCompetenciaEstadual();
        $statusAtivos = ['aberto', 'em_andamento', 'em_analise', 'parado'];
        $processosAtivos = $estabelecimento->processos()
            ->with(['tipoProcesso', 'usuario:id,nome', 'usuarioExterno:id,nome', 'responsavelAtual:id,nome'])
            ->withCount(['alertasPendentes', 'documentos'])
            ->whereIn('status', $statusAtivos)
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();
        $totalProcessosAtivos = $estabelecimento->processos()->whereIn('status', $statusAtivos)->count();
        $totalProcessos = $estabelecimento->processos()->count();
        
        // Verifica se o estabelecimento exige equipamentos de radiação
        $exigeEquipamentosRadiacao = \App\Models\AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentos($estabelecimento);
        $equipamentosRadiacao = [];
        $totalEquipamentosRadiacao = 0;
        
        if ($exigeEquipamentosRadiacao) {
            $equipamentosRadiacao = \App\Models\EquipamentoRadiacao::where('estabelecimento_id', $estabelecimento->id)
                ->orderBy('tipo_equipamento')
                ->get();
            $totalEquipamentosRadiacao = $equipamentosRadiacao->count();
        }

        // Documentos obrigatórios disponíveis para o modal de aprovação (mesma lógica de /pendentes)
        $tiposDocumentoDisponiveis = collect();
        $documentosPermitidosEstabelecimento = [];
        if ($estabelecimento->status === 'pendente' && $estabelecimento->usaDocumentosManuais()) {
            $tiposDocumentoDisponiveis = \App\Models\TipoDocumentoObrigatorio::where('ativo', true)
                ->ordenado()
                ->get(['id', 'nome', 'descricao']);

            // Array vazio = sem restrição (mostra todos)
            $documentosPermitidosEstabelecimento = $this->idsDocumentosListasMunicipais($estabelecimento->municipio_id) ?? [];
        }

        return view('estabelecimentos.show', compact(
            'estabelecimento',
            'competenciaEstadual',
            'processosAtivos',
            'totalProcessosAtivos',
            'totalProcessos',
            'exigeEquipamentosRadiacao',
            'equipamentosRadiacao',
            'totalEquipamentosRadiacao',
            'tiposDocumentoDisponiveis',
            'documentosPermitidosEstabelecimento'
        ));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'editá-lo');
        
        // Redireciona para view específica baseado no tipo de pessoa
        if ($estabelecimento->tipo_pessoa === 'fisica') {
            return view('estabelecimentos.edit-fisica', compact('estabelecimento'));
        }
        
        return view('estabelecimentos.edit', compact('estabelecimento'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'atualizá-lo');
        
        $rules = [
            'tipo_setor' => 'required|in:publico,privado',
            'nome_fantasia' => 'required|string|max:255',
            'endereco' => 'required|string|max:255',
            'numero' => 'required|string|max:20',
            'complemento' => 'nullable|string|max:100',
            'bairro' => 'required|string|max:100',
            'cidade' => 'required|string|max:100',
            'estado' => 'required|string|size:2',
            'cep' => 'required|string',
            'telefone' => 'nullable|string|max:20',
            'telefone2' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
            'codigo_municipio_ibge' => 'nullable|string',
        ];

        // Adicionar regras específicas baseado no tipo de pessoa
        if ($estabelecimento->tipo_pessoa === 'juridica') {
            $rules['razao_social'] = 'required|string|max:255';
            $rules['cnpj'] = 'required|string';
        } else {
            $rules['nome_completo'] = 'required|string|max:255';
            $rules['cpf'] = 'required|string';
        }

        $validated = $request->validate($rules);

        // Limpar formatação de documentos e CEP
        if (isset($validated['cnpj'])) {
            $validated['cnpj'] = preg_replace('/[^0-9]/', '', $validated['cnpj']);
        }
        
        if (isset($validated['cpf'])) {
            $validated['cpf'] = preg_replace('/[^0-9]/', '', $validated['cpf']);
        }
        
        if (isset($validated['cep'])) {
            $validated['cep'] = preg_replace('/[^0-9]/', '', $validated['cep']);
        }

        // Atualiza o município baseado na cidade e código IBGE
        $nomeMunicipio = $validated['cidade'];
        $codigoIbge = $validated['codigo_municipio_ibge'] ?? null;
        
        if ($nomeMunicipio) {
            // Remove " - TO" ou "/TO" do nome se existir
            $nomeMunicipio = preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $nomeMunicipio);
            
            $municipioId = \App\Helpers\MunicipioHelper::normalizarEObterIdPorNome($nomeMunicipio, $codigoIbge);
            if ($municipioId) {
                $validated['municipio_id'] = $municipioId;
                $validated['municipio'] = $nomeMunicipio;
            }
        }

        // VALIDAÇÃO: Usuários municipais só podem atualizar para seu próprio município
        if (auth('interno')->check()) {
            $usuario = auth('interno')->user();
            
            if ($usuario->isMunicipal()) {
                // Verifica se o município do estabelecimento é o mesmo do usuário
                if (isset($validated['municipio_id']) && $validated['municipio_id'] != $usuario->municipio_id) {
                    $municipioUsuario = $usuario->municipioRelacionado->nome ?? 'seu município';
                    return back()->withErrors([
                        'cidade' => "Você só pode atualizar estabelecimentos para o município de {$municipioUsuario}. O endereço informado pertence a {$nomeMunicipio}."
                    ])->withInput();
                }
            }
        }

        $estabelecimento->update($validated);

        // Garante que o timestamp de atualização reflita a última edição
        $estabelecimento->touch();

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Estabelecimento atualizado com sucesso!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $nome = '';

            DB::transaction(function () use ($id, &$nome) {
                $estabelecimento = Estabelecimento::with(['responsaveis', 'usuariosVinculados', 'processos'])->findOrFail($id);

                $nome = $estabelecimento->nome_fantasia
                    ?? $estabelecimento->razao_social
                    ?? $estabelecimento->nome_completo
                    ?? 'Estabelecimento';

                // Desvincula responsáveis (não exclui os responsáveis, apenas o vínculo pivot)
                $estabelecimento->responsaveis()->detach();

                // Desvincula usuários externos (não exclui os usuários, apenas o vínculo pivot)
                $estabelecimento->usuariosVinculados()->detach();

                // Remove equipamentos de radiação
                DB::table('equipamentos_radiacao')->where('estabelecimento_id', $estabelecimento->id)->delete();

                // Remove histórico do estabelecimento
                DB::table('estabelecimento_historicos')->where('estabelecimento_id', $estabelecimento->id)->delete();

                // Remove processos e todas as suas dependências
                $processoIds = $estabelecimento->processos->pluck('id')->toArray();

                if (!empty($processoIds)) {
                    // 1. Remove ordens de serviço vinculadas aos processos
                    $osIds = DB::table('ordens_servico')->whereIn('processo_id', $processoIds)->pluck('id')->toArray();
                    if (!empty($osIds)) {
                        // Remove pivot de estabelecimentos das OSs
                        DB::table('ordem_servico_estabelecimentos')->whereIn('ordem_servico_id', $osIds)->delete();
                        // Remove documentos digitais das OSs e suas dependências
                        $docDigitalOsIds = DB::table('documentos_digitais')->whereIn('os_id', $osIds)->pluck('id')->toArray();
                        if (!empty($docDigitalOsIds)) {
                            DB::table('documento_assinaturas')->whereIn('documento_digital_id', $docDigitalOsIds)->delete();
                            DB::table('documento_respostas')->whereIn('documento_digital_id', $docDigitalOsIds)->delete();
                            DB::table('documento_visualizacoes')->whereIn('documento_digital_id', $docDigitalOsIds)->delete();
                            DB::table('documentos_digitais')->whereIn('id', $docDigitalOsIds)->delete();
                        }
                        // Remove arquivos vinculados às OSs
                        DB::table('processo_documentos')->whereIn('os_id', $osIds)->delete();
                        // Remove as OSs fisicamente
                        DB::table('ordens_servico')->whereIn('id', $osIds)->delete();
                    }

                    // 2. Remove documentos digitais dos processos e suas dependências
                    $docDigitalIds = DB::table('documentos_digitais')->whereIn('processo_id', $processoIds)->pluck('id')->toArray();
                    if (!empty($docDigitalIds)) {
                        DB::table('documento_assinaturas')->whereIn('documento_digital_id', $docDigitalIds)->delete();
                        DB::table('documento_respostas')->whereIn('documento_digital_id', $docDigitalIds)->delete();
                        DB::table('documento_visualizacoes')->whereIn('documento_digital_id', $docDigitalIds)->delete();
                        DB::table('whatsapp_mensagens')->whereIn('documento_digital_id', $docDigitalIds)->delete();
                        DB::table('documentos_digitais')->whereIn('id', $docDigitalIds)->delete();
                    }

                    // 3. Remove documentos do processo (uploads)
                    DB::table('processo_documentos')->whereIn('processo_id', $processoIds)->delete();

                    // 4. Remove pastas do processo
                    DB::table('processo_pastas')->whereIn('processo_id', $processoIds)->delete();

                    // 5. Remove alertas
                    DB::table('processo_alertas')->whereIn('processo_id', $processoIds)->delete();

                    // 6. Remove acompanhamentos
                    DB::table('processo_acompanhamentos')->whereIn('processo_id', $processoIds)->delete();

                    // 7. Remove eventos/histórico
                    DB::table('processo_eventos')->whereIn('processo_id', $processoIds)->delete();

                    // 8. Remove designações
                    DB::table('processo_designacoes')->whereIn('processo_id', $processoIds)->delete();

                    // 9. Remove unidades vinculadas (pivot)
                    DB::table('processo_unidades')->whereIn('processo_id', $processoIds)->delete();

                    // 10. Remove os processos fisicamente
                    DB::table('processos')->whereIn('id', $processoIds)->delete();
                }

                // Remove OSs vinculadas diretamente ao estabelecimento (sem processo)
                $osEstabIds = DB::table('ordens_servico')->where('estabelecimento_id', $estabelecimento->id)->pluck('id')->toArray();
                if (!empty($osEstabIds)) {
                    DB::table('ordem_servico_estabelecimentos')->whereIn('ordem_servico_id', $osEstabIds)->delete();
                    $docDigitalOsIds2 = DB::table('documentos_digitais')->whereIn('os_id', $osEstabIds)->pluck('id')->toArray();
                    if (!empty($docDigitalOsIds2)) {
                        DB::table('documento_assinaturas')->whereIn('documento_digital_id', $docDigitalOsIds2)->delete();
                        DB::table('documento_respostas')->whereIn('documento_digital_id', $docDigitalOsIds2)->delete();
                        DB::table('documento_visualizacoes')->whereIn('documento_digital_id', $docDigitalOsIds2)->delete();
                        DB::table('documentos_digitais')->whereIn('id', $docDigitalOsIds2)->delete();
                    }
                    DB::table('processo_documentos')->whereIn('os_id', $osEstabIds)->delete();
                    DB::table('ordens_servico')->whereIn('id', $osEstabIds)->delete();
                }

                // Remove pivot de OSs que referenciam este estabelecimento
                DB::table('ordem_servico_estabelecimentos')->where('estabelecimento_id', $estabelecimento->id)->delete();

                // Remove mensagens whatsapp vinculadas ao estabelecimento
                DB::table('whatsapp_mensagens')->where('estabelecimento_id', $estabelecimento->id)->delete();

                // Remove o estabelecimento fisicamente
                DB::table('estabelecimentos')->where('id', $estabelecimento->id)->delete();
            });

            return redirect()
                ->route('admin.estabelecimentos.index')
                ->with('success', "Estabelecimento '{$nome}' e todos os seus dados foram excluídos com sucesso!");

        } catch (\Exception $e) {
            \Log::error('Erro ao excluir estabelecimento', [
                'id' => $id,
                'erro' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()
                ->back()
                ->with('error', 'Erro ao excluir estabelecimento: ' . $e->getMessage());
        }
    }

    /**
     * Atualizar dados do estabelecimento pela API da Receita Federal
     */
    public function atualizarPelaApi(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'atualizar pela API');

        if (!$estabelecimento->cnpj) {
            return response()->json(['success' => false, 'message' => 'Estabelecimento sem CNPJ.'], 422);
        }

        try {
            $service = new \App\Services\CnpjService();
            $dados = $service->consultarCnpj($estabelecimento->cnpj);

            if (!$dados) {
                return response()->json(['success' => false, 'message' => 'CNPJ não encontrado na API.'], 404);
            }

            $alteracoes = [];

            // Campos para comparar e atualizar
            $camposMap = [
                'razao_social' => 'razao_social',
                'nome_fantasia' => 'nome_fantasia',
                'natureza_juridica' => 'natureza_juridica',
                'porte' => 'porte',
                'cep' => 'cep',
                'logradouro' => 'endereco',
                'numero' => 'numero',
                'complemento' => 'complemento',
                'bairro' => 'bairro',
                'cidade' => 'cidade',
                'estado' => 'estado',
                'telefone' => 'telefone',
                'email' => 'email',
                'cnae_fiscal' => 'cnae_fiscal',
                'cnae_fiscal_descricao' => 'cnae_fiscal_descricao',
                'situacao_cadastral' => 'situacao_cadastral',
            ];

            foreach ($camposMap as $campoApi => $campoBanco) {
                $valorApi = $dados[$campoApi] ?? null;
                $valorAtual = $estabelecimento->$campoBanco;
                if ($valorApi && $valorApi != $valorAtual) {
                    $alteracoes[] = "{$campoBanco}: \"{$valorAtual}\" → \"{$valorApi}\"";
                    $estabelecimento->$campoBanco = $valorApi;
                }
            }

            // Atualiza CNAEs secundários
            $cnaesApi = $dados['cnaes_secundarios'] ?? [];
            $cnaesAtuais = $estabelecimento->cnaes_secundarios ?? [];
            
            $codigosAtuais = collect($cnaesAtuais)->pluck('codigo')->map(fn($c) => preg_replace('/[^0-9]/', '', $c))->filter()->sort()->values()->toArray();
            $codigosApi = collect($cnaesApi)->pluck('codigo')->map(fn($c) => preg_replace('/[^0-9]/', '', $c))->filter()->sort()->values()->toArray();

            if ($codigosAtuais != $codigosApi) {
                $novos = array_diff($codigosApi, $codigosAtuais);
                $removidos = array_diff($codigosAtuais, $codigosApi);
                if (!empty($novos)) $alteracoes[] = "CNAEs Novos na Receita: " . implode(', ', $novos);
                if (!empty($removidos)) $alteracoes[] = "CNAEs Removidos da Receita: " . implode(', ', $removidos);
                $estabelecimento->cnaes_secundarios = $cnaesApi;
            }

            // Compara atividades exercidas (marcadas) com atividades da Receita
            $avisos = [];
            $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
            $codigosExercidos = collect($atividadesExercidas)->map(function($a) {
                $codigo = is_array($a) ? ($a['codigo'] ?? null) : $a;
                return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
            })->filter()->values()->toArray();

            // Todos os CNAEs da Receita (principal + secundários)
            $cnaePrincipalApi = $dados['cnae_fiscal'] ? preg_replace('/[^0-9]/', '', $dados['cnae_fiscal']) : null;
            $todosCodigosReceita = $codigosApi;
            if ($cnaePrincipalApi) {
                $todosCodigosReceita = array_unique(array_merge([$cnaePrincipalApi], $codigosApi));
            }

            $exercidasNaoNaReceita = array_diff($codigosExercidos, $todosCodigosReceita);
            $receitaNaoExercidas = array_diff($todosCodigosReceita, $codigosExercidos);

            if (!empty($exercidasNaoNaReceita)) {
                $avisos[] = "Atividades marcadas que NÃO estão na Receita: " . implode(', ', $exercidasNaoNaReceita);
            }
            if (!empty($receitaNaoExercidas)) {
                $avisos[] = "Atividades da Receita NÃO marcadas como exercidas: " . implode(', ', $receitaNaoExercidas);
            }

            if (!empty($alteracoes)) {
                $estabelecimento->save();
            }

            return response()->json([
                'success' => true,
                'alteracoes' => $alteracoes,
                'avisos' => $avisos,
                'total_alteracoes' => count($alteracoes),
                'api_source' => $dados['api_source'] ?? 'desconhecida',
            ]);

        } catch (\Exception $e) {
            \Log::error('Erro ao atualizar estabelecimento pela API', ['id' => $id, 'erro' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Altera manualmente a competência do estabelecimento (decisão administrativa/judicial)
     */
    public function alterarCompetencia(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        
        $request->validate([
            'competencia_manual' => 'required|in:estadual,municipal,automatica',
            'motivo_alteracao_competencia' => 'required|string|min:10|max:1000',
        ], [
            'competencia_manual.required' => 'Selecione a nova competência',
            'motivo_alteracao_competencia.required' => 'O motivo da alteração é obrigatório',
            'motivo_alteracao_competencia.min' => 'O motivo deve ter no mínimo 10 caracteres',
            'motivo_alteracao_competencia.max' => 'O motivo deve ter no máximo 1000 caracteres',
        ]);
        
        // Se escolheu "automatica", remove o override manual
        if ($request->competencia_manual === 'automatica') {
            $estabelecimento->update([
                'competencia_manual' => null,
                'motivo_alteracao_competencia' => $request->motivo_alteracao_competencia,
                'alterado_por' => auth('interno')->id(),
                'alterado_em' => now(),
            ]);
            
            $competenciaFinal = $estabelecimento->isCompetenciaEstadual() ? 'ESTADUAL' : 'MUNICIPAL';
            
            return redirect()
                ->route('admin.estabelecimentos.show', $estabelecimento->id)
                ->with('success', "Competência voltou a seguir as regras de pactuação automática! O estabelecimento agora é de competência {$competenciaFinal}.");
        }
        
        // Caso contrário, define o override manual
        $estabelecimento->update([
            'competencia_manual' => $request->competencia_manual,
            'motivo_alteracao_competencia' => $request->motivo_alteracao_competencia,
            'alterado_por' => auth('interno')->id(),
            'alterado_em' => now(),
        ]);
        
        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Competência alterada com sucesso! O estabelecimento agora é de competência ' . strtoupper($request->competencia_manual) . '.');
    }

    /**
     * Show the form for editing activities.
     */
    public function editAtividades(string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        // Carrega respostas dos questionários com metadados da pergunta (por CNAE)
        $normalizarCnae = fn($cnae) => preg_replace('/[^0-9]/', '', (string) $cnae);

        $respostasQuestionario = is_array($estabelecimento->respostas_questionario) ? $estabelecimento->respostas_questionario : [];
        $respostasQuestionario2 = is_array($estabelecimento->respostas_questionario2) ? $estabelecimento->respostas_questionario2 : [];

        $respostasQuestionarioNorm = [];
        foreach ($respostasQuestionario as $cnae => $resposta) {
            $codigo = $normalizarCnae($cnae);
            if ($codigo !== '') {
                $respostasQuestionarioNorm[$codigo] = $resposta;
            }
        }

        $respostasQuestionario2Norm = [];
        foreach ($respostasQuestionario2 as $cnae => $resposta) {
            $codigo = $normalizarCnae($cnae);
            if ($codigo !== '') {
                $respostasQuestionario2Norm[$codigo] = $resposta;
            }
        }

        $cnaesQuestionarios = collect(array_keys($respostasQuestionarioNorm))
            ->merge(array_keys($respostasQuestionario2Norm))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $pactuacoesQuestionario = empty($cnaesQuestionarios)
            ? collect()
            : Pactuacao::whereIn('cnae_codigo', $cnaesQuestionarios)
                ->where('requer_questionario', true)
                ->where('ativo', true)
                ->get()
                ->keyBy('cnae_codigo');

        $questionariosRespondidos = collect($cnaesQuestionarios)->map(function ($codigo) use ($pactuacoesQuestionario, $respostasQuestionarioNorm, $respostasQuestionario2Norm) {
            $pactuacao = $pactuacoesQuestionario->get($codigo);

            return [
                'cnae' => $codigo,
                'descricao' => $pactuacao->cnae_descricao ?? null,
                'pergunta' => $pactuacao->pergunta ?? null,
                'resposta' => $respostasQuestionarioNorm[$codigo] ?? null,
                'pergunta2' => $pactuacao->pergunta2 ?? null,
                'resposta2' => $respostasQuestionario2Norm[$codigo] ?? null,
            ];
        });
        
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'editar suas atividades');
        
        // Para pessoa física, usa view específica com API IBGE
        if ($estabelecimento->tipo_pessoa === 'fisica') {
            return view('estabelecimentos.atividades-fisica', compact('estabelecimento'));
        }
        
        // Buscar atividades da API ReceitaWS se for pessoa jurídica
        $atividadesApi = [];
        if ($estabelecimento->tipo_pessoa === 'juridica' && $estabelecimento->cnpj) {
            try {
                $cnpj = preg_replace('/[^0-9]/', '', $estabelecimento->cnpj);
                $response = Http::timeout(10)->get("https://receitaws.com.br/v1/cnpj/{$cnpj}");
                
                if ($response->successful()) {
                    $dados = $response->json();
                    
                    // Atividade principal
                    if (!empty($dados['atividade_principal'])) {
                        foreach ($dados['atividade_principal'] as $atividade) {
                            $codigo = $atividade['code'] ?? '';
                            // Filtra códigos inválidos ou vazios
                            if (empty($codigo) || $codigo === '00.00-0-00') continue;
                            
                            $atividadesApi[] = [
                                'codigo' => $codigo,
                                'descricao' => $atividade['text'] ?? '',
                                'tipo' => 'principal'
                            ];
                        }
                    }
                    
                    // Atividades secundárias
                    if (!empty($dados['atividades_secundarias'])) {
                        foreach ($dados['atividades_secundarias'] as $atividade) {
                            $codigo = $atividade['code'] ?? '';
                            // Filtra códigos inválidos ou vazios
                            if (empty($codigo) || $codigo === '00.00-0-00') continue;

                            $atividadesApi[] = [
                                'codigo' => $codigo,
                                'descricao' => $atividade['text'] ?? '',
                                'tipo' => 'secundaria'
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                \Log::error('Erro ao buscar atividades da API: ' . $e->getMessage());
            }
        }
        
        // Adiciona CNAEs manuais salvos no banco (para estabelecimentos públicos)
        if ($estabelecimento->cnaes_secundarios) {
            foreach ($estabelecimento->cnaes_secundarios as $cnae) {
                // Verifica se é manual (flag 'manual' = true)
                if (isset($cnae['manual']) && $cnae['manual']) {
                    $codigoLimpo = preg_replace('/[^0-9]/', '', $cnae['codigo']);
                    
                    // Verifica duplicidade na lista atual
                    $existe = false;
                    foreach ($atividadesApi as $apiCnae) {
                        // Remove pontuação para comparar
                        $codigoApi = preg_replace('/[^0-9]/', '', $apiCnae['codigo']);
                        
                        if ($codigoApi === $codigoLimpo) {
                            $existe = true;
                            break;
                        }
                    }
                    
                    if (!$existe) {
                        // Formata o CNAE para exibição (XX.XX-X-XX) se tiver 7 dígitos
                        $codigoFormatado = $cnae['codigo'];
                        if (strlen($codigoLimpo) === 7) {
                            $codigoFormatado = substr($codigoLimpo, 0, 2) . '.' . 
                                             substr($codigoLimpo, 2, 2) . '-' . 
                                             substr($codigoLimpo, 4, 1) . '-' . 
                                             substr($codigoLimpo, 5, 2);
                        }

                        $atividadesApi[] = [
                            'codigo' => $codigoFormatado,
                            'descricao' => $cnae['descricao'],
                            'tipo' => 'secundaria',
                            'manual' => true
                        ];
                    }
                }
            }
        }
        
        // Se não conseguiu buscar da API, usa as atividades salvas no banco
        if (empty($atividadesApi) && $estabelecimento->atividades_exercidas) {
            foreach ($estabelecimento->atividades_exercidas as $atividade) {
                $atividadesApi[] = [
                    'codigo' => $atividade['codigo'] ?? '',
                    'descricao' => $atividade['descricao'] ?? '',
                    'tipo' => ($atividade['principal'] ?? false) ? 'principal' : 'secundaria'
                ];
            }
        }
        
        // Se ainda não tem atividades, adiciona a atividade principal do CNAE fiscal
        if (empty($atividadesApi) && $estabelecimento->cnae_fiscal) {
            $atividadesApi[] = [
                'codigo' => $estabelecimento->cnae_fiscal,
                'descricao' => $estabelecimento->cnae_fiscal_descricao ?? '',
                'tipo' => 'principal'
            ];
        }

        // Atividades exercidas salvas que a Receita não devolve mais (CNAE removido do CNPJ
        // depois do cadastro). Precisam aparecer: senão ficam marcadas "invisíveis", continuam
        // definindo a competência e não podem ser desmarcadas.
        $codigosNumericosApi = collect($atividadesApi)
            ->map(fn ($a) => preg_replace('/[^0-9]/', '', (string) ($a['codigo'] ?? '')))
            ->filter()
            ->all();

        foreach (($estabelecimento->atividades_exercidas ?? []) as $atividadeSalva) {
            $codigoOriginal = is_array($atividadeSalva) ? (string) ($atividadeSalva['codigo'] ?? '') : (string) $atividadeSalva;
            if (in_array(strtoupper($codigoOriginal), ['PROJ_ARQ', 'ANAL_ROT'], true)) {
                continue;
            }

            $codigoLimpo = preg_replace('/[^0-9]/', '', $codigoOriginal);
            if ($codigoLimpo === '' || in_array($codigoLimpo, $codigosNumericosApi, true)) {
                continue;
            }

            $atividadesApi[] = [
                'codigo' => strlen($codigoLimpo) === 7
                    ? substr($codigoLimpo, 0, 2) . '.' . substr($codigoLimpo, 2, 2) . '-' . substr($codigoLimpo, 4, 1) . '-' . substr($codigoLimpo, 5, 2)
                    : $codigoOriginal,
                'descricao' => is_array($atividadeSalva) ? ($atividadeSalva['descricao'] ?? '') : '',
                'tipo' => (is_array($atividadeSalva) && !empty($atividadeSalva['principal'])) ? 'principal' : 'secundaria',
                'manual' => is_array($atividadeSalva) && !empty($atividadeSalva['manual']),
                'fora_receita' => !(is_array($atividadeSalva) && !empty($atividadeSalva['manual'])),
            ];
            $codigosNumericosApi[] = $codigoLimpo;
        }

        // Atividades especiais da Tabela VI não vêm da Receita Federal.
        // No admin, elas devem aparecer para que somente o administrador marque/desmarque.
        $codigosDisponiveis = collect($atividadesApi)
            ->pluck('codigo')
            ->map(fn ($codigo) => strtoupper((string) $codigo))
            ->all();

        foreach (($estabelecimento->atividades_exercidas ?? []) as $atividadeSalva) {
            $codigoSalvo = strtoupper((string) ($atividadeSalva['codigo'] ?? ''));
            if (!in_array($codigoSalvo, ['PROJ_ARQ', 'ANAL_ROT'], true) || in_array($codigoSalvo, $codigosDisponiveis, true)) {
                continue;
            }

            $atividadesApi[] = [
                'codigo' => $codigoSalvo,
                'descricao' => $atividadeSalva['descricao'] ?? $codigoSalvo,
                'tipo' => 'especial',
                'especial' => true,
            ];
            $codigosDisponiveis[] = $codigoSalvo;
        }

        $atividadesEspeciais = Pactuacao::where('tabela', 'VI')
            ->where('ativo', true)
            ->whereIn('cnae_codigo', ['PROJ_ARQ', 'ANAL_ROT'])
            ->orderBy('cnae_descricao')
            ->get(['cnae_codigo', 'cnae_descricao', 'tipo_processo_codigo']);

        foreach ($atividadesEspeciais as $especial) {
            $codigoEspecial = strtoupper((string) $especial->cnae_codigo);
            if (in_array($codigoEspecial, $codigosDisponiveis, true)) {
                continue;
            }

            $atividadesApi[] = [
                'codigo' => $codigoEspecial,
                'descricao' => $especial->cnae_descricao,
                'tipo' => 'especial',
                'especial' => true,
                'tipo_processo_codigo' => $especial->tipo_processo_codigo,
            ];
        }
        
        return view('estabelecimentos.atividades', compact('estabelecimento', 'atividadesApi', 'questionariosRespondidos'));
    }

    /**
     * Update the activities.
     */
    public function updateAtividades(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'atualizar suas atividades');
        
        $validated = $request->validate([
            'atividades_exercidas' => 'nullable|string',
        ]);
        
        // Decodifica o JSON das atividades
        $atividades = [];
        if (!empty($validated['atividades_exercidas'])) {
            $atividades = json_decode($validated['atividades_exercidas'], true);
        }

        if (!is_array($atividades)) {
            $atividades = [];
        }

        $podeGerenciarCnaeManual = auth('interno')->check() && auth('interno')->user()->isAdmin();

        // Mantém apenas estrutura esperada e identifica atividades manuais
        $atividades = collect($atividades)
            ->filter(fn($atividade) => is_array($atividade) && !empty($atividade['codigo']))
            ->map(function ($atividade) use ($podeGerenciarCnaeManual) {
                return [
                    'codigo' => (string) ($atividade['codigo'] ?? ''),
                    'descricao' => (string) ($atividade['descricao'] ?? ''),
                    'principal' => (bool) ($atividade['principal'] ?? false),
                    'manual' => $podeGerenciarCnaeManual ? (bool) ($atividade['manual'] ?? false) : false,
                ];
            })
            ->values()
            ->all();

        // Persiste CNAEs manuais também em cnaes_secundarios para reaparecer na tela
        $cnaesSecundariosAtuais = is_array($estabelecimento->cnaes_secundarios)
            ? $estabelecimento->cnaes_secundarios
            : [];

        $cnaesSecundariosNaoManuais = collect($cnaesSecundariosAtuais)
            ->filter(fn($cnae) => is_array($cnae) && empty($cnae['manual']))
            ->values()
            ->all();

        $cnaesManuaisSelecionados = collect($atividades)
            ->filter(function ($atividade) {
                $codigoNumerico = preg_replace('/[^0-9]/', '', (string) ($atividade['codigo'] ?? ''));

                return !empty($atividade['manual']) && $codigoNumerico !== '';
            })
            ->map(function ($atividade) {
                return [
                    'codigo' => $atividade['codigo'],
                    'descricao' => $atividade['descricao'],
                    'manual' => true,
                ];
            })
            ->values()
            ->all();

        $cnaesSecundariosFinal = collect(array_merge($cnaesSecundariosNaoManuais, $cnaesManuaisSelecionados))
            ->unique(function ($cnae) {
                $codigo = (string) ($cnae['codigo'] ?? '');
                $codigoNumerico = preg_replace('/[^0-9]/', '', $codigo);

                return $codigoNumerico !== '' ? $codigoNumerico : strtoupper($codigo);
            })
            ->values()
            ->all();
        
        $estabelecimento->update([
            'atividades_exercidas' => $atividades,
            'cnaes_secundarios' => $cnaesSecundariosFinal,
        ]);
        
        $estabelecimento->touch();
        
        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Atividades atualizadas com sucesso!');
    }

    /**
     * IDs dos tipos de documento vinculados às listas de documentos municipais do município.
     * Retorna null quando o município não possui nenhuma lista (sem restrição = todos os tipos).
     */
    private function idsDocumentosListasMunicipais(?int $municipioId): ?array
    {
        if (!$municipioId) {
            return null;
        }

        // 1) Documentos vinculados a listas municipais ativas deste município
        $idsListas = \App\Models\ListaDocumento::where('ativo', true)
            ->where('escopo', 'municipal')
            ->where('municipio_id', $municipioId)
            ->with('tiposDocumentoObrigatorio:tipos_documento_obrigatorio.id')
            ->get()
            ->flatMap(fn($lista) => $lista->tiposDocumentoObrigatorio->pluck('id'));

        // 2) Documentos cadastrados diretamente para este município
        //    (escopo de competência municipal + municipio_id), mesmo sem estar em lista
        $idsDiretos = \App\Models\TipoDocumentoObrigatorio::where('ativo', true)
            ->where('escopo_competencia', 'municipal')
            ->where('municipio_id', $municipioId)
            ->pluck('id');

        $ids = $idsListas->merge($idsDiretos)->unique()->values()->toArray();

        return !empty($ids) ? $ids : null;
    }

    /**
     * Formulário de gerenciamento dos documentos obrigatórios definidos manualmente
     * (município com modo "documentos manuais" habilitado)
     */
    public function editDocumentosManuais(string $id)
    {
        $estabelecimento = Estabelecimento::with('municipio')->findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'gerenciar seus documentos obrigatórios');

        if (!$estabelecimento->usaDocumentosManuais()) {
            return redirect()
                ->route('admin.estabelecimentos.show', $estabelecimento->id)
                ->with('error', 'Este estabelecimento não usa definição manual de documentos obrigatórios. Habilite a opção no cadastro do município.');
        }

        $query = \App\Models\TipoDocumentoObrigatorio::where('ativo', true);

        // Se o município possui listas de documentos vinculadas, restringe aos documentos dessas listas
        $idsListasMunicipais = $this->idsDocumentosListasMunicipais($estabelecimento->municipio_id);
        if ($idsListasMunicipais !== null) {
            // Inclui também os já selecionados (para não sumirem caso a lista mude)
            $idsSelecionados = $estabelecimento->documentosManuais()->pluck('tipos_documento_obrigatorio.id')->toArray();
            $query->whereIn('id', array_unique(array_merge($idsListasMunicipais, $idsSelecionados)));
        }

        $tiposDocumento = $query->ordenado()->get(['id', 'nome', 'descricao']);

        $selecionados = $estabelecimento->documentosManuais()->pluck('tipos_documento_obrigatorio.id')->toArray();

        return view('estabelecimentos.documentos-manuais', compact('estabelecimento', 'tiposDocumento', 'selecionados'));
    }

    /**
     * Atualiza os documentos obrigatórios definidos manualmente
     */
    public function updateDocumentosManuais(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::with('municipio')->findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'atualizar seus documentos obrigatórios');

        if (!$estabelecimento->usaDocumentosManuais()) {
            return redirect()
                ->route('admin.estabelecimentos.show', $estabelecimento->id)
                ->with('error', 'Este estabelecimento não usa definição manual de documentos obrigatórios.');
        }

        $validated = $request->validate([
            'documentos_manuais' => 'nullable|array',
            'documentos_manuais.*' => 'integer|exists:tipos_documento_obrigatorio,id',
        ]);

        $sync = collect($validated['documentos_manuais'] ?? [])
            ->mapWithKeys(fn($tipoId) => [$tipoId => ['definido_por' => auth('interno')->id()]])
            ->all();

        $estabelecimento->documentosManuais()->sync($sync);

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Documentos obrigatórios atualizados com sucesso!');
    }

    /**
     * Lista estabelecimentos pendentes de aprovação
     */
    public function pendentes(Request $request)
    {
        $usuario = auth('interno')->user();

        $query = Estabelecimento::pendentes()
            ->with(['usuarioExterno', 'municipio']);

        // Filtro de busca
        if ($request->filled('search')) {
            $search = $request->search;
            $searchLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $search);
            
            $query->where(function ($q) use ($search, $searchLimpo) {
                $q->where('nome_fantasia', 'ilike', "%{$search}%")
                  ->orWhere('razao_social', 'ilike', "%{$search}%")
                  ->orWhere('cidade', 'ilike', "%{$search}%")
                  ->orWhere('municipio', 'ilike', "%{$search}%");
                
                if (!empty($searchLimpo)) {
                    $q->orWhere('cnpj', 'like', "%{$searchLimpo}%")
                      ->orWhere('cpf', 'like', "%{$searchLimpo}%");
                }
            });
        }

        $this->aplicarFiltroTerritorialEstabelecimentos($query, $usuario);
        $estabelecimentosFiltrados = $this->filtrarEstabelecimentosPorEscopo(
            $query->orderBy('created_at', 'asc')->get(),
            $usuario
        );
        $estabelecimentos = $this->paginarColecao($estabelecimentosFiltrados, 15, $request);

        // Totais para as tabs
        $totalPendentes = $this->contarEstabelecimentosPorEscopo(Estabelecimento::pendentes(), $usuario);
        $totalRejeitados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::rejeitados(), $usuario);
        $totalDesativados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::where('ativo', false), $usuario);

        // Carrega atividades exercidas (marcadas pelo usuário) direto do campo JSON
        $atividadesPorEstabelecimento = [];
        foreach ($estabelecimentos as $estab) {
            $lista = collect();
            if ($estab->atividades_exercidas && is_array($estab->atividades_exercidas)) {
                foreach ($estab->atividades_exercidas as $ativ) {
                    $codigo = is_array($ativ) ? ($ativ['codigo'] ?? '') : (string) $ativ;
                    $descricao = is_array($ativ) ? ($ativ['descricao'] ?? '') : '';
                    if (!empty($codigo)) {
                        $lista->push((object) ['codigo' => $codigo, 'descricao' => $descricao]);
                    }
                }
            }
            $atividadesPorEstabelecimento[$estab->id] = $lista;
        }

        // Tipos de documento disponíveis para seleção manual na aprovação
        // (apenas se algum estabelecimento da página usa o modo de documentos manuais)
        // Quando o município possui listas de documentos vinculadas, restringe aos documentos dessas listas
        $tiposDocumentoDisponiveis = collect();
        $documentosPermitidosPorEstabelecimento = [];
        $algumUsaDocumentosManuais = $estabelecimentos->getCollection()->contains(fn($e) => $e->usaDocumentosManuais());
        if ($algumUsaDocumentosManuais) {
            $tiposDocumentoDisponiveis = \App\Models\TipoDocumentoObrigatorio::where('ativo', true)
                ->ordenado()
                ->get(['id', 'nome', 'descricao']);

            $cachePorMunicipio = [];
            foreach ($estabelecimentos as $estab) {
                if (!$estab->usaDocumentosManuais()) {
                    continue;
                }
                $munId = $estab->municipio_id;
                if (!array_key_exists($munId, $cachePorMunicipio)) {
                    $cachePorMunicipio[$munId] = $this->idsDocumentosListasMunicipais($munId);
                }
                // Array vazio = sem restrição (mostra todos)
                $documentosPermitidosPorEstabelecimento[$estab->id] = $cachePorMunicipio[$munId] ?? [];
            }
        }

        return view('estabelecimentos.pendentes', compact('estabelecimentos', 'totalPendentes', 'totalRejeitados', 'totalDesativados', 'atividadesPorEstabelecimento', 'tiposDocumentoDisponiveis', 'documentosPermitidosPorEstabelecimento'));
    }

    /**
     * Lista estabelecimentos rejeitados
     */
    public function rejeitados(Request $request)
    {
        $usuario = auth('interno')->user();

        $query = Estabelecimento::rejeitados()
            ->with(['usuarioExterno', 'aprovadoPor']);

        // Filtro de busca
        if ($request->filled('search')) {
            $search = $request->search;
            $searchLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $search);
            
            $query->where(function ($q) use ($search, $searchLimpo) {
                $q->where('nome_fantasia', 'ilike', "%{$search}%")
                  ->orWhere('razao_social', 'ilike', "%{$search}%")
                  ->orWhere('cidade', 'ilike', "%{$search}%")
                  ->orWhere('municipio', 'ilike', "%{$search}%");
                
                if (!empty($searchLimpo)) {
                    $q->orWhere('cnpj', 'like', "%{$searchLimpo}%")
                      ->orWhere('cpf', 'like', "%{$searchLimpo}%");
                }
            });
        }

        $this->aplicarFiltroTerritorialEstabelecimentos($query, $usuario);
        $estabelecimentosFiltrados = $this->filtrarEstabelecimentosPorEscopo(
            $query->orderBy('aprovado_em', 'desc')->get(),
            $usuario
        );
        $estabelecimentos = $this->paginarColecao($estabelecimentosFiltrados, 15, $request);

        // Totais para as tabs
        $totalPendentes = $this->contarEstabelecimentosPorEscopo(Estabelecimento::pendentes(), $usuario);
        $totalRejeitados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::rejeitados(), $usuario);
        $totalDesativados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::where('ativo', false), $usuario);

        return view('estabelecimentos.rejeitados', compact('estabelecimentos', 'totalPendentes', 'totalRejeitados', 'totalDesativados'));
    }

    /**
     * Lista estabelecimentos desativados
     */
    public function desativados(Request $request)
    {
        $usuario = auth('interno')->user();

        $query = Estabelecimento::where('ativo', false)
            ->with(['usuarioExterno', 'aprovadoPor']);

        // Filtro de busca
        if ($request->filled('search')) {
            $search = $request->search;
            $searchLimpo = preg_replace('/[^a-zA-Z0-9]/', '', $search);
            
            $query->where(function ($q) use ($search, $searchLimpo) {
                $q->where('nome_fantasia', 'ilike', "%{$search}%")
                  ->orWhere('razao_social', 'ilike', "%{$search}%")
                  ->orWhere('cidade', 'ilike', "%{$search}%")
                  ->orWhere('municipio', 'ilike', "%{$search}%");
                
                if (!empty($searchLimpo)) {
                    $q->orWhere('cnpj', 'like', "%{$searchLimpo}%")
                      ->orWhere('cpf', 'like', "%{$searchLimpo}%");
                }
            });
        }

        $this->aplicarFiltroTerritorialEstabelecimentos($query, $usuario);
        $estabelecimentosFiltrados = $this->filtrarEstabelecimentosPorEscopo(
            $query->orderBy('updated_at', 'desc')->get(),
            $usuario
        );
        $estabelecimentos = $this->paginarColecao($estabelecimentosFiltrados, 15, $request);

        // Totais para as tabs
        $totalPendentes = $this->contarEstabelecimentosPorEscopo(Estabelecimento::pendentes(), $usuario);
        $totalRejeitados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::rejeitados(), $usuario);
        $totalDesativados = $this->contarEstabelecimentosPorEscopo(Estabelecimento::where('ativo', false), $usuario);

        return view('estabelecimentos.desativados', compact('estabelecimentos', 'totalPendentes', 'totalRejeitados', 'totalDesativados'));
    }


    /**
     * Aprova um estabelecimento
     */
    public function aprovar(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        // Verifica permissão
        if (!auth('interno')->check()) {
            return redirect()->back()->with('error', 'Você não tem permissão para aprovar estabelecimentos.');
        }

        $validated = $request->validate([
            'observacao' => 'nullable|string|max:500',
            'documentos_manuais' => 'nullable|array',
            'documentos_manuais.*' => 'integer|exists:tipos_documento_obrigatorio,id',
        ]);

        $estabelecimento->aprovar($validated['observacao'] ?? null);

        // Documentos obrigatórios definidos manualmente pela vigilância municipal
        if ($estabelecimento->usaDocumentosManuais() && !empty($validated['documentos_manuais'])) {
            $documentosSelecionados = collect($validated['documentos_manuais']);

            // Se o município possui listas de documentos vinculadas, aceita apenas documentos dessas listas
            $idsPermitidos = $this->idsDocumentosListasMunicipais($estabelecimento->municipio_id);
            if ($idsPermitidos !== null) {
                $documentosSelecionados = $documentosSelecionados->filter(fn($tipoId) => in_array($tipoId, $idsPermitidos));
            }

            $sync = $documentosSelecionados
                ->mapWithKeys(fn($tipoId) => [$tipoId => ['definido_por' => auth('interno')->id()]])
                ->all();
            $estabelecimento->documentosManuais()->sync($sync);
        }

        // PJ Unidade Móvel: cria processos de credenciamento automaticamente
        if ($estabelecimento->is_unidade_movel) {
            // Marca o módulo como aprovado junto com a aprovação do cadastro novo
            if ($estabelecimento->status_unidade_movel !== 'aprovado') {
                $estabelecimento->update(['status_unidade_movel' => 'aprovado']);
            }
            $this->criarProcessosUnidadeMovel($estabelecimento);
        }

        return redirect()
            ->route('admin.estabelecimentos.pendentes')
            ->with('success', 'Estabelecimento aprovado com sucesso!');
    }

    /**
     * Cria processos de credenciamento para PJ Unidade Móvel ao aprovar.
     * - 1 processo estadual com pastas por município estadual
     * - 1 processo por município municipal que usa InfoVisa
     */
    private function criarProcessosUnidadeMovel(Estabelecimento $estabelecimento): void
    {
        $municipiosAtuacao = \App\Models\EstabelecimentoMunicipioAtuacao::where('estabelecimento_id', $estabelecimento->id)->get();

        if ($municipiosAtuacao->isEmpty()) {
            return;
        }

        $tipoProcesso = \App\Models\TipoProcesso::where('codigo', 'credenciamento_movel')->first();
        if (!$tipoProcesso) {
            return;
        }

        $municipiosEstaduais = $municipiosAtuacao->where('competencia', 'estadual');
        $municipiosMunicipaisInfovisa = $municipiosAtuacao->where('competencia', 'municipal')->where('usa_infovisa', true);
        $usuarioId = auth('interno')->id();

        DB::transaction(function () use (
            $estabelecimento, $tipoProcesso, $municipiosEstaduais, $municipiosMunicipaisInfovisa, $usuarioId
        ) {
            // Processo ESTADUAL (1 processo com pastas por município)
            if ($municipiosEstaduais->isNotEmpty()) {
                $dadosNumero = \App\Models\Processo::gerarNumeroProcesso();
                $processoEstadual = \App\Models\Processo::create([
                    'estabelecimento_id' => $estabelecimento->id,
                    'tipo' => $tipoProcesso->codigo,
                    'ano' => $dadosNumero['ano'],
                    'numero_sequencial' => $dadosNumero['numero_sequencial'],
                    'numero_processo' => $dadosNumero['numero_processo'],
                    'status' => 'aberto',
                    'usuario_id' => $usuarioId,
                    'setor_atual' => $tipoProcesso->tipoSetor?->codigo,
                ]);

                $ordem = 1;
                foreach ($municipiosEstaduais as $mun) {
                    \App\Models\ProcessoPasta::create([
                        'processo_id' => $processoEstadual->id,
                        'nome' => $mun->municipio_nome,
                        'descricao' => "Documentos para o município de {$mun->municipio_nome}",
                        'protegida' => true,
                        'ordem' => $ordem++,
                        'status' => 'aberta',
                    ]);
                }
            }

            // Processos MUNICIPAIS (1 por município que usa InfoVisa)
            foreach ($municipiosMunicipaisInfovisa as $mun) {
                $dadosNumero = \App\Models\Processo::gerarNumeroProcesso();

                $setorMunicipal = $tipoProcesso->setoresMunicipais()
                    ->where('municipio_id', $mun->municipio_id)
                    ->with('tipoSetor')
                    ->first();
                $setor = $setorMunicipal?->tipoSetor?->codigo;

                if (!$setor) {
                    $setorDoMunicipio = \App\Models\TipoSetor::whereHas('municipios', fn($q) => $q->where('municipios.id', $mun->municipio_id))->first();
                    $setor = $setorDoMunicipio?->codigo;
                }

                \App\Models\Processo::create([
                    'estabelecimento_id' => $estabelecimento->id,
                    'tipo' => $tipoProcesso->codigo,
                    'ano' => $dadosNumero['ano'],
                    'numero_sequencial' => $dadosNumero['numero_sequencial'],
                    'numero_processo' => $dadosNumero['numero_processo'],
                    'status' => 'aberto',
                    'usuario_id' => $usuarioId,
                    'setor_atual' => $setor,
                    'observacoes' => "Credenciamento municipal - {$mun->municipio_nome}",
                ]);
            }
        });
    }

    /**
     * Rejeita um estabelecimento
     */
    public function rejeitar(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        // Verifica permissão
        if (!auth('interno')->check()) {
            return redirect()->back()->with('error', 'Você não tem permissão para rejeitar estabelecimentos.');
        }

        $validated = $request->validate([
            'motivo_rejeicao' => 'required|string|max:1000',
            'observacao' => 'nullable|string|max:500',
        ]);

        $estabelecimento->rejeitar(
            $validated['motivo_rejeicao'],
            $validated['observacao'] ?? null
        );

        return redirect()
            ->route('admin.estabelecimentos.pendentes')
            ->with('success', 'Estabelecimento rejeitado.');
    }

    /**
     * Reinicia um estabelecimento (volta para pendente)
     */
    public function reiniciar(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        // Verifica permissão
        if (!auth('interno')->check()) {
            return redirect()->back()->with('error', 'Você não tem permissão para reiniciar estabelecimentos.');
        }

        $validated = $request->validate([
            'observacao' => 'nullable|string|max:500',
        ]);

        $estabelecimento->reiniciar($validated['observacao'] ?? null);

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Estabelecimento reiniciado. Status voltou para pendente.');
    }


    /**
     * Exibe o histórico de um estabelecimento
     */
    public function historico(string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $historicos = $estabelecimento->historicos()
            ->with('usuario')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return view('estabelecimentos.historico', compact('estabelecimento', 'historicos'));
    }

    /**
     * Exibe os municípios de atuação de uma Unidade Móvel
     */
    public function municipiosAtuacao(string $id)
    {
        $estabelecimento = Estabelecimento::with('municipiosAtuacao')->findOrFail($id);
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'acessá-lo');

        $municipiosAtuacao = $estabelecimento->municipiosAtuacao()->orderBy('municipio_nome')->get();

        return view('estabelecimentos.municipios-atuacao', compact('estabelecimento', 'municipiosAtuacao'));
    }

    /**
     * Aprova o módulo de Unidade Móvel solicitado por um estabelecimento já
     * aprovado. Marca o status e cria os processos de credenciamento.
     */
    public function aprovarUnidadeMovel(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'aprová-lo');

        if ($estabelecimento->status_unidade_movel !== 'pendente') {
            return back()->with('error', 'Não há solicitação de Unidade Móvel pendente para este estabelecimento.');
        }

        $estabelecimento->update([
            'status_unidade_movel' => 'aprovado',
            'motivo_rejeicao_unidade_movel' => null,
        ]);

        $this->criarProcessosUnidadeMovel($estabelecimento);

        return back()->with('success', 'Módulo de Unidade Móvel aprovado e processos de credenciamento criados com sucesso!');
    }

    /**
     * Rejeita o módulo de Unidade Móvel solicitado. Reverte is_unidade_movel
     * e remove os municípios de atuação registrados na solicitação.
     */
    public function rejeitarUnidadeMovel(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'rejeitá-lo');

        if ($estabelecimento->status_unidade_movel !== 'pendente') {
            return back()->with('error', 'Não há solicitação de Unidade Móvel pendente para este estabelecimento.');
        }

        $validated = $request->validate([
            'motivo_rejeicao_unidade_movel' => 'required|string|max:500',
        ], [
            'motivo_rejeicao_unidade_movel.required' => 'Informe o motivo da rejeição.',
        ]);

        DB::transaction(function () use ($estabelecimento, $validated) {
            $estabelecimento->municipiosAtuacao()->delete();
            $estabelecimento->update([
                'is_unidade_movel' => false,
                'status_unidade_movel' => 'rejeitado',
                'tipo_unidade_movel' => null,
                'motivo_rejeicao_unidade_movel' => $validated['motivo_rejeicao_unidade_movel'],
            ]);
        });

        return back()->with('success', 'Solicitação de Unidade Móvel rejeitada.');
    }

    /**
     * Lista documentos digitais do estabelecimento
     */
    public function documentos(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'visualizar documentos');

        $processosIds = $estabelecimento->processos()->pluck('id');

        $query = \App\Models\DocumentoDigital::whereIn('processo_id', $processosIds)
            ->with(['tipoDocumento', 'usuarioCriador', 'processo']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('busca')) {
            $busca = $request->busca;
            $query->where(function ($q) use ($busca) {
                $q->where('nome', 'ilike', "%{$busca}%")
                  ->orWhere('numero_documento', 'ilike', "%{$busca}%")
                  ->orWhereHas('tipoDocumento', fn($tq) => $tq->where('nome', 'ilike', "%{$busca}%"));
            });
        }

        $documentos = $query->orderByDesc('created_at')->paginate(15)->withQueryString();

        return view('estabelecimentos.documentos', compact('estabelecimento', 'documentos'));
    }

    /**
     * Volta estabelecimento aprovado para pendente (apenas admin sem processos)
     */
    public function voltarPendente(Request $request, string $id)
    {
        // Verifica se é administrador
        if (!auth('interno')->user()->nivel_acesso->isAdmin()) {
            return redirect()->back()->with('error', 'Apenas administradores podem realizar esta ação.');
        }

        $estabelecimento = Estabelecimento::findOrFail($id);

        // Verifica se está aprovado
        if ($estabelecimento->status !== 'aprovado') {
            return redirect()->back()->with('error', 'Apenas estabelecimentos aprovados podem voltar para pendente.');
        }

        // Verifica se tem processos
        if ($estabelecimento->processos()->count() > 0) {
            return redirect()->back()->with('error', 'Não é possível voltar para pendente. Este estabelecimento possui processos vinculados.');
        }

        $validated = $request->validate([
            'observacao' => 'required|string|max:1000',
        ]);

        // Atualiza status
        $statusAnterior = $estabelecimento->status;
        $estabelecimento->status = 'pendente';
        $estabelecimento->aprovado_por = null;
        $estabelecimento->aprovado_em = null;
        $estabelecimento->save();

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'reiniciado',
            $statusAnterior,
            'pendente',
            'Voltou para pendente: ' . $validated['observacao']
        );

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Estabelecimento voltou para status pendente.');
    }

    /**
     * Desativa um estabelecimento (apenas admin)
     */
    public function desativar(Request $request, string $id)
    {
        // Verifica se é administrador
        if (!auth('interno')->user()->nivel_acesso->isAdmin()) {
            return redirect()->back()->with('error', 'Apenas administradores podem desativar estabelecimentos.');
        }

        $validated = $request->validate([
            'motivo' => 'required|string|max:1000',
        ]);

        $estabelecimento = Estabelecimento::findOrFail($id);
        $estabelecimento->ativo = false;
        $estabelecimento->motivo_desativacao = $validated['motivo'];
        $estabelecimento->save();

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            'ativo',
            'inativo',
            'Estabelecimento desativado: ' . $validated['motivo']
        );

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Estabelecimento desativado com sucesso.');
    }

    /**
     * Ativa um estabelecimento (apenas admin)
     */
    public function ativar(string $id)
    {
        // Verifica se é administrador
        if (!auth('interno')->user()->nivel_acesso->isAdmin()) {
            return redirect()->back()->with('error', 'Apenas administradores podem ativar estabelecimentos.');
        }

        $estabelecimento = Estabelecimento::findOrFail($id);
        $estabelecimento->ativo = true;
        $estabelecimento->motivo_desativacao = null; // Limpa o motivo ao reativar
        $estabelecimento->save();

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            'inativo',
            'ativo',
            'Estabelecimento reativado'
        );

        return redirect()
            ->route('admin.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Estabelecimento reativado com sucesso.');
    }

    /**
     * Lista usuários vinculados ao estabelecimento
     */
    public function usuariosIndex(string $id)
    {
        $estabelecimento = Estabelecimento::with(['usuariosVinculados' => function($query) {
            $query->orderBy('estabelecimento_usuario_externo.created_at', 'desc');
        }, 'usuarioExterno'])->findOrFail($id);

        // Buscar todos os usuários externos para vincular
        $usuariosDisponiveis = UsuarioExterno::where('ativo', true)
            ->whereNotIn('id', $estabelecimento->usuariosVinculados->pluck('id'))
            ->orderBy('nome')
            ->get();

        // Buscar o criador do cadastro (usuário que cadastrou o estabelecimento)
        $criador = $estabelecimento->usuarioExterno;
        
        // Verificar se o criador já está na lista de vinculados
        $criadorVinculado = $estabelecimento->usuariosVinculados->contains('id', $criador?->id);

        return view('estabelecimentos.usuarios.index', compact('estabelecimento', 'usuariosDisponiveis', 'criador', 'criadorVinculado'));
    }

    /**
     * Vincula um usuário externo ao estabelecimento
     */
    public function vincularUsuario(Request $request, string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $validated = $request->validate([
            'usuario_externo_id' => 'required|exists:usuarios_externos,id',
            'tipo_vinculo' => 'required|in:funcionario,contador',
            'nivel_acesso' => 'required|in:gestor,visualizador',
            'observacao' => 'nullable|string|max:500',
        ]);

        // Verifica se já está vinculado
        if ($estabelecimento->usuariosVinculados()->where('usuario_externo_id', $validated['usuario_externo_id'])->exists()) {
            return redirect()->back()->with('error', 'Este usuário já está vinculado ao estabelecimento.');
        }

        // Vincula
        $estabelecimento->usuariosVinculados()->attach($validated['usuario_externo_id'], [
            'tipo_vinculo' => $validated['tipo_vinculo'],
            'nivel_acesso' => $validated['nivel_acesso'],
            'observacao' => $validated['observacao'] ?? null,
            'vinculado_por' => auth('interno')->id(),
        ]);

        // Registra no histórico
        $usuario = UsuarioExterno::find($validated['usuario_externo_id']);
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            null,
            null,
            "Usuário {$usuario->nome} vinculado como " . ucfirst(str_replace('_', ' ', $validated['tipo_vinculo']))
        );

        return redirect()
            ->route('admin.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Usuário vinculado com sucesso.');
    }

    /**
     * Desvincula um usuário externo do estabelecimento
     */
    public function desvincularUsuario(string $id, string $usuario_id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        $usuario = UsuarioExterno::findOrFail($usuario_id);

        $estabelecimento->usuariosVinculados()->detach($usuario_id);

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            null,
            null,
            "Usuário {$usuario->nome} desvinculado"
        );

        return redirect()
            ->route('admin.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Usuário desvinculado com sucesso.');
    }

    /**
     * Remove o criador do cadastro (apenas admin)
     */
    public function removerCriador(string $id)
    {
        // Verifica se o usuário é administrador
        if (!auth('interno')->user()->isAdmin()) {
            return redirect()
                ->route('admin.estabelecimentos.usuarios.index', $id)
                ->with('error', 'Apenas administradores podem desvincular o criador do cadastro.');
        }

        $estabelecimento = Estabelecimento::findOrFail($id);
        
        // Verifica se tem criador
        if (!$estabelecimento->usuario_externo_id) {
            return redirect()
                ->route('admin.estabelecimentos.usuarios.index', $estabelecimento->id)
                ->with('error', 'Este estabelecimento não possui um criador vinculado.');
        }

        $criador = UsuarioExterno::find($estabelecimento->usuario_externo_id);
        $nomeUsuario = $criador ? $criador->nome : 'Usuário desconhecido';

        // Remove o vínculo do criador
        $estabelecimento->usuario_externo_id = null;
        $estabelecimento->save();

        // Registra no histórico
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            null,
            null,
            "Criador do cadastro ({$nomeUsuario}) desvinculado pelo administrador"
        );

        return redirect()
            ->route('admin.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', "Criador do cadastro ({$nomeUsuario}) desvinculado com sucesso.");
    }

    /**
     * Atualiza o vínculo de um usuário externo
     */
    public function atualizarVinculo(Request $request, string $id, string $usuario_id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $validated = $request->validate([
            'tipo_vinculo' => 'required|in:funcionario,contador',
            'nivel_acesso' => 'required|in:gestor,visualizador',
            'observacao' => 'nullable|string|max:500',
        ]);

        $estabelecimento->usuariosVinculados()->updateExistingPivot($usuario_id, [
            'tipo_vinculo' => $validated['tipo_vinculo'],
            'nivel_acesso' => $validated['nivel_acesso'],
            'observacao' => $validated['observacao'] ?? null,
        ]);

        $usuario = UsuarioExterno::find($usuario_id);
        EstabelecimentoHistorico::registrar(
            $estabelecimento->id,
            'atualizado',
            null,
            null,
            "Vínculo do usuário {$usuario->nome} atualizado"
        );

        return redirect()
            ->route('admin.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Vínculo atualizado com sucesso.');
    }

    /**
     * Buscar estabelecimento por CPF
     */
    public function buscarPorCpf($cpf)
    {
        // Remove máscara do CPF
        $cpf = preg_replace('/\D/', '', $cpf);
        
        // Busca estabelecimento por CPF
        $estabelecimento = Estabelecimento::where('cpf', $cpf)->first();
        
        if ($estabelecimento) {
            return response()->json([
                'existe' => true,
                'nome' => $estabelecimento->nome_completo,
                'rg' => $estabelecimento->rg,
                'orgao_emissor' => $estabelecimento->orgao_emissor,
                'nome_fantasia' => $estabelecimento->nome_fantasia,
                'email' => $estabelecimento->email,
                'telefone' => $estabelecimento->telefone,
            ]);
        }
        
        return response()->json(['existe' => false]);
    }

    /**
     * Verifica se já existe um estabelecimento público com o mesmo CNPJ e Nome Fantasia
     */
    public function verificarDuplicidadePublico(Request $request)
    {
        $request->validate([
            'cnpj' => 'required|string',
            'nome_fantasia' => 'required|string',
        ]);

        // Remove formatação do CNPJ
        $cnpj = preg_replace('/\D/', '', $request->cnpj);
        $nomeFantasia = mb_strtoupper(trim($request->nome_fantasia));

        $existe = Estabelecimento::where('cnpj', $cnpj)
            ->whereRaw('UPPER(nome_fantasia) = ?', [$nomeFantasia])
            ->exists();

        return response()->json(['existe' => $existe]);
    }

    /**
     * Busca usuários externos para vincular ao estabelecimento
     */
    public function buscarUsuarios(Request $request)
    {
        $query = $request->input('q', '');
        $estabelecimentoId = $request->input('estabelecimento_id');
        
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        // Remove formatação do CPF para busca
        $cpfLimpo = preg_replace('/\D/', '', $query);

        $usuarios = \App\Models\UsuarioExterno::where(function($q) use ($query, $cpfLimpo) {
            $q->whereRaw("nome ILIKE ?", ["%{$query}%"])
              ->orWhereRaw("email ILIKE ?", ["%{$query}%"]);
            
            if (strlen($cpfLimpo) >= 3) {
                $q->orWhere('cpf', 'like', "%{$cpfLimpo}%");
            }
        });

        // Exclui usuários já vinculados ao estabelecimento
        if ($estabelecimentoId) {
            $estabelecimento = Estabelecimento::find($estabelecimentoId);
            if ($estabelecimento) {
                $usuariosVinculados = $estabelecimento->usuariosVinculados->pluck('id')->toArray();
                $usuarios->whereNotIn('id', $usuariosVinculados);
            }
        }

        $usuarios = $usuarios->limit(10)->get(['id', 'nome', 'email', 'cpf']);

        return response()->json($usuarios->map(function($usuario) {
            return [
                'id' => $usuario->id,
                'nome' => $usuario->nome,
                'email' => $usuario->email,
                'cpf' => $usuario->cpf_formatado ?? $usuario->cpf,
            ];
        }));
    }

    /**
     * EXEMPLO: Busca documentos aplicáveis para um estabelecimento
     * Demonstra como usar a nova funcionalidade de documentos comuns
     */
    public function buscarDocumentosAplicaveis(string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);
        
        // Busca todos os documentos aplicáveis (listas específicas + documentos comuns)
        $documentos = \App\Models\ListaDocumento::buscarTodosDocumentosParaEstabelecimento($estabelecimento);
        
        return response()->json([
            'estabelecimento' => [
                'id' => $estabelecimento->id,
                'nome' => $estabelecimento->nome_fantasia ?? $estabelecimento->razao_social,
                'tipo_setor' => $estabelecimento->tipo_setor,
                'municipio' => $estabelecimento->municipio,
            ],
            'escopo_competencia' => $documentos['escopo_competencia'],
            'listas_especificas' => $documentos['listas']->map(function($lista) {
                return [
                    'id' => $lista->id,
                    'nome' => $lista->nome,
                    'escopo' => $lista->escopo,
                    'documentos' => $lista->tiposDocumentoObrigatorio->map(function($doc) use ($estabelecimento) {
                        return [
                            'id' => $doc->id,
                            'nome' => $doc->nome,
                            'descricao' => $doc->descricao,
                            'obrigatorio' => $doc->pivot->obrigatorio,
                            'observacao' => $doc->pivot->observacao,
                        ];
                    })
                ];
            }),
            'documentos_comuns' => $documentos['documentos_comuns']->map(function($doc) use ($estabelecimento) {
                return [
                    'id' => $doc->id,
                    'nome' => $doc->nome,
                    'descricao' => $doc->descricao,
                    'escopo_competencia' => $doc->escopo_competencia_label,
                    'tipo_setor' => $doc->tipo_setor_label,
                    'prazo_validade_dias' => $doc->prazo_validade_dias,
                    'observacao' => $doc->getObservacaoParaTipoSetor($estabelecimento->tipo_setor ?? 'privado'),
                    'aplica_ao_estabelecimento' => $doc->aplicaAoTipoSetor($estabelecimento->tipo_setor ?? 'privado'),
                ];
            })
        ]);
    }

    /**
     * Lista equipamentos de radiação do estabelecimento
     */
    public function equipamentosRadiacaoIndex(string $id)
    {
        $estabelecimento = Estabelecimento::findOrFail($id);

        $this->autorizarAcessoEstabelecimentoInterno($estabelecimento, 'acessar este estabelecimento');
        
        // Verifica se o estabelecimento exige equipamentos de radiação
        $exigeEquipamentos = \App\Models\AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentos($estabelecimento);
        
        if (!$exigeEquipamentos) {
            return redirect()->route('admin.estabelecimentos.show', $estabelecimento->id)
                ->with('error', 'Este estabelecimento não possui atividades que exigem cadastro de equipamentos de imagem.');
        }
        
        // Busca os equipamentos
        $equipamentos = \App\Models\EquipamentoRadiacao::where('estabelecimento_id', $estabelecimento->id)
            ->orderBy('tipo_equipamento')
            ->orderBy('created_at', 'desc')
            ->get();
        
        // Busca as atividades que exigem equipamentos
        $atividadesQueExigem = \App\Models\AtividadeEquipamentoRadiacao::getAtividadesQueExigemEquipamentos($estabelecimento);
        
        return view('estabelecimentos.equipamentos-radiacao.index', compact('estabelecimento', 'equipamentos', 'atividadesQueExigem'));
    }
}
