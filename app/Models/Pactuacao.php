<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Pactuacao extends Model
{
    protected $table = 'pactuacoes';
    
    protected $fillable = [
        'tipo',
        'municipio',
        'municipio_id',
        'cnae_codigo',
        'cnae_descricao',
        'municipios_excecao',
        'municipios_excecao_ids',
        'observacao',
        'ativo',
        'tabela',
        'requer_questionario',
        'tipo_questionario',
        'pergunta',
        'pergunta2',
        'tipo_pergunta2',
        'classificacao_risco',
        'risco_sim',
        'risco_nao',
        'competencia_base',
        'municipios_excecao_hospitalar',
        'unidade_movel',
        'pessoa_fisica',
    ];
    
    protected $casts = [
        'ativo' => 'boolean',
        'requer_questionario' => 'boolean',
        'unidade_movel' => 'boolean',
        'pessoa_fisica' => 'boolean',
        'municipios_excecao' => 'array',
        'municipios_excecao_ids' => 'array',
        'municipios_excecao_hospitalar' => 'array',
    ];

    /**
     * Retorna os códigos CNAE (normalizados, somente dígitos) marcados como
     * contemplados para o cadastro de PJ Unidade Móvel.
     *
     * @return array<int,string>
     */
    public static function cnaesUnidadeMovel(): array
    {
        return self::where('unidade_movel', true)
            ->where('ativo', true)
            ->pluck('cnae_codigo')
            ->map(fn ($cnae) => preg_replace('/\D/', '', (string) $cnae))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Retorna os códigos CNAE (normalizados, somente dígitos) permitidos para
     * o cadastro de Pessoa Física.
     *
     * @return array<int,string>
     */
    public static function cnaesPessoaFisica(): array
    {
        return self::where('pessoa_fisica', true)
            ->where('ativo', true)
            ->pluck('cnae_codigo')
            ->map(fn ($cnae) => preg_replace('/\D/', '', (string) $cnae))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Cache em memória (por request) de pactuações ativas por CNAE.
     * Evita N+1 ao computar grupo de risco e competência para muitos estabelecimentos.
     *
     * @var array<string,self|null>
     */
    protected static array $cachePorCnae = [];

    /**
     * Retorna a pactuação ativa para um CNAE (com memoização por request).
     * Se $tabela for informada, filtra também por tabela.
     */
    public static function ativaPorCnae($cnaeCodigo, ?string $tabela = null): ?self
    {
        $chave = ($tabela ?? '*') . '|' . $cnaeCodigo;

        if (array_key_exists($chave, self::$cachePorCnae)) {
            return self::$cachePorCnae[$chave];
        }

        $query = self::where('cnae_codigo', $cnaeCodigo)->where('ativo', true);
        if ($tabela !== null) {
            $query->where('tabela', $tabela);
        }

        return self::$cachePorCnae[$chave] = $query->first();
    }

    /**
     * Limpa o cache estático (útil em testes ou após alterações em massa).
     */
    public static function limparCachePorCnae(): void
    {
        self::$cachePorCnae = [];
    }

    /**
     * Relacionamento com município (para pactuações municipais)
     */
    public function municipio()
    {
        return $this->belongsTo(Municipio::class);
    }
    
    /**
     * Relacionamento com municípios de exceção
     */
    public function municipiosExcecao()
    {
        if (!$this->municipios_excecao_ids) {
            return collect();
        }
        
        return Municipio::whereIn('id', $this->municipios_excecao_ids)->get();
    }
    
    /**
     * Verifica se uma atividade é de competência estadual
     * Considera exceções municipais (descentralização) e resposta do questionário
     * 
     * @return bool|string Retorna true (estadual), false (municipal), ou 'nao_sujeito_visa' para Tabela V com resposta NÃO
     */
    public static function isAtividadeEstadual($cnaeCodigo, $municipio = null, $resposta = null)
    {
        \Log::info('isAtividadeEstadual chamado:', [
            'cnae' => $cnaeCodigo,
            'municipio' => $municipio,
            'resposta' => $resposta
        ]);
        
        // Atividades especiais (Tabela VI) - Projeto Arquitetônico e Análise de Rotulagem
        if (in_array($cnaeCodigo, ['PROJ_ARQ', 'ANAL_ROT'])) {
            $pactuacao = self::where('cnae_codigo', $cnaeCodigo)
                ->where('tabela', 'VI')
                ->where('ativo', true)
                ->first();
            
            if (!$pactuacao) {
                // Se não encontrou na pactuação, assume estadual por padrão
                \Log::info('Atividade especial não encontrada, assumindo estadual');
                return true;
            }
            
            // Verifica se o município está na lista de exceções (descentralizado)
            if ($municipio && $pactuacao->municipios_excecao && is_array($pactuacao->municipios_excecao)) {
                $municipioNormalizado = strtoupper(self::removerAcentos(trim($municipio)));
                
                foreach ($pactuacao->municipios_excecao as $municipioExcecao) {
                    $municipioExcecaoNorm = strtoupper(self::removerAcentos(trim($municipioExcecao)));
                    
                    if ($municipioExcecaoNorm === $municipioNormalizado) {
                        \Log::info('Atividade especial descentralizada para município');
                        return false; // Descentralizado para o município
                    }
                }
            }
            
            \Log::info('Atividade especial é estadual');
            return true; // Competência estadual
        }
        
        // Busca a pactuação (sem filtrar por tipo para considerar todas as tabelas)
        $pactuacao = self::where('cnae_codigo', $cnaeCodigo)
            ->where('ativo', true)
            ->first();
        
        if (!$pactuacao) {
            \Log::info('Pactuação não encontrada para CNAE, assumindo municipal');
            return false;
        }
        
        \Log::info('Pactuação encontrada:', [
            'tabela' => $pactuacao->tabela,
            'tipo' => $pactuacao->tipo,
            'tipo_questionario' => $pactuacao->tipo_questionario,
            'requer_questionario' => $pactuacao->requer_questionario
        ]);
        
        // Normaliza município para verificação de exceções
        $municipioNormalizado = null;
        if ($municipio) {
            $municipioNormalizado = strtoupper(self::removerAcentos(trim($municipio)));
        }
        
        // ========================================
        // TABELA I - Competência Municipal (base)
        // ========================================
        if ($pactuacao->tabela === 'I') {
            // Tabela I é competência municipal por padrão
            // Só pode ser estadual se estiver em hospital (tipo_questionario = localizacao)
            if ($pactuacao->tipo_questionario === 'localizacao' || $pactuacao->tipo_questionario === 'risco_localizacao') {
                // Se resposta for SIM (está em hospital), pode ser estadual
                if ($resposta !== null) {
                    $resp = strtolower(trim($resposta));
                    $respSim = ($resp === 'sim' || $resp === 'yes' || $resp === '1' || $resp === 'true');
                    
                    if ($respSim) {
                        // Verifica exceção hospitalar (Palmas, Araguaína)
                        if ($municipioNormalizado && $pactuacao->municipios_excecao_hospitalar) {
                            foreach ($pactuacao->municipios_excecao_hospitalar as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                                    \Log::info('Tabela I: em hospital mas município tem exceção hospitalar = municipal');
                                    return false; // Municipal (exceção hospitalar)
                                }
                            }
                        }
                        \Log::info('Tabela I: em hospital = estadual');
                        return true; // Estadual (em hospital)
                    }
                }
            }
            \Log::info('Tabela I: competência municipal');
            return false; // Municipal
        }
        
        // ========================================
        // TABELA II - Estadual Exclusiva
        // ========================================
        if ($pactuacao->tabela === 'II') {
            \Log::info('Tabela II: competência estadual exclusiva');
            return true; // Sempre estadual
        }
        
        // ========================================
        // TABELA III - Alto Risco Pactuado
        // ========================================
        if ($pactuacao->tabela === 'III') {
            // Verifica descentralização
            if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                foreach ($pactuacao->municipios_excecao as $munExcecao) {
                    if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                        \Log::info('Tabela III: município descentralizado = municipal');
                        return false; // Municipal (descentralizado)
                    }
                }
            }
            \Log::info('Tabela III: competência estadual');
            return true; // Estadual
        }
        
        // ========================================
        // TABELA IV - Com Questionário
        // ========================================
        if ($pactuacao->tabela === 'IV') {
            \Log::info('Processando Tabela IV com tipo_questionario: ' . $pactuacao->tipo_questionario);
            
            // Normaliza resposta
            $respNao = false;
            $respSim = false;
            if ($resposta !== null) {
                $resp = strtolower(trim($resposta));
                $respNao = ($resp === 'nao' || $resp === 'não' || $resp === 'no' || $resp === '0' || $resp === 'false');
                $respSim = ($resp === 'sim' || $resp === 'yes' || $resp === '1' || $resp === 'true');
                
                \Log::info('Processando resposta do questionário:', [
                    'resposta_original' => $resposta,
                    'resposta_normalizada' => $resp,
                    'respNao' => $respNao,
                    'respSim' => $respSim
                ]);
            }
            
            switch ($pactuacao->tipo_questionario) {
                case 'competencia':
                    // SIM = Estadual (com verificação de descentralização)
                    // NÃO = Municipal
                    if ($respNao) {
                        \Log::info('Tabela IV competencia: resposta NÃO = municipal');
                        return false; // Municipal
                    }
                    if ($respSim) {
                        // Verifica descentralização
                        if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                                    \Log::info('Tabela IV competencia: município descentralizado = municipal');
                                    return false; // Municipal (descentralizado)
                                }
                            }
                        }
                        \Log::info('Tabela IV competencia: resposta SIM = estadual');
                        return true; // Estadual
                    }
                    // Se não respondeu, assume estadual (será validado no frontend)
                    \Log::info('Tabela IV competencia: sem resposta, assumindo estadual');
                    break;
                    
                case 'risco':
                    // Questionário define apenas o risco
                    // SIM (alto risco) = Estadual com descentralização
                    // NÃO (médio risco) = Municipal
                    if ($respNao) {
                        \Log::info('Tabela IV risco: resposta NÃO = municipal (risco médio)');
                        return false; // Municipal (risco médio)
                    }
                    if ($respSim) {
                        // Verifica descentralização
                        if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                                    \Log::info('Tabela IV risco: município descentralizado = municipal');
                                    return false; // Municipal (descentralizado)
                                }
                            }
                        }
                        \Log::info('Tabela IV risco: resposta SIM = estadual');
                        return true; // Estadual
                    }
                    // Se não respondeu, assume estadual
                    \Log::info('Tabela IV risco: sem resposta, assumindo estadual');
                    break;
                    
                case 'localizacao':
                case 'competencia_localizacao':
                    // Lógica mais complexa - se NÃO faz análises, é municipal
                    if ($respNao) {
                        \Log::info('Tabela IV localizacao: resposta NÃO = municipal');
                        return false; // Municipal (posto de coleta)
                    }
                    if ($respSim) {
                        // Verifica descentralização
                        if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                                    \Log::info('Tabela IV localizacao: município descentralizado = municipal');
                                    return false; // Municipal (descentralizado)
                                }
                            }
                        }
                        \Log::info('Tabela IV localizacao: resposta SIM = estadual');
                        return true; // Estadual
                    }
                    // Se não respondeu, assume estadual
                    \Log::info('Tabela IV localizacao: sem resposta, assumindo estadual');
                    break;
                    
                default:
                    // Comportamento padrão para Tabela IV sem tipo_questionario definido
                    // SIM = Estadual, NÃO = Municipal
                    if ($respNao) {
                        \Log::info('Tabela IV default: resposta NÃO = municipal');
                        return false;
                    }
                    if ($respSim) {
                        // Verifica descentralização
                        if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                                    \Log::info('Tabela IV default: município descentralizado = municipal');
                                    return false; // Municipal (descentralizado)
                                }
                            }
                        }
                        \Log::info('Tabela IV default: resposta SIM = estadual');
                        return true; // Estadual
                    }
                    \Log::info('Tabela IV default: sem resposta, assumindo estadual');
            }
            
            // Verifica descentralização para casos sem resposta
            if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                foreach ($pactuacao->municipios_excecao as $munExcecao) {
                    if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                        \Log::info('Tabela IV: município descentralizado = municipal');
                        return false; // Municipal (descentralizado)
                    }
                }
            }
            
            \Log::info('Tabela IV: resultado final = estadual');
            return true; // Estadual
        }
        
        // ========================================
        // TABELA V - Definir se é VISA
        // ========================================
        if ($pactuacao->tabela === 'V') {
            if ($resposta !== null) {
                $resp = strtolower(trim($resposta));
                $respNao = ($resp === 'nao' || $resp === 'não' || $resp === 'no' || $resp === '0' || $resp === 'false');
                
                if ($respNao) {
                    \Log::info('Tabela V: resposta NÃO = não sujeito à VISA');
                    return 'nao_sujeito_visa';
                }
            }
            
            // SIM = Sujeito à VISA, verifica descentralização
            if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                foreach ($pactuacao->municipios_excecao as $munExcecao) {
                    if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                        \Log::info('Tabela V: município descentralizado = municipal');
                        return false; // Municipal (descentralizado)
                    }
                }
            }
            
            \Log::info('Tabela V: competência estadual');
            return true; // Estadual
        }
        
        // ========================================
        // Fallback para pactuações sem tabela definida
        // ========================================
        // Se tipo = 'estadual', é estadual
        if ($pactuacao->tipo === 'estadual') {
            // Verifica descentralização
            if ($municipioNormalizado && $pactuacao->municipios_excecao) {
                foreach ($pactuacao->municipios_excecao as $munExcecao) {
                    if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNormalizado) {
                        \Log::info('Fallback: município descentralizado = municipal');
                        return false; // Municipal (descentralizado)
                    }
                }
            }
            \Log::info('Fallback: tipo estadual = estadual');
            return true;
        }
        
        \Log::info('Fallback: competência municipal');
        return false; // Municipal
    }
    
    /**
     * Remove acentos de uma string
     */
    private static function removerAcentos($string)
    {
        $acentos = [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ];
        
        return strtr($string, $acentos);
    }
    
    /**
     * Verifica se uma atividade é de competência municipal
     */
    public static function isAtividadeMunicipal($municipio, $cnaeCodigo)
    {
        return self::where('tipo', 'municipal')
            ->where('municipio', $municipio)
            ->where('cnae_codigo', $cnaeCodigo)
            ->where('ativo', true)
            ->exists();
    }
    
    /**
     * Retorna todas as atividades de um município
     */
    public static function getAtividadesMunicipio($municipio)
    {
        return self::where('tipo', 'municipal')
            ->where('municipio', $municipio)
            ->where('ativo', true)
            ->pluck('cnae_codigo')
            ->toArray();
    }
    
    /**
     * Retorna todas as atividades estaduais
     */
    public static function getAtividadesEstaduais()
    {
        return self::where('tipo', 'estadual')
            ->where('ativo', true)
            ->pluck('cnae_codigo')
            ->toArray();
    }
    
    /**
     * Verifica se um município tem exceção (descentralização) para uma atividade estadual
     */
    public static function municipioTemExcecao($cnaeCodigo, $municipio)
    {
        $pactuacao = self::where('tipo', 'estadual')
            ->where('cnae_codigo', $cnaeCodigo)
            ->where('ativo', true)
            ->first();
        
        if (!$pactuacao || !$pactuacao->municipios_excecao) {
            return false;
        }
        
        return in_array($municipio, $pactuacao->municipios_excecao);
    }
    
    /**
     * Adiciona um município à lista de exceções
     */
    public function adicionarMunicipioExcecao($municipio)
    {
        $excecoes = $this->municipios_excecao ?? [];
        
        if (!in_array($municipio, $excecoes)) {
            $excecoes[] = $municipio;
            $this->municipios_excecao = $excecoes;
            $this->save();
        }
        
        return $this;
    }
    
    /**
     * Remove um município da lista de exceções
     */
    public function removerMunicipioExcecao($municipio)
    {
        $excecoes = $this->municipios_excecao ?? [];
        $excecoes = array_values(array_filter($excecoes, fn($m) => $m !== $municipio));
        
        $this->municipios_excecao = $excecoes;
        $this->save();
        
        return $this;
    }
    
    /**
     * Verifica competência e risco de forma avançada
     * Considera todos os cenários de pactuação
     * 
     * @param string $cnaeCodigo Código CNAE
     * @param string|null $municipio Nome do município
     * @param string|null $resposta1 Resposta da primeira pergunta (sim/nao)
     * @param string|null $resposta2 Resposta da segunda pergunta (sim/nao) - para localização hospitalar
     * @return array ['competencia' => 'estadual'|'municipal'|'nao_sujeito_visa', 'risco' => 'baixo'|'medio'|'alto', 'detalhes' => [...]]
     */
    public static function verificarCompetenciaAvancada($cnaeCodigo, $municipio = null, $resposta1 = null, $resposta2 = null)
    {
        // ========================================
        // ATIVIDADES ESPECIAIS (Tabela VI)
        // ========================================
        // Verifica se é uma atividade especial (PROJ_ARQ, ANAL_ROT)
        if (in_array($cnaeCodigo, ['PROJ_ARQ', 'ANAL_ROT'])) {
            $pactuacao = self::ativaPorCnae($cnaeCodigo, 'VI');
            
            $resultado = [
                'competencia' => 'estadual', // Padrão é estadual
                'risco' => $pactuacao->classificacao_risco ?? 'medio',
                'detalhes' => [
                    'encontrado' => (bool)$pactuacao,
                    'tabela' => 'VI',
                    'atividade_especial' => true,
                    'tipo_processo_codigo' => $pactuacao->tipo_processo_codigo ?? null
                ]
            ];
            
            // Verifica se o município está na lista de exceções (descentralizado)
            if ($municipio && $pactuacao && $pactuacao->municipios_excecao && is_array($pactuacao->municipios_excecao)) {
                $municipioNormalizado = strtoupper(self::removerAcentos(trim($municipio)));
                
                foreach ($pactuacao->municipios_excecao as $municipioExcecao) {
                    $municipioExcecaoNorm = strtoupper(self::removerAcentos(trim($municipioExcecao)));
                    
                    if ($municipioExcecaoNorm === $municipioNormalizado) {
                        $resultado['competencia'] = 'municipal';
                        $resultado['detalhes']['descentralizado'] = true;
                        break;
                    }
                }
            }
            
            return $resultado;
        }
        // ========================================
        
        // Normaliza o código CNAE (apenas para códigos numéricos)
        $cnaeCodigo = preg_replace('/[^0-9]/', '', $cnaeCodigo);
        
        // Busca a pactuação (com cache em memória por request)
        $pactuacao = self::ativaPorCnae($cnaeCodigo);
        
        if (!$pactuacao) {
            // Fora da pactuação = atividade que não é de competência da Vigilância Sanitária
            // (mesmo efeito de responder "NÃO" na Tabela V). Não torna o estabelecimento estadual.
            return [
                'competencia' => 'nao_sujeito_visa',
                'risco' => 'baixo',
                'detalhes' => [
                    'encontrado' => false,
                    'nao_pactuada' => true,
                    'sujeito_visa' => false,
                    'mensagem' => 'Atividade não consta na pactuação - não é de competência da Vigilância Sanitária'
                ]
            ];
        }
        
        $resultado = [
            'competencia' => null,
            'risco' => $pactuacao->classificacao_risco ?? 'baixo',
            'detalhes' => [
                'encontrado' => true,
                'tabela' => $pactuacao->tabela,
                'tipo_questionario' => $pactuacao->tipo_questionario,
                'requer_questionario' => $pactuacao->requer_questionario,
                'pergunta' => $pactuacao->pergunta,
                'pergunta2' => $pactuacao->pergunta2
            ]
        ];
        
        // Normaliza respostas
        $resp1 = $resposta1 ? strtolower(trim($resposta1)) : null;
        $resp1Sim = $resp1 === 'sim' || $resp1 === 'yes' || $resp1 === '1' || $resp1 === 'true';
        $resp1Nao = $resp1 === 'nao' || $resp1 === 'não' || $resp1 === 'no' || $resp1 === '0' || $resp1 === 'false';
        
        $resp2 = $resposta2 ? strtolower(trim($resposta2)) : null;
        $resp2Sim = $resp2 === 'sim' || $resp2 === 'yes' || $resp2 === '1' || $resp2 === 'true';
        
        // Normaliza município
        $municipioNorm = $municipio ? strtoupper(self::removerAcentos(trim($municipio))) : null;
        
        // ========================================
        // TABELA I - Competência Municipal
        // ========================================
        if ($pactuacao->tabela === 'I') {
            // Competência base é municipal
            $resultado['competencia'] = 'municipal';
            
            // Verifica tipo de questionário
            switch ($pactuacao->tipo_questionario) {
                case 'risco':
                    // Questionário define apenas o risco, competência sempre municipal
                    if ($resp1Sim) {
                        $resultado['risco'] = $pactuacao->risco_sim ?? 'alto';
                    } elseif ($resp1Nao) {
                        $resultado['risco'] = $pactuacao->risco_nao ?? 'medio';
                    }
                    break;
                    
                case 'localizacao':
                    // Competência depende se está em Unidade Hospitalar
                    // Pergunta: "O estabelecimento exerce a atividade dentro de Unidade Hospitalar?"
                    if ($resp1Sim) {
                        // Dentro de hospital = Estadual, EXCETO em municípios específicos
                        $resultado['competencia'] = 'estadual';
                        
                        // Verifica se município está na exceção hospitalar (Palmas, Araguaína)
                        if ($municipioNorm && $pactuacao->municipios_excecao_hospitalar) {
                            foreach ($pactuacao->municipios_excecao_hospitalar as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['excecao_hospitalar'] = true;
                                    break;
                                }
                            }
                        }
                    }
                    // Se NÃO está em hospital, mantém municipal
                    break;
                    
                case 'risco_localizacao':
                    // Pergunta 1 define risco, Pergunta 2 define competência (localização)
                    if ($resp1Sim) {
                        $resultado['risco'] = $pactuacao->risco_sim ?? 'alto';
                    } elseif ($resp1Nao) {
                        $resultado['risco'] = $pactuacao->risco_nao ?? 'medio';
                    }
                    
                    // Pergunta 2: localização hospitalar
                    if ($resp2Sim) {
                        $resultado['competencia'] = 'estadual';
                        
                        // Verifica exceção hospitalar
                        if ($municipioNorm && $pactuacao->municipios_excecao_hospitalar) {
                            foreach ($pactuacao->municipios_excecao_hospitalar as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['excecao_hospitalar'] = true;
                                    break;
                                }
                            }
                        }
                    }
                    break;
            }
            
            return $resultado;
        }
        
        // ========================================
        // TABELA II - Estadual Exclusiva
        // ========================================
        if ($pactuacao->tabela === 'II') {
            $resultado['competencia'] = 'estadual';
            $resultado['risco'] = $pactuacao->classificacao_risco ?? 'alto';
            return $resultado;
        }
        
        // ========================================
        // TABELA III - Alto Risco Pactuado
        // ========================================
        if ($pactuacao->tabela === 'III') {
            $resultado['competencia'] = 'estadual';
            $resultado['risco'] = 'alto';
            
            // Verifica se município está descentralizado
            if ($municipioNorm && $pactuacao->municipios_excecao) {
                foreach ($pactuacao->municipios_excecao as $munExcecao) {
                    if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                        $resultado['competencia'] = 'municipal';
                        $resultado['detalhes']['descentralizado'] = true;
                        break;
                    }
                }
            }
            
            return $resultado;
        }
        
        // ========================================
        // TABELA IV - Com Questionário
        // ========================================
        if ($pactuacao->tabela === 'IV') {
            // Competência base é estadual
            $resultado['competencia'] = 'estadual';
            
            switch ($pactuacao->tipo_questionario) {
                case 'competencia':
                    // Questionário define competência
                    if ($resp1Sim) {
                        $resultado['competencia'] = 'estadual';
                        $resultado['risco'] = $pactuacao->risco_sim ?? 'alto';
                        
                        // Verifica descentralização
                        if ($municipioNorm && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['descentralizado'] = true;
                                    break;
                                }
                            }
                        }
                    } elseif ($resp1Nao) {
                        $resultado['competencia'] = 'municipal';
                        $resultado['risco'] = $pactuacao->risco_nao ?? 'medio';
                    }
                    break;
                    
                case 'risco':
                    // Questionário define risco, competência é estadual com descentralização
                    if ($resp1Sim) {
                        $resultado['risco'] = $pactuacao->risco_sim ?? 'alto';
                        $resultado['competencia'] = 'estadual';
                        
                        // Verifica descentralização
                        if ($municipioNorm && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['descentralizado'] = true;
                                    break;
                                }
                            }
                        }
                    } elseif ($resp1Nao) {
                        // Risco médio = competência municipal
                        $resultado['risco'] = $pactuacao->risco_nao ?? 'medio';
                        $resultado['competencia'] = 'municipal';
                    }
                    break;
                    
                case 'localizacao':
                case 'competencia_localizacao':
                    // Múltiplas perguntas: competência + localização
                    // Pergunta 1: análises clínicas?
                    if ($resp1Sim) {
                        $resultado['competencia'] = 'estadual';
                        
                        // Verifica descentralização para análises clínicas
                        if ($municipioNorm && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['descentralizado'] = true;
                                    break;
                                }
                            }
                        }
                    } else {
                        // Não faz análises = posto de coleta = municipal
                        $resultado['competencia'] = 'municipal';
                    }
                    
                    // Pergunta 2: dentro de hospital?
                    if ($resp2Sim && $resultado['competencia'] === 'municipal') {
                        $resultado['competencia'] = 'estadual';
                        
                        // Verifica exceção hospitalar
                        if ($municipioNorm && $pactuacao->municipios_excecao_hospitalar) {
                            foreach ($pactuacao->municipios_excecao_hospitalar as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['excecao_hospitalar'] = true;
                                    break;
                                }
                            }
                        }
                    }
                    break;
                    
                default:
                    // Comportamento padrão: SIM = estadual, NÃO = municipal
                    if ($resp1Nao) {
                        $resultado['competencia'] = 'municipal';
                    } else {
                        // Verifica descentralização
                        if ($municipioNorm && $pactuacao->municipios_excecao) {
                            foreach ($pactuacao->municipios_excecao as $munExcecao) {
                                if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                                    $resultado['competencia'] = 'municipal';
                                    $resultado['detalhes']['descentralizado'] = true;
                                    break;
                                }
                            }
                        }
                    }
            }
            
            return $resultado;
        }
        
        // ========================================
        // TABELA V - Definir se é VISA
        // ========================================
        if ($pactuacao->tabela === 'V') {
            if ($resp1Nao) {
                // NÃO = Não sujeito à VISA
                $resultado['competencia'] = 'nao_sujeito_visa';
                $resultado['risco'] = $pactuacao->risco_nao ?? 'medio';
                $resultado['detalhes']['sujeito_visa'] = false;
            } else {
                // SIM = Sujeito à VISA, aplicar regras de competência
                $resultado['risco'] = $pactuacao->risco_sim ?? 'alto';
                $resultado['competencia'] = 'estadual';
                $resultado['detalhes']['sujeito_visa'] = true;
                
                // Verifica descentralização
                if ($municipioNorm && $pactuacao->municipios_excecao) {
                    foreach ($pactuacao->municipios_excecao as $munExcecao) {
                        if (strtoupper(self::removerAcentos(trim($munExcecao))) === $municipioNorm) {
                            $resultado['competencia'] = 'municipal';
                            $resultado['detalhes']['descentralizado'] = true;
                            break;
                        }
                    }
                }
            }
            
            return $resultado;
        }
        
        // Fallback: competência municipal
        $resultado['competencia'] = 'municipal';
        return $resultado;
    }
    
    /**
     * Retorna os tipos de questionário disponíveis
     */
    public static function getTiposQuestionario()
    {
        return [
            'competencia' => 'Competência (SIM=Estadual, NÃO=Municipal)',
            'risco' => 'Risco (SIM=Alto, NÃO=Médio)',
            'localizacao' => 'Localização (Hospital=Estadual)',
            'risco_localizacao' => 'Risco + Localização',
            'competencia_localizacao' => 'Competência + Localização',
            'visa' => 'Sujeito à VISA (SIM=Sujeito, NÃO=Não sujeito)'
        ];
    }
}
