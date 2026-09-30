@extends('layouts.admin')

@section('title', 'Atividades alteradas no CNPJ')
@section('page-title', 'Atividades alteradas no CNPJ')

@section('content')
<div class="max-w-6xl mx-auto space-y-5">
    {{-- Cabeçalho --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-500 to-red-500 text-white flex items-center justify-center shadow-md flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
                </div>
                <div>
                    <h1 class="text-lg font-bold text-slate-900">Atividades alteradas no CNPJ</h1>
                    <p class="text-sm text-slate-500 mt-0.5 max-w-2xl">
                        O sistema compara diariamente os CNAEs do CNPJ na Receita com as atividades marcadas no cadastro.
                        Revise as atividades de cada estabelecimento: ao salvar, o alerta é encerrado.
                    </p>
                    @if($ultimaVerificacao)
                        <p class="text-[11px] text-slate-400 mt-1">Última verificação: {{ \Carbon\Carbon::parse($ultimaVerificacao)->format('d/m/Y H:i') }}</p>
                    @endif
                </div>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition self-start">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Dashboard
            </a>
        </div>

        {{-- Filtros --}}
        <div class="flex flex-wrap gap-2 mt-4">
            <a href="{{ route('admin.dashboard.atividades-alteradas') }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ring-1 transition {{ $filtro !== 'competencia' ? 'bg-slate-900 text-white ring-slate-900' : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50' }}">
                Todos <span class="opacity-70">{{ $total }}</span>
            </a>
            <a href="{{ route('admin.dashboard.atividades-alteradas', ['filtro' => 'competencia']) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold ring-1 transition {{ $filtro === 'competencia' ? 'bg-red-600 text-white ring-red-600' : 'bg-white text-red-700 ring-red-200 hover:bg-red-50' }}">
                Mudam de competência <span class="opacity-70">{{ $totalMudamCompetencia }}</span>
            </a>
        </div>
    </div>

    {{-- Lista --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        @if($alertas->count() > 0)
            <div class="divide-y divide-slate-100">
                @foreach($alertas as $alerta)
                    @include('admin.dashboard.partials.alerta-cnae-item', ['alerta' => $alerta])
                @endforeach
            </div>
            @if($alertas->hasPages())
                <div class="px-4 py-3 border-t border-slate-100">{{ $alertas->links() }}</div>
            @endif
        @else
            <div class="p-12 text-center">
                <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                    <svg class="w-7 h-7 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <p class="text-sm font-semibold text-slate-600">Nenhuma alteração de atividade pendente de revisão</p>
            </div>
        @endif
    </div>

    <p class="text-[11px] text-slate-400 px-1">
        <strong>Saiu do CNPJ:</strong> atividade marcada no cadastro que não consta mais no CNPJ.
        <strong>Novo no CNPJ:</strong> CNAE que entrou no CNPJ e ainda não foi revisado.
        Em vermelho, os casos em que a revisão muda a competência do estabelecimento.
    </p>
</div>
@endsection
