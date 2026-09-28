<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const NOVOS_TIPOS = ['unidade_prazo_reiniciado'];

    /**
     * Acrescenta o evento de reinício do prazo da unidade à constraint de tipo_evento,
     * preservando os tipos que já existem no banco.
     */
    public function up(): void
    {
        $this->recriarConstraint(array_unique(array_merge($this->tiposAtuais(), self::NOVOS_TIPOS)));
    }

    public function down(): void
    {
        DB::table('processo_eventos')->whereIn('tipo_evento', self::NOVOS_TIPOS)->update(['tipo_evento' => 'unidade_retomada']);
        $this->recriarConstraint(array_values(array_diff($this->tiposAtuais(), self::NOVOS_TIPOS)));
    }

    private function tiposAtuais(): array
    {
        $definicao = DB::selectOne("
            SELECT pg_get_constraintdef(oid) AS def
            FROM pg_constraint
            WHERE conrelid = 'processo_eventos'::regclass
              AND conname = 'processo_eventos_tipo_evento_check'
        ")?->def ?? '';

        preg_match_all("/'([a-z_]+)'::text/", $definicao, $matches);

        return $matches[1];
    }

    private function recriarConstraint(array $tipos): void
    {
        if (empty($tipos)) {
            return;
        }

        $lista = implode(', ', array_map(fn ($tipo) => "'{$tipo}'::text", $tipos));

        DB::statement('ALTER TABLE processo_eventos DROP CONSTRAINT IF EXISTS processo_eventos_tipo_evento_check');
        DB::statement("
            ALTER TABLE processo_eventos
            ADD CONSTRAINT processo_eventos_tipo_evento_check
            CHECK (tipo_evento::text = ANY (ARRAY[{$lista}]))
        ");
    }
};
