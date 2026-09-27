@extends('layouts.admin')

@section('title', 'Relatórios')

@section('content')
@php
    $usuarioLogado = auth('interno')->user();
    $icones = [
        'predio' => 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M8 7h8M8 11h8M8 15h5',
        'lampada' => 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z',
        'documento' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
        'grafico' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
        'pasta' => 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z',
        'checklist' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
        'equipe' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z',
        'estrela' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z',
    ];
    $cores = [
        'cyan' => 'bg-cyan-50 text-cyan-600 group-hover:bg-cyan-100',
        'orange' => 'bg-orange-50 text-orange-600 group-hover:bg-orange-100',
        'blue' => 'bg-blue-50 text-blue-600 group-hover:bg-blue-100',
        'green' => 'bg-green-50 text-green-600 group-hover:bg-green-100',
        'purple' => 'bg-purple-50 text-purple-600 group-hover:bg-purple-100',
        'indigo' => 'bg-indigo-50 text-indigo-600 group-hover:bg-indigo-100',
        'emerald' => 'bg-emerald-50 text-emerald-600 group-hover:bg-emerald-100',
    ];

    $categorias = [
        'Estabelecimentos' => [
            ['rota' => 'admin.relatorios.estabelecimentos-cnae', 'titulo' => 'Estabelecimentos por CNAE', 'descricao' => 'Quantos estabelecimentos existem por atividade, com escopo automático por perfil.', 'icone' => 'predio', 'cor' => 'cyan'],
            ['rota' => 'admin.relatorios.equipamentos-radiacao', 'titulo' => 'Equipamentos de Imagem', 'descricao' => 'Situação do cadastro de equipamentos de radiação por estabelecimento.', 'icone' => 'lampada', 'cor' => 'orange'],
        ],
        'Processos e fiscalização' => [
            ['rota' => 'admin.relatorios.processos', 'titulo' => 'Processos', 'descricao' => 'Processos por tipo, status, município, período e técnico responsável.', 'icone' => 'pasta', 'cor' => 'green'],
            ['rota' => 'admin.relatorios.acoes-atividade', 'titulo' => 'Ações e Estabelecimentos por Atividade', 'descricao' => 'Ordens de serviço por ação, município, região de saúde, competência e técnico.', 'icone' => 'checklist', 'cor' => 'purple'],
        ],
        'Documentos' => [
            ['rota' => 'admin.relatorios.documentos-gerados', 'titulo' => 'Documentos Gerados', 'descricao' => 'Listagem completa de documentos com filtros por período e status.', 'icone' => 'documento', 'cor' => 'blue'],
        ],
        'Equipe e qualidade' => array_values(array_filter([
            ['rota' => 'admin.relatorios.usuarios', 'titulo' => 'Pendências por Usuário', 'descricao' => 'Assinaturas, processos, respostas e OS pendentes por técnico ou gestor.', 'icone' => 'equipe', 'cor' => 'indigo'],
            in_array($usuarioLogado->nivel_acesso->value, ['administrador', 'gestor_estadual'])
                ? ['rota' => 'admin.relatorios.pesquisa-satisfacao', 'titulo' => 'Pesquisa de Satisfação', 'descricao' => 'Gráficos e análise das respostas de cada pesquisa.', 'icone' => 'estrela', 'cor' => 'emerald']
                : null,
        ])),
    ];
@endphp

<div class="space-y-6" x-data="{ busca: '' }">
    {{-- Cabeçalho --}}
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">Relatórios</h1>
            <p class="text-sm text-slate-500 mt-1">Indicadores e listagens para a vigilância acompanhar estabelecimentos, processos e equipe</p>
        </div>
        <div class="relative w-full md:w-72">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" x-model="busca" placeholder="Buscar relatório..."
                   class="w-full pl-9 pr-3 py-2 text-sm bg-white border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>
    </div>

    {{-- Destaque: Controle de Estabelecimentos e Processos --}}
    <a href="{{ route('admin.relatorios.estabelecimentos') }}"
       x-show="busca === '' || 'controle estabelecimentos processos licenciamento projeto arquitetonico rotulagem pendentes graficos'.includes(busca.toLowerCase())"
       class="group relative block overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-violet-600 p-6 text-white shadow-lg shadow-indigo-500/20 hover:shadow-xl hover:shadow-indigo-500/30 transition">
        <div class="absolute -right-10 -top-10 w-48 h-48 rounded-full bg-white/10"></div>
        <div class="absolute right-20 -bottom-16 w-40 h-40 rounded-full bg-white/5"></div>
        <div class="relative flex flex-col md:flex-row md:items-center gap-5">
            <div class="w-14 h-14 rounded-2xl bg-white/15 ring-1 ring-white/25 flex items-center justify-center flex-shrink-0">
                <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icones['grafico'] }}"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                    <span class="px-2 py-0.5 rounded-full bg-white/20 text-[10px] font-bold uppercase tracking-wider">Novo</span>
                    <span class="text-xs text-white/70">Estabelecimentos</span>
                </div>
                <h2 class="text-lg font-bold">Controle de Estabelecimentos e Processos</h2>
                <p class="text-sm text-white/80 mt-1 max-w-2xl">
                    Veja quem já abriu processo e quem ainda não abriu: licenciamento do ano, projeto arquitetônico e análise de rotulagem,
                    com gráficos para as competências estadual e municipal.
                </p>
            </div>
            <span class="inline-flex items-center gap-1.5 self-start md:self-center px-4 py-2 rounded-xl bg-white text-indigo-700 text-sm font-semibold group-hover:gap-2.5 transition-all">
                Abrir relatório
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
            </span>
        </div>
    </a>

    {{-- Categorias --}}
    @foreach($categorias as $categoria => $relatorios)
        @continue(empty($relatorios))
        @php
            $termos = collect($relatorios)->map(fn ($r) => mb_strtolower($r['titulo'] . ' ' . $r['descricao'] . ' ' . $categoria))->all();
        @endphp
        <section x-show="busca === '' || {{ json_encode($termos) }}.some(t => t.includes(busca.toLowerCase()))">
            <h2 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2.5 px-1">{{ $categoria }}</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($relatorios as $relatorio)
                    <a href="{{ route($relatorio['rota']) }}"
                       x-show="busca === '' || {{ json_encode(mb_strtolower($relatorio['titulo'] . ' ' . $relatorio['descricao'] . ' ' . $categoria)) }}.includes(busca.toLowerCase())"
                       class="group flex items-start gap-3.5 bg-white rounded-2xl border border-slate-200/80 shadow-sm p-4 hover:shadow-md hover:border-slate-300 hover:-translate-y-0.5 transition-all">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 transition-colors {{ $cores[$relatorio['cor']] }}">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $icones[$relatorio['icone']] }}"/></svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-slate-900 group-hover:text-blue-700 transition-colors">{{ $relatorio['titulo'] }}</h3>
                            <p class="text-xs text-slate-500 mt-1 leading-relaxed">{{ $relatorio['descricao'] }}</p>
                        </div>
                        <svg class="w-4 h-4 mt-0.5 text-slate-300 group-hover:text-blue-600 group-hover:translate-x-0.5 transition flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
@endsection
