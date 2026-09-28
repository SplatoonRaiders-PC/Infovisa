@php
    // Não interrompe quem está editando documentos
    $rotaAtualAlerta = (string) Route::currentRouteName();
    $exibirAlertaPendencias = auth('interno')->check()
        && !str_contains($rotaAtualAlerta, 'documentos.create')
        && !str_contains($rotaAtualAlerta, 'documentos.edit');
@endphp

@if($exibirAlertaPendencias)
{{-- Alerta de pendências: faixa fixa enquanto houver atraso + resumo obrigatório no primeiro acesso do dia --}}
<div x-data="alertaPendencias()" x-init="init()" x-cloak>

    {{-- ===================== FAIXA DE ALERTA ===================== --}}
    <div x-show="mostrarFaixa()" x-transition.opacity
         class="mb-5 rounded-2xl overflow-hidden shadow-sm ring-1"
         :class="critico() ? 'bg-red-50 ring-red-200' : 'bg-amber-50 ring-amber-200'">
        <div class="flex flex-col lg:flex-row lg:items-center gap-3 px-4 py-3">
            <div class="flex items-start gap-3 flex-1 min-w-0">
                <span class="relative flex-shrink-0 mt-0.5">
                    <span x-show="critico()" class="absolute inset-0 rounded-xl bg-red-400 animate-ping opacity-40"></span>
                    <span class="relative w-9 h-9 rounded-xl flex items-center justify-center text-white"
                          :class="critico() ? 'bg-red-600' : 'bg-amber-500'">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    </span>
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-bold" :class="critico() ? 'text-red-900' : 'text-amber-900'" x-text="tituloFaixa()"></p>
                    <p class="text-xs mt-0.5 truncate" :class="critico() ? 'text-red-700' : 'text-amber-800'" x-show="maisUrgente()">
                        <span class="font-semibold">Mais urgente:</span>
                        <span x-text="maisUrgente()?.titulo"></span>
                        <span x-show="maisUrgente()?.situacao" x-text="' — ' + maisUrgente()?.situacao"></span>
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
                <a :href="maisUrgente()?.url || dados.url_todas"
                   class="inline-flex items-center gap-1.5 px-3.5 py-2 text-xs font-bold text-white rounded-lg shadow-sm transition"
                   :class="critico() ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'">
                    Resolver agora
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                <button type="button" @click="expandido = !expandido"
                        class="inline-flex items-center gap-1 px-3 py-2 text-xs font-semibold rounded-lg bg-white ring-1 transition"
                        :class="critico() ? 'text-red-700 ring-red-200 hover:bg-red-100' : 'text-amber-800 ring-amber-200 hover:bg-amber-100'">
                    <span x-text="expandido ? 'Ocultar lista' : 'Ver lista (' + criticos().length + ')'"></span>
                    <svg class="w-3.5 h-3.5 transition-transform" :class="expandido && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </button>
                <button type="button" @click="adiar()" class="px-2.5 py-2 text-xs font-medium rounded-lg transition"
                        :class="critico() ? 'text-red-600 hover:bg-red-100' : 'text-amber-700 hover:bg-amber-100'"
                        title="O alerta volta em 1 hora enquanto houver pendências">
                    Lembrar em 1h
                </button>
            </div>
        </div>

        {{-- Lista expandida --}}
        <div x-show="expandido" x-transition.opacity class="border-t bg-white/70" :class="critico() ? 'border-red-200' : 'border-amber-200'">
            <ul class="divide-y divide-slate-100 max-h-72 overflow-y-auto">
                <template x-for="(item, i) in criticos()" :key="i">
                    <li class="flex items-center gap-3 px-4 py-2.5">
                        <span class="text-[10px] font-bold uppercase tracking-wide px-1.5 py-0.5 rounded flex-shrink-0 w-[70px] text-center"
                              :class="estiloNivel(item.nivel)" x-text="rotuloNivel(item.nivel)"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-slate-900 truncate" x-text="item.titulo"></p>
                            <p class="text-xs text-slate-500 truncate" x-text="[item.subtitulo, item.situacao].filter(Boolean).join(' · ')"></p>
                        </div>
                        <a :href="item.url || '#'" class="flex-shrink-0 px-3 py-1.5 text-xs font-bold text-white bg-slate-900 rounded-lg hover:bg-slate-700 transition">Resolver</a>
                    </li>
                </template>
            </ul>
            <div class="px-4 py-2 text-right border-t border-slate-100">
                <a :href="dados.url_todas" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Ver todas as minhas demandas →</a>
            </div>
        </div>
    </div>

    {{-- ===================== RESUMO DO DIA (obrigatório) ===================== --}}
    <template x-teleport="body">
        <div x-show="modalAberto" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
            <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm"></div>

            <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-2xl overflow-hidden flex flex-col max-h-[90vh]"
                 x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
                {{-- Cabeçalho --}}
                <div class="px-6 pt-6 pb-4" :class="critico() ? 'bg-gradient-to-br from-red-600 to-rose-600' : 'bg-gradient-to-br from-amber-500 to-orange-500'">
                    <div class="flex items-center gap-3 text-white">
                        <span class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
                        </span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-widest text-white/80">Pendências de hoje</p>
                            <h2 class="text-lg font-bold leading-tight" x-text="dados.saudacao"></h2>
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-white/90">Antes de continuar, confira o que precisa ser finalizado por você:</p>

                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <div class="rounded-xl bg-white/15 px-3 py-2 text-white">
                            <p class="text-2xl font-black" x-text="dados.resumo.atrasados"></p>
                            <p class="text-[11px] font-semibold text-white/80">Atrasadas</p>
                        </div>
                        <div class="rounded-xl bg-white/15 px-3 py-2 text-white">
                            <p class="text-2xl font-black" x-text="dados.resumo.vencendo"></p>
                            <p class="text-[11px] font-semibold text-white/80">Vencendo</p>
                        </div>
                        <div class="rounded-xl bg-white/15 px-3 py-2 text-white">
                            <p class="text-2xl font-black" x-text="dados.resumo.parados"></p>
                            <p class="text-[11px] font-semibold text-white/80" x-text="'Paradas +' + dados.resumo.dias_para_parado + ' dias'"></p>
                        </div>
                    </div>
                </div>

                {{-- Itens --}}
                <ul class="flex-1 overflow-y-auto divide-y divide-slate-100">
                    <template x-for="(item, i) in criticos().slice(0, 8)" :key="i">
                        <li class="flex items-center gap-3 px-6 py-3">
                            <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                                  :class="item.nivel === 'atrasado' ? 'bg-red-500' : (item.nivel === 'vencendo' ? 'bg-amber-500' : 'bg-orange-400')"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-900 truncate" x-text="item.titulo"></p>
                                <p class="text-xs font-medium truncate"
                                   :class="item.nivel === 'atrasado' ? 'text-red-600' : (item.nivel === 'vencendo' ? 'text-amber-600' : 'text-orange-600')"
                                   x-text="item.situacao || rotuloNivel(item.nivel)"></p>
                            </div>
                            <a :href="item.url || '#'" @click="marcarCiente()" class="flex-shrink-0 text-xs font-bold text-blue-600 hover:text-blue-800">Resolver →</a>
                        </li>
                    </template>
                    <li x-show="criticos().length > 8" class="px-6 py-2.5 text-center">
                        <a :href="dados.url_todas" @click="marcarCiente()" class="text-xs font-semibold text-blue-600 hover:text-blue-800"
                           x-text="'+ ' + (criticos().length - 8) + ' outras — ver todas as demandas'"></a>
                    </li>
                </ul>

                {{-- Ações --}}
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex flex-col-reverse sm:flex-row gap-2">
                    <a :href="dados.url_todas" @click="marcarCiente()"
                       class="flex-1 text-center px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white ring-1 ring-slate-200 rounded-xl hover:bg-slate-100 transition">
                        Abrir minhas demandas
                    </a>
                    <button type="button" @click="marcarCiente(); modalAberto = false"
                            class="flex-1 px-4 py-2.5 text-sm font-bold text-white rounded-xl transition"
                            :class="critico() ? 'bg-red-600 hover:bg-red-700' : 'bg-amber-600 hover:bg-amber-700'">
                        Estou ciente, vou resolver
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function alertaPendencias() {
    const USUARIO_ID = @js(auth('interno')->id());
    const OCULTAR_FAIXA = @js(request()->routeIs('admin.dashboard'));
    const CHAVE_CIENTE = 'pendencias_ciente_' + USUARIO_ID;
    const CHAVE_ADIADO = 'pendencias_adiado_ate_' + USUARIO_ID;
    const hoje = () => new Date().toLocaleDateString('sv-SE'); // AAAA-MM-DD no fuso local
    const ler = (chave) => { try { return JSON.parse(localStorage.getItem(chave) || 'null'); } catch (e) { return null; } };
    const gravar = (chave, valor) => { try { localStorage.setItem(chave, JSON.stringify(valor)); } catch (e) {} };

    return {
        carregado: false,
        expandido: false,
        modalAberto: false,
        adiadoAte: 0,
        tituloOriginal: document.title,
        dados: { saudacao: '', resumo: { total: 0, atrasados: 0, vencendo: 0, parados: 0, dias_para_parado: 5 }, itens: [], url_todas: '#' },

        init() {
            // O robô do chat espera o resumo do dia ser fechado antes de falar
            this.$watch('modalAberto', aberto => { window.alertaPendenciasModalAberto = aberto; });
            this.adiadoAte = Number(ler(CHAVE_ADIADO) || 0);
            this.carregar();
            setInterval(() => { if (!document.hidden) this.carregar(); }, 5 * 60 * 1000);
            // Atualiza quando o usuário volta para a aba (pode ter resolvido algo em outra aba)
            document.addEventListener('visibilitychange', () => { if (!document.hidden && this.carregado) this.carregar(); });
        },

        async carregar() {
            try {
                const r = await fetch(@js(route('admin.chat.assistente')), { headers: { 'Accept': 'application/json' } });
                if (!r.ok) return;
                this.dados = await r.json();
                this.carregado = true;
                this.atualizarTitulo();
                this.avaliarModal();
            } catch (e) { /* silencioso: o alerta não pode quebrar a página */ }
        },

        criticos() {
            return (this.dados.itens || []).filter(i => i.nivel !== 'normal');
        },

        critico() {
            return this.dados.resumo.atrasados > 0;
        },

        maisUrgente() {
            return this.criticos()[0] || null;
        },

        mostrarFaixa() {
            // No dashboard a faixa não aparece: o card "Minhas demandas" já mostra as pendências
            if (OCULTAR_FAIXA) return false;
            return this.carregado && this.criticos().length > 0 && Date.now() >= this.adiadoAte;
        },

        tituloFaixa() {
            const r = this.dados.resumo;
            const partes = [];
            if (r.atrasados) partes.push(r.atrasados + (r.atrasados === 1 ? ' demanda atrasada' : ' demandas atrasadas'));
            if (r.vencendo) partes.push(r.vencendo + (r.vencendo === 1 ? ' vencendo' : ' vencendo em breve'));
            if (r.parados) partes.push(r.parados + (r.parados === 1 ? ' parada' : ' paradas') + ' há mais de ' + r.dias_para_parado + ' dias');
            const lista = partes.length > 1 ? partes.slice(0, -1).join(', ') + ' e ' + partes[partes.length - 1] : partes[0];
            return (this.critico() ? 'Atenção! Você tem ' : 'Você tem ') + lista + ' para finalizar.';
        },

        rotuloNivel(nivel) {
            return { atrasado: 'Atrasada', vencendo: 'Vencendo', parado: 'Parada' }[nivel] || 'Pendente';
        },

        estiloNivel(nivel) {
            return { atrasado: 'bg-red-100 text-red-700', vencendo: 'bg-amber-100 text-amber-700', parado: 'bg-orange-100 text-orange-700' }[nivel] || 'bg-slate-100 text-slate-600';
        },

        adiar() {
            this.adiadoAte = Date.now() + 60 * 60 * 1000;
            gravar(CHAVE_ADIADO, this.adiadoAte);
            this.expandido = false;
        },

        // Resumo obrigatório: 1x por dia, ou de novo se aumentarem as atrasadas
        avaliarModal() {
            const r = this.dados.resumo;
            if (this.criticos().length === 0) return;
            const ciente = ler(CHAVE_CIENTE);
            const jaCiente = ciente && ciente.dia === hoje() && r.atrasados <= (ciente.atrasados ?? 0);
            if (!jaCiente) this.modalAberto = true;
        },

        marcarCiente() {
            gravar(CHAVE_CIENTE, { dia: hoje(), atrasados: this.dados.resumo.atrasados });
        },

        // Mostra o número de atrasadas na aba do navegador
        atualizarTitulo() {
            const base = this.tituloOriginal.replace(/^\(\d+\)\s*⚠\s*/, '');
            const n = this.dados.resumo.atrasados;
            document.title = n > 0 ? `(${n}) ⚠ ${base}` : base;
        },
    };
}
</script>
@endif
