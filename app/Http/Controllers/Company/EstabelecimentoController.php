<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Estabelecimento;
use App\Models\Pactuacao;
use App\Services\ResponsavelTecnicoNomeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class EstabelecimentoController extends Controller
{
    /**
     * Retorna query base para estabelecimentos do usuário (próprios e vinculados)
     */
    private function estabelecimentosDoUsuario()
    {
        $usuarioId = auth('externo')->id();
        
        return Estabelecimento::where(function($q) use ($usuarioId) {
            $q->where('usuario_externo_id', $usuarioId)
              ->orWhereHas('usuariosVinculados', function($q2) use ($usuarioId) {
                  $q2->where('usuario_externo_id', $usuarioId);
              });
        });
    }

    /**
     * Verifica se o usuário tem acesso de gestor ao estabelecimento.
     * Se não tiver, redireciona com mensagem de erro.
     * 
     * @param Estabelecimento $estabelecimento
     * @return \Illuminate\Http\RedirectResponse|null
     */
    private function verificarAcessoGestor(Estabelecimento $estabelecimento)
    {
        if ($estabelecimento->usuarioEhVisualizador()) {
            return redirect()->route('company.estabelecimentos.show', $estabelecimento->id)
                ->with('error', 'Acesso restrito: sua conta possui permissão apenas para visualização. Entre em contato com o responsável do estabelecimento para solicitar permissões de edição.');
        }
        return null;
    }

    /**
     * Verifica se já existe estabelecimento público com o mesmo nome fantasia
     */
    public function verificarNomeFantasia(Request $request)
    {
        $nomeFantasia = mb_strtoupper(trim($request->input('nome_fantasia', '')));
        $cnpj = preg_replace('/\D/', '', $request->input('cnpj', ''));

        if (strlen($nomeFantasia) < 3) {
            return response()->json(['existe' => false]);
        }

        $existe = Estabelecimento::where('nome_fantasia', $nomeFantasia)
            ->where('tipo_setor', 'publico')
            ->whereNull('deleted_at')
            ->exists();

        return response()->json([
            'existe' => $existe,
            'mensagem' => $existe
                ? "Já existe um estabelecimento público cadastrado com o nome \"{$nomeFantasia}\". Entre em contato com a Vigilância Sanitária."
                : null,
        ]);
    }

    /**
     * Busca CNAEs por código ou descrição
     */
    public function buscarCnaes(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        // Busca nas pactuações cadastradas
        $resultados = Pactuacao::where('ativo', true)
            ->where(function($q) use ($query) {
                $q->where('cnae_codigo', 'like', "%{$query}%")
                  ->orWhere('cnae_descricao', 'ilike', "%{$query}%");
            })
            ->select('cnae_codigo as codigo', 'cnae_descricao as descricao')
            ->distinct()
            ->limit(20)
            ->get();

        return response()->json($resultados);
    }

    /**
     * Busca questionários para uma lista de CNAEs
     */
    public function buscarQuestionarios(Request $request)
    {
        $cnaes = $request->input('cnaes', []);
        
        if (empty($cnaes)) {
            return response()->json([]);
        }

        // Normaliza os CNAEs (remove formatação)
        $cnaesNormalizados = array_map(function($cnae) {
            return preg_replace('/[^0-9]/', '', $cnae);
        }, $cnaes);

        // Busca pactuações que requerem questionário
        $questionarios = Pactuacao::whereIn('cnae_codigo', $cnaesNormalizados)
            ->where('requer_questionario', true)
            ->where('ativo', true)
            ->get()
            ->map(function($pactuacao) {
                return [
                    'cnae' => $pactuacao->cnae_codigo,
                    'cnae_formatado' => $pactuacao->cnae_codigo,
                    'descricao' => $pactuacao->cnae_descricao,
                    'pergunta' => $pactuacao->pergunta,
                    'pergunta2' => $pactuacao->pergunta2,
                    'tipo_questionario' => $pactuacao->tipo_questionario,
                    'tabela' => $pactuacao->tabela,
                    'municipios_excecao' => $pactuacao->municipios_excecao ?? [],
                ];
            });

        return response()->json($questionarios);
    }

    public function index(Request $request)
    {
        // Busca todos os estabelecimentos do usuário (próprios e vinculados) para estatísticas
        $todosEstabelecimentos = $this->estabelecimentosDoUsuario()->get();
        
        // Query para listagem com filtros
        $query = $this->estabelecimentosDoUsuario();
        
        // Filtro por status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        // Busca
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nome_fantasia', 'ilike', "%{$search}%")
                  ->orWhere('razao_social', 'ilike', "%{$search}%")
                  ->orWhere('nome_completo', 'ilike', "%{$search}%")
                  ->orWhere('cnpj', 'like', "%{$search}%")
                  ->orWhere('cpf', 'like', "%{$search}%");
            });
        }
        
        $estabelecimentos = $query->orderBy('created_at', 'desc')->paginate(10);
        
        // Estatísticas baseadas na collection já carregada
        $estatisticas = [
            'total' => $todosEstabelecimentos->count(),
            'pendentes' => $todosEstabelecimentos->where('status', 'pendente')->count(),
            'aprovados' => $todosEstabelecimentos->where('status', 'aprovado')->count(),
            'rejeitados' => $todosEstabelecimentos->where('status', 'rejeitado')->count(),
        ];
        
        return view('company.estabelecimentos.index', compact('estabelecimentos', 'estatisticas'));
    }
    
    public function show($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->with(['processos' => function($q) {
                $q->whereHas('tipoProcesso', fn($tp) => $tp->where('usuario_externo_pode_visualizar', true));
            }, 'processos.tipoProcesso', 'municipiosAtuacao'])
            ->findOrFail($id);
        
        return view('company.estabelecimentos.show', compact('estabelecimento'));
    }
    
    public function create()
    {
        return view('company.estabelecimentos.create');
    }
    
    public function createJuridica()
    {
        return view('company.estabelecimentos.create-juridica');
    }
    
    public function createFisica()
    {
        // CNAEs permitidos para cadastro de Pessoa Física (configurados na pactuação).
        // Códigos normalizados (só dígitos) para validação no formulário.
        $cnaesPermitidos = \App\Models\Pactuacao::cnaesPessoaFisica();

        // Lista com descrição para exibir ao usuário quais atividades são aceitas.
        $cnaesPermitidosLista = \App\Models\Pactuacao::where('pessoa_fisica', true)
            ->where('ativo', true)
            ->orderBy('cnae_codigo')
            ->get(['cnae_codigo', 'cnae_descricao']);

        return view('company.estabelecimentos.create-fisica', compact('cnaesPermitidos', 'cnaesPermitidosLista'));
    }

    /**
     * Formulário de cadastro do PJ Unidade Móvel (serviço itinerante).
     * Disponibiliza a lista de municípios do TO e os CNAEs permitidos.
     */
    public function createUnidadeMovel()
    {
        $municipios = \App\Models\Municipio::orderBy('nome')->get(['id', 'nome', 'usa_infovisa']);
        $cnaesPermitidosUM = \App\Models\Pactuacao::cnaesUnidadeMovel();

        return view('company.estabelecimentos.create-unidade-movel', compact('municipios', 'cnaesPermitidosUM'));
    }

    /**
     * Resolve a competência (estadual/municipal/nao_sujeito_visa) de um município
     * de atuação a partir das atividades e respostas do questionário, seguindo a
     * mesma regra do sistema (qualquer atividade estadual prevalece). Retorna
     * também a flag usa_infovisa do município.
     */
    private function resolverCompetenciaUnidadeMovel(array $atividades, \App\Models\Municipio $municipio, $respostas1, $respostas2): array
    {
        $normalizar = function ($respostas) {
            $out = [];
            if (is_array($respostas)) {
                foreach ($respostas as $key => $val) {
                    $keyLimpa = preg_replace('/[^0-9]/', '', $key);
                    $out[$keyLimpa] = $val;
                    if ($key !== $keyLimpa) {
                        $out[$key] = $val;
                    }
                }
            }
            return $out;
        };

        $r1 = $normalizar(is_array($respostas1) ? $respostas1 : []);
        $r2 = $normalizar(is_array($respostas2) ? $respostas2 : []);

        $temEstadual = false;
        $temNaoSujeito = false;
        $todasNaoSujeitas = !empty($atividades);

        foreach ($atividades as $cnae) {
            $cnaeOriginal = is_array($cnae) ? ($cnae['codigo'] ?? '') : $cnae;
            if (in_array($cnaeOriginal, ['PROJ_ARQ', 'ANAL_ROT'])) {
                $cnaeLimpo = $cnaeOriginal;
            } else {
                $cnaeLimpo = preg_replace('/[^0-9]/', '', (string) $cnaeOriginal);
            }

            if ($cnaeLimpo === '') {
                continue;
            }

            $resp1 = $r1[$cnaeLimpo] ?? $r1[$cnaeOriginal] ?? null;
            $resp2 = $r2[$cnaeLimpo] ?? $r2[$cnaeOriginal] ?? null;

            $resultado = \App\Models\Pactuacao::verificarCompetenciaAvancada($cnaeLimpo, $municipio->nome, $resp1, $resp2);

            if (($resultado['competencia'] ?? null) === 'estadual') {
                $temEstadual = true;
            }
            if (($resultado['competencia'] ?? null) === 'nao_sujeito_visa') {
                $temNaoSujeito = true;
            } else {
                $todasNaoSujeitas = false;
            }
        }

        if ($temEstadual) {
            $competencia = 'estadual';
        } elseif ($temNaoSujeito && $todasNaoSujeitas) {
            $competencia = 'nao_sujeito_visa';
        } else {
            $competencia = 'municipal';
        }

        return [
            'competencia' => $competencia,
            'usa_infovisa' => (bool) $municipio->usa_infovisa,
        ];
    }

    /**
     * Atividades (CNAE) que não são sujeitas à Vigilância Sanitária pela pactuação:
     * não constam na pactuação ou foram respondidas "NÃO" na Tabela V. Retorna "código - descrição".
     */
    private function atividadesNaoSujeitasVisa(array $atividades, ?string $cidade, array $respostas1, array $respostas2): array
    {
        $municipio = $cidade ? trim(preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $cidade)) : null;
        $naoSujeitas = [];

        foreach ($atividades as $atividade) {
            $codigoOriginal = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            if (!$codigoOriginal || in_array($codigoOriginal, ['PROJ_ARQ', 'ANAL_ROT'], true)) {
                continue;
            }

            $codigo = preg_replace('/\D/', '', (string) $codigoOriginal);
            if ($codigo === '') {
                continue;
            }

            $resultado = \App\Models\Pactuacao::verificarCompetenciaAvancada(
                $codigo,
                $municipio,
                $respostas1[$codigo] ?? $respostas1[$codigoOriginal] ?? null,
                $respostas2[$codigo] ?? $respostas2[$codigoOriginal] ?? null
            );

            if (($resultado['competencia'] ?? null) === 'nao_sujeito_visa') {
                $descricao = is_array($atividade) ? ($atividade['descricao'] ?? $atividade['nome'] ?? '') : '';
                $naoSujeitas[] = trim($codigo . ($descricao ? ' - ' . $descricao : ''));
            }
        }

        return $naoSujeitas;
    }

    public function store(Request $request)
    {
        Log::info('Dados recebidos no store:', $request->all());
        
        $rules = [
            'tipo_pessoa' => 'required|in:juridica,fisica',
            'tipo_setor' => 'required|in:publico,privado',
            'nome_fantasia' => 'required|string|max:255',
            'endereco' => 'required|string|max:255',
            'numero' => 'required|string|max:20',
            'complemento' => 'nullable|string|max:100',
            'bairro' => 'required|string|max:100',
            'cidade' => 'required|string|max:100',
            'estado' => 'required|string|size:2',
            'cep' => 'required|string',
            'telefone' => 'required|string',
            'email' => 'required|email|max:255',
            'vinculo_usuario' => 'required|in:responsavel_legal,responsavel_tecnico,funcionario,contador',
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
            'atividades_exercidas' => 'nullable|string',
            'respostas_questionario' => 'nullable|string',
            'respostas_questionario2' => 'nullable|string',
            'is_unidade_movel' => 'nullable|boolean',
            'produtor_rural' => 'nullable|boolean',
        ];

        // PJ Unidade Móvel: cadastro itinerante (tipo_pessoa continua 'juridica')
        $isUnidadeMovel = $request->boolean('is_unidade_movel');

        // Pessoa Física que escolheu "Apenas Projeto Arquitetônico e/ou Análise de Rotulagem":
        // não informa CNAE (as atividades especiais são montadas mais abaixo).
        $pfApenasAtividadesEspeciais = $request->tipo_pessoa === 'fisica'
            && $request->input('apenas_atividades_especiais') === '1';

        if ($request->tipo_pessoa === 'juridica') {
            $rules['cnpj'] = 'required|string';
            $rules['razao_social'] = 'required|string|max:255';
        } else {
            $rules['cpf'] = 'required|string';
            $rules['nome_completo'] = 'required|string|max:255';
            $rules['rg'] = 'required|string|max:20';
            $rules['orgao_emissor'] = 'required|string|max:20';
            // Para pessoa física, atividades_exercidas é obrigatório (exceto no cadastro só de Projeto/Rotulagem)
            if (!$pfApenasAtividadesEspeciais) {
                $rules['atividades_exercidas'] = 'required|string';
            }
        }

        if ($isUnidadeMovel) {
            $rules['tipo_unidade_movel'] = 'required|string|max:100';
            $rules['municipios_atuacao'] = 'required|string';
            $rules['respostas_unidade_movel'] = 'nullable|string';
            $rules['atividades_exercidas'] = 'required|string';
        }

        $validated = $request->validate($rules, [
            'atividades_exercidas.required' => 'Você deve adicionar pelo menos uma Atividade Econômica (CNAE).',
        ]);

        // PJ Unidade Móvel: verifica se ao menos um CNAE contemplado está presente
        if ($isUnidadeMovel) {
            $atividadesEnviadas = json_decode($request->atividades_exercidas, true) ?: [];
            $codigosEnviados = collect($atividadesEnviadas)->pluck('codigo')->map(fn ($c) => preg_replace('/\D/', '', (string) $c))->all();
            $cnaesPermitidos = \App\Models\Pactuacao::cnaesUnidadeMovel();
            $temContemplado = !empty(array_intersect($codigosEnviados, $cnaesPermitidos));
            if (!$temContemplado) {
                return back()->withErrors([
                    'atividades_exercidas' => 'Nenhuma das atividades selecionadas está contemplada para cadastro de Unidade Móvel. Verifique os CNAEs aceitos.'
                ])->withInput();
            }
        }
        
        // Valida se há pelo menos uma atividade para pessoa física
        // (no cadastro só de Projeto/Rotulagem as atividades especiais são validadas mais abaixo)
        if ($request->tipo_pessoa === 'fisica' && !$pfApenasAtividadesEspeciais) {
            $atividades = json_decode($request->atividades_exercidas, true);
            if (empty($atividades) || !is_array($atividades) || count($atividades) === 0) {
                return back()->withErrors([
                    'atividades_exercidas' => 'Você deve adicionar pelo menos uma Atividade Econômica (CNAE).'
                ])->withInput();
            }

            // Restringe aos CNAEs liberados na pactuação (aba Pessoa Física).
            // Se nenhum CNAE estiver configurado, a restrição não é aplicada.
            $cnaesPermitidosPF = \App\Models\Pactuacao::cnaesPessoaFisica();
            if (!empty($cnaesPermitidosPF)) {
                $codigosEnviados = collect($atividades)
                    ->pluck('codigo')
                    ->map(fn ($c) => preg_replace('/\D/', '', (string) $c))
                    ->filter()
                    ->all();
                $naoPermitidos = array_diff($codigosEnviados, $cnaesPermitidosPF);
                if (!empty($naoPermitidos)) {
                    return back()->withErrors([
                        'atividades_exercidas' => 'Um ou mais CNAEs informados não estão disponíveis para cadastro de Pessoa Física: ' . implode(', ', $naoPermitidos) . '.'
                    ])->withInput();
                }
            }
        }
        
        // Limpa formatação do CNPJ/CPF antes de verificar unicidade
        if ($request->tipo_pessoa === 'juridica') {
            $cnpjLimpo = preg_replace('/\D/', '', $validated['cnpj']);
            if ($request->tipo_setor === 'privado') {
                $existe = Estabelecimento::where('cnpj', $cnpjLimpo)->exists();
                if ($existe) {
                    return back()->withErrors(['cnpj' => 'Este CNPJ já está cadastrado no sistema.'])->withInput();
                }
            }
            $validated['cnpj'] = $cnpjLimpo;
        } else {
            $cpfLimpo = preg_replace('/\D/', '', $validated['cpf']);
            $existe = Estabelecimento::where('cpf', $cpfLimpo)->exists();
            if ($existe) {
                return back()->withErrors(['cpf' => 'Este CPF já está cadastrado no sistema.'])->withInput();
            }
            $validated['cpf'] = $cpfLimpo;
        }

        if ($request->input('vinculo_usuario') === 'responsavel_tecnico') {
            $estabelecimentoTemporario = new Estabelecimento([
                'tipo_pessoa' => $validated['tipo_pessoa'],
                'razao_social' => $validated['razao_social'] ?? null,
                'nome_completo' => $validated['nome_completo'] ?? null,
                'nome_fantasia' => $validated['nome_fantasia'] ?? null,
            ]);

            $nomeResponsavelTecnico = auth('externo')->user()?->nome ?? '';
            $cpfResponsavelTecnico = auth('externo')->user()?->cpf ?? null;

            $mensagemBloqueioRt = app(ResponsavelTecnicoNomeGuard::class)
                ->obterMensagemDeBloqueio($nomeResponsavelTecnico, $cpfResponsavelTecnico, $estabelecimentoTemporario);

            if ($mensagemBloqueioRt) {
                throw ValidationException::withMessages([
                    'vinculo_usuario' => $mensagemBloqueioRt,
                ]);
            }
        }

        // Processa campos JSON
        if ($request->filled('cnaes_secundarios')) {
            $validated['cnaes_secundarios'] = json_decode($request->cnaes_secundarios, true);
        }
        if ($request->filled('qsa')) {
            $validated['qsa'] = json_decode($request->qsa, true);
        }
        if ($request->filled('atividades_exercidas')) {
            $validated['atividades_exercidas'] = json_decode($request->atividades_exercidas, true);
        }
        if ($request->filled('respostas_questionario')) {
            $validated['respostas_questionario'] = json_decode($request->respostas_questionario, true);
        }
        if ($request->filled('respostas_questionario2')) {
            $validated['respostas_questionario2'] = json_decode($request->respostas_questionario2, true);
        }

        // Atividades que não são da Vigilância Sanitária (fora da pactuação ou "NÃO" na Tabela V)
        // não podem ser cadastradas — vale para público e privado.
        if (!$isUnidadeMovel) {
            $naoSujeitas = $this->atividadesNaoSujeitasVisa(
                $validated['atividades_exercidas'] ?? [],
                $request->input('cidade'),
                $validated['respostas_questionario'] ?? [],
                $validated['respostas_questionario2'] ?? []
            );

            if (!empty($naoSujeitas)) {
                $totalAtividades = collect($validated['atividades_exercidas'] ?? [])
                    ->reject(fn ($a) => in_array(is_array($a) ? ($a['codigo'] ?? null) : $a, ['PROJ_ARQ', 'ANAL_ROT'], true))
                    ->count();

                return back()->withErrors([
                    'atividades_exercidas' => count($naoSujeitas) >= $totalAtividades
                        ? 'As atividades selecionadas não são de competência da Vigilância Sanitária. Este estabelecimento não precisa de cadastro/licença sanitária.'
                        : 'Estas atividades não são de competência da Vigilância Sanitária e devem ser desmarcadas: ' . implode('; ', $naoSujeitas) . '.',
                ])->withInput();
            }
        }

        // PJ Unidade Móvel: decodifica respostas P1/P2 e a tabela de municípios de atuação (P4)
        $municipiosAtuacaoInput = [];
        if ($isUnidadeMovel) {
            if ($request->filled('respostas_unidade_movel')) {
                $validated['respostas_unidade_movel'] = json_decode($request->respostas_unidade_movel, true);
            }

            // municipios_atuacao é JSON e NÃO é coluna do estabelecimento — remove de $validated
            $municipiosAtuacaoInput = json_decode($request->input('municipios_atuacao', '[]'), true) ?: [];
            unset($validated['municipios_atuacao']);

            if (empty($municipiosAtuacaoInput) || !is_array($municipiosAtuacaoInput)) {
                return back()->withErrors([
                    'municipios_atuacao' => 'Você deve adicionar pelo menos um município de atuação.'
                ])->withInput();
            }

            foreach ($municipiosAtuacaoInput as $linha) {
                if (empty($linha['municipio_id']) || empty($linha['data_inicio']) || empty($linha['data_fim'])) {
                    return back()->withErrors([
                        'municipios_atuacao' => 'Para cada município de atuação informe o município, a data de início e a data de fim.'
                    ])->withInput();
                }
            }

            $validated['is_unidade_movel'] = true;
            $validated['status_unidade_movel'] = 'pendente';
        }

        // ========================================
        // PROCESSAMENTO: Atividades Especiais (Projeto Arquitetônico / Análise de Rotulagem)
        // ========================================
        $apenasAtividadesEspeciais = $request->input('apenas_atividades_especiais') === '1';
        
        if ($apenasAtividadesEspeciais) {
            $atividadesEspeciais = [];
            
            if ($request->input('atividade_especial_projeto_arq') === '1') {
                $atividadesEspeciais[] = [
                    'codigo' => 'PROJ_ARQ',
                    'descricao' => 'Projeto Arquitetônico - Análise de projeto arquitetônico para adequação sanitária',
                    'especial' => true
                ];
            }
            
            if ($request->input('atividade_especial_rotulagem') === '1') {
                $atividadesEspeciais[] = [
                    'codigo' => 'ANAL_ROT',
                    'descricao' => 'Análise de Rotulagem - Análise e aprovação de rótulos de produtos',
                    'especial' => true
                ];
            }
            
            // Valida se pelo menos uma atividade especial foi selecionada
            if (empty($atividadesEspeciais)) {
                return back()->withErrors([
                    'atividades_exercidas' => 'Você deve selecionar pelo menos uma atividade especial (Projeto Arquitetônico ou Análise de Rotulagem).'
                ])->withInput();
            }
            
            // Substitui as atividades exercidas pelas atividades especiais
            $validated['atividades_exercidas'] = $atividadesEspeciais;
            
            Log::info('Cadastro com atividades especiais:', [
                'atividades' => $atividadesEspeciais
            ]);
        }
        // ========================================

        // Produtor Rural: identificação apenas para Pessoa Física
        $validated['produtor_rural'] = $request->tipo_pessoa === 'fisica' && $request->boolean('produtor_rural');

        // Usuário externo - sempre pendente
        $validated['usuario_externo_id'] = auth('externo')->id();
        $validated['status'] = 'pendente';
        $validated['ativo'] = true;

        // Define o município
        $validated['municipio'] = $validated['cidade'];
        $nomeMunicipio = $validated['cidade'];
        $codigoIbge = $validated['codigo_municipio_ibge'] ?? null;
        
        if ($nomeMunicipio) {
            $nomeMunicipio = preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $nomeMunicipio);
            $municipioId = \App\Helpers\MunicipioHelper::normalizarEObterIdPorNome($nomeMunicipio, $codigoIbge);
            if ($municipioId) {
                $validated['municipio_id'] = $municipioId;
                $validated['municipio'] = $nomeMunicipio;
            }
        }

        // ========================================
        // VALIDAÇÃO: Município usa InfoVISA?
        // ========================================
        // Cria um estabelecimento temporário (não salvo) para verificar competência
        $estabelecimentoTemp = new Estabelecimento($validated);
        
        // Log para debug da competência
        Log::info('Verificação de competência no cadastro:', [
            'municipio' => $validated['cidade'] ?? null,
            'atividades_exercidas' => $validated['atividades_exercidas'] ?? [],
            'respostas_questionario' => $validated['respostas_questionario'] ?? [],
            'todas_atividades' => $estabelecimentoTemp->getTodasAtividades(),
            'is_competencia_estadual' => $estabelecimentoTemp->isCompetenciaEstadual(),
            'is_competencia_municipal' => $estabelecimentoTemp->isCompetenciaMunicipal(),
        ]);
        
        // Se for de competência MUNICIPAL, verifica se o município usa o InfoVISA.
        // PJ Unidade Móvel é exceção: a sede fica em outro estado e a verificação
        // de competência/usa_infovisa é feita por município de atuação (P4), não pela sede.
        if (!$isUnidadeMovel && $estabelecimentoTemp->isCompetenciaMunicipal()) {
            $municipio = null;
            if (isset($validated['municipio_id'])) {
                $municipio = \App\Models\Municipio::find($validated['municipio_id']);
            }
            
            if (!$municipio || !$municipio->usa_infovisa) {
                $nomeMunicipioMsg = $municipio ? $municipio->nome : ($validated['municipio'] ?? 'seu município');
                return back()->withErrors([
                    'cidade' => "O município de {$nomeMunicipioMsg} ainda não utiliza o InfoVISA. " .
                               "Estabelecimentos de competência municipal deste município não podem se cadastrar no momento. " .
                               "Entre em contato com a Vigilância Sanitária do seu município para mais informações."
                ])->withInput();
            }
        }
        // ========================================

        // Remove formatação (CEP e telefone - CNPJ/CPF já foram limpos acima)
        if (isset($validated['cep'])) {
            $validated['cep'] = preg_replace('/\D/', '', $validated['cep']);
        }
        if (isset($validated['telefone'])) {
            $validated['telefone'] = preg_replace('/\D/', '', $validated['telefone']);
        }

        try {
            $estabelecimento = Estabelecimento::create($validated);

            // PJ Unidade Móvel: grava os municípios de atuação (P4) com a competência
            // recalculada no servidor (não confia no valor enviado pelo front).
            if ($isUnidadeMovel && !empty($municipiosAtuacaoInput)) {
                $atividadesUM = is_array($validated['atividades_exercidas'] ?? null) ? $validated['atividades_exercidas'] : [];
                $respostas1UM = $validated['respostas_questionario'] ?? [];
                $respostas2UM = $validated['respostas_questionario2'] ?? [];

                foreach ($municipiosAtuacaoInput as $linha) {
                    $municipioModel = \App\Models\Municipio::find($linha['municipio_id']);
                    if (!$municipioModel) {
                        continue;
                    }

                    $resolucao = $this->resolverCompetenciaUnidadeMovel($atividadesUM, $municipioModel, $respostas1UM, $respostas2UM);

                    $estabelecimento->municipiosAtuacao()->create([
                        'municipio_id' => $municipioModel->id,
                        'municipio_nome' => $municipioModel->nome,
                        'data_inicio' => $linha['data_inicio'],
                        'data_fim' => $linha['data_fim'],
                        'competencia' => $resolucao['competencia'],
                        'usa_infovisa' => $resolucao['usa_infovisa'],
                        'status' => 'pendente',
                    ]);
                }
            }

            // Vincula o usuário criador ao estabelecimento na tabela pivot
            $vinculoUsuario = $request->input('vinculo_usuario');
            if ($vinculoUsuario) {
                $estabelecimento->usuariosVinculados()->attach(auth('externo')->id(), [
                    'tipo_vinculo' => $vinculoUsuario,
                    'observacao' => 'Vínculo informado no cadastro do estabelecimento',
                    'vinculado_por' => null,
                ]);
                
                // Se for responsável legal ou técnico, cria também na tabela de responsáveis
                if (in_array($vinculoUsuario, ['responsavel_legal', 'responsavel_tecnico'])) {
                    $usuarioExterno = auth('externo')->user();
                    $tipoVinculo = $vinculoUsuario === 'responsavel_legal' ? 'legal' : 'tecnico';
                    
                    // Busca ou cria o responsável com base no CPF do usuário
                    $responsavel = \App\Models\Responsavel::where('cpf', $usuarioExterno->cpf)->first();
                    
                    if (!$responsavel) {
                        $responsavel = \App\Models\Responsavel::create([
                            'cpf' => $usuarioExterno->cpf,
                            'tipo' => $tipoVinculo,
                            'nome' => $usuarioExterno->nome,
                            'email' => $usuarioExterno->email,
                            'telefone' => $usuarioExterno->telefone,
                        ]);
                    }
                    
                    // Vincula o responsável ao estabelecimento (documentos serão adicionados depois)
                    $estabelecimento->responsaveis()->attach($responsavel->id, [
                        'tipo_vinculo' => $tipoVinculo,
                        'ativo' => true
                    ]);

                    // Auto-criar usuário externo e vincular ao estabelecimento
                    \App\Services\ResponsavelUsuarioService::vincularResponsavelComoUsuario($responsavel, $estabelecimento, $tipoVinculo);
                }
            }

            return redirect()->route('company.estabelecimentos.show', $estabelecimento->id)
                ->with('success', 'Estabelecimento cadastrado com sucesso! Aguarde a aprovação da Vigilância Sanitária.');
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Erro ao cadastrar estabelecimento (DB): ' . $e->getMessage(), [
                'dados' => $validated,
                'trace' => $e->getTraceAsString()
            ]);

            // Trata erro de violação de unicidade (código 23505 no PostgreSQL)
            if ($e->getCode() === '23505') {
                return back()->withErrors(['cnpj' => 'Este estabelecimento já está cadastrado no sistema. Verifique o CNPJ/CPF e o Nome Fantasia informados.'])->withInput();
            }

            return back()->withErrors(['error' => 'Erro ao cadastrar estabelecimento. Tente novamente ou entre em contato com o suporte.'])->withInput();
        } catch (\Exception $e) {
            Log::error('Erro ao cadastrar estabelecimento: ' . $e->getMessage(), [
                'dados' => $validated,
                'trace' => $e->getTraceAsString()
            ]);
            
            return back()->withErrors(['error' => 'Erro ao cadastrar estabelecimento. Tente novamente ou entre em contato com o suporte.'])->withInput();
        }
    }

    /**
     * Formulário de edição do estabelecimento
     */
    public function edit($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);
        
        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }
        
        return view('company.estabelecimentos.edit', compact('estabelecimento'));
    }

    /**
     * Atualiza os dados do estabelecimento
     */
    public function update(Request $request, $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        $rules = [
            'nome_fantasia' => 'required|string|max:255',
            'telefone' => 'required|string',
            'email' => 'required|email|max:255',
            'cep' => 'nullable|string',
            'endereco' => 'nullable|string|max:255',
            'numero' => 'nullable|string|max:20',
            'complemento' => 'nullable|string|max:100',
            'bairro' => 'nullable|string|max:100',
        ];

        // Campos específicos por tipo de pessoa
        if ($estabelecimento->tipo_pessoa === 'juridica') {
            $rules['razao_social'] = 'nullable|string|max:255';
        } else {
            $rules['nome_completo'] = 'nullable|string|max:255';
        }

        $validated = $request->validate($rules);

        // Remove formatação do telefone e CEP
        if (isset($validated['telefone'])) {
            $validated['telefone'] = preg_replace('/\D/', '', $validated['telefone']);
        }
        if (isset($validated['cep'])) {
            $validated['cep'] = preg_replace('/\D/', '', $validated['cep']);
        }

        $estabelecimento->update($validated);

        return redirect()->route('company.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Dados atualizados com sucesso!');
    }

    /**
     * Formulário de edição de atividades
     */
    public function editAtividades($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->findOrFail($id);
        
        return view('company.estabelecimentos.atividades', compact('estabelecimento'));
    }

    /**
     * Atualiza as atividades exercidas
     */
    public function updateAtividades(Request $request, $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->findOrFail($id);

        // Bloqueia edição se estabelecimento já foi aprovado
        if ($estabelecimento->status === 'aprovado') {
            return redirect()->route('company.estabelecimentos.atividades.edit', $estabelecimento->id)
                ->with('error', 'Não é possível alterar atividades de um estabelecimento já aprovado. Entre em contato com a Vigilância Sanitária.');
        }

        $atividades = $request->input('atividades_exercidas', []);
        
        if (is_string($atividades)) {
            $atividades = json_decode($atividades, true) ?? [];
        }

        $estabelecimento->update(['atividades_exercidas' => $atividades]);

        return redirect()->route('company.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Atividades atualizadas com sucesso!');
    }

    /**
     * Lista de responsáveis do estabelecimento
     */
    public function responsaveisIndex($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->with(['responsaveisLegais', 'responsaveisTecnicos'])
            ->findOrFail($id);
        
        return view('company.estabelecimentos.responsaveis.index', compact('estabelecimento'));
    }

    /**
     * Formulário para adicionar responsável
     */
    public function responsaveisCreate($id, $tipo = 'legal')
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);
        
        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }
        
        // Valida o tipo
        if (!in_array($tipo, ['legal', 'tecnico'])) {
            $tipo = 'legal';
        }
        
        return view('company.estabelecimentos.responsaveis.create', compact('estabelecimento', 'tipo'));
    }

    /**
     * Salva novo responsável
     */
    public function responsaveisStore(Request $request, $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        $cpfInformado = preg_replace('/\D/', '', (string) $request->input('cpf'));
        $tipoVinculoInformado = $request->input('tipo_vinculo', 'legal');
        $responsavelExistente = $cpfInformado
            ? \App\Models\Responsavel::where('cpf', $cpfInformado)->where('tipo', $tipoVinculoInformado)->first()
            : null;

        $rules = [
            'nome' => 'required|string|max:255',
            'cpf' => 'required|string',
            'tipo_vinculo' => 'required|in:legal,tecnico',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:20',
        ];

        // Campos específicos para responsável técnico
        if ($request->tipo_vinculo === 'tecnico') {
            $rules['conselho'] = 'required|string|max:100';
            $rules['numero_registro'] = 'required|string|max:50';
            $carteirinhaJaCadastrada = !empty($responsavelExistente?->carteirinha_conselho);
            $rules['carteirinha_conselho'] = ($carteirinhaJaCadastrada ? 'nullable' : 'required') . '|file|mimes:pdf,jpg,jpeg,png|max:5120';
        } else {
            $rules['conselho'] = 'nullable|string|max:100';
            $rules['numero_registro'] = 'nullable|string|max:50';
            $documentoJaCadastrado = !empty($responsavelExistente?->documento_identificacao);
            $rules['documento_identificacao'] = ($documentoJaCadastrado ? 'nullable' : 'required') . '|file|mimes:pdf,jpg,jpeg,png|max:5120';
        }

        $validated = $request->validate($rules);

        $mensagemBloqueioRt = app(ResponsavelTecnicoNomeGuard::class)
            ->obterMensagemDeBloqueio(
                $validated['nome'],
                $validated['cpf'],
                $validated['tipo_vinculo'] === 'tecnico' ? $estabelecimento : null,
            );

        if ($mensagemBloqueioRt) {
            throw ValidationException::withMessages([
                'nome' => $mensagemBloqueioRt,
            ]);
        }

        // Limpa formatação
        $validated['cpf'] = preg_replace('/\D/', '', $validated['cpf']);
        if (isset($validated['telefone'])) {
            $validated['telefone'] = preg_replace('/\D/', '', $validated['telefone']);
        }

        // Upload de arquivos
        $carteirinhaPath = null;
        $documentoPath = null;
        
        if ($request->hasFile('carteirinha_conselho')) {
            $carteirinhaPath = $request->file('carteirinha_conselho')->store('responsaveis/carteirinhas', 'public');
        }
        
        if ($request->hasFile('documento_identificacao')) {
            $documentoPath = $request->file('documento_identificacao')->store('responsaveis/documentos', 'public');
        }

        // Busca ou cria o responsável (por CPF + tipo)
        $responsavel = \App\Models\Responsavel::where('cpf', $validated['cpf'])
            ->where('tipo', $validated['tipo_vinculo'])
            ->first();
        
        if ($responsavel) {
            // Atualiza dados se o responsável já existia com o mesmo tipo
            $updateData = [
                'nome' => $validated['nome'],
                'email' => $validated['email'] ?? null,
                'telefone' => $validated['telefone'] ?? null,
            ];
            
            if (isset($validated['conselho'])) {
                $updateData['conselho'] = $validated['conselho'];
            }
            if (isset($validated['numero_registro'])) {
                $updateData['numero_registro_conselho'] = $validated['numero_registro'];
            }
            if ($carteirinhaPath) {
                $updateData['carteirinha_conselho'] = $carteirinhaPath;
            }
            if ($documentoPath) {
                $updateData['documento_identificacao'] = $documentoPath;
            }
            
            $responsavel->update($updateData);
        } else {
            // Cria novo responsável com o tipo correto
            $responsavel = \App\Models\Responsavel::create([
                'cpf' => $validated['cpf'],
                'tipo' => $validated['tipo_vinculo'],
                'nome' => $validated['nome'],
                'email' => $validated['email'] ?? null,
                'telefone' => $validated['telefone'] ?? null,
                'conselho' => $validated['conselho'] ?? null,
                'numero_registro_conselho' => $validated['numero_registro'] ?? null,
                'carteirinha_conselho' => $carteirinhaPath,
                'documento_identificacao' => $documentoPath,
            ]);
        }

        // Verifica se já existe vínculo com este tipo
        $vinculoExistente = $estabelecimento->responsaveis()
            ->where('responsavel_id', $responsavel->id)
            ->wherePivot('tipo_vinculo', $validated['tipo_vinculo'])
            ->exists();

        if ($vinculoExistente) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('warning', 'Este responsável já está vinculado como ' . ($validated['tipo_vinculo'] === 'legal' ? 'Responsável Legal' : 'Responsável Técnico') . '.');
        }

        // Usa attach para permitir múltiplos vínculos (legal e técnico) para a mesma pessoa
        $estabelecimento->responsaveis()->attach($responsavel->id, [
            'tipo_vinculo' => $validated['tipo_vinculo'],
            'ativo' => true
        ]);

        // Auto-criar usuário externo e vincular ao estabelecimento
        \App\Services\ResponsavelUsuarioService::vincularResponsavelComoUsuario($responsavel, $estabelecimento, $validated['tipo_vinculo']);

        return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
            ->with('success', 'Responsável adicionado com sucesso!');
    }

    /**
     * Remove responsável
     */
    public function responsaveisDestroy($id, $responsavelId)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        // Verifica se o responsável está vinculado ao estabelecimento
        $vinculo = $estabelecimento->responsaveis()
            ->where('responsavel_id', $responsavelId)
            ->wherePivot('ativo', true)
            ->first();

        if (!$vinculo) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Responsável não encontrado ou já removido deste estabelecimento.');
        }

        // Desativa o vínculo do responsável com o estabelecimento
        $estabelecimento->responsaveis()->updateExistingPivot($responsavelId, [
            'ativo' => false,
        ]);

        return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
            ->with('success', 'Responsável removido com sucesso.');
    }

    /**
     * Formulário de edição de responsável
     */
    public function responsaveisEdit($id, $responsavelId, $tipo = 'legal')
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);
        
        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }
        
        $responsavel = \App\Models\Responsavel::findOrFail($responsavelId);
        
        // Verifica se o responsável está vinculado ao estabelecimento
        if (!$estabelecimento->responsaveis()->where('responsavel_id', $responsavelId)->exists()) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Responsável não encontrado para este estabelecimento.');
        }
        
        return view('company.estabelecimentos.responsaveis.edit', compact('estabelecimento', 'responsavel', 'tipo'));
    }

    /**
     * Atualiza responsável (principalmente para completar documentos)
     */
    public function responsaveisUpdate(Request $request, $id, $responsavelId)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);
        
        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }
        
        $responsavel = \App\Models\Responsavel::findOrFail($responsavelId);
        
        // Verifica se o responsável está vinculado ao estabelecimento
        if (!$estabelecimento->responsaveis()->where('responsavel_id', $responsavelId)->exists()) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Responsável não encontrado para este estabelecimento.');
        }
        
        // Guarda se o documento estava pendente antes da atualização
        $tipo = $request->input('tipo_vinculo', 'legal');
        $documentoEstaPendente = ($tipo === 'legal' && empty($responsavel->documento_identificacao)) ||
                                  ($tipo === 'tecnico' && empty($responsavel->carteirinha_conselho));
        
        $rules = [
            'nome' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'telefone' => 'nullable|string|max:20',
        ];

        // Campos específicos para responsável técnico
        if ($tipo === 'tecnico') {
            $rules['conselho'] = 'required|string|max:100';
            $rules['numero_registro'] = 'required|string|max:50';
            $rules['carteirinha_conselho'] = (empty($responsavel->carteirinha_conselho) ? 'required' : 'nullable') . '|file|mimes:pdf,jpg,jpeg,png|max:5120';
        } else {
            $rules['documento_identificacao'] = (empty($responsavel->documento_identificacao) ? 'required' : 'nullable') . '|file|mimes:pdf,jpg,jpeg,png|max:5120';
        }

        $validated = $request->validate($rules);

        $mensagemBloqueioRt = app(ResponsavelTecnicoNomeGuard::class)
            ->obterMensagemDeBloqueio(
                $validated['nome'],
                $responsavel->cpf,
                $tipo === 'tecnico' ? $estabelecimento : null,
            );

        if ($mensagemBloqueioRt) {
            throw ValidationException::withMessages([
                'nome' => $mensagemBloqueioRt,
            ]);
        }

        // Limpa formatação
        if (isset($validated['telefone'])) {
            $validated['telefone'] = preg_replace('/\D/', '', $validated['telefone']);
        }

        // Upload de arquivos
        $documentoFoiEnviado = false;
        if ($request->hasFile('carteirinha_conselho')) {
            $carteirinhaPath = $request->file('carteirinha_conselho')->store('responsaveis/carteirinhas', 'public');
            $validated['carteirinha_conselho'] = $carteirinhaPath;
            $documentoFoiEnviado = true;
        }
        
        if ($request->hasFile('documento_identificacao')) {
            $documentoPath = $request->file('documento_identificacao')->store('responsaveis/documentos', 'public');
            $validated['documento_identificacao'] = $documentoPath;
            $documentoFoiEnviado = true;
        }

        // Atualiza dados do responsável
        $updateData = [
            'nome' => $validated['nome'],
            'email' => $validated['email'] ?? null,
            'telefone' => $validated['telefone'] ?? null,
        ];
        
        if (isset($validated['conselho'])) {
            $updateData['conselho'] = $validated['conselho'];
        }
        if (isset($validated['numero_registro'])) {
            $updateData['numero_registro_conselho'] = $validated['numero_registro'];
        }
        if (isset($validated['carteirinha_conselho'])) {
            $updateData['carteirinha_conselho'] = $validated['carteirinha_conselho'];
        }
        if (isset($validated['documento_identificacao'])) {
            $updateData['documento_identificacao'] = $validated['documento_identificacao'];
        }

        // Sempre atualiza o registro atual primeiro
        $responsavel->update($updateData);

        // Sincroniza dados básicos em todos os registros do mesmo CPF
        // (cenários legados podem ter mais de um registro por CPF)
        if (!empty($responsavel->cpf)) {
            $dadosBasicos = [
                'nome' => $updateData['nome'],
                'email' => $updateData['email'],
                'telefone' => $updateData['telefone'],
            ];

            \App\Models\Responsavel::where('cpf', $responsavel->cpf)->update($dadosBasicos);
        }

        // Campos específicos de tipo/documento: atualiza apenas o tipo correspondente
        if ($tipo === 'tecnico' && !empty($responsavel->cpf)) {
            $dadosTecnico = [];
            if (isset($updateData['conselho'])) {
                $dadosTecnico['conselho'] = $updateData['conselho'];
            }
            if (isset($updateData['numero_registro_conselho'])) {
                $dadosTecnico['numero_registro_conselho'] = $updateData['numero_registro_conselho'];
            }
            if (isset($updateData['carteirinha_conselho'])) {
                $dadosTecnico['carteirinha_conselho'] = $updateData['carteirinha_conselho'];
            }

            if (!empty($dadosTecnico)) {
                \App\Models\Responsavel::where('cpf', $responsavel->cpf)
                    ->where('tipo', 'tecnico')
                    ->update($dadosTecnico);
            }
        } elseif (!empty($responsavel->cpf)) {
            if (isset($updateData['documento_identificacao'])) {
                \App\Models\Responsavel::where('cpf', $responsavel->cpf)
                    ->where('tipo', 'legal')
                    ->update(['documento_identificacao' => $updateData['documento_identificacao']]);
            }
        }

        // Recarrega para manter fluxo atual consistente
        $responsavel->refresh();

        // Se o documento estava pendente e foi enviado agora, redireciona para criação de processo
        if ($documentoEstaPendente && $documentoFoiEnviado && $tipo === 'legal') {
            return redirect()->route('company.estabelecimentos.processos.create', $estabelecimento->id)
                ->with('success', 'Documento do Responsável Legal cadastrado com sucesso! Agora você pode abrir um processo.');
        }

        return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
            ->with('success', 'Responsável atualizado com sucesso!');
    }

    /**
     * Lista de usuários vinculados ao estabelecimento
     */
    public function usuariosIndex($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->with(['usuariosVinculados', 'usuarioExterno'])
            ->findOrFail($id);
        
        // Inclui o usuário criador na lista (se não estiver já vinculado)
        $criador = $estabelecimento->usuarioExterno;
        $criadorVinculado = $estabelecimento->usuariosVinculados->contains('id', $criador?->id);
        
        // Verifica se o usuário atual é visualizador
        $ehVisualizador = $estabelecimento->usuarioEhVisualizador();
        $usuarioAtualId = auth('externo')->id();
        
        return view('company.estabelecimentos.usuarios.index', compact('estabelecimento', 'criador', 'criadorVinculado', 'ehVisualizador', 'usuarioAtualId'));
    }

    /**
     * Vincula um usuário ao estabelecimento
     */
    public function usuariosStore(Request $request, $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        $validated = $request->validate([
            'email' => 'required|email',
            'tipo_vinculo' => 'required|string|max:50',
            'nivel_acesso' => 'required|in:gestor,visualizador',
            'observacao' => 'nullable|string|max:255',
        ]);

        $usuario = \App\Models\UsuarioExterno::where('email', $validated['email'])->first();

        if (!$usuario) {
            return back()->withErrors(['email' => 'Usuário não encontrado com este e-mail.'])->withInput();
        }

        if ($usuario->id === auth('externo')->id()) {
            return back()->withErrors(['email' => 'Você não pode vincular a si mesmo.'])->withInput();
        }

        $estabelecimento->usuariosVinculados()->syncWithoutDetaching([
            $usuario->id => [
                'tipo_vinculo' => $validated['tipo_vinculo'],
                'nivel_acesso' => $validated['nivel_acesso'],
                'observacao' => $validated['observacao'] ?? null,
                'vinculado_por' => null, // Usuário externo não tem vinculado_por
            ]
        ]);

        return redirect()->route('company.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Usuário vinculado com sucesso!');
    }

    /**
     * Remove vínculo de usuário
     */
    public function usuariosDestroy($id, $usuarioId)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        // Bloqueia exclusão do usuário criador do estabelecimento
        if ($estabelecimento->usuario_externo_id == $usuarioId) {
            return redirect()->route('company.estabelecimentos.usuarios.index', $estabelecimento->id)
                ->with('error', 'Não é possível desvincular o usuário que cadastrou o estabelecimento. Apenas um administrador pode realizar esta ação.');
        }

        $estabelecimento->usuariosVinculados()->detach($usuarioId);

        return redirect()->route('company.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Vínculo removido com sucesso!');
    }

    /**
     * Atualiza o nível de acesso de um usuário vinculado
     */
    public function usuariosUpdate(Request $request, $id, $usuarioId)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        $validated = $request->validate([
            'nivel_acesso' => 'required|in:gestor,visualizador',
        ]);

        // Não permite alterar o nível de acesso do criador do estabelecimento
        if ($estabelecimento->usuario_externo_id == $usuarioId) {
            return redirect()->route('company.estabelecimentos.usuarios.index', $estabelecimento->id)
                ->with('error', 'Não é possível alterar o nível de acesso do criador do estabelecimento.');
        }

        $estabelecimento->usuariosVinculados()->updateExistingPivot($usuarioId, [
            'nivel_acesso' => $validated['nivel_acesso'],
        ]);

        return redirect()->route('company.estabelecimentos.usuarios.index', $estabelecimento->id)
            ->with('success', 'Nível de acesso atualizado com sucesso!');
    }

    /**
     * Lista de processos do estabelecimento
     */
    public function processosIndex($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->with(['processos' => function($q) {
                $q->whereHas('tipoProcesso', fn($tp) => $tp->where('usuario_externo_pode_visualizar', true));
            }, 'processos.tipoProcesso'])
            ->findOrFail($id);
        
        return view('company.estabelecimentos.processos.index', compact('estabelecimento'));
    }

    /**
     * Formulário para abrir novo processo
     */
    public function processosCreate($id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->with(['responsaveisLegais', 'responsaveisTecnicos'])
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        // Verifica se tem responsável legal cadastrado
        if ($estabelecimento->responsaveisLegais->isEmpty()) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Para abrir um processo, é obrigatório ter pelo menos um Responsável Legal cadastrado. Por favor, cadastre o responsável legal primeiro.');
        }

        // Verifica se o responsável legal tem documento de identificação cadastrado
        $responsavelLegalSemDocumento = $estabelecimento->responsaveisLegais->first(function ($responsavel) {
            return empty($responsavel->documento_identificacao);
        });
        
        if ($responsavelLegalSemDocumento) {
            return redirect()->route('company.estabelecimentos.responsaveis.edit', [
                $estabelecimento->id, 
                $responsavelLegalSemDocumento->id, 
                'legal'
            ])->with('error', 'Para abrir um processo, é obrigatório que o Responsável Legal tenha o documento de identificação cadastrado. Por favor, faça o upload do documento.');
        }

        // ========================================
        // VERIFICA EQUIPAMENTOS DE RADIAÇÃO OBRIGATÓRIOS
        // ========================================
        // Verifica se o estabelecimento tem atividades que exigem equipamentos de radiação
        $exigeEquipamentos = \App\Models\AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentos($estabelecimento);
        $temEquipamentosCadastrados = \App\Models\EquipamentoRadiacao::where('estabelecimento_id', $estabelecimento->id)->exists();
        $declarouSemEquipamentos = (bool) $estabelecimento->declaracao_sem_equipamentos_imagem;
        
        // Guarda info para usar no filtro de tipos de processo
        $equipamentosInfo = [
            'exige' => $exigeEquipamentos,
            'tem_cadastrados' => $temEquipamentosCadastrados,
            'declarou_sem' => $declarouSemEquipamentos,
            // Considera OK se: tem equipamentos OU declarou que não tem
            'ok' => $temEquipamentosCadastrados || $declarouSemEquipamentos,
        ];
        // ========================================

        // ========================================
        // VERIFICAÇÃO: Responsável Técnico Obrigatório por Atividade
        // ========================================
        $precisaCadastrarResponsavelTecnico = $estabelecimento->precisaCadastrarResponsavelTecnicoPorAtividade();
        // ========================================

        // VERIFICA SE TEM APENAS ATIVIDADES ESPECIAIS
        // ========================================
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        $apenasAtividadesEspeciais = false;
        $atividadesEspeciaisCodigos = [];
        
        if (!empty($atividadesExercidas)) {
            $apenasAtividadesEspeciais = true;
            foreach ($atividadesExercidas as $atividade) {
                $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                $especial = is_array($atividade) ? ($atividade['especial'] ?? false) : false;
                
                if ($codigo) {
                    // Se encontrar alguma atividade que não seja especial, não é "apenas especiais"
                    if (!in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT']) && !$especial) {
                        $apenasAtividadesEspeciais = false;
                    }
                    
                    // Guarda os códigos das atividades especiais
                    if (in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT']) || $especial) {
                        $atividadesEspeciaisCodigos[] = $codigo;
                    }
                }
            }
        }
        // ========================================

        // Busca tipos de processo disponíveis para usuários externos
        $tiposProcessoBase = \App\Models\TipoProcesso::where('ativo', true)
            ->where('usuario_externo_pode_abrir', true)
            ->orderBy('ordem')
            ->orderBy('nome')
            ->get()
            ->filter(fn ($tipo) => $tipo->disponivelParaEstabelecimento($estabelecimento))
            ->values();

        // Filtra tipos de processo baseado nas regras de anual/único E atividades especiais
        $anoAtual = date('Y');
        $tiposProcesso = $tiposProcessoBase->filter(function($tipo) use ($estabelecimento, $anoAtual, $apenasAtividadesEspeciais, $atividadesEspeciaisCodigos, $equipamentosInfo) {
            // ========================================
            // FILTRO POR ATIVIDADES ESPECIAIS
            // ========================================
            if ($apenasAtividadesEspeciais) {
                // Se tem apenas atividades especiais, só pode abrir processos vinculados a elas
                $codigosPermitidos = [];
                
                if (in_array('PROJ_ARQ', $atividadesEspeciaisCodigos)) {
                    $codigosPermitidos[] = 'projeto_arquitetonico';
                }
                if (in_array('ANAL_ROT', $atividadesEspeciaisCodigos)) {
                    $codigosPermitidos[] = 'analise_rotulagem';
                }
                
                // Se o tipo de processo não está na lista de permitidos, bloqueia
                if (!in_array($tipo->codigo, $codigosPermitidos)) {
                    return false;
                }
            }
            // ========================================
            
            // ========================================
            // FILTRO POR EQUIPAMENTOS DE RADIAÇÃO
            // ========================================
            // Não bloqueia mais aqui, só marca como bloqueado para mostrar na view
            // A verificação será feita na view para exibir mensagem informativa
            // ========================================
            
            // Se é único por estabelecimento, verifica se já existe algum processo deste tipo
            if ($tipo->unico_por_estabelecimento) {
                $existeProcesso = \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', $tipo->codigo)
                    ->exists();
                
                if ($existeProcesso) {
                    return false; // Não pode abrir, já existe
                }
            }
            // Se é anual, verifica se já existe processo deste tipo no ano atual
            elseif ($tipo->anual) {
                $existeNoAno = \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', $tipo->codigo)
                    ->where('ano', $anoAtual)
                    ->exists();
                
                if ($existeNoAno) {
                    return false; // Não pode abrir, já existe neste ano
                }
            }
            
            // Pode abrir
            return true;
        });

        // Busca documentos obrigatórios baseados nas atividades exercidas
        $documentosObrigatorios = $this->buscarDocumentosObrigatorios($estabelecimento, $tiposProcesso);
        
        // Busca tipos bloqueados para mostrar mensagem informativa
        $tiposBloqueados = $tiposProcessoBase->filter(function($tipo) use ($estabelecimento, $anoAtual) {
            if ($tipo->unico_por_estabelecimento) {
                return \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', $tipo->codigo)
                    ->exists();
            }
            if ($tipo->anual) {
                return \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', $tipo->codigo)
                    ->where('ano', $anoAtual)
                    ->exists();
            }
            return false;
        });
        
        // ========================================
        // TIPOS BLOQUEADOS POR FALTA DE EQUIPAMENTOS DE RADIAÇÃO
        // ========================================
        $tiposBloqueadosPorEquipamentos = [];
        // Só bloqueia se exige equipamentos E não está OK (não tem cadastrados E não declarou que não tem)
        if ($equipamentosInfo['exige'] && !$equipamentosInfo['ok']) {
            // Verifica quais tipos de processo exigem equipamentos para este estabelecimento
            foreach ($tiposProcesso as $tipo) {
                if (\App\Models\AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentosParaProcesso($estabelecimento, $tipo->codigo)) {
                    $tiposBloqueadosPorEquipamentos[] = $tipo->codigo;
                }
            }
        }
        // ========================================
        
        return view('company.estabelecimentos.processos.create', compact(
            'estabelecimento', 
            'tiposProcesso', 
            'documentosObrigatorios', 
            'tiposBloqueados',
            'tiposBloqueadosPorEquipamentos',
            'precisaCadastrarResponsavelTecnico'
        ));
    }

    /**
     * Busca documentos obrigatórios para o estabelecimento baseado nas atividades exercidas
     */
    private function buscarDocumentosObrigatorios($estabelecimento, $tiposProcesso)
    {
        $documentosPorTipoProcesso = [];
        
        // Pega as atividades exercidas do estabelecimento (apenas as marcadas)
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        
        if (empty($atividadesExercidas)) {
            return $documentosPorTipoProcesso;
        }

        // ========================================
        // VERIFICA SE TEM ATIVIDADES ESPECIAIS
        // ========================================
        $temAtividadesEspeciais = false;
        $atividadesEspeciaisCodigos = [];
        
        foreach ($atividadesExercidas as $atividade) {
            $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            $especial = is_array($atividade) ? ($atividade['especial'] ?? false) : false;
            
            if ($codigo && (in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT']) || $especial)) {
                $temAtividadesEspeciais = true;
                $atividadesEspeciaisCodigos[] = $codigo;
            }
        }
        // ========================================

        // Extrai os códigos CNAE das atividades exercidas (excluindo atividades especiais)
        $codigosCnae = collect($atividadesExercidas)->map(function($atividade) {
            $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
            // Ignora atividades especiais
            if (in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT'])) {
                return null;
            }
            return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
        })->filter()->values()->toArray();

        // Determina o escopo de competência e tipo de setor do estabelecimento
        $tipoSetorEnum = $estabelecimento->tipo_setor;
        $tipoSetor = $tipoSetorEnum instanceof \App\Enums\TipoSetor ? $tipoSetorEnum->value : ($tipoSetorEnum ?? 'privado');

        // Para cada tipo de processo, busca as listas de documentos aplicáveis
        foreach ($tiposProcesso as $tipoProcesso) {
            $escopoCompetencia = $tipoProcesso->resolverEscopoCompetencia($estabelecimento);

            // ========================================
            // BUSCA POR TIPO DE PROCESSO (para atividades especiais)
            // ========================================
            if ($temAtividadesEspeciais) {
                // Mapeia atividade especial para código do tipo de processo
                $codigoProcessoEspecial = null;
                if (in_array('PROJ_ARQ', $atividadesEspeciaisCodigos) && $tipoProcesso->codigo === 'projeto_arquitetonico') {
                    $codigoProcessoEspecial = 'projeto_arquitetonico';
                } elseif (in_array('ANAL_ROT', $atividadesEspeciaisCodigos) && $tipoProcesso->codigo === 'analise_rotulagem') {
                    $codigoProcessoEspecial = 'analise_rotulagem';
                }
                
                if ($codigoProcessoEspecial) {
                    // Busca listas vinculadas ao tipo de processo (sem filtrar por atividade)
                    $queryEspecial = \App\Models\ListaDocumento::where('ativo', true)
                        ->where('tipo_processo_id', $tipoProcesso->id)
                        ->with(['tiposDocumentoObrigatorio' => function($q) {
                            $q->orderBy('lista_documento_tipo.ordem');
                        }]);

                    // Filtra por escopo baseado na competência
                    $isEstadualEspecial = $estabelecimento->isCompetenciaEstadual();
                    $queryEspecial->where(function($q) use ($estabelecimento, $isEstadualEspecial) {
                        if ($isEstadualEspecial) {
                            $q->where('escopo', 'estadual');
                        } else {
                            $q->where('escopo', 'estadual');
                            if ($estabelecimento->municipio_id) {
                                $q->orWhere(function($q2) use ($estabelecimento) {
                                    $q2->where('escopo', 'municipal')
                                       ->where('municipio_id', $estabelecimento->municipio_id);
                                });
                            }
                        }
                    });

                    $listasEspeciais = $queryEspecial->get();

                    // Consolida os documentos
                    $documentos = collect();
                    foreach ($listasEspeciais as $lista) {
                        foreach ($lista->tiposDocumentoObrigatorio as $doc) {
                            // Filtra apenas por tipo_setor (escopo da lista já foi filtrado)
                            $aplicaTipoSetor = $doc->tipo_setor === 'todos' || $doc->tipo_setor === $tipoSetor;
                            
                            if (!$aplicaTipoSetor) {
                                continue;
                            }
                            
                            if (!$documentos->contains('id', $doc->id)) {
                                $documentos->push([
                                    'id' => $doc->id,
                                    'nome' => $doc->nome,
                                    'descricao' => $doc->descricao,
                                    'obrigatorio' => $doc->pivot->obrigatorio,
                                    'observacao' => $doc->pivot->observacao,
                                    'lista_nome' => $lista->nome,
                                ]);
                            }
                        }
                    }

                    if ($documentos->isNotEmpty()) {
                        $documentosPorTipoProcesso[$tipoProcesso->codigo] = $documentos;
                    }
                    continue; // Pula para o próximo tipo de processo
                }
            }
            // ========================================

            // Se não tem CNAEs normais, pula
            if (empty($codigosCnae)) {
                continue;
            }

            // Busca as atividades cadastradas que correspondem aos CNAEs exercidos
            $atividadeIds = \App\Models\Atividade::where('ativo', true)
                ->where(function($query) use ($codigosCnae) {
                    foreach ($codigosCnae as $codigo) {
                        $query->orWhere('codigo_cnae', $codigo);
                    }
                })
                ->pluck('id');

            if ($atividadeIds->isEmpty()) {
                continue;
            }

            $query = \App\Models\ListaDocumento::where('ativo', true)
                ->where('tipo_processo_id', $tipoProcesso->id)
                ->whereHas('atividades', function($q) use ($atividadeIds) {
                    $q->whereIn('atividades.id', $atividadeIds);
                })
                ->with(['tiposDocumentoObrigatorio' => function($q) {
                    $q->orderBy('lista_documento_tipo.ordem');
                }]);

            // Filtra por escopo baseado na competência do estabelecimento
            $isEstadualDoc = $estabelecimento->isCompetenciaEstadual();
            $query->where(function($q) use ($estabelecimento, $isEstadualDoc) {
                if ($isEstadualDoc) {
                    $q->where('escopo', 'estadual');
                } else {
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
            foreach ($listas as $lista) {
                foreach ($lista->tiposDocumentoObrigatorio as $doc) {
                    // Filtra apenas por tipo_setor (escopo da lista já foi filtrado acima)
                    $aplicaTipoSetor = $doc->tipo_setor === 'todos' || $doc->tipo_setor === $tipoSetor;
                    
                    if (!$aplicaTipoSetor) {
                        continue; // Pula documentos que não se aplicam ao tipo de setor
                    }
                    
                    // Evita duplicatas pelo ID do tipo de documento
                    if (!$documentos->contains('id', $doc->id)) {
                        $documentos->push([
                            'id' => $doc->id,
                            'nome' => $doc->nome,
                            'descricao' => $doc->descricao,
                            'obrigatorio' => $doc->pivot->obrigatorio,
                            'observacao' => $doc->pivot->observacao,
                            'lista_nome' => $lista->nome,
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

            // Ordena: obrigatórios primeiro, depois por nome
            $documentos = $documentos->sortBy([
                ['obrigatorio', 'desc'],
                ['nome', 'asc'],
            ])->values();

            if ($documentos->isNotEmpty()) {
                $documentosPorTipoProcesso[$tipoProcesso->id] = $documentos;
            }
        }

        return $documentosPorTipoProcesso;
    }

    /**
     * Cria novo processo
     */
    public function processosStore(Request $request, $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->where('status', 'aprovado')
            ->with(['responsaveisLegais', 'responsaveisTecnicos'])
            ->findOrFail($id);

        // Verifica se o usuário tem permissão de edição
        if ($redirect = $this->verificarAcessoGestor($estabelecimento)) {
            return $redirect;
        }

        // Verifica se tem responsável legal com documento
        if ($estabelecimento->responsaveisLegais->isEmpty()) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Para abrir um processo, é obrigatório ter pelo menos um Responsável Legal cadastrado.');
        }

        $responsavelLegalSemDocumento = $estabelecimento->responsaveisLegais->first(function ($responsavel) {
            return empty($responsavel->documento_identificacao);
        });
        
        if ($responsavelLegalSemDocumento) {
            return redirect()->route('company.estabelecimentos.responsaveis.edit', [
                $estabelecimento->id, 
                $responsavelLegalSemDocumento->id, 
                'legal'
            ])->with('error', 'Para abrir um processo, é obrigatório que o Responsável Legal tenha o documento de identificação cadastrado.');
        }

        // Validação de responsável técnico obrigatório por atividade
        if ($estabelecimento->precisaCadastrarResponsavelTecnicoPorAtividade()) {
            return redirect()->route('company.estabelecimentos.responsaveis.index', $estabelecimento->id)
                ->with('error', 'Para abrir processo, este estabelecimento precisa ter pelo menos um Responsável Técnico cadastrado.');
        }

        $validated = $request->validate([
            'tipo_processo_id' => 'required|exists:tipo_processos,id',
            'observacao' => 'nullable|string|max:1000',
            'documentos' => 'nullable|array',
            'documentos.*' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
        ], [
            'documentos.*.max' => 'Cada arquivo não pode ter mais de 10MB.',
            'documentos.*.mimes' => 'Apenas arquivos PDF, JPG e PNG são permitidos.',
        ]);

        $tipoProcesso = \App\Models\TipoProcesso::where('id', $validated['tipo_processo_id'])
            ->where('ativo', true)
            ->where('usuario_externo_pode_abrir', true)
            ->firstOrFail();

        if (!$tipoProcesso->disponivelParaEstabelecimento($estabelecimento)) {
            return back()->withErrors([
                'tipo_processo_id' => 'O tipo de processo selecionado não está disponível para este estabelecimento.',
            ])->withInput();
        }

        if ($tipoProcesso->exibir_aviso_abertura_empresa && $tipoProcesso->aviso_abertura_mensagem && !$request->boolean('confirmar_aviso_abertura')) {
            return back()->withErrors([
                'confirmar_aviso_abertura' => 'Leia e confirme o aviso antes de abrir este tipo de processo.',
            ])->withInput();
        }

        // Validação de processo único por estabelecimento
        if ($tipoProcesso->unico_por_estabelecimento) {
            $existeProcesso = \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                ->where('tipo', $tipoProcesso->codigo)
                ->exists();
            
            if ($existeProcesso) {
                return back()->withErrors(['tipo_processo_id' => 'Este estabelecimento já possui um processo de ' . $tipoProcesso->nome . '. Este tipo de processo só pode ser aberto uma vez.']);
            }
        }
        // Validação de processo anual
        elseif ($tipoProcesso->anual) {
            $anoAtual = date('Y');
            $existeNoAno = \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                ->where('tipo', $tipoProcesso->codigo)
                ->where('ano', $anoAtual)
                ->exists();
            
            if ($existeNoAno) {
                return back()->withErrors(['tipo_processo_id' => 'Este estabelecimento já possui um processo de ' . $tipoProcesso->nome . ' aberto em ' . $anoAtual . '. Processos anuais só podem ser abertos uma vez por ano.']);
            }
        }

        // Validação de equipamentos de radiação obrigatórios
        if (\App\Models\AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentosParaProcesso($estabelecimento, $tipoProcesso->codigo)) {
            $temEquipamentos = \App\Models\EquipamentoRadiacao::where('estabelecimento_id', $estabelecimento->id)->exists();
            $declarouSemEquipamentos = (bool) $estabelecimento->declaracao_sem_equipamentos_imagem;
            
            // Só bloqueia se não tem equipamentos E não declarou que não tem
            if (!$temEquipamentos && !$declarouSemEquipamentos) {
                return redirect()->route('company.estabelecimentos.equipamentos-radiacao.index', $estabelecimento->id)
                    ->with('error', 'Para abrir um processo de ' . $tipoProcesso->nome . ', é obrigatório ter pelo menos um Equipamento de Imagem cadastrado ou declarar que não possui. Por favor, cadastre os equipamentos ou faça a declaração.');
            }
        }

        try {
            $processo = \DB::transaction(function () use ($estabelecimento, $tipoProcesso, $validated, $request) {
                // Gera número do processo usando o método do model (dentro da transaction)
                $ano = date('Y');
                $dadosNumero = \App\Models\Processo::gerarNumeroProcesso($ano);

                // Prepara dados do processo
                $dadosProcesso = [
                    'estabelecimento_id' => $estabelecimento->id,
                    'usuario_externo_id' => auth('externo')->id(),
                    'aberto_por_externo' => true,
                    'tipo' => $tipoProcesso->codigo,
                    'ano' => $dadosNumero['ano'],
                    'numero_sequencial' => $dadosNumero['numero_sequencial'],
                    'numero_processo' => $dadosNumero['numero_processo'],
                    'status' => 'aberto',
                    'observacoes' => $validated['observacao'] ?? null,
                ];
                
                // Resolve o setor inicial considerando override municipal por município.
                $setorInicial = $tipoProcesso->resolverSetorInicial($estabelecimento);
                if ($setorInicial) {
                    $dadosProcesso['setor_atual'] = $setorInicial->codigo;
                }

                $processo = \App\Models\Processo::create($dadosProcesso);

                // Salva os documentos enviados
                if ($request->hasFile('documentos')) {
                    foreach ($request->file('documentos') as $tipoDocumentoId => $arquivo) {
                        if ($arquivo && $arquivo->isValid()) {
                            $tipoDocumento = \App\Models\TipoDocumentoObrigatorio::find($tipoDocumentoId);
                            
                            $nomeOriginal = $arquivo->getClientOriginalName();
                            $extensao = $arquivo->getClientOriginalExtension();
                            $tamanho = $arquivo->getSize();
                            $nomeArquivo = time() . '_' . uniqid() . '.' . $extensao;
                            
                            // Salva o arquivo
                            $caminho = $arquivo->storeAs(
                                'processos/' . $processo->id . '/documentos',
                                $nomeArquivo,
                                'public'
                            );

                            // Cria o registro do documento
                            \App\Models\ProcessoDocumento::create([
                                'processo_id' => $processo->id,
                                'usuario_externo_id' => auth('externo')->id(),
                                'tipo_usuario' => 'externo',
                                'nome_arquivo' => $nomeArquivo,
                                'nome_original' => $nomeOriginal,
                                'caminho' => $caminho,
                                'extensao' => strtolower($extensao),
                                'tamanho' => $tamanho,
                                'tipo_documento' => 'documento_obrigatorio',
                                'tipo_documento_obrigatorio_id' => $tipoDocumentoId,
                                'observacoes' => $tipoDocumento ? $tipoDocumento->nome : null,
                                'status_aprovacao' => 'pendente',
                            ]);
                        }
                    }
                }

                return $processo;
            });

            return redirect()->route('company.processos.show', $processo->id)
                ->with('success', 'Processo aberto com sucesso!');
        } catch (\Exception $e) {
            \Log::error('Erro ao criar processo (company)', [
                'erro' => $e->getMessage(),
                'estabelecimento_id' => $estabelecimento->id,
            ]);
            
            return back()->withErrors(['erro' => 'Erro ao criar processo. Tente novamente.'])->withInput();
        }
    }

    /**
     * Busca usuários externos para vincular ao estabelecimento
     */
    public function buscarUsuariosExternos(Request $request)
    {
        $query = $request->input('q', '');
        $estabelecimentoId = $request->input('estabelecimento_id');
        
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        // Remove formatação do CPF para busca
        $cpfLimpo = preg_replace('/\D/', '', $query);

        $usuarios = \App\Models\UsuarioExterno::where('id', '!=', auth('externo')->id())
            ->where(function($q) use ($query, $cpfLimpo) {
                // Busca por nome (case-insensitive, qualquer parte)
                $q->whereRaw("nome ILIKE ?", ["%{$query}%"])
                  // Busca por email
                  ->orWhereRaw("email ILIKE ?", ["%{$query}%"]);
                
                // Busca por CPF (com ou sem formatação)
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
     * Busca responsável por CPF para preenchimento automático
     * Primeiro busca em responsaveis, depois em usuarios_externos
     */
    public function buscarResponsavelPorCpf(Request $request)
    {
        $cpf = preg_replace('/\D/', '', $request->input('cpf', ''));
        
        if (strlen($cpf) !== 11) {
            return response()->json(['encontrado' => false]);
        }

        // Primeiro busca em responsaveis
        $responsavel = \App\Models\Responsavel::where('cpf', $cpf)->first();

        if ($responsavel) {
            return response()->json([
                'encontrado' => true,
                'fonte' => 'responsavel',
                'dados' => [
                    'nome' => $responsavel->nome,
                    'email' => $responsavel->email,
                    'telefone' => $responsavel->telefone,
                    'conselho' => $responsavel->conselho,
                    'numero_registro' => $responsavel->numero_registro_conselho,
                    // Indica se já tem documento (não envia o documento em si por segurança)
                    'tem_documento_identificacao' => !empty($responsavel->documento_identificacao),
                    'tem_carteirinha_conselho' => !empty($responsavel->carteirinha_conselho),
                ]
            ]);
        }

        // Se não encontrou em responsaveis, busca em usuarios_externos
        $usuarioExterno = \App\Models\UsuarioExterno::where('cpf', $cpf)->first();

        if ($usuarioExterno) {
            return response()->json([
                'encontrado' => true,
                'fonte' => 'usuario_externo',
                'dados' => [
                    'nome' => $usuarioExterno->nome,
                    'email' => $usuarioExterno->email,
                    'telefone' => $usuarioExterno->telefone,
                    'conselho' => null,
                    'numero_registro' => null,
                    // Usuário externo não tem documentos de responsável
                    'tem_documento_identificacao' => false,
                    'tem_carteirinha_conselho' => false,
                ]
            ]);
        }

        return response()->json(['encontrado' => false]);
    }

    /**
     * Lista os municípios de atuação da Unidade Móvel (painel da empresa).
     */
    public function municipiosAtuacaoIndex(string $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()
            ->with('municipiosAtuacao')
            ->findOrFail($id);

        if (!$estabelecimento->is_unidade_movel) {
            return redirect()->route('company.estabelecimentos.show', $id);
        }

        $municipiosAtuacao = $estabelecimento->municipiosAtuacao()->orderBy('municipio_nome')->get();
        $municipiosDisponiveis = \App\Models\Municipio::orderBy('nome')->get()
            ->filter(fn($m) => !$estabelecimento->municipiosAtuacao->contains('municipio_id', $m->id));

        return view('company.estabelecimentos.municipios-atuacao', compact('estabelecimento', 'municipiosAtuacao', 'municipiosDisponiveis'));
    }

    /**
     * Adiciona um novo município de atuação pós-aprovação para PJ Unidade Móvel.
     * Cria registro na tabela e gera o processo/pasta correspondente.
     */
    public function adicionarMunicipioAtuacao(Request $request, string $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()->findOrFail($id);

        if (!$estabelecimento->unidadeMovelAprovada() || $estabelecimento->status !== 'aprovado') {
            return back()->with('error', 'Operação não permitida para este estabelecimento.');
        }

        $request->validate([
            'municipio_id' => 'required|exists:municipios,id',
            'data_inicio' => 'required|date',
            'data_fim' => 'required|date|after_or_equal:data_inicio',
        ]);

        $municipio = \App\Models\Municipio::findOrFail($request->municipio_id);

        $jaExiste = \App\Models\EstabelecimentoMunicipioAtuacao::where('estabelecimento_id', $estabelecimento->id)
            ->where('municipio_id', $municipio->id)
            ->exists();

        if ($jaExiste) {
            return back()->with('error', "O município {$municipio->nome} já está cadastrado para este estabelecimento.");
        }

        // Determina competência (reutilizando lógica de pactuação)
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        $codigosCnae = collect($atividadesExercidas)->map(function ($a) {
            $codigo = is_array($a) ? ($a['codigo'] ?? null) : $a;
            return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
        })->filter()->values()->toArray();

        $competencia = 'municipal';
        $usaInfovisa = $municipio->usa_infovisa ?? false;

        if (!empty($codigosCnae)) {
            foreach ($codigosCnae as $cnae) {
                $resultado = \App\Models\Pactuacao::verificarCompetenciaAvancada($cnae, $municipio->nome, null, null);
                if ($resultado['competencia'] === 'estadual') {
                    $competencia = 'estadual';
                    break;
                }
            }
        }

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $estabelecimento, $municipio, $request, $competencia, $usaInfovisa
        ) {
            $munAtuacao = \App\Models\EstabelecimentoMunicipioAtuacao::create([
                'estabelecimento_id' => $estabelecimento->id,
                'municipio_id' => $municipio->id,
                'municipio_nome' => $municipio->nome,
                'data_inicio' => $request->data_inicio,
                'data_fim' => $request->data_fim,
                'competencia' => $competencia,
                'usa_infovisa' => $usaInfovisa,
            ]);

            $tipoProcesso = \App\Models\TipoProcesso::where('codigo', 'credenciamento_movel')->first();
            if (!$tipoProcesso) return;

            if ($competencia === 'estadual') {
                // Verifica se já existe processo estadual aberto
                $processoEstadual = \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', 'credenciamento_movel')
                    ->whereIn('status', ['aberto', 'parado'])
                    ->whereHas('pastas', fn ($query) => $query->where('protegida', true))
                    ->latest('id')
                    ->first();

                $processoEstadual ??= \App\Models\Processo::where('estabelecimento_id', $estabelecimento->id)
                    ->where('tipo', 'credenciamento_movel')
                    ->whereIn('status', ['aberto', 'parado'])
                    ->latest('id')
                    ->first();

                if ($processoEstadual) {
                    $pastaExistente = $processoEstadual->pastas()
                        ->where('protegida', true)
                        ->whereRaw('LOWER(nome) = ?', [mb_strtolower($municipio->nome)])
                        ->exists();

                    if (!$pastaExistente) {
                        $maxOrdem = $processoEstadual->pastas()->max('ordem') ?? 0;
                        \App\Models\ProcessoPasta::create([
                            'processo_id' => $processoEstadual->id,
                            'nome' => $municipio->nome,
                            'descricao' => "Documentos para o município de {$municipio->nome}",
                            'protegida' => true,
                            'ordem' => $maxOrdem + 1,
                            'status' => 'aberta',
                        ]);
                    }
                } else {
                    $dadosNumero = \App\Models\Processo::gerarNumeroProcesso();
                    $novoProcesso = \App\Models\Processo::create([
                        'estabelecimento_id' => $estabelecimento->id,
                        'tipo' => $tipoProcesso->codigo,
                        'ano' => $dadosNumero['ano'],
                        'numero_sequencial' => $dadosNumero['numero_sequencial'],
                        'numero_processo' => $dadosNumero['numero_processo'],
                        'status' => 'aberto',
                        'usuario_id' => null,
                        'usuario_externo_id' => auth('web')->id(),
                        'aberto_por_externo' => true,
                        'setor_atual' => $tipoProcesso->tipoSetor?->codigo,
                    ]);
                    \App\Models\ProcessoPasta::create([
                        'processo_id' => $novoProcesso->id,
                        'nome' => $municipio->nome,
                        'descricao' => "Documentos para o município de {$municipio->nome}",
                        'protegida' => true,
                        'ordem' => 1,
                        'status' => 'aberta',
                    ]);
                }
            } elseif ($usaInfovisa) {
                $dadosNumero = \App\Models\Processo::gerarNumeroProcesso();
                $setorMunicipal = $tipoProcesso->setoresMunicipais()
                    ->where('municipio_id', $municipio->id)
                    ->with('tipoSetor')
                    ->first();
                $setor = $setorMunicipal?->tipoSetor?->codigo;
                if (!$setor) {
                    $setorDoMunicipio = \App\Models\TipoSetor::whereHas('municipios', fn($q) => $q->where('municipios.id', $municipio->id))->first();
                    $setor = $setorDoMunicipio?->codigo;
                }

                \App\Models\Processo::create([
                    'estabelecimento_id' => $estabelecimento->id,
                    'tipo' => $tipoProcesso->codigo,
                    'ano' => $dadosNumero['ano'],
                    'numero_sequencial' => $dadosNumero['numero_sequencial'],
                    'numero_processo' => $dadosNumero['numero_processo'],
                    'status' => 'aberto',
                    'usuario_id' => null,
                    'usuario_externo_id' => auth('web')->id(),
                    'aberto_por_externo' => true,
                    'setor_atual' => $setor,
                    'observacoes' => "Credenciamento municipal - {$municipio->nome}",
                ]);
            }
        });

        return back()->with('success', "Município {$municipio->nome} adicionado com sucesso! " .
            ($competencia === 'estadual' || $usaInfovisa
                ? 'Um processo de credenciamento foi criado/atualizado automaticamente.'
                : 'Este município não utiliza o InfoVISA, procure a vigilância sanitária municipal.'));
    }

    /**
     * Formulário simplificado para um estabelecimento JÁ APROVADO solicitar o
     * credenciamento do módulo de Unidade Móvel. Reaproveita os dados de CNAE
     * do próprio estabelecimento e exige apenas tipo de unidade + municípios.
     */
    public function solicitarUnidadeMovelForm(string $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()->findOrFail($id);

        if (!$this->podeSolicitarUnidadeMovel($estabelecimento)) {
            return redirect()->route('company.estabelecimentos.show', $id)
                ->with('error', 'Este estabelecimento não está apto a solicitar o credenciamento de Unidade Móvel.');
        }

        $municipios = \App\Models\Municipio::orderBy('nome')->get(['id', 'nome', 'usa_infovisa']);
        $cnaesPermitidosUM = \App\Models\Pactuacao::cnaesUnidadeMovel();

        return view('company.estabelecimentos.solicitar-unidade-movel', compact('estabelecimento', 'municipios', 'cnaesPermitidosUM'));
    }

    /**
     * Persiste a solicitação do módulo Unidade Móvel para um estabelecimento
     * existente: marca is_unidade_movel, status_unidade_movel = pendente, salva
     * o tipo de unidade, as atividades de interesse e os municípios de atuação.
     */
    public function solicitarUnidadeMovelStore(Request $request, string $id)
    {
        $estabelecimento = $this->estabelecimentosDoUsuario()->findOrFail($id);

        if (!$this->podeSolicitarUnidadeMovel($estabelecimento)) {
            return redirect()->route('company.estabelecimentos.show', $id)
                ->with('error', 'Este estabelecimento não está apto a solicitar o credenciamento de Unidade Móvel.');
        }

        $validated = $request->validate([
            'tipo_unidade_movel' => 'required|string|max:100',
            'atividades_exercidas' => 'required|string',
            'municipios_atuacao' => 'required|string',
            'respostas_unidade_movel' => 'nullable|string',
        ], [
            'tipo_unidade_movel.required' => 'Selecione o tipo de unidade móvel.',
            'atividades_exercidas.required' => 'Selecione ao menos uma atividade de interesse para a unidade móvel.',
            'municipios_atuacao.required' => 'Adicione ao menos um município de atuação.',
        ]);

        $atividadesEnviadas = json_decode($validated['atividades_exercidas'], true) ?: [];
        if (empty($atividadesEnviadas)) {
            return back()->withErrors(['atividades_exercidas' => 'Selecione ao menos uma atividade de interesse para a unidade móvel.'])->withInput();
        }

        // Valida que ao menos uma atividade selecionada está contemplada na pactuação UM
        $codigosEnviados = collect($atividadesEnviadas)
            ->pluck('codigo')
            ->map(fn ($c) => preg_replace('/\D/', '', (string) $c))
            ->all();
        $cnaesPermitidos = \App\Models\Pactuacao::cnaesUnidadeMovel();
        if (empty(array_intersect($codigosEnviados, $cnaesPermitidos))) {
            return back()->withErrors([
                'atividades_exercidas' => 'Nenhuma das atividades selecionadas está contemplada para Unidade Móvel. Verifique os CNAEs aceitos.'
            ])->withInput();
        }

        $municipiosAtuacaoInput = json_decode($validated['municipios_atuacao'], true) ?: [];
        if (empty($municipiosAtuacaoInput) || !is_array($municipiosAtuacaoInput)) {
            return back()->withErrors(['municipios_atuacao' => 'Adicione ao menos um município de atuação.'])->withInput();
        }

        foreach ($municipiosAtuacaoInput as $linha) {
            if (empty($linha['municipio_id']) || empty($linha['data_inicio']) || empty($linha['data_fim'])) {
                return back()->withErrors([
                    'municipios_atuacao' => 'Para cada município informe o município, a data de início e a data de fim.'
                ])->withInput();
            }
        }

        $respostasUM = json_decode($request->input('respostas_unidade_movel', '[]'), true) ?: [];

        \Illuminate\Support\Facades\DB::transaction(function () use (
            $estabelecimento, $validated, $atividadesEnviadas, $municipiosAtuacaoInput, $respostasUM
        ) {
            $estabelecimento->update([
                'is_unidade_movel' => true,
                'tipo_unidade_movel' => $validated['tipo_unidade_movel'],
                'status_unidade_movel' => 'pendente',
                'motivo_rejeicao_unidade_movel' => null,
                'respostas_unidade_movel' => $respostasUM,
            ]);

            // Limpa eventuais municípios de uma solicitação anterior rejeitada
            $estabelecimento->municipiosAtuacao()->delete();

            $respostas1 = $estabelecimento->respostas_questionario ?? [];
            $respostas2 = $estabelecimento->respostas_questionario2 ?? [];

            foreach ($municipiosAtuacaoInput as $linha) {
                $municipioModel = \App\Models\Municipio::find($linha['municipio_id']);
                if (!$municipioModel) {
                    continue;
                }

                $resolucao = $this->resolverCompetenciaUnidadeMovel($atividadesEnviadas, $municipioModel, $respostas1, $respostas2);

                $estabelecimento->municipiosAtuacao()->create([
                    'municipio_id' => $municipioModel->id,
                    'municipio_nome' => $municipioModel->nome,
                    'data_inicio' => $linha['data_inicio'],
                    'data_fim' => $linha['data_fim'],
                    'competencia' => $resolucao['competencia'],
                    'usa_infovisa' => $resolucao['usa_infovisa'],
                    'status' => 'pendente',
                ]);
            }
        });

        return redirect()->route('company.estabelecimentos.show', $estabelecimento->id)
            ->with('success', 'Solicitação de credenciamento de Unidade Móvel enviada! Aguarde a análise da Vigilância Sanitária.');
    }

    /**
     * Determina se um estabelecimento pode solicitar o módulo de Unidade Móvel:
     * deve estar aprovado, ainda não ter o módulo ativo/pendente, e possuir ao
     * menos um CNAE contemplado na pactuação de Unidade Móvel.
     */
    private function podeSolicitarUnidadeMovel(\App\Models\Estabelecimento $estabelecimento): bool
    {
        return $estabelecimento->podeSolicitarModuloUnidadeMovel();
    }
}
