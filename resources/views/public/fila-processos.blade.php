@extends('layouts.public')

@section('title', 'Processos - InfoVISA')

@section('content')
@php
    $statusColors = [
        'aberto' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'em_analise' => 'bg-amber-50 text-amber-700 ring-amber-200',
        'pendente' => 'bg-orange-50 text-orange-700 ring-orange-200',
        'parado' => 'bg-red-50 text-red-700 ring-red-200',
        'arquivado' => 'bg-slate-100 text-slate-700 ring-slate-200',
    ];
    $statusDots = [
        'aberto' => 'bg-blue-500',
        'em_analise' => 'bg-amber-500',
        'pendente' => 'bg-orange-500',
        'parado' => 'bg-red-500',
        'arquivado' => 'bg-slate-400',
    ];
    $statusLabels = [
        'aberto' => 'Aberto',
        'em_analise' => 'Em Análise',
        'pendente' => 'Pendente',
        'parado' => 'Parado',
        'arquivado' => 'Arquivado',
    ];

    // Resumo geral da fila
    $todosProcessosFila = collect($filaProcessos)->flatMap(fn ($f) => $f['processos']);
    $resumoFila = [
        'total' => $todosProcessosFila->count(),
        'no_prazo' => $todosProcessosFila->filter(fn ($p) => !$p['pausado'] && !$p['atrasado'])->count(),
        'atrasados' => $todosProcessosFila->filter(fn ($p) => !$p['pausado'] && $p['atrasado'])->count(),
        'suspensos' => $todosProcessosFila->where('pausado', true)->count(),
    ];
@endphp
<div class="min-h-screen bg-slate-50" x-data="{
    abaAtiva: 'fila',
    cnpj: '',
    statusSelecionados: ['aberto', 'em_analise'],
    formatCnpj() {
        let value = this.cnpj.replace(/\D/g, '');
        if (value.length <= 14) {
            value = value.replace(/^(\d{2})(\d)/, '$1.$2');
            value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');
            this.cnpj = value;
        }
    },
    toggleStatus(status) {
        if (this.statusSelecionados.includes(status)) {
            this.statusSelecionados = this.statusSelecionados.filter(s => s !== status);
        } else {
            this.statusSelecionados.push(status);
        }
    },
    mostrarProcesso(status) {
        return this.statusSelecionados.includes(status);
    }
}">
    {{-- Cabeçalho --}}
    <section class="relative overflow-hidden bg-white border-b border-slate-200">
        <div class="absolute inset-0 pointer-events-none">
            <div class="absolute -top-24 -right-24 w-96 h-96 bg-blue-100 rounded-full blur-3xl opacity-60"></div>
            <div class="absolute -bottom-32 -left-24 w-80 h-80 bg-violet-100 rounded-full blur-3xl opacity-60"></div>
            <svg class="absolute inset-0 w-full h-full opacity-30" aria-hidden="true">
                <defs><pattern id="fila-grid" width="32" height="32" patternUnits="userSpaceOnUse"><path d="M32 0H0V32" fill="none" stroke="#e2e8f0" stroke-width="1"/></pattern></defs>
                <rect width="100%" height="100%" fill="url(#fila-grid)"/>
            </svg>
        </div>

        <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-8 pb-6">
            <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-6">
                <div>
                    <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/80 backdrop-blur border border-blue-100 text-blue-700 rounded-full text-xs font-semibold shadow-sm mb-3">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-blue-500"></span>
                        </span>
                        Atualizado em {{ now()->format('d/m/Y \à\s H:i') }}
                    </div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">Fila de Processos</h1>
                    <p class="text-sm text-slate-500 mt-1 max-w-2xl">Acompanhe em tempo real a ordem de análise dos processos com documentação completa, ou consulte os processos pelo CNPJ.</p>
                </div>

                {{-- Abas --}}
                <div class="inline-flex p-1 bg-slate-100 rounded-xl self-start lg:self-auto">
                    <button type="button" @click="abaAtiva = 'fila'"
                            :class="abaAtiva === 'fila' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                        Fila de Processos
                    </button>
                    <button type="button" @click="abaAtiva = 'consultar'"
                            :class="abaAtiva === 'consultar' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        Consultar por CNPJ
                    </button>
                </div>
            </div>

            {{-- Resumo --}}
            @if(count($filaProcessos) > 0)
            <div x-show="abaAtiva === 'fila'" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mt-6">
                <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
                    <div class="w-9 h-9 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-slate-900 leading-none">{{ $resumoFila['total'] }}</p>
                        <p class="text-[11px] font-medium text-slate-500 mt-1">Na fila</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
                    <div class="w-9 h-9 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-slate-900 leading-none">{{ $resumoFila['no_prazo'] }}</p>
                        <p class="text-[11px] font-medium text-slate-500 mt-1">Dentro do prazo</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
                    <div class="w-9 h-9 rounded-lg bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-slate-900 leading-none">{{ $resumoFila['atrasados'] }}</p>
                        <p class="text-[11px] font-medium text-slate-500 mt-1">Com prazo vencido</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-xl px-4 py-3 shadow-sm">
                    <div class="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xl font-bold text-slate-900 leading-none">{{ $resumoFila['suspensos'] }}</p>
                        <p class="text-[11px] font-medium text-slate-500 mt-1">Prazo suspenso</p>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </section>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">

        {{-- Aba: Consultar Processo --}}
        <div x-show="abaAtiva === 'consultar'" x-cloak x-transition>
            <div class="max-w-lg mx-auto">
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="px-6 pt-6 pb-4 text-center">
                        <div class="inline-flex items-center justify-center w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl mb-3 shadow-lg shadow-blue-600/20">
                            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        </div>
                        <h2 class="text-lg font-bold text-slate-900">Consultar Processo</h2>
                        <p class="text-xs text-slate-500 mt-1">Digite o CNPJ para ver todos os processos do estabelecimento</p>
                    </div>
                    <form action="{{ route('consultar.processo') }}" method="POST" class="px-6 pb-6 space-y-4">
                        @csrf
                        <div>
                            <label for="cnpj" class="block text-xs font-semibold text-slate-700 mb-1.5">CNPJ da Empresa</label>
                            <input
                                type="text"
                                id="cnpj"
                                name="cnpj"
                                x-model="cnpj"
                                @input="formatCnpj()"
                                placeholder="00.000.000/0000-00"
                                maxlength="18"
                                required
                                class="w-full px-4 py-3 text-sm font-mono border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-white transition"
                            >
                            <p class="mt-1.5 text-[11px] text-slate-500">Formato: 00.000.000/0000-00</p>
                        </div>
                        <button type="submit" class="w-full inline-flex items-center justify-center gap-2 py-3 px-4 bg-slate-900 hover:bg-slate-800 text-white text-sm font-bold rounded-xl shadow-lg shadow-slate-900/15 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            Buscar Processos
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Aba: Fila de Processos --}}
        <div x-show="abaAtiva === 'fila'" x-transition>
        @if(count($filaProcessos) > 0)
            {{-- Filtros + como funciona --}}
            <div class="grid lg:grid-cols-5 gap-4 mb-6">
                <div class="lg:col-span-3 bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                    <div class="flex items-center justify-between mb-3">
                        <h4 class="text-xs font-bold text-slate-900 uppercase tracking-wide flex items-center gap-2">
                            <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                            Filtrar por status
                        </h4>
                        <span class="text-[11px] font-medium text-slate-500 bg-slate-100 px-2.5 py-0.5 rounded-full" x-text="statusSelecionados.length + ' selecionado(s)'"></span>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @foreach(['aberto', 'em_analise', 'pendente', 'parado', 'arquivado'] as $st)
                        <button type="button" @click="toggleStatus('{{ $st }}')"
                                :class="statusSelecionados.includes('{{ $st }}') ? '{{ $statusColors[$st] }} ring-1' : 'bg-white text-slate-500 ring-1 ring-slate-200 hover:bg-slate-50'"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold transition">
                            <span class="w-1.5 h-1.5 rounded-full" :class="statusSelecionados.includes('{{ $st }}') ? '{{ $statusDots[$st] }}' : 'bg-slate-300'"></span>
                            {{ $statusLabels[$st] }}
                        </button>
                        @endforeach
                    </div>
                </div>

                <div class="lg:col-span-2 bg-gradient-to-br from-blue-50 to-indigo-50 rounded-2xl border border-blue-100 p-4 flex gap-3">
                    <div class="w-9 h-9 rounded-lg bg-white text-blue-600 flex items-center justify-center flex-shrink-0 shadow-sm">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-slate-900">Como funciona a fila?</h4>
                        <p class="text-xs text-slate-600 leading-relaxed mt-0.5">
                            O processo entra na fila <strong>após todos os documentos obrigatórios serem enviados e aprovados</strong>.
                            Cada unidade é avaliada separadamente: pendências em outras unidades ou na parte principal não impedem sua entrada na fila.
                            Unidades suspensas aparecem somente no filtro <strong>Parado</strong>, separadas das unidades em andamento.
                            O prazo conta a partir da aprovação do último documento obrigatório ou do reinício do processo/unidade, e fica <strong>suspenso</strong> enquanto o processo ou a unidade estiver parado.
                        </p>
                    </div>
                </div>
            </div>

            {{-- Filas por tipo de processo --}}
            <div class="space-y-6">
                @foreach($filaProcessos as $fila)
                @php
                    $atrasadosTipo = collect($fila['processos'])->filter(fn ($p) => !$p['pausado'] && $p['atrasado'])->count();
                @endphp
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    {{-- Cabeçalho da fila --}}
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 px-5 py-4 border-b border-slate-100">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-blue-600/20 flex-shrink-0">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-bold text-slate-900">{{ $fila['tipo'] }}</h3>
                                <p class="text-xs text-slate-500">Ordenado pela data de referência do prazo (mais antigo primeiro)</p>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">
                                {{ count($fila['processos']) }} {{ count($fila['processos']) == 1 ? 'registro' : 'registros' }}
                            </span>
                            @if($fila['prazo_analise'])
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 ring-1 ring-blue-200">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Prazo padrão: {{ $fila['prazo_analise'] }} dias · varia por risco
                            </span>
                            @endif
                            @if($atrasadosTipo > 0)
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700 ring-1 ring-red-200">
                                {{ $atrasadosTipo }} vencido(s)
                            </span>
                            @endif
                        </div>
                    </div>

                    {{-- Tabela --}}
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50/80 text-[11px] font-semibold text-slate-500 uppercase tracking-wider">
                                    <th class="px-5 py-2.5 w-16">Posição</th>
                                    <th class="px-5 py-2.5">Processo / Estabelecimento</th>
                                    <th class="px-5 py-2.5">Status</th>
                                    <th class="px-5 py-2.5" title="Data usada atualmente para a contagem do prazo">Referência do prazo</th>
                                    <th class="px-5 py-2.5">Tempo na fila</th>
                                    @if($fila['prazo_analise'])
                                    <th class="px-5 py-2.5">Prazo</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($fila['processos'] as $processo)
                                <tr class="hover:bg-slate-50/70 transition-colors align-top"
                                    x-cloak
                                    x-show="mostrarProcesso('{{ $processo['status'] }}')"
                                    x-transition:enter="transition ease-out duration-200"
                                    x-transition:enter-start="opacity-0"
                                    x-transition:enter-end="opacity-100">
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl text-sm font-bold
                                            {{ $processo['posicao'] <= 3 ? 'bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-sm' : 'bg-slate-100 text-slate-700' }}">
                                            {{ $processo['posicao'] }}º
                                        </span>
                                    </td>
                                    <td class="px-5 py-3.5 min-w-[260px]">
                                        <p class="text-xs font-mono font-semibold text-blue-700">{{ $processo['numero_processo'] }}</p>
                                        <p class="text-sm font-medium text-slate-900 mt-0.5">{{ $processo['estabelecimento'] }}</p>

                                        {{-- Prazo por unidade --}}
                                        @if(!empty($processo['unidades_prazo']))
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            @foreach($processo['unidades_prazo'] as $uPrazo)
                                            @php
                                                if ($uPrazo['pausado']) {
                                                    $corU = 'bg-amber-50 text-amber-800 ring-amber-200';
                                                    $textoU = $uPrazo['atrasado']
                                                        ? 'Suspenso • ' . abs($uPrazo['dias_restantes']) . 'd de atraso'
                                                        : 'Suspenso • ' . $uPrazo['dias_restantes'] . 'd restante(s)';
                                                } elseif ($uPrazo['atrasado']) {
                                                    $corU = 'bg-red-50 text-red-700 ring-red-200';
                                                    $textoU = abs($uPrazo['dias_restantes']) . 'd atrasado';
                                                } else {
                                                    $corU = $uPrazo['dias_restantes'] <= 2 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200';
                                                    $textoU = $uPrazo['dias_restantes'] . 'd restante(s)';
                                                }
                                            @endphp
                                            <span class="inline-flex items-center gap-1.5 pl-2 pr-2.5 py-1 rounded-lg text-[11px] ring-1 {{ $corU }}"
                                                  title="Referência do prazo da unidade: {{ $uPrazo['data_referencia_prazo'] ?? '-' }}{{ !empty($uPrazo['prazo_reiniciado']) ? ' (prazo reiniciado)' : '' }}">
                                                <svg class="w-3.5 h-3.5 opacity-70" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                                <span class="font-semibold">{{ $uPrazo['nome'] }}</span>
                                                <span class="opacity-40">|</span>
                                                <span>Prazo: {{ $uPrazo['prazo'] }} dias</span>
                                                <span class="opacity-40">|</span>
                                                <span>{{ $textoU }}</span>
                                                @if(!empty($uPrazo['prazo_reiniciado']))
                                                <span class="opacity-70">• reiniciado {{ $uPrazo['data_referencia_prazo'] }}</span>
                                                @endif
                                            </span>
                                            @endforeach
                                        </div>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ring-1 {{ $statusColors[$processo['status']] ?? 'bg-slate-100 text-slate-700 ring-slate-200' }}">
                                            <span class="w-1.5 h-1.5 rounded-full {{ $statusDots[$processo['status']] ?? 'bg-slate-400' }}"></span>
                                            {{ $statusLabels[$processo['status']] ?? ucfirst($processo['status']) }}
                                        </span>
                                        @if($processo['unidade_referencia'])
                                            <p class="text-[11px] text-slate-500 mt-1">Status da unidade</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="text-xs text-slate-700">{{ $processo['data_referencia_prazo'] }}</p>
                                        @if($processo['unidade_referencia'])
                                            <p class="text-[11px] text-slate-500 mt-0.5">Unidade: {{ $processo['unidade_referencia'] }}</p>
                                        @endif
                                        @if($processo['prazo_reiniciado'])
                                            <p class="text-[11px] font-medium text-blue-600 mt-0.5">Prazo reiniciado</p>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        <p class="text-xs font-semibold {{ $processo['pausado'] ? 'text-amber-700' : ($processo['atrasado'] ? 'text-red-600' : 'text-slate-900') }}">
                                            {{ $processo['tempo_formatado'] }}
                                        </p>
                                        @if($processo['pausado'])
                                            <p class="text-[11px] text-amber-700 mt-0.5">Contagem congelada</p>
                                        @endif
                                    </td>
                                    @if($fila['prazo_analise'])
                                    <td class="px-5 py-3.5 whitespace-nowrap">
                                        @if($processo['prazo'])
                                            <p class="text-[11px] text-slate-500 mb-1">Prazo: {{ $processo['prazo'] }} dias</p>
                                        @endif
                                        @if($processo['pausado'] && $processo['dias_restantes'] !== null && $processo['dias_restantes'] < 0)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 ring-1 ring-amber-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                Suspenso • {{ abs($processo['dias_restantes']) }}d de atraso
                                            </span>
                                        @elseif($processo['pausado'] && $processo['dias_restantes'] !== null)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 ring-1 ring-amber-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                Suspenso • {{ $processo['dias_restantes'] }}d restante(s)
                                            </span>
                                        @elseif($processo['pausado'])
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 ring-1 ring-amber-200">Prazo suspenso</span>
                                        @elseif($processo['atrasado'])
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-red-50 text-red-700 ring-1 ring-red-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                                {{ abs($processo['dias_restantes']) }}d atrasado
                                            </span>
                                        @elseif($processo['dias_restantes'] !== null && $processo['dias_restantes'] <= 2)
                                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-50 text-amber-700 ring-1 ring-amber-200">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                {{ $processo['dias_restantes'] }}d restante(s)
                                            </span>
                                        @elseif($processo['dias_restantes'] !== null)
                                            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200">
                                                {{ $processo['dias_restantes'] }}d restante(s)
                                            </span>
                                        @else
                                            <span class="text-xs text-slate-400">—</span>
                                        @endif
                                    </td>
                                    @endif
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 px-5 py-2.5 bg-slate-50/80 border-t border-slate-100 text-[11px] text-slate-500">
                        <span><strong>Total:</strong> {{ count($fila['processos']) }} registro(s) de processos/unidades, separados por status</span>
                        <span>Unidades com prazo próprio aparecem abaixo do estabelecimento</span>
                    </div>
                </div>
                @endforeach
            </div>

            {{-- Legenda --}}
            <div class="mt-6 bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wide mb-3">Legenda</h3>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-xs text-slate-600">
                    @foreach(['aberto' => 'Recém criado', 'em_analise' => 'Sendo analisado', 'pendente' => 'Aguardando docs', 'parado' => 'Suspenso', 'arquivado' => 'Arquivado'] as $st => $desc)
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 {{ $statusColors[$st] }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $statusDots[$st] }}"></span>{{ $statusLabels[$st] }}
                        </span>
                        {{ $desc }}
                    </span>
                    @endforeach
                    <span class="inline-flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 bg-amber-50 text-amber-800 ring-amber-200">Suspenso</span>
                        Prazo congelado (processo ou unidade parada)
                    </span>
                </div>
            </div>

        @else
            {{-- Estado vazio --}}
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-12 text-center">
                <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-slate-100 text-slate-400 mb-4">
                    <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <h3 class="text-base font-semibold text-slate-900 mb-1">Nenhum processo na fila</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto mb-6">
                    Não há processos ou unidades com documentação completa no momento. Eles aparecerão aqui quando seus documentos obrigatórios forem enviados e aprovados.
                </p>
                <a href="{{ route('home') }}" class="inline-flex items-center gap-2 px-4 py-2 bg-slate-900 text-white text-sm font-semibold rounded-xl hover:bg-slate-800 transition">
                    Voltar para Home
                </a>
            </div>
        @endif
        </div>
    </div>
</div>
@endsection
