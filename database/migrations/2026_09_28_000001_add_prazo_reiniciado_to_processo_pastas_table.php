<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data em que o prazo da fila pública da pasta/unidade foi reiniciado
     * (equivalente ao processos.prazo_fila_publica_reiniciado_em, mas por unidade).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('processo_pastas', 'prazo_fila_publica_reiniciado_em')) {
            Schema::table('processo_pastas', function (Blueprint $table) {
                $table->timestamp('prazo_fila_publica_reiniciado_em')->nullable()->after('tempo_total_parado_segundos');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('processo_pastas', 'prazo_fila_publica_reiniciado_em')) {
            Schema::table('processo_pastas', function (Blueprint $table) {
                $table->dropColumn('prazo_fila_publica_reiniciado_em');
            });
        }
    }
};
