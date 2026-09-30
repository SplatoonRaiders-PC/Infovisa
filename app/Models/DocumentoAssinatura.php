<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentoAssinatura extends Model
{
    protected $table = 'documento_assinaturas';

    protected $fillable = [
        'documento_digital_id',
        'usuario_interno_id',
        'ordem',
        'obrigatoria',
        'status',
        'assinado_em',
        'observacao',
        'hash_assinatura',
    ];

    protected $casts = [
        'obrigatoria' => 'boolean',
        'assinado_em' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Assinatura criada, feita ou removida: atualiza o robô de pendências do assinante
        $limparAssistente = function (self $assinatura) {
            \App\Support\AssistentePendencias::limpar([$assinatura->usuario_interno_id]);
        };

        static::saved($limparAssistente);
        static::deleted($limparAssistente);
    }

    /**
     * Relacionamento com documento digital
     */
    public function documentoDigital()
    {
        return $this->belongsTo(DocumentoDigital::class);
    }

    /**
     * Relacionamento com usuário interno
     */
    public function usuarioInterno()
    {
        return $this->belongsTo(UsuarioInterno::class);
    }
}
