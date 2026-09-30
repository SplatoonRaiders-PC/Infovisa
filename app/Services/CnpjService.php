<?php

namespace App\Services;

use App\Support\NaturezaJuridicaHelper;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class CnpjService
{
    private const TIMEOUT = 30; // 30 segundos
    
    // URLs das APIs
    private const MINHA_RECEITA_URL = 'https://minhareceita.org';
    private const BRASIL_API_URL = 'https://brasilapi.com.br/api/cnpj/v1';
    private const RECEITA_WS_URL = 'https://www.receitaws.com.br/v1/cnpj';
    private const PUBLICA_CNPJ_URL = 'https://publica.cnpj.ws/cnpj';
    private const CNPJA_COMMERCIAL_URL = 'https://api.cnpja.com/office';

    /**
     * Cliente HTTP padronizado para consultas externas.
     *
     * Em ambiente local desabilita verificação SSL para evitar erros de certificado
     * (cURL error 60 em máquinas de desenvolvimento sem cadeia de CA configurada).
     */
    private function httpClient(): PendingRequest
    {
        return Http::timeout(self::TIMEOUT)
            ->withOptions([
                'verify' => !app()->environment('local'),
            ]);
    }

    /**
     * Consulta dados de CNPJ com fallback em múltiplas APIs
     *
     * @param string $cnpj
     * @return array|null
     */
    public function consultarCnpj(string $cnpj): ?array
    {
        try {
            // Remove formatação do CNPJ
            $cnpjLimpo = preg_replace('/[^0-9]/', '', $cnpj);
            
            // Valida se tem 14 dígitos
            if (strlen($cnpjLimpo) !== 14) {
                throw new Exception('CNPJ deve ter 14 dígitos');
            }

            // Tenta API 1: Minha Receita (principal)
            Log::info('Tentando buscar CNPJ na Receita', ['cnpj' => $cnpjLimpo]);
            $dados = $this->consultarMinhaReceita($cnpjLimpo);
            if ($dados !== null) {
                Log::info('CNPJ encontrado na Receita', ['cnpj' => $cnpjLimpo]);
                return $dados;
            }

            // Tenta API 2: BrasilAPI (backup 1)
            Log::info('Receita falhou, tentando BrasilAPI', ['cnpj' => $cnpjLimpo]);
            $dados = $this->consultarBrasilApi($cnpjLimpo);
            if ($dados !== null) {
                Log::info('CNPJ encontrado na BrasilAPI', ['cnpj' => $cnpjLimpo]);
                return $dados;
            }

            // Tenta API 3: ReceitaWS (backup 2)
            Log::info('BrasilAPI falhou, tentando ReceitaWS', ['cnpj' => $cnpjLimpo]);
            $dados = $this->consultarReceitaWs($cnpjLimpo);
            if ($dados !== null) {
                Log::info('CNPJ encontrado na ReceitaWS', ['cnpj' => $cnpjLimpo]);
                return $dados;
            }

            // Tenta API 4: Publica CNPJ WS (backup 3 - dados mais atualizados)
            Log::info('ReceitaWS falhou, tentando Publica CNPJ WS', ['cnpj' => $cnpjLimpo]);
            $dados = $this->consultarPublicaCnpjWs($cnpjLimpo);
            if ($dados !== null) {
                Log::info('CNPJ encontrado na Publica CNPJ WS', ['cnpj' => $cnpjLimpo]);
                return $dados;
            }

            // Tenta API 5: CNPJa Commercial (último fallback - consulta em tempo real na Receita)
            // Só é acionada quando TODAS as APIs gratuitas falham, para economizar créditos.
            Log::info('Publica CNPJ WS falhou, tentando CNPJa Commercial (consome créditos)', ['cnpj' => $cnpjLimpo]);
            $dados = $this->consultarCnpjaCommercial($cnpjLimpo);
            if ($dados !== null) {
                Log::info('CNPJ encontrado na CNPJa Commercial', ['cnpj' => $cnpjLimpo]);
                return $dados;
            }

            // Nenhuma API retornou dados
            Log::warning('CNPJ não encontrado em nenhuma API', ['cnpj' => $cnpjLimpo]);
            return null;

        } catch (Exception $e) {
            Log::error('Erro ao consultar CNPJ', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Limite gratuito por minuto das fontes mais atualizadas (as demais não têm limite rígido)
     */
    private const LIMITE_POR_MINUTO = [
        'publica_cnpj_ws' => 3,
        'receita_ws' => 3,
    ];

    /**
     * Consulta nas APIs gratuitas priorizando a mais ATUALIZADA disponível
     * (nunca usa a CNPJa, que consome créditos). Usada nas verificações de CNAE.
     *
     * Ordem: Publica CNPJ WS e ReceitaWS (dados mais recentes, ~3 consultas/min cada)
     * → BrasilAPI e Minha Receita (base mensal da Receita, sem limite rígido).
     * Quando a cota por minuto de uma fonte acaba, passa para a próxima sem esperar.
     */
    public function consultarCnpjFontesGratuitas(string $cnpj): ?array
    {
        $cnpjLimpo = preg_replace('/[^0-9]/', '', $cnpj);

        if (strlen($cnpjLimpo) !== 14) {
            return null;
        }

        $fontes = [
            'publica_cnpj_ws' => fn () => $this->consultarPublicaCnpjWs($cnpjLimpo),
            'receita_ws' => fn () => $this->consultarReceitaWs($cnpjLimpo),
            'brasil_api' => fn () => $this->consultarBrasilApi($cnpjLimpo),
            'minha_receita' => fn () => $this->consultarMinhaReceita($cnpjLimpo),
        ];

        foreach ($fontes as $fonte => $consultar) {
            if (!$this->fonteDisponivel($fonte)) {
                continue;
            }

            $this->registrarUsoFonte($fonte);
            $dados = $consultar();

            if ($dados !== null) {
                return $dados;
            }
        }

        return null;
    }

    /**
     * Quantas consultas das fontes limitadas ainda cabem no minuto atual
     */
    public function consultasAtualizadasDisponiveis(): int
    {
        return collect(array_keys(self::LIMITE_POR_MINUTO))
            ->sum(fn ($fonte) => \Illuminate\Support\Facades\RateLimiter::remaining('cnpj-api:' . $fonte, self::LIMITE_POR_MINUTO[$fonte]));
    }

    /**
     * Segundos até liberar a próxima consulta em alguma fonte atualizada
     */
    public function segundosAteProximaConsultaAtualizada(): int
    {
        return (int) collect(array_keys(self::LIMITE_POR_MINUTO))
            ->map(fn ($fonte) => \Illuminate\Support\Facades\RateLimiter::availableIn('cnpj-api:' . $fonte))
            ->min();
    }

    private function fonteDisponivel(string $fonte): bool
    {
        if (!isset(self::LIMITE_POR_MINUTO[$fonte])) {
            return true;
        }

        return !\Illuminate\Support\Facades\RateLimiter::tooManyAttempts('cnpj-api:' . $fonte, self::LIMITE_POR_MINUTO[$fonte]);
    }

    private function registrarUsoFonte(string $fonte): void
    {
        if (isset(self::LIMITE_POR_MINUTO[$fonte])) {
            \Illuminate\Support\Facades\RateLimiter::hit('cnpj-api:' . $fonte, 60);
        }
    }

    /**
     * Consulta priorizando fontes com dados mais atualizados (CNPJa > Publica).
     * Usada quando o usuário sabe que alterou CNAEs recentemente e a primeira
     * consulta trouxe dados antigos.
     */
    public function consultarCnpjAtualizado(string $cnpj): ?array
    {
        $cnpjLimpo = preg_replace('/[^0-9]/', '', $cnpj);

        if (strlen($cnpjLimpo) !== 14) {
            return null;
        }

        // Tenta primeiro a CNPJa (tempo real na Receita)
        $dados = $this->consultarCnpjaCommercial($cnpjLimpo);
        if ($dados !== null) {
            return $dados;
        }

        // Fallback: Publica CNPJ WS (atualiza com mais frequência)
        $dados = $this->consultarPublicaCnpjWs($cnpjLimpo);
        if ($dados !== null) {
            return $dados;
        }

        // Último recurso: consulta normal (Minha Receita, BrasilAPI, ReceitaWS)
        return $this->consultarCnpj($cnpjLimpo);
    }

    /**
     * Consulta na API Minha Receita
     */
    private function consultarMinhaReceita(string $cnpj): ?array
    {
        try {
            $url = self::MINHA_RECEITA_URL . '/' . $cnpj;
            
            Log::info('Consultando Minha Receita', [
                'url' => $url,
                'cnpj' => $cnpj
            ]);
            
            $response = $this->httpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'InfoVISA/3.0'
                ])
                ->get($url);

            Log::info('Resposta Minha Receita', [
                'status' => $response->status(),
                'successful' => $response->successful(),
                'body_size' => strlen($response->body())
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // A API retorna o CNPJ sem formatação
                if (isset($data['cnpj'])) {
                    Log::info('Dados encontrados na Minha Receita', [
                        'cnpj' => $data['cnpj'],
                        'razao_social' => $data['razao_social'] ?? 'N/A'
                    ]);
                    return $this->formatarMinhaReceita($data);
                }
            }

            Log::warning('Minha Receita não retornou dados válidos', [
                'status' => $response->status()
            ]);

            return null;

        } catch (Exception $e) {
            Log::error('Erro ao consultar Minha Receita', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Consulta na BrasilAPI
     */
    private function consultarBrasilApi(string $cnpj): ?array
    {
        try {
            $response = $this->httpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'InfoVISA/3.0'
                ])
                ->get(self::BRASIL_API_URL . '/' . $cnpj);

            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['cnpj'])) {
                    return $this->formatarBrasilApi($data);
                }
            }

            return null;

        } catch (Exception $e) {
            Log::warning('Erro ao consultar BrasilAPI', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Consulta na ReceitaWS
     */
    private function consultarReceitaWs(string $cnpj): ?array
    {
        try {
            $response = $this->httpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'InfoVISA/3.0'
                ])
                ->get(self::RECEITA_WS_URL . '/' . $cnpj);

            if ($response->successful()) {
                $data = $response->json();
                
                // ReceitaWS retorna status=ERROR quando não encontra
                if (isset($data['status']) && $data['status'] === 'ERROR') {
                    return null;
                }
                
                if (isset($data['cnpj'])) {
                    return $this->formatarReceitaWs($data);
                }
            }

            return null;

        } catch (Exception $e) {
            Log::warning('Erro ao consultar ReceitaWS', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Consulta na API Publica CNPJ WS (dados atualizados da Receita Federal)
     */
    private function consultarPublicaCnpjWs(string $cnpj): ?array
    {
        try {
            $response = $this->httpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'InfoVISA/3.0'
                ])
                ->get(self::PUBLICA_CNPJ_URL . '/' . $cnpj);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['razao_social'])) {
                    return $this->formatarPublicaCnpjWs($data);
                }
            }

            return null;

        } catch (Exception $e) {
            Log::warning('Erro ao consultar Publica CNPJ WS', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Formata dados da API Publica CNPJ WS
     */
    private function formatarPublicaCnpjWs(array $data): array
    {
        $estabelecimento = $data['estabelecimento'] ?? [];
        $cnaePrincipal = $estabelecimento['atividade_principal'] ?? [];
        $cnaesSecundarios = [];

        if (isset($estabelecimento['atividades_secundarias']) && is_array($estabelecimento['atividades_secundarias'])) {
            foreach ($estabelecimento['atividades_secundarias'] as $cnae) {
                $cnaesSecundarios[] = [
                    'codigo' => $cnae['id'] ?? '',
                    'descricao' => $cnae['descricao'] ?? '',
                ];
            }
        }

        $qsa = [];
        if (isset($data['socios']) && is_array($data['socios'])) {
            foreach ($data['socios'] as $socio) {
                $qsa[] = [
                    'nome_socio' => $socio['nome'] ?? '',
                    'qualificacao_socio' => $socio['qualificacao'] ?? '',
                ];
            }
        }

        $logradouro = trim(($estabelecimento['tipo_logradouro'] ?? '') . ' ' . ($estabelecimento['logradouro'] ?? ''));
        $natureza = ($data['natureza_juridica']['id'] ?? '') . ' - ' . ($data['natureza_juridica']['descricao'] ?? '');
        $telefone1 = ($estabelecimento['ddd1'] ?? '') . ($estabelecimento['telefone1'] ?? '');
        $telefone2 = ($estabelecimento['ddd2'] ?? '') . ($estabelecimento['telefone2'] ?? '');

        return [
            'cnpj' => preg_replace('/[^0-9]/', '', $data['estabelecimento']['cnpj'] ?? ''),
            'razao_social' => $data['razao_social'] ?? null,
            'nome_fantasia' => $estabelecimento['nome_fantasia'] ?? $data['razao_social'] ?? null,

            'logradouro' => $logradouro ?: null,
            'endereco' => $logradouro ?: null,
            'numero' => $estabelecimento['numero'] ?? 'S/N',
            'complemento' => $estabelecimento['complemento'] ?? null,
            'bairro' => $estabelecimento['bairro'] ?? null,
            'cidade' => $estabelecimento['cidade']['nome'] ?? null,
            'estado' => $estabelecimento['estado']['sigla'] ?? null,
            'cep' => $estabelecimento['cep'] ?? null,
            'codigo_municipio_ibge' => $estabelecimento['cidade']['ibge_id'] ?? null,

            'telefone' => $telefone1 ?: ($telefone2 ?: null),
            'email' => $estabelecimento['email'] ?? null,
            'ddd_telefone_1' => $telefone1,
            'ddd_telefone_2' => $telefone2,
            'ddd_fax' => '',

            'natureza_juridica' => $natureza,
            'tipo_setor' => NaturezaJuridicaHelper::inferirTipoSetor($natureza),
            'porte' => $data['porte']['descricao'] ?? null,
            'situacao_cadastral' => strtoupper($estabelecimento['situacao_cadastral'] ?? ''),
            'descricao_situacao_cadastral' => strtoupper($estabelecimento['situacao_cadastral'] ?? ''),
            'data_situacao_cadastral' => $this->formatarData($estabelecimento['data_situacao_cadastral'] ?? null),
            'data_inicio_atividade' => $this->formatarData($estabelecimento['data_inicio_atividade'] ?? null),

            'cnae_fiscal' => $cnaePrincipal['id'] ?? null,
            'cnae_fiscal_descricao' => $cnaePrincipal['descricao'] ?? null,
            'cnaes_secundarios' => $cnaesSecundarios,
            'atividade_principal' => $cnaePrincipal['descricao'] ?? null,

            'qsa' => $qsa,

            'capital_social' => $data['capital_social'] ?? null,
            'opcao_pelo_mei' => $data['simei']['optante'] ?? false,
            'opcao_pelo_simples' => $data['simples']['optante'] ?? false,
            'data_opcao_pelo_simples' => $this->formatarData($data['simples']['data_opcao'] ?? null),
            'data_exclusao_do_simples' => $this->formatarData($data['simples']['data_exclusao'] ?? null),
            'regime_tributario' => [],
            'situacao_especial' => '',
            'motivo_situacao_cadastral' => '',
            'descricao_motivo_situacao_cadastral' => '',
            'identificador_matriz_filial' => $estabelecimento['tipo'] ?? '',
            'qualificacao_do_responsavel' => $data['qualificacao_do_responsavel']['descricao'] ?? '',

            'tipo_pessoa' => 'juridica',
            'ativo' => strtoupper($estabelecimento['situacao_cadastral'] ?? '') === 'ATIVA' || ($estabelecimento['situacao_cadastral'] ?? '') === 'Ativa',
            'api_source' => 'publica_cnpj_ws',
        ];
    }

    /**
     * Consulta na API CNPJa Commercial (último fallback - consulta em tempo real)
     *
     * Esta API é PAGA por crédito e só é chamada quando todas as APIs gratuitas falham.
     * Ideal para CNPJs recentes que ainda não constam nas bases públicas da Receita.
     */
    private function consultarCnpjaCommercial(string $cnpj): ?array
    {
        try {
            $token = config('app.cnpja_api_token');

            if (empty($token)) {
                Log::warning('Token CNPJa Commercial não configurado (CNPJA_API_TOKEN)', ['cnpj' => $cnpj]);
                return null;
            }

            $response = $this->httpClient()
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Authorization' => $token,
                    'User-Agent' => 'InfoVISA/3.0',
                ])
                ->get(self::CNPJA_COMMERCIAL_URL . '/' . $cnpj);

            Log::info('Resposta CNPJa Commercial', [
                'status' => $response->status(),
                'successful' => $response->successful(),
            ]);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['taxId']) || isset($data['company']['name'])) {
                    return $this->formatarCnpjaCommercial($data);
                }
            }

            return null;

        } catch (Exception $e) {
            Log::warning('Erro ao consultar CNPJa Commercial', [
                'cnpj' => $cnpj,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Formata dados da API CNPJa Commercial
     *
     * Estrutura retornada:
     * - taxId, company.name, alias, company.nature, company.size, company.equity
     * - address.{street, number, details, district, city, state, zip, municipality}
     * - phones[].{type, area, number}, emails[].{ownership, address}
     * - mainActivity.{id, text}, sideActivities[].{id, text}
     * - status.{id, text}, founded, statusDate, head
     * - company.members[] (quadro societário)
     */
    private function formatarCnpjaCommercial(array $data): array
    {
        $company = $data['company'] ?? [];
        $address = $data['address'] ?? [];
        $mainActivity = $data['mainActivity'] ?? [];

        // Atividades secundárias
        $cnaesSecundarios = [];
        foreach (($data['sideActivities'] ?? []) as $cnae) {
            $cnaesSecundarios[] = [
                'codigo' => (string) ($cnae['id'] ?? ''),
                'descricao' => $cnae['text'] ?? '',
            ];
        }

        // Quadro societário
        $qsa = [];
        foreach (($company['members'] ?? []) as $socio) {
            $qsa[] = [
                'nome_socio' => $socio['person']['name'] ?? ($socio['name'] ?? ''),
                'qualificacao_socio' => $socio['role']['text'] ?? ($socio['qualification'] ?? ''),
            ];
        }

        // Telefones: prefere o primeiro disponível, monta ddd+numero
        $telefone = null;
        $dddTelefone1 = '';
        $dddTelefone2 = '';
        $phones = $data['phones'] ?? [];
        if (!empty($phones[0])) {
            $dddTelefone1 = ($phones[0]['area'] ?? '') . ($phones[0]['number'] ?? '');
            $telefone = $dddTelefone1;
        }
        if (!empty($phones[1])) {
            $dddTelefone2 = ($phones[1]['area'] ?? '') . ($phones[1]['number'] ?? '');
        }

        // E-mail: pega o primeiro
        $email = $data['emails'][0]['address'] ?? null;

        // Natureza jurídica no formato "codigo - descricao"
        $natureza = '';
        if (isset($company['nature'])) {
            $natId = $company['nature']['id'] ?? '';
            $natText = $company['nature']['text'] ?? '';
            $natureza = trim(($natId ? $natId . ' - ' : '') . $natText);
        }

        $situacao = strtoupper($data['status']['text'] ?? '');
        $ativo = $situacao === 'ATIVA';

        // Tipo matriz/filial: head=true é matriz, false é filial
        $matrizFilial = ($data['head'] ?? false) ? 'MATRIZ' : 'FILIAL';

        // Endereço completo
        $logradouro = trim($address['street'] ?? '');

        return [
            'cnpj' => preg_replace('/[^0-9]/', '', $data['taxId'] ?? ''),
            'razao_social' => $company['name'] ?? null,
            'nome_fantasia' => $data['alias'] ?? ($company['name'] ?? null),

            // Endereço
            'logradouro' => $logradouro ?: null,
            'endereco' => $logradouro ?: null,
            'numero' => $address['number'] ?? 'S/N',
            'complemento' => $address['details'] ?? null,
            'bairro' => $address['district'] ?? null,
            'cidade' => $address['city'] ?? null,
            'estado' => $address['state'] ?? null,
            'cep' => preg_replace('/[^0-9]/', '', $address['zip'] ?? ''),
            'codigo_municipio_ibge' => $address['municipality'] ?? null,

            // Contato
            'telefone' => $telefone,
            'email' => $email,
            'ddd_telefone_1' => $dddTelefone1,
            'ddd_telefone_2' => $dddTelefone2,
            'ddd_fax' => '',

            // Dados empresariais
            'natureza_juridica' => $natureza ?: null,
            'tipo_setor' => NaturezaJuridicaHelper::inferirTipoSetor($natureza),
            'porte' => $company['size']['text'] ?? null,
            'situacao_cadastral' => $situacao,
            'descricao_situacao_cadastral' => $situacao,
            'data_situacao_cadastral' => $this->formatarData($data['statusDate'] ?? null),
            'data_inicio_atividade' => $this->formatarData($data['founded'] ?? null),

            // CNAE
            'cnae_fiscal' => isset($mainActivity['id']) ? (string) $mainActivity['id'] : null,
            'cnae_fiscal_descricao' => $mainActivity['text'] ?? null,
            'cnaes_secundarios' => $cnaesSecundarios,
            'atividade_principal' => $mainActivity['text'] ?? null,

            // Quadro Societário
            'qsa' => $qsa,

            // Outros dados
            'capital_social' => $company['equity'] ?? null,
            'opcao_pelo_mei' => null,
            'opcao_pelo_simples' => null,
            'regime_tributario' => [],
            'situacao_especial' => '',
            'motivo_situacao_cadastral' => '',
            'descricao_motivo_situacao_cadastral' => '',
            'identificador_matriz_filial' => $matrizFilial,
            'qualificacao_do_responsavel' => '',

            'tipo_pessoa' => 'juridica',
            'ativo' => $ativo,
            'api_source' => 'cnpja_commercial',
        ];
    }

    /**
     * Formata dados da API Minha Receita
     */
    private function formatarMinhaReceita(array $data): array
    {
        // Formata endereço completo
        $endereco = ($data['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($data['logradouro'] ?? '');
        $endereco = trim($endereco);
        
        return [
            // Dados básicos
            'cnpj' => $data['cnpj'] ?? null,
            'razao_social' => $data['razao_social'] ?? null,
            'nome_fantasia' => $data['nome_fantasia'] ?? $data['razao_social'] ?? null,
            
            // Endereço
            'logradouro' => $endereco ?: ($data['logradouro'] ?? null),
            'endereco' => $endereco ?: ($data['logradouro'] ?? null),
            'numero' => $data['numero'] ?? 'S/N',
            'complemento' => $data['complemento'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'cidade' => $data['municipio'] ?? null,
            'estado' => $data['uf'] ?? null,
            'cep' => $data['cep'] ?? null,
            'codigo_municipio_ibge' => $data['codigo_municipio_ibge'] ?? null,
            
            // Contato
            'telefone' => $this->formatarTelefone($data),
            'email' => $data['email'] ?? null,
            'ddd_telefone_1' => $data['ddd_telefone_1'] ?? '',
            'ddd_telefone_2' => $data['ddd_telefone_2'] ?? '',
            'ddd_fax' => $data['ddd_fax'] ?? '',
            
            // Dados empresariais
            'natureza_juridica' => $data['natureza_juridica'] ?? null,
            'tipo_setor' => NaturezaJuridicaHelper::inferirTipoSetor($data['natureza_juridica'] ?? ''),
            'porte' => $data['porte'] ?? null,
            'situacao_cadastral' => $data['situacao_cadastral'] ?? null,
            'descricao_situacao_cadastral' => $data['descricao_situacao_cadastral'] ?? null,
            'data_situacao_cadastral' => $this->formatarData($data['data_situacao_cadastral'] ?? null),
            'data_inicio_atividade' => $this->formatarData($data['data_inicio_atividade'] ?? null),
            
            // CNAE
            'cnae_fiscal' => $data['cnae_fiscal'] ?? null,
            'cnae_fiscal_descricao' => $data['cnae_fiscal_descricao'] ?? null,
            'cnaes_secundarios' => $data['cnaes_secundarios'] ?? [],
            'atividade_principal' => $data['cnae_fiscal_descricao'] ?? null,
            
            // Quadro Societário
            'qsa' => $data['qsa'] ?? [],
            
            // Outros dados
            'capital_social' => $data['capital_social'] ?? null,
            'opcao_pelo_mei' => $data['opcao_pelo_mei'] ?? false,
            'opcao_pelo_simples' => $data['opcao_pelo_simples'] ?? false,
            'data_opcao_pelo_simples' => $this->formatarData($data['data_opcao_pelo_simples'] ?? null),
            'data_exclusao_do_simples' => $this->formatarData($data['data_exclusao_do_simples'] ?? null),
            'regime_tributario' => $data['regime_tributario'] ?? [],
            'situacao_especial' => $data['situacao_especial'] ?? '',
            'motivo_situacao_cadastral' => $data['motivo_situacao_cadastral'] ?? '',
            'descricao_motivo_situacao_cadastral' => $data['descricao_motivo_situacao_cadastral'] ?? '',
            'identificador_matriz_filial' => $data['descricao_identificador_matriz_filial'] ?? '',
            'qualificacao_do_responsavel' => $data['qualificacao_do_responsavel'] ?? '',
            
            // Campos do sistema
            'tipo_pessoa' => 'juridica',
            'ativo' => ($data['situacao_cadastral'] ?? 0) == 2,
            'api_source' => 'minha_receita', // Identificador da fonte
        ];
    }

    /**
     * Formata dados da BrasilAPI
     */
    private function formatarBrasilApi(array $data): array
    {
        // BrasilAPI usa estrutura um pouco diferente
        $qsa = [];
        if (isset($data['qsa']) && is_array($data['qsa'])) {
            foreach ($data['qsa'] as $socio) {
                $qsa[] = [
                    'nome_socio' => $socio['nome_socio'] ?? '',
                    'qualificacao_socio' => $socio['qualificacao_socio'] ?? '',
                    'faixa_etaria' => $socio['faixa_etaria'] ?? '',
                ];
            }
        }

        $cnaes_secundarios = [];
        if (isset($data['cnaes_secundarios']) && is_array($data['cnaes_secundarios'])) {
            foreach ($data['cnaes_secundarios'] as $cnae) {
                $cnaes_secundarios[] = [
                    'codigo' => $cnae['codigo'] ?? '',
                    'descricao' => $cnae['descricao'] ?? '',
                ];
            }
        }

        return [
            'cnpj' => $data['cnpj'] ?? null,
            'razao_social' => $data['razao_social'] ?? null,
            'nome_fantasia' => $data['nome_fantasia'] ?? $data['razao_social'] ?? null,
            
            // Endereço
            'logradouro' => $data['descricao_tipo_de_logradouro'] . ' ' . $data['logradouro'] ?? null,
            'endereco' => $data['descricao_tipo_de_logradouro'] . ' ' . $data['logradouro'] ?? null,
            'numero' => $data['numero'] ?? 'S/N',
            'complemento' => $data['complemento'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'cidade' => $data['municipio'] ?? null,
            'estado' => $data['uf'] ?? null,
            'cep' => $data['cep'] ?? null,
            'codigo_municipio_ibge' => $data['codigo_municipio_ibge'] ?? null,
            
            // Contato
            'telefone' => $this->formatarTelefoneBrasilApi($data),
            'email' => null, // BrasilAPI não retorna email
            'ddd_telefone_1' => $data['ddd_telefone_1'] ?? '',
            'ddd_telefone_2' => $data['ddd_telefone_2'] ?? '',
            'ddd_fax' => $data['ddd_fax'] ?? '',
            
            // Dados empresariais
            'natureza_juridica' => $data['natureza_juridica'] ?? null,
            'tipo_setor' => NaturezaJuridicaHelper::inferirTipoSetor($data['natureza_juridica'] ?? ''),
            'porte' => $data['porte'] ?? null,
            'situacao_cadastral' => $data['situacao_cadastral'] ?? null,
            'descricao_situacao_cadastral' => $data['descricao_situacao_cadastral'] ?? null,
            'data_situacao_cadastral' => $this->formatarData($data['data_situacao_cadastral'] ?? null),
            'data_inicio_atividade' => $this->formatarData($data['data_inicio_atividade'] ?? null),
            
            // CNAE
            'cnae_fiscal' => $data['cnae_fiscal'] ?? null,
            'cnae_fiscal_descricao' => $data['cnae_fiscal_descricao'] ?? null,
            'cnaes_secundarios' => $cnaes_secundarios,
            'atividade_principal' => $data['cnae_fiscal_descricao'] ?? null,
            
            // Quadro Societário
            'qsa' => $qsa,
            
            // Outros dados
            'capital_social' => $data['capital_social'] ?? null,
            'opcao_pelo_mei' => $data['opcao_pelo_mei'] ?? null,
            'opcao_pelo_simples' => $data['opcao_pelo_simples'] ?? null,
            'regime_tributario' => [],
            'situacao_especial' => $data['situacao_especial'] ?? '',
            'motivo_situacao_cadastral' => $data['motivo_situacao_cadastral'] ?? '',
            'descricao_motivo_situacao_cadastral' => $data['descricao_motivo_situacao_cadastral'] ?? '',
            'identificador_matriz_filial' => $data['descricao_identificador_matriz_filial'] ?? '',
            'qualificacao_do_responsavel' => '',
            
            'tipo_pessoa' => 'juridica',
            'ativo' => ($data['codigo_situacao_cadastral'] ?? 0) == 2,
            'api_source' => 'brasil_api', // Identificador da fonte
        ];
    }

    /**
     * Formata dados da ReceitaWS
     */
    private function formatarReceitaWs(array $data): array
    {
        // ReceitaWS usa estrutura diferente
        $qsa = [];
        if (isset($data['qsa']) && is_array($data['qsa'])) {
            foreach ($data['qsa'] as $socio) {
                $qsa[] = [
                    'nome_socio' => $socio['nome'] ?? '',
                    'qualificacao_socio' => $socio['qual'] ?? '',
                ];
            }
        }

        $cnaes_secundarios = [];
        if (isset($data['atividades_secundarias']) && is_array($data['atividades_secundarias'])) {
            foreach ($data['atividades_secundarias'] as $cnae) {
                $cnaes_secundarios[] = [
                    'codigo' => $cnae['code'] ?? '',
                    'descricao' => $cnae['text'] ?? '',
                ];
            }
        }

        return [
            'cnpj' => preg_replace('/[^0-9]/', '', $data['cnpj'] ?? ''),
            'razao_social' => $data['nome'] ?? null,
            'nome_fantasia' => $data['fantasia'] ?? $data['nome'] ?? null,
            
            // Endereço
            'logradouro' => $data['logradouro'] ?? null,
            'endereco' => $data['logradouro'] ?? null,
            'numero' => $data['numero'] ?? 'S/N',
            'complemento' => $data['complemento'] ?? null,
            'bairro' => $data['bairro'] ?? null,
            'cidade' => $data['municipio'] ?? null,
            'estado' => $data['uf'] ?? null,
            'cep' => preg_replace('/[^0-9]/', '', $data['cep'] ?? ''),
            'codigo_municipio_ibge' => null,
            
            // Contato
            'telefone' => preg_replace('/[^0-9]/', '', $data['telefone'] ?? ''),
            'email' => $data['email'] ?? null,
            'ddd_telefone_1' => '',
            'ddd_telefone_2' => '',
            'ddd_fax' => '',
            
            // Dados empresariais
            'natureza_juridica' => $data['natureza_juridica'] ?? null,
            'tipo_setor' => NaturezaJuridicaHelper::inferirTipoSetor($data['natureza_juridica'] ?? ''),
            'porte' => $data['porte'] ?? null,
            'situacao_cadastral' => $data['situacao'] ?? null,
            'descricao_situacao_cadastral' => $data['situacao'] ?? null,
            'data_situacao_cadastral' => $this->formatarData($data['data_situacao'] ?? null),
            'data_inicio_atividade' => $this->formatarData($data['abertura'] ?? null),
            
            // CNAE
            'cnae_fiscal' => preg_replace('/[^0-9]/', '', $data['atividade_principal'][0]['code'] ?? ''),
            'cnae_fiscal_descricao' => $data['atividade_principal'][0]['text'] ?? null,
            'cnaes_secundarios' => $cnaes_secundarios,
            'atividade_principal' => $data['atividade_principal'][0]['text'] ?? null,
            
            // Quadro Societário
            'qsa' => $qsa,
            
            // Outros dados
            'capital_social' => $data['capital_social'] ?? null,
            'opcao_pelo_mei' => null,
            'opcao_pelo_simples' => null,
            'regime_tributario' => [],
            'situacao_especial' => $data['situacao_especial'] ?? '',
            'motivo_situacao_cadastral' => $data['motivo_situacao'] ?? '',
            'descricao_motivo_situacao_cadastral' => $data['motivo_situacao'] ?? '',
            'identificador_matriz_filial' => $data['tipo'] ?? '',
            'qualificacao_do_responsavel' => '',
            
            'tipo_pessoa' => 'juridica',
            'ativo' => strtoupper($data['situacao'] ?? '') === 'ATIVA',
            'api_source' => 'receita_ws', // Identificador da fonte
        ];
    }

    /**
     * Formata telefone dos dados da API
     */
    private function formatarTelefone(array $data): ?string
    {
        $ddd1 = $data['ddd_telefone_1'] ?? '';
        $tel1 = $data['telefone_1'] ?? '';
        
        if (!empty($ddd1) && !empty($tel1)) {
            return $ddd1 . $tel1;
        }
        
        $ddd2 = $data['ddd_telefone_2'] ?? '';
        $tel2 = $data['telefone_2'] ?? '';
        
        if (!empty($ddd2) && !empty($tel2)) {
            return $ddd2 . $tel2;
        }
        
        return null;
    }

    /**
     * Formata telefone da BrasilAPI
     */
    private function formatarTelefoneBrasilApi(array $data): ?string
    {
        $ddd1 = $data['ddd_telefone_1'] ?? '';
        
        if (!empty($ddd1)) {
            return $ddd1;
        }
        
        $ddd2 = $data['ddd_telefone_2'] ?? '';
        
        if (!empty($ddd2)) {
            return $ddd2;
        }
        
        return null;
    }

    /**
     * Formata data para formato Y-m-d
     */
    private function formatarData(?string $data): ?string
    {
        if (empty($data)) {
            return null;
        }

        try {
            // Tenta vários formatos de data
            $formatos = ['Y-m-d', 'd/m/Y', 'Y/m/d'];
            
            foreach ($formatos as $formato) {
                $date = \DateTime::createFromFormat($formato, $data);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            }
            
            // Se nenhum formato funcionar, tenta strtotime
            return date('Y-m-d', strtotime($data));
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Formata CNPJ para exibição
     */
    public static function formatarCnpj(string $cnpj): string
    {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        if (strlen($cnpj) === 14) {
            return substr($cnpj, 0, 2) . '.' . 
                   substr($cnpj, 2, 3) . '.' . 
                   substr($cnpj, 5, 3) . '/' . 
                   substr($cnpj, 8, 4) . '-' . 
                   substr($cnpj, 12, 2);
        }
        
        return $cnpj;
    }

    /**
     * Valida CNPJ
     */
    public static function validarCnpj(string $cnpj): bool
    {
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        if (strlen($cnpj) != 14) {
            return false;
        }
        
        // Verifica se todos os dígitos são iguais
        if (preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }
        
        // Calcula os dígitos verificadores
        for ($i = 0, $j = 5, $soma = 0; $i < 12; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        
        $resto = $soma % 11;
        
        if ($cnpj[12] != ($resto < 2 ? 0 : 11 - $resto)) {
            return false;
        }
        
        for ($i = 0, $j = 6, $soma = 0; $i < 13; $i++) {
            $soma += $cnpj[$i] * $j;
            $j = ($j == 2) ? 9 : $j - 1;
        }
        
        $resto = $soma % 11;
        
        return $cnpj[13] == ($resto < 2 ? 0 : 11 - $resto);
    }
}
