<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class DocumentoResposta extends Model
{
    protected $table = 'documento_respostas';

    protected $fillable = [
        'documento_digital_id',
        'documento_item_atendimento_id',
        'usuario_externo_id',
        'tipo_documento_resposta_id',
        'nome_arquivo',
        'nome_original',
        'caminho',
        'extensao',
        'tamanho',
        'observacoes',
        'status',
        'motivo_rejeicao',
        'avaliado_por',
        'avaliado_em',
        'historico_rejeicao',
        // Prazo de análise interna
        'prazo_analise_iniciado_em',
        'prazo_analise_dias',
        'prazo_analise_data_limite',
        'prazo_analise_prorrogado_dias',
        'prazo_analise_prorrogado_em',
        'prazo_analise_prorrogado_por',
        'prazo_analise_prorrogado_motivo',
    ];

    protected $casts = [
        'avaliado_em' => 'datetime',
        'tamanho' => 'integer',
        'historico_rejeicao' => 'array',
        'prazo_analise_iniciado_em' => 'datetime',
        'prazo_analise_dias' => 'integer',
        'prazo_analise_data_limite' => 'date',
        'prazo_analise_prorrogado_dias' => 'integer',
        'prazo_analise_prorrogado_em' => 'datetime',
    ];

    /**
     * Relacionamento com documento digital
     */
    public function documentoDigital()
    {
        return $this->belongsTo(DocumentoDigital::class);
    }

    /**
     * Tipo de documento resposta (quando vinculado)
     */
    public function tipoDocumentoResposta()
    {
        return $this->belongsTo(TipoDocumentoResposta::class);
    }

    public function itemAtendimento()
    {
        return $this->belongsTo(DocumentoItemAtendimento::class, 'documento_item_atendimento_id');
    }

    /**
     * Relacionamento com usuário externo que enviou a resposta
     */
    public function usuarioExterno()
    {
        return $this->belongsTo(UsuarioExterno::class);
    }

    /**
     * Relacionamento com usuário interno que avaliou
     */
    public function avaliadoPor()
    {
        return $this->belongsTo(UsuarioInterno::class, 'avaliado_por');
    }

    /**
     * Relacionamento com usuário que prorrogou o prazo de análise
     */
    public function prazoAnaliseProrrogadoPor()
    {
        return $this->belongsTo(UsuarioInterno::class, 'prazo_analise_prorrogado_por');
    }

    /**
     * Verifica se a resposta está pendente
     */
    public function isPendente(): bool
    {
        return $this->status === 'pendente';
    }

    /**
     * Verifica se a resposta foi aprovada
     */
    public function isAprovada(): bool
    {
        return $this->status === 'aprovado';
    }

    /**
     * Verifica se a resposta foi rejeitada
     */
    public function isRejeitada(): bool
    {
        return $this->status === 'rejeitado';
    }

    /**
     * Retorna o tamanho formatado (KB, MB)
     */
    public function getTamanhoFormatadoAttribute(): string
    {
        $bytes = $this->tamanho;

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' bytes';
    }

    /**
     * Aprova a resposta
     */
    public function aprovar($usuarioInternoId): void
    {
        $this->update([
            'status' => 'aprovado',
            'motivo_rejeicao' => null,
            'avaliado_por' => $usuarioInternoId,
            'avaliado_em' => now(),
        ]);
    }

    /**
     * Rejeita a resposta
     */
    public function rejeitar($usuarioInternoId, $motivo): void
    {
        $this->update([
            'status' => 'rejeitado',
            'motivo_rejeicao' => $motivo,
            'avaliado_por' => $usuarioInternoId,
            'avaliado_em' => now(),
        ]);
    }

    // ==========================================================
    // PRAZO DE ANÁLISE INTERNA DA VIGILÂNCIA
    // ==========================================================

    /**
     * Inicia o prazo de análise interna a partir do envio da resposta.
     * Chamado automaticamente na criação ou manualmente se necessário.
     */
    public function iniciarPrazoAnalise(?int $prazoDias = null, string $tipoPrazo = 'corridos'): void
    {
        // Se já foi iniciado, não reinicia
        if ($this->prazo_analise_iniciado_em) {
            return;
        }

        // Tenta pegar dias do tipo de documento se não for informado
        if ($prazoDias === null) {
            $tipoDoc = $this->documentoDigital?->tipoDocumento;
            $prazoDias = $tipoDoc?->prazo_analise_dias ?? 15;
            $tipoPrazo = $tipoDoc?->tipo_prazo_analise ?? 'corridos';
        }

        $inicio = $this->created_at ?? now();
        $dataLimite = $this->calcularDataLimite($inicio, $prazoDias, $tipoPrazo);

        $this->update([
            'prazo_analise_iniciado_em' => $inicio,
            'prazo_analise_dias' => $prazoDias,
            'prazo_analise_data_limite' => $dataLimite,
        ]);
    }

    /**
     * Calcula a data limite considerando dias corridos ou úteis.
     */
    private function calcularDataLimite(Carbon|\DateTimeInterface $inicio, int $dias, string $tipoPrazo): Carbon
    {
        $data = Carbon::parse($inicio)->startOfDay();

        if ($tipoPrazo === 'uteis') {
            return $data->addWeekdays($dias);
        }

        return $data->addDays($dias);
    }

    /**
     * Dias restantes para análise (negativo = vencido)
     */
    public function getDiasRestantesAnaliseAttribute(): ?int
    {
        if (!$this->prazo_analise_data_limite) {
            return null;
        }

        return (int) Carbon::now()->startOfDay()
            ->diffInDays($this->prazo_analise_data_limite, false);
    }

    /**
     * Verifica se o prazo de análise está vencido
     */
    public function isPrazoAnaliseVencido(): bool
    {
        if (!$this->isPendente() || !$this->prazo_analise_data_limite) {
            return false;
        }

        return $this->prazo_analise_data_limite->isPast()
            && !$this->prazo_analise_data_limite->isToday();
    }

    /**
     * Texto descritivo do prazo de análise
     */
    public function getTextoPrazoAnaliseAttribute(): string
    {
        if (!$this->prazo_analise_data_limite) {
            return '';
        }

        if (!$this->isPendente()) {
            return 'Analisado';
        }

        $dias = $this->dias_restantes_analise;

        if ($dias === null) {
            return '';
        }

        if ($dias < 0) {
            $vencidos = abs($dias);
            return "Análise vencida há {$vencidos} " . ($vencidos === 1 ? 'dia' : 'dias');
        }

        if ($dias === 0) {
            return 'Análise vence hoje';
        }

        if ($dias === 1) {
            return 'Análise vence amanhã';
        }

        return "Análise em {$dias} dias";
    }

    /**
     * Cor do badge conforme urgência
     * retorna: 'red' (vencido), 'amber' (<=2 dias), 'blue' (ok), 'green' (analisado), 'gray' (sem prazo)
     */
    public function getCorPrazoAnaliseAttribute(): string
    {
        if (!$this->prazo_analise_data_limite) {
            return 'gray';
        }

        if (!$this->isPendente()) {
            return 'green';
        }

        $dias = $this->dias_restantes_analise;

        if ($dias === null) {
            return 'gray';
        }

        if ($dias < 0) {
            return 'red';
        }

        if ($dias <= 2) {
            return 'amber';
        }

        return 'blue';
    }

    /**
     * Prorroga o prazo de análise interna
     */
    public function prorrogarPrazoAnalise(int $dias, int $usuarioInternoId, string $motivo): void
    {
        if ($dias < 1) {
            throw new \InvalidArgumentException('Dias de prorrogação deve ser pelo menos 1.');
        }

        if (!$this->prazo_analise_data_limite) {
            throw new \RuntimeException('Esta resposta não possui prazo de análise para prorrogar.');
        }

        $novaData = Carbon::parse($this->prazo_analise_data_limite)->addDays($dias);

        $this->update([
            'prazo_analise_data_limite' => $novaData,
            'prazo_analise_prorrogado_dias' => ((int) ($this->prazo_analise_prorrogado_dias ?? 0)) + $dias,
            'prazo_analise_prorrogado_em' => now(),
            'prazo_analise_prorrogado_por' => $usuarioInternoId,
            'prazo_analise_prorrogado_motivo' => trim($motivo),
        ]);
    }

    /**
     * Boot: inicia prazo de análise automaticamente ao criar
     */
    protected static function booted(): void
    {
        static::created(function (self $resposta) {
            // Só inicia prazo se for resposta pendente
            if ($resposta->status === 'pendente') {
                try {
                    $resposta->iniciarPrazoAnalise();
                } catch (\Throwable $e) {
                    // Silencia erro: prazo é opcional
                    \Log::warning('Erro ao iniciar prazo de análise', [
                        'resposta_id' => $resposta->id,
                        'erro' => $e->getMessage(),
                    ]);
                }
            }
        });

        // Resposta nova ou analisada: o robô de pendências dos envolvidos precisa refletir na hora
        static::saved(function (self $resposta) {
            if (!$resposta->wasRecentlyCreated && !$resposta->wasChanged(['status', 'prazo_analise_data_limite'])) {
                return;
            }

            try {
                $assinantes = DocumentoAssinatura::where('documento_digital_id', $resposta->documento_digital_id)
                    ->pluck('usuario_interno_id');

                \App\Support\AssistentePendencias::limpar(
                    $assinantes->push($resposta->avaliado_por, auth('interno')->id())
                );
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
