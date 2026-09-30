<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache do "Assistente de Pendências" (robô do chat interno).
 * O resumo é guardado por alguns minutos; quando uma demanda muda
 * (resposta analisada, assinatura feita...), o cache dos envolvidos é limpo
 * para o robô não continuar cobrando algo já resolvido.
 */
class AssistentePendencias
{
    public static function chave(int $usuarioId): string
    {
        return 'chat_assistente_pendencias_v3_' . $usuarioId;
    }

    /**
     * @param iterable<int|null> $usuarioIds
     */
    public static function limpar(iterable $usuarioIds): void
    {
        foreach (collect($usuarioIds)->filter()->unique() as $usuarioId) {
            Cache::forget(self::chave((int) $usuarioId));
        }
    }
}
