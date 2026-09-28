<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - InfoVISA Admin</title>
    
    {{-- URL base para chamadas JavaScript --}}
    <script>
        window.APP_URL = '{{ url('/') }}';
    </script>
    
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }
        .sidebar-expanded { width: 232px; }
        .sidebar-collapsed { width: 68px; }
        @media (max-width: 1023px) {
            .sidebar-expanded, .sidebar-collapsed { width: 232px; }
        }
        /* Scrollbar discreta da navegação lateral */
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, .25); border-radius: 9999px; }
        .sidebar-nav:hover::-webkit-scrollbar-thumb { background: rgba(148, 163, 184, .45); }
        .sidebar-nav { scrollbar-width: thin; scrollbar-color: rgba(148, 163, 184, .3) transparent; }
    </style>
    
    {{-- Script inline para aplicar estado do sidebar ANTES do render --}}
    <script>
        (function() {
            // Aplica classe CSS baseada no localStorage ANTES do Alpine carregar
            const stored = localStorage.getItem('sidebarExpanded');
            const isExpanded = stored === null ? true : stored === 'true';
            
            // Salva no window para o Alpine usar
            window.__sidebarExpanded = isExpanded;
            
            // Injeta CSS dinâmico para definir estado inicial correto SEM transição
            const style = document.createElement('style');
            style.id = 'sidebar-initial-state';
            style.textContent = `
                /* Remove transições durante carregamento inicial */
                aside.fixed {
                    width: ${isExpanded ? '232px' : '68px'} !important;
                    transition: none !important;
                }
                /* Controla visibilidade dos botões de toggle ANTES do Alpine */
                .sidebar-toggle-collapse { display: ${isExpanded ? 'flex' : 'none'} !important; }
                .sidebar-toggle-expand { display: ${isExpanded ? 'none' : 'flex'} !important; }
                /* Esconde todos os textos/labels do sidebar quando colapsado */
                aside.fixed span[x-show="showLabels()"],
                aside.fixed div[x-show="showLabels()"],
                aside.fixed p[x-show="showLabels()"] { 
                    display: ${isExpanded ? '' : 'none'} !important; 
                }
                /* Esconde elementos com x-cloak até Alpine estar pronto */
                [x-cloak] { display: none !important; }
                @media (max-width: 1023px) {
                    aside.fixed { width: 232px !important; }
                    .sidebar-toggle-collapse { display: flex !important; }
                    .sidebar-toggle-expand { display: none !important; }
                    aside.fixed span[x-show="showLabels()"],
                    aside.fixed div[x-show="showLabels()"],
                    aside.fixed p[x-show="showLabels()"] { 
                        display: inline !important; 
                    }
                }
            `;
            document.head.appendChild(style);
            
            // Remove o estilo após Alpine inicializar e aplicar seu estado
            document.addEventListener('alpine:initialized', function() {
                // Aguarda 2 frames para garantir que Alpine aplicou tudo
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        const initialStyle = document.getElementById('sidebar-initial-state');
                        if (initialStyle) initialStyle.remove();
                    });
                });
            });
        })();
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-DTOQO9RWCH3ppGqcWaEA1BIZOC6xxalwEsw9c2QQeAIftl+Vegovlnee1c9QX4TctnWMn13TZye+giMm8e2LwA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    @stack('styles')
</head>
<body class="bg-slate-50 font-sans antialiased text-slate-800" x-data="sidebarState()" x-init="init()">

    <script>
        // Define o estado do sidebar usando o valor pré-calculado
        function sidebarState() {
            // Usa o valor já calculado pelo script inline no <head>
            const initialExpanded = window.__sidebarExpanded !== undefined
                ? window.__sidebarExpanded
                : (localStorage.getItem('sidebarExpanded') !== 'false');

            return {
                sidebarOpen: false,
                sidebarExpanded: initialExpanded,
                userMenuOpen: false,
                helpMenuOpen: false,
                isMobile: window.innerWidth < 1024,

                init() {
                    // Listener para resize
                    window.addEventListener('resize', () => {
                        this.isMobile = window.innerWidth < 1024;
                    });
                },

                toggleSidebar() {
                    this.sidebarExpanded = !this.sidebarExpanded;
                    localStorage.setItem('sidebarExpanded', this.sidebarExpanded.toString());
                },

                showLabels() {
                    return this.isMobile || this.sidebarExpanded;
                }
            };
        }
    </script>

    @php
        $usuarioMenu = auth('interno')->user();
        $nivelMenu = $usuarioMenu->nivel_acesso->value;
        $partesNomeMenu = preg_split('/\s+/', trim($usuarioMenu->nome));
        $iniciaisMenu = mb_strtoupper(mb_substr($partesNomeMenu[0] ?? '', 0, 1) . (count($partesNomeMenu) > 1 ? mb_substr(end($partesNomeMenu), 0, 1) : ''));

        // Seções do menu lateral (as regras de visibilidade são as mesmas de antes)
        $menuSections = [
            [
                'label' => 'Principal',
                'items' => [
                    ['route' => 'admin.dashboard', 'check' => 'admin.dashboard', 'label' => 'Dashboard', 'show' => true,
                     'icon' => ['M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6']],
                    ['route' => 'admin.estabelecimentos.index', 'check' => 'admin.estabelecimentos.*', 'label' => 'Estabelecimentos', 'show' => true,
                     'icon' => ['M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4']],
                    ['route' => 'admin.processos.index-geral', 'check' => 'admin.processos.*', 'label' => 'Processos', 'show' => true,
                     'icon' => ['M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z']],
                    ['route' => 'admin.alertas-processos.index', 'check' => 'admin.alertas-processos.*', 'label' => 'Alertas', 'show' => true,
                     'icon' => ['M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9']],
                    ['route' => 'admin.documentos.index', 'check' => 'admin.documentos.*', 'label' => 'Documentos', 'show' => true,
                     'icon' => ['M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z']],
                    ['route' => 'admin.responsaveis.index', 'check' => 'admin.responsaveis.*', 'label' => 'Responsáveis', 'show' => true,
                     'icon' => ['M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z']],
                ],
            ],
            [
                'label' => 'Operacional',
                'items' => [
                    ['route' => 'admin.receituarios.index', 'check' => 'admin.receituarios.*', 'label' => 'Receituários',
                     'show' => $usuarioMenu->isAdmin() || $usuarioMenu->isEstadual(),
                     'icon' => ['M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z']],
                    ['route' => 'admin.ordens-servico.index', 'check' => 'admin.ordens-servico.*', 'label' => 'Ordens de Serviço',
                     'show' => $usuarioMenu->isAdmin() || $usuarioMenu->isEstadual() || $usuarioMenu->isMunicipal(),
                     'icon' => ['M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01']],
                    ['route' => 'admin.relatorios.index', 'check' => 'admin.relatorios.*', 'label' => 'Relatórios',
                     'show' => $usuarioMenu->isAdmin() || $usuarioMenu->isEstadual() || $usuarioMenu->isMunicipal(),
                     'icon' => ['M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z']],
                ],
            ],
            [
                'label' => 'Administração',
                'items' => [
                    ['route' => 'admin.usuarios-internos.index', 'check' => 'admin.usuarios-internos.*', 'label' => 'Usuários Internos',
                     'show' => $usuarioMenu->isAdmin() || $usuarioMenu->isGestor(),
                     'icon' => ['M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z']],
                    ['route' => 'admin.usuarios-externos.index', 'check' => 'admin.usuarios-externos.*', 'label' => 'Usuários Externos',
                     'show' => $usuarioMenu->isAdmin() || $nivelMenu === 'gestor_estadual',
                     'icon' => ['M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z']],
                    ['route' => 'admin.configuracoes.index', 'check' => 'admin.configuracoes.*', 'label' => 'Configurações',
                     'show' => $usuarioMenu->isAdmin() || in_array($nivelMenu, ['gestor_estadual', 'gestor_municipal']),
                     'icon' => ['M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z', 'M15 12a3 3 0 11-6 0 3 3 0 016 0z']],
                    ['route' => 'admin.whatsapp.painel', 'check' => 'admin.whatsapp.*', 'label' => 'WhatsApp',
                     'show' => $usuarioMenu->isAdmin(), 'fill' => true, 'accent' => 'green',
                     'icon' => ['M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z']],
                ],
            ],
        ];
    @endphp

    {{-- Overlay Mobile --}}
    <div x-show="sidebarOpen"
         x-cloak
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-slate-900/60 backdrop-blur-sm lg:hidden"
         x-transition:enter="transition-opacity ease-linear duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-300"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"></div>

    <div class="flex h-screen overflow-hidden">

        {{-- Sidebar --}}
        <aside class="fixed lg:relative inset-y-0 left-0 z-50 flex flex-col bg-white text-slate-600 shadow-2xl lg:shadow-none border-r border-slate-200/80"
               :class="{
                   'translate-x-0': sidebarOpen,
                   '-translate-x-full lg:translate-x-0': !sidebarOpen,
                   'sidebar-expanded': sidebarExpanded,
                   'sidebar-collapsed': !sidebarExpanded
               }"
               x-bind:style="'transition: width 300ms ease-in-out, transform 300ms ease-in-out;'">

            {{-- Logo Header --}}
            <div class="flex items-center h-16 px-4 border-b border-slate-100 flex-shrink-0"
                 :class="showLabels() ? 'justify-between' : 'lg:justify-center'">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-md shadow-blue-500/30 flex-shrink-0">
                        <svg class="w-[18px] h-[18px] text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                    </div>
                    <div x-show="showLabels()" x-cloak class="sidebar-label min-w-0 leading-tight">
                        <p class="text-slate-900 font-bold text-sm tracking-tight">InfoVISA</p>
                        <p class="text-[9px] font-semibold text-slate-400 uppercase tracking-widest">Painel Admin</p>
                    </div>
                </a>
                {{-- Toggle Desktop (Colapsar) --}}
                <button @click="toggleSidebar()"
                        x-show="sidebarExpanded"
                        x-cloak
                        title="Recolher menu"
                        class="sidebar-toggle-collapse hidden lg:flex items-center justify-center w-7 h-7 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                    </svg>
                </button>
                {{-- Close Mobile --}}
                <button @click="sidebarOpen = false"
                        class="lg:hidden flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            {{-- Expand Button (quando collapsed) --}}
            <button @click="toggleSidebar()"
                    x-show="!sidebarExpanded"
                    x-cloak
                    title="Expandir menu"
                    class="sidebar-toggle-expand hidden lg:flex items-center justify-center mx-auto mt-3 w-8 h-8 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
                </svg>
            </button>

            {{-- Navigation --}}
            <nav class="sidebar-nav flex-1 overflow-y-auto overflow-x-hidden py-3 px-2.5">
                @php $primeiraSecao = true; @endphp
                @foreach($menuSections as $section)
                    @php $itensVisiveis = array_filter($section['items'], fn ($i) => $i['show']); @endphp
                    @if(count($itensVisiveis) > 0)
                    <div class="{{ $primeiraSecao ? '' : 'mt-4' }}">
                        <p x-show="showLabels()" class="px-2.5 mb-1 text-[10px] font-semibold text-slate-400 uppercase tracking-[0.12em]">{{ $section['label'] }}</p>
                        @unless($primeiraSecao)
                        <div x-show="!showLabels()" x-cloak class="hidden lg:block mx-2 mb-2 border-t border-slate-100"></div>
                        @endunless
                        <div class="space-y-0.5">
                            @foreach($itensVisiveis as $item)
                                @php
                                    $ativo = request()->routeIs($item['check']);
                                    $verde = ($item['accent'] ?? null) === 'green';
                                    $classeAtiva = $verde ? 'bg-green-50 text-green-700' : 'bg-blue-50 text-blue-700';
                                    $iconeAtivo = $verde ? 'text-green-600' : 'text-blue-600';
                                    $barraAtiva = $verde ? 'bg-green-500' : 'bg-blue-600';
                                @endphp
                                <a href="{{ route($item['route']) }}"
                                   title="{{ $item['label'] }}"
                                   @if($ativo) aria-current="page" @endif
                                   class="group relative flex items-center gap-2.5 px-2.5 py-[7px] rounded-lg text-[13px] transition-colors duration-150 {{ $ativo ? $classeAtiva . ' font-semibold' : 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100' }}"
                                   :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                                    @if($ativo)
                                    <span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full {{ $barraAtiva }}"></span>
                                    @endif
                                    <svg class="w-[18px] h-[18px] flex-shrink-0 transition-colors {{ $ativo ? $iconeAtivo : 'text-slate-400 group-hover:text-slate-600' }}" fill="{{ !empty($item['fill']) ? 'currentColor' : 'none' }}" @empty($item['fill']) stroke="currentColor" @endempty viewBox="0 0 24 24">
                                        @foreach($item['icon'] as $path)
                                            @if(!empty($item['fill']))
                                                <path d="{{ $path }}"/>
                                            @else
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="{{ $path }}"/>
                                            @endif
                                        @endforeach
                                    </svg>
                                    <span x-show="showLabels()" class="truncate">{{ $item['label'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                    @php $primeiraSecao = false; @endphp
                    @endif
                @endforeach

                <div x-show="showLabels()" x-cloak class="mt-5 mx-2.5 pt-3 border-t border-slate-100 text-[10px] text-slate-400 leading-relaxed">
                    <p class="font-medium text-slate-500">Desenvolvido por Erick Vinicius</p>
                    <p>Versão do sistema: v3.1</p>
                </div>
            </nav>

            {{-- User Info & Logout --}}
            <div class="border-t border-slate-100 p-2.5 flex-shrink-0">
                <div class="flex items-center gap-2.5 rounded-lg bg-slate-50 p-1.5"
                     :class="!showLabels() ? 'lg:flex-col lg:gap-1.5 lg:bg-transparent lg:p-0' : ''">
                    <a href="{{ route('admin.perfil.index') }}" title="Meu Perfil"
                       class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0 text-white text-[11px] font-bold shadow-sm">
                        {{ $iniciaisMenu }}
                    </a>
                    <div x-show="showLabels()" class="flex-1 min-w-0 leading-tight">
                        <p class="text-xs font-semibold text-slate-800 truncate">{{ $usuarioMenu->nome }}</p>
                        <p class="text-[10px] text-slate-400 truncate">{{ $usuarioMenu->nivel_acesso->label() }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="flex-shrink-0">
                        @csrf
                        <button type="submit"
                                title="Sair do Sistema"
                                class="flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Main Content --}}
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            {{-- Top Header --}}
            <header class="sticky top-0 z-30 flex items-center h-16 bg-white/80 backdrop-blur-md border-b border-slate-200/70 px-4 lg:px-8">
                {{-- Mobile Menu Button --}}
                <button @click="sidebarOpen = !sidebarOpen"
                        class="lg:hidden p-2 -ml-2 mr-2 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>

                {{-- Page Title --}}
                <div class="min-w-0">
                    <h1 class="text-base sm:text-lg font-semibold text-slate-900 truncate tracking-tight">@yield('page-title', 'Dashboard')</h1>
                    <p class="hidden sm:block text-[11px] text-slate-400 leading-none mt-0.5">{{ ucfirst(now()->locale('pt_BR')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</p>
                </div>

                {{-- Spacer --}}
                <div class="flex-1"></div>

                {{-- Right Actions --}}
                <div class="flex items-center gap-1 sm:gap-2">
                    {{-- Notificações --}}
                    @include('components.notificacoes')

                    <div class="hidden sm:block w-px h-8 bg-slate-200 mx-1"></div>

                    {{-- User Menu --}}
                    <div class="relative" @click.away="userMenuOpen = false">
                        <button @click="userMenuOpen = !userMenuOpen"
                                class="flex items-center gap-2.5 pl-1 pr-2 py-1 rounded-xl hover:bg-slate-100 transition"
                                :class="userMenuOpen ? 'bg-slate-100' : ''">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                                <span class="text-white font-semibold text-xs">{{ $iniciaisMenu }}</span>
                            </div>
                            <div class="hidden md:block text-left leading-tight max-w-[160px]">
                                <p class="text-sm font-semibold text-slate-800 truncate">{{ Str::words($usuarioMenu->nome, 2, '') }}</p>
                                <p class="text-[11px] text-slate-400 truncate">{{ $usuarioMenu->nivel_acesso->label() }}</p>
                            </div>
                            <svg class="hidden md:block w-4 h-4 text-slate-400 transition-transform" :class="userMenuOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        {{-- Dropdown Menu --}}
                        <div x-show="userMenuOpen"
                             x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl shadow-slate-900/10 ring-1 ring-slate-200 p-1.5 z-50 origin-top-right">

                            <div class="flex items-center gap-3 px-3 py-3 mb-1 rounded-xl bg-slate-50">
                                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                                    <span class="text-white font-semibold text-sm">{{ $iniciaisMenu }}</span>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 truncate">{{ $usuarioMenu->nome }}</p>
                                    <p class="text-xs text-slate-500 truncate">{{ $usuarioMenu->nivel_acesso->label() }}</p>
                                    <p class="text-[11px] text-slate-400 truncate">{{ $usuarioMenu->email }}</p>
                                </div>
                            </div>

                            <a href="{{ route('admin.perfil.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-700 hover:bg-slate-100 transition">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                Meu Perfil
                            </a>
                            <a href="{{ route('admin.assinatura.configurar-senha') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-700 hover:bg-slate-100 transition">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                </svg>
                                Assinatura Digital
                            </a>

                            <div class="border-t border-slate-100 my-1.5"></div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex items-center gap-2.5 w-full px-3 py-2 rounded-lg text-sm text-red-600 hover:bg-red-50 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Sair do Sistema
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Page Content --}}
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                {{-- Alerta de demandas atrasadas/vencendo/paradas do usuário --}}
                @include('components.alerta-pendencias')

                {{-- Alertas Flash --}}
                @if(session('success'))
                <div class="mb-4 bg-emerald-50 border border-emerald-200/80 text-emerald-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
                    <span class="w-7 h-7 rounded-lg bg-emerald-500 text-white flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    <span class="text-sm font-medium">{{ session('success') }}</span>
                </div>
                @endif

                @if(session('error'))
                <div class="mb-4 bg-red-50 border border-red-200/80 text-red-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
                    <span class="w-7 h-7 rounded-lg bg-red-500 text-white flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                    <span class="text-sm font-medium">{{ session('error') }}</span>
                </div>
                @endif

                @if(session('warning'))
                <div class="mb-4 bg-amber-50 border border-amber-200/80 text-amber-800 px-4 py-3 rounded-xl flex items-center gap-3 shadow-sm">
                    <span class="w-7 h-7 rounded-lg bg-amber-500 text-white flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                    </span>
                    <span class="text-sm font-medium">{{ session('warning') }}</span>
                </div>
                @endif

                @yield('content')
            </main>
        </div>
    </div>

    {{-- Base URL para JavaScript --}}
    <script>
        window.APP_BASE_URL = '{{ rtrim(config('app.url'), '/') }}';
    </script>

    {{-- PDF.js Library --}}
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        if (typeof pdfjsLib !== 'undefined') {
            pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }
    </script>

    {{-- PDF Viewer --}}
    <script src="{{ asset('js/pdf-viewer-anotacoes.js') }}?v={{ time() }}"></script>
    <script src="{{ asset('js/pdf-viewer-simple.js') }}?v={{ time() }}"></script>

    @stack('scripts')
    @stack('modals')

    {{-- Chat Interno --}}
    @include('components.chat-interno')

    {{-- Assistentes IA --}}
    @include('components.assistente-ia-chat')
    @include('components.assistente-documento-chat')

</body>
</html>
