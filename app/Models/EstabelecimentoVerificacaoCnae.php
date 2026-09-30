<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Última verificação dos CNAEs do CNPJ de um estabelecimento na Receita.
 * Com status "divergente" é um alerta para a equipe revisar as atividades.
 */
class EstabelecimentoVerificacaoCnae extends Model
{
    protected $table = 'estabelecimento_verificacoes_cnae';

    protected $fillable = [
        'estabelecimento_id',
        'status',
        'cnaes_receita',
        'cnaes_base',
        'cnaes_ignorados',
        'cnaes_removidos',
        'cnaes_novos',
        'competencia_atual',
        'competencia_sugerida',
        'altera_competencia',
        'novo_cnae_estadual',
        'fonte',
        'erro',
        'verificado_em',
        'detectado_em',
        'revisado_em',
        'revisado_por',
    ];

    protected $casts = [
        'cnaes_receita' => 'array',
        'cnaes_base' => 'array',
        'cnaes_ignorados' => 'array',
        'cnaes_removidos' => 'array',
        'cnaes_novos' => 'array',
        'altera_competencia' => 'boolean',
        'novo_cnae_estadual' => 'boolean',
        'verificado_em' => 'datetime',
        'detectado_em' => 'datetime',
        'revisado_em' => 'datetime',
    ];

    public function estabelecimento()
    {
        return $this->belongsTo(Estabelecimento::class);
    }

    public function revisor()
    {
        return $this->belongsTo(UsuarioInterno::class, 'revisado_por');
    }

    /**
     * Alertas em aberto (divergência ainda não revisada)
     */
    public function scopePendentes($query)
    {
        return $query->where('status', 'divergente')
            ->whereHas('estabelecimento', fn ($q) => $q->where(fn ($s) => $s->whereNull('status')->orWhere('status', '!=', 'rejeitado')));
    }

    /**
     * Respeita a competência do usuário:
     * - Administrador: tudo
     * - Estadual: estabelecimentos estaduais, os que passariam a ser estaduais ou ganharam CNAE estadual
     * - Municipal: do seu município, quando são (ou passariam a ser) municipais
     */
    public function scopeParaUsuario($query, $usuario)
    {
        if ($usuario->isAdmin()) {
            return $query;
        }

        if ($usuario->isEstadual()) {
            return $query->where(function ($q) {
                $q->where('competencia_atual', 'estadual')
                    ->orWhere('competencia_sugerida', 'estadual')
                    ->orWhere('novo_cnae_estadual', true);
            });
        }

        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            return $query
                ->whereHas('estabelecimento', fn ($q) => $q->where('municipio_id', $usuario->municipio_id))
                ->where(function ($q) {
                    $q->where('competencia_atual', 'municipal')
                        ->orWhere('competencia_sugerida', 'municipal');
                });
        }

        return $query->whereRaw('1 = 0');
    }

    /**
     * Ordem de prioridade: muda competência primeiro, depois os mais antigos
     */
    public function scopePrioridade($query)
    {
        return $query->orderByDesc('altera_competencia')->orderBy('detectado_em');
    }

    public static function formatarCnae(?string $codigo): string
    {
        $limpo = preg_replace('/[^0-9]/', '', (string) $codigo);

        return strlen($limpo) === 7
            ? substr($limpo, 0, 2) . '.' . substr($limpo, 2, 2) . '-' . substr($limpo, 4, 1) . '-' . substr($limpo, 5, 2)
            : (string) $codigo;
    }
}
