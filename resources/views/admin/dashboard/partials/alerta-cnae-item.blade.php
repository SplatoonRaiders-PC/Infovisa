{{-- Um estabelecimento com CNAE alterado na Receita. Espera $alerta (EstabelecimentoVerificacaoCnae) --}}
@php
    $estab = $alerta->estabelecimento;
    $nomeEstab = $estab?->nome_fantasia ?: ($estab?->razao_social ?: ($estab?->nome_completo ?: 'Estabelecimento #' . $alerta->estabelecimento_id));
    $rotuloCompetencia = fn ($c) => $c === 'estadual' ? 'Estadual' : 'Municipal';
@endphp
<div class="flex flex-col sm:flex-row sm:items-start gap-3 px-4 py-3 {{ $alerta->altera_competencia ? 'bg-red-50/40' : '' }}">
    <div class="w-8 h-8 rounded-lg {{ $alerta->altera_competencia ? 'bg-red-100 text-red-600' : 'bg-amber-50 text-amber-600' }} flex items-center justify-center flex-shrink-0">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
    </div>

    <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <p class="text-sm font-semibold text-slate-900 truncate max-w-full">{{ $nomeEstab }}</p>
            @if($alerta->altera_competencia)
                <span class="inline-flex items-center gap-1 text-[10px] px-2 py-0.5 bg-red-600 text-white rounded-full font-bold">
                    {{ $rotuloCompetencia($alerta->competencia_atual) }} → {{ $rotuloCompetencia($alerta->competencia_sugerida) }}
                </span>
            @else
                <span class="text-[10px] px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full font-semibold">{{ $rotuloCompetencia($alerta->competencia_atual) }}</span>
            @endif
        </div>
        <p class="text-[11px] text-slate-500 mt-0.5">
            @if($estab?->cnpj){{ \App\Services\CnpjService::formatarCnpj($estab->cnpj) }} · @endif{{ $estab?->cidade }}
            · detectado {{ $alerta->detectado_em?->format('d/m/Y') }}
        </p>

        <div class="mt-1.5 flex flex-wrap gap-1.5">
            @foreach($alerta->cnaes_removidos ?? [] as $cnae)
                <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-md ring-1 {{ ($cnae['competencia'] ?? '') === 'estadual' ? 'bg-red-50 text-red-700 ring-red-200' : 'bg-amber-50 text-amber-800 ring-amber-200' }}"
                      title="{{ $cnae['descricao'] ?? '' }}">
                    <strong>Saiu do CNPJ:</strong> {{ \App\Models\EstabelecimentoVerificacaoCnae::formatarCnae($cnae['codigo'] ?? '') }}
                    <span class="opacity-70">({{ $rotuloCompetencia($cnae['competencia'] ?? 'municipal') }})</span>
                </span>
            @endforeach
            @foreach($alerta->cnaes_novos ?? [] as $cnae)
                <span class="inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-md ring-1 {{ ($cnae['competencia'] ?? '') === 'estadual' ? 'bg-blue-50 text-blue-700 ring-blue-200' : 'bg-slate-50 text-slate-700 ring-slate-200' }}"
                      title="{{ $cnae['descricao'] ?? '' }}">
                    <strong>Novo no CNPJ:</strong> {{ \App\Models\EstabelecimentoVerificacaoCnae::formatarCnae($cnae['codigo'] ?? '') }}
                    <span class="opacity-70">({{ $rotuloCompetencia($cnae['competencia'] ?? 'municipal') }})</span>
                </span>
            @endforeach
        </div>
    </div>

    <a href="{{ route('admin.estabelecimentos.atividades.edit', $alerta->estabelecimento_id) }}"
       class="self-start sm:self-center inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg transition flex-shrink-0">
        Revisar atividades
        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </a>
</div>
