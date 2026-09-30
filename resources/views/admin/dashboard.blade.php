@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
@php
    $isGestorOuAdmin = auth('interno')->user()->isGestor() || auth('interno')->user()->isAdmin();
    $docsAtrasados = 0;
    // Prazo de 5 dias aplica-se APENAS a processos de licenciamento
    foreach($documentos_pendentes_aprovacao ?? [] as $doc) {
        if ($doc->processo && $doc->processo->tipo === 'licenciamento') {
            if ((int) $doc->created_at->diffInDays(now()) > 5) $docsAtrasados++;
        }
    }
    foreach($respostas_pendentes_aprovacao ?? [] as $resp) {
        if ($resp->documentoDigital && $resp->documentoDigital->processo && $resp->documentoDigital->processo->tipo === 'licenciamento') {
            if ((int) $resp->created_at->diffInDays(now()) > 5) $docsAtrasados++;
        }
    }
@endphp

@php
    $countAvisos = (isset($avisos_sistema) ? $avisos_sistema->count() : 0)
        + ((($stats['estabelecimentos_pendentes'] ?? 0) > 0) ? 1 : 0)
        + ((($alertas_cnae_total ?? 0) > 0) ? 1 : 0);
    $countAcompanhamento = (isset($processos_acompanhados) ? count($processos_acompanhados) : 0);
    $mostraAvisos = $countAvisos > 0 || auth('interno')->user()->isGestor() || auth('interno')->user()->isAdmin();
    $mostraAcompanhamento = $countAcompanhamento > 0 || (isset($aniversariantes_mes) && $aniversariantes_mes->count() > 0);

    // Saudação dinâmica conforme o horário
    $horaAtual = now()->hour;
    $saudacao = $horaAtual < 12 ? 'Bom dia' : ($horaAtual < 18 ? 'Boa tarde' : 'Boa noite');

    // Contadores para o aviso do boneco (apenas pendências DO usuário logado)
    $usuarioLogado = auth('interno')->user();
    $pendAssinaturas = $stats['documentos_pendentes_assinatura'] ?? 0;
    $pendRascunhos = count($documentos_rascunho_pendentes ?? []);
    // Processos sob responsabilidade (abertos ou parados)
    $pendProcessos = \App\Models\Processo::where('responsavel_atual_id', $usuarioLogado->id)
        ->whereIn('status', ['aberto', 'parado'])
        ->with(['estabelecimento', 'tipoProcesso'])
        ->get()
        ->filter(function ($processo) use ($usuarioLogado) {
            try {
                return $processo->pertenceAoEscopoDoUsuario($usuarioLogado);
            } catch (\Exception $e) {
                return false;
            }
        })
        ->count();
    // Respostas: apenas de documentos que o usuário assinou e estão pendentes de análise
    $pendRespostas = \App\Models\DocumentoResposta::where('status', 'pendente')
        ->whereHas('documentoDigital', function ($q) {
            $q->whereHas('processo');
        })
        ->whereHas('documentoDigital.assinaturas', function ($q) use ($usuarioLogado) {
            $q->where('usuario_interno_id', $usuarioLogado->id)
              ->where('status', 'assinado');
        })
        ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.processo.tipoProcesso'])
        ->get()
        ->filter(function ($resposta) use ($usuarioLogado) {
            try {
                $processo = $resposta->documentoDigital?->processo;
                return $processo && $processo->pertenceAoEscopoDoUsuario($usuarioLogado);
            } catch (\Exception $e) {
                return false;
            }
        })
        ->count();
    // OS: apenas onde o usuário tem atividades pendentes
    $pendOS = collect($ordens_servico_andamento ?? [])->filter(function ($os) use ($usuarioLogado) {
        return count($os->getAtividadesPendentesParaTecnico($usuarioLogado->id)) > 0;
    })->count();
    $pendExigencias = $stats['exigencias_colaborativas'] ?? 0;
    // OS pendentes de assinatura do gestor
    $pendAssinaturasOS = \App\Models\OrdemServico::where('gestor_assinatura_id', $usuarioLogado->id)
        ->whereNull('gestor_assinado_em')
        ->whereNotIn('status', ['cancelada'])
        ->get()
        ->filter(function ($os) use ($usuarioLogado) {
            if ($usuarioLogado->isAdmin()) {
                return true;
            }

            if ($usuarioLogado->isEstadual()) {
                return ($os->competencia ?? 'estadual') !== 'municipal';
            }

            return $usuarioLogado->isMunicipal()
                && $usuarioLogado->municipio_id
                && $os->competencia === 'municipal'
                && (int) $os->municipio_id === (int) $usuarioLogado->municipio_id;
        })
        ->count();
    $totalPendencias = $pendAssinaturas + $pendRascunhos + $pendProcessos + $pendRespostas + $pendOS + $pendAssinaturasOS + $pendExigencias;
@endphp

@php
    $primeiroNome = explode(' ', trim(auth('interno')->user()->nome))[0];
    // Chips do resumo de pendências no topo (mesmos contadores do aviso anterior)
    $chipsPendencias = [
        ['valor' => $pendAssinaturas, 'texto' => 'assinatura(s)', 'icon' => 'M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z'],
        ['valor' => $pendRascunhos, 'texto' => 'rascunho(s)', 'icon' => 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z'],
        ['valor' => $pendProcessos, 'texto' => 'processo(s)', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ['valor' => $pendRespostas, 'texto' => 'análise(s) de resposta', 'icon' => 'M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6'],
        ['valor' => $pendOS, 'texto' => 'OS', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['valor' => $pendExigencias, 'texto' => 'exigência(s)', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z'],
    ];
    $spinner = '<svg class="animate-spin h-5 w-5 mx-auto" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>';
    // Estilos compartilhados dos cards
    $cardTabBase = 'flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition whitespace-nowrap';
    $cardTabOff = 'text-slate-500 hover:text-slate-800 hover:bg-white/60';
@endphp

<div class="space-y-5" x-data="{ tab: localStorage.getItem('dashboardTab') || 'trabalho' }" x-init="$watch('tab', v => localStorage.setItem('dashboardTab', v))">
    {{-- Modal de Data de Nascimento (se não preenchida) --}}
    @if(!auth('interno')->user()->data_nascimento)
    <div x-data="{ open: true }" x-show="open" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            {{-- Overlay --}}
            <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity"></div>

            {{-- Modal --}}
            <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
                <form action="{{ route('admin.perfil.atualizar-nascimento') }}" method="POST">
                    @csrf
                    <div class="bg-white px-6 pt-7 pb-4">
                        <div class="text-center">
                            <div class="mx-auto flex items-center justify-center h-14 w-14 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 shadow-lg shadow-blue-500/30 mb-4">
                                <svg class="h-7 w-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                            <h3 class="text-lg font-semibold text-slate-900" id="modal-title">
                                Complete seu cadastro
                            </h3>
                            <p class="text-sm text-slate-500 mt-2">
                                Por favor, informe sua data de nascimento para continuar.
                            </p>
                        </div>

                        <div class="mt-5">
                            <label for="data_nascimento_modal" class="block text-sm font-medium text-slate-700 mb-2">
                                Data de Nascimento <span class="text-red-500">*</span>
                            </label>
                            <input type="date"
                                   id="data_nascimento_modal"
                                   name="data_nascimento"
                                   required
                                   max="{{ date('Y-m-d') }}"
                                   class="w-full px-4 py-3 border border-slate-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-center text-lg">
                        </div>
                    </div>

                    <div class="bg-slate-50 px-6 py-4">
                        <button type="submit"
                                class="w-full px-4 py-3 bg-blue-600 text-white font-semibold rounded-xl hover:bg-blue-700 transition flex items-center justify-center gap-2 shadow-sm">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Salvar e Continuar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- ============================ --}}
    {{-- HERO: saudação + resumo de pendências --}}
    {{-- ============================ --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 via-indigo-600 to-violet-600 text-white shadow-lg shadow-indigo-500/20">
        {{-- Elementos decorativos --}}
        <div class="pointer-events-none absolute -top-16 -right-16 w-64 h-64 rounded-full bg-white/10 blur-2xl"></div>
        <div class="pointer-events-none absolute -bottom-20 right-40 w-56 h-56 rounded-full bg-fuchsia-400/20 blur-3xl"></div>
        <svg class="pointer-events-none absolute right-0 top-0 h-full opacity-[0.07]" viewBox="0 0 200 200" fill="none" aria-hidden="true">
            <defs><pattern id="hero-grid" width="20" height="20" patternUnits="userSpaceOnUse"><circle cx="2" cy="2" r="1.5" fill="white"/></pattern></defs>
            <rect width="200" height="200" fill="url(#hero-grid)"/>
        </svg>

        <div class="relative flex flex-col md:flex-row md:items-center gap-3 px-4 py-3">
            <div class="flex items-center gap-3 flex-1 min-w-0">
                <div class="relative flex-shrink-0 hidden sm:block">
                    <div class="w-10 h-10 rounded-xl bg-white/15 ring-1 ring-white/25 backdrop-blur flex items-center justify-center">
                        <span class="text-lg">🧑‍💼</span>
                    </div>
                    @if($totalPendencias > 0)
                    <span class="absolute -top-1.5 -right-1.5 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 ring-2 ring-indigo-600 flex items-center justify-center text-[9px] font-bold">{{ $totalPendencias }}</span>
                    @endif
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <h2 class="text-base sm:text-lg font-bold tracking-tight truncate">{{ $saudacao }}, {{ $primeiroNome }}! 👋</h2>
                        <span class="text-[11px] font-medium text-white/70">{{ ucfirst(now()->locale('pt_BR')->isoFormat('dddd, D [de] MMMM')) }}</span>
                    </div>
                    <div class="flex flex-wrap items-center gap-1.5 mt-1 text-xs text-white/80">
                        @if($totalPendencias > 0)
                            <span>Você tem <span class="font-semibold text-white">{{ $totalPendencias }} {{ $totalPendencias == 1 ? 'pendência' : 'pendências' }}</span>:</span>
                            @foreach($chipsPendencias as $chip)
                                @continue($chip['valor'] <= 0)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-white/15 ring-1 ring-white/20 text-[11px] font-medium text-white">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $chip['icon'] }}"/></svg>
                                    <span class="font-bold">{{ $chip['valor'] }}</span> {{ $chip['texto'] }}
                                </span>
                            @endforeach
                        @else
                            <span>Tudo em dia por aqui. Nenhuma pendência sua no momento. ✨</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-shrink-0">
                @if($totalPendencias > 0)
                <a href="{{ route('admin.minhas-pendencias') }}"
                   class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white text-indigo-700 text-xs font-semibold rounded-lg shadow-sm hover:shadow-md transition-all">
                    Ver minhas pendências
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
                </a>
                @endif
            </div>
        </div>
    </div>

    {{-- Aniversariantes do Dia (fora das abas) --}}
    @if(isset($aniversariantes_mes) && $aniversariantes_mes->count() > 0)
    @php
        $hojeDiaMesBanner = now()->format('d/m');
        $aniversariantesHojeBanner = $aniversariantes_mes->filter(function($anv) use ($hojeDiaMesBanner) {
            return (bool)($anv->eh_hoje ?? false) || (!empty($anv->dia_aniversario) && $anv->dia_aniversario === $hojeDiaMesBanner);
        });
    @endphp
    @if($aniversariantesHojeBanner->count() > 0)
    <div class="relative overflow-hidden bg-gradient-to-r from-pink-50 via-rose-50 to-orange-50 rounded-2xl border border-pink-200/70 p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-pink-500 to-rose-500 shadow-md shadow-pink-500/30 flex items-center justify-center flex-shrink-0">
            <span class="text-white text-lg">🎂</span>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-pink-500">Aniversariantes de hoje</p>
            <p class="text-sm font-semibold text-pink-900">
                🎉 {{ $aniversariantesHojeBanner->map(fn($a) => \Str::words($a->nome, 2, ''))->implode(', ') }} faz{{ $aniversariantesHojeBanner->count() > 1 ? 'em' : '' }} aniversário hoje!
            </p>
        </div>
    </div>
    @endif
    @endif

    {{-- ============================ --}}
    {{-- BARRA DE ABAS --}}
    {{-- ============================ --}}
    <div class="flex items-center justify-between gap-3">
        <div class="inline-flex items-center gap-1 p-1 bg-white rounded-xl border border-slate-200/80 shadow-sm max-w-full overflow-x-auto scrollbar-thin">
            <button type="button" @click="tab = 'trabalho'"
                :class="tab === 'trabalho' ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50'"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Meu Painel
                <span x-show="$store.dashboard.trabalhoTotal > 0" :class="tab === 'trabalho' ? 'bg-white/20 text-white' : 'bg-blue-100 text-blue-700'" class="text-[10px] min-w-[20px] text-center px-1.5 py-0.5 rounded-full font-bold" x-text="$store.dashboard.trabalhoTotal"></span>
            </button>

            @if($mostraAvisos)
            <button type="button" @click="tab = 'avisos'"
                :class="tab === 'avisos' ? 'bg-amber-500 text-white shadow-md shadow-amber-500/25' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50'"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                Avisos
                <span x-show="$store.dashboard.avisosTotal > 0" :class="tab === 'avisos' ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-700'" class="text-[10px] min-w-[20px] text-center px-1.5 py-0.5 rounded-full font-bold" x-text="$store.dashboard.avisosTotal"></span>
            </button>
            @endif

            @if($mostraAcompanhamento)
            <button type="button" @click="tab = 'acompanhamento'"
                :class="tab === 'acompanhamento' ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25' : 'text-slate-500 hover:text-slate-800 hover:bg-slate-50'"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold transition whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                Acompanhamento
                <span :class="tab === 'acompanhamento' ? 'bg-white/20 text-white' : 'bg-indigo-100 text-indigo-700'" class="text-[10px] min-w-[20px] text-center px-1.5 py-0.5 rounded-full font-bold">{{ $countAcompanhamento }}</span>
            </button>
            @endif
        </div>
    </div>

    {{-- ============================ --}}
    {{-- ABA: AVISOS --}}
    {{-- ============================ --}}
    <div x-show="tab === 'avisos'" x-cloak class="space-y-3">

    {{-- Avisos do Sistema --}}
    @if(isset($avisos_sistema) && $avisos_sistema->count() > 0)
    <div class="space-y-2">
        @foreach($avisos_sistema as $aviso)
        <div class="flex items-start gap-3 p-4 rounded-2xl border shadow-sm {{ $aviso->tipo_color }}">
            <div class="w-9 h-9 rounded-xl bg-white/70 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $aviso->tipo_icone }}"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold">{{ $aviso->titulo }}</p>
                <p class="text-xs mt-0.5 opacity-80 leading-relaxed">{{ $aviso->mensagem }}</p>
                @if($aviso->link)
                <a href="{{ $aviso->link }}" target="_blank" class="inline-flex items-center gap-1 text-xs font-medium mt-1.5 underline underline-offset-2 hover:opacity-80">
                    {{ $aviso->link }}
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                    </svg>
                </a>
                @endif
            </div>
        </div>
        @endforeach
    </div>
    @endif

    {{-- Card: Atividades alteradas no CNPJ (Receita) — respeita a competência do usuário --}}
    @if(($alertas_cnae_total ?? 0) > 0)
    <div x-data="{ aberto: {{ ($alertas_cnae_mudam_competencia ?? 0) > 0 ? 'true' : 'false' }} }" class="bg-white rounded-2xl border {{ ($alertas_cnae_mudam_competencia ?? 0) > 0 ? 'border-red-200/80' : 'border-amber-200/80' }} shadow-sm overflow-hidden">
        <button type="button" @click="aberto = !aberto"
                class="w-full flex items-center gap-3 px-4 py-3 bg-gradient-to-r {{ ($alertas_cnae_mudam_competencia ?? 0) > 0 ? 'from-red-50 hover:from-red-100/70' : 'from-amber-50 hover:from-amber-100/70' }} to-white transition group text-left">
            <div class="w-9 h-9 rounded-xl {{ ($alertas_cnae_mudam_competencia ?? 0) > 0 ? 'bg-red-500 shadow-red-500/25' : 'bg-amber-500 shadow-amber-500/25' }} shadow-md flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-slate-900">Atividades alteradas no CNPJ</p>
                <p class="text-xs text-slate-500">
                    Estabelecimentos cujo CNPJ na Receita mudou de CNAE — revise as atividades
                    @if(($alertas_cnae_mudam_competencia ?? 0) > 0)
                        · <strong class="text-red-600">{{ $alertas_cnae_mudam_competencia }} {{ $alertas_cnae_mudam_competencia == 1 ? 'muda' : 'mudam' }} de competência</strong>
                    @endif
                </p>
            </div>
            <span class="text-xs min-w-[26px] text-center px-2 py-1 {{ ($alertas_cnae_mudam_competencia ?? 0) > 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700' }} rounded-full font-bold">{{ $alertas_cnae_total }}</span>
            <svg class="w-4 h-4 text-slate-400 group-hover:text-slate-600 transition-transform" :class="aberto ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
        <div x-show="aberto" x-cloak class="border-t border-slate-100">
            <div class="divide-y divide-slate-100 max-h-[380px] overflow-y-auto">
                @foreach($alertas_cnae as $alerta)
                    @include('admin.dashboard.partials.alerta-cnae-item', ['alerta' => $alerta])
                @endforeach
            </div>
            <a href="{{ route('admin.dashboard.atividades-alteradas') }}" class="flex items-center justify-center gap-1 px-4 py-2.5 text-xs font-semibold text-blue-700 bg-slate-50 hover:bg-blue-50 border-t border-slate-100 transition">
                Ver todos ({{ $alertas_cnae_total }})
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>
    </div>
    @endif

    {{-- Card de OSs Vencidas (apenas para gestores e admins) --}}
    @if(auth('interno')->user()->isGestor() || auth('interno')->user()->isAdmin())
    <div x-data="ordensServicoVencidas()" x-show="ordens.length > 0" x-cloak class="bg-white rounded-2xl border border-red-200/80 shadow-sm overflow-hidden">
        <button type="button"
                @click="aberto = !aberto"
                class="w-full flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-red-50 to-white hover:from-red-100/70 transition group text-left">
            <div class="w-9 h-9 rounded-xl bg-red-500 shadow-md shadow-red-500/25 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-red-900">OS Atrasadas</p>
                <p class="text-xs text-red-600/80">+15 dias sem encerramento</p>
            </div>
            <span class="text-xs min-w-[26px] text-center px-2 py-1 bg-red-100 text-red-700 rounded-full font-bold" x-text="ordens.length"></span>
            <svg class="w-4 h-4 text-red-400 group-hover:text-red-600 transition-transform" :class="aberto ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>

        <div x-show="aberto" class="border-t border-red-100">
            <div class="divide-y divide-slate-100 max-h-[320px] overflow-y-auto scrollbar-thin scrollbar-thumb-red-500 scrollbar-track-red-100">
                <template x-for="os in ordens" :key="os.id">
                    <a :href="os.url" class="flex items-center gap-3 px-4 py-3 hover:bg-red-50/50 transition">
                        <div class="w-8 h-8 rounded-lg bg-red-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                                <span x-text="'OS #' + os.numero"></span>
                                <span class="text-[10px] px-1.5 py-0.5 bg-red-100 text-red-700 rounded-full font-bold" x-text="os.dias_atraso + 'd'"></span>
                            </p>
                            <p class="text-xs text-slate-500 truncate" x-text="os.estabelecimento"></p>
                            <p class="text-xs text-slate-400 mt-0.5 truncate" x-text="os.tecnicos.length > 0 ? os.tecnicos.join(', ') : 'Sem técnico'"></p>
                        </div>
                        <span class="text-xs text-slate-400" x-text="os.data_fim"></span>
                    </a>
                </template>
            </div>
        </div>
    </div>
    @endif

    {{-- Card de Respostas com Análise ATRASADA (gestores e admins) --}}
    @if(auth('interno')->user()->isGestor() || auth('interno')->user()->isAdmin())
    <div x-data="respostasAtrasadasAnalise()" x-show="respostas.length > 0" x-cloak class="bg-white rounded-2xl border border-orange-200/80 shadow-sm overflow-hidden">
        <button type="button"
                @click="aberto = !aberto"
                class="w-full flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-orange-50 to-white hover:from-orange-100/70 transition group text-left">
            <div class="w-9 h-9 rounded-xl bg-orange-500 shadow-md shadow-orange-500/25 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                </svg>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-semibold text-orange-900">Respostas Atrasadas para Analisar</p>
                <p class="text-xs text-orange-600/80">Técnicos não analisaram dentro do prazo</p>
            </div>
            <span class="text-xs min-w-[26px] text-center px-2 py-1 bg-orange-100 text-orange-700 rounded-full font-bold" x-text="respostas.length"></span>
            <svg class="w-4 h-4 text-orange-400 group-hover:text-orange-600 transition-transform" :class="aberto ? 'rotate-90' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </button>

        <div x-show="aberto" class="border-t border-orange-100">
            <div class="divide-y divide-slate-100 max-h-[320px] overflow-y-auto">
                <template x-for="r in respostas" :key="r.id">
                    <a :href="r.url" class="flex items-center gap-3 px-4 py-3 hover:bg-orange-50/50 transition">
                        <div class="w-8 h-8 rounded-lg bg-orange-50 flex items-center justify-center flex-shrink-0">
                            <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                            </svg>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-900 flex items-center gap-2 flex-wrap">
                                <span x-text="r.tipo_documento"></span>
                                <template x-if="r.processo_numero">
                                    <span class="text-[11px] font-normal text-slate-500" x-text="'#' + r.processo_numero"></span>
                                </template>
                                <span class="text-[10px] px-1.5 py-0.5 bg-orange-100 text-orange-700 rounded-full font-bold" x-text="r.dias_atraso + 'd atraso'"></span>
                            </p>
                            <p class="text-xs text-slate-500 truncate" x-text="r.estabelecimento"></p>
                            <p class="text-[11px] text-slate-400 mt-0.5 truncate">
                                <svg class="inline w-3 h-3 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span x-text="r.tecnicos.length > 0 ? r.tecnicos.join(', ') : 'Sem técnico atribuído'"></span>
                            </p>
                        </div>
                        <div class="text-right flex-shrink-0">
                            <p class="text-[10px] text-slate-400">Protocolado</p>
                            <p class="text-xs text-slate-600" x-text="r.data_resposta"></p>
                            <p class="text-[10px] text-orange-600 mt-0.5">Limite: <span x-text="r.data_limite_analise"></span></p>
                        </div>
                    </a>
                </template>
            </div>
        </div>
    </div>
    @endif

    @if(!$mostraAvisos)
    <div class="bg-white rounded-2xl border border-slate-200/80 p-10 text-center shadow-sm">
        <div class="w-14 h-14 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
            <svg class="w-7 h-7 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        </div>
        <p class="text-sm font-semibold text-slate-600">Sem avisos ou pendências</p>
    </div>
    @endif
    </div>
    {{-- /ABA: AVISOS --}}

    {{-- ============================ --}}
    {{-- ABA: TRABALHO (Suas Demandas) --}}
    {{-- ============================ --}}
    <div x-show="tab === 'trabalho'" x-cloak class="space-y-4">

    {{-- Alerta: Cadastros Pendentes (também exibido em Meu Painel) --}}
    @if(($stats['estabelecimentos_pendentes'] ?? 0) > 0)
    <a id="tour-cadastros-pendentes" href="{{ route('admin.estabelecimentos.pendentes') }}" class="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-amber-50 to-white border border-amber-200/80 rounded-2xl shadow-sm hover:shadow-md hover:border-amber-300 transition group">
        <div class="w-9 h-9 rounded-xl bg-amber-500 shadow-md shadow-amber-500/25 flex items-center justify-center flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-sm font-semibold text-amber-900">{{ $stats['estabelecimentos_pendentes'] }} cadastro(s) aguardando aprovação</p>
            <p class="text-xs text-amber-700/70">Clique para revisar os estabelecimentos</p>
        </div>
        <span class="hidden sm:inline-flex items-center gap-1 text-xs font-semibold text-amber-700 group-hover:text-amber-900 transition">
            Revisar
            <svg class="w-4 h-4 group-hover:translate-x-0.5 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </span>
    </a>
    @endif

    {{-- Alerta: Solicitações de Unidade Móvel pendentes --}}
    @if(isset($solicitacoes_unidade_movel) && $solicitacoes_unidade_movel->count() > 0)
    <div class="bg-white border border-fuchsia-200/80 rounded-2xl shadow-sm overflow-hidden">
        <div class="flex items-center gap-3 px-4 py-3 bg-gradient-to-r from-fuchsia-50 to-white border-b border-fuchsia-100">
            <div class="w-9 h-9 rounded-xl bg-fuchsia-600 shadow-md shadow-fuchsia-600/25 flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div class="flex-1">
                <span class="text-sm font-semibold text-fuchsia-900">{{ $solicitacoes_unidade_movel->count() }} solicitação(ões) de Unidade Móvel aguardando aprovação</span>
            </div>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($solicitacoes_unidade_movel as $solicitacao)
            <a href="{{ route('admin.estabelecimentos.show', $solicitacao->id) }}"
               class="flex items-center gap-3 px-4 py-3 hover:bg-fuchsia-50/60 transition group">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-900 truncate">
                        {{ $solicitacao->nome_fantasia ?: $solicitacao->nome_razao_social }}
                    </p>
                    <p class="text-xs text-fuchsia-700">
                        {{ $solicitacao->tipo_unidade_movel ?? 'Unidade Móvel' }}
                        @if($solicitacao->municipiosAtuacao->count() > 0)
                            · {{ $solicitacao->municipiosAtuacao->count() }} município(s) de atuação
                        @endif
                    </p>
                </div>
                <span class="text-xs font-semibold text-fuchsia-600 group-hover:text-fuchsia-800 transition whitespace-nowrap">Revisar →</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Layout Principal --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">

        {{-- Coluna 1: PARA MIM --}}
        <div class="space-y-4 {{ $isGestorOuAdmin ? 'lg:col-span-6' : 'lg:col-span-7' }}" x-data="{ cardTab1: 'os' }">
        <div id="tour-minhas-tarefas" class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden" x-data="tarefasPaginadas()">
            <div class="px-4 pt-4 pb-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-[15px] font-semibold text-slate-900 truncate">Minhas demandas</h3>
                        <p class="text-xs text-slate-400 truncate">Tarefas atribuídas a você</p>
                    </div>
                </div>
                <a href="{{ route('admin.dashboard.todas-tarefas') }}" class="flex-shrink-0 text-xs font-semibold text-blue-600 hover:text-blue-800 hover:bg-blue-50 px-2.5 py-1.5 rounded-lg transition">Ver todos →</a>
            </div>

            {{-- Abas internas do card --}}
            <div class="px-3 pb-3 border-b border-slate-100">
            <div class="flex flex-wrap items-stretch gap-1 p-1 bg-slate-100/80 rounded-xl">
                <button type="button" @click="cardTab1 = 'os'"
                    :class="cardTab1 === 'os' ? 'bg-white text-blue-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    OS
                    <span class="text-[10px] px-1.5 rounded-full bg-blue-100 text-blue-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'os').length || '0'"></span>
                </button>
                <button type="button" @click="cardTab1 = 'processos'"
                    :class="cardTab1 === 'processos' ? 'bg-white text-indigo-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Processos
                    <span x-show="$store.dashboard.processosMeuDireto > 0" class="text-[10px] px-1.5 rounded-full bg-indigo-100 text-indigo-700 font-bold" x-text="$store.dashboard.processosMeuDireto"></span>
                </button>
                <button type="button" @click="cardTab1 = 'assinatura'"
                    :class="cardTab1 === 'assinatura' ? 'bg-white text-amber-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    Assinar
                    <span x-show="tarefas.filter(t => t.tipo === 'assinatura' || (t.tipo === 'os' && t.aguardando_assinatura_gestor)).length > 0" class="text-[10px] px-1.5 rounded-full bg-amber-100 text-amber-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'assinatura' || (t.tipo === 'os' && t.aguardando_assinatura_gestor)).length"></span>
                </button>
                <button type="button" @click="cardTab1 = 'rascunho'"
                    :class="cardTab1 === 'rascunho' ? 'bg-white text-purple-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Rascunhos
                    <span x-show="tarefas.filter(t => t.tipo === 'rascunho' || t.tipo === 'rascunho_lote').length > 0" class="text-[10px] px-1.5 rounded-full bg-purple-100 text-purple-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'rascunho' || t.tipo === 'rascunho_lote').length"></span>
                </button>
                {{-- NOVA ABA: Analisar Resposta (respostas de documentos que o usuário assinou) --}}
                <button type="button" @click="cardTab1 = 'analisar_resposta'"
                    :class="cardTab1 === 'analisar_resposta' ? 'bg-white text-emerald-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                    Analisar Resposta
                    <span x-show="tarefas.filter(t => t.tipo === 'resposta' && t.assinou_documento).length > 0" class="text-[10px] px-1.5 rounded-full bg-emerald-100 text-emerald-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'resposta' && t.assinou_documento).length"></span>
                </button>
                <button type="button" @click="cardTab1 = 'exigencia'"
                    :class="cardTab1 === 'exigencia' ? 'bg-white text-indigo-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/></svg>
                    Exigências
                    <span x-show="tarefas.filter(t => t.tipo === 'exigencia').length > 0" class="text-[10px] px-1.5 rounded-full bg-indigo-100 text-indigo-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'exigencia').length"></span>
                </button>
            </div>
            </div>

            {{-- Aba Exigências (partes compartilhadas comigo) --}}
            <div x-show="cardTab1 === 'exigencia'" x-cloak class="divide-y divide-slate-100 min-h-[120px] max-h-[510px] overflow-y-auto">
                <template x-if="tarefas.filter(t => t.tipo === 'exigencia').length === 0">
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-600">Nenhuma exigência compartilhada</p>
                        <p class="text-xs text-slate-400 mt-1">Quando alguém atribuir uma área para você, ela aparecerá aqui.</p>
                    </div>
                </template>
                <template x-if="tarefas.filter(t => t.tipo === 'exigencia').length > 0">
                    <div class="divide-y divide-slate-100">
                        <div class="px-4 py-2 bg-slate-50/70">
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                                Exigências para elaborar
                            </span>
                        </div>
                        <template x-for="t in tarefas.filter(t => t.tipo === 'exigencia')" :key="'exigencia-' + t.id">
                            <a :href="t.url" class="group flex items-start gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/30' : ''">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : 'bg-indigo-50'">
                                    <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-500' : 'text-indigo-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2Z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-indigo-700 transition" x-text="t.titulo"></p>
                                    <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                    <template x-if="t.prazo_interno">
                                        <p class="text-[10px] mt-0.5 truncate" :class="t.atrasado ? 'text-red-500 font-medium' : 'text-indigo-600'">
                                            Prazo interno: <span x-text="t.prazo_interno"></span>
                                        </p>
                                    </template>
                                </div>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>

            <div x-show="cardTab1 === 'os'" x-cloak class="divide-y divide-slate-100 min-h-[120px] max-h-[510px] overflow-y-auto">
                <template x-if="loading">
                    <div class="p-8 text-center text-blue-300">{!! $spinner !!}</div>
                </template>
                <template x-if="!loading && tarefas.filter(t => t.tipo === 'os').length > 0">
                    <div>
                        {{-- Ordens de Serviço --}}
                        <template x-if="tarefas.filter(t => t.tipo === 'os').length > 0">
                            <div class="divide-y divide-slate-100">
                                <div class="px-4 py-2 bg-slate-50/70">
                                    <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>
                                        Ordens de Serviço
                                    </span>
                                </div>
                                <template x-for="t in tarefas.filter(t => t.tipo === 'os')" :key="'os-' + t.id">
                                    <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/40' : (t.em_finalizacao ? 'bg-amber-50/30' : '')">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : (t.em_finalizacao ? 'bg-amber-100' : 'bg-blue-50')">
                                            <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-600' : (t.em_finalizacao ? 'text-amber-600' : 'text-blue-600')" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-blue-700 transition" x-text="t.titulo"></p>
                                            <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                            <template x-if="t.em_finalizacao || t.atrasado">
                                                <p class="text-[10px] font-medium truncate flex items-center gap-0.5 mt-0.5" :class="t.atrasado ? 'text-red-500' : 'text-amber-600'">
                                                    <svg class="w-2.5 h-2.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <span x-text="t.atrasado ? 'Prazo de finalização expirado!' : 'Prazo p/ finalizar até ' + t.prazo_finalizacao_formatado"></span>
                                                </p>
                                            </template>
                                            <template x-if="!t.em_finalizacao && !t.atrasado && t.data_fim_formatada">
                                                <p class="text-[10px] text-slate-400 truncate mt-0.5">
                                                    Encerramento: <span x-text="t.data_fim_formatada"></span> • Finalizar em até 15 dias após
                                                </p>
                                            </template>
                                        </div>
                                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                                    </a>
                                </template>
                            </div>
                        </template>

                    </div>
                </template>
                <template x-if="!loading && tarefas.filter(t => t.tipo === 'os').length === 0">
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-600">Tudo em dia</p>
                        <p class="text-xs text-slate-400 mt-1">Nenhuma demanda pendente</p>
                    </div>
                </template>
            </div>

            {{-- Processos atribuídos a mim --}}
            <div x-show="cardTab1 === 'processos'" x-cloak x-data="processosAtribuidos('meu_direto')">
                <div class="px-4 py-2 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between gap-2">
                    <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5 min-w-0">
                        <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 flex-shrink-0"></span>
                        <span class="truncate">Processos sob minha responsabilidade</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-indigo-100 text-indigo-700 font-bold" x-text="totalMeuDireto"></span>
                    </span>
                    <a href="{{ route('admin.dashboard.processos-responsabilidade') }}" class="text-[11px] text-indigo-600 hover:text-indigo-800 font-semibold transition whitespace-nowrap">Ver todos →</a>
                </div>
                <div class="divide-y divide-slate-100 max-h-[160px] overflow-y-auto">
                    <template x-if="loading">
                        <div class="p-4 text-center text-slate-300">{!! $spinner !!}</div>
                    </template>
                    <template x-if="!loading && processos.length > 0">
                        <div class="divide-y divide-slate-100">
                            <template x-for="p in processos" :key="'meu-proc-' + p.id">
                                <a :href="p.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="p.prazo && p.prazo.vencido ? 'bg-red-50/50' : (p.prazo && p.prazo.proximo ? 'bg-amber-50/30' : '')">
                                    <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[13px] font-semibold text-slate-800 flex items-center gap-1 flex-wrap group-hover:text-indigo-700 transition">
                                            <span x-text="p.numero_processo"></span>
                                            <template x-if="p.docs_total > 0">
                                                <span class="text-[9px] px-1 py-0.5 rounded font-medium" :class="p.docs_enviados >= p.docs_total ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'" x-text="p.docs_enviados + '/' + p.docs_total"></span>
                                            </template>
                                            <template x-if="p.prazo">
                                                <span class="text-[9px] px-1.5 py-0.5 rounded-full font-medium flex items-center gap-0.5" :class="p.prazo.vencido ? 'bg-red-100 text-red-700' : (p.prazo.proximo ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700')">
                                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <span x-text="'Prazo: ' + (p.prazo.vencido ? 'Vencido' : (Math.abs(p.prazo.dias_restantes) + 'd'))"></span>
                                                </span>
                                            </template>
                                        </p>
                                        <p class="text-[11px] text-slate-400 truncate" x-text="p.estabelecimento"></p>
                                        <template x-if="p.recebido_em_humano">
                                            <p class="text-[10px] text-sky-700 truncate mt-0.5" :title="p.recebido_em">
                                                Recebido em <span x-text="p.recebido_em"></span> (<span x-text="p.recebido_em_humano"></span>)
                                            </p>
                                        </template>
                                        <template x-if="!p.recebido_em_humano && p.aguardando_ciencia">
                                            <p class="text-[10px] text-amber-600 truncate mt-0.5" :title="p.tramitado_em">
                                                Tramitado em <span x-text="p.tramitado_em"></span> (aguardando ciência)
                                            </p>
                                        </template>
                                        <template x-if="p.motivo_atribuicao">
                                            <p class="text-[10px] text-indigo-600 mt-0.5 line-clamp-2" :title="p.motivo_atribuicao">
                                                Motivo da atribuição: <span x-text="p.motivo_atribuicao"></span>
                                            </p>
                                        </template>
                                    </div>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="getStatusClass(p.status)" x-text="p.status_nome"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                    <template x-if="!loading && processos.length === 0">
                        <div class="p-5 text-center text-xs text-slate-400">Nenhum processo atribuído</div>
                    </template>
                </div>
                <template x-if="lastPage > 1">
                    <div class="px-4 py-2 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-[11px] text-slate-400">Página <span x-text="currentPage"></span> de <span x-text="lastPage"></span></span>
                        <div class="flex gap-1">
                            <button @click="prevPage()" :disabled="currentPage <= 1" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
                            <button @click="nextPage()" :disabled="currentPage >= lastPage" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Aba Assinar (minhas demandas de assinatura) --}}
            <div x-show="cardTab1 === 'assinatura'" x-cloak class="divide-y divide-slate-100 min-h-[120px] max-h-[510px] overflow-y-auto">
                @php $tarefasAssinarTotal = $pendAssinaturasOS; @endphp
                <template x-if="tarefas.filter(t => t.tipo === 'assinatura' || (t.tipo === 'os' && t.aguardando_assinatura_gestor)).length === 0">
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-600">Tudo em dia</p>
                        <p class="text-xs text-slate-400 mt-1">Nenhum documento ou OS pendente de assinatura</p>
                    </div>
                </template>
                <template x-if="tarefas.filter(t => t.tipo === 'assinatura' || (t.tipo === 'os' && t.aguardando_assinatura_gestor)).length > 0">
                    <div>
                        {{-- Documentos digitais pendentes de assinatura --}}
                        <template x-if="tarefas.filter(t => t.tipo === 'assinatura').length > 0">
                            <div class="divide-y divide-slate-100">
                                <div class="px-4 py-2 bg-slate-50/70">
                                    <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        Documentos Pendentes
                                    </span>
                                </div>
                                <template x-for="t in tarefas.filter(t => t.tipo === 'assinatura')" :key="'ass-doc-' + t.id">
                                    <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition">
                                        <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-amber-700 transition" x-text="t.titulo"></p>
                                            <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                        </div>
                                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700" x-text="t.is_lote ? 'Lote' : 'Assinar'"></span>
                                    </a>
                                </template>
                            </div>
                        </template>

                        {{-- OS Pendentes de Assinatura do Gestor --}}
                        <template x-if="tarefas.filter(t => t.tipo === 'os' && t.aguardando_assinatura_gestor).length > 0">
                            <div class="divide-y divide-slate-100">
                                <div class="px-4 py-2 bg-slate-50/70">
                                    <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                        <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                                        Ordens de Serviço para Assinar
                                    </span>
                                </div>
                                <template x-for="t in tarefas.filter(t => t.tipo === 'os' && t.aguardando_assinatura_gestor)" :key="'ass-os-' + t.id">
                                    <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition">
                                        <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-purple-700 transition" x-text="t.titulo"></p>
                                            <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                        </div>
                                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-100 text-purple-700">Assinar OS</span>
                                    </a>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Aba Rascunhos (meus rascunhos) --}}
            <div x-show="cardTab1 === 'rascunho'" x-cloak class="divide-y divide-slate-100 min-h-[120px] max-h-[510px] overflow-y-auto">
                <template x-if="tarefas.filter(t => t.tipo === 'rascunho' || t.tipo === 'rascunho_lote').length === 0">
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-600">Sem rascunhos</p>
                        <p class="text-xs text-slate-400 mt-1">Nenhum documento em rascunho</p>
                    </div>
                </template>
                <template x-if="tarefas.filter(t => t.tipo === 'rascunho' || t.tipo === 'rascunho_lote').length > 0">
                    <div class="divide-y divide-slate-100">
                        <div class="px-4 py-2 bg-slate-50/70">
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                                Documentos em Rascunho
                            </span>
                        </div>
                        <template x-for="t in tarefas.filter(t => t.tipo === 'rascunho' || t.tipo === 'rascunho_lote')" :key="'minhas-rascunho-' + t.id + '-' + t.tipo">
                            <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition">
                                <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center flex-shrink-0">
                                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-purple-700 transition" x-text="t.titulo"></p>
                                    <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                </div>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-purple-100 text-purple-700" x-text="t.tipo === 'rascunho_lote' ? 'Editar' : 'Abrir'"></span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Aba Analisar Resposta (respostas de documentos que o usuário assinou) --}}
            <div x-show="cardTab1 === 'analisar_resposta'" x-cloak class="divide-y divide-slate-100 min-h-[120px] max-h-[510px] overflow-y-auto">
                <template x-if="tarefas.filter(t => t.tipo === 'resposta' && t.assinou_documento).length === 0">
                    <div class="p-8 text-center">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-50 flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-sm font-semibold text-slate-600">Nenhuma resposta para analisar</p>
                        <p class="text-xs text-slate-400 mt-1">Você receberá respostas de documentos que assinou</p>
                    </div>
                </template>
                <template x-if="tarefas.filter(t => t.tipo === 'resposta' && t.assinou_documento).length > 0">
                    <div class="divide-y divide-slate-100">
                        <div class="px-4 py-2 bg-slate-50/70">
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                Respostas de Documentos que Você Assinou
                            </span>
                        </div>
                        <template x-for="t in tarefas.filter(t => t.tipo === 'resposta' && t.assinou_documento)" :key="'minhas-resp-assinante-' + (t.id || t.processo_id)">
                            <a :href="t.url" class="group flex items-start gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/30' : ''">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : 'bg-emerald-50'">
                                    <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-500' : 'text-emerald-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-emerald-700 transition" x-text="t.titulo"></p>
                                    <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                    <template x-if="t.prazo_analise_data_limite">
                                        <p class="text-[10px] mt-0.5 truncate" :class="t.atrasado ? 'text-red-500 font-medium' : 'text-emerald-600'">
                                            <svg class="inline w-2.5 h-2.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Prazo: <span x-text="t.prazo_analise_data_limite"></span>
                                        </p>
                                    </template>
                                </div>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                            </a>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        </div>

        {{-- Coluna 2: DEMANDAS DO SETOR (apenas gestor/admin) --}}
        @if($isGestorOuAdmin)
        <div id="tour-processos-setor" class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden lg:col-span-3" x-data="{ cardTab2: 'aprovacoes' }">
            <div class="px-4 pt-4 pb-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-[15px] font-semibold text-slate-900 truncate">Demandas do Setor</h3>
                        <p class="text-xs text-slate-400 truncate">Pendências da sua gerência</p>
                    </div>
                </div>
                <a href="{{ route('admin.dashboard.todas-tarefas') }}" title="Ver todos" class="flex-shrink-0 text-xs font-semibold text-purple-600 hover:text-purple-800 hover:bg-purple-50 px-2.5 py-1.5 rounded-lg transition"><span class="lg:hidden 2xl:inline">Ver todos </span>→</a>
            </div>

            {{-- Abas internas do card --}}
            <div class="px-3 pb-3 border-b border-slate-100">
            <div class="flex flex-wrap items-stretch gap-1 p-1 bg-slate-100/80 rounded-xl">
                <button type="button" @click="cardTab2 = 'aprovacoes'"
                    :class="cardTab2 === 'aprovacoes' ? 'bg-white text-purple-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Aprovações
                    <span x-show="$store.dashboard.aprovacoesCount > 0" class="text-[10px] px-1.5 rounded-full bg-purple-100 text-purple-700 font-bold" x-text="$store.dashboard.aprovacoesCount"></span>
                </button>
                <button type="button" @click="cardTab2 = 'processos'"
                    :class="cardTab2 === 'processos' ? 'bg-white text-teal-600 shadow-sm' : '{{ $cardTabOff }}'"
                    class="{{ $cardTabBase }}">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Processos do Setor
                    <span x-show="$store.dashboard.processosSetor > 0" class="text-[10px] px-1.5 rounded-full bg-teal-100 text-teal-700 font-bold" x-text="$store.dashboard.processosSetor"></span>
                </button>
            </div>
            </div>

            {{-- Documentos do setor --}}
            <div x-show="cardTab2 === 'aprovacoes'" x-cloak x-data="tarefasPaginadas()">
                <div class="divide-y divide-slate-100 max-h-[250px] overflow-y-auto">
                    <template x-if="loading">
                        <div class="p-8 text-center text-purple-300">{!! $spinner !!}</div>
                    </template>
                    <template x-if="!loading && tarefas.filter(t => t.tipo === 'aprovacao').length > 0">
                        <div>
                            <template x-if="tarefas.filter(t => t.tipo === 'aprovacao').length > 0">
                                <div class="divide-y divide-slate-100">
                                    <div class="px-4 py-2 bg-slate-50/70">
                                        <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 rounded-full bg-purple-500"></span>
                                            Documentos pendentes de aprovação
                                        </span>
                                    </div>
                                    <template x-for="t in tarefas.filter(t => t.tipo === 'aprovacao')" :key="'aprov-' + (t.id || t.processo_id)">
                                        <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/30' : ''">
                                            <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : 'bg-purple-50'">
                                                <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-500' : 'text-purple-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-1 mb-0.5">
                                                    <template x-if="t.tipo_processo">
                                                        <span class="text-[9px] px-1.5 py-0.5 rounded font-medium" :class="t.is_licenciamento ? 'bg-blue-50 text-blue-600' : 'bg-slate-100 text-slate-500'" x-text="t.tipo_processo"></span>
                                                    </template>
                                                    <template x-if="t.total && t.total > 1">
                                                        <span class="text-[9px] px-1.5 py-0.5 rounded font-medium bg-purple-50 text-purple-600" x-text="'+' + (t.total - 1)"></span>
                                                    </template>
                                                </div>
                                                <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-purple-700 transition" x-text="t.titulo"></p>
                                                <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                            </div>
                                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                                        </a>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="!loading && tarefas.filter(t => t.tipo === 'aprovacao').length === 0">
                        <div class="p-6 text-center">
                            <div class="w-10 h-10 rounded-2xl bg-purple-50 flex items-center justify-center mx-auto mb-2">
                                <svg class="w-5 h-5 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <p class="text-xs font-semibold text-slate-500">Nenhum documento pendente no setor</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Documentos enviados por empresas para aprovação aparecerão aqui</p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Processos do Setor --}}
            <div x-show="cardTab2 === 'processos'" x-cloak x-data="processosAtribuidos('setor')">
                <div class="px-4 py-2 bg-slate-50/70 border-b border-slate-100 flex items-center justify-between gap-2">
                    <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5 min-w-0">
                        <span class="w-1.5 h-1.5 rounded-full bg-teal-500 flex-shrink-0"></span>
                        <span class="truncate">Processos sob responsabilidade do meu Setor</span>
                        <span class="text-[10px] px-1.5 py-0.5 rounded-full bg-teal-100 text-teal-700 font-bold" x-text="totalDoSetor"></span>
                    </span>
                    @if(auth('interno')->user()->setor)
                        <a href="{{ route('admin.processos.index-geral', ['setor' => auth('interno')->user()->setor, 'apenas_ativos' => 1]) }}"
                           class="text-[11px] text-teal-600 hover:text-teal-800 font-semibold transition whitespace-nowrap">
                            Ver todos →
                        </a>
                    @endif
                </div>
                <div class="divide-y divide-slate-100 max-h-[180px] overflow-y-auto">
                    <template x-if="loading">
                        <div class="p-4 text-center text-slate-300">{!! $spinner !!}</div>
                    </template>
                    <template x-if="!loading && processos.length > 0">
                        <div class="divide-y divide-slate-100">
                            <template x-for="p in processos" :key="'setor-proc-' + p.id">
                                <a :href="p.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition">
                                    <div class="w-8 h-8 rounded-lg bg-teal-50 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[13px] font-semibold text-slate-800 flex items-center gap-1 flex-wrap group-hover:text-teal-700 transition">
                                            <span x-text="p.numero_processo"></span>
                                            <template x-if="p.tramitado_para_setor">
                                                <span class="text-[9px] px-1.5 py-0.5 rounded font-medium bg-teal-100 text-teal-700">Seu setor</span>
                                            </template>
                                            <template x-if="p.docs_pendentes > 0">
                                                <span class="text-[9px] px-1.5 py-0.5 rounded font-medium bg-yellow-50 text-yellow-600" x-text="p.docs_pendentes + ' pend.'"></span>
                                            </template>
                                            <template x-if="p.prazo">
                                                <span class="text-[9px] px-1.5 py-0.5 rounded font-medium flex items-center gap-0.5"
                                                      :class="p.prazo.vencido ? 'bg-red-50 text-red-600' : (p.prazo.proximo ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600')">
                                                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                    <span x-text="p.prazo.data"></span>
                                                </span>
                                            </template>
                                        </p>
                                        <p class="text-[11px] text-slate-400 truncate" x-text="p.estabelecimento"></p>
                                        <template x-if="p.tramitado_para_setor && p.tramitado_em_humano">
                                            <p class="text-[10px] text-teal-700 truncate mt-0.5" :title="p.tramitado_em">
                                                Tramitado para seu setor em <span x-text="p.tramitado_em"></span> (<span x-text="p.tramitado_em_humano"></span>)
                                            </p>
                                        </template>
                                        <template x-if="!p.tramitado_para_setor && p.recebido_em_humano">
                                            <p class="text-[10px] text-sky-700 truncate mt-0.5" :title="p.recebido_em">
                                                Recebido em <span x-text="p.recebido_em"></span> (<span x-text="p.recebido_em_humano"></span>)
                                            </p>
                                        </template>
                                        <template x-if="!p.tramitado_para_setor && !p.recebido_em_humano && p.aguardando_ciencia">
                                            <p class="text-[10px] text-amber-600 truncate mt-0.5" :title="p.tramitado_em">
                                                Tramitado em <span x-text="p.tramitado_em"></span> (aguardando ciência)
                                            </p>
                                        </template>
                                    </div>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="getStatusClass(p.status)" x-text="p.status_nome"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                    <template x-if="!loading && processos.length === 0">
                        <div class="p-5 text-center">
                            <p class="text-xs font-semibold text-slate-500">Nenhum processo no setor</p>
                            <p class="text-[11px] text-slate-400 mt-0.5">Processos tramitados para sua gerência aparecerão aqui</p>
                        </div>
                    </template>
                </div>
                <template x-if="lastPage > 1">
                    <div class="px-4 py-2 border-t border-slate-100 flex items-center justify-between">
                        <span class="text-[11px] text-slate-400">Página <span x-text="currentPage"></span> de <span x-text="lastPage"></span></span>
                        <div class="flex gap-1">
                            <button @click="prevPage()" :disabled="currentPage <= 1" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg></button>
                            <button @click="nextPage()" :disabled="currentPage >= lastPage" class="p-1.5 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50 disabled:opacity-30 transition"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        @endif

        {{-- Coluna 3: ACOMPANHAMENTO --}}
        <div class="space-y-4 {{ $isGestorOuAdmin ? 'lg:col-span-3' : 'lg:col-span-5' }}" x-data="{ cardTab3: 'prazo' }">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden" x-data="tarefasPaginadas()" x-show="tarefas.filter(t => t.tipo === 'resposta' || t.tipo === 'prazo_documento').length > 0" x-cloak>
                <div class="px-4 pt-4 pb-3 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-[15px] font-semibold text-slate-900 truncate flex items-center gap-2">
                                Acompanhamento de Documentos
                                <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded-full font-bold" x-text="tarefas.filter(t => t.tipo === 'resposta' || t.tipo === 'prazo_documento').length || '0'"></span>
                            </h3>
                            <p class="text-xs text-slate-400 truncate">Prazos e respostas de documentos digitais</p>
                        </div>
                    </div>
                    <a href="{{ route('admin.dashboard.todas-tarefas') }}" title="Ver todos" class="flex-shrink-0 text-xs font-semibold text-amber-600 hover:text-amber-800 hover:bg-amber-50 px-2.5 py-1.5 rounded-lg transition"><span class="lg:hidden 2xl:inline">Ver todos </span>→</a>
                </div>

                {{-- Abas internas do card --}}
                <div class="px-3 pb-3 border-b border-slate-100">
                <div class="flex flex-wrap items-stretch gap-1 p-1 bg-slate-100/80 rounded-xl">
                    <button type="button" @click="cardTab3 = 'prazo'"
                        :class="cardTab3 === 'prazo' ? 'bg-white text-rose-600 shadow-sm' : '{{ $cardTabOff }}'"
                        class="{{ $cardTabBase }} flex-1 justify-center">
                        Prazos
                        <span x-show="tarefas.filter(t => t.tipo === 'prazo_documento').length > 0" class="text-[10px] px-1.5 rounded-full bg-rose-100 text-rose-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'prazo_documento').length"></span>
                    </button>
                    <button type="button" @click="cardTab3 = 'resposta'"
                        :class="cardTab3 === 'resposta' ? 'bg-white text-emerald-600 shadow-sm' : '{{ $cardTabOff }}'"
                        class="{{ $cardTabBase }} flex-1 justify-center">
                        Respostas
                        <span x-show="tarefas.filter(t => t.tipo === 'resposta').length > 0" class="text-[10px] px-1.5 rounded-full bg-emerald-100 text-emerald-700 font-bold" x-text="tarefas.filter(t => t.tipo === 'resposta').length"></span>
                    </button>
                </div>
                </div>

                <div class="divide-y divide-slate-100 max-h-[440px] overflow-y-auto">
                    <template x-if="cardTab3 === 'prazo' && tarefas.filter(t => t.tipo === 'prazo_documento').length === 0">
                        <div class="p-6 text-center text-xs text-slate-400">Nenhum documento com prazo em aberto</div>
                    </template>
                    <template x-if="cardTab3 === 'resposta' && tarefas.filter(t => t.tipo === 'resposta').length === 0">
                        <div class="p-6 text-center text-xs text-slate-400">Nenhuma resposta para analisar</div>
                    </template>

                    <template x-if="cardTab3 === 'prazo'">
                        <div class="divide-y divide-slate-100">
                            <div class="px-4 py-2 bg-slate-50/70">
                                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>
                                    Documentos com Prazo
                                </span>
                            </div>
                            <template x-for="t in tarefas.filter(t => t.tipo === 'prazo_documento')" :key="'prazo-docs-' + t.id">
                                <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/40' : 'bg-amber-50/20'">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : 'bg-rose-50'">
                                        <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-600' : 'text-rose-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-rose-700 transition" x-text="t.titulo"></p>
                                        <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                        <p class="text-[10px] mt-0.5 truncate" :class="t.atrasado ? 'text-red-500' : 'text-amber-600'" x-text="t.prazo_texto"></p>
                                    </div>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full whitespace-nowrap" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                                </a>
                            </template>
                        </div>
                    </template>

                    <template x-if="cardTab3 === 'resposta'">
                        <div class="divide-y divide-slate-100">
                            <div class="px-4 py-2 bg-slate-50/70">
                                <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                    Respostas para Analisar
                                </span>
                            </div>
                            <template x-for="t in tarefas.filter(t => t.tipo === 'resposta')" :key="'resp-docs-' + (t.id || t.processo_id)">
                                <a :href="t.url" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition" :class="t.atrasado ? 'bg-red-50/30' : ''">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0" :class="t.atrasado ? 'bg-red-100' : 'bg-emerald-50'">
                                        <svg class="w-4 h-4" :class="t.atrasado ? 'text-red-500' : 'text-emerald-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-[13px] font-semibold text-slate-800 truncate group-hover:text-emerald-700 transition" x-text="t.titulo"></p>
                                        <p class="text-[11px] text-slate-400 truncate" x-text="t.subtitulo"></p>
                                        <template x-if="t.prazo_analise_data_limite">
                                            <p class="text-[10px] mt-0.5 truncate" :class="t.atrasado ? 'text-red-500 font-medium' : 'text-emerald-600'">
                                                <svg class="inline w-2.5 h-2.5 -mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                Prazo de análise: <span x-text="t.prazo_analise_data_limite"></span>
                                            </p>
                                        </template>
                                    </div>
                                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="getBadgeClass(t)" x-text="getBadgeText(t)"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

        </div>
    </div>

    </div>
    {{-- /ABA: TRABALHO --}}

    {{-- ============================ --}}
    {{-- ABA: ACOMPANHAMENTO (Monitorando + Aniversariantes) --}}
    {{-- ============================ --}}
    @if($mostraAcompanhamento)
    <div x-show="tab === 'acompanhamento'" x-cloak class="grid grid-cols-1 lg:grid-cols-2 gap-4 items-start">

        {{-- Monitorando --}}
        @if(count($processos_acompanhados ?? []) > 0)
        <div id="tour-monitorando" class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
            <div class="px-4 pt-4 pb-3 border-b border-slate-100 flex items-center justify-between gap-2">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-[15px] font-semibold text-slate-900 flex items-center gap-2">
                            Monitorando
                            <span class="text-[10px] px-1.5 py-0.5 bg-indigo-100 text-indigo-700 rounded-full font-bold">{{ count($processos_acompanhados ?? []) }}</span>
                        </h3>
                        <p class="text-xs text-slate-400 truncate">Processos que você acompanha</p>
                    </div>
                </div>
                <a href="{{ route('admin.processos.index-geral', ['monitorando' => 1]) }}" class="flex-shrink-0 text-xs font-semibold text-indigo-600 hover:text-indigo-800 hover:bg-indigo-50 px-2.5 py-1.5 rounded-lg transition">Ver todos →</a>
            </div>
            <div class="divide-y divide-slate-100 max-h-[200px] overflow-y-auto">
                @forelse(($processos_acompanhados ?? collect())->take(5) as $proc)
                <a href="{{ route('admin.estabelecimentos.processos.show', [$proc->estabelecimento_id, $proc->id]) }}" class="group flex items-center gap-3 px-4 py-2.5 hover:bg-slate-50 transition">
                    <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-semibold text-slate-800 flex items-center gap-1 group-hover:text-indigo-700 transition">
                            {{ $proc->numero_processo }}
                            @if($proc->tipoProcesso)
                            <span class="text-[9px] px-1.5 py-0.5 rounded font-medium bg-slate-100 text-slate-500">{{ $proc->tipoProcesso->nome }}</span>
                            @endif
                        </p>
                        <p class="text-[11px] text-slate-400 truncate">{{ $proc->estabelecimento->nome_fantasia ?? $proc->estabelecimento->razao_social ?? '-' }}</p>
                        @php
                            $meuAcompanhamento = $proc->acompanhamentos->first();
                        @endphp
                        @if($meuAcompanhamento && $meuAcompanhamento->descricao)
                            <p class="text-[10px] text-indigo-500 truncate mt-0.5">📝 {{ $meuAcompanhamento->descricao }}</p>
                        @endif
                    </div>
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full {{ $proc->status === 'aberto' ? 'bg-blue-100 text-blue-600' : ($proc->status === 'arquivado' ? 'bg-slate-100 text-slate-500' : 'bg-yellow-100 text-yellow-600') }}">
                        {{ ucfirst($proc->status) }}
                    </span>
                </a>
                @empty
                <div class="p-6 text-center">
                    <div class="w-10 h-10 rounded-2xl bg-indigo-50 flex items-center justify-center mx-auto mb-2">
                        <svg class="w-5 h-5 text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    </div>
                    <p class="text-xs text-slate-400">Nenhum processo monitorado</p>
                    <p class="text-[10px] text-slate-300 mt-0.5">Acompanhe processos para vê-los aqui</p>
                </div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- Aniversariantes do Mês --}}
        @if(isset($aniversariantes_mes) && $aniversariantes_mes->count() > 0)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
            <div class="px-4 pt-4 pb-3 border-b border-slate-100 flex items-center justify-between gap-2">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-xl bg-pink-50 flex items-center justify-center flex-shrink-0">
                        <span class="text-lg">🎂</span>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-[15px] font-semibold text-slate-900 flex items-center gap-2">
                            Aniversariantes do Mês
                            <span class="text-[10px] px-1.5 py-0.5 bg-pink-100 text-pink-700 rounded-full font-bold">{{ $aniversariantes_mes->count() }}</span>
                        </h3>
                        <p class="text-xs text-slate-400 truncate">Equipe {{ $escopoAniversariantes ?? '' }}</p>
                    </div>
                </div>
            </div>
            <div class="divide-y divide-slate-100 max-h-[200px] overflow-y-auto">
                @foreach($aniversariantes_mes as $anv)
                <div class="flex items-center gap-3 px-4 py-2.5 {{ ($anv->eh_hoje ?? false) ? 'bg-pink-50/60' : '' }}">
                    <div class="w-8 h-8 rounded-lg {{ ($anv->eh_hoje ?? false) ? 'bg-gradient-to-br from-pink-500 to-rose-500 text-white shadow-md shadow-pink-500/30' : 'bg-pink-50 text-pink-600' }} flex items-center justify-center flex-shrink-0 text-[11px] font-bold">
                        {{ strtoupper(mb_substr($anv->nome, 0, 1)) }}{{ strtoupper(mb_substr(collect(explode(' ', $anv->nome))->last() ?? '', 0, 1)) }}
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-[13px] font-semibold text-slate-800 truncate">{{ \Str::words($anv->nome, 3, '') }}</p>
                    </div>
                    @if($anv->eh_hoje ?? false)
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-pink-500 text-white whitespace-nowrap">Hoje 🎉</span>
                    @else
                        <span class="text-[11px] font-medium text-slate-400 whitespace-nowrap">{{ $anv->dia_aniversario }}</span>
                    @endif
                </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>
    @endif

</div>


<script>
document.addEventListener('alpine:init', () => {
    Alpine.store('dashboard', {
        tarefasTotal: 0,
        aprovacoesCount: 0,
        processosMeuDireto: 0,
        processosSetor: 0,
        osVencidas: 0,
        get avisosTotal() {
            return {{ $countAvisos }} + this.osVencidas;
        },
        get trabalhoTotal() {
            return this.tarefasTotal + this.processosMeuDireto + this.processosSetor;
        }
    });
});

function tarefasPaginadas() {
    return {
        tarefas: [], loading: true, currentPage: 1, lastPage: 1, total: 0,
        init() { this.load(); },
        async load() {
            this.loading = true;
            try {
                const r = await fetch(`{{ route('admin.dashboard.tarefas') }}?page=${this.currentPage}&per_page=100`);
                const d = await r.json();
                this.tarefas = d.data; this.currentPage = d.current_page; this.lastPage = d.last_page; this.total = d.total;
                if (Alpine.store('dashboard')) {
                    Alpine.store('dashboard').tarefasTotal = d.total;
                    Alpine.store('dashboard').aprovacoesCount = (d.data || []).filter(t => t.tipo === 'aprovacao').length;
                }
            } catch(e) { console.error(e); }
            this.loading = false;
        },
        prevPage() { if (this.currentPage > 1) { this.currentPage--; this.load(); } },
        nextPage() { if (this.currentPage < this.lastPage) { this.currentPage++; this.load(); } },
        getBadgeClass(t) {
            if (t.tipo === 'exigencia') {
                if (t.atrasado) return 'bg-red-100 text-red-700';
                if (t.dias_restantes === 0) return 'bg-orange-100 text-orange-700';
                return 'bg-indigo-100 text-indigo-700';
            }
            if (t.tipo === 'rascunho' || t.tipo === 'rascunho_lote') return 'bg-purple-100 text-purple-700';
            if (t.tipo === 'prazo_documento') {
                if (t.atrasado) return 'bg-red-100 text-red-700';
                if (t.dias_restantes === 0) return 'bg-orange-100 text-orange-700';
                if (t.dias_restantes !== null && t.dias_restantes <= 2) return 'bg-amber-100 text-amber-700';
                return 'bg-yellow-100 text-yellow-700';
            }
            if (t.tipo === 'os') {
                const diasOs = t.dias_para_finalizar;
                if (t.atrasado) return 'bg-red-100 text-red-700'; // Passou 15 dias após data_fim
                if (diasOs === null) return 'bg-gray-100 text-gray-600';
                if (diasOs === 0) return 'bg-orange-100 text-orange-700';
                if (t.em_finalizacao) {
                    if (diasOs <= 3) return 'bg-orange-100 text-orange-700';
                    if (diasOs <= 7) return 'bg-amber-100 text-amber-700';
                    return 'bg-yellow-100 text-yellow-700';
                }
                return 'bg-green-100 text-green-700';
            }
            // Respostas: usa prazo de análise (agora sempre existe, não depende de licenciamento)
            if (t.tipo === 'resposta') {
                if (t.atrasado) return 'bg-red-100 text-red-700';
                if (t.dias_restantes === 0) return 'bg-orange-100 text-orange-700';
                if (t.dias_restantes !== null && t.dias_restantes <= 2) return 'bg-amber-100 text-amber-700';
                if (t.dias_restantes === null) return 'bg-gray-100 text-gray-600';
                return 'bg-green-100 text-green-700';
            }
            if (t.is_licenciamento === false) return 'bg-gray-100 text-gray-600';
            if (t.atrasado) return 'bg-red-100 text-red-700';
            if (t.dias_restantes === 0) return 'bg-orange-100 text-orange-700';
            if (t.dias_restantes !== null && t.dias_restantes <= 3) return 'bg-amber-100 text-amber-700';
            if (t.dias_restantes === null) return 'bg-gray-100 text-gray-600';
            return 'bg-green-100 text-green-700';
        },
        getBadgeText(t) {
            if (t.tipo === 'assinatura') return 'Assinar';
            if (t.tipo === 'exigencia') {
                if (t.atrasado) return 'atrasado';
                if (t.dias_restantes === 0) return 'hoje';
                if (t.dias_restantes === null || t.dias_restantes === undefined) return 'preencher';
                return t.dias_restantes + 'd';
            }
            if (t.tipo === 'rascunho_lote') return 'Editar';
            if (t.tipo === 'rascunho') return 'Abrir';
            if (t.tipo === 'prazo_documento') {
                if (t.atrasado) return 'prazo venc.';
                if (t.dias_restantes === 0) return 'hoje p/ vencer';
                if (t.dias_restantes === null) return 'Prazo';
                return t.dias_restantes + 'd p/ vencer';
            }
            if (t.tipo === 'os') {
                const diasOs = t.dias_para_finalizar;
                if (t.atrasado) return 'finaliz. venc.';
                if (diasOs === null) return '-';
                if (diasOs === 0) return 'hoje p/ finalizar';
                return diasOs + 'd p/ finalizar';
            }
            // Respostas: usa prazo de análise específico (dias_restantes vem do backend como dias_restantes_analise)
            if (t.tipo === 'resposta') {
                if (t.dias_restantes === null || t.dias_restantes === undefined) return 'analisar';
                if (t.atrasado || t.dias_restantes < 0) {
                    const d = Math.abs(t.dias_restantes);
                    return 'venc. há ' + d + 'd';
                }
                if (t.dias_restantes === 0) return 'vence hoje';
                if (t.dias_restantes === 1) return 'vence amanhã';
                return t.dias_restantes + 'd p/ analisar';
            }
            if (t.is_licenciamento === false) return 'Verificar';
            if (t.atrasado) return t.tipo === 'aprovacao' ? (t.dias_pendente - 5) + 'd atras.' : Math.abs(t.dias_restantes) + 'd atras.';
            if (t.dias_restantes === 0) return 'hoje p/ analisar';
            if (t.dias_restantes === null) return '-';
            return t.dias_restantes + 'd p/ analisar';
        }
    }
}

// Funções de referência (para coluna 2 que precisa de ambos os dados)
function tarefasPaginadasRef() { return {}; }
function processosAtribuidosRef() { return {}; }

function processosAtribuidos(escopo = 'todos') {
    return {
        processos: [], loading: true, currentPage: 1, lastPage: 1, total: 0, totalMeuDireto: 0, totalDoSetor: 0,
        init() { this.load(); },
        async load() {
            this.loading = true;
            try {
                const r = await fetch(`{{ route('admin.dashboard.processos-atribuidos') }}?page=${this.currentPage}&escopo=${escopo}`);
                const d = await r.json();
                this.processos = d.data;
                this.currentPage = d.current_page;
                this.lastPage = d.last_page;
                this.total = d.total;
                this.totalMeuDireto = d.total_meu_direto ?? (escopo === 'meu_direto' ? d.total : this.processos.filter(p => p.is_meu_direto).length);
                this.totalDoSetor = d.total_do_setor ?? (escopo === 'setor' ? d.total : this.processos.filter(p => p.is_do_setor).length);
                if (Alpine.store('dashboard')) {
                    if (escopo === 'meu_direto') Alpine.store('dashboard').processosMeuDireto = this.totalMeuDireto;
                    if (escopo === 'setor') Alpine.store('dashboard').processosSetor = this.totalDoSetor;
                }
            } catch(e) { console.error(e); }
            this.loading = false;
        },
        prevPage() { if (this.currentPage > 1) { this.currentPage--; this.load(); } },
        nextPage() { if (this.currentPage < this.lastPage) { this.currentPage++; this.load(); } },
        getStatusClass(s) {
            return { 'aberto': 'bg-blue-100 text-blue-700', 'em_analise': 'bg-yellow-100 text-yellow-700', 'pendente': 'bg-orange-100 text-orange-700' }[s] || 'bg-gray-100 text-gray-700';
        }
    }
}

function ordensServicoVencidas() {
    return {
        ordens: [],
        aberto: true,
        init() { 
            this.load(); 
        },
        async load() {
            try {
                const r = await fetch('{{ route('admin.dashboard.ordens-servico-vencidas') }}');
                const d = await r.json();
                this.ordens = d;
                if (Alpine.store('dashboard')) Alpine.store('dashboard').osVencidas = d.length || 0;
            } catch(e) {
                console.error('Erro ao carregar OSs vencidas:', e); 
            }
        }
    }
}

function respostasAtrasadasAnalise() {
    return {
        respostas: [],
        aberto: true,
        init() {
            this.load();
        },
        async load() {
            try {
                const r = await fetch('{{ route('admin.dashboard.respostas-atrasadas-analise') }}');
                if (!r.ok) {
                    console.error('Erro ao carregar respostas atrasadas: HTTP', r.status);
                    return;
                }
                const d = await r.json();
                this.respostas = Array.isArray(d) ? d : [];
                if (Alpine.store('dashboard')) Alpine.store('dashboard').respostasAtrasadas = this.respostas.length;
            } catch(e) {
                console.error('Erro ao carregar respostas atrasadas:', e);
            }
        }
    }
}
</script>
@endsection
