<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') - InfoVISA Empresa</title>
    
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
        $usuarioExterno = auth('externo')->user();
        $partesNomeExt = preg_split('/\s+/', trim($usuarioExterno->nome));
        $iniciaisExt = mb_strtoupper(mb_substr($partesNomeExt[0] ?? '', 0, 1) . (count($partesNomeExt) > 1 ? mb_substr(end($partesNomeExt), 0, 1) : ''));
        $classeItemMenu = 'group relative flex items-center gap-2.5 px-2.5 py-[7px] rounded-lg text-[13px] transition-colors duration-150';
        $classeItemAtivo = 'bg-blue-50 text-blue-700 font-semibold';
        $classeItemInativo = 'font-medium text-slate-600 hover:text-slate-900 hover:bg-slate-100';
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
                <a href="{{ route('company.dashboard') }}" class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-md shadow-blue-500/30 flex-shrink-0">
                        <svg class="w-[18px] h-[18px] text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <div x-show="showLabels()" x-cloak class="sidebar-label min-w-0 leading-tight">
                        <p class="text-slate-900 font-bold text-sm tracking-tight">InfoVISA</p>
                        <p class="text-[9px] font-semibold text-slate-400 uppercase tracking-widest">Área da Empresa</p>
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
                <p x-show="showLabels()" class="px-2.5 mb-1 text-[10px] font-semibold text-slate-400 uppercase tracking-[0.12em]">Menu</p>
                <div class="space-y-0.5">
                    {{-- Dashboard --}}
                    @php $ativo = request()->routeIs('company.dashboard'); @endphp
                    <a href="{{ route('company.dashboard') }}"
                       title="Dashboard"
                       class="{{ $classeItemMenu }} {{ $ativo ? $classeItemAtivo : $classeItemInativo }}"
                       :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                        @if($ativo)<span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-blue-600"></span>@endif
                        <svg class="w-[18px] h-[18px] flex-shrink-0 {{ $ativo ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                        <span x-show="showLabels()" class="truncate">Dashboard</span>
                    </a>

                    {{-- Estabelecimentos --}}
                    @php $ativo = request()->routeIs('company.estabelecimentos.*'); @endphp
                    <a href="{{ route('company.estabelecimentos.index') }}"
                       title="Meus Estabelecimentos"
                       class="{{ $classeItemMenu }} {{ $ativo ? $classeItemAtivo : $classeItemInativo }}"
                       :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                        @if($ativo)<span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-blue-600"></span>@endif
                        <svg class="w-[18px] h-[18px] flex-shrink-0 {{ $ativo ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                        <span x-show="showLabels()" class="truncate">Estabelecimentos</span>
                    </a>

                    {{-- Processos --}}
                    @php $ativo = request()->routeIs('company.processos.*'); @endphp
                    <a href="{{ route('company.processos.index') }}"
                       title="Meus Processos"
                       class="{{ $classeItemMenu }} {{ $ativo ? $classeItemAtivo : $classeItemInativo }}"
                       :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                        @if($ativo)<span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-blue-600"></span>@endif
                        <svg class="w-[18px] h-[18px] flex-shrink-0 {{ $ativo ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span x-show="showLabels()" class="truncate">Processos</span>
                    </a>

                    {{-- Alertas --}}
                    @php
                        $alertasPendentesCount = 0;
                        $documentosPendentesCount = 0;
                        $documentosRejeitadosCount = 0;
                        $documentosComPrazoCount = 0;
                        if (auth('externo')->check()) {
                            $estabelecimentoIds = \App\Models\Estabelecimento::where('usuario_externo_id', auth('externo')->id())
                                ->orWhereHas('usuariosVinculados', function($q) {
                                    $q->where('usuario_externo_id', auth('externo')->id());
                                })
                                ->pluck('id');
                            $processoIds = \App\Models\Processo::whereIn('estabelecimento_id', $estabelecimentoIds)->pluck('id');
                            $alertasPendentesCount = \App\Models\ProcessoAlerta::whereIn('processo_id', $processoIds)
                                ->where('status', '!=', 'concluido')
                                ->count();
                            $documentosPendentesCount = \App\Models\DocumentoDigital::whereIn('processo_id', $processoIds)
                                ->where('status', 'assinado')
                                ->where('sigiloso', false)
                                ->whereDoesntHave('visualizacoes')
                                ->count();
                            $documentosRejeitadosCount = \App\Models\ProcessoDocumento::whereIn('processo_id', $processoIds)
                                ->where('status_aprovacao', 'rejeitado')
                                ->count();
                            $documentosComPrazoCount = \App\Models\DocumentoDigital::whereIn('processo_id', $processoIds)
                                ->where('status', 'assinado')
                                ->where('sigiloso', false)
                                ->where('prazo_notificacao', true)
                                ->whereNotNull('prazo_iniciado_em')
                                ->whereNull('prazo_finalizado_em')
                                ->count();
                        }
                        $totalNotificacoes = $alertasPendentesCount + $documentosPendentesCount + $documentosRejeitadosCount + $documentosComPrazoCount;
                        $ativo = request()->routeIs('company.alertas.*');
                    @endphp
                    <a href="{{ route('company.alertas.index') }}"
                       title="Alertas{{ $documentosRejeitadosCount > 0 ? " - {$documentosRejeitadosCount} documento(s) rejeitado(s)" : '' }}{{ $documentosPendentesCount > 0 ? " - {$documentosPendentesCount} documento(s) pendente(s)" : '' }}"
                       class="{{ $classeItemMenu }} {{ $ativo ? 'bg-orange-50 text-orange-700 font-semibold' : $classeItemInativo }}"
                       :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                        @if($ativo)<span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-orange-500"></span>@endif
                        <span class="relative flex-shrink-0">
                            <svg class="w-[18px] h-[18px] {{ $ativo ? 'text-orange-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            @if($totalNotificacoes > 0)
                            <span x-show="!showLabels()" class="absolute -top-1.5 -right-1.5 min-w-[16px] h-4 px-1 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center ring-2 ring-white">
                                {{ $totalNotificacoes > 9 ? '9+' : $totalNotificacoes }}
                            </span>
                            @endif
                        </span>
                        <span x-show="showLabels()" class="truncate flex-1">Alertas</span>
                        @if($totalNotificacoes > 0)
                        <span x-show="showLabels()" class="min-w-[20px] h-5 px-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full inline-flex items-center justify-center">
                            {{ $totalNotificacoes > 9 ? '9+' : $totalNotificacoes }}
                        </span>
                        @endif
                    </a>

                    {{-- Meu Perfil --}}
                    @php $ativo = request()->routeIs('company.perfil.*'); @endphp
                    <a href="{{ route('company.perfil.index') }}"
                       title="Meu Perfil"
                       class="{{ $classeItemMenu }} {{ $ativo ? $classeItemAtivo : $classeItemInativo }}"
                       :class="!showLabels() ? 'lg:justify-center lg:px-0' : ''">
                        @if($ativo)<span class="absolute -left-2.5 top-1/2 -translate-y-1/2 h-5 w-1 rounded-r-full bg-blue-600"></span>@endif
                        <svg class="w-[18px] h-[18px] flex-shrink-0 {{ $ativo ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                        <span x-show="showLabels()" class="truncate">Meu Perfil</span>
                    </a>
                </div>

                <div x-show="showLabels()" x-cloak class="mt-5 mx-2.5 pt-3 border-t border-slate-100 text-[10px] text-slate-400 leading-relaxed">
                    <p class="font-medium text-slate-500">Desenvolvido por Erick Vinicius</p>
                    <p>Versão do sistema: v3.1</p>
                </div>
            </nav>

            {{-- Usuário + Logout --}}
            <div class="border-t border-slate-100 p-2.5 flex-shrink-0">
                <div class="flex items-center gap-2.5 rounded-lg bg-slate-50 p-1.5"
                     :class="!showLabels() ? 'lg:flex-col lg:gap-1.5 lg:bg-transparent lg:p-0' : ''">
                    <a href="{{ route('company.perfil.index') }}" title="Meu Perfil"
                       class="w-8 h-8 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0 text-white text-[11px] font-bold shadow-sm">
                        {{ $iniciaisExt }}
                    </a>
                    <div x-show="showLabels()" class="flex-1 min-w-0 leading-tight">
                        <p class="text-xs font-semibold text-slate-800 truncate">{{ $usuarioExterno->nome }}</p>
                        <p class="text-[10px] text-slate-400 truncate">{{ $usuarioExterno->email }}</p>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="flex-shrink-0">
                        @csrf
                        <button type="submit"
                                title="Sair"
                                class="flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 hover:text-red-600 hover:bg-red-50 transition">
                            <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </aside>

        {{-- Conteúdo principal --}}
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            {{-- Header --}}
            <header class="sticky top-0 z-30 flex items-center h-16 bg-white/80 backdrop-blur-md border-b border-slate-200/70 px-4 lg:px-8">
                {{-- Botão hamburguer (mobile) --}}
                <button @click="sidebarOpen = !sidebarOpen"
                        class="lg:hidden p-2 -ml-2 mr-2 rounded-lg text-slate-500 hover:text-slate-800 hover:bg-slate-100 transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                </button>
                <div class="min-w-0">
                    <h2 class="text-base sm:text-lg font-semibold text-slate-900 truncate tracking-tight">@yield('page-title', 'Dashboard')</h2>
                    <p class="hidden sm:block text-[11px] text-slate-400 leading-none mt-0.5">{{ ucfirst(now()->locale('pt_BR')->isoFormat('dddd, D [de] MMMM [de] YYYY')) }}</p>
                </div>

                <div class="flex-1"></div>

                <div class="flex items-center gap-1 sm:gap-2">
                    {{-- Botão de Ajuda (?) - Documentos Instrutivos --}}
                    <div class="relative" @click.away="helpMenuOpen = false">
                        <button id="btn-ajuda"
                                @click="helpMenuOpen = !helpMenuOpen; userMenuOpen = false"
                                class="flex items-center justify-center w-9 h-9 rounded-xl text-slate-500 hover:text-blue-600 hover:bg-blue-50 transition-colors focus:outline-none focus:ring-2 focus:ring-blue-500/30"
                                :class="helpMenuOpen ? 'bg-blue-50 text-blue-600' : ''"
                                title="Documentos de Ajuda">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </button>

                        <div x-show="helpMenuOpen"
                             x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             x-transition:leave="transition ease-in duration-75"
                             x-transition:leave-start="transform opacity-100 scale-100"
                             x-transition:leave-end="transform opacity-0 scale-95"
                             class="origin-top-right absolute right-0 mt-2 w-80 rounded-2xl shadow-xl shadow-slate-900/10 bg-white ring-1 ring-slate-200 z-50 overflow-hidden">
                            <div class="px-4 py-3 border-b border-slate-100 bg-gradient-to-r from-blue-50 to-white">
                                <div class="flex items-center gap-2.5">
                                    <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center">
                                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <h3 class="font-semibold text-sm text-slate-900">Documentos de Ajuda</h3>
                                        <p class="text-xs text-slate-500">Instrutivos e manuais do sistema</p>
                                    </div>
                                </div>
                            </div>
                            <div class="max-h-80 overflow-y-auto p-1.5">
                                {{-- Manual fixo do InfoVISA 3.0 --}}
                                <a href="{{ asset('Manual/manual-infovisa.html') }}"
                                   target="_blank"
                                   class="flex items-start gap-3 px-3 py-2.5 rounded-xl hover:bg-blue-50 transition-colors group">
                                    <div class="flex-shrink-0 mt-0.5">
                                        <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-semibold text-slate-800 group-hover:text-blue-700">Manual InfoVISA 3.0</p>
                                        <p class="text-xs text-slate-500 mt-0.5">Guia completo do sistema</p>
                                    </div>
                                    <div class="flex-shrink-0 mt-0.5">
                                        <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </div>
                                </a>
                                @if(isset($documentosAjuda) && $documentosAjuda->count() > 0)
                                @foreach($documentosAjuda as $docAjuda)
                                <a href="{{ route('company.documentos-ajuda.visualizar', $docAjuda->id) }}"
                                   target="_blank"
                                   class="flex items-start gap-3 px-3 py-2.5 rounded-xl hover:bg-slate-50 transition-colors group">
                                    <div class="flex-shrink-0 mt-0.5">
                                        <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 group-hover:text-blue-600 truncate">{{ $docAjuda->titulo }}</p>
                                        @if($docAjuda->descricao)
                                        <p class="text-xs text-slate-500 mt-0.5 line-clamp-2">{{ $docAjuda->descricao }}</p>
                                        @endif
                                        <p class="text-xs text-slate-400 mt-1">{{ $docAjuda->tamanho_formatado }}</p>
                                    </div>
                                    <div class="flex-shrink-0 mt-0.5">
                                        <svg class="w-4 h-4 text-slate-400 group-hover:text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                    </div>
                                </a>
                                @endforeach
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="hidden sm:block w-px h-8 bg-slate-200 mx-1"></div>

                    {{-- Menu do usuário --}}
                    <div class="relative" @click.away="userMenuOpen = false">
                        <button @click="userMenuOpen = !userMenuOpen; helpMenuOpen = false"
                                class="flex items-center gap-2.5 pl-1 pr-2 py-1 rounded-xl hover:bg-slate-100 transition focus:outline-none"
                                :class="userMenuOpen ? 'bg-slate-100' : ''">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center shadow-sm">
                                <span class="text-white font-semibold text-xs">{{ $iniciaisExt }}</span>
                            </div>
                            <div class="hidden md:block text-left leading-tight max-w-[160px]">
                                <p class="text-sm font-semibold text-slate-800 truncate">{{ Str::limit($usuarioExterno->nome, 20) }}</p>
                                <p class="text-[11px] text-slate-400 truncate">Usuário externo</p>
                            </div>
                            <svg class="hidden md:block w-4 h-4 text-slate-400 transition-transform" :class="userMenuOpen ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>

                        <div x-show="userMenuOpen"
                             x-cloak
                             x-transition:enter="transition ease-out duration-100"
                             x-transition:enter-start="transform opacity-0 scale-95"
                             x-transition:enter-end="transform opacity-100 scale-100"
                             class="origin-top-right absolute right-0 mt-2 w-64 rounded-2xl shadow-xl shadow-slate-900/10 p-1.5 bg-white ring-1 ring-slate-200 z-50">
                            <div class="flex items-center gap-3 px-3 py-3 mb-1 rounded-xl bg-slate-50">
                                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center flex-shrink-0">
                                    <span class="text-white font-semibold text-sm">{{ $iniciaisExt }}</span>
                                </div>
                                <div class="min-w-0">
                                    <div class="font-semibold text-sm text-slate-900 truncate">{{ $usuarioExterno->nome }}</div>
                                    <div class="text-xs text-slate-500 truncate">{{ $usuarioExterno->email }}</div>
                                </div>
                            </div>
                            <a href="{{ route('company.perfil.index') }}" class="flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm text-slate-700 hover:bg-slate-100 transition">
                                <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                Meu Perfil
                            </a>
                            <div class="border-t border-slate-100 my-1.5"></div>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="flex items-center gap-2.5 w-full px-3 py-2 rounded-lg text-sm text-red-600 hover:bg-red-50 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                                    </svg>
                                    Sair
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </header>

            {{-- Conteúdo da página --}}
            <main class="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8">
                {{-- Nome do usuário parece ser de empresa --}}
                @php $usuarioLogadoExterno = auth('externo')->user(); @endphp
                @if($usuarioLogadoExterno && \App\Support\NomePessoaHelper::pareceEmpresa($usuarioLogadoExterno->nome))
                <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded-xl flex flex-col sm:flex-row sm:items-center gap-3 shadow-sm">
                    <div class="flex items-start gap-3 flex-1">
                        <span class="w-7 h-7 rounded-lg bg-amber-500 text-white flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </span>
                        <div>
                            <p class="text-sm font-semibold">Esse nome não parece ser seu: "{{ $usuarioLogadoExterno->nome }}" parece ser de uma empresa.</p>
                            <p class="text-xs text-amber-800 mt-0.5">O cadastro de usuário deve estar no nome da pessoa (titular do CPF). Atualize seus dados com o seu nome completo.</p>
                        </div>
                    </div>
                    @unless(request()->routeIs('company.perfil.*'))
                    <a href="{{ route('company.perfil.index') }}#dados" class="flex-shrink-0 inline-flex items-center justify-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-amber-600 rounded-lg hover:bg-amber-700 transition">
                        Atualizar meu nome
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                    </a>
                    @endunless
                </div>
                @endif

                {{-- Alertas --}}
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

                @yield('content')

                {{-- Rodapé --}}
                <footer class="mt-12 pt-6 border-t border-slate-200/70">
                    <p class="text-center text-xs text-slate-400 flex flex-wrap items-center justify-center gap-x-2 gap-y-1">
                        <span class="font-semibold text-slate-600">InfoVISA</span>
                        <span class="text-slate-300" aria-hidden="true">·</span>
                        <span>© {{ date('Y') }} Todos os direitos reservados.</span>
                        <span class="text-slate-300" aria-hidden="true">·</span>
                        <span>Desenvolvido por <span class="font-medium text-slate-600">Erick Vinicius</span></span>
                        <span class="text-slate-300" aria-hidden="true">·</span>
                        <a href="tel:+556330274486" class="text-slate-500 hover:text-blue-600 transition-colors">(63) 3027-4486</a>
                    </p>
                </footer>
            </main>
        </div>
    </div>

    @stack('scripts')

    {{-- Notificações push para o app Android --}}
    <script>
    (function() {
        // Detecta se está no app Android
        var isApp = false;
        try { isApp = (typeof InfoVISAApp !== 'undefined'); } catch(e) {}
        
        if (!isApp) return;

        // Marca no body que está no app (para CSS)
        document.body.classList.add('is-android-app');

        // Busca notificações da API
        var xhr = new XMLHttpRequest();
        xhr.open('GET', window.APP_URL + '/company/api/notificacoes', true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.notificacoes && data.notificacoes.length > 0) {
                        for (var i = 0; i < data.notificacoes.length; i++) {
                            var n = data.notificacoes[i];
                            InfoVISAApp.showNotification(n.titulo, n.mensagem, n.tipo, n.url, n.id);
                        }
                    }
                } catch(e) {}
            }
        };
        xhr.send();
    })();
    </script>
</body>
</html>
