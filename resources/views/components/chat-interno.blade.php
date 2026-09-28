@php
    // Verifica se o Chat Interno está ativo nas configurações
    $chatInternoAtivo = \App\Models\ConfiguracaoSistema::where('chave', 'chat_interno_ativo')->first();
    $chatAtivo = $chatInternoAtivo && $chatInternoAtivo->valor === 'true';

    // Não mostra chat na página de criação/edição de documentos
    $rotaAtual = Route::currentRouteName();
    if (str_contains($rotaAtual, 'documentos.create') || str_contains($rotaAtual, 'documentos.edit')) {
        $chatAtivo = false;
    }
@endphp

@if($chatAtivo)
{{-- Chat Interno + Assistente de Pendências --}}
<div x-data="chatInterno()" x-init="init()" class="fixed bottom-8 z-40" style="right: 20px;">

    {{-- Robô de avisos: aparece ao lado do botão, fala sobre as demandas e some sozinho --}}
    <div x-show="robo.visivel && !isOpen" x-cloak
         x-transition:enter="robo-entrada"
         x-transition:enter-start="opacity-0 translate-y-6 scale-50"
         x-transition:enter-end="opacity-100 translate-y-0 scale-100"
         x-transition:leave="transition ease-in duration-300"
         x-transition:leave-start="opacity-100 translate-y-0 scale-100"
         x-transition:leave-end="opacity-0 translate-y-6 scale-75"
         class="absolute bottom-0 right-[4.25rem] flex items-end gap-2 origin-bottom-right">

        {{-- Balão de fala --}}
        <div x-show="robo.balao" x-transition.opacity.duration.200ms
             class="relative mb-10 w-64 sm:w-72 bg-white rounded-2xl rounded-br-sm shadow-2xl ring-1 p-3.5 cursor-pointer"
             :class="robo.item?.nivel === 'atrasado' ? 'ring-red-200' : (robo.item?.nivel === 'vencendo' ? 'ring-amber-200' : 'ring-slate-200')"
             @click="clicarMensagemRobo()" @mouseenter="pausarRobo()" @mouseleave="retomarRobo()">
            <button @click.stop="silenciarRobo()" class="absolute top-1.5 right-1.5 p-1 text-slate-300 hover:text-slate-500 rounded-md" title="Silenciar por 30 minutos">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <p class="text-[10px] font-bold uppercase tracking-widest mb-1"
               :class="robo.item?.nivel === 'atrasado' ? 'text-red-600' : (robo.item?.nivel === 'vencendo' ? 'text-amber-600' : (robo.item?.nivel === 'parado' ? 'text-orange-600' : 'text-blue-600'))"
               x-text="robo.rotulo"></p>
            <p class="text-sm text-slate-800 leading-snug pr-3 min-h-[2.5rem]">
                <span x-html="formatarMensagem(robo.texto)"></span><span x-show="robo.digitando" class="inline-block w-1.5 h-4 -mb-0.5 ml-0.5 bg-slate-400 animate-pulse"></span>
            </p>
            <div x-show="!robo.digitando" class="flex items-center gap-2 mt-2.5">
                <a x-show="robo.item?.url" :href="robo.item?.url" @click.stop
                   class="px-2.5 py-1 text-[11px] font-bold text-white rounded-lg transition"
                   :class="robo.item?.nivel === 'atrasado' ? 'bg-red-600 hover:bg-red-700' : 'bg-slate-900 hover:bg-slate-700'">Resolver agora</a>
                <button @click.stop="abrirChat(); abrirAssistente(); fecharRobo()" class="px-2.5 py-1 text-[11px] font-semibold text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition">Ver todas</button>
            </div>
            {{-- "rabinho" do balão --}}
            <span class="absolute -bottom-1.5 right-3 w-3 h-3 bg-white rotate-45 border-r border-b border-slate-200"></span>
        </div>

        {{-- Robô --}}
        <button @click="clicarRobo()" class="robo-flutuar flex-shrink-0 w-16 h-16 focus:outline-none" title="Assistente de Pendências">
            <svg viewBox="0 0 64 64" class="w-full h-full drop-shadow-lg" :class="{ 'robo-falando': robo.digitando, 'robo-alerta': assistente.resumo.atrasados > 0 }">
                <defs>
                    <linearGradient id="robo-cabeca" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#3b82f6"/><stop offset="1" stop-color="#4f46e5"/></linearGradient>
                </defs>
                {{-- antena --}}
                <line x1="32" y1="5" x2="32" y2="13" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round"/>
                <circle class="robo-luz" cx="32" cy="5" r="3.5" :fill="assistente.resumo.atrasados > 0 ? '#ef4444' : '#f59e0b'"/>
                {{-- orelhas --}}
                <rect x="5" y="25" width="5" height="11" rx="2.5" fill="#6366f1"/>
                <rect x="54" y="25" width="5" height="11" rx="2.5" fill="#6366f1"/>
                {{-- cabeça e tela --}}
                <rect x="9" y="12" width="46" height="36" rx="13" fill="url(#robo-cabeca)"/>
                <rect x="15" y="19" width="34" height="22" rx="9" fill="#0f172a"/>
                {{-- olhos --}}
                <ellipse class="robo-olho" cx="25" cy="28" rx="3.4" ry="4" fill="#67e8f9"/>
                <ellipse class="robo-olho" cx="39" cy="28" rx="3.4" ry="4" fill="#67e8f9"/>
                {{-- boca --}}
                <rect class="robo-boca" x="27" y="34" width="10" height="3" rx="1.5" fill="#67e8f9"/>
                {{-- corpo e braço acenando --}}
                <rect x="21" y="48" width="22" height="12" rx="6" fill="#4f46e5"/>
                <circle cx="32" cy="54" r="2.2" :fill="assistente.resumo.atrasados > 0 ? '#fca5a5' : '#a5f3fc'"/>
                <path class="robo-braco" d="M43 51 q7 -2 9 -9" stroke="#6366f1" stroke-width="3.5" stroke-linecap="round" fill="none"/>
                <circle class="robo-braco" cx="52.5" cy="41" r="3" fill="#818cf8"/>
            </svg>
        </button>
    </div>

    {{-- Botão flutuante --}}
    <button @click="toggleChat()"
            class="relative w-14 h-14 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl shadow-lg shadow-blue-600/30 hover:shadow-xl hover:scale-105 transition-all duration-200 flex items-center justify-center"
            :class="{ 'ring-4 ring-blue-200': isOpen }" title="Chat Interno">
        <svg x-show="!isOpen" class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
        </svg>
        <svg x-show="isOpen" x-cloak class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
        {{-- Mensagens não lidas --}}
        <span x-show="(totalNaoLidas + suporteNaoLidos) > 0 && !isOpen" x-cloak
              class="absolute -top-1.5 -right-1.5 min-w-[22px] h-[22px] px-1 bg-red-500 text-white text-[11px] font-bold rounded-full flex items-center justify-center ring-2 ring-white"
              x-text="(totalNaoLidas + suporteNaoLidos) > 9 ? '9+' : (totalNaoLidas + suporteNaoLidos)"></span>
        {{-- Demandas atrasadas (assistente) --}}
        <span x-show="assistente.resumo.atrasados > 0 && !isOpen" x-cloak
              class="absolute -bottom-1.5 -left-1.5 min-w-[22px] h-[22px] px-1 bg-orange-500 text-white text-[11px] font-bold rounded-full flex items-center justify-center ring-2 ring-white"
              :title="assistente.resumo.atrasados + ' demanda(s) atrasada(s)'"
              x-text="assistente.resumo.atrasados > 9 ? '9+' : assistente.resumo.atrasados"></span>
    </button>

    {{-- Janela --}}
    <div x-show="isOpen" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-4"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         class="fixed inset-0 sm:inset-auto sm:absolute sm:bottom-[4.5rem] sm:right-0 sm:w-[400px] sm:h-[620px] sm:max-h-[calc(100vh-7rem)] bg-white sm:rounded-2xl shadow-2xl ring-1 ring-slate-200 overflow-hidden flex flex-col">

        {{-- Cabeçalho --}}
        <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-3 flex-shrink-0 bg-white">
            <button x-show="conversaAtual || suporteAberto || assistenteAberto" @click="voltarLista()" class="p-1.5 -ml-1.5 text-slate-500 hover:text-slate-800 hover:bg-slate-100 rounded-lg transition" title="Voltar">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>

            {{-- Lista --}}
            <div x-show="!conversaAtual && !suporteAberto && !assistenteAberto" class="flex-1 min-w-0">
                <h3 class="text-base font-bold text-slate-900 tracking-tight">Mensagens</h3>
                <p class="text-xs text-slate-400 flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                    <span x-text="usuariosOnline + (usuariosOnline === 1 ? ' pessoa online' : ' pessoas online')"></span>
                </p>
            </div>

            {{-- Assistente --}}
            <div x-show="assistenteAberto" class="flex items-center gap-3 flex-1 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0M3.124 7.5A8.969 8.969 0 015.292 3m13.416 0a8.969 8.969 0 012.168 4.5"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-bold text-slate-900 truncate">Assistente de Pendências</h3>
                    <p class="text-xs text-slate-400" x-text="assistente.atualizado_em ? 'Atualizado às ' + assistente.atualizado_em : 'Robô de avisos'"></p>
                </div>
                <button @click="carregarAssistente(true, true)" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition" title="Atualizar">
                    <svg class="w-4 h-4" :class="assistente.carregando && 'animate-spin'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                </button>
            </div>

            {{-- Suporte --}}
            <div x-show="suporteAberto" class="flex items-center gap-3 flex-1 min-w-0">
                <div class="w-9 h-9 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                </div>
                <div class="min-w-0">
                    <h3 class="text-sm font-bold text-slate-900">Suporte InfoVISA</h3>
                    <p class="text-xs text-slate-400">Avisos e comunicados do sistema</p>
                </div>
            </div>

            {{-- Conversa --}}
            <div x-show="conversaAtual && !suporteAberto && !assistenteAberto" class="flex items-center gap-3 flex-1 min-w-0">
                <div class="relative flex-shrink-0">
                    <div class="w-9 h-9 rounded-full flex items-center justify-center text-white text-sm font-semibold" :class="corAvatar(conversaAtual?.nome)">
                        <span x-text="conversaAtual?.iniciais || ''"></span>
                    </div>
                    <span x-show="conversaAtual?.online" class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-emerald-500 border-2 border-white rounded-full"></span>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-sm font-bold text-slate-900 truncate" x-text="conversaAtual?.nome || ''"></h3>
                    <p class="text-xs truncate" :class="conversaAtual?.online ? 'text-emerald-600' : 'text-slate-400'"
                       x-text="(conversaAtual?.online ? 'Online' : 'Offline') + ' · ' + (conversaAtual?.tipo || '') + (conversaAtual?.municipio ? ' · ' + conversaAtual.municipio : '')"></p>
                </div>
            </div>

            <button @click="toggleChat()" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition" title="Fechar">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        {{-- Conteúdo --}}
        <div class="flex-1 overflow-hidden flex flex-col bg-white">

            {{-- ===================== LISTA ===================== --}}
            <div x-show="!conversaAtual && !suporteAberto && !assistenteAberto" class="flex-1 overflow-hidden flex flex-col">
                {{-- Busca + abas --}}
                <div class="px-3 pt-3 pb-2 space-y-2 flex-shrink-0">
                    <div class="relative">
                        <svg class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" x-model="buscaUsuario" @input.debounce.250ms="onBusca()"
                               :placeholder="tab === 'conversas' ? 'Buscar conversa...' : 'Buscar pessoa...'"
                               class="w-full pl-9 pr-3 py-2 text-sm bg-slate-100 border border-transparent rounded-xl focus:bg-white focus:border-blue-400 focus:ring-4 focus:ring-blue-500/10 transition">
                    </div>
                    <div class="flex p-0.5 bg-slate-100 rounded-lg text-xs font-semibold">
                        <button @click="tab = 'conversas'" :class="tab === 'conversas' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="flex-1 py-1.5 rounded-md transition">
                            Conversas <span x-show="totalNaoLidas > 0" class="ml-1 px-1.5 rounded-full bg-blue-600 text-white text-[10px]" x-text="totalNaoLidas"></span>
                        </button>
                        <button @click="tab = 'usuarios'; carregarUsuarios()" :class="tab === 'usuarios' ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-500 hover:text-slate-700'" class="flex-1 py-1.5 rounded-md transition">Pessoas</button>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto">
                    {{-- Fixados --}}
                    <div x-show="tab === 'conversas' && !buscaUsuario" class="px-2 pb-1 space-y-1">
                        {{-- Assistente --}}
                        <button @click="abrirAssistente()" class="w-full p-2.5 flex items-center gap-3 rounded-xl transition text-left"
                                :class="(assistente.resumo.atrasados + assistente.resumo.parados) > 0 ? 'bg-orange-50 hover:bg-orange-100/70 ring-1 ring-orange-200' : 'bg-slate-50 hover:bg-slate-100'">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0M3.124 7.5A8.969 8.969 0 015.292 3m13.416 0a8.969 8.969 0 012.168 4.5"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-slate-900">Assistente de Pendências</span>
                                    <span class="text-[10px] font-semibold uppercase tracking-wider text-orange-600">Robô</span>
                                </div>
                                <p class="text-xs truncate mt-0.5" :class="assistente.resumo.atrasados > 0 ? 'text-orange-700 font-medium' : 'text-slate-500'" x-text="resumoAssistenteCurto()"></p>
                            </div>
                            <span x-show="assistente.resumo.atrasados > 0" class="min-w-[20px] h-5 px-1.5 bg-orange-500 text-white text-[11px] font-bold rounded-full flex items-center justify-center" x-text="assistente.resumo.atrasados"></span>
                        </button>

                        {{-- Suporte --}}
                        <button @click="abrirSuporte()" class="w-full p-2.5 flex items-center gap-3 rounded-xl hover:bg-slate-50 transition text-left">
                            <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-blue-600 to-indigo-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <span class="text-sm font-semibold text-slate-900">Suporte InfoVISA</span>
                                <p class="text-xs text-slate-500 mt-0.5">Avisos e comunicados</p>
                            </div>
                            <span x-show="suporteNaoLidos > 0" class="min-w-[20px] h-5 px-1.5 bg-blue-600 text-white text-[11px] font-bold rounded-full flex items-center justify-center" x-text="suporteNaoLidos"></span>
                        </button>
                        <p class="px-2 pt-2 text-[10px] font-semibold uppercase tracking-widest text-slate-400">Conversas recentes</p>
                    </div>

                    {{-- Conversas --}}
                    <div x-show="tab === 'conversas'" class="px-2 pb-2">
                        <div x-show="loading && conversas.length === 0" class="p-8 text-center text-slate-400">
                            <svg class="w-6 h-6 mx-auto animate-spin mb-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <p class="text-xs">Carregando...</p>
                        </div>
                        <div x-show="!loading && conversasFiltradas().length === 0" class="py-8 text-center">
                            <p class="text-sm text-slate-500" x-text="buscaUsuario ? 'Nenhuma conversa encontrada' : 'Nenhuma conversa ainda'"></p>
                            <button x-show="!buscaUsuario" @click="tab = 'usuarios'; carregarUsuarios()" class="mt-2 text-xs font-semibold text-blue-600 hover:text-blue-800">Iniciar uma conversa →</button>
                        </div>
                        <template x-for="conv in conversasFiltradas()" :key="conv.id">
                            <button @click="abrirConversa(conv)" class="w-full p-2.5 flex items-center gap-3 rounded-xl hover:bg-slate-50 transition text-left">
                                <div class="relative flex-shrink-0">
                                    <div class="w-11 h-11 rounded-full flex items-center justify-center text-white text-sm font-semibold" :class="corAvatar(conv.nome)"><span x-text="conv.iniciais"></span></div>
                                    <span x-show="conv.online" class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white rounded-full"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-sm text-slate-900 truncate" :class="conv.nao_lidas > 0 ? 'font-bold' : 'font-semibold'" x-text="conv.nome"></span>
                                        <span class="text-[11px] flex-shrink-0" :class="conv.nao_lidas > 0 ? 'text-blue-600 font-semibold' : 'text-slate-400'" x-text="conv.ultima_mensagem?.data || ''"></span>
                                    </div>
                                    <div class="flex items-center justify-between gap-2 mt-0.5">
                                        <p class="text-xs truncate" :class="conv.nao_lidas > 0 ? 'text-slate-700 font-medium' : 'text-slate-500'">
                                            <span x-show="conv.ultima_mensagem?.minha" class="text-slate-400">Você: </span><span x-text="(conv.ultima_mensagem?.conteudo || 'Sem mensagens').replace(/\*/g, '')"></span>
                                        </p>
                                        <span x-show="conv.nao_lidas > 0" class="min-w-[20px] h-5 px-1.5 bg-blue-600 text-white text-[11px] font-bold rounded-full flex items-center justify-center flex-shrink-0" x-text="conv.nao_lidas"></span>
                                    </div>
                                </div>
                            </button>
                        </template>
                    </div>

                    {{-- Pessoas --}}
                    <div x-show="tab === 'usuarios'" class="px-2 pb-2">
                        <div x-show="loading && usuarios.length === 0" class="p-8 text-center text-slate-400">
                            <svg class="w-6 h-6 mx-auto animate-spin mb-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <p class="text-xs">Carregando...</p>
                        </div>
                        <div x-show="!loading && usuarios.length === 0" class="py-8 text-center text-sm text-slate-500">Nenhuma pessoa encontrada</div>
                        <template x-for="usuario in usuarios" :key="usuario.id">
                            <button @click="iniciarConversa(usuario)" class="w-full p-2.5 flex items-center gap-3 rounded-xl hover:bg-slate-50 transition text-left">
                                <div class="relative flex-shrink-0">
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-semibold" :class="corAvatar(usuario.nome)"><span x-text="usuario.iniciais"></span></div>
                                    <span x-show="usuario.online" class="absolute bottom-0 right-0 w-3 h-3 bg-emerald-500 border-2 border-white rounded-full"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-semibold text-slate-900 truncate block" x-text="usuario.nome"></span>
                                    <p class="text-xs text-slate-500 truncate" x-text="usuario.tipo + (usuario.municipio ? ' · ' + usuario.municipio : '')"></p>
                                </div>
                                <span x-show="usuario.online" class="text-[10px] font-semibold text-emerald-600">online</span>
                            </button>
                        </template>
                    </div>
                </div>
            </div>

            {{-- ===================== ASSISTENTE ===================== --}}
            <div x-show="assistenteAberto" class="flex-1 flex flex-col overflow-hidden">
                <div x-ref="assistenteContainer" class="flex-1 overflow-y-auto px-3 py-4 space-y-3 bg-slate-50">
                    <div x-show="assistente.carregando && assistente.conversa.length === 0" class="text-center py-10 text-slate-400">
                        <svg class="w-6 h-6 mx-auto animate-spin mb-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <p class="text-xs">Verificando suas demandas...</p>
                    </div>

                    <template x-for="(msg, idx) in assistente.conversa" :key="idx">
                        <div :class="msg.autor === 'eu' ? 'flex justify-end' : 'flex justify-start gap-2'">
                            <div x-show="msg.autor === 'bot'" class="w-7 h-7 rounded-lg bg-gradient-to-br from-amber-400 to-orange-500 text-white flex items-center justify-center flex-shrink-0 mt-0.5 text-sm">🤖</div>

                            {{-- Minha "pergunta" --}}
                            <div x-show="msg.autor === 'eu'" class="max-w-[80%] px-3.5 py-2 rounded-2xl rounded-br-md bg-blue-600 text-white text-sm" x-text="msg.texto"></div>

                            {{-- Resposta do robô --}}
                            <div x-show="msg.autor === 'bot'" class="max-w-[88%] min-w-0 space-y-2">
                                <div x-show="msg.texto" class="px-3.5 py-2.5 rounded-2xl rounded-tl-md bg-white ring-1 ring-slate-200/80 text-sm text-slate-700 leading-relaxed shadow-sm" x-html="formatarMensagem(msg.texto)"></div>

                                <template x-if="msg.itens && msg.itens.length">
                                    <div class="rounded-2xl bg-white ring-1 ring-slate-200/80 shadow-sm overflow-hidden divide-y divide-slate-100">
                                        <template x-for="(item, j) in msg.itens" :key="j">
                                            <a :href="item.url || '#'" class="flex items-start gap-2.5 px-3 py-2.5 hover:bg-slate-50 transition">
                                                <span class="mt-1.5 w-2 h-2 rounded-full flex-shrink-0"
                                                      :class="{ atrasado: 'bg-red-500', vencendo: 'bg-amber-500', parado: 'bg-orange-400' }[item.nivel] || 'bg-slate-300'"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-[13px] font-semibold text-slate-900 truncate" x-text="(iconeCategoria(item.categoria) + ' ' + item.titulo)"></p>
                                                    <p x-show="item.subtitulo" class="text-xs text-slate-500 truncate" x-text="item.subtitulo"></p>
                                                    <p x-show="item.situacao" class="text-[11px] font-medium mt-0.5"
                                                       :class="{ atrasado: 'text-red-600', vencendo: 'text-amber-600', parado: 'text-orange-600' }[item.nivel] || 'text-slate-400'"
                                                       x-text="item.situacao"></p>
                                                </div>
                                                <svg class="w-4 h-4 text-slate-300 flex-shrink-0 mt-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            </a>
                                        </template>
                                        <a x-show="msg.restantes > 0" :href="assistente.url_todas" class="block px-3 py-2 text-xs font-semibold text-blue-600 hover:bg-blue-50 text-center"
                                           x-text="'+ ' + msg.restantes + ' item(ns) — ver todos no painel'"></a>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Respostas rápidas --}}
                <div class="px-3 py-2.5 border-t border-slate-100 bg-white flex-shrink-0">
                    <p class="text-[10px] font-semibold uppercase tracking-widest text-slate-400 mb-1.5">O que você quer ver?</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button x-show="assistente.resumo.atrasados > 0" @click="perguntarAssistente('atrasados')"
                                class="px-2.5 py-1 text-xs font-semibold rounded-full bg-red-50 text-red-700 ring-1 ring-red-200 hover:bg-red-100 transition"
                                x-text="'🔴 Atrasados (' + assistente.resumo.atrasados + ')'"></button>
                        <button x-show="assistente.resumo.vencendo > 0" @click="perguntarAssistente('vencendo')"
                                class="px-2.5 py-1 text-xs font-semibold rounded-full bg-amber-50 text-amber-700 ring-1 ring-amber-200 hover:bg-amber-100 transition"
                                x-text="'🟡 Vencendo (' + assistente.resumo.vencendo + ')'"></button>
                        <button x-show="assistente.resumo.parados > 0" @click="perguntarAssistente('parados')"
                                class="px-2.5 py-1 text-xs font-semibold rounded-full bg-orange-50 text-orange-700 ring-1 ring-orange-200 hover:bg-orange-100 transition"
                                x-text="'🟠 Paradas (' + assistente.resumo.parados + ')'"></button>
                        <template x-for="cat in assistente.categorias" :key="cat.chave">
                            <button @click="perguntarAssistente(cat.chave)"
                                    class="px-2.5 py-1 text-xs font-semibold rounded-full bg-slate-100 text-slate-700 hover:bg-slate-200 transition"
                                    x-text="cat.icone + ' ' + cat.titulo + ' (' + cat.total + ')'"></button>
                        </template>
                        <a :href="assistente.url_todas" class="px-2.5 py-1 text-xs font-semibold rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-200 hover:bg-blue-100 transition">📋 Abrir Minhas demandas</a>
                    </div>
                </div>
            </div>

            {{-- ===================== SUPORTE ===================== --}}
            <div x-show="suporteAberto" class="flex-1 flex flex-col overflow-hidden">
                <div class="flex-1 overflow-y-auto px-3 py-4 space-y-2 bg-slate-50">
                    <template x-for="msg in suporteMensagens" :key="msg.id">
                        <div class="flex justify-start">
                            <div class="bg-white max-w-[85%] px-3.5 py-2.5 rounded-2xl rounded-tl-md ring-1 ring-slate-200/80 shadow-sm">
                                <div x-show="msg.tipo === 'texto'" class="text-sm whitespace-pre-wrap break-words text-slate-700" x-html="formatarMensagem(msg.conteudo)"></div>
                                <img x-show="msg.tipo === 'imagem'" :src="msg.arquivo_url" class="max-w-full rounded-lg">
                                <a x-show="msg.tipo === 'arquivo'" :href="msg.arquivo_url" target="_blank" class="flex items-center gap-2 text-blue-600 hover:underline text-sm" x-text="'📎 ' + (msg.arquivo_nome || 'Arquivo')"></a>
                                <p class="text-[10px] text-slate-400 text-right mt-1" x-text="msg.data_completa || msg.data"></p>
                            </div>
                        </div>
                    </template>
                    <div x-show="suporteMensagens.length === 0" class="text-center text-slate-400 py-10"><p class="text-sm">Nenhum aviso do suporte</p></div>
                </div>
                <div class="px-3 py-2.5 bg-white border-t border-slate-100 text-center text-xs text-slate-400">Canal somente de avisos — não é possível responder.</div>
            </div>

            {{-- ===================== CONVERSA ===================== --}}
            <div x-show="conversaAtual && !suporteAberto && !assistenteAberto" class="flex-1 flex flex-col overflow-hidden">
                <div x-ref="mensagensContainer" class="flex-1 overflow-y-auto px-3 py-4 space-y-1.5 bg-slate-50">
                    <div x-show="loadingMensagens" class="text-center py-4">
                        <svg class="w-6 h-6 mx-auto text-slate-400 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    </div>
                    <div x-show="!loadingMensagens && mensagens.length === 0" class="text-center py-10">
                        <div class="w-12 h-12 mx-auto rounded-full flex items-center justify-center text-white font-semibold mb-2" :class="corAvatar(conversaAtual?.nome)"><span x-text="conversaAtual?.iniciais"></span></div>
                        <p class="text-sm text-slate-500">Envie a primeira mensagem para <span class="font-semibold" x-text="conversaAtual?.nome"></span>.</p>
                    </div>
                    <template x-for="(msg, idx) in mensagens" :key="msg.id">
                        <div>
                            <div x-show="rotuloDia(idx)" class="flex justify-center my-3">
                                <span class="px-2.5 py-0.5 rounded-full bg-white ring-1 ring-slate-200 text-[11px] font-medium text-slate-500" x-text="rotuloDia(idx)"></span>
                            </div>
                            <div :class="msg.minha ? 'flex justify-end' : 'flex justify-start'" class="group">
                                <div class="max-w-[80%] px-3.5 py-2 relative shadow-sm"
                                     :class="msg.minha ? 'bg-blue-600 text-white rounded-2xl rounded-br-md' : 'bg-white text-slate-800 ring-1 ring-slate-200/80 rounded-2xl rounded-bl-md'">
                                    <button x-show="msg.minha && msg.pode_deletar && !msg.deletada" @click="apagarMensagem(msg.id)"
                                            class="absolute -left-8 top-1/2 -translate-y-1/2 w-6 h-6 bg-white ring-1 ring-slate-200 text-slate-400 hover:text-red-600 rounded-full opacity-0 group-hover:opacity-100 transition flex items-center justify-center" title="Apagar para todos (até 30 min)">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                    <div x-show="msg.deletada" class="text-sm italic flex items-center gap-1" :class="msg.minha ? 'text-blue-100' : 'text-slate-400'">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                        Mensagem apagada
                                    </div>
                                    <div x-show="!msg.deletada">
                                        <div x-show="msg.tipo === 'texto'" class="text-sm whitespace-pre-wrap break-words" x-html="formatarMensagem(msg.conteudo)"></div>
                                        <img x-show="msg.tipo === 'imagem'" :src="msg.arquivo_url" class="max-w-full rounded-lg cursor-pointer" @click="window.open(msg.arquivo_url, '_blank')" @load="if (idx >= mensagens.length - 3) scrollToBottom()">
                                        <audio x-show="msg.tipo === 'audio'" :src="msg.arquivo_url" controls class="max-w-full"></audio>
                                        <a x-show="msg.tipo === 'arquivo'" :href="msg.arquivo_url" target="_blank"
                                           class="flex items-center gap-2 text-sm font-medium px-2 py-1.5 rounded-lg"
                                           :class="msg.minha ? 'bg-white/15 text-white hover:bg-white/25' : 'bg-slate-100 text-blue-700 hover:bg-slate-200'">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                            <span class="truncate" x-text="msg.arquivo_nome || 'Arquivo'"></span>
                                            <span class="text-[10px] opacity-70 flex-shrink-0" x-text="msg.arquivo_tamanho || ''"></span>
                                        </a>
                                    </div>
                                    <div class="flex items-center justify-end gap-1 mt-0.5">
                                        <span class="text-[10px]" :class="msg.minha ? 'text-blue-100' : 'text-slate-400'" x-text="msg.data"></span>
                                        <span x-show="msg.minha && !msg.deletada" class="flex items-center" :title="msg.lida ? 'Lida' : (msg.entregue ? 'Entregue' : 'Enviada')">
                                            <svg x-show="msg.lida || msg.entregue" class="w-4 h-4" :class="msg.lida ? 'text-sky-300' : 'text-blue-200'" viewBox="0 0 16 15" fill="currentColor"><path d="M15.01 3.316l-.478-.372a.365.365 0 0 0-.51.063L8.666 9.88a.32.32 0 0 1-.484.032l-.358-.325a.32.32 0 0 0-.484.032l-.378.48a.418.418 0 0 0 .036.54l1.32 1.267a.32.32 0 0 0 .484-.034l6.272-8.048a.366.366 0 0 0-.064-.512zm-4.1 0l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.032L1.892 7.77a.366.366 0 0 0-.516.005l-.423.433a.364.364 0 0 0 .006.514l3.255 3.185a.32.32 0 0 0 .484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/></svg>
                                            <svg x-show="!msg.lida && !msg.entregue" class="w-4 h-4 text-blue-200" viewBox="0 0 16 15" fill="currentColor"><path d="M10.91 3.316l-.478-.372a.365.365 0 0 0-.51.063L4.566 9.88a.32.32 0 0 1-.484.032L1.892 7.77a.366.366 0 0 0-.516.005l-.423.433a.364.364 0 0 0 .006.514l3.255 3.185a.32.32 0 0 0 .484-.033l6.272-8.048a.365.365 0 0 0-.063-.51z"/></svg>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Campo de mensagem --}}
                <div class="p-3 bg-white border-t border-slate-100 flex-shrink-0">
                    <form @submit.prevent="enviarMensagem()" class="flex items-end gap-2">
                        <label class="p-2 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-xl cursor-pointer transition" title="Anexar arquivo (até 10 MB)">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                            <input type="file" class="hidden" @change="enviarArquivo($event)" accept="image/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx">
                        </label>
                        <textarea x-ref="campoMensagem" x-model="novaMensagem" rows="1"
                                  @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); enviarMensagem(); }"
                                  @input="ajustarAlturaCampo()"
                                  placeholder="Escreva uma mensagem..."
                                  class="flex-1 resize-none max-h-32 px-3.5 py-2 text-sm bg-slate-100 border border-transparent rounded-xl focus:bg-white focus:border-blue-400 focus:ring-4 focus:ring-blue-500/10 transition"></textarea>
                        <button type="submit" :disabled="!novaMensagem.trim() || enviando"
                                class="p-2.5 bg-blue-600 text-white rounded-xl hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed transition" title="Enviar (Enter)">
                            <svg x-show="!enviando" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                            <svg x-show="enviando" class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        </button>
                    </form>
                    <p class="mt-1 text-[10px] text-slate-400 text-right">Enter envia · Shift+Enter quebra linha</p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    /* Robô de avisos */
    .robo-entrada { transition: all .45s cubic-bezier(.34, 1.56, .64, 1); }
    .robo-flutuar { animation: robo-flutuar 3s ease-in-out infinite; }
    .robo-olho { transform-box: fill-box; transform-origin: center; animation: robo-piscar 4s infinite; }
    .robo-luz { animation: robo-luz 1.6s ease-in-out infinite; }
    .robo-alerta .robo-luz { animation-duration: .7s; }
    .robo-braco { transform-box: view-box; transform-origin: 43px 51px; animation: robo-acenar 1.2s ease-in-out 3; }
    .robo-falando .robo-boca { transform-box: fill-box; transform-origin: center; animation: robo-falar .22s ease-in-out infinite alternate; }
    @keyframes robo-flutuar { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-5px); } }
    @keyframes robo-piscar { 0%, 90%, 100% { transform: scaleY(1); } 94% { transform: scaleY(.1); } }
    @keyframes robo-luz { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
    @keyframes robo-acenar { 0%, 100% { transform: rotate(0); } 50% { transform: rotate(-22deg); } }
    @keyframes robo-falar { from { transform: scaleY(1); } to { transform: scaleY(2.4); } }
    @media (prefers-reduced-motion: reduce) {
        .robo-flutuar, .robo-olho, .robo-luz, .robo-braco, .robo-falando .robo-boca { animation: none; }
    }
</style>

<script>
function chatInterno() {
    const USUARIO_ID = @js(auth('interno')->id());
    const CORES_AVATAR = ['bg-blue-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-rose-500', 'bg-cyan-600', 'bg-indigo-500', 'bg-teal-500', 'bg-fuchsia-500', 'bg-orange-500'];
    const ICONES_CATEGORIA = { os: '🧾', assinaturas: '✍️', rascunhos: '📝', exigencias: '📋', respostas: '📨', prazos: '⏳' };
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Robô de avisos
    const ROBO_PRIMEIRA_FALA_MS = 6000;       // primeira aparição após carregar a página
    const ROBO_INTERVALO_MS = 3 * 60 * 1000;  // volta a lembrar a cada 3 minutos
    const ROBO_TEMPO_NA_TELA_MS = 9000;       // quanto tempo a mensagem fica visível
    const ROBO_SILENCIO_MS = 30 * 60 * 1000;  // "x" no balão silencia por 30 minutos
    const CHAVE_ROBO_SILENCIO = 'robo_silenciado_ate_' + USUARIO_ID;

    return {
        isOpen: false,
        tab: 'conversas',
        conversas: [],
        usuarios: [],
        mensagens: [],
        conversaAtual: null,
        novaMensagem: '',
        totalNaoLidas: 0,
        usuariosOnline: 0,
        pollingInterval: null,
        ultimaMensagemId: 0,
        buscaUsuario: '',
        suporteAberto: false,
        suporteMensagens: [],
        suporteNaoLidos: 0,
        loading: false,
        loadingMensagens: false,
        enviando: false,
        cache: { conversas: null, conversasTime: 0 },
        documentVisible: true,

        // Assistente de Pendências
        assistenteAberto: false,
        assistenteInterval: null,

        // Robô de avisos (ao lado do botão do chat)
        robo: {
            visivel: false,
            balao: false,
            digitando: false,
            pausado: false,
            iniciado: false,
            texto: '',
            rotulo: '',
            item: null,
            fila: [],
            indice: 0,
            timerProximo: null,
            timerFechar: null,
            timerDigitar: null,
        },
        assistente: {
            carregando: false,
            carregado: false,
            saudacao: '',
            mensagem: '',
            resumo: { total: 0, atrasados: 0, vencendo: 0, parados: 0 },
            categorias: [],
            itens: [],
            atualizado_em: null,
            url_todas: '#',
            conversa: [],
        },

        // Formata texto com links clicáveis e *negrito* (HTML sempre escapado antes)
        formatarMensagem(texto) {
            if (!texto) return '';
            let html = texto
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
            html = html.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" class="underline underline-offset-2 break-all">$1</a>');
            html = html.replace(/\*([^*]+)\*/g, '<strong>$1</strong>');
            return html;
        },

        corAvatar(nome) {
            if (!nome) return CORES_AVATAR[0];
            let h = 0;
            for (const c of nome) h = (h * 31 + c.charCodeAt(0)) >>> 0;
            return CORES_AVATAR[h % CORES_AVATAR.length];
        },

        iconeCategoria(categoria) {
            return ICONES_CATEGORIA[categoria] || '•';
        },

        init() {
            this.carregarConversas();
            this.verificarNovas();
            this.startPolling();

            // Assistente: primeira verificação logo após carregar a página e depois a cada 5 minutos
            setTimeout(() => this.carregarAssistente(), 1500);
            this.assistenteInterval = setInterval(() => { if (this.documentVisible) this.carregarAssistente(); }, 5 * 60 * 1000);

            // Pausa polling quando aba não está visível
            window.addEventListener('abrir-assistente-pendencias', () => { this.abrirChat(); this.abrirAssistente(); });
            window.chatInternoDisponivel = true;

            document.addEventListener('visibilitychange', () => {
                this.documentVisible = !document.hidden;
                if (this.documentVisible) {
                    this.verificarNovas();
                    this.startPolling();
                } else {
                    this.stopPolling();
                }
            });
        },

        startPolling() {
            if (this.pollingInterval) return;
            // Polling mais frequente se chat aberto (3s), senão mais lento (5s)
            const interval = this.isOpen ? 3000 : 5000;
            this.pollingInterval = setInterval(() => this.verificarNovas(), interval);
        },

        stopPolling() {
            if (this.pollingInterval) {
                clearInterval(this.pollingInterval);
                this.pollingInterval = null;
            }
        },

        restartPolling() {
            this.stopPolling();
            if (this.documentVisible) {
                this.startPolling();
            }
        },

        // Notifica o usuário sobre nova mensagem
        notificarNovaMensagem() {
            try {
                const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj2a2teleVtSu+bw1HQQAGvt+vaOLQBM0uv7tlwAAKLs//+xMwAAsuv8/7I4AAC27Pv/sjkAALrr+f+1PAAAvur3/7k/AACq4OLqpT8AAH3P0dyPNAAAV7e9x3UrAAA7oqOwYCMAAC6RmZ1WHAAAJYWMj04XAAB/hIV8MQ4AAJmYl4oeAwAApaOfjwAAAAAA');
                audio.volume = 0.3;
                audio.play().catch(() => {});
            } catch (e) {}

            if (document.hidden) {
                const originalTitle = document.title;
                const blink = setInterval(() => {
                    document.title = document.title === '💬 Nova mensagem!' ? originalTitle : '💬 Nova mensagem!';
                }, 1000);
                const stopBlink = () => {
                    clearInterval(blink);
                    document.title = originalTitle;
                    document.removeEventListener('visibilitychange', stopBlink);
                };
                document.addEventListener('visibilitychange', stopBlink);
                setTimeout(() => { clearInterval(blink); document.title = originalTitle; }, 10000);
            }
        },

        toggleChat() {
            this.isOpen ? this.fecharChat() : this.abrirChat();
        },

        abrirChat() {
            if (this.robo.visivel) this.fecharRobo();
            this.isOpen = true;
            this.carregarConversas();
            if (this.usuarios.length === 0) this.carregarUsuarios();
            this.restartPolling();
        },

        fecharChat() {
            this.isOpen = false;
            this.restartPolling();
        },

        // ------------------------------------------------------------------
        // Assistente de Pendências
        // ------------------------------------------------------------------
        async carregarAssistente(forcar = false, reiniciarConversa = false) {
            if (this.assistente.carregando) return;
            this.assistente.carregando = true;
            try {
                const r = await fetch(`{{ route('admin.chat.assistente') }}${forcar ? '?atualizar=1' : ''}`, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const data = await r.json();
                Object.assign(this.assistente, {
                    saudacao: data.saudacao,
                    mensagem: data.mensagem,
                    resumo: data.resumo,
                    categorias: data.categorias || [],
                    itens: data.itens || [],
                    atualizado_em: data.atualizado_em,
                    url_todas: data.url_todas,
                    carregado: true,
                });
                this.prepararRobo();
                if (reiniciarConversa || (this.assistenteAberto && this.assistente.conversa.length === 0)) {
                    this.iniciarConversaAssistente();
                }
            } catch (e) {
                console.error('Assistente de pendências:', e);
            }
            this.assistente.carregando = false;
        },

        resumoAssistenteCurto() {
            if (!this.assistente.carregado) return 'Verificando suas demandas...';
            const { total, atrasados, vencendo } = this.assistente.resumo;
            if (total === 0) return 'Tudo em dia! Nenhuma pendência. 🎉';
            if (atrasados > 0) return `${atrasados} atrasada(s) · ${total} pendência(s) no total`;
            if (vencendo > 0) return `${vencendo} vencendo em breve · ${total} no total`;
            if (this.assistente.resumo.parados > 0) return `${this.assistente.resumo.parados} parada(s) · ${total} no total`;
            return `${total} pendência(s) para finalizar`;
        },

        abrirAssistente() {
            this.conversaAtual = null;
            this.suporteAberto = false;
            this.assistenteAberto = true;
            if (!this.assistente.carregado) {
                this.carregarAssistente(false, true);
            } else {
                this.iniciarConversaAssistente();
            }
        },

        iniciarConversaAssistente() {
            const a = this.assistente;
            const destaques = a.itens.filter(i => i.nivel !== 'normal');
            const conversa = [{ autor: 'bot', texto: a.saudacao + ' ' + a.mensagem }];
            if (destaques.length) {
                conversa.push({ autor: 'bot', texto: 'Estas precisam da sua atenção primeiro:', itens: destaques.slice(0, 6), restantes: Math.max(0, destaques.length - 6) });
            } else if (a.resumo.total > 0) {
                conversa.push({ autor: 'bot', texto: 'Escolha abaixo uma categoria para ver os itens. 👇' });
            }
            a.conversa = conversa;
            this.$nextTick(() => this.rolarAssistente(false));
        },

        perguntarAssistente(chave) {
            const a = this.assistente;
            let pergunta, itens, vazio;
            if (chave === 'atrasados') {
                pergunta = 'O que está atrasado?';
                itens = a.itens.filter(i => i.nivel === 'atrasado');
                vazio = 'Nada atrasado. 👏';
            } else if (chave === 'vencendo') {
                pergunta = 'O que está vencendo?';
                itens = a.itens.filter(i => i.nivel === 'vencendo');
                vazio = 'Nenhum prazo vencendo nos próximos dias.';
            } else if (chave === 'parados') {
                pergunta = 'O que está parado?';
                itens = a.itens.filter(i => i.nivel === 'parado');
                vazio = 'Nada parado. 👏';
            } else {
                const cat = a.categorias.find(c => c.chave === chave);
                pergunta = cat ? cat.titulo : chave;
                itens = a.itens.filter(i => i.categoria === chave);
                vazio = 'Nenhum item nessa categoria.';
            }
            const limite = 8;
            const texto = itens.length
                ? `Encontrei *${itens.length}* ${itens.length === 1 ? 'item' : 'itens'}` + (itens.some(i => i.nivel === 'atrasado') && chave !== 'atrasados' ? ', com atrasados no topo:' : ':')
                : vazio;
            a.conversa.push({ autor: 'eu', texto: pergunta });
            a.conversa.push({ autor: 'bot', texto, itens: itens.slice(0, limite), restantes: Math.max(0, itens.length - limite) });
            this.$nextTick(() => this.rolarAssistente(true));
        },

        rolarAssistente(suave) {
            const el = this.$refs.assistenteContainer;
            if (el) el.scrollTo({ top: el.scrollHeight, behavior: suave ? 'smooth' : 'auto' });
        },

        // ------------------------------------------------------------------
        // Robô de avisos: de tempos em tempos aparece, "fala" uma demanda e some
        // ------------------------------------------------------------------
        prepararRobo() {
            this.robo.fila = this.montarFalasRobo();
            if (!this.robo.iniciado) {
                this.robo.iniciado = true;
                this.agendarRobo(ROBO_PRIMEIRA_FALA_MS);
            }
        },

        montarFalasRobo() {
            const a = this.assistente;
            const nome = (a.saudacao || '').replace(/^(Bom dia|Boa tarde|Boa noite),\s*/, '').replace(/!$/, '');
            const plural = (n, s, p) => n === 1 ? s : p;
            const curto = (t, max = 40) => !t ? '' : (t.length > max ? t.slice(0, max - 1).trimEnd() + '…' : t);
            const atrasados = a.itens.filter(i => i.nivel === 'atrasado');
            const vencendo = a.itens.filter(i => i.nivel === 'vencendo');
            const parados = a.itens.filter(i => i.nivel === 'parado');
            const falas = [];

            if (atrasados.length) {
                falas.push({
                    rotulo: '⚠️ Demandas atrasadas',
                    texto: `Ei, ${nome}! Você tem *${atrasados.length} ${plural(atrasados.length, 'demanda atrasada', 'demandas atrasadas')}*. Vamos resolver? 💪`,
                    item: atrasados[0],
                });
                atrasados.slice(0, 3).forEach(i => falas.push({
                    rotulo: '⚠️ Atrasada',
                    texto: `*${i.titulo}*${i.subtitulo ? ' · ' + curto(i.subtitulo) : ''} — ${i.situacao || 'está atrasada'}.`,
                    item: i,
                }));
            }

            vencendo.slice(0, 2).forEach(i => falas.push({
                rotulo: '⏰ Prazo chegando',
                texto: `Fique de olho: *${i.titulo}* — ${i.situacao || 'vence em breve'}.`,
                item: i,
            }));

            const assinaturas = parados.filter(i => i.categoria === 'assinaturas');
            if (assinaturas.length) {
                falas.push({
                    rotulo: '✍️ Assinaturas paradas',
                    texto: `Tem *${assinaturas.length} ${plural(assinaturas.length, 'documento', 'documentos')}* esperando sua assinatura. O mais antigo: *${assinaturas[0].titulo}* — ${assinaturas[0].situacao}.`,
                    item: assinaturas[0],
                });
            }
            const rascunhos = parados.filter(i => i.categoria === 'rascunhos');
            if (rascunhos.length) {
                falas.push({
                    rotulo: '📝 Rascunhos parados',
                    texto: `Você tem *${rascunhos.length} ${plural(rascunhos.length, 'rascunho', 'rascunhos')}* sem finalizar. Que tal concluir *${rascunhos[0].titulo}*?`,
                    item: rascunhos[0],
                });
            }

            if (!falas.length && a.resumo.total > 0) {
                falas.push({
                    rotulo: '📋 Lembrete',
                    texto: `Você tem *${a.resumo.total} ${plural(a.resumo.total, 'pendência', 'pendências')}* para finalizar. Nenhuma atrasada, continue assim! 👏`,
                    item: a.itens[0],
                });
            }

            return falas;
        },

        agendarRobo(ms) {
            clearTimeout(this.robo.timerProximo);
            this.robo.timerProximo = setTimeout(() => this.falarRobo(), ms);
        },

        falarRobo() {
            if (!this.robo.fila.length) {
                this.agendarRobo(ROBO_INTERVALO_MS);
                return;
            }
            // Não interrompe: chat aberto, aba em segundo plano, resumo do dia na tela ou robô silenciado
            if (this.isOpen || document.hidden || window.alertaPendenciasModalAberto || this.roboSilenciado()) {
                this.agendarRobo(15000);
                return;
            }

            const fala = this.robo.fila[this.robo.indice % this.robo.fila.length];
            this.robo.indice++;
            Object.assign(this.robo, { item: fala.item, rotulo: fala.rotulo, texto: '', balao: false, visivel: true, pausado: false });
            setTimeout(() => {
                this.robo.balao = true;
                this.digitarRobo(fala.texto);
            }, 550);
        },

        // Efeito de digitação (sem os marcadores de negrito até terminar)
        digitarRobo(textoCompleto) {
            const plano = textoCompleto.replace(/\*/g, '');
            let pos = 0;
            this.robo.digitando = true;
            clearInterval(this.robo.timerDigitar);
            this.robo.timerDigitar = setInterval(() => {
                pos += 2;
                this.robo.texto = plano.slice(0, pos);
                if (pos >= plano.length) {
                    clearInterval(this.robo.timerDigitar);
                    this.robo.texto = textoCompleto;
                    this.robo.digitando = false;
                    this.agendarFechamentoRobo();
                }
            }, 28);
        },

        agendarFechamentoRobo() {
            clearTimeout(this.robo.timerFechar);
            this.robo.timerFechar = setTimeout(() => {
                if (this.robo.pausado) return this.agendarFechamentoRobo(); // mouse em cima: espera
                this.fecharRobo();
            }, ROBO_TEMPO_NA_TELA_MS);
        },

        fecharRobo() {
            clearInterval(this.robo.timerDigitar);
            clearTimeout(this.robo.timerFechar);
            this.robo.balao = false;
            this.robo.digitando = false;
            setTimeout(() => { this.robo.visivel = false; }, 200);
            this.agendarRobo(ROBO_INTERVALO_MS);
        },

        pausarRobo() { this.robo.pausado = true; },
        retomarRobo() { this.robo.pausado = false; },

        silenciarRobo() {
            try { localStorage.setItem(CHAVE_ROBO_SILENCIO, String(Date.now() + ROBO_SILENCIO_MS)); } catch (e) {}
            this.fecharRobo();
        },

        roboSilenciado() {
            try { return Number(localStorage.getItem(CHAVE_ROBO_SILENCIO) || 0) > Date.now(); } catch (e) { return false; }
        },

        clicarRobo() {
            this.abrirChat();
            this.abrirAssistente();
        },

        clicarMensagemRobo() {
            if (this.robo.digitando) return;
            if (this.robo.item?.url) {
                window.location.href = this.robo.item.url;
            } else {
                this.clicarRobo();
            }
        },

        // ------------------------------------------------------------------
        // Conversas
        // ------------------------------------------------------------------
        conversasFiltradas() {
            const termo = this.buscaUsuario.trim().toLowerCase();
            if (this.tab !== 'conversas' || !termo) return this.conversas;
            return this.conversas.filter(c => (c.nome || '').toLowerCase().includes(termo));
        },

        onBusca() {
            if (this.tab === 'usuarios') this.buscarUsuarios();
        },

        async carregarConversas() {
            const now = Date.now();
            if (this.cache.conversas && (now - this.cache.conversasTime) < 3000) return;
            this.loading = true;
            try {
                const r = await fetch('{{ route("admin.chat.conversas") }}');
                const data = await r.json();
                this.conversas = data;
                this.totalNaoLidas = data.reduce((t, c) => t + c.nao_lidas, 0);
                this.cache.conversas = data;
                this.cache.conversasTime = now;
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        async carregarUsuarios() {
            this.loading = true;
            try {
                const r = await fetch('{{ route("admin.chat.usuarios") }}');
                const data = await r.json();
                this.usuarios = data;
                this.usuariosOnline = data.filter(u => u.online).length;
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        async buscarUsuarios() {
            try {
                const url = this.buscaUsuario ? `{{ route("admin.chat.usuarios.buscar") }}?q=${encodeURIComponent(this.buscaUsuario)}` : '{{ route("admin.chat.usuarios") }}';
                const r = await fetch(url);
                this.usuarios = await r.json();
            } catch (e) { console.error(e); }
        },

        async abrirConversa(conv) {
            this.suporteAberto = false;
            this.assistenteAberto = false;
            this.conversaAtual = { id: conv.id, usuario_id: conv.usuario_id, nome: conv.nome, iniciais: conv.iniciais, tipo: conv.tipo, municipio: conv.municipio, online: conv.online };
            await this.carregarMensagens(conv.usuario_id);
        },

        async iniciarConversa(usuario) {
            this.suporteAberto = false;
            this.assistenteAberto = false;
            this.buscaUsuario = '';
            this.conversaAtual = { usuario_id: usuario.id, nome: usuario.nome, iniciais: usuario.iniciais, tipo: usuario.tipo, municipio: usuario.municipio, online: usuario.online };
            await this.carregarMensagens(usuario.id);
        },

        async carregarMensagens(usuarioId) {
            this.loadingMensagens = true;
            this.mensagens = [];
            try {
                const r = await fetch(`{{ url('/admin/chat/mensagens') }}/${usuarioId}`);
                const data = await r.json();
                this.mensagens = data.mensagens || [];
                if (this.conversaAtual) this.conversaAtual.id = data.conversa_id;
                if (this.mensagens.length > 0) this.ultimaMensagemId = this.mensagens[this.mensagens.length - 1].id;
                this.$nextTick(() => { this.scrollToBottom(); this.$refs.campoMensagem?.focus(); });
                this.cache.conversasTime = 0;
                this.carregarConversas();
            } catch (e) { console.error(e); }
            this.loadingMensagens = false;
        },

        // Separador de dia ("Hoje", "Ontem", dd/mm/aaaa) quando muda o dia entre mensagens
        rotuloDia(idx) {
            const dia = (m) => (m && m.data_completa ? m.data_completa.slice(0, 10) : this.hojeBr());
            const atual = dia(this.mensagens[idx]);
            if (idx > 0 && dia(this.mensagens[idx - 1]) === atual) return '';
            if (atual === this.hojeBr()) return 'Hoje';
            const ontem = new Date(); ontem.setDate(ontem.getDate() - 1);
            if (atual === ontem.toLocaleDateString('pt-BR')) return 'Ontem';
            return atual;
        },

        hojeBr() {
            return new Date().toLocaleDateString('pt-BR');
        },

        ajustarAlturaCampo() {
            const el = this.$refs.campoMensagem;
            if (!el) return;
            el.style.height = 'auto';
            el.style.height = Math.min(el.scrollHeight, 128) + 'px';
        },

        async abrirSuporte() {
            this.conversaAtual = null;
            this.assistenteAberto = false;
            this.suporteAberto = true;
            try {
                const r = await fetch('{{ route("admin.chat.suporte.mensagens") }}');
                const data = await r.json();
                this.suporteMensagens = data.mensagens || [];
                this.suporteNaoLidos = 0;
            } catch (e) { console.error(e); }
        },

        async verificarSuporteNaoLidos() {
            try {
                const r = await fetch('{{ route("admin.chat.suporte.nao-lidos") }}');
                const data = await r.json();
                this.suporteNaoLidos = data.nao_lidos || 0;
            } catch (e) { console.error(e); }
        },

        voltarLista() {
            this.conversaAtual = null;
            this.suporteAberto = false;
            this.assistenteAberto = false;
            this.mensagens = [];
            this.ultimaMensagemId = 0;
            this.carregarConversas();
            this.verificarSuporteNaoLidos();
        },

        async enviarMensagem() {
            if (!this.novaMensagem.trim() || !this.conversaAtual || this.enviando) return;
            const texto = this.novaMensagem.trim();
            this.novaMensagem = '';
            this.$nextTick(() => this.ajustarAlturaCampo());
            this.enviando = true;
            const tempId = 'temp_' + Date.now();
            const agora = new Date();
            const msgTemp = {
                id: tempId, conteudo: texto, tipo: 'texto', minha: true,
                data: agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }),
                data_completa: agora.toLocaleDateString('pt-BR'),
                lida: false, entregue: false, deletada: false, pode_deletar: false,
            };
            this.mensagens.push(msgTemp);
            this.$nextTick(() => this.scrollToBottom());
            try {
                const fd = new FormData();
                fd.append('usuario_id', this.conversaAtual.usuario_id);
                fd.append('conteudo', texto);
                const r = await fetch('{{ route("admin.chat.enviar") }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' }, body: fd });
                const data = await r.json();
                const idx = this.mensagens.findIndex(m => m.id === tempId);
                if (idx !== -1 && data.success && data.mensagem) {
                    this.mensagens[idx] = data.mensagem;
                    this.ultimaMensagemId = data.mensagem.id;
                } else if (idx !== -1) {
                    this.mensagens.splice(idx, 1);
                    this.novaMensagem = texto;
                    alert('Não foi possível enviar a mensagem.');
                }
            } catch (e) {
                console.error(e);
                this.mensagens = this.mensagens.filter(m => m.id !== tempId);
                this.novaMensagem = texto;
            }
            this.enviando = false;
        },

        async enviarArquivo(event) {
            const file = event.target.files[0];
            if (!file || !this.conversaAtual) return;
            if (file.size > 10 * 1024 * 1024) {
                alert('O arquivo deve ter no máximo 10 MB.');
                event.target.value = '';
                return;
            }
            this.enviando = true;
            try {
                const fd = new FormData();
                fd.append('usuario_id', this.conversaAtual.usuario_id);
                fd.append('arquivo', file);
                const r = await fetch('{{ route("admin.chat.enviar") }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' }, body: fd });
                const data = await r.json();
                if (data.success && data.mensagem) {
                    this.mensagens.push(data.mensagem);
                    this.ultimaMensagemId = data.mensagem.id;
                    this.$nextTick(() => this.scrollToBottom());
                } else {
                    alert('Não foi possível enviar o arquivo.');
                }
            } catch (e) { console.error(e); alert('Não foi possível enviar o arquivo.'); }
            this.enviando = false;
            event.target.value = '';
        },

        async apagarMensagem(msgId) {
            if (!confirm('Apagar esta mensagem para todos?')) return;
            try {
                const r = await fetch(`{{ url('/admin/chat/mensagem') }}/${msgId}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } });
                const data = await r.json();
                if (data.success) {
                    const idx = this.mensagens.findIndex(m => m.id === msgId);
                    if (idx !== -1) { this.mensagens[idx].deletada = true; this.mensagens[idx].conteudo = null; this.mensagens[idx].pode_deletar = false; }
                } else { alert(data.error || 'Erro ao apagar mensagem'); }
            } catch (e) { console.error(e); alert('Erro ao apagar mensagem'); }
        },

        async verificarNovas() {
            try {
                const params = new URLSearchParams({ ultima_id: this.ultimaMensagemId });

                if (this.isOpen && this.conversaAtual) {
                    params.append('usuario_id', this.conversaAtual.usuario_id);
                    const msgIds = this.mensagens.filter(m => !m.deletada && typeof m.id === 'number').slice(-50).map(m => m.id);
                    msgIds.forEach(id => params.append('msg_ids[]', id));
                }

                const r = await fetch(`{{ route("admin.chat.verificar-novas") }}?${params}`);
                const data = await r.json();

                const tinhaAntes = this.totalNaoLidas + this.suporteNaoLidos;
                this.totalNaoLidas = data.total_nao_lidas || 0;
                if (data.suporte_nao_lidos !== undefined) {
                    this.suporteNaoLidos = data.suporte_nao_lidos;
                }
                const temAgora = this.totalNaoLidas + this.suporteNaoLidos;
                if (temAgora > tinhaAntes && !this.isOpen) {
                    this.notificarNovaMensagem();
                }

                if (!this.isOpen) return;

                // Atualiza a lista de conversas quando chegam mensagens novas
                if (temAgora > tinhaAntes && !this.conversaAtual) {
                    this.cache.conversasTime = 0;
                    this.carregarConversas();
                }

                if (this.conversaAtual) {
                    if (data.outro_online !== undefined) this.conversaAtual.online = data.outro_online;

                    if (data.novas_mensagens && data.novas_mensagens.length > 0) {
                        for (const msg of data.novas_mensagens) {
                            if (!this.mensagens.find(m => m.id === msg.id)) this.mensagens.push(msg);
                        }
                        this.ultimaMensagemId = data.novas_mensagens[data.novas_mensagens.length - 1].id;
                        this.$nextTick(() => this.scrollToBottom());
                    }

                    if (data.mensagens_lidas && data.mensagens_lidas.length > 0) {
                        for (let i = 0; i < this.mensagens.length; i++) {
                            if (this.mensagens[i].minha && data.mensagens_lidas.includes(this.mensagens[i].id)) {
                                this.mensagens[i].lida = true;
                                this.mensagens[i].entregue = true;
                            }
                        }
                    }

                    if (data.mensagens_deletadas && data.mensagens_deletadas.length > 0) {
                        for (let i = 0; i < this.mensagens.length; i++) {
                            if (data.mensagens_deletadas.includes(this.mensagens[i].id) && !this.mensagens[i].deletada) {
                                this.mensagens[i].deletada = true;
                                this.mensagens[i].tipo = 'deletada';
                                this.mensagens[i].conteudo = null;
                                this.mensagens[i].arquivo_url = null;
                                this.mensagens[i].pode_deletar = false;
                            }
                        }
                    }
                }
            } catch (e) { /* silencioso */ }
        },

        // Rola até a última mensagem depois que a lista termina de renderizar
        scrollToBottom() {
            const rolar = () => {
                const container = this.$refs.mensagensContainer;
                if (container) container.scrollTop = container.scrollHeight;
            };
            this.$nextTick(() => {
                rolar();
                requestAnimationFrame(rolar);
                setTimeout(rolar, 150);
            });
        }
    };
}
</script>@endif
