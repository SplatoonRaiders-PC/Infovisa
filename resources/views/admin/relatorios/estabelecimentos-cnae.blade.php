@extends('layouts.admin')

@section('title', 'Estabelecimentos por Atividade (CNAE)')
@section('page-title', 'Relatórios')

@php
    $usuarioLogado = auth('interno')->user();
    $rota = 'admin.relatorios.estabelecimentos-cnae';
    $query = request()->except(['page', 'est_page', 'exportar', 'aba']);
    $urlCom = fn(array $params) => route($rota, array_filter(array_merge($query, $params), fn($v) => $v !== null && $v !== ''));

    // Classes completas (Tailwind não detecta classes montadas dinamicamente)
    $coresArea = [
        'saude' => ['chip' => 'bg-rose-50 text-rose-700 ring-rose-200', 'dot' => 'bg-rose-500', 'hex' => '#f43f5e'],
        'produtos_saude' => ['chip' => 'bg-violet-50 text-violet-700 ring-violet-200', 'dot' => 'bg-violet-500', 'hex' => '#8b5cf6'],
        'alimentos' => ['chip' => 'bg-amber-50 text-amber-700 ring-amber-200', 'dot' => 'bg-amber-500', 'hex' => '#f59e0b'],
        'interesse_saude' => ['chip' => 'bg-sky-50 text-sky-700 ring-sky-200', 'dot' => 'bg-sky-500', 'hex' => '#0ea5e9'],
        'ambiente' => ['chip' => 'bg-emerald-50 text-emerald-700 ring-emerald-200', 'dot' => 'bg-emerald-500', 'hex' => '#10b981'],
        null => ['chip' => 'bg-gray-100 text-gray-600 ring-gray-200', 'dot' => 'bg-gray-400', 'hex' => '#94a3b8'],
    ];
    $corArea = fn($area) => $coresArea[$area] ?? $coresArea[null];

    $situacoes = ['ativos' => 'Ativos (aprovados)', 'pendentes' => 'Pendentes de aprovação', 'rejeitados' => 'Rejeitados', 'inativos' => 'Desativados', 'todos' => 'Todos'];

    $filtrosAtivos = collect([
        $tituloFiltro ? ['rotulo' => $tituloFiltro, 'remover' => ['atividade' => null, 'divisao' => null, 'cnae' => null]] : null,
        $filtros['municipio_id'] ? ['rotulo' => 'Município: ' . ($municipios->firstWhere('id', $filtros['municipio_id'])->nome ?? '-'), 'remover' => ['municipio_id' => null]] : null,
        $filtros['competencia'] ? ['rotulo' => 'Competência ' . $filtros['competencia'], 'remover' => ['competencia' => null]] : null,
        $filtros['situacao'] !== 'ativos' ? ['rotulo' => 'Situação: ' . $situacoes[$filtros['situacao']], 'remover' => ['situacao' => null]] : null,
        $filtros['considerar'] === 'principal' ? ['rotulo' => 'Somente atividade principal', 'remover' => ['considerar' => null]] : null,
        $filtros['busca'] !== '' ? ['rotulo' => 'Busca: "' . $filtros['busca'] . '"', 'remover' => ['busca_estabelecimento' => null]] : null,
    ])->filter();
@endphp

@section('content')
<div class="space-y-5 max-w-[1440px] mx-auto" x-data="{ aba: @js(request('aba') === 'estabelecimentos' ? 'estabelecimentos' : 'cnaes') }">

    {{-- Cabeçalho --}}
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <div class="flex items-center gap-1.5 text-xs text-gray-400 mb-1">
                <a href="{{ route('admin.relatorios.index') }}" class="hover:text-gray-600 transition">Relatórios</a>
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span class="text-gray-700 font-medium">Estabelecimentos por Atividade</span>
            </div>
            <h1 class="text-xl font-bold text-gray-900 tracking-tight">Estabelecimentos por Atividade (CNAE)</h1>
            <p class="text-xs text-gray-400 mt-0.5 flex items-center gap-1.5">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                {{ $escopoVisual }}
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ $urlCom(['exportar' => 'csv']) }}"
               class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg hover:bg-emerald-100 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Exportar planilha
            </a>
            <button type="button" onclick="window.print()"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition print:hidden">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                Imprimir
            </button>
        </div>
    </div>

    {{-- Atalhos por tipo de atividade --}}
    @if($atalhos->isNotEmpty())
    <div class="print:hidden">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest mb-2">Atalhos por tipo de atividade</p>
        <div class="flex flex-wrap gap-2">
            <a href="{{ $urlCom(['atividade' => null, 'divisao' => null, 'cnae' => null]) }}"
               class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold ring-1 transition {{ !$tituloFiltro ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-600 ring-gray-200 hover:ring-gray-300' }}">
                Todas as atividades
                <span class="px-1.5 rounded-full text-[10px] {{ !$tituloFiltro ? 'bg-white/20' : 'bg-gray-100' }}">{{ number_format($totais['escopo'], 0, ',', '.') }}</span>
            </a>
            @foreach($atalhos as $atalho)
                @php $ativo = $filtros['atividade'] === 'tipo:' . $atalho['slug']; @endphp
                <a href="{{ $urlCom(['atividade' => 'tipo:' . $atalho['slug'], 'divisao' => null, 'cnae' => null]) }}"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-xs font-semibold ring-1 transition {{ $ativo ? 'bg-gray-900 text-white ring-gray-900' : 'bg-white text-gray-600 ring-gray-200 hover:ring-gray-300' }}">
                    <span class="w-2 h-2 rounded-full {{ $corArea($atalho['area'])['dot'] }}"></span>
                    {{ $atalho['nome'] }}
                    <span class="px-1.5 rounded-full text-[10px] {{ $ativo ? 'bg-white/20' : 'bg-gray-100' }}">{{ number_format($atalho['total'], 0, ',', '.') }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Filtros --}}
    <form method="GET" action="{{ route($rota) }}" x-data x-ref="form" class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 print:hidden">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-3">
            <div class="lg:col-span-4">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Tipo de atividade</label>
                <select name="atividade" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Todas as atividades</option>
                    @foreach(\App\Support\CnaeCatalogo::tiposPorArea() as $area => $grupo)
                        <optgroup label="{{ $grupo['nome'] }}">
                            <option value="area:{{ $area }}" @selected($filtros['atividade'] === 'area:' . $area)>
                                ▸ Toda a área: {{ $grupo['nome'] }} ({{ $contagemAreasEscopo[$area] ?? 0 }})
                            </option>
                            @foreach($grupo['tipos'] as $slug => $nome)
                                <option value="tipo:{{ $slug }}" @selected($filtros['atividade'] === 'tipo:' . $slug)>
                                    {{ $nome }} ({{ $contagemTiposEscopo[$slug] ?? 0 }})
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-4">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Divisão CNAE (ramo econômico)</label>
                <select name="divisao" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Todas as divisões</option>
                    @foreach($divisoesEscopo as $divisao)
                        <option value="{{ $divisao['codigo'] }}" @selected($filtros['divisao'] === $divisao['codigo'])>
                            {{ $divisao['codigo'] }} - {{ $divisao['nome'] }} ({{ $divisao['total'] }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-4">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">CNAE específico</label>
                <input type="text" name="cnae" list="lista-cnaes" autocomplete="off"
                       value="{{ $filtros['cnae'] ? preg_replace('/^(\d{4})(\d)(\d{2})$/', '$1-$2/$3', $filtros['cnae']) : '' }}"
                       placeholder="Digite o código ou escolha da lista..."
                       @change="$refs.form.submit()"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                <datalist id="lista-cnaes">
                    @foreach($cnaesEscopo as $cnae)
                        <option value="{{ preg_replace('/^(\d{4})(\d)(\d{2})$/', '$1-$2/$3', $cnae['codigo']) }}">{{ $cnae['rotulo'] }}</option>
                    @endforeach
                </datalist>
            </div>

            @unless($usuarioLogado->isMunicipal())
            <div class="lg:col-span-3">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Município</label>
                <select name="municipio_id" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Todos os municípios</option>
                    @foreach($municipios as $municipio)
                        <option value="{{ $municipio->id }}" @selected((string) $filtros['municipio_id'] === (string) $municipio->id)>{{ $municipio->nome }}</option>
                    @endforeach
                </select>
            </div>
            @endunless
            @if($usuarioLogado->isAdmin())
            <div class="lg:col-span-2">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Competência</label>
                <select name="competencia" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Todas</option>
                    <option value="estadual" @selected($filtros['competencia'] === 'estadual')>Estadual</option>
                    <option value="municipal" @selected($filtros['competencia'] === 'municipal')>Municipal</option>
                </select>
            </div>
            @endif
            <div class="lg:col-span-2">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Situação</label>
                <select name="situacao" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    @foreach($situacoes as $valor => $rotulo)
                        <option value="{{ $valor }}" @selected($filtros['situacao'] === $valor)>{{ $rotulo }}</option>
                    @endforeach
                </select>
            </div>
            <div class="lg:col-span-2">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1" title="Qualquer atividade exercida ou somente a principal">Considerar</label>
                <select name="considerar" @change="$refs.form.submit()"
                        class="w-full px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Qualquer atividade</option>
                    <option value="principal" @selected($filtros['considerar'] === 'principal')>Só a principal</option>
                </select>
            </div>
            <div class="{{ $usuarioLogado->isAdmin() ? 'lg:col-span-3' : 'lg:col-span-5' }}">
                <label class="block text-[11px] font-semibold text-gray-500 uppercase tracking-wider mb-1">Estabelecimento</label>
                <div class="flex gap-2">
                    <input type="text" name="busca_estabelecimento" value="{{ $filtros['busca'] }}" placeholder="Nome, razão social, CNPJ..."
                           class="flex-1 min-w-0 px-3 py-2 text-sm border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <button type="submit" class="px-3 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-lg hover:bg-indigo-700 transition" title="Pesquisar">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </button>
                </div>
            </div>
        </div>

        @if($filtrosAtivos->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2 mt-3 pt-3 border-t border-gray-100">
                <span class="text-xs text-gray-400">Filtros:</span>
                @foreach($filtrosAtivos as $filtro)
                    <a href="{{ $urlCom($filtro['remover']) }}" class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-indigo-50 text-indigo-700 rounded-full text-xs font-medium hover:bg-indigo-100 transition">
                        {{ $filtro['rotulo'] }}
                        <span class="text-indigo-400">&times;</span>
                    </a>
                @endforeach
                <a href="{{ route($rota) }}" class="text-xs text-gray-500 hover:text-gray-700 underline underline-offset-2 ml-1">Limpar tudo</a>
            </div>
        @endif
    </form>

    {{-- KPIs --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="col-span-2 bg-gradient-to-br from-indigo-600 to-violet-700 rounded-xl p-5 text-white shadow-lg shadow-indigo-200/50 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-28 h-28 bg-white/10 rounded-full -translate-y-10 translate-x-10"></div>
            <p class="text-[10px] font-semibold uppercase tracking-widest text-indigo-200">{{ $tituloFiltro ?? 'Todas as atividades' }}</p>
            <p class="text-4xl font-black mt-2 tracking-tight">{{ number_format($totais['estabelecimentos'], 0, ',', '.') }}</p>
            <p class="text-xs text-indigo-200 mt-1">
                estabelecimento{{ $totais['estabelecimentos'] === 1 ? '' : 's' }}
                @if($tituloFiltro && $totais['escopo'] > 0)
                    · {{ number_format($totais['estabelecimentos'] * 100 / $totais['escopo'], 1, ',', '.') }}% de {{ number_format($totais['escopo'], 0, ',', '.') }} no escopo
                @endif
            </p>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex flex-col justify-between">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">CNAEs distintos</p>
            <p class="text-3xl font-black text-indigo-600 mt-1">{{ number_format($totais['cnaes'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm flex flex-col justify-between">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">Municípios</p>
            <p class="text-3xl font-black text-teal-600 mt-1">{{ number_format($totais['municipios'], 0, ',', '.') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-gray-100 p-4 shadow-sm col-span-2 lg:col-span-1">
            <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-widest">Competência</p>
            <div class="mt-2 space-y-1.5 text-sm">
                <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-gray-600"><span class="w-2 h-2 rounded-full bg-blue-500"></span>Estadual</span><span class="font-bold text-gray-900">{{ number_format($totais['estadual'], 0, ',', '.') }}</span></div>
                <div class="flex items-center justify-between"><span class="flex items-center gap-1.5 text-gray-600"><span class="w-2 h-2 rounded-full bg-emerald-500"></span>Municipal</span><span class="font-bold text-gray-900">{{ number_format($totais['municipal'], 0, ',', '.') }}</span></div>
            </div>
        </div>
    </div>

    @if($totais['estabelecimentos'] === 0)
        <div class="bg-white rounded-xl border border-gray-100 shadow-sm px-6 py-16 text-center">
            <div class="mx-auto w-12 h-12 rounded-full bg-gray-100 flex items-center justify-center mb-3">
                <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <p class="text-sm font-semibold text-gray-700">Nenhum estabelecimento encontrado</p>
            <p class="text-xs text-gray-400 mt-1">Ajuste os filtros ou <a href="{{ route($rota) }}" class="text-indigo-600 hover:underline">limpe a pesquisa</a>.</p>
        </div>
    @else

    {{-- Gráficos --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-sm font-semibold text-gray-900">{{ $graficos['principal']['titulo'] }}</h2>
                <span class="text-[11px] text-gray-400">{{ count($graficos['principal']['valores']) < ($detalharPorCnae ? $resumoCnaes->count() : $porTipo->count()) ? 'Top ' . count($graficos['principal']['valores']) : '' }}</span>
            </div>
            <div style="height: {{ max(300, count($graficos['principal']['valores']) * 30 + 30) }}px">
                <canvas id="graficoPrincipal"></canvas>
            </div>
            @unless($detalharPorCnae)
                <p class="mt-2 text-[11px] text-gray-400">Clique em uma barra para detalhar o tipo de atividade. Um estabelecimento pode aparecer em mais de um tipo.</p>
            @endunless
        </div>
        <div class="space-y-4">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Competência</h2>
                <div class="h-44"><canvas id="graficoCompetencia"></canvas></div>
            </div>
            @unless($usuarioLogado->isMunicipal())
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Municípios com mais estabelecimentos</h2>
                <div style="height: {{ max(140, count($graficos['municipios']['valores']) * 24 + 20) }}px"><canvas id="graficoMunicipios"></canvas></div>
            </div>
            @endunless
        </div>
    </div>

    {{-- Abas: resumo por CNAE / estabelecimentos --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
        <div class="flex items-center justify-between px-4 pt-3 border-b border-gray-100">
            <div class="flex gap-1 text-sm font-semibold">
                <button type="button" @click="aba = 'cnaes'" :class="aba === 'cnaes' ? 'text-indigo-700 border-indigo-600' : 'text-gray-500 border-transparent hover:text-gray-700'" class="px-3 pb-2.5 border-b-2 transition">
                    Resumo por CNAE <span class="ml-1 text-xs font-medium text-gray-400">{{ $resumoCnaes->count() }}</span>
                </button>
                <button type="button" @click="aba = 'estabelecimentos'" :class="aba === 'estabelecimentos' ? 'text-indigo-700 border-indigo-600' : 'text-gray-500 border-transparent hover:text-gray-700'" class="px-3 pb-2.5 border-b-2 transition">
                    Estabelecimentos <span class="ml-1 text-xs font-medium text-gray-400">{{ number_format($totais['estabelecimentos'], 0, ',', '.') }}</span>
                </button>
            </div>
        </div>

        {{-- Resumo por CNAE --}}
        <div x-show="aba === 'cnaes'" class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50/70 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-2.5 text-left">CNAE</th>
                        <th class="px-4 py-2.5 text-left">Atividade</th>
                        <th class="px-4 py-2.5 text-left w-64">Estabelecimentos</th>
                        <th class="px-4 py-2.5 text-center" title="Estabelecimentos em que este CNAE é a atividade principal">Principal</th>
                        <th class="px-4 py-2.5 text-center">Est. / Mun.</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($resumoCnaes as $item)
                        @php $cor = $corArea(\App\Support\CnaeCatalogo::areaDoTipo($item['tipo'])); @endphp
                        <tr class="hover:bg-gray-50/60 {{ $filtros['cnae'] === $item['codigo'] ? 'bg-indigo-50/50' : '' }}">
                            <td class="px-4 py-2.5 font-mono text-xs font-semibold text-indigo-700 whitespace-nowrap">{{ $item['codigo_formatado'] }}</td>
                            <td class="px-4 py-2.5">
                                <p class="text-gray-800">{{ $item['descricao'] }}</p>
                                <span class="inline-flex items-center gap-1 mt-1 px-1.5 py-0.5 rounded text-[10px] font-semibold ring-1 {{ $cor['chip'] }}">{{ $item['tipo_nome'] }}</span>
                            </td>
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
                                        <div class="h-full rounded-full bg-indigo-500" style="width: {{ min(100, $item['percentual']) }}%"></div>
                                    </div>
                                    <span class="w-10 text-right font-bold text-gray-900">{{ number_format($item['total'], 0, ',', '.') }}</span>
                                    <span class="w-12 text-right text-[11px] text-gray-400">{{ number_format($item['percentual'], 1, ',', '.') }}%</span>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-center text-gray-600">{{ $item['como_principal'] }}</td>
                            <td class="px-4 py-2.5 text-center text-xs whitespace-nowrap"><span class="text-blue-600 font-semibold">{{ $item['estadual'] }}</span> <span class="text-gray-300">/</span> <span class="text-emerald-600 font-semibold">{{ $item['municipal'] }}</span></td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                <a href="{{ $urlCom(['cnae' => $item['codigo'], 'aba' => 'estabelecimentos']) }}" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800">Ver estabelecimentos →</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="px-4 py-2.5 text-[11px] text-gray-400 border-t border-gray-50">
                Percentual sobre os {{ number_format($totais['estabelecimentos'], 0, ',', '.') }} estabelecimentos filtrados. Um estabelecimento com vários CNAEs é contado em cada um deles.
            </p>
        </div>

        {{-- Estabelecimentos --}}
        <div x-show="aba === 'estabelecimentos'" x-cloak>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50/70 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-2.5 text-left">Estabelecimento</th>
                            <th class="px-4 py-2.5 text-left">Município</th>
                            <th class="px-4 py-2.5 text-left">Atividades encontradas</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($estabelecimentos as $estabelecimento)
                            @php $outras = $estabelecimento->cnaes_relatorio->count() - $estabelecimento->cnaes_correspondentes->count(); @endphp
                            <tr class="hover:bg-gray-50/60 align-top">
                                <td class="px-4 py-3">
                                    <a href="{{ route('admin.estabelecimentos.show', $estabelecimento->id) }}" class="font-semibold text-gray-900 hover:text-indigo-700 hover:underline">
                                        {{ $estabelecimento->nome_fantasia ?: ($estabelecimento->razao_social ?: $estabelecimento->nome_completo) }}
                                    </a>
                                    @if($estabelecimento->nome_fantasia && $estabelecimento->razao_social)
                                        <p class="text-xs text-gray-400">{{ $estabelecimento->razao_social }}</p>
                                    @endif
                                    <p class="text-xs text-gray-500 mt-0.5 font-mono">{{ $estabelecimento->cnpj ? 'CNPJ ' . $estabelecimento->cnpj_formatado : ($estabelecimento->cpf ? 'CPF ' . $estabelecimento->cpf_formatado : '') }}</p>
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <p class="text-gray-700">{{ $estabelecimento->municipio->nome ?? ($estabelecimento->cidade ?: '-') }}</p>
                                    <span class="inline-flex mt-1 px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $estabelecimento->competencia_relatorio === 'estadual' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700' }}">
                                        {{ ucfirst($estabelecimento->competencia_relatorio) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($estabelecimento->cnaes_correspondentes as $cnae)
                                            <a href="{{ $urlCom(['cnae' => $cnae['codigo'], 'aba' => 'estabelecimentos']) }}" title="{{ $cnae['descricao'] }}"
                                               class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] ring-1 {{ $corArea(\App\Support\CnaeCatalogo::areaDoTipo($cnae['tipo']))['chip'] }} hover:opacity-80">
                                                <span class="font-mono font-semibold">{{ $cnae['codigo_formatado'] }}</span>
                                                <span class="hidden xl:inline truncate max-w-[220px]">{{ $cnae['descricao'] }}</span>
                                                @if($cnae['principal'])<span class="font-bold" title="Atividade principal">★</span>@endif
                                            </a>
                                        @endforeach
                                        @if($outras > 0)
                                            <span class="px-2 py-0.5 rounded text-[11px] bg-gray-100 text-gray-500"
                                                  title="{{ $estabelecimento->cnaes_relatorio->whereNotIn('codigo', $estabelecimento->cnaes_correspondentes->pluck('codigo'))->map(fn($c) => $c['codigo_formatado'] . ' ' . $c['descricao'])->implode("\n") }}">
                                                +{{ $outras }} outra{{ $outras > 1 ? 's' : '' }}
                                            </span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($estabelecimentos->hasPages())
                <div class="px-4 py-3 border-t border-gray-100 bg-gray-50/50">
                    {{ $estabelecimentos->appends(['aba' => 'estabelecimentos'])->links('pagination.tailwind-clean') }}
                </div>
            @endif
        </div>
    </div>
    @endif
</div>

@if($totais['estabelecimentos'] > 0)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Chart.defaults.font.family = "'Inter','Segoe UI',system-ui,sans-serif";
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';

    const graficos = @json($graficos);
    const coresArea = @json(collect($coresArea)->map(fn($c) => $c['hex']));
    const porTipo = @json($porTipo);
    const detalharPorCnae = @json($detalharPorCnae);
    const urlBase = @json($urlCom(['atividade' => null, 'divisao' => null, 'cnae' => null]));
    const grade = { color: '#f1f5f9' };

    // Gráfico principal (barras horizontais)
    const coresPrincipal = detalharPorCnae
        ? graficos.principal.valores.map(() => '#6366f1')
        : porTipo.slice(0, 15).map(t => coresArea[t.area] || coresArea['']);

    new Chart(document.getElementById('graficoPrincipal'), {
        type: 'bar',
        data: {
            labels: graficos.principal.labels,
            datasets: [{ data: graficos.principal.valores, backgroundColor: coresPrincipal, borderRadius: 4, barThickness: 18 }]
        },
        options: {
            indexAxis: 'y',
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.x} estabelecimento(s)` } }
            },
            scales: {
                x: { beginAtZero: true, ticks: { precision: 0 }, grid: grade },
                y: { grid: { display: false }, ticks: { color: '#334155', autoSkip: false } }
            },
            onHover: (e, els) => { e.native.target.style.cursor = (!detalharPorCnae && els.length && porTipo[els[0].index]?.slug !== 'outros') ? 'pointer' : 'default'; },
            onClick: (e, els) => {
                if (detalharPorCnae || !els.length) return;
                const tipo = porTipo[els[0].index];
                if (!tipo || tipo.slug === 'outros') return;
                const url = new URL(urlBase, window.location.origin);
                url.searchParams.set('atividade', 'tipo:' + tipo.slug);
                window.location = url.toString();
            }
        }
    });

    // Competência (rosca)
    new Chart(document.getElementById('graficoCompetencia'), {
        type: 'doughnut',
        data: {
            labels: graficos.competencia.labels,
            datasets: [{ data: graficos.competencia.valores, backgroundColor: ['#3b82f6', '#10b981'], borderWidth: 2, borderColor: '#fff' }]
        },
        options: { maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true } } } }
    });

    // Municípios
    const elMunicipios = document.getElementById('graficoMunicipios');
    if (elMunicipios) {
        new Chart(elMunicipios, {
            type: 'bar',
            data: {
                labels: graficos.municipios.labels,
                datasets: [{ data: graficos.municipios.valores, backgroundColor: '#14b8a6', borderRadius: 4, barThickness: 14 }]
            },
            options: {
                indexAxis: 'y',
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid: grade },
                    y: { grid: { display: false }, ticks: { color: '#334155', autoSkip: false } }
                }
            }
        });
    }
});
</script>
@endif
@endsection
