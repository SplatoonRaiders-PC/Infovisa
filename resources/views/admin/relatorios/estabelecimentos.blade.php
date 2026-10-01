@extends('layouts.admin')

@section('title', 'Relatório de Estabelecimentos e Processos')
@section('page-title', 'Relatório de Estabelecimentos e Processos')

@section('content')
@php
    $usuarioLogado = auth('interno')->user();
    $queryBase = request()->except(['page', 'situacao']);
    $urlSituacao = fn ($s) => route('admin.relatorios.estabelecimentos', array_filter($queryBase + ['situacao' => $s]));
    // "Não abriram" por setor (público/privado), opcionalmente de um tipo de processo
    $urlPendenteSetor = function ($setor, $tipo = null) {
        $params = array_merge(request()->except(['page', 'situacao', 'setor']), ['situacao' => 'pendente', 'setor' => $setor]);
        if ($tipo) {
            $params['tipo'] = $tipo;
        }
        return route('admin.relatorios.estabelecimentos', array_filter($params, fn ($v) => $v !== null && $v !== ''));
    };
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
    $tipoFoco = $filtros['tipo'];
    if ($tipoFoco) {
        // Com um processo escolhido: etapas do processo no ano (substituem "com/sem processo ativo")
        $situacoes = [
            null => ['label' => 'Todos', 'total' => $indicadores['total']],
            'pendente' => ['label' => 'Não abriram', 'total' => $indicadores['pendentes']],
            'em_dia' => ['label' => 'Abriram', 'total' => $indicadores['em_dia']],
        ];
        if ($tipoFoco === 'licenciamento') {
            $situacoes['com_alvara'] = ['label' => 'Com alvará sanitário', 'total' => $indicadores['com_alvara']];
        }
        $situacoes['doc_completa'] = ['label' => $tipoFoco === 'licenciamento' ? 'Doc. completa (sem alvará)' : 'Doc. completa', 'total' => $indicadores['doc_completa']];
        $situacoes['doc_incompleta'] = ['label' => 'Doc. incompleta', 'total' => $indicadores['doc_incompleta']];
    } else {
        $situacoes = [
            null => ['label' => 'Todos', 'total' => $indicadores['total']],
            'pendente' => ['label' => 'Pendentes', 'total' => $indicadores['pendentes']],
            'em_dia' => ['label' => 'Em dia', 'total' => $indicadores['em_dia']],
            'com_ativo' => ['label' => 'Com processo ativo', 'total' => $indicadores['com_ativo']],
            'sem_ativo' => ['label' => 'Sem processo ativo', 'total' => $indicadores['sem_ativo']],
        ];
        if ($totalSemAtividade > 0) {
            $situacoes['sem_atividade'] = ['label' => 'Sem atividade marcada', 'total' => $totalSemAtividade];
        }
    }
    $etapaClasse = [
        'nao_abriu' => 'bg-red-50 text-red-700 ring-red-200',
        'com_alvara' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
        'doc_completa' => 'bg-blue-50 text-blue-700 ring-blue-200',
        'doc_incompleta' => 'bg-amber-50 text-amber-700 ring-amber-200',
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
    @php
        $anoAtual = (int) now()->year;
        // Filtros ativos (diferentes do padrão), cada um com link para removê-lo
        $removerFiltro = fn (...$chaves) => route('admin.relatorios.estabelecimentos', array_filter(request()->except(array_merge(['page'], $chaves)), fn ($v) => $v !== null && $v !== ''));
        $filtrosAtivos = array_values(array_filter([
            $filtros['busca'] !== '' ? ['rotulo' => 'Busca: "' . $filtros['busca'] . '"', 'url' => $removerFiltro('busca')] : null,
            $filtros['ano'] !== $anoAtual ? ['rotulo' => 'Ano ' . $filtros['ano'], 'url' => $removerFiltro('ano')] : null,
            $filtros['competencia'] ? ['rotulo' => 'Competência ' . ucfirst($filtros['competencia']), 'url' => $removerFiltro('competencia')] : null,
            $filtros['municipio_id'] ? ['rotulo' => 'Município: ' . ($municipios->firstWhere('id', $filtros['municipio_id'])->nome ?? $filtros['municipio_id']), 'url' => $removerFiltro('municipio_id')] : null,
            $filtros['tipo'] ? ['rotulo' => $tipos[$filtros['tipo']]->nome ?? $filtros['tipo'], 'url' => $removerFiltro('tipo', 'situacao')] : null,
            $filtros['setor'] ? ['rotulo' => $filtros['setor'] === 'publico' ? 'Setor público' : 'Setor privado', 'url' => $removerFiltro('setor')] : null,
            $filtros['status_estabelecimento'] === 'todos' ? ['rotulo' => 'Todos os cadastros', 'url' => $removerFiltro('status_estabelecimento')] : null,
            $filtros['situacao'] ? ['rotulo' => 'Situação: ' . ($situacoes[$filtros['situacao']]['label'] ?? ['alvara_doc_incompleta' => 'Com alvará e doc. incompleta', 'completa_favoravel' => 'Parecer favorável', 'completa_pendencia' => 'Parecer desfavorável / notificação', 'completa_sem_parecer' => 'Aguardando parecer'][$filtros['situacao']] ?? $filtros['situacao']), 'url' => $removerFiltro('situacao')] : null,
        ]));
        $campoSelect = 'w-full pl-9 pr-8 py-2 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition';
        $segmento = 'px-3 py-1.5 text-xs font-semibold rounded-lg cursor-pointer transition text-slate-600 hover:text-slate-900 has-[:checked]:bg-white has-[:checked]:text-blue-700 has-[:checked]:shadow-sm has-[:checked]:ring-1 has-[:checked]:ring-slate-200';
    @endphp
    <form method="GET" action="{{ route('admin.relatorios.estabelecimentos') }}" x-data
          @change="if ($event.target.name === 'busca') return;
                   if ($event.target.name === 'tipo') $el.querySelector('input[type=hidden][name=situacao]')?.remove();
                   $el.requestSubmit()"
          class="bg-white rounded-2xl border border-slate-200/80 shadow-sm overflow-hidden">
        @if($filtros['situacao'])<input type="hidden" name="situacao" value="{{ $filtros['situacao'] }}">@endif

        {{-- Linha 1: busca + processo exigido --}}
        <div class="flex flex-col lg:flex-row lg:items-center gap-3 p-4 border-b border-slate-100">
            <div class="relative flex-1 min-w-0">
                <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" name="busca" value="{{ $filtros['busca'] }}" placeholder="Buscar estabelecimento por nome ou CNPJ/CPF..."
                       class="w-full pl-9 pr-24 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition">
                <button type="submit" class="absolute right-1.5 top-1/2 -translate-y-1/2 px-3 py-1.5 text-xs font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition">
                    Buscar
                </button>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0 overflow-x-auto">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide whitespace-nowrap">Processo exigido</span>
                <div class="inline-flex p-1 bg-slate-100 rounded-xl">
                    <label class="{{ $segmento }}">
                        <input type="radio" name="tipo" value="" class="sr-only" @checked(!$filtros['tipo'])>Todos
                    </label>
                    @foreach($tipos as $codigo => $tipo)
                        <label class="{{ $segmento }} whitespace-nowrap">
                            <input type="radio" name="tipo" value="{{ $codigo }}" class="sr-only" @checked($filtros['tipo'] === $codigo)>{{ $tipo->nome }}
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Linha 2: demais filtros (aplicam ao mudar) --}}
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3 p-4 bg-slate-50/50">
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 mb-1">Ano de referência</label>
                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    <select name="ano" class="{{ $campoSelect }}">
                        @foreach($anos as $ano)
                            <option value="{{ $ano }}" @selected($filtros['ano'] === (int) $ano)>{{ $ano }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @if($usuarioLogado->isAdmin())
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 mb-1">Competência</label>
                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                    <select name="competencia" class="{{ $campoSelect }}">
                        <option value="">Todas</option>
                        <option value="estadual" @selected($filtros['competencia'] === 'estadual')>Estadual</option>
                        <option value="municipal" @selected($filtros['competencia'] === 'municipal')>Municipal</option>
                    </select>
                </div>
            </div>
            @endif
            @if($municipios->isNotEmpty())
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 mb-1">Município</label>
                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <select name="municipio_id" class="{{ $campoSelect }}">
                        <option value="">Todos</option>
                        @foreach($municipios as $municipio)
                            <option value="{{ $municipio->id }}" @selected($filtros['municipio_id'] === $municipio->id)>{{ $municipio->nome }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            @endif
            <div class="col-span-2 md:col-span-1">
                <label class="block text-[11px] font-semibold text-slate-500 mb-1">Setor</label>
                <div class="flex p-1 bg-slate-100 rounded-lg">
                    <label class="flex-1 text-center whitespace-nowrap {{ $segmento }}"><input type="radio" name="setor" value="" class="sr-only" @checked(!$filtros['setor'])>Todos</label>
                    <label class="flex-1 text-center whitespace-nowrap {{ $segmento }}"><input type="radio" name="setor" value="publico" class="sr-only" @checked($filtros['setor'] === 'publico')>Público</label>
                    <label class="flex-1 text-center whitespace-nowrap {{ $segmento }}"><input type="radio" name="setor" value="privado" class="sr-only" @checked($filtros['setor'] === 'privado')>Privado</label>
                </div>
            </div>
            <div>
                <label class="block text-[11px] font-semibold text-slate-500 mb-1">Cadastro</label>
                <div class="relative">
                    <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <select name="status_estabelecimento" class="{{ $campoSelect }}">
                        <option value="aprovado" @selected($filtros['status_estabelecimento'] === 'aprovado')>Aprovados e ativos</option>
                        <option value="todos" @selected($filtros['status_estabelecimento'] === 'todos')>Todos (exceto rejeitados)</option>
                    </select>
                </div>
            </div>
        </div>

        {{-- Filtros ativos --}}
        @if(count($filtrosAtivos) > 0)
        <div class="flex flex-wrap items-center gap-2 px-4 py-2.5 border-t border-slate-100">
            <span class="text-[11px] font-semibold text-slate-500">Filtrando por:</span>
            @foreach($filtrosAtivos as $ativo)
                <a href="{{ $ativo['url'] }}" title="Remover este filtro"
                   class="inline-flex items-center gap-1 pl-2.5 pr-1.5 py-0.5 text-xs font-medium text-blue-700 bg-blue-50 ring-1 ring-blue-200 rounded-full hover:bg-blue-100 transition">
                    {{ $ativo['rotulo'] }}
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </a>
            @endforeach
            <a href="{{ route('admin.relatorios.estabelecimentos') }}" class="ml-auto text-xs font-semibold text-slate-500 hover:text-red-600 transition">Limpar tudo</a>
        </div>
        @endif
    </form>

    @if($filtros['tipo'] && isset($tipos[$filtros['tipo']]))
        @php $corFoco = $coresTipo[$iconesTipo[$filtros['tipo']]['cor'] ?? 'blue']; @endphp
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 px-4 py-3 rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl {{ $corFoco['bg'] }} {{ $corFoco['text'] }} flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconesTipo[$filtros['tipo']]['icone'] ?? '' }}"/></svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-slate-900">Visualizando somente: {{ $tipos[$filtros['tipo']]->nome }}</p>
                    <p class="text-[11px] text-slate-500">Indicadores, gráficos, situação e processos ativos consideram apenas estabelecimentos que exigem este processo e somente processos deste tipo.</p>
                </div>
            </div>
            <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(request()->except(['page', 'tipo']))) }}"
               class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition whitespace-nowrap">
                Ver todos os tipos
            </a>
        </div>
    @endif

    @php
        $pct = fn ($parte, $todo) => $todo > 0 ? (int) round($parte * 100 / $todo) : 0;
        $tomCobertura = fn ($v) => $v === null ? 'slate' : ($v >= 80 ? 'emerald' : ($v >= 50 ? 'amber' : 'red'));
        $tons = [
            'slate' => ['num' => 'text-slate-400', 'barra' => 'bg-slate-300', 'chip' => 'bg-slate-100 text-slate-500'],
            'emerald' => ['num' => 'text-emerald-600', 'barra' => 'bg-emerald-500', 'chip' => 'bg-emerald-50 text-emerald-700'],
            'amber' => ['num' => 'text-amber-600', 'barra' => 'bg-amber-500', 'chip' => 'bg-amber-50 text-amber-700'],
            'red' => ['num' => 'text-red-600', 'barra' => 'bg-red-500', 'chip' => 'bg-red-50 text-red-700'],
        ];
        $tomGeral = $tons[$tomCobertura($indicadores['cobertura'])];
        $icone = fn ($d, $classe) => '<span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ' . $classe . '"><svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $d . '"/></svg></span>';
    @endphp

    {{-- Indicadores principais --}}
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
        {{-- Estabelecimentos --}}
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Estabelecimentos</p>
                {!! $icone('M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', 'bg-slate-100 text-slate-600') !!}
            </div>
            <p class="text-3xl font-bold text-slate-900 tabular-nums -mt-1">{{ $fmt($indicadores['total']) }}</p>
            <div class="mt-2.5 flex h-1.5 rounded-full overflow-hidden bg-slate-100" title="Públicos x privados">
                <div class="bg-indigo-500" style="width: {{ $pct($indicadores['publico'], $indicadores['total']) }}%"></div>
                <div class="bg-slate-400" style="width: {{ $pct($indicadores['privado'], $indicadores['total']) }}%"></div>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5">
                <span class="text-indigo-600 font-semibold">{{ $fmt($indicadores['publico']) }}</span> públicos ·
                <span class="text-slate-700 font-semibold">{{ $fmt($indicadores['privado']) }}</span> privados
            </p>
        </div>

        {{-- Segundo card: depende do processo escolhido --}}
        @if($tipoFoco === 'licenciamento')
        <a href="{{ $urlSituacao('com_alvara') }}" class="bg-gradient-to-br from-emerald-50 via-white to-white rounded-2xl border border-emerald-200 shadow-sm p-4 hover:ring-2 hover:ring-emerald-200 transition">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-emerald-700 uppercase tracking-wide">Com alvará sanitário</p>
                {!! $icone('M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z', 'bg-emerald-100 text-emerald-600') !!}
            </div>
            <p class="text-3xl font-bold text-emerald-600 tabular-nums -mt-1">{{ $fmt($indicadores['com_alvara']) }}</p>
            <p class="text-[11px] text-slate-600 mt-2">
                <span class="font-semibold text-emerald-700">{{ $fmt($indicadores['alvara_definitivo']) }}</span> definitivos
                @if($indicadores['alvara_nao_definitivo'])
                    · <span class="font-semibold text-sky-700">{{ $fmt($indicadores['alvara_nao_definitivo']) }}</span> provisórios
                @endif
            </p>
            @if($indicadores['media_dias_alvara'] !== null)
                <p class="mt-1.5 inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-emerald-100/70 text-[11px] font-semibold text-emerald-800">
                    ⏱ média de {{ $fmt($indicadores['media_dias_alvara']) }} {{ $indicadores['media_dias_alvara'] === 1 ? 'dia' : 'dias' }} até o definitivo
                </p>
            @endif
            @if(($indicadores['alvara_doc_incompleta'] ?? 0) > 0)
                <p class="mt-1.5 text-[11px] font-semibold text-amber-700">
                    ⚠ {{ $fmt($indicadores['alvara_doc_incompleta']) }} com documentação incompleta
                </p>
            @endif
        </a>
        @elseif($tipoFoco)
        <a href="{{ $urlSituacao('doc_completa') }}" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4 hover:ring-2 hover:ring-violet-200 transition">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Documentação completa</p>
                {!! $icone('M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z', 'bg-violet-100 text-violet-600') !!}
            </div>
            <p class="text-3xl font-bold text-violet-600 tabular-nums -mt-1">{{ $fmt($indicadores['doc_completa']) }}</p>
            <p class="text-[11px] text-slate-500 mt-2">{{ $fmt($indicadores['doc_incompleta']) }} com documentação incompleta</p>
        </a>
        @else
        <a href="{{ $urlSituacao('com_ativo') }}" class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4 hover:ring-2 hover:ring-blue-200 transition">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Com processo ativo</p>
                {!! $icone('M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'bg-blue-100 text-blue-600') !!}
            </div>
            <p class="text-3xl font-bold text-blue-600 tabular-nums -mt-1">{{ $fmt($indicadores['com_ativo']) }}</p>
            <p class="text-[11px] text-slate-500 mt-2">{{ $fmt($indicadores['sem_ativo']) }} sem nenhum processo ativo</p>
        </a>
        @endif

        {{-- Precisam abrir --}}
        <div class="bg-gradient-to-br from-red-50 via-white to-white rounded-2xl border border-red-200 shadow-sm p-4 hover:ring-2 hover:ring-red-200 transition">
            <a href="{{ $urlPendenteSetor(null) }}" class="block">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-[11px] font-semibold text-red-700 uppercase tracking-wide">Precisam abrir processo</p>
                    {!! $icone('M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z', 'bg-red-100 text-red-600') !!}
                </div>
                <p class="text-3xl font-bold text-red-600 tabular-nums -mt-1">{{ $fmt($indicadores['pendentes']) }}</p>
            </a>
            <div class="flex flex-wrap gap-1.5 mt-2">
                <a href="{{ $urlPendenteSetor('publico') }}"
                   class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold ring-1 transition {{ $filtros['situacao'] === 'pendente' && $filtros['setor'] === 'publico' ? 'bg-indigo-600 text-white ring-indigo-600' : 'bg-indigo-50 text-indigo-700 ring-indigo-200 hover:bg-indigo-100' }}">
                    🏛️ <span class="tabular-nums">{{ $fmt($indicadores['pendentes_publico']) }}</span> públicos
                </a>
                <a href="{{ $urlPendenteSetor('privado') }}"
                   class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-semibold ring-1 transition {{ $filtros['situacao'] === 'pendente' && $filtros['setor'] === 'privado' ? 'bg-slate-700 text-white ring-slate-700' : 'bg-white text-slate-700 ring-slate-200 hover:bg-slate-50' }}">
                    🏢 <span class="tabular-nums">{{ $fmt($indicadores['pendentes_privado']) }}</span> privados
                </a>
            </div>
        </div>

        {{-- Cobertura --}}
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Cobertura</p>
                {!! $icone('M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', $tomGeral['chip']) !!}
            </div>
            <p class="text-3xl font-bold tabular-nums -mt-1 {{ $tomGeral['num'] }}">{{ $indicadores['cobertura'] !== null ? $indicadores['cobertura'] . '%' : '—' }}</p>
            <div class="mt-2.5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                <div class="h-full rounded-full {{ $tomGeral['barra'] }}" style="width: {{ $indicadores['cobertura'] ?? 0 }}%"></div>
            </div>
            <p class="text-[11px] text-slate-500 mt-1.5">{{ $fmt($indicadores['em_dia']) }} de {{ $fmt($indicadores['em_dia'] + $indicadores['pendentes']) }} já abriram</p>
        </div>

        {{-- Processos ativos --}}
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4">
            <div class="flex items-start justify-between gap-2">
                <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide">Processos ativos</p>
                {!! $icone('M13 10V3L4 14h7v7l9-11h-7z', 'bg-sky-100 text-sky-600') !!}
            </div>
            <p class="text-3xl font-bold text-slate-900 tabular-nums -mt-1">{{ $fmt($indicadores['processos_ativos']) }}</p>
            @if($indicadores['processos_parados'])
                <p class="mt-2 inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-red-50 text-[11px] font-semibold text-red-700">
                    ⏸ {{ $fmt($indicadores['processos_parados']) }} {{ $indicadores['processos_parados'] === 1 ? 'parado' : 'parados' }}
                </p>
            @else
                <p class="text-[11px] text-slate-500 mt-2">nenhum parado</p>
            @endif
        </div>
    </div>

    {{-- Cobertura por tipo de processo --}}
    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach($indicadores['por_tipo'] as $codigo => $item)
            @continue($codigo === 'licenciamento')
            @php
                $cor = $coresTipo[$iconesTipo[$codigo]['cor'] ?? 'blue'];
                $tom = $tons[$tomCobertura($item['cobertura'])];
                $selecionado = $tipoFoco === $codigo;
            @endphp
            <div class="bg-white rounded-2xl border shadow-sm p-4 flex flex-col transition {{ $selecionado ? 'border-blue-300 ring-2 ring-blue-100' : 'border-slate-200/80' }}">
                <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['tipo' => $codigo] + request()->except(['page', 'situacao', 'tipo']))) }}"
                   class="flex items-center justify-between gap-3 group">
                    <span class="flex items-center gap-2.5 min-w-0">
                        <span class="w-10 h-10 rounded-xl {{ $cor['bg'] }} {{ $cor['text'] }} flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $iconesTipo[$codigo]['icone'] ?? '' }}"/></svg>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-slate-900 truncate group-hover:text-blue-700">{{ $item['nome'] }}</span>
                            <span class="block text-[11px] text-slate-500">{{ $item['anual'] ? 'Anual · ' . $indicadores['ano'] : 'Processo único' }}</span>
                        </span>
                    </span>
                    <span class="text-2xl font-bold tabular-nums {{ $tom['num'] }}">{{ $item['cobertura'] !== null ? $item['cobertura'] . '%' : '—' }}</span>
                </a>

                @if($item['exigem'] === 0)
                    <p class="mt-4 text-xs text-slate-400">Nenhum estabelecimento exige este processo.</p>
                @else
                    {{-- Barra: abriram x não abriram --}}
                    <div class="mt-4 flex h-2.5 rounded-full overflow-hidden bg-slate-100" title="{{ $fmt($item['atendidos']) }} abriram · {{ $fmt($item['pendentes']) }} não abriram">
                        <div class="bg-emerald-500" style="width: {{ $pct($item['atendidos'], $item['exigem']) }}%"></div>
                        <div class="bg-red-400" style="width: {{ $pct($item['pendentes'], $item['exigem']) }}%"></div>
                    </div>

                    <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-lg bg-slate-50 py-1.5">
                            <p class="text-base font-bold text-slate-900 tabular-nums">{{ $fmt($item['exigem']) }}</p>
                            <p class="text-[10px] text-slate-500 uppercase tracking-wide">precisam</p>
                        </div>
                        <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['tipo' => $codigo, 'situacao' => 'em_dia'] + request()->except(['page', 'situacao', 'tipo']))) }}"
                           class="rounded-lg bg-emerald-50 py-1.5 hover:bg-emerald-100 transition">
                            <p class="text-base font-bold text-emerald-700 tabular-nums">{{ $fmt($item['atendidos']) }}</p>
                            <p class="text-[10px] text-emerald-700 uppercase tracking-wide">abriram</p>
                        </a>
                        <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['tipo' => $codigo, 'situacao' => 'pendente'] + request()->except(['page', 'situacao', 'tipo']))) }}"
                           class="rounded-lg py-1.5 transition {{ $item['pendentes'] ? 'bg-red-50 hover:bg-red-100' : 'bg-slate-50' }}">
                            <p class="text-base font-bold tabular-nums {{ $item['pendentes'] ? 'text-red-700' : 'text-slate-400' }}">{{ $fmt($item['pendentes']) }}</p>
                            <p class="text-[10px] uppercase tracking-wide {{ $item['pendentes'] ? 'text-red-700' : 'text-slate-400' }}">não abriram</p>
                        </a>
                    </div>

                    @if($item['pendentes'])
                    <div class="mt-2.5 flex items-center gap-1.5 text-[11px]">
                        <span class="text-slate-400">Faltam:</span>
                        <a href="{{ $urlPendenteSetor('publico', $codigo) }}" class="px-2 py-0.5 rounded-md font-semibold {{ $item['pendentes_publico'] ? 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' : 'bg-slate-50 text-slate-400' }}">
                            🏛️ {{ $fmt($item['pendentes_publico']) }} públicos
                        </a>
                        <a href="{{ $urlPendenteSetor('privado', $codigo) }}" class="px-2 py-0.5 rounded-md font-semibold {{ $item['pendentes_privado'] ? 'bg-slate-100 text-slate-700 hover:bg-slate-200' : 'bg-slate-50 text-slate-400' }}">
                            🏢 {{ $fmt($item['pendentes_privado']) }} privados
                        </a>
                    </div>
                    @else
                    <p class="mt-2.5 text-[11px] font-semibold text-emerald-600">✓ Todos que precisam já abriram</p>
                    @endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- Cadastros sem atividade marcada: não entram no cálculo, mas ficam visíveis para correção --}}
    @if($totalSemAtividade > 0 && $filtros['situacao'] !== 'sem_atividade')
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3 rounded-2xl bg-amber-50 border border-amber-200">
            <span class="w-9 h-9 rounded-xl bg-amber-100 text-amber-600 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <p class="text-sm text-amber-900 flex-1">
                <strong>{{ $fmt($totalSemAtividade) }} {{ $totalSemAtividade === 1 ? 'cadastro não tem' : 'cadastros não têm' }} nenhuma atividade marcada</strong>
                e por isso não {{ $totalSemAtividade === 1 ? 'entra' : 'entram' }} nas contas de processos exigidos. Marque as atividades para que {{ $totalSemAtividade === 1 ? 'ele seja contado' : 'sejam contados' }}.
            </p>
            <a href="{{ route('admin.relatorios.estabelecimentos', array_filter(['situacao' => 'sem_atividade'] + request()->except(['page', 'situacao', 'tipo']))) }}"
               class="self-start sm:self-auto px-3 py-1.5 text-xs font-semibold text-amber-800 bg-white ring-1 ring-amber-300 rounded-lg hover:bg-amber-100 whitespace-nowrap">
                Ver quais são
            </a>
        </div>
    @endif

    {{-- Gráficos --}}
    @php
        $cabecalhoGrafico = fn ($icone, $classe, $titulo, $subtitulo) =>
            '<div class="flex items-start gap-3 mb-4">'
            . '<span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 ' . $classe . '"><svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $icone . '"/></svg></span>'
            . '<div><h3 class="text-sm font-semibold text-slate-900">' . e($titulo) . '</h3><p class="text-[11px] text-slate-500">' . e($subtitulo) . '</p></div></div>';
    @endphp
    <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="xl:col-span-2 bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z', 'bg-blue-50 text-blue-600', 'Processos abertos por mês · ' . $indicadores['ano'], 'Pela competência do estabelecimento') !!}
            <div class="h-64"><canvas id="chartAberturas"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3', 'bg-emerald-50 text-emerald-600', 'Estadual x municipal', 'Em dia, pendentes e sem exigência') !!}
            <div class="h-64"><canvas id="chartCompetencia"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z', 'bg-violet-50 text-violet-600', 'Processos ativos por tipo', $fmt($indicadores['processos_ativos']) . ' em tramitação') !!}
            <div class="h-60 relative">
                @if($graficos['ativos_por_tipo']->isEmpty())
                    <div class="absolute inset-0 flex items-center justify-center text-xs text-slate-400">Nenhum processo ativo</div>
                @else
                    <canvas id="chartTipos"></canvas>
                @endif
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'bg-amber-50 text-amber-600', 'Há quanto tempo estão abertos', 'Idade dos processos ativos') !!}
            <div class="h-60"><canvas id="chartIdade"></canvas></div>
        </div>
        @if($graficos['top_municipios']->isNotEmpty())
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z', 'bg-red-50 text-red-600', 'Municípios com mais pendências', 'Estabelecimentos que precisam abrir processo') !!}
            <div class="h-60"><canvas id="chartMunicipios"></canvas></div>
        </div>
        @else
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-sm p-5">
            {!! $cabecalhoGrafico('M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z', 'bg-slate-100 text-slate-600', 'Abertura por tipo exigido', 'Abriram x não abriram') !!}
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
                    @php
                        $ativa = ($filtros['situacao'] ?? null) === ($chave ?: null)
                            || ($chave === 'doc_completa' && str_starts_with((string) ($filtros['situacao'] ?? ''), 'completa_'));
                    @endphp
                    <a href="{{ $urlSituacao($chave ?: null) }}"
                       class="whitespace-nowrap inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold border-b-2 transition {{ $ativa ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-800' }}">
                        {{ $sit['label'] }}
                        <span class="px-1.5 py-0.5 rounded-md text-[10px] tabular-nums {{ $ativa ? 'bg-blue-100 text-blue-700' : ($chave === 'pendente' && $sit['total'] ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600') }}">{{ $fmt($sit['total']) }}</span>
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Explica a diferença para a tela de Processos ("Incompletos" lá inclui quem já tem alvará) --}}
        @if($tipoFoco === 'licenciamento' && ($indicadores['alvara_doc_incompleta'] ?? 0) > 0)
            @php $ativaAlvaraIncompleta = ($filtros['situacao'] ?? null) === 'alvara_doc_incompleta'; @endphp
            <div class="px-5 py-2.5 border-b border-amber-100 bg-amber-50/70 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <p class="text-[11px] text-amber-900 leading-relaxed">
                    <strong>{{ $fmt($indicadores['alvara_doc_incompleta']) }} {{ $indicadores['alvara_doc_incompleta'] === 1 ? 'estabelecimento já tem' : 'estabelecimentos já têm' }} alvará sanitário, mas com documentação obrigatória incompleta.</strong>
                    Aqui {{ $indicadores['alvara_doc_incompleta'] === 1 ? 'ele conta' : 'eles contam' }} em "Com alvará sanitário"; na tela de Processos, aparece{{ $indicadores['alvara_doc_incompleta'] === 1 ? '' : 'm' }} também em "Incompletos".
                </p>
                <a href="{{ $urlSituacao('alvara_doc_incompleta') }}"
                   class="self-start sm:self-auto whitespace-nowrap inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-semibold ring-1 ring-inset transition {{ $ativaAlvaraIncompleta ? 'bg-amber-600 text-white ring-amber-600' : 'bg-white text-amber-800 ring-amber-300 hover:bg-amber-100' }}">
                    Ver esses {{ $fmt($indicadores['alvara_doc_incompleta']) }}
                </a>
            </div>
        @endif

        @if($tipoFoco === 'licenciamento')
            @php
                $subParecer = [
                    'completa_favoravel' => ['label' => 'Parecer favorável', 'total' => $indicadores['completa_favoravel'], 'cor' => 'emerald', 'dica' => 'Último parecer favorável, sem notificação em aberto'],
                    'completa_pendencia' => ['label' => 'Parecer desfavorável / notificação em prazo', 'total' => $indicadores['completa_pendencia'], 'cor' => 'red', 'dica' => 'Último parecer desfavorável ou notificação com prazo em aberto'],
                    'completa_sem_parecer' => ['label' => 'Aguardando parecer', 'total' => $indicadores['completa_sem_parecer'], 'cor' => 'slate', 'dica' => 'Documentação completa, ainda sem parecer'],
                ];
                $corChip = [
                    'emerald' => ['on' => 'bg-emerald-600 text-white ring-emerald-600', 'off' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 hover:bg-emerald-100'],
                    'red' => ['on' => 'bg-red-600 text-white ring-red-600', 'off' => 'bg-red-50 text-red-700 ring-red-200 hover:bg-red-100'],
                    'slate' => ['on' => 'bg-slate-700 text-white ring-slate-700', 'off' => 'bg-slate-50 text-slate-600 ring-slate-200 hover:bg-slate-100'],
                ];
            @endphp
            <div class="px-5 py-2.5 border-b border-slate-100 bg-slate-50/60 flex flex-wrap items-center gap-2">
                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wide mr-1">Doc. completa sem alvará:</span>
                @foreach($subParecer as $chave => $sub)
                    @php $ativaSub = ($filtros['situacao'] ?? null) === $chave; @endphp
                    <a href="{{ $urlSituacao($chave) }}" title="{{ $sub['dica'] }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold ring-1 ring-inset transition {{ $ativaSub ? $corChip[$sub['cor']]['on'] : $corChip[$sub['cor']]['off'] }}">
                        {{ $sub['label'] }}
                        <span class="px-1.5 rounded-full text-[10px] tabular-nums {{ $ativaSub ? 'bg-white/20' : 'bg-white' }}">{{ $fmt($sub['total']) }}</span>
                    </a>
                @endforeach
            </div>
        @endif

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
                                <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold {{ $linha['setor'] === 'publico' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $linha['setor'] === 'publico' ? 'Público' : 'Privado' }}
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
                                            @if(!empty($demanda['arquivado']))
                                                <a href="{{ route('admin.estabelecimentos.processos.show', [$e->id, $demanda['arquivado']->id]) }}"
                                                   title="Processo arquivado sem alvará sanitário: não conta como aberto"
                                                   class="self-start text-[10px] text-slate-500 hover:text-slate-800 underline decoration-dotted underline-offset-2">
                                                    {{ $demanda['arquivado']->numero_processo }} arquivado (desconsiderado)
                                                </a>
                                            @endif
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
                            @if(isset($linha['etapa']))
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset whitespace-nowrap {{ $etapaClasse[$linha['etapa']] ?? $sitClasse }}">{{ isset($linha['sub_etapa']) ? 'Doc. completa · sem alvará' : $linha['etapa_label'] }}</span>
                            @if(($linha['etapa'] ?? null) === 'com_alvara')
                                @if($linha['alvara_doc_incompleta'] ?? false)
                                    <p class="mt-1 text-[11px] font-semibold text-amber-700" title="Algum documento obrigatório do processo não está aprovado">⚠ Doc. obrigatória incompleta</p>
                                @endif
                                @if($linha['alvara_definitivo'] ?? false)
                                    <p class="mt-1 text-[11px] font-semibold text-emerald-700">
                                        ⏱ {{ $fmt($linha['dias_ate_alvara']) }} {{ $linha['dias_ate_alvara'] === 1 ? 'dia' : 'dias' }} até o definitivo
                                    </p>
                                @else
                                    <p class="mt-1 text-[11px] font-semibold text-sky-700">📄 Só alvará provisório</p>
                                @endif
                            @endif
                            @isset($linha['sub_etapa'])
                                <p class="mt-1 text-[11px] font-semibold {{ ['completa_favoravel' => 'text-emerald-700', 'completa_pendencia' => 'text-red-700', 'completa_sem_parecer' => 'text-slate-500'][$linha['sub_etapa']] ?? 'text-slate-500' }}">
                                    {{ $linha['sub_etapa_label'] }}
                                </p>
                            @endisset
                            @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset {{ $sitClasse }}">{{ $linha['situacao_label'] }}</span>
                            @endif
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
        Processo <strong>arquivado sem Alvará Sanitário</strong> não conta como aberto (o estabelecimento aparece em "Não abriram");
        arquivado com alvará conta como licenciamento concluído.
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
    Chart.defaults.plugins.legend.labels.pointStyle = 'circle';
    Chart.defaults.plugins.legend.labels.boxWidth = 7;
    Chart.defaults.plugins.legend.labels.padding = 14;
    Object.assign(Chart.defaults.plugins.tooltip, {
        backgroundColor: '#0f172a', titleColor: '#fff', bodyColor: '#e2e8f0',
        padding: 10, cornerRadius: 8, boxPadding: 4, usePointStyle: true,
    });
    Chart.defaults.animation.duration = 500;

    const cores = { estadual: '#3b82f6', municipal: '#10b981', emDia: '#10b981', pendente: '#f87171', neutro: '#e2e8f0' };
    const grid = { color: '#f1f5f9', drawTicks: false };
    const semBorda = { display: false };
    const graficos = @json($graficos);
    const el = id => document.getElementById(id);
    const soma = arr => arr.reduce((a, b) => a + (Number(b) || 0), 0);

    // Total no centro da rosca
    const totalNoCentro = {
        id: 'totalNoCentro',
        afterDraw(chart) {
            if (chart.config.type !== 'doughnut') return;
            const { ctx, chartArea: { left, right, top, bottom } } = chart;
            const x = (left + right) / 2, y = (top + bottom) / 2;
            const total = soma(chart.data.datasets[0].data);
            ctx.save();
            ctx.textAlign = 'center';
            ctx.fillStyle = '#0f172a';
            ctx.font = "700 22px 'Inter', system-ui, sans-serif";
            ctx.fillText(total.toLocaleString('pt-BR'), x, y + 4);
            ctx.fillStyle = '#94a3b8';
            ctx.font = "500 10px 'Inter', system-ui, sans-serif";
            ctx.fillText('ATIVOS', x, y + 20);
            ctx.restore();
        }
    };

    if (el('chartAberturas')) {
        new Chart(el('chartAberturas'), {
            type: 'bar',
            data: {
                labels: graficos.meses,
                datasets: [
                    { label: 'Estadual', data: graficos.aberturas.estadual, backgroundColor: cores.estadual, borderRadius: 6, maxBarThickness: 26 },
                    { label: 'Municipal', data: graficos.aberturas.municipal, backgroundColor: cores.municipal, borderRadius: 6, maxBarThickness: 26 },
                ]
            },
            options: {
                maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                scales: {
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid, border: semBorda },
                    x: { stacked: true, grid: { display: false }, border: semBorda },
                },
                plugins: {
                    legend: { position: 'top', align: 'end' },
                    tooltip: { callbacks: { footer: itens => 'Total: ' + soma(itens.map(i => i.parsed.y)) } },
                },
            }
        });
    }

    if (el('chartCompetencia')) {
        const c = graficos.por_competencia;
        new Chart(el('chartCompetencia'), {
            type: 'bar',
            data: {
                labels: ['Estadual', 'Municipal'],
                datasets: [
                    { label: 'Em dia', data: [c.estadual.em_dia, c.municipal.em_dia], backgroundColor: cores.emDia, borderRadius: 6 },
                    { label: 'Pendentes', data: [c.estadual.pendente, c.municipal.pendente], backgroundColor: cores.pendente, borderRadius: 6 },
                    { label: 'Sem exigência', data: [c.estadual.sem_exigencia, c.municipal.sem_exigencia], backgroundColor: cores.neutro, borderRadius: 6 },
                ]
            },
            options: {
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, grid: { display: false }, border: semBorda },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid, border: semBorda },
                },
                plugins: { legend: { position: 'bottom' } },
            }
        });
    }

    if (el('chartTipos')) {
        const tipos = graficos.ativos_por_tipo;
        new Chart(el('chartTipos'), {
            type: 'doughnut',
            data: { labels: Object.keys(tipos), datasets: [{ data: Object.values(tipos),
                backgroundColor: ['#3b82f6', '#8b5cf6', '#f59e0b', '#10b981', '#ec4899', '#64748b', '#06b6d4'],
                borderWidth: 3, borderColor: '#fff', hoverOffset: 6 }] },
            options: { maintainAspectRatio: false, cutout: '68%', plugins: { legend: { position: 'bottom' } } },
            plugins: [totalNoCentro],
        });
    }

    if (el('chartIdade')) {
        const idade = graficos.idade_ativos;
        new Chart(el('chartIdade'), {
            type: 'bar',
            data: { labels: Object.keys(idade), datasets: [{ label: 'Processos', data: Object.values(idade),
                backgroundColor: ['#34d399', '#fbbf24', '#fb923c', '#f87171'], borderRadius: 8, maxBarThickness: 46 }] },
            options: {
                maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, padding: 8 }, grid, border: semBorda },
                    x: { grid: { display: false }, border: semBorda },
                },
            }
        });
    }

    if (el('chartMunicipios')) {
        const m = graficos.top_municipios;
        new Chart(el('chartMunicipios'), {
            type: 'bar',
            data: { labels: Object.keys(m), datasets: [{ label: 'Pendentes', data: Object.values(m), backgroundColor: '#f87171', borderRadius: 6, maxBarThickness: 16 }] },
            options: {
                indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, ticks: { precision: 0 }, grid, border: semBorda },
                    y: { grid: { display: false }, border: semBorda, ticks: { color: '#334155' } },
                },
            }
        });
    }

    if (el('chartCobertura')) {
        const t = graficos.cobertura_tipos;
        new Chart(el('chartCobertura'), {
            type: 'bar',
            data: { labels: t.map(i => i.nome), datasets: [
                { label: 'Abriram', data: t.map(i => i.atendidos), backgroundColor: cores.emDia, borderRadius: 6 },
                { label: 'Não abriram', data: t.map(i => i.pendentes), backgroundColor: cores.pendente, borderRadius: 6 },
            ] },
            options: {
                indexAxis: 'y', maintainAspectRatio: false,
                scales: {
                    x: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, grid, border: semBorda },
                    y: { stacked: true, grid: { display: false }, border: semBorda, ticks: { color: '#334155' } },
                },
                plugins: { legend: { position: 'bottom' } },
            }
        });
    }
})();
</script>
@endpush
