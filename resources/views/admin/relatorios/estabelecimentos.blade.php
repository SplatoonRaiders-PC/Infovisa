@extends('layouts.admin')

@section('title', 'Relatório de Estabelecimentos e Processos')

@section('content')
@php
    $usuarioLogado = auth('interno')->user();
    $queryBase = request()->except(['page', 'situacao']);
    $urlSituacao = fn ($s) => route('admin.relatorios.estabelecimentos', array_filter($queryBase + ['situacao' => $s]));
    $iconesTipo = [
        'licenciamento' => ['cor' => 'blue', 'icone' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
        'projeto_arquitetonico' => ['cor' => 'violet', 'icone' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'],
        'analise_rotulagem' => ['cor' => 'amber', 'icone' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
    ];
    $coresTipo = [
        'blue' => ['bg' => 'bg-blue-50', 'text' => 'text-blue-600', 'bar' => 'bg-blue-500', 'ring' => 'hover:ring-blue-300'],
        'violet' => ['bg' => 'bg-violet-50', 'text' => 'text-violet-600', 'bar' => 'bg-violet-500', 'ring' => 'hover:ring-violet-300'],
        'amber' => ['bg' => 'bg-amber-50', 'text' => 'text-amber-600', 'bar' => 'bg-amber-500', 'ring' => 'hover:ring-amber-300'],
    ];
    $situacoes = [
        null => ['label' => 'Todos', 'total' => $indicadores['total']],
        'pendente' => ['label' => 'Pendentes', 'total' => $indicadores['pendentes']],
        'em_dia' => ['label' => 'Em dia', 'total' => $indicadores['em_dia']],
        'com_ativo' => ['label' => 'Com processo ativo', 'total' => $indicadores['com_ativo']],
        'sem_ativo' => ['label' => 'Sem processo ativo', 'total' => $indicadores['sem_ativo']],
    ];
    $fmt = fn ($n) => number_format((int) $n, 0, ',', '.');
@endphp

<div class="space-y-5">
    {{-- Cabeçalho --}}
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <nav class="flex items-center gap-1.5 text-xs text-slate-500 mb-1.5">
                <a href="{{ route('admin.relatorios.index') }}" class="hover:text-slate-800">Relatórios</a>
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="text-slate-800 font-medium">Estabelecimentos e Processos</span>
            </nav>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Controle de Estabelecimentos e Processos</h1>
            <p class="text-sm text-slate-500 mt-1">
                Quem já abriu processo, quem ainda precisa abrir e como está a tramitação ·
                <span class="font-medium text-slate-700">{{ $escopoVisual }}</span>
            </p>
        </div>
        <a href="{{ route('admin.relatorios.estabelecimentos.export', request()->query()) }}"
           class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold text-emerald-700 bg-emerald-50 ring-1 ring-inset ring-emerald-200 rounded-lg hover:bg-emerald-100 transition self-start md:self-auto">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            Exportar planilha (CSV)
        </a>
    </div>

    {{-- Filtros --}}
    <form method="GET" action="{{ route('admin.relatorios.estabelecimentos') }}"
          class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
        @if($filtros['situacao'])<input type="hidden" name="situacao" value="{{ $filtros['situacao'] }}">@endif
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-8 gap-3 items-end">
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Ano de referência</label>
                <select name="ano" class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    @foreach($anos as $ano)
                        <option value="{{ $ano }}" @selected($filtros['ano'] === (int) $ano)>{{ $ano }}</option>
                    @endforeach
                </select>
            </div>
            @if($usuarioLogado->isAdmin())
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Competência</label>
                <select name="competencia" class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Estadual e municipal</option>
                    <option value="estadual" @selected($filtros['competencia'] === 'estadual')>Estadual</option>
                    <option value="municipal" @selected($filtros['competencia'] === 'municipal')>Municipal</option>
                </select>
            </div>
            @endif
            @if($municipios->isNotEmpty())
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Município</label>
                <select name="municipio_id" class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Todos</option>
                    @foreach($municipios as $municipio)
                        <option value="{{ $municipio->id }}" @selected($filtros['municipio_id'] === $municipio->id)>{{ $municipio->nome }}</option>
                    @endforeach
                </select>
            </div>
            @endif
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Processo exigido</label>
                <select name="tipo" class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Todos os tipos</option>
                    @foreach($tipos as $codigo => $tipo)
                        <option value="{{ $codigo }}" @selected($filtros['tipo'] === $codigo)>{{ $tipo->nome }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Cadastro</label>
                <select name="status_estabelecimento" class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="aprovado" @selected($filtros['status_estabelecimento'] === 'aprovado')>Aprovados e ativos</option>
                    <option value="todos" @selected($filtros['status_estabelecimento'] === 'todos')>Todos os cadastros (exceto rejeitados)</option>
                </select>
            </div>
            <div class="col-span-2">
                <label class="block text-[11px] font-semibold text-slate-500 uppercase tracking-wide mb-1">Buscar</label>
                <input type="text" name="busca" value="{{ $filtros['busca'] }}" placeholder="Nome ou CNPJ/CPF"
                       class="w-full px-2.5 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            </div>
            <div class="col-span-2 md:col-span-1 2xl:col-span-2 flex gap-2">
                <button type="submit" class="flex-1 px-3 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">Aplicar</button>
                <a href="{{ route('admin.relatorios.estabelecimentos') }}" title="Limpar filtros"
                   class="px-3 py-2 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition">Limpar</a>
            </div>
        </div>
    </form>

    {{-- Indicadores principais --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Estabelecimentos</p>
            <p class="text-2xl font-bold text-slate-900 tabular-nums mt-1">{{ $fmt($indicadores['total']) }}</p>
            <p class="text-[11px] text-slate-500 mt-1">
                <span class="text-blue-600 font-semibold">{{ $fmt($indicadores['estadual']) }}</span> estaduais ·
                <span class="text-emerald-600 font-semibold">{{ $fmt($indicadores['municipal']) }}</span> municipais
            </p>
        </div>
        <a href="{{ $urlSituacao('com_ativo') }}" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4 hover:ring-2 hover:ring-blue-200 transition">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Com processo ativo</p>
            <p class="text-2xl font-bold text-blue-600 tabular-nums mt-1">{{ $fmt($indicadores['com_ativo']) }}</p>
            <p class="text-[11px] text-slate-500 mt-1">{{ $fmt($indicadores['sem_ativo']) }} sem nenhum processo ativo</p>
        </a>
        <a href="{{ $urlSituacao('pendente') }}" class="relative bg-gradient-to-br from-red-50 to-white rounded-2xl border border-red-200 shadow-sm p-4 hover:ring-2 hover:ring-red-200 transition">
            <p class="text-[11px] font-semibold text-red-700 uppercase tracking-wide">Precisam abrir processo</p>
            <p class="text-2xl font-bold text-red-600 tabular-nums mt-1">{{ $fmt($indicadores['pendentes']) }}</p>
            <p class="text-[11px] text-red-700/80 mt-1">têm atividade que exige processo e não abriram</p>
        </a>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Cobertura geral</p>
            <p class="text-2xl font-bold tabular-nums mt-1 {{ ($indicadores['cobertura'] ?? 0) >= 80 ? 'text-emerald-600' : (($indicadores['cobertura'] ?? 0) >= 50 ? 'text-amber-600' : 'text-red-600') }}">
                {{ $indicadores['cobertura'] !== null ? $indicadores['cobertura'] . '%' : '—' }}
            </p>
            <div class="mt-2 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full {{ ($indicadores['cobertura'] ?? 0) >= 80 ? 'bg-emerald-500' : (($indicadores['cobertura'] ?? 0) >= 50 ? 'bg-amber-500' : 'bg-red-500') }}" style="width: {{ $indicadores['cobertura'] ?? 0 }}%"></div>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Processos ativos</p>
            <p class="text-2xl font-bold text-slate-900 tabular-nums mt-1">{{ $fmt($indicadores['processos_ativos']) }}</p>
            <p class="text-[11px] mt-1 {{ $indicadores['processos_parados'] ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                {{ $fmt($indicadores['processos_parados']) }} {{ $indicadores['processos_parados'] === 1 ? 'parado' : 'parados' }}
            </p>
        </div>
    </div>

    {{-- Cobertura por tipo de processo --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach($indicadores['por_tipo'] as $codigo => $item)
            @php $cor = $coresTipo[$iconesTipo[$codigo]['cor'] ?? 'blue']; @endphp
            <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-xl {{ $cor['bg'] }} {{ $cor['text'] }} flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconesTipo[$codigo]['icone'] ?? '' }}"/></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-900 truncate">{{ $item['nome'] }}</p>
                            <p class="text-[11px] text-slate-500">
                                {{ $item['anual'] ? 'Anual · verificado em ' . $indicadores['ano'] : 'Processo único por estabelecimento' }}
                            </p>
                        </div>
                    </div>
                    <p class="text-lg font-bold text-slate-900 tabular-nums">{{ $item['cobertura'] !== null ? $item['cobertura'] . '%' : '—' }}</p>
                </div>
                <div class="mt-3 h-2 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full {{ $cor['bar'] }}" style="width: {{ $item['cobertura'] ?? 0 }}%"></div>
                </div>
                <div class="mt-3 flex items-center justify-between text-xs">
                    <span class="text-slate-500"><span class="font-semibold text-slate-800 tabular-nums">{{ $fmt($item['exigem']) }}</span> exigem</span>
                    <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['tipo' => $codigo, 'situacao' => 'em_dia'] + request()->except('page'))) }}"
                       class="text-emerald-700 hover:underline"><span class="font-semibold tabular-nums">{{ $fmt($item['atendidos']) }}</span> abriram</a>
                    <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['tipo' => $codigo, 'situacao' => 'pendente'] + request()->except('page'))) }}"
                       class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md font-semibold {{ $item['pendentes'] ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-slate-50 text-slate-400' }}">
                        {{ $fmt($item['pendentes']) }} não abriram
                    </a>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Gráficos --}}
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Processos abertos por mês · {{ $indicadores['ano'] }}</h3>
                    <p class="text-[11px] text-slate-500">Pela competência do estabelecimento</p>
                </div>
            </div>
            <div class="h-64"><canvas id="chartAberturas"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Situação: estadual x municipal</h3>
            <p class="text-[11px] text-slate-500 mb-3">Estabelecimentos em dia, pendentes e sem exigência</p>
            <div class="h-64"><canvas id="chartCompetencia"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Processos ativos por tipo</h3>
            <p class="text-[11px] text-slate-500 mb-3">{{ $fmt($indicadores['processos_ativos']) }} em tramitação</p>
            <div class="h-60 relative">
                @if($graficos['ativos_por_tipo']->isEmpty())
                    <div class="absolute inset-0 flex items-center justify-center text-xs text-slate-400">Nenhum processo ativo</div>
                @else
                    <canvas id="chartTipos"></canvas>
                @endif
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Há quanto tempo estão abertos</h3>
            <p class="text-[11px] text-slate-500 mb-3">Idade dos processos ativos</p>
            <div class="h-60"><canvas id="chartIdade"></canvas></div>
        </div>
        @if($graficos['top_municipios']->isNotEmpty())
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Municípios com mais pendências</h3>
            <p class="text-[11px] text-slate-500 mb-3">Estabelecimentos que precisam abrir processo</p>
            <div class="h-60"><canvas id="chartMunicipios"></canvas></div>
        </div>
        @else
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            <h3 class="text-sm font-semibold text-slate-900">Abertura por tipo exigido</h3>
            <p class="text-[11px] text-slate-500 mb-3">Abriram x não abriram</p>
            <div class="h-60"><canvas id="chartCobertura"></canvas></div>
        </div>
        @endif
    </div>

    {{-- Lista de estabelecimentos --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        <div class="px-5 pt-4 border-b border-slate-100">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 mb-3">
                <div>
                    <h3 class="text-sm font-semibold text-slate-900">Estabelecimentos</h3>
                    <p class="text-[11px] text-slate-500">{{ $fmt($totalFiltrado) }} {{ $totalFiltrado === 1 ? 'resultado' : 'resultados' }}{{ $filtros['tipo'] ? ' · exigem ' . ($tipos[$filtros['tipo']]->nome ?? '') : '' }}</p>
                </div>
            </div>
            <div class="flex gap-1 overflow-x-auto -mb-px">
                @foreach($situacoes as $chave => $sit)
                    @php $ativa = ($filtros['situacao'] ?? null) === ($chave ?: null); @endphp
                    <a href="{{ $urlSituacao($chave ?: null) }}"
                       class="whitespace-nowrap inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition {{ $ativa ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        {{ $sit['label'] }}
                        <span class="px-1.5 py-0.5 rounded-md text-[10px] tabular-nums {{ $ativa ? 'bg-blue-100 text-blue-700' : ($chave === 'pendente' && $sit['total'] ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $fmt($sit['total']) }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        @if($estabelecimentos->isEmpty())
            <div class="px-5 py-14 text-center">
                <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </div>
                <p class="text-sm font-semibold text-slate-800">Nenhum estabelecimento encontrado</p>
                <p class="text-xs text-slate-500 mt-1">Ajuste os filtros para ver outros resultados.</p>
            </div>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-slate-50/80 text-[11px] font-semibold text-slate-500 uppercase tracking-wide text-left">
                        <th class="px-5 py-2.5">Estabelecimento</th>
                        <th class="px-3 py-2.5">Processos exigidos pelas atividades</th>
                        <th class="px-3 py-2.5">Processos ativos</th>
                        <th class="px-3 py-2.5">Situação</th>
                        <th class="px-3 py-2.5 w-10"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($estabelecimentos as $linha)
                    @php $e = $linha['estabelecimento']; @endphp
                    <tr class="hover:bg-slate-50/60 align-top">
                        <td class="px-5 py-3 min-w-[240px]">
                            <a href="{{ route('admin.estabelecimentos.show', $e->id) }}" class="font-semibold text-slate-900 hover:text-blue-700 leading-snug">
                                {{ $e->nome_fantasia ?: $e->razao_social }}
                            </a>
                            <p class="text-[11px] text-slate-500 tabular-nums mt-0.5">{{ $e->documento_formatado }}</p>
                            <div class="flex flex-wrap items-center gap-1.5 mt-1.5">
                                <span class="text-[11px] text-slate-600">{{ $linha['municipio'] }}</span>
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $linha['competencia'] === 'estadual' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700' }}">
                                    {{ ucfirst($linha['competencia']) }}
                                </span>
                                @if($e->status !== 'aprovado')
                                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold bg-yellow-50 text-yellow-800">Cadastro {{ $e->status }}</span>
                                @endif
                            </div>
                        </td>
                        <td class="px-3 py-3 min-w-[260px]">
                            @if(empty($linha['demandas']))
                                <span class="text-xs text-slate-400">Nenhuma atividade exige processo</span>
                            @else
                                <div class="flex flex-col gap-1.5">
                                    @foreach($linha['demandas'] as $demanda)
                                        @if($demanda['atendida'])
                                            <a href="{{ route('admin.estabelecimentos.processos.show', [$e->id, $demanda['processo']->id]) }}"
                                               class="inline-flex items-center gap-1.5 self-start px-2 py-1 rounded-lg text-[11px] font-medium bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100">
                                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                                {{ $demanda['nome'] }}{{ $demanda['anual'] ? ' ' . $filtros['ano'] : '' }}
                                                <span class="font-bold tabular-nums">· {{ $demanda['processo']->numero_processo }}</span>
                                            </a>
                                        @else
                                            <span class="inline-flex items-center gap-1.5 self-start px-2 py-1 rounded-lg text-[11px] font-medium bg-red-50 text-red-800 ring-1 ring-inset ring-red-200">
                                                <svg class="w-3.5 h-3.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                                                {{ $demanda['nome'] }}{{ $demanda['anual'] ? ' ' . $filtros['ano'] : '' }}
                                                <span class="font-bold">· não aberto</span>
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 min-w-[200px]">
                            @forelse($linha['processos_ativos']->take(3) as $p)
                                <a href="{{ route('admin.estabelecimentos.processos.show', [$e->id, $p->id]) }}"
                                   class="flex items-center gap-1.5 text-xs text-slate-700 hover:text-blue-700 py-0.5" title="{{ $p->tipo_nome }} · aberto em {{ $p->created_at->format('d/m/Y') }}">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0 {{ $p->status === 'parado' ? 'bg-red-500' : 'bg-blue-500' }}"></span>
                                    <span class="font-semibold tabular-nums">{{ $p->numero_processo }}</span>
                                    <span class="text-slate-400 truncate max-w-[140px]">{{ $p->tipo_nome }}</span>
                                </a>
                            @empty
                                <span class="text-xs text-slate-400">Nenhum</span>
                            @endforelse
                            @if($linha['processos_ativos']->count() > 3)
                                <a href="{{ route('admin.estabelecimentos.processos.index', $e->id) }}" class="text-[11px] font-semibold text-blue-600 hover:underline">
                                    + {{ $linha['processos_ativos']->count() - 3 }} outros
                                </a>
                            @endif
                        </td>
                        <td class="px-3 py-3 whitespace-nowrap">
                            @php
                                $sitClasse = match ($linha['situacao']) {
                                    'pendente' => 'bg-red-50 text-red-700 ring-red-200',
                                    'em_dia' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                    default => 'bg-slate-100 text-slate-600 ring-slate-200',
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset {{ $sitClasse }}">{{ $linha['situacao_label'] }}</span>
                            <p class="text-[10px] text-slate-400 mt-1">
                                {{ $linha['ultimo_processo'] ? 'Último: ' . $linha['ultimo_processo']->format('d/m/Y') : 'Nunca abriu processo' }}
                            </p>
                        </td>
                        <td class="px-3 py-3 text-right">
                            <a href="{{ route('admin.estabelecimentos.show', $e->id) }}" title="Abrir estabelecimento"
                               class="inline-flex w-8 h-8 items-center justify-center rounded-lg text-slate-400 hover:text-blue-700 hover:bg-blue-50 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($estabelecimentos->hasPages())
            <div class="px-5 py-3 border-t border-slate-100">{{ $estabelecimentos->links() }}</div>
        @endif
        @endif
    </div>

    <p class="text-[11px] text-slate-400 leading-relaxed">
        Como a exigência é calculada: atividades CNAE comuns exigem <strong>Licenciamento</strong> (anual, verificado no ano de referência);
        a atividade <strong>Projeto Arquitetônico</strong> (PROJ_ARQ) e a <strong>Análise de Rotulagem</strong> (ANAL_ROT) exigem o respectivo processo, aberto uma única vez.
        Processo ativo = qualquer processo não arquivado.
    </p>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';
    Chart.defaults.plugins.legend.labels.usePointStyle = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 8;

    const cores = { estadual: '#2563eb', municipal: '#10b981', emDia: '#10b981', pendente: '#ef4444', neutro: '#cbd5e1' };
    const grid = { color: '#f1f5f9' };
    const graficos = @json($graficos);
    const el = id => document.getElementById(id);

    if (el('chartAberturas')) {
        new Chart(el('chartAberturas'), {
            type: 'bar',
            data: {
                labels: graficos.meses,
                datasets: [
                    { label: 'Estadual', data: graficos.aberturas.estadual, backgroundColor: cores.estadual, borderRadius: 4, maxBarThickness: 28 },
                    { label: 'Municipal', data: graficos.aberturas.municipal, backgroundColor: cores.municipal, borderRadius: 4, maxBarThickness: 28 },
                ]
            },
            options: { maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                scales: { y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid }, x: { stacked: true, grid: { display: false } } },
                plugins: { legend: { position: 'top', align: 'end' } } }
        });
    }

    if (el('chartCompetencia')) {
        const c = graficos.por_competencia;
        new Chart(el('chartCompetencia'), {
            type: 'bar',
            data: {
                labels: ['Estadual', 'Municipal'],
                datasets: [
                    { label: 'Em dia', data: [c.estadual.em_dia, c.municipal.em_dia], backgroundColor: cores.emDia, borderRadius: 4 },
                    { label: 'Pendentes', data: [c.estadual.pendente, c.municipal.pendente], backgroundColor: cores.pendente, borderRadius: 4 },
                    { label: 'Sem exigência', data: [c.estadual.sem_exigencia, c.municipal.sem_exigencia], backgroundColor: cores.neutro, borderRadius: 4 },
                ]
            },
            options: { maintainAspectRatio: false,
                scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid } },
                plugins: { legend: { position: 'bottom' } } }
        });
    }

    if (el('chartTipos')) {
        const tipos = graficos.ativos_por_tipo;
        new Chart(el('chartTipos'), {
            type: 'doughnut',
            data: { labels: Object.keys(tipos), datasets: [{ data: Object.values(tipos),
                backgroundColor: ['#2563eb', '#8b5cf6', '#f59e0b', '#10b981', '#ec4899', '#64748b', '#06b6d4'], borderWidth: 2, borderColor: '#fff' }] },
            options: { maintainAspectRatio: false, cutout: '62%', plugins: { legend: { position: 'bottom' } } }
        });
    }

    if (el('chartIdade')) {
        const idade = graficos.idade_ativos;
        new Chart(el('chartIdade'), {
            type: 'bar',
            data: { labels: Object.keys(idade), datasets: [{ label: 'Processos', data: Object.values(idade),
                backgroundColor: ['#10b981', '#f59e0b', '#f97316', '#ef4444'], borderRadius: 6, maxBarThickness: 44 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid }, x: { grid: { display: false } } } }
        });
    }

    if (el('chartMunicipios')) {
        const m = graficos.top_municipios;
        new Chart(el('chartMunicipios'), {
            type: 'bar',
            data: { labels: Object.keys(m), datasets: [{ label: 'Pendentes', data: Object.values(m), backgroundColor: '#ef4444', borderRadius: 4, maxBarThickness: 18 }] },
            options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { precision: 0 }, grid }, y: { grid: { display: false } } } }
        });
    }

    if (el('chartCobertura')) {
        const t = graficos.cobertura_tipos;
        new Chart(el('chartCobertura'), {
            type: 'bar',
            data: { labels: t.map(i => i.nome), datasets: [
                { label: 'Abriram', data: t.map(i => i.atendidos), backgroundColor: cores.emDia, borderRadius: 4 },
                { label: 'Não abriram', data: t.map(i => i.pendentes), backgroundColor: cores.pendente, borderRadius: 4 },
            ] },
            options: { indexAxis: 'y', maintainAspectRatio: false,
                scales: { x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid }, y: { stacked: true, grid: { display: false } } },
                plugins: { legend: { position: 'bottom' } } }
        });
    }
})();
</script>
@endpush
