<?php

namespace App\Models;

use App\Enums\TipoSetor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Estabelecimento extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Os atributos que podem ser atribuídos em massa
     */
    protected $fillable = [
        'nome_fantasia',
        'razao_social',
        'cnpj',
        'cpf',
        'nome_completo',
        'rg',
        'orgao_emissor',
        'inscricao_estadual',
        'endereco',
        'numero',
        'complemento',
        'bairro',
        'cidade',
        'estado',
        'cep',
        'telefone',
        'email',
        'tipo_estabelecimento',
        'atividade_principal',
        'ativo',
        'motivo_desativacao',
        'situacao',
        'usuario_externo_id',
        // Campos da API
        'natureza_juridica',
        'porte',
        'situacao_cadastral',
        'data_situacao_cadastral',
        'data_inicio_atividade',
        'cnae_fiscal',
        'cnae_fiscal_descricao',
        'cnaes_secundarios',
        'qsa',
        'capital_social',
        'logradouro',
        'codigo_municipio_ibge',
        'tipo_pessoa',
        'produtor_rural',
        'is_unidade_movel',
        'tipo_unidade_movel',
        'respostas_unidade_movel',
        'status_unidade_movel',
        'motivo_rejeicao_unidade_movel',
        'tipo_setor',
        'atividades_exercidas',
        'respostas_questionario',
        'respostas_questionario2',
        'competencia_manual',
        'motivo_alteracao_competencia',
        'alterado_por',
        'alterado_em',
        // Campos adicionais da API
        'ddd_telefone_1',
        'ddd_telefone_2',
        'ddd_fax',
        'opcao_pelo_mei',
        'opcao_pelo_simples',
        'regime_tributario',
        'situacao_especial',
        'motivo_situacao_cadastral',
        'descricao_situacao_cadastral',
        'descricao_motivo_situacao_cadastral',
        'identificador_matriz_filial',
        'qualificacao_do_responsavel',
        // Campos de aprovação
        'status',
        'municipio',
        'municipio_id',
        'motivo_rejeicao',
        'aprovado_por',
        'aprovado_em',
        // Declaração sem equipamentos de imagem
        'declaracao_sem_equipamentos_imagem',
        'declaracao_sem_equipamentos_imagem_data',
        'declaracao_sem_equipamentos_imagem_justificativa',
        'declaracao_sem_equipamentos_imagem_usuario_id',
        'declaracao_sem_equipamentos_opcoes',
    ];

    /**
     * Os atributos que devem ser convertidos para tipos nativos
     */
    protected $casts = [
        'ativo' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'data_situacao_cadastral' => 'date',
        'data_inicio_atividade' => 'date',
        'cnaes_secundarios' => 'array',
        'declaracao_sem_equipamentos_opcoes' => 'array',
        'qsa' => 'array',
        'capital_social' => 'decimal:2',
        'opcao_pelo_mei' => 'boolean',
        'opcao_pelo_simples' => 'boolean',
        'regime_tributario' => 'array',
        'atividades_exercidas' => 'array',
        'respostas_questionario' => 'array',
        'respostas_questionario2' => 'array',
        'is_unidade_movel' => 'boolean',
        'produtor_rural' => 'boolean',
        'respostas_unidade_movel' => 'array',
        'tipo_setor' => TipoSetor::class,
        'aprovado_em' => 'datetime',
        'alterado_em' => 'datetime',
        'declaracao_sem_equipamentos_imagem' => 'boolean',
        'declaracao_sem_equipamentos_imagem_data' => 'datetime',
    ];

    /**
     * Relacionamento com usuário externo
     */
    public function usuarioExterno()
    {
        return $this->belongsTo(UsuarioExterno::class);
    }

    /**
     * Relacionamento com município
     */
    public function municipio()
    {
        return $this->belongsTo(Municipio::class);
    }

    /**
     * Documentos obrigatórios definidos manualmente pela vigilância sanitária municipal
     * (usado quando o município tem o modo "documentos manuais" habilitado)
     */
    public function documentosManuais()
    {
        return $this->belongsToMany(
            TipoDocumentoObrigatorio::class,
            'estabelecimento_documento_manual',
            'estabelecimento_id',
            'tipo_documento_obrigatorio_id'
        )->withPivot('definido_por')->withTimestamps();
    }

    /**
     * Verifica se o estabelecimento usa definição manual de documentos obrigatórios
     * (competência municipal + município com o modo habilitado)
     */
    public function usaDocumentosManuais(): bool
    {
        if (!$this->municipio_id || $this->isCompetenciaEstadual()) {
            return false;
        }

        // Atenção: a coluna string 'municipio' conflita com o relacionamento,
        // então busca o model explicitamente
        $municipio = $this->relationLoaded('municipio') && is_object($this->getRelation('municipio'))
            ? $this->getRelation('municipio')
            : Municipio::find($this->municipio_id);

        return (bool) ($municipio?->documentos_manuais);
    }

    /**
     * Alias para relacionamento com município (consistência com outros models)
     */
    public function municipioRelacionado()
    {
        return $this->municipio();
    }

    /**
     * Relacionamento com responsáveis
     */
    public function responsaveis()
    {
        return $this->belongsToMany(Responsavel::class, 'estabelecimento_responsavel')
                    ->withPivot('tipo_vinculo', 'ativo')
                    ->withTimestamps();
    }

    /**
     * Responsáveis legais ativos
     */
    public function responsaveisLegais()
    {
        return $this->responsaveis()->wherePivot('tipo_vinculo', 'legal')->wherePivot('ativo', true);
    }

    /**
     * Responsáveis técnicos ativos
     */
    public function responsaveisTecnicos()
    {
        return $this->responsaveis()->wherePivot('tipo_vinculo', 'tecnico')->wherePivot('ativo', true);
    }

    /**
     * Relacionamento com processos
     */
    public function processos()
    {
        return $this->hasMany(Processo::class);
    }

    /**
     * Municípios de atuação (P4) — apenas para PJ Unidade Móvel
     */
    public function municipiosAtuacao()
    {
        return $this->hasMany(EstabelecimentoMunicipioAtuacao::class);
    }

    /**
     * Relacionamento com equipamentos de radiação ionizante
     */
    public function equipamentosRadiacao()
    {
        return $this->hasMany(EquipamentoRadiacao::class);
    }

    /**
     * Relacionamento com usuário que fez a declaração de sem equipamentos
     */
    public function declaracaoSemEquipamentosUsuario()
    {
        return $this->belongsTo(UsuarioExterno::class, 'declaracao_sem_equipamentos_imagem_usuario_id');
    }

    /**
     * Verifica se o estabelecimento declarou não possuir equipamentos de imagem
     */
    public function declarouSemEquipamentosImagem(): bool
    {
        return (bool) $this->declaracao_sem_equipamentos_imagem;
    }

    /**
     * Verifica se precisa cadastrar equipamentos (exige equipamentos E não declarou que não tem E não tem equipamentos cadastrados)
     */
    public function precisaCadastrarEquipamentosImagem(): bool
    {
        // Se não exige equipamentos, não precisa cadastrar
        if (!AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentos($this)) {
            return false;
        }

        // Se declarou que não tem, não precisa cadastrar
        if ($this->declarouSemEquipamentosImagem()) {
            return false;
        }

        // Se já tem equipamentos cadastrados, não precisa mais
        if ($this->equipamentosRadiacao()->count() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se precisa cadastrar equipamentos para um tipo de processo específico
     */
    public function precisaCadastrarEquipamentosImagemParaProcesso(string $tipoProcessoCodigo): bool
    {
        // Se não exige equipamentos para este tipo de processo, não precisa cadastrar
        if (!AtividadeEquipamentoRadiacao::estabelecimentoExigeEquipamentosParaProcesso($this, $tipoProcessoCodigo)) {
            return false;
        }

        // Se declarou que não tem, não precisa cadastrar
        if ($this->declarouSemEquipamentosImagem()) {
            return false;
        }

        // Se já tem equipamentos cadastrados, não precisa mais
        if ($this->equipamentosRadiacao()->count() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Verifica se precisa cadastrar responsável legal
     */
    public function precisaCadastrarResponsavelLegal(): bool
    {
        return $this->responsaveisLegais()->count() === 0;
    }

    /**
     * Verifica se precisa cadastrar responsável técnico por regra de atividade
     */
    public function precisaCadastrarResponsavelTecnicoPorAtividade(): bool
    {
        if (!AtividadeResponsavelTecnico::estabelecimentoExigeResponsavelTecnico($this)) {
            return false;
        }

        if ($this->responsaveisTecnicos()->count() > 0) {
            return false;
        }

        return true;
    }

    /**
     * Retorna as atividades exercidas do estabelecimento que exigem responsável técnico
     */
    public function getAtividadesQueExigemResponsavelTecnico(): array
    {
        if (empty($this->atividades_exercidas)) {
            return [];
        }

        $atividadesObrigatoriasNormalizadas = AtividadeResponsavelTecnico::where('ativo', true)
            ->get()
            ->map(fn($atividade) => AtividadeResponsavelTecnico::normalizarCodigo($atividade->codigo_atividade))
            ->toArray();

        return collect($this->atividades_exercidas)
            ->filter(function ($atividade) use ($atividadesObrigatoriasNormalizadas) {
                $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                if (!$codigo) {
                    return false;
                }

                return in_array(AtividadeResponsavelTecnico::normalizarCodigo((string) $codigo), $atividadesObrigatoriasNormalizadas);
            })
            ->values()
            ->toArray();
    }

    /**
     * Relacionamento com histórico
     */
    public function historicos()
    {
        return $this->hasMany(EstabelecimentoHistorico::class);
    }

    /**
     * Relacionamento com usuário que aprovou
     */
    public function aprovadoPor()
    {
        return $this->belongsTo(UsuarioInterno::class, 'aprovado_por');
    }

    /**
     * Relacionamento com usuários externos vinculados
     */
    public function usuariosVinculados()
    {
        return $this->belongsToMany(UsuarioExterno::class, 'estabelecimento_usuario_externo')
                    ->withPivot('tipo_vinculo', 'observacao', 'vinculado_por', 'nivel_acesso')
                    ->withTimestamps();
    }

    /**
     * Verifica se o estabelecimento possui ao menos um usuário externo
     * apto a receber ciência e visualizar documentos vinculados.
     */
    public function possuiUsuariosExternosVinculados(): bool
    {
        if (!empty($this->usuario_externo_id)) {
            return true;
        }

        if ($this->relationLoaded('usuariosVinculados')) {
            return $this->usuariosVinculados->isNotEmpty();
        }

        return $this->usuariosVinculados()->exists();
    }

    /**
     * Verifica se o usuário tem acesso de gestor (pode editar) no estabelecimento
     * Retorna true se:
     * - É o criador do estabelecimento
     * - É um usuário vinculado com nivel_acesso = 'gestor'
     * 
     * @param int|null $usuarioId ID do usuário externo (se null, usa o usuário logado)
     * @return bool
     */
    public function usuarioTemAcessoGestor(?int $usuarioId = null): bool
    {
        $usuarioId = $usuarioId ?? auth('externo')->id();
        
        if (!$usuarioId) {
            return false;
        }
        
        // Criador do estabelecimento sempre tem acesso total
        if ($this->usuario_externo_id == $usuarioId) {
            return true;
        }
        
        // Verifica se é um usuário vinculado com nível 'gestor'
        $vinculo = $this->usuariosVinculados()
            ->where('usuario_externo_id', $usuarioId)
            ->first();
        
        if ($vinculo && ($vinculo->pivot->nivel_acesso ?? 'gestor') === 'gestor') {
            return true;
        }
        
        return false;
    }

    /**
     * Verifica se o usuário é apenas visualizador no estabelecimento
     * 
     * @param int|null $usuarioId ID do usuário externo (se null, usa o usuário logado)
     * @return bool
     */
    public function usuarioEhVisualizador(?int $usuarioId = null): bool
    {
        $usuarioId = $usuarioId ?? auth('externo')->id();
        
        if (!$usuarioId) {
            return false;
        }
        
        // Criador do estabelecimento nunca é visualizador
        if ($this->usuario_externo_id == $usuarioId) {
            return false;
        }
        
        // Verifica se é um usuário vinculado com nível 'visualizador'
        $vinculo = $this->usuariosVinculados()
            ->where('usuario_externo_id', $usuarioId)
            ->first();
        
        if ($vinculo && ($vinculo->pivot->nivel_acesso ?? 'gestor') === 'visualizador') {
            return true;
        }
        
        return false;
    }

    /**
     * Accessor para CNPJ formatado
     */
    public function getCnpjFormatadoAttribute(): string
    {
        if (empty($this->cnpj)) {
            return '';
        }
        
        $cnpj = preg_replace('/[^0-9]/', '', $this->cnpj);
        
        if (strlen($cnpj) === 14) {
            return substr($cnpj, 0, 2) . '.' . 
                   substr($cnpj, 2, 3) . '.' . 
                   substr($cnpj, 5, 3) . '/' . 
                   substr($cnpj, 8, 4) . '-' . 
                   substr($cnpj, 12, 2);
        }
        
        return $this->cnpj;
    }

    /**
     * Accessor para CPF formatado
     */
    public function getCpfFormatadoAttribute(): string
    {
        if (empty($this->cpf)) {
            return '';
        }
        
        $cpf = preg_replace('/[^0-9]/', '', $this->cpf);
        
        if (strlen($cpf) === 11) {
            return substr($cpf, 0, 3) . '.' . 
                   substr($cpf, 3, 3) . '.' . 
                   substr($cpf, 6, 3) . '-' . 
                   substr($cpf, 9, 2);
        }
        
        return $this->cpf;
    }

    /**
     * Accessor para documento formatado (CNPJ ou CPF)
     */
    public function getDocumentoFormatadoAttribute(): string
    {
        if ($this->tipo_pessoa === 'juridica' && !empty($this->cnpj)) {
            return $this->cnpj_formatado;
        } elseif ($this->tipo_pessoa === 'fisica' && !empty($this->cpf)) {
            return $this->cpf_formatado;
        }
        
        return '';
    }

    /**
     * Accessor para nome/razão social
     */
    public function getNomeRazaoSocialAttribute(): string
    {
        if ($this->tipo_pessoa === 'juridica') {
            return $this->razao_social ?? '';
        } else {
            return $this->nome_completo ?? '';
        }
    }

    /**
     * Accessor para CEP formatado
     */
    public function getCepFormatadoAttribute(): string
    {
        $cep = $this->cep;
        return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
    }

    /**
     * Accessor para telefone formatado
     */
    public function getTelefoneFormatadoAttribute(): ?string
    {
        if (!$this->telefone) {
            return null;
        }

        $telefone = preg_replace('/[^0-9]/', '', $this->telefone);

        if (strlen($telefone) === 11) {
            return '(' . substr($telefone, 0, 2) . ') ' .
                   substr($telefone, 2, 5) . '-' .
                   substr($telefone, 7);
        }

        if (strlen($telefone) === 10) {
            return '(' . substr($telefone, 0, 2) . ') ' .
                   substr($telefone, 2, 4) . '-' .
                   substr($telefone, 6);
        }

        return $this->telefone;
    }

    /**
     * Accessor para endereço completo
     */
    public function getEnderecoCompletoAttribute(): string
    {
        return $this->endereco . ', ' . $this->numero .
               ($this->complemento ? ', ' . $this->complemento : '') .
               ' - ' . $this->bairro . ', ' . $this->cidade . ' - ' . $this->estado .
               ', ' . $this->cep_formatado;
    }

    /**
     * Retorna o tipo de estabelecimento legível
     */
    public function getTipoEstabelecimentoLabelAttribute(): string
    {
        return match($this->tipo_estabelecimento) {
            'restaurante' => 'Restaurante',
            'bar' => 'Bar',
            'lanchonete' => 'Lanchonete',
            'supermercado' => 'Supermercado',
            'mercearia' => 'Mercearia',
            'padaria' => 'Padaria',
            'acougue' => 'Açougue',
            'farmacia' => 'Farmácia',
            'hospital' => 'Hospital',
            'clinica' => 'Clínica',
            'laboratorio' => 'Laboratório',
            'pet_shop' => 'Pet Shop',
            'outros' => 'Outros',
            default => $this->tipo_estabelecimento,
        };
    }

    /**
     * Retorna o label da situação cadastral (vem da API)
     */
    public function getSituacaoLabelAttribute(): string
    {
        // A API da Receita Federal retorna códigos numéricos
        $situacao = $this->situacao_cadastral ?? $this->descricao_situacao_cadastral ?? '2';
        
        // Se for texto, usa direto
        if (!is_numeric($situacao)) {
            $situacao = strtoupper($situacao);
            return match($situacao) {
                'ATIVA' => 'Ativa',
                'BAIXADA' => 'Baixada',
                'SUSPENSA' => 'Suspensa',
                'INAPTA' => 'Inapta',
                'NULA' => 'Nula',
                default => ucfirst(strtolower($situacao)),
            };
        }
        
        // Mapeamento dos códigos da Receita Federal
        return match((string)$situacao) {
            '1' => 'Nula',
            '2' => 'Ativa',
            '3' => 'Suspensa',
            '4' => 'Inapta',
            '8' => 'Baixada',
            default => 'Ativa',
        };
    }

    /**
     * Retorna a cor da badge da situação cadastral
     */
    public function getSituacaoCorAttribute(): string
    {
        $situacao = $this->situacao_cadastral ?? $this->descricao_situacao_cadastral ?? '2';
        
        // Se for texto, converte
        if (!is_numeric($situacao)) {
            $situacao = strtoupper($situacao);
            return match($situacao) {
                'ATIVA' => 'bg-green-100 text-green-800',
                'BAIXADA' => 'bg-gray-100 text-gray-800',
                'SUSPENSA' => 'bg-yellow-100 text-yellow-800',
                'INAPTA' => 'bg-red-100 text-red-800',
                'NULA' => 'bg-red-100 text-red-800',
                default => 'bg-gray-100 text-gray-800',
            };
        }
        
        // Mapeamento dos códigos da Receita Federal
        return match((string)$situacao) {
            '1' => 'bg-red-100 text-red-800',      // Nula
            '2' => 'bg-green-100 text-green-800',  // Ativa
            '3' => 'bg-yellow-100 text-yellow-800', // Suspensa
            '4' => 'bg-red-100 text-red-800',      // Inapta
            '8' => 'bg-gray-100 text-gray-800',    // Baixada
            default => 'bg-green-100 text-green-800',
        };
    }

    /**
     * Verifica se o estabelecimento está ativo
     */
    public function isAtivo(): bool
    {
        return $this->ativo === true;
    }

    /**
     * Scope para filtrar estabelecimentos ativos
     */
    public function scopeAtivos($query)
    {
        return $query->where('ativo', true);
    }

    /**
     * Scope para filtrar estabelecimentos por prefixos de CEP.
     * Usado para setores que atendem apenas uma região (ex: Luzimangues = CEP 77502xxx).
     * Se $ceps for vazio, não aplica filtro.
     */
    public function scopeFiltroPorCep($query, array $ceps)
    {
        if (empty($ceps)) {
            return $query;
        }

        return $query->where(function ($q) use ($ceps) {
            foreach ($ceps as $prefixo) {
                $prefixoLimpo = preg_replace('/[^0-9]/', '', $prefixo);
                $q->orWhere('cep', 'LIKE', $prefixoLimpo . '%');
            }
        });
    }

    public function scopeFiltroTerritorial($query, array $ceps = [], array $bairros = [])
    {
        if (empty($ceps) && empty($bairros)) {
            return $query;
        }

        return $query->where(function ($q) use ($ceps, $bairros) {
            foreach ($ceps as $prefixo) {
                $prefixoLimpo = preg_replace('/[^0-9]/', '', (string) $prefixo);
                if ($prefixoLimpo !== '') {
                    $q->orWhere('cep', 'LIKE', $prefixoLimpo . '%');
                }
            }

            foreach ($bairros as $bairro) {
                $bairro = trim((string) $bairro);
                if ($bairro !== '') {
                    $q->orWhere('bairro', 'ilike', '%' . $bairro . '%')
                        ->orWhere('complemento', 'ilike', '%' . $bairro . '%')
                        ->orWhere('endereco', 'ilike', '%' . $bairro . '%');
                }
            }
        });
    }

    /**
     * Scope para filtrar por tipo de estabelecimento
     */
    public function scopeTipo($query, string $tipo)
    {
        return $query->where('tipo_estabelecimento', $tipo);
    }

    /**
     * Scope para buscar estabelecimentos do usuário logado
     */
    public function scopeDoUsuario($query, $usuarioId = null)
    {
        $usuarioId = $usuarioId ?? auth('externo')->id();
        return $query->where('usuario_externo_id', $usuarioId);
    }

    /**
     * Scope para filtrar por município
     */
    public function scopePorMunicipio($query, $municipio)
    {
        return $query->where('municipio', $municipio);
    }

    /**
     * Scope para filtrar por status
     */
    public function scopePorStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope para estabelecimentos pendentes
     */
    public function scopePendentes($query)
    {
        return $query->where('status', 'pendente');
    }

    /**
     * Scope para estabelecimentos aprovados
     */
    public function scopeAprovados($query)
    {
        return $query->where('status', 'aprovado');
    }

    /**
     * Scope para estabelecimentos rejeitados
     */
    public function scopeRejeitados($query)
    {
        return $query->where('status', 'rejeitado');
    }

    /**
     * Scope para filtrar estabelecimentos do município do usuário logado
     */
    public function scopeDoMunicipioUsuario($query)
    {
        if (auth('interno')->check()) {
            $usuario = auth('interno')->user();
            // Se não for administrador, filtra por município
            if (!$usuario->nivel_acesso->isAdmin()) {
                return $query->where('municipio', $usuario->municipio);
            }
        }
        return $query;
    }

    /**
     * Verifica se o estabelecimento está aprovado
     */
    public function isAprovado(): bool
    {
        return $this->status === 'aprovado';
    }

    /**
     * Verifica se o estabelecimento está pendente
     */
    public function isPendente(): bool
    {
        return $this->status === 'pendente';
    }

    /**
     * Verifica se o estabelecimento está rejeitado
     */
    public function isRejeitado(): bool
    {
        return $this->status === 'rejeitado';
    }

    /**
     * Aprova o estabelecimento
     */
    public function aprovar(?string $observacao = null)
    {
        $statusAnterior = $this->status;
        
        $this->update([
            'status' => 'aprovado',
            'aprovado_por' => auth('interno')->id(),
            'aprovado_em' => now(),
            'motivo_rejeicao' => null,
        ]);

        EstabelecimentoHistorico::registrar(
            $this->id,
            'aprovado',
            $statusAnterior,
            'aprovado',
            $observacao
        );

        return $this;
    }

    /**
     * Rejeita o estabelecimento
     */
    public function rejeitar(string $motivo, ?string $observacao = null)
    {
        $statusAnterior = $this->status;
        
        $this->update([
            'status' => 'rejeitado',
            'aprovado_por' => auth('interno')->id(),
            'aprovado_em' => now(),
            'motivo_rejeicao' => $motivo,
        ]);

        EstabelecimentoHistorico::registrar(
            $this->id,
            'rejeitado',
            $statusAnterior,
            'rejeitado',
            $observacao ?? $motivo
        );

        return $this;
    }

    /**
     * Reinicia o estabelecimento (volta para pendente)
     */
    public function reiniciar(?string $observacao = null)
    {
        $statusAnterior = $this->status;
        
        $this->update([
            'status' => 'pendente',
            'aprovado_por' => null,
            'aprovado_em' => null,
            'motivo_rejeicao' => null,
        ]);

        EstabelecimentoHistorico::registrar(
            $this->id,
            'reiniciado',
            $statusAnterior,
            'pendente',
            $observacao
        );

        return $this;
    }

    /**
     * Determina se o estabelecimento é de competência estadual
     * Um estabelecimento é estadual se PELO MENOS UMA de suas atividades for estadual
     * Considera exceções de descentralização para o município do estabelecimento
     */
    public function isCompetenciaEstadual()
    {
        // PRIORIDADE 1: Se há competência manual definida, usa ela (override administrativo/judicial)
        if ($this->competencia_manual) {
            return $this->competencia_manual === 'estadual';
        }
        
        // PRIORIDADE 2: Se possui SOMENTE atividades especiais (PROJ_ARQ, ANAL_ROT), é estadual
        // Essas atividades são sempre de competência estadual (não descentralizadas)
        if ($this->possuiSomenteAtividadesEspeciais()) {
            return true;
        }
        
        // PRIORIDADE 3: Verifica pela pactuação (lógica normal)
        // Pega todas as atividades do estabelecimento (excluindo as especiais)
        $atividades = array_values(array_filter(
            $this->getTodasAtividades(),
            fn ($cnae) => !in_array($cnae, ['PROJ_ARQ', 'ANAL_ROT'], true)
        ));
        
        // Normaliza o nome do município (remove " - TO" ou "/TO")
        $municipio = $this->cidade;
        if ($municipio) {
            $municipio = preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $municipio);
            $municipio = trim($municipio);
        }
        
        // Se pelo menos uma atividade for estadual (considerando exceções e questionários), o estabelecimento é estadual
        foreach ($atividades as $cnae) {
            // Busca resposta do questionário para este CNAE, se houver
            $resposta1 = null;
            $resposta2 = null;
            $cnaeString = (string)$cnae;
            
            // Busca resposta da primeira pergunta
            if ($this->respostas_questionario) {
                if (isset($this->respostas_questionario[$cnaeString])) {
                    $resposta1 = $this->respostas_questionario[$cnaeString];
                } elseif (isset($this->respostas_questionario[(int)$cnaeString])) {
                    $resposta1 = $this->respostas_questionario[(int)$cnaeString];
                }
            }
            
            // Busca resposta da segunda pergunta (para tipo risco_localizacao)
            if ($this->respostas_questionario2) {
                if (isset($this->respostas_questionario2[$cnaeString])) {
                    $resposta2 = $this->respostas_questionario2[$cnaeString];
                } elseif (isset($this->respostas_questionario2[(int)$cnaeString])) {
                    $resposta2 = $this->respostas_questionario2[(int)$cnaeString];
                }
            }

            // Usa o método avançado que considera ambas as respostas
            $resultado = Pactuacao::verificarCompetenciaAvancada($cnaeString, $municipio, $resposta1, $resposta2);
            
            if ($resultado['competencia'] === 'estadual') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Competência de UMA atividade pela pactuação (somente informativo).
     * Usa o mesmo município, respostas de questionário e verificação de
     * isCompetenciaEstadual(), sem alterar a regra do estabelecimento.
     *
     * @return array{competencia: string, descentralizado: bool, excecao_hospitalar: bool, tabela: ?string, sem_resposta: bool}
     */
    public function getCompetenciaAtividade(string $cnae): array
    {
        $codigo = in_array(strtoupper($cnae), ['PROJ_ARQ', 'ANAL_ROT'], true)
            ? strtoupper($cnae)
            : preg_replace('/[^0-9]/', '', $cnae);

        $municipio = $this->cidade ? trim(preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $this->cidade)) : null;

        $resposta1 = null;
        $resposta2 = null;
        if ($this->respostas_questionario) {
            $resposta1 = $this->respostas_questionario[$codigo] ?? $this->respostas_questionario[(int) $codigo] ?? null;
        }
        if ($this->respostas_questionario2) {
            $resposta2 = $this->respostas_questionario2[$codigo] ?? $this->respostas_questionario2[(int) $codigo] ?? null;
        }

        $resultado = Pactuacao::verificarCompetenciaAvancada($codigo, $municipio, $resposta1, $resposta2);
        $detalhes = $resultado['detalhes'] ?? [];

        return [
            'competencia' => $resultado['competencia'] ?? 'municipal',
            'descentralizado' => !empty($detalhes['descentralizado']),
            'excecao_hospitalar' => !empty($detalhes['excecao_hospitalar']),
            'tabela' => $detalhes['tabela'] ?? null,
            'sem_resposta' => !empty($detalhes['requer_questionario']) && $resposta1 === null,
        ];
    }

    /**
     * Determina se o estabelecimento é de competência municipal
     * Um estabelecimento é municipal se TODAS as suas atividades forem municipais
     * e NENHUMA for estadual
     */
    public function isCompetenciaMunicipal()
    {
        return !$this->isCompetenciaEstadual();
    }

    public function possuiEscopoCompetencia(string $escopo): bool
    {
        $escopoNormalizado = strtolower(trim($escopo));

        if (!in_array($escopoNormalizado, ['estadual', 'municipal'], true)) {
            return false;
        }

        if ($escopoNormalizado === 'estadual' && $this->isCompetenciaEstadual()) {
            return true;
        }

        if ($escopoNormalizado === 'municipal' && $this->isCompetenciaMunicipal()) {
            return true;
        }

        $processos = $this->relationLoaded('processos')
            ? $this->processos
            : $this->processos()->with('tipoProcesso')->get();

        foreach ($processos as $processo) {
            if ($processo->resolverEscopoCompetencia() === $escopoNormalizado) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Retorna APENAS as atividades que o estabelecimento REALMENTE EXERCE
     * Essas são as atividades marcadas pelo estabelecimento no cadastro
     * 
     * IMPORTANTE: A competência (estadual/municipal) é determinada pelas atividades
     * que o estabelecimento EXERCE, não por todas as atividades cadastradas na Receita.
     */
    public function getTodasAtividades()
    {
        $atividades = [];
        
        // Códigos especiais que não são CNAEs numéricos (Tabela VI - Atividades de Processo)
        $codigosEspeciais = ['PROJ_ARQ', 'ANAL_ROT'];
        
        // Retorna APENAS as atividades exercidas (marcadas pelo estabelecimento)
        if ($this->atividades_exercidas && is_array($this->atividades_exercidas)) {
            foreach ($this->atividades_exercidas as $atividade) {
                $codigo = null;
                
                // Se for um array (com código e descrição), pega apenas o código
                if (is_array($atividade) && isset($atividade['codigo'])) {
                    $codigo = $atividade['codigo'];
                } 
                // Se for uma string (apenas o código CNAE), usa diretamente
                elseif (is_string($atividade)) {
                    $codigo = $atividade;
                }
                
                // Verifica se é um código especial (PROJ_ARQ, ANAL_ROT)
                if ($codigo && in_array($codigo, $codigosEspeciais)) {
                    $atividades[] = $codigo;
                }
                // Normaliza o código CNAE removendo formatação (pontos, traços, etc)
                elseif ($codigo) {
                    $codigo = preg_replace('/[^0-9]/', '', $codigo);
                    if (!empty($codigo)) {
                        $atividades[] = $codigo;
                    }
                }
            }
        }
        
        // Se não houver atividades exercidas marcadas, considera o CNAE principal como fallback
        // (para estabelecimentos antigos que não têm atividades exercidas cadastradas)
        if (empty($atividades) && $this->cnae_fiscal) {
            $cnae = preg_replace('/[^0-9]/', '', $this->cnae_fiscal);
            if (!empty($cnae)) {
                $atividades[] = $cnae;
            }
        }
        
        return array_unique(array_filter($atividades));
    }

    /**
     * Verifica se o estabelecimento possui atividades exercidas inseridas manualmente
     * (não constam no CNPJ da Receita Federal).
     */
    public function temAtividadesManuais(): bool
    {
        return $this->getAtividadesManuais()->isNotEmpty();
    }

    /**
     * Retorna as atividades exercidas inseridas manualmente.
     */
    public function getAtividadesManuais(): \Illuminate\Support\Collection
    {
        $codigosReceita = $this->getCodigosCnaeReceitaFederal();

        return collect($this->atividades_exercidas ?? [])
            ->filter(function ($atividade) use ($codigosReceita) {
                if (!is_array($atividade) || empty($atividade['codigo'])) {
                    return false;
                }

                if (!empty($atividade['manual'])) {
                    return true;
                }

                $codigo = preg_replace('/[^0-9]/', '', $atividade['codigo']);

                return $codigo && !in_array($codigo, $codigosReceita, true);
            })
            ->values();
    }

    /**
     * Códigos CNAE registrados na Receita Federal (principal + secundários não manuais).
     */
    private function getCodigosCnaeReceitaFederal(): array
    {
        $codigos = [];

        if ($this->cnae_fiscal) {
            $codigos[] = preg_replace('/[^0-9]/', '', $this->cnae_fiscal);
        }

        foreach ($this->cnaes_secundarios ?? [] as $cnae) {
            if (!is_array($cnae) || !empty($cnae['manual'])) {
                continue;
            }

            $cod = preg_replace('/[^0-9]/', '', $cnae['codigo'] ?? '');
            if ($cod) {
                $codigos[] = $cod;
            }
        }

        return array_values(array_unique(array_filter($codigos)));
    }

    public function getAtividadesEspeciais(): array
    {
        return array_values(array_filter(
            $this->getTodasAtividades(),
            fn ($codigo) => in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT'], true)
        ));
    }

    public function possuiAtividadeEspecial(string $codigo): bool
    {
        return in_array($codigo, $this->getAtividadesEspeciais(), true);
    }

    public function possuiSomenteAtividadesEspeciais(): bool
    {
        $atividades = $this->getTodasAtividades();

        if (empty($atividades)) {
            return false;
        }

        foreach ($atividades as $codigo) {
            if (!in_array($codigo, ['PROJ_ARQ', 'ANAL_ROT'], true)) {
                return false;
            }
        }

        return true;
    }
    
    /**
     * Retorna TODAS as atividades cadastradas (incluindo as não exercidas)
     * Usado apenas para exibição/informação, NÃO para determinar competência
     */
    public function getTodasAtividadesCadastradas()
    {
        $atividades = [];
        
        // Adiciona CNAE principal
        if ($this->cnae_fiscal) {
            $atividades[] = $this->cnae_fiscal;
        }
        
        // Adiciona CNAEs secundários
        if ($this->cnaes_secundarios && is_array($this->cnaes_secundarios)) {
            foreach ($this->cnaes_secundarios as $cnae) {
                if (isset($cnae['codigo'])) {
                    $atividades[] = $cnae['codigo'];
                }
            }
        }
        
        return array_unique(array_filter($atividades));
    }

    /**
     * Indica se o estabelecimento pode solicitar o módulo de Unidade Móvel:
     * precisa estar aprovado, ainda não ter o módulo pendente/aprovado e
     * possuir ao menos um CNAE contemplado na pactuação de Unidade Móvel.
     */
    public function podeSolicitarModuloUnidadeMovel(): bool
    {
        if ($this->status !== 'aprovado') {
            return false;
        }

        if (in_array($this->status_unidade_movel, ['pendente', 'aprovado'], true)) {
            return false;
        }

        $cnaesPermitidos = \App\Models\Pactuacao::cnaesUnidadeMovel();
        if (empty($cnaesPermitidos)) {
            return false;
        }

        $cnaesEstabelecimento = collect($this->getTodasAtividadesCadastradas())
            ->map(fn ($c) => preg_replace('/\D/', '', (string) $c))
            ->all();

        return !empty(array_intersect($cnaesEstabelecimento, $cnaesPermitidos));
    }

    /**
     * Indica se o módulo de Unidade Móvel está aprovado e ativo.
     */
    public function unidadeMovelAprovada(): bool
    {
        return $this->is_unidade_movel && $this->status_unidade_movel === 'aprovado';
    }

    /**
     * Scope para filtrar estabelecimentos por competência do usuário
     */
    public function scopeParaUsuario($query, $usuario)
    {
        // Administrador vê tudo
        if ($usuario->isAdmin()) {
            return $query;
        }
        
        // Gestor/Técnico Municipal - vê apenas do seu município
        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id) {
                return $query->whereRaw('1=0'); // Sem município vinculado, não vê nada
            }
            return $query->where('municipio_id', $usuario->municipio_id);
        }
        
        // Gestor/Técnico Estadual - vê estabelecimentos de competência estadual de qualquer município
        // O filtro de competência será aplicado no controller
        if ($usuario->isEstadual()) {
            return $query;
        }
        
        return $query->whereRaw('1=0'); // Nenhum acesso por padrão
    }
    
    /**
     * Scope para filtrar apenas estabelecimentos de um município específico
     */
    public function scopeDoMunicipio($query, $municipioId)
    {
        return $query->where('municipio_id', $municipioId);
    }

    /**
     * Calcula o Grupo de Risco do estabelecimento baseado nas atividades pactuadas
     * 
     * Lógica:
     * - Alto Risco: Estabelecimentos com atividades de classificação_risco "alto" na pactuação
     * - Médio Risco: Estabelecimentos com atividades de classificação_risco "medio"
     * - Baixo Risco: Estabelecimentos sem atividades de risco ou classificação "baixo"
     * 
     * @return string 'alto', 'medio', 'baixo', ou 'indefinido'
     */
    public function getGrupoRisco(): string
    {
        $atividades = $this->getTodasAtividades();
        
        if (empty($atividades)) {
            return 'indefinido';
        }
        
        $temAltoRisco = false;
        $temMedioRisco = false;
        
        foreach ($atividades as $cnae) {
            $pactuacao = Pactuacao::ativaPorCnae($cnae);

            if ($pactuacao && $pactuacao->classificacao_risco) {
                $risco = strtolower($pactuacao->classificacao_risco);
                
                if ($risco === 'alto') {
                    $temAltoRisco = true;
                    break; // Se tem alto risco, já retorna
                } elseif ($risco === 'medio' || $risco === 'médio') {
                    $temMedioRisco = true;
                }
            }
        }
        
        if ($temAltoRisco) {
            return 'alto';
        } elseif ($temMedioRisco) {
            return 'medio';
        }
        
        return 'baixo';
    }
    
    /**
     * Retorna o label curto do grupo de risco (versão clean)
     */
    public function getGrupoRiscoLabelAttribute(): string
    {
        $risco = $this->getGrupoRisco();
        
        return match($risco) {
            'alto' => 'Alto',
            'medio' => 'Médio',
            'baixo' => 'Baixo',
            'indefinido' => 'N/A',
            default => 'N/A',
        };
    }
    
    /**
     * Retorna o label completo do grupo de risco (para tooltip)
     */
    public function getGrupoRiscoTooltipAttribute(): string
    {
        $risco = $this->getGrupoRisco();
        
        return match($risco) {
            'alto' => 'Alto Risco - Estabelecimento com atividades de alto risco sanitário',
            'medio' => 'Médio Risco - Estabelecimento com atividades de risco moderado',
            'baixo' => 'Baixo Risco - Estabelecimento com atividades de baixo risco',
            'indefinido' => 'Não Classificado - Sem atividades cadastradas',
            default => 'Não Classificado',
        };
    }
    
    /**
     * Retorna o estilo inline do grupo de risco (cores exatas)
     */
    public function getGrupoRiscoStyleAttribute(): string
    {
        $risco = $this->getGrupoRisco();
        
        return match($risco) {
            'alto' => 'background-color: #ef4444; color: white;',      // Vermelho
            'medio' => 'background-color: #fbbf24; color: white;',     // Laranja
            'baixo' => 'background-color: #34d399; color: white;',     // Verde
            'indefinido' => 'background-color: #9ca3af; color: white;',
            default => 'background-color: #9ca3af; color: white;',
        };
    }
    
    /**
     * Retorna as classes CSS do grupo de risco (versão compacta com cores vibrantes)
     */
    public function getGrupoRiscoCorAttribute(): string
    {
        $risco = $this->getGrupoRisco();
        
        return match($risco) {
            'alto' => 'bg-red-500 text-white',
            'medio' => 'bg-yellow-500 text-white',
            'baixo' => 'bg-green-500 text-white',
            'indefinido' => 'bg-gray-400 text-white',
            default => 'bg-gray-400 text-white',
        };
    }
    
    /**
     * Retorna a cor de fundo mais escura para hover
     */
    public function getGrupoRiscoCorHoverAttribute(): string
    {
        $risco = $this->getGrupoRisco();
        
        return match($risco) {
            'alto' => 'hover:bg-red-600',
            'medio' => 'hover:bg-yellow-600',
            'baixo' => 'hover:bg-green-600',
            'indefinido' => 'hover:bg-gray-500',
            default => 'hover:bg-gray-500',
        };
    }

    /**
     * Retorna os códigos CNAE do estabelecimento (atividades exercidas)
     * Alias para getTodasAtividades() para compatibilidade
     * 
     * @return array
     */
    public function getCnaes(): array
    {
        return $this->getTodasAtividades();
    }

    /**
     * Retorna o escopo de competência do estabelecimento
     * 
     * @return string 'estadual' ou 'municipal'
     */
    public function getEscopoCompetencia(): string
    {
        return $this->isCompetenciaEstadual() ? 'estadual' : 'municipal';
    }

    /**
     * Retorna o label do escopo de competência
     * 
     * @return string
     */
    public function getEscopoCompetenciaLabelAttribute(): string
    {
        return $this->isCompetenciaEstadual() ? 'Estadual' : 'Municipal';
    }

    /**
     * Retorna a cor do badge do escopo de competência
     * 
     * @return string
     */
    public function getEscopoCompetenciaCorAttribute(): string
    {
        return $this->isCompetenciaEstadual() 
            ? 'bg-blue-100 text-blue-800' 
            : 'bg-green-100 text-green-800';
    }

    /**
     * Retorna o label do tipo de setor
     * 
     * @return string
     */
    public function getTipoSetorLabelAttribute(): string
    {
        $tipoSetor = $this->tipo_setor ?? 'privado';
        
        if ($tipoSetor instanceof TipoSetor) {
            return $tipoSetor->label();
        }
        
        return match($tipoSetor) {
            'publico' => 'Público',
            'privado' => 'Privado',
            default => 'Privado',
        };
    }

    /**
     * Retorna a cor do badge do tipo de setor
     * 
     * @return string
     */
    public function getTipoSetorCorAttribute(): string
    {
        $tipoSetor = $this->tipo_setor ?? 'privado';
        
        if ($tipoSetor instanceof TipoSetor) {
            $tipoSetor = $tipoSetor->value;
        }
        
        return match($tipoSetor) {
            'publico' => 'bg-indigo-100 text-indigo-800',
            'privado' => 'bg-orange-100 text-orange-800',
            default => 'bg-orange-100 text-orange-800',
        };
    }

    /**
     * Busca todos os documentos obrigatórios para este estabelecimento
     * com deduplicação automática baseado nas atividades exercidas
     * 
     * @return \Illuminate\Support\Collection
     */
    public function getDocumentosObrigatorios(): \Illuminate\Support\Collection
    {
        return TipoDocumentoObrigatorio::getDocumentosParaEstabelecimento($this);
    }

    /**
     * Busca documentos obrigatórios agrupados por categoria
     * 
     * @return array
     */
    public function getDocumentosObrigatoriosAgrupados(): array
    {
        return TipoDocumentoObrigatorio::getDocumentosAgrupadosParaEstabelecimento($this);
    }

}
