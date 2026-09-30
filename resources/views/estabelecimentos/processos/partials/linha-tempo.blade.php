{{-- Modal "Tempo por etapa": quanto tempo levou cada etapa do processo e quanto tempo ficou em cada setor --}}
<div x-data="linhaTempoProcesso(@js(route('admin.estabelecimentos.processos.linha-tempo', [$estabelecimento->id, $processo->id])))"
     @abrir-linha-tempo.window="abrir()" @keydown.escape.window="aberto = false">
    <template x-teleport="body">
        <div x-show="aberto" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
            <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" @click="aberto = false"></div>

            <div class="relative w-full max-w-3xl max-h-[90vh] bg-white rounded-2xl shadow-2xl flex flex-col overflow-hidden">
                {{-- Cabeçalho --}}
                <div class="px-6 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <span class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <div>
                            <h3 class="text-base font-bold text-slate-900">Tempo por etapa <span class="text-slate-400 font-medium" x-text="dados ? '· ' + dados.numero : ''"></span></h3>
                            <p class="text-xs text-slate-500" x-show="dados">
                                <span x-text="dados?.em_andamento ? 'Aberto há ' + dados?.total : 'Durou ' + dados?.total + ' até o arquivamento'"></span>
                                <template x-if="dados?.parado">
                                    <span class="ml-1 px-1.5 py-0.5 rounded bg-red-50 text-red-700 font-semibold" x-text="'parado por ' + dados.parado"></span>
                                </template>
                            </p>
                        </div>
                    </div>
                    <button @click="aberto = false" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg" title="Fechar">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="flex-1 overflow-y-auto px-6 py-5 space-y-7">
                    <div x-show="carregando" class="py-12 text-center text-slate-400">
                        <svg class="w-6 h-6 mx-auto animate-spin mb-2" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                        <p class="text-xs">Calculando os tempos...</p>
                    </div>
                    <p x-show="erro" class="py-10 text-center text-sm text-red-600">Não foi possível calcular a linha do tempo deste processo.</p>

                    <template x-if="dados && !carregando">
                        <div class="space-y-7">
                            {{-- 1. Etapas --}}
                            <section>
                                <h4 class="text-sm font-bold text-slate-900">Etapas do processo</h4>
                                <p class="text-xs text-slate-500 mb-3">Quanto tempo levou de um marco até o outro.</p>

                                {{-- Barra proporcional --}}
                                <div class="flex h-3 rounded-full overflow-hidden bg-slate-100 mb-2">
                                    <template x-for="(etapa, i) in dados.etapas" :key="i">
                                        <div class="h-full" :class="cor(etapa.de).barra" :style="`width: ${Math.max(etapa.percentual, 1.5)}%`" :title="etapa.titulo + ': ' + etapa.duracao"></div>
                                    </template>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-1 mb-4">
                                    <template x-for="(etapa, i) in dados.etapas" :key="i">
                                        <span class="inline-flex items-center gap-1.5 text-[11px] text-slate-600">
                                            <span class="w-2 h-2 rounded-full" :class="cor(etapa.de).barra"></span>
                                            <span x-text="etapa.titulo"></span>
                                            <strong class="text-slate-900" x-text="etapa.duracao"></strong>
                                        </span>
                                    </template>
                                </div>

                                {{-- Linha do tempo vertical --}}
                                <ol class="relative">
                                    <template x-for="(marco, i) in dados.marcos" :key="marco.chave">
                                        <li class="relative pl-10">
                                            <span class="absolute left-0 top-0 w-8 h-8 rounded-full bg-white ring-2 flex items-center justify-center text-sm" :class="cor(marco.chave).anel" x-text="marco.icone"></span>
                                            <div class="pb-1 pt-1">
                                                <p class="text-sm font-semibold text-slate-900" x-text="marco.titulo"></p>
                                                <p class="text-xs text-slate-500" x-text="marco.data"></p>
                                            </div>
                                            {{-- Duração até o próximo marco --}}
                                            <template x-if="dados.etapas[i]">
                                                <div class="relative -ml-10 pl-10 py-3">
                                                    <span class="absolute left-[15px] top-0 bottom-0 w-0.5" :class="cor(dados.etapas[i].de).barra"></span>
                                                    <div class="inline-flex flex-wrap items-center gap-2 px-3 py-1.5 rounded-lg text-xs" :class="cor(dados.etapas[i].de).fundo">
                                                        <span class="font-bold" x-text="'⏳ ' + dados.etapas[i].duracao"></span>
                                                        <span class="text-slate-600" x-text="quem(dados.etapas[i].de)"></span>
                                                        <span x-show="dados.etapas[i].em_andamento" class="px-1.5 py-0.5 rounded bg-white/70 text-[10px] font-bold uppercase tracking-wide">em andamento</span>
                                                    </div>
                                                </div>
                                            </template>
                                        </li>
                                    </template>
                                    <li x-show="dados.em_andamento" class="relative pl-10">
                                        <span class="absolute left-0 top-0 w-8 h-8 rounded-full bg-slate-900 text-white flex items-center justify-center text-[10px] font-bold">HOJE</span>
                                        <p class="pt-1.5 text-sm font-semibold text-slate-900">Situação atual</p>
                                    </li>
                                </ol>

                                <div x-show="dados.faltando.length" class="mt-4 flex flex-wrap items-center gap-2">
                                    <span class="text-xs text-slate-500">Ainda não aconteceu:</span>
                                    <template x-for="f in dados.faltando" :key="f">
                                        <span class="px-2 py-0.5 rounded-full text-[11px] font-medium text-slate-500 border border-dashed border-slate-300" x-text="f"></span>
                                    </template>
                                </div>
                            </section>

                            {{-- 2. Tempo em cada setor --}}
                            <section>
                                <h4 class="text-sm font-bold text-slate-900">Tempo em cada setor</h4>
                                <p class="text-xs text-slate-500 mb-3">Soma do tempo que o processo ficou com cada setor (fora os períodos arquivado).</p>
                                <p x-show="!dados.setores.length" class="text-xs text-slate-400">Sem registros de tramitação.</p>
                                <div class="space-y-2.5">
                                    <template x-for="(s, i) in dados.setores" :key="i">
                                        <div>
                                            <div class="flex items-center justify-between gap-3 text-xs mb-1">
                                                <span class="font-semibold text-slate-800 truncate">
                                                    <span x-text="s.nome"></span>
                                                    <span x-show="s.atual" class="ml-1 px-1.5 py-0.5 rounded bg-blue-50 text-blue-700 text-[10px] font-bold">AGORA</span>
                                                    <span x-show="s.passagens > 1" class="ml-1 text-slate-400 font-normal" x-text="s.passagens + ' passagens'"></span>
                                                </span>
                                                <span class="font-bold text-slate-900 whitespace-nowrap" x-text="s.duracao"></span>
                                            </div>
                                            <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                                                <div class="h-full rounded-full" :class="s.atual ? 'bg-blue-500' : 'bg-slate-400'" :style="`width: ${Math.max(s.percentual, 2)}%`"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </section>

                            {{-- 3. Trajeto --}}
                            <section x-show="dados.trajeto.length">
                                <h4 class="text-sm font-bold text-slate-900">Caminho da tramitação</h4>
                                <p class="text-xs text-slate-500 mb-3">Por onde o processo passou, na ordem.</p>
                                <div class="flex flex-wrap items-stretch gap-2">
                                    <template x-for="(t, i) in dados.trajeto" :key="i">
                                        <div class="flex items-center gap-2">
                                            <div class="px-3 py-2 rounded-xl ring-1 text-xs min-w-[140px]"
                                                 :class="t.arquivado ? 'bg-slate-50 ring-slate-200 text-slate-500' : (t.atual ? 'bg-blue-50 ring-blue-200' : 'bg-white ring-slate-200')">
                                                <p class="font-semibold text-slate-800" x-text="t.nome"></p>
                                                <p class="font-bold" :class="t.atual ? 'text-blue-700' : 'text-slate-900'" x-text="t.duracao + (t.atual ? ' · atual' : '')"></p>
                                                <p class="text-[10px] text-slate-400" x-text="t.periodo"></p>
                                                <p x-show="t.responsaveis.length" class="text-[10px] text-slate-500 truncate max-w-[180px]" :title="t.responsaveis.join(', ')" x-text="'👤 ' + t.responsaveis.join(', ')"></p>
                                            </div>
                                            <svg x-show="i < dados.trajeto.length - 1" class="w-4 h-4 text-slate-300 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        </div>
                                    </template>
                                </div>
                            </section>
                        </div>
                    </template>
                </div>

                <div class="px-6 py-3 border-t border-slate-100 bg-slate-50 text-[11px] text-slate-500">
                    Calculado a partir da abertura do processo, dos documentos enviados pela empresa, da aprovação dos documentos obrigatórios, do Alvará Sanitário assinado e do histórico de tramitação.
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function linhaTempoProcesso(url) {
    const CORES = {
        abertura: { barra: 'bg-amber-400', fundo: 'bg-amber-50 text-amber-900', anel: 'ring-amber-300' },
        primeiro_envio: { barra: 'bg-blue-500', fundo: 'bg-blue-50 text-blue-900', anel: 'ring-blue-300' },
        doc_completa: { barra: 'bg-violet-500', fundo: 'bg-violet-50 text-violet-900', anel: 'ring-violet-300' },
        alvara: { barra: 'bg-emerald-500', fundo: 'bg-emerald-50 text-emerald-900', anel: 'ring-emerald-300' },
        arquivamento: { barra: 'bg-slate-400', fundo: 'bg-slate-100 text-slate-700', anel: 'ring-slate-300' },
    };
    const QUEM = {
        abertura: 'aguardando a empresa enviar os documentos',
        primeiro_envio: 'envio, análise e correção dos documentos',
        doc_completa: 'análise técnica / inspeção da vigilância',
        alvara: 'depois do alvará',
    };

    return {
        aberto: false,
        carregando: false,
        erro: false,
        dados: null,

        cor(chave) { return CORES[chave] || CORES.arquivamento; },
        quem(chave) { return QUEM[chave] || ''; },

        async abrir() {
            this.aberto = true;
            if (this.dados || this.carregando) return;
            this.carregando = true;
            this.erro = false;
            try {
                const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                this.dados = await r.json();
            } catch (e) {
                console.error('Linha do tempo:', e);
                this.erro = true;
            }
            this.carregando = false;
        },
    };
}
</script>
