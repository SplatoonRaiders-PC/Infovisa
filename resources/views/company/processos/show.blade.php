@extends('layouts.company')

@section('title', 'Detalhes do Processo')
@section('page-title', 'Detalhes do Processo')

@section('content')
<div class="space-y-4" x-data="{ modalUpload: false, modalAlertas: false, modalVisualizador: false, documentoUrl: '', documentoNome: '', documentoExtensao: '', modalResposta: false, docRespostaId: null, docRespostaNome: '', docRespostaTipos: [], docRespostaEnviados: [], modalReenvio: false, docReenvioId: null, docReenvioNome: '', docReenvioMotivo: '' }" data-processo-root>
    {{-- Mensagens --}}
    @if(session('success'))
    <div class="bg-green-50 border border-green-200/80 border-l-4 border-l-green-500 px-4 py-3 rounded-xl">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
            </svg>
            <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
        </div>
    </div>
    @endif

    @if(session('error'))
    <div class="bg-red-50 border border-red-200/80 border-l-4 border-l-red-500 px-4 py-3 rounded-xl">
        <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
    </div>
    @endif

    @if($errors->any())
    <div class="bg-red-50 border border-red-200/80 border-l-4 border-l-red-500 px-4 py-3 rounded-xl">
        <ul class="list-disc list-inside text-sm text-red-700">
            @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
    @endif

    @if($processo->status === 'parado')
    <div class="bg-red-50 border border-red-200/80 border-l-4 border-l-red-500 px-4 py-3 rounded-xl">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-red-800">Processo parado</p>
                @if($processo->motivo_parada)
                <p class="text-sm text-red-700 mt-1"><strong>Motivo:</strong> {{ $processo->motivo_parada }}</p>
                @endif
                @if($processo->data_parada)
                <p class="text-xs text-red-600 mt-1">Parado em {{ $processo->data_parada->format('d/m/Y H:i') }}</p>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Cabeçalho com dados do processo --}}
    @php
        $statusBadgeProc = match(true) {
            $processo->status === 'aprovado' => ['bg-green-50 text-green-700 ring-green-200', 'bg-green-500'],
            $processo->status === 'em_analise' => ['bg-blue-50 text-blue-700 ring-blue-200', 'bg-blue-500'],
            $processo->status === 'arquivado' => ['bg-slate-100 text-slate-700 ring-slate-200', 'bg-slate-400'],
            $processo->status === 'parado' => ['bg-red-50 text-red-700 ring-red-200', 'bg-red-500'],
            default => ['bg-yellow-50 text-yellow-800 ring-yellow-200', 'bg-yellow-500'],
        };
    @endphp
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
        {{-- Faixa do processo --}}
        <div class="px-4 sm:px-5 py-4 bg-gradient-to-r from-slate-50 via-white to-white border-b border-slate-100 flex flex-col sm:flex-row gap-3 sm:items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('company.processos.index') }}" title="Voltar"
                   class="w-9 h-9 flex-shrink-0 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-slate-800 hover:bg-slate-50 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="w-11 h-11 flex-shrink-0 rounded-xl bg-gradient-to-br from-indigo-500 to-blue-600 text-white flex items-center justify-center shadow-md shadow-indigo-500/25">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Processo · {{ $processo->tipo_nome }}</p>
                    <h1 class="text-xl font-bold text-slate-900 tracking-tight tabular-nums">{{ $processo->numero_processo }}</h1>
                </div>
            </div>
            <span class="self-start sm:self-auto inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold rounded-full ring-1 ring-inset {{ $statusBadgeProc[0] }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $statusBadgeProc[1] }}"></span>
                {{ $processo->status_nome }}
            </span>
        </div>

        <div class="p-4 sm:p-5">
            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tipo</dt>
                    <dd class="text-[13px] font-medium text-slate-800">{{ $processo->tipo_nome }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2 min-w-0">
                    <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Estabelecimento</dt>
                    <dd class="text-[13px] font-semibold truncate">
                        <a href="{{ route('company.estabelecimentos.show', $processo->estabelecimento->id) }}" class="text-blue-700 hover:text-blue-900 hover:underline underline-offset-2">
                            {{ $processo->estabelecimento->nome_fantasia ?: $processo->estabelecimento->razao_social }}
                        </a>
                    </dd>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Data de Abertura</dt>
                    <dd class="text-[13px] font-medium text-slate-800 tabular-nums">{{ $processo->created_at->format('d/m/Y H:i') }}</dd>
                </div>
                <div class="rounded-xl bg-slate-50 px-3 py-2">
                    <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Última Atualização</dt>
                    <dd class="text-[13px] font-medium text-slate-800 tabular-nums">{{ $processo->updated_at->format('d/m/Y H:i') }}</dd>
                </div>
            </dl>

            {{-- Setor Atual (visível para o estabelecimento) --}}
            @if($processo->setor_atual)
            <div class="mt-3">
                <div class="flex items-center gap-3 px-3 py-2.5 bg-blue-50/70 border border-blue-100 rounded-xl">
                    <div class="w-9 h-9 rounded-lg bg-blue-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm shadow-blue-600/25">
                        <svg class="w-[18px] h-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold text-blue-500 uppercase tracking-wider">Processo está com</dt>
                        <dd class="text-sm font-semibold text-blue-800">{{ $processo->setor_atual_nome }}</dd>
                        @if($processo->responsavel_desde)
                        @php
                            $diasNoSetor = (int) $processo->responsavel_desde->startOfDay()->diffInDays(now()->startOfDay());
                        @endphp
                        <dd class="text-xs text-slate-500">
                            @if($diasNoSetor === 0)
                                desde hoje
                            @elseif($diasNoSetor === 1)
                                há 1 dia
                            @else
                                há {{ $diasNoSetor }} dias
                            @endif
                            <span class="text-slate-400">({{ $processo->responsavel_desde->format('d/m/Y') }})</span>
                        </dd>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            @if($processo->observacoes)
            <div class="mt-3 px-3 py-2 rounded-xl bg-amber-50/60 border border-amber-100">
                <dt class="text-[10px] font-semibold text-amber-600 uppercase tracking-wider">Observações</dt>
                <dd class="text-[13px] text-slate-700 mt-0.5">{{ $processo->observacoes }}</dd>
            </div>
            @endif
        </div>
    </div>

    {{-- Modal Bloqueante de Responsável Legal / Responsável Técnico / Equipamentos de Imagem --}}
    @if(isset($precisaCadastrarResponsavelLegal) && $precisaCadastrarResponsavelLegal)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog">
        <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 border-t-4 border-red-500">
                <div class="flex justify-center mb-4">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center">
                        <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                </div>

                <h2 class="text-xl font-bold text-slate-900 text-center mb-2">Ação Obrigatória</h2>
                <h3 class="text-lg font-semibold text-red-600 text-center mb-4">Cadastro de Responsável Legal</h3>

                <div class="bg-red-50 rounded-lg p-4 mb-6">
                    <p class="text-sm text-slate-700 mb-3">
                        O estabelecimento <strong class="text-slate-900">{{ $processo->estabelecimento->nome_fantasia ?: $processo->estabelecimento->razao_social }}</strong>
                        não possui Responsável Legal cadastrado.
                    </p>
                    <p class="text-sm text-slate-700">
                        Para visualizar e dar continuidade ao processo, é obrigatório cadastrar ao menos <strong>um Responsável Legal</strong> no estabelecimento.
                    </p>
                </div>

                <a href="{{ route('company.estabelecimentos.responsaveis.create', [$processo->estabelecimento->id, 'legal']) }}"
                   class="flex items-center justify-center w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white text-base font-semibold rounded-xl transition-colors shadow-lg hover:shadow-xl">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    Cadastrar Responsável Legal
                </a>

                <p class="text-xs text-slate-500 text-center mt-4">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    Este processo está bloqueado até o cadastro ser realizado
                </p>
            </div>
        </div>
    </div>
    @elseif(isset($precisaCadastrarResponsavelTecnico) && $precisaCadastrarResponsavelTecnico)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog">
        <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 border-t-4 border-red-500">
                <div class="flex justify-center mb-4">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center">
                        <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </div>
                </div>

                <h2 class="text-xl font-bold text-slate-900 text-center mb-2">Ação Obrigatória</h2>
                <h3 class="text-lg font-semibold text-red-600 text-center mb-4">Cadastro de Responsável Técnico</h3>

                <div class="bg-red-50 rounded-lg p-4 mb-6">
                    <p class="text-sm text-slate-700 mb-3">
                        O estabelecimento <strong class="text-slate-900">{{ $processo->estabelecimento->nome_fantasia ?: $processo->estabelecimento->razao_social }}</strong>
                        possui atividade que exige responsável técnico cadastrado.
                    </p>
                    <p class="text-sm text-slate-700">
                        Para dar continuidade ao processo, é necessário cadastrar ao menos <strong>um Responsável Técnico</strong> no estabelecimento.
                    </p>
                </div>

                <a href="{{ route('company.estabelecimentos.responsaveis.index', $processo->estabelecimento->id) }}"
                   class="flex items-center justify-center w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white text-base font-semibold rounded-xl transition-colors shadow-lg hover:shadow-xl">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    Cadastrar Responsável Técnico
                </a>

                <p class="text-xs text-slate-500 text-center mt-4">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    Este processo está bloqueado até o cadastro ser realizado
                </p>
            </div>
        </div>
    </div>
    @elseif(isset($precisaCadastrarEquipamentos) && $precisaCadastrarEquipamentos)
    <div class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true" role="dialog">
        {{-- Overlay escuro --}}
        <div class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm"></div>
        
        {{-- Container do Modal --}}
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl max-w-lg w-full p-6 border-t-4 border-red-500">
                {{-- Ícone de alerta --}}
                <div class="flex justify-center mb-4">
                    <div class="w-16 h-16 rounded-full bg-red-100 flex items-center justify-center">
                        <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                    </div>
                </div>
                
                {{-- Título --}}
                <h2 class="text-xl font-bold text-slate-900 text-center mb-2">
                    Ação Obrigatória
                </h2>
                <h3 class="text-lg font-semibold text-red-600 text-center mb-4">
                    Cadastro de Equipamentos de Imagem
                </h3>
                
                {{-- Conteúdo --}}
                <div class="bg-red-50 rounded-lg p-4 mb-6">
                    <p class="text-sm text-slate-700 mb-3">
                        O estabelecimento <strong class="text-slate-900">{{ $processo->estabelecimento->nome_fantasia ?: $processo->estabelecimento->razao_social }}</strong> 
                        possui atividades que exigem o cadastro de equipamentos de imagem (raio-x, tomografia, ressonância, etc).
                    </p>
                    <p class="text-sm text-slate-700">
                        Para dar continuidade ao processo, é necessário <strong>cadastrar os equipamentos</strong> ou <strong>declarar que o estabelecimento não os possui</strong>.
                    </p>
                </div>
                
                {{-- Botão --}}
                <a href="{{ route('company.estabelecimentos.equipamentos-radiacao.index', $processo->estabelecimento->id) }}" 
                   class="flex items-center justify-center w-full px-6 py-3 bg-red-600 hover:bg-red-700 text-white text-base font-semibold rounded-xl transition-colors shadow-lg hover:shadow-xl">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/>
                    </svg>
                    Cadastrar Equipamentos de Imagem
                </a>
                
                {{-- Aviso --}}
                <p class="text-xs text-slate-500 text-center mt-4">
                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                    </svg>
                    Este processo está bloqueado até o cadastro ser realizado
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- Aviso de Prazo da Fila Pública --}}
    @if(isset($avisoFilaPublica) && $avisoFilaPublica && $processo->status !== 'arquivado')
        @php
            $dias = $avisoFilaPublica['dias_restantes'];
            $prazoPausado = $avisoFilaPublica['pausado'] ?? false;
            $prazoReiniciado = $avisoFilaPublica['prazo_reiniciado'] ?? false;
            $corBg = $prazoPausado ? 'bg-slate-50' : ($avisoFilaPublica['atrasado'] ? 'bg-red-50' : ($dias <= 5 ? 'bg-amber-50' : 'bg-cyan-50'));
            $corBorda = $prazoPausado ? 'border-slate-400' : ($avisoFilaPublica['atrasado'] ? 'border-red-400' : ($dias <= 5 ? 'border-amber-400' : 'border-cyan-400'));
            $corTexto = $prazoPausado ? 'text-slate-700' : ($avisoFilaPublica['atrasado'] ? 'text-red-700' : ($dias <= 5 ? 'text-amber-700' : 'text-cyan-700'));
        @endphp
        <div class="mb-3 {{ $corBg }} border-l-4 {{ $corBorda }} px-4 py-2.5 rounded-r-lg">
            <div class="flex items-center gap-2 {{ $corTexto }} text-sm">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>
                    @if($prazoPausado)
                        <strong>Prazo suspenso.</strong> {{ $avisoFilaPublica['atrasado'] ? 'Atraso de ' . abs($dias) . ' dias' : 'Restavam ' . $dias . ' dias' }}
                    @elseif($avisoFilaPublica['atrasado'])
                        <strong>Prazo vencido!</strong> Atrasado há {{ abs($dias) }} {{ abs($dias) == 1 ? 'dia' : 'dias' }}
                    @else
                        Documentação completa em {{ $avisoFilaPublica['data_documentos_completos']->format('d/m/Y') }} • Prazo: {{ $avisoFilaPublica['prazo'] }} dias • <strong>Restam {{ $dias }} dias</strong>
                    @endif
                </span>
            </div>
        </div>
    @endif

    {{-- Avisos de Prazo por Unidade --}}
    @if(isset($avisoFilaPublicaPorUnidade) && $avisoFilaPublicaPorUnidade instanceof \Illuminate\Support\Collection && $avisoFilaPublicaPorUnidade->count() > 0 && $processo->status !== 'arquivado')
        @foreach($avisoFilaPublicaPorUnidade as $pastaId => $avisoU)
        @php
            $diasU = $avisoU['dias_restantes'];
            $pausadoU = $avisoU['pausado'] ?? false;
            $corBgU = $pausadoU ? 'bg-slate-50' : ($avisoU['atrasado'] ? 'bg-red-50' : ($diasU <= 5 ? 'bg-amber-50' : 'bg-violet-50'));
            $corBordaU = $pausadoU ? 'border-slate-400' : ($avisoU['atrasado'] ? 'border-red-400' : ($diasU <= 5 ? 'border-amber-400' : 'border-violet-400'));
            $corTextoU = $pausadoU ? 'text-slate-700' : ($avisoU['atrasado'] ? 'text-red-700' : ($diasU <= 5 ? 'text-amber-700' : 'text-violet-700'));
        @endphp
        <div class="mb-2 {{ $corBgU }} border-l-4 {{ $corBordaU }} px-4 py-2 rounded-r-lg">
            <div class="flex items-center gap-2 {{ $corTextoU }} text-sm">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <span>
                    <strong>{{ $avisoU['nome'] }}:</strong>
                    @if($pausadoU && $avisoU['atrasado'])
                        Prazo suspenso com atraso de {{ abs($diasU) }} dias
                    @elseif($pausadoU)
                        Prazo suspenso • Restavam {{ $diasU }} dias
                    @elseif($avisoU['atrasado'])
                        Prazo vencido! Atrasado há {{ abs($diasU) }} dias
                    @else
                        Documentação completa em {{ $avisoU['data_documentos_completos']->format('d/m/Y') }} • Prazo: {{ $avisoU['prazo'] }} dias • Restam {{ $diasU }} dias
                    @endif
                </span>
            </div>
        </div>
        @endforeach
    @endif

    {{-- Alerta de Documentos com Prazo + Upload Inline --}}
    @php
        $documentosComPrazo = collect();
        if(isset($todosDocumentos)) {
            $documentosComPrazo = $todosDocumentos->filter(function($item) {
                if($item['tipo'] === 'vigilancia') {
                    $doc = $item['documento'];
                    return $doc->temPrazo() && !$doc->isPrazoFinalizado() && $doc->status === 'assinado';
                }
                return false;
            });
        }
    @endphp
    @if($documentosComPrazo->count() > 0)
    <div x-data="{ uploadAberto: null, enviando: false }" class="bg-orange-50 border border-orange-200 rounded-xl overflow-hidden">
        <div class="px-4 py-3 flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-orange-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="flex-1">
                <h3 class="text-sm font-semibold text-orange-800">{{ $documentosComPrazo->count() }} documento(s) com prazo pendente</h3>
                <p class="text-[11px] text-orange-600">Anexe sua resposta diretamente abaixo</p>
            </div>
        </div>

        <div class="bg-white divide-y divide-slate-100">
            @foreach($documentosComPrazo as $itemPrazo)
                @php
                    $docPrazo = $itemPrazo['documento'];
                    $precisaVisualizarPrazo = $docPrazo->prazo_notificacao && !$docPrazo->prazo_iniciado_em;
                @endphp
                <div class="px-4 py-3">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <div class="w-8 h-8 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $docPrazo->tipoDocumento->nome ?? 'Documento' }}</p>
                                <p class="text-[11px] text-slate-400">Nº {{ $docPrazo->numero_documento }} · {{ $docPrazo->data_disponibilizacao ? 'Disponível desde ' . $docPrazo->data_disponibilizacao->format('d/m/Y H:i') : 'Data de disponibilização não registrada' }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            @php
                                $corBadge = $docPrazo->cor_status_prazo;
                                $textoBadge = $docPrazo->texto_status_prazo;
                                $classesCor = [
                                    'red' => 'bg-red-100 text-red-700',
                                    'yellow' => 'bg-yellow-100 text-yellow-700',
                                    'green' => 'bg-green-100 text-green-700',
                                    'blue' => 'bg-blue-100 text-blue-700',
                                    'gray' => 'bg-slate-100 text-slate-700',
                                ];
                                $classeBadge = $classesCor[$corBadge] ?? $classesCor['gray'];
                            @endphp
                            <span class="text-[11px] font-medium px-2 py-0.5 rounded-full {{ $classeBadge }}">{{ $textoBadge }}</span>
                            <a href="{{ route('company.processos.documento-digital.visualizar', [$processo->id, $docPrazo->id]) }}" target="_blank" @if($precisaVisualizarPrazo) onclick="recarregarAposVisualizarDocumentoPrazo()" @endif
                               class="inline-flex items-center gap-1 rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700">
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                </svg>
                                Visualizar documento
                            </a>
                            @if($docPrazo->permiteResposta() && $docPrazo->itensAtendimento->isEmpty() && !$precisaVisualizarPrazo)
                            @php
                                $setorEstabPrazo = $processo->estabelecimento?->tipo_setor;
                                $setorEstabPrazo = $setorEstabPrazo instanceof \App\Enums\TipoSetor ? $setorEstabPrazo->value : ($setorEstabPrazo ?? 'privado');
                                $tiposRespPrazo = ($docPrazo->tipoDocumento?->tiposDocumentoResposta ?? collect())
                                    ->filter(function($tr) use ($setorEstabPrazo) { return $tr->tipo_setor === 'todos' || $tr->tipo_setor === $setorEstabPrazo; })
                                    ->map(function($tr) { return ['id' => $tr->id, 'nome' => $tr->nome, 'descricao' => $tr->descricao]; })
                                    ->values();
                                $enviadosPrazo = $docPrazo->respostas
                                    ->whereIn('status', ['pendente', 'aprovado'])
                                    ->pluck('tipo_documento_resposta_id')
                                    ->filter()
                                    ->values();
                            @endphp
                            <button @click="docRespostaId = {{ $docPrazo->id }}; docRespostaNome = '{{ $docPrazo->tipoDocumento->nome ?? 'Documento' }}'; docRespostaTipos = {{ $tiposRespPrazo->toJson() }}; docRespostaEnviados = {{ $enviadosPrazo->toJson() }}; arquivosResposta = []; modalResposta = true"
                                    class="px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 transition inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                Anexar Resposta
                            </button>
                            @endif
                        </div>
                    </div>

                    @if($docPrazo->todasAssinaturasCompletas())
                        @include('company.processos.partials.itens-atendimento', ['docDigital' => $docPrazo, 'compacto' => true])
                    @endif

                    {{-- Respostas já enviadas para este documento --}}
                    @if($docPrazo->respostas->whereNull('documento_item_atendimento_id')->count() > 0)
                    <div class="mt-2 ml-11 space-y-1">
                        @foreach($docPrazo->respostas->whereNull('documento_item_atendimento_id') as $resp)
                        <div class="flex items-center gap-2 text-[11px]">
                            <span class="w-1.5 h-1.5 rounded-full flex-shrink-0
                                {{ $resp->status === 'aprovado' ? 'bg-green-500' : ($resp->status === 'rejeitado' ? 'bg-red-500' : 'bg-yellow-500') }}"></span>
                            <span class="text-slate-600 truncate">{{ $resp->nome_original }}</span>
                            <span class="text-slate-400">·</span>
                            <span class="{{ $resp->status === 'aprovado' ? 'text-green-600' : ($resp->status === 'rejeitado' ? 'text-red-600' : 'text-yellow-600') }} font-medium">
                                {{ $resp->status === 'aprovado' ? 'Verificado' : ($resp->status === 'rejeitado' ? 'Rejeitado' : 'Pendente') }}
                            </span>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Layout 2 colunas: Menu (esquerda) + Documentos (direita) --}}
    <div class="flex flex-col lg:flex-row gap-6">
        {{-- Coluna Esquerda: Menu --}}
        <div class="flex-shrink-0 space-y-4" style="width: 320px; min-width: 320px;">
            {{-- Documentos de Ajuda --}}
            @if(isset($documentosAjuda) && $documentosAjuda->count() > 0)
            <div class="bg-emerald-50 rounded-lg shadow-sm border border-emerald-200 p-2">
                <h3 class="text-xs font-semibold text-emerald-800 uppercase mb-2 flex items-center gap-1 px-1">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Ajuda
                </h3>
                <div class="space-y-1">
                    @foreach($documentosAjuda as $docAjuda)
                    <a href="{{ route('company.processos.documento-ajuda', [$processo->id, $docAjuda->id]) }}" 
                       target="_blank"
                       class="flex items-center gap-2 p-1.5 bg-white rounded-md border border-emerald-200 hover:border-emerald-400 hover:bg-emerald-50 transition-colors group">
                        <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M14,2H6A2,2 0 0,0 4,4V20A2,2 0 0,0 6,22H18A2,2 0 0,0 20,20V8L14,2M18,20H6V4H13V9H18V20Z"/>
                        </svg>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-medium text-slate-900 truncate">{{ $docAjuda->titulo }}</p>
                        </div>
                        <svg class="w-3 h-3 text-slate-400 flex-shrink-0 opacity-0 group-hover:opacity-100 transition-opacity" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>
                    </a>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Progresso dos Documentos Obrigatórios --}}
            @php
                // Docs base (sem unidade) - sempre existem
                $totalObrigatorios = isset($documentosObrigatorios) ? $documentosObrigatorios->where('obrigatorio', true)->count() : 0;
                $enviadosOuAprovados = isset($documentosObrigatorios) ? $documentosObrigatorios->where('obrigatorio', true)->whereIn('status_envio', ['pendente', 'aprovado'])->count() : 0;
                $aprovados = isset($documentosObrigatorios) ? $documentosObrigatorios->where('obrigatorio', true)->where('status_envio', 'aprovado')->count() : 0;
                $aguardandoAprovacao = isset($documentosObrigatorios) ? $documentosObrigatorios->where('obrigatorio', true)->where('status_envio', 'pendente')->count() : 0;

                // Soma docs das unidades (adicionais)
                if ($processo->unidades->count() > 0 && !empty($documentosObrigatoriosPorUnidade)) {
                    foreach ($documentosObrigatoriosPorUnidade as $info) {
                        $docsObrig = $info['documentos']->where('obrigatorio', true);
                        $totalObrigatorios += $docsObrig->count();
                        $enviadosOuAprovados += $docsObrig->whereIn('status_envio', ['pendente', 'aprovado'])->count();
                        $aprovados += $docsObrig->where('status_envio', 'aprovado')->count();
                        $aguardandoAprovacao += $docsObrig->where('status_envio', 'pendente')->count();
                    }
                }

                $percentual = $totalObrigatorios > 0 ? round(($enviadosOuAprovados / $totalObrigatorios) * 100) : 0;
                $faltam = $totalObrigatorios - $enviadosOuAprovados;
                $todosAprovados = ($aprovados == $totalObrigatorios && $totalObrigatorios > 0);
                $todosEnviados = ($percentual == 100 && $totalObrigatorios > 0);
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Documentos Obrigatórios
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold {{ $todosAprovados ? 'text-green-600' : ($todosEnviados ? 'text-amber-600' : ($totalObrigatorios == 0 ? 'text-slate-400' : 'text-blue-600')) }}">
                            {{ $enviadosOuAprovados }}/{{ $totalObrigatorios }}
                        </span>
                        @if($totalObrigatorios > 0)
                        <span class="text-xs font-bold px-2 py-0.5 rounded-full {{ $todosAprovados ? 'bg-green-100 text-green-700' : ($todosEnviados ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700') }}">
                            {{ $percentual }}%
                        </span>
                        @endif
                    </div>
                </div>
                
                {{-- Barra de Progresso --}}
                <div class="relative">
                    <div class="w-full bg-slate-200 rounded-full h-4 overflow-hidden flex">
                        @if($totalObrigatorios > 0)
                            @php
                                $pctAprovados = round(($aprovados / $totalObrigatorios) * 100);
                                $pctPendentes = round(($aguardandoAprovacao / $totalObrigatorios) * 100);
                            @endphp
                            @if($aprovados > 0)
                            <div class="h-full transition-all duration-500 ease-out bg-green-500 flex items-center justify-center {{ $aguardandoAprovacao == 0 ? 'rounded-full' : 'rounded-l-full' }}" 
                                 style="width: {{ $pctAprovados }}%">
                                @if($pctAprovados >= 20)
                                <span class="text-[10px] font-bold text-white">{{ $pctAprovados }}%</span>
                                @endif
                            </div>
                            @endif
                            @if($aguardandoAprovacao > 0)
                            <div class="h-full transition-all duration-500 ease-out bg-amber-500 flex items-center justify-center {{ $aprovados == 0 ? 'rounded-l-full' : '' }} rounded-r-full" 
                                 style="width: {{ $pctPendentes }}%">
                                @if($pctPendentes >= 20)
                                <span class="text-[10px] font-bold text-white">{{ $pctPendentes }}%</span>
                                @endif
                            </div>
                            @endif
                            @if($percentual == 0)
                            <div class="h-full rounded-full bg-blue-500" style="width: 0%"></div>
                            @endif
                        @else
                        <div class="h-full rounded-full bg-slate-300" style="width: 100%"></div>
                        @endif
                    </div>
                    @if($todosAprovados)
                    <div class="absolute -top-1 -right-1">
                        <span class="flex h-5 w-5">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-5 w-5 bg-green-500 items-center justify-center">
                                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                </svg>
                            </span>
                        </span>
                    </div>
                    @elseif($todosEnviados && $aguardandoAprovacao > 0)
                    <div class="absolute -top-1 -right-1">
                        <span class="relative inline-flex rounded-full h-5 w-5 bg-amber-500 items-center justify-center animate-pulse">
                            <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                        </span>
                    </div>
                    @endif
                </div>
                
                {{-- Status --}}
                <div class="mt-3">
                    @if($totalObrigatorios == 0)
                    <p class="text-xs text-slate-500 flex items-center gap-1">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Nenhum documento obrigatório configurado
                    </p>
                    @elseif($todosAprovados)
                    <p class="text-xs text-green-600 font-medium flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Todos os documentos foram aprovados!
                    </p>
                    @elseif($todosEnviados && $aguardandoAprovacao > 0)
                    <p class="text-xs text-amber-600 font-medium flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Todos enviados! <span class="font-bold">{{ $aguardandoAprovacao }}</span> aguardando aprovação
                    </p>
                    @else
                    <p class="text-xs text-slate-500">
                        <span class="font-medium text-amber-600">{{ $faltam }}</span> documento(s) obrigatório(s) pendente(s)
                    </p>
                    <button @click="modalUpload = true" class="mt-2 text-xs text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Enviar documentos
                    </button>
                    @endif
                </div>

                {{-- Progresso por Unidade --}}
                @if(!empty($documentosObrigatoriosPorUnidade) && count($documentosObrigatoriosPorUnidade) > 0)
                <div class="mt-3 pt-3 border-t border-slate-100 space-y-2">
                    <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Por Unidade</p>
                    @foreach($documentosObrigatoriosPorUnidade as $pastaId => $info)
                    @php
                        $pctUnidade = $info['total'] > 0 ? round(($info['enviados'] / $info['total']) * 100) : 0;
                    @endphp
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-600 w-24 truncate" title="{{ $info['nome'] }}">{{ $info['nome'] }}</span>
                        <div class="flex-1 bg-slate-200 rounded-full h-2 overflow-hidden">
                            <div class="h-full rounded-full transition-all {{ $pctUnidade === 100 ? 'bg-green-500' : 'bg-violet-500' }}" style="width: {{ $pctUnidade }}%"></div>
                        </div>
                        <span class="text-[10px] font-bold {{ $pctUnidade === 100 ? 'text-green-600' : 'text-violet-600' }}">{{ $info['enviados'] }}/{{ $info['total'] }}</span>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>

            {{-- Menu de Opções --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4">
                <h3 class="text-sm font-semibold text-slate-900 uppercase mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    Menu
                </h3>
                <div class="space-y-1">
                    @if($processo->status !== 'arquivado')
                    <button @click="modalUpload = true" class="w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Upload de Arquivos
                    </button>
                    @if(isset($tipoProcessoTemUnidades) && $tipoProcessoTemUnidades)
                    <button @click="$refs.modalNovaUnidade.classList.remove('hidden')" class="w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-violet-50 hover:text-violet-700 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-violet-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                        </svg>
                        Solicitar Nova Unidade
                    </button>
                    @endif
                    @endif
                    <button @click="modalAlertas = true" class="w-full flex items-center gap-3 px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-orange-50 hover:text-orange-700 rounded-lg transition-colors">
                        <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        Alertas
                        @if($alertas->where('status', 'pendente')->count() > 0)
                        <span class="ml-auto px-2 py-0.5 bg-red-100 text-red-700 text-xs font-bold rounded-full">
                            {{ $alertas->where('status', 'pendente')->count() }}
                        </span>
                        @endif
                    </button>
                </div>
            </div>

            {{-- Resumo --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4">
                <h3 class="text-sm font-semibold text-slate-900 uppercase mb-3">Resumo</h3>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-slate-500">Documentos</span>
                        <span class="font-medium text-slate-900">{{ $documentosAprovados->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Pendentes</span>
                        <span id="pendentes-count-resumo" class="font-medium text-yellow-600">{{ $documentosPendentes->count() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-slate-500">Alertas</span>
                        <span class="font-medium text-orange-600">{{ $alertas->where('status', 'pendente')->count() }}</span>
                    </div>
                </div>
            </div>

            {{-- Protocolo do Processo --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4">
                <a href="{{ route('company.processos.protocolo', $processo->id) }}" 
                   target="_blank"
                   class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Protocolo do Processo
                </a>
                <p class="text-xs text-slate-500 text-center mt-2">Comprovante de abertura do processo</p>
            </div>
        </div>

        {{-- Coluna Direita: Documentos --}}
        <div class="flex-1 space-y-4">
            {{-- Documentos Pendentes --}}
            <div id="pendentes-wrapper" class="bg-yellow-50 rounded-xl shadow-sm border border-yellow-200 overflow-hidden {{ $documentosPendentes->count() > 0 ? '' : 'hidden' }}">
                <div class="px-4 py-3 border-b border-yellow-200 bg-yellow-100">
                    <h2 class="text-sm font-semibold text-yellow-800 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Aguardando Aprovação
                        <span id="pendentes-count" class="px-2 py-0.5 bg-yellow-200 text-yellow-800 text-xs font-bold rounded-full">{{ $documentosPendentes->count() }}</span>
                    </h2>
                </div>
                <div id="pendentes-list" class="divide-y divide-yellow-200">
                    @foreach($documentosPendentes as $documento)
                    <div class="px-4 py-3 flex items-start justify-between hover:bg-yellow-100/50 gap-3" data-pendente-doc-id="{{ $documento->id }}">
                        <button type="button" 
                                @click="documentoUrl = '{{ route('company.processos.documento.visualizar', [$processo->id, $documento->id]) }}'; documentoNome = '{{ $documento->nome_original }}'; documentoExtensao = '{{ $documento->extensao }}'; modalVisualizador = true"
                                class="flex items-start gap-3 text-left flex-1 min-w-0">
                            <span class="text-xl flex-shrink-0">{{ $documento->icone }}</span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900 hover:text-blue-600 break-words">{{ $documento->nome_original }}</p>
                                <p class="text-xs text-slate-500">{{ $documento->tamanho_formatado }} • {{ $documento->created_at->format('d/m/Y H:i') }}</p>
                            </div>
                        </button>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="px-2 py-1 bg-yellow-200 text-yellow-800 text-xs font-medium rounded">Pendente</span>
                            @if($documento->usuario_externo_id == auth('externo')->id())
                            <form action="{{ route('company.processos.documento.delete', [$processo->id, $documento->id]) }}" method="POST" onsubmit="return confirm('Tem certeza que deseja excluir este arquivo?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded transition-colors" title="Excluir">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            <div id="pendentes-empty" class="bg-yellow-50 rounded-xl shadow-sm border border-yellow-200 p-6 text-center {{ $documentosPendentes->count() === 0 ? '' : 'hidden' }}">
                <svg class="w-10 h-10 text-yellow-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm text-yellow-800 font-medium">Nenhum documento pendente no momento</p>
                <p class="text-xs text-yellow-700 mt-1">Envios recentes aparecem aqui automaticamente.</p>
            </div>

            {{-- Documentos Rejeitados --}}
            @if($documentosRejeitados->count() > 0)
            <div class="bg-red-50 rounded-xl shadow-sm border border-red-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-red-200 bg-red-100">
                    <h2 class="text-sm font-semibold text-red-800 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Arquivos Rejeitados
                        <span class="px-2 py-0.5 bg-red-200 text-red-800 text-xs font-bold rounded-full">{{ $documentosRejeitados->count() }}</span>
                    </h2>
                </div>
                <div class="divide-y divide-red-200">
                    @foreach($documentosRejeitados as $documento)
                    <div class="px-4 py-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0 flex-1">
                                <span class="text-xl flex-shrink-0">{{ $documento->icone }}</span>
                                <div class="min-w-0">
                                    <button type="button"
                                            @click="documentoUrl = '{{ route('company.processos.documento.visualizar', [$processo->id, $documento->id]) }}'; documentoNome = '{{ $documento->nome_original }}'; documentoExtensao = '{{ $documento->extensao }}'; modalVisualizador = true"
                                            class="text-sm font-medium text-blue-600 hover:text-blue-800 hover:underline text-left break-words">
                                        {{ $documento->nome_original }}
                                    </button>
                                    <p class="text-xs text-slate-500">{{ $documento->tamanho_formatado }} • {{ $documento->created_at->format('d/m/Y H:i') }}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="px-2 py-1 bg-red-200 text-red-800 text-xs font-medium rounded">Rejeitado</span>
                                @if($processo->status !== 'arquivado')
                                <button type="button"
                                    @click.prevent="docReenvioId = {{ $documento->id }}; docReenvioNome = @js($documento->nome_original); docReenvioMotivo = @js($documento->motivo_rejeicao ?? ''); modalReenvio = true" 
                                        class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                    Reenviar
                                </button>
                                @endif
                            </div>
                        </div>
                        @if($documento->motivo_rejeicao)
                        <div class="mt-2 ml-9 p-2 bg-red-100 rounded text-xs text-red-700">
                            <strong>Motivo:</strong> {{ $documento->motivo_rejeicao }}
                        </div>
                        @endif
                        
                        {{-- Histórico de Rejeições Anteriores --}}
                        @if($documento->historico_rejeicao && count($documento->historico_rejeicao) > 0)
                        <div class="mt-2 ml-9 p-2 bg-slate-100 rounded text-xs text-slate-600" x-data="{ showHistorico: false }">
                            <button type="button" @click="showHistorico = !showHistorico" class="flex items-center gap-1 text-slate-700 hover:text-slate-900">
                                <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-90': showHistorico }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <strong>Histórico de rejeições ({{ count($documento->historico_rejeicao) }})</strong>
                            </button>
                            <div x-show="showHistorico" x-transition class="mt-2 space-y-2" style="display: none;">
                                @foreach($documento->historico_rejeicao as $index => $rejeicao)
                                <div class="p-2 bg-white rounded border border-slate-200">
                                    <p class="text-[10px] text-slate-500">Tentativa {{ $index + 1 }} - {{ \Carbon\Carbon::parse($rejeicao['rejeitado_em'])->format('d/m/Y H:i') }}</p>
                                    <p class="text-xs text-slate-700"><strong>Arquivo:</strong> {{ $rejeicao['arquivo_anterior'] }}</p>
                                    <p class="text-xs text-red-600"><strong>Motivo:</strong> {{ $rejeicao['motivo'] }}</p>
                                </div>
                                @endforeach
                            </div>
                        </div>
                        @endif
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Lista de Documentos e Arquivos do Processo --}}
            @php
                $totalDocumentos = isset($todosDocumentos) ? $todosDocumentos->count() : 0;
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden" x-data="{ pastaAtiva: null }">
                <div class="px-4 py-3 border-b border-slate-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between bg-gradient-to-r from-gray-50 to-white">
                    <div>
                    <h2 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        Lista de Documentos/Arquivos
                    </h2>
                    <p class="text-[11px] text-slate-500 mt-0.5">{{ $totalDocumentos }} item(ns) neste processo</p>
                    </div>
                    @if($processo->status !== 'arquivado')
                    <button @click="modalUpload = true" class="w-full sm:w-auto px-3 py-2 bg-blue-600 text-white text-xs font-medium rounded-lg hover:bg-blue-700 transition-colors inline-flex items-center justify-center gap-1.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Enviar Arquivo
                    </button>
                    @endif
                </div>
                
                {{-- Abas de Pastas --}}
                @if($pastas->count() > 0)
                <div class="border-b border-slate-200 bg-slate-50">
                    <nav class="flex px-3 sm:px-4 overflow-x-auto" aria-label="Tabs">
                        {{-- Aba "Todos" --}}
                        <button @click="pastaAtiva = null" 
                                :class="pastaAtiva === null ? 'text-blue-600 border-blue-600' : 'text-slate-500 border-transparent hover:text-slate-700 hover:border-slate-300'"
                                class="px-3 py-2.5 text-[11px] sm:text-xs font-medium border-b-2 transition-colors whitespace-nowrap">
                            Todos
                            <span class="ml-1.5 px-1.5 py-0.5 text-[10px] rounded-full"
                                  :class="pastaAtiva === null ? 'bg-blue-100 text-blue-600' : 'bg-slate-200 text-slate-600'">
                                {{ $totalDocumentos }}
                            </span>
                        </button>
                        
                        {{-- Abas das Pastas --}}
                        @foreach($pastas as $pasta)
                        @php
                            $docsNaPasta = $todosDocumentos->where('pasta_id', $pasta->id)->count();
                        @endphp
                        <button @click="pastaAtiva = {{ $pasta->id }}"
                                :class="pastaAtiva === {{ $pasta->id }} ? 'border-b-2' : 'text-slate-500 border-transparent hover:text-slate-700 hover:border-slate-300'"
                                :style="pastaAtiva === {{ $pasta->id }} ? 'color: {{ $pasta->cor }}; border-color: {{ $pasta->cor }}' : ''"
                                class="px-3 py-2.5 text-[11px] sm:text-xs font-medium border-b-2 transition-colors whitespace-nowrap flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" :style="pastaAtiva === {{ $pasta->id }} ? 'color: {{ $pasta->cor }}' : ''">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                            </svg>
                            {{ $pasta->nome }}
                            @if($docsNaPasta > 0)
                            <span class="px-1.5 py-0.5 text-[10px] rounded-full"
                                  :class="pastaAtiva === {{ $pasta->id }} ? 'bg-opacity-20' : 'bg-slate-200 text-slate-600'"
                                  :style="pastaAtiva === {{ $pasta->id }} ? 'background-color: {{ $pasta->cor }}20; color: {{ $pasta->cor }}' : ''">
                                {{ $docsNaPasta }}
                            </span>
                            @endif
                        </button>
                        @endforeach

                    </nav>
                </div>
                @endif
                
                @if($totalDocumentos > 0)
                @php
                    $pastasPorId = $pastas->keyBy('id');
                    $contagemPorGrupoPasta = $todosDocumentos
                        ->groupBy(fn($item) => $item['pasta_id'] ?? 'sem_pasta')
                        ->map(fn($itens) => $itens->count());
                @endphp
                <div class="divide-y divide-slate-100">
                    @foreach($todosDocumentos as $indice => $item)
                        @php
                            $pastaAtualId = $item['pasta_id'] ?? null;
                            $chaveGrupoAtual = $pastaAtualId ?? 'sem_pasta';

                            $itemAnterior = $indice > 0 ? $todosDocumentos[$indice - 1] : null;
                            $pastaAnteriorId = $itemAnterior ? ($itemAnterior['pasta_id'] ?? null) : '__inicio__';
                            $chaveGrupoAnterior = $pastaAnteriorId ?? 'sem_pasta';

                            $mostrarCabecalhoGrupo = $indice === 0 || $chaveGrupoAtual !== $chaveGrupoAnterior;

                            $pastaAtual = $pastaAtualId ? $pastasPorId->get($pastaAtualId) : null;
                            $nomeGrupo = $pastaAtual ? $pastaAtual->nome : 'Sem pasta';
                            $corGrupo = $pastaAtual ? $pastaAtual->cor : '#9CA3AF';
                            $contagemGrupo = $contagemPorGrupoPasta[$chaveGrupoAtual] ?? 0;
                        @endphp

                        @if($mostrarCabecalhoGrupo)
                            <div x-show="pastaAtiva === null"
                                class="px-4 py-2.5 bg-slate-50/80 border-l-4"
                             style="display: none; border-left-color: {{ $corGrupo }};">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full" style="background-color: {{ $corGrupo }}"></span>
                                    <span class="text-xs font-semibold text-slate-700">{{ $nomeGrupo }}</span>
                                </div>
                                <span class="px-2 py-0.5 text-[10px] font-semibold bg-white border border-slate-200 text-slate-600 rounded-full">
                                    {{ $contagemGrupo }}
                                </span>
                            </div>
                        </div>
                        @endif

                        @if($item['tipo'] === 'vigilancia')
                            @php 
                                $docDigital = $item['documento'];
                                // Determinar cor da borda para documentos da vigilância
                                // Verde: documento assinado e sem prazo pendente
                                // Laranja: documento com prazo pendente ou respostas pendentes
                                // Cinza: outros casos
                                $temRespostaPendente = $docDigital->respostas->where('status', 'pendente')->count() > 0;
                                $temRespostaRejeitada = $docDigital->respostas->where('status', 'rejeitado')->count() > 0;
                                $temPrazoPendente = $docDigital->temPrazo() && !$docDigital->isPrazoFinalizado() && $docDigital->status === 'assinado';
                                $precisaVisualizarDocDigital = $docDigital->prazo_notificacao && !$docDigital->prazo_iniciado_em;
                                
                                if ($temRespostaRejeitada) {
                                    $corBordaDoc = 'border-red-500';
                                } elseif ($temRespostaPendente || $temPrazoPendente) {
                                    $corBordaDoc = 'border-orange-500';
                                } elseif ($docDigital->status === 'assinado') {
                                    $corBordaDoc = 'border-green-500';
                                } else {
                                    $corBordaDoc = 'border-slate-300';
                                }
                            @endphp
                            <div x-show="pastaAtiva === null || pastaAtiva === {{ $item['pasta_id'] ?? 'null' }}"
                                 class="px-4 py-3 hover:bg-slate-50 border-l-4 {{ $corBordaDoc }}">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                    <a href="{{ route('company.processos.documento-digital.visualizar', [$processo->id, $docDigital->id]) }}" 
                                       target="_blank"
                                       class="flex items-start gap-3 flex-1 min-w-0">
                                        <div class="w-10 h-10 rounded-lg bg-red-100 text-red-600 flex items-center justify-center flex-shrink-0">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold text-slate-900 hover:text-blue-600 break-words leading-tight">
                                                {{ $docDigital->tipoDocumento->nome ?? 'Documento' }}
                                            </p>
                                            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1 text-xs text-slate-500">
                                                <span>Nº {{ $docDigital->numero_documento }}</span>
                                                <span title="Data de conclusão das assinaturas e liberação do documento no portal">{{ $docDigital->data_disponibilizacao ? 'Disponível desde ' . $docDigital->data_disponibilizacao->format('d/m/Y H:i') : 'Data de disponibilização não registrada' }}</span>
                                                <span class="text-blue-600 font-medium">Vigilância Sanitária</span>
                                            </div>
                                        </div>
                                    </a>
                                    <div class="flex flex-wrap items-center gap-2 flex-shrink-0 lg:justify-end">
                                        {{-- Badge de Prazo --}}
                                        @if($docDigital->temPrazo())
                                            @php
                                                $corBadge = $docDigital->cor_status_prazo;
                                                $textoBadge = $docDigital->texto_status_prazo;
                                                $classesCor = [
                                                    'red' => 'bg-red-100 text-red-700 border-red-200',
                                                    'yellow' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
                                                    'green' => 'bg-green-100 text-green-700 border-green-200',
                                                    'blue' => 'bg-blue-100 text-blue-700 border-blue-200',
                                                    'gray' => 'bg-slate-100 text-slate-700 border-slate-200',
                                                ];
                                                $classeBadge = $classesCor[$corBadge] ?? $classesCor['gray'];
                                            @endphp
                                            <span class="px-2 py-1 {{ $classeBadge }} border rounded-full text-[11px] font-medium whitespace-nowrap inline-flex items-center gap-1">
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                </svg>
                                                {{ $textoBadge }}
                                            </span>
                                        @endif
                                        <a href="{{ route('company.processos.documento-digital.visualizar', [$processo->id, $docDigital->id]) }}"
                                           target="_blank"
                                           @if($precisaVisualizarDocDigital) onclick="recarregarAposVisualizarDocumentoPrazo()" @endif
                                           class="px-3 py-1.5 bg-blue-600 text-white text-xs font-semibold rounded-lg hover:bg-blue-700 transition-colors inline-flex items-center gap-1 shadow-sm">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.25 12s3.75-6.75 9.75-6.75S21.75 12 21.75 12 18 18.75 12 18.75 2.25 12 2.25 12Z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/>
                                            </svg>
                                            Visualizar
                                        </a>
                                        <a href="{{ route('company.processos.documento-digital.download', [$processo->id, $docDigital->id]) }}" 
                                                         class="px-3 py-1.5 bg-blue-100 text-blue-700 text-xs font-medium rounded-lg hover:bg-blue-200 transition-colors inline-flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                            </svg>
                                            Download
                                        </a>
                                        @if($docDigital->permiteResposta() && $docDigital->itensAtendimento->isEmpty() && !$precisaVisualizarDocDigital)
                                        @php
                                            $setorEstab = $processo->estabelecimento?->tipo_setor;
                                            $setorEstab = $setorEstab instanceof \App\Enums\TipoSetor ? $setorEstab->value : ($setorEstab ?? 'privado');
                                            $tiposResp = ($docDigital->tipoDocumento?->tiposDocumentoResposta ?? collect())
                                                ->filter(function($tr) use ($setorEstab) { return $tr->tipo_setor === 'todos' || $tr->tipo_setor === $setorEstab; })
                                                ->map(function($tr) { return ['id' => $tr->id, 'nome' => $tr->nome, 'descricao' => $tr->descricao]; })
                                                ->values();
                                            $enviadosDoc = ($docDigital->respostas ?? collect())
                                                ->whereIn('status', ['pendente', 'aprovado'])
                                                ->pluck('tipo_documento_resposta_id')
                                                ->filter()
                                                ->values();
                                        @endphp
                                        <button type="button"
                                                @click="docRespostaId = {{ $docDigital->id }}; docRespostaNome = '{{ $docDigital->tipoDocumento->nome ?? 'Documento' }}'; docRespostaTipos = {{ $tiposResp->toJson() }}; docRespostaEnviados = {{ $enviadosDoc->toJson() }}; arquivosResposta = []; modalResposta = true"
                                                class="px-3 py-1.5 bg-green-100 text-green-700 text-xs font-medium rounded-lg hover:bg-green-200 transition-colors inline-flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                            </svg>
                                            Responder
                                        </button>
                                        @elseif($docDigital->temPrazo() && $docDigital->isPrazoFinalizado())
                                        <span class="px-3 py-1.5 bg-green-100 text-green-700 text-xs font-semibold rounded-lg inline-flex items-center gap-1">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            Finalizado
                                        </span>
                                        @endif
                                    </div>
                                </div>
                                
                                @if($docDigital->todasAssinaturasCompletas())
                                    @include('company.processos.partials.itens-atendimento', ['docDigital' => $docDigital, 'compacto' => false])
                                @endif

                                {{-- Respostas vinculadas a este documento --}}
                                @php
                                    $respostasGerais = $docDigital->respostas
                                        ->whereNull('documento_item_atendimento_id');
                                @endphp
                                @if($respostasGerais->count() > 0)
                                @php
                                    $respostasAprovadas = $respostasGerais->where('status', 'aprovado');
                                    $respostasPendentes = $respostasGerais->where('status', 'pendente');
                                    $respostasRejeitadas = $respostasGerais->where('status', 'rejeitado');
                                    $totalRejeicoes = $respostasGerais->sum(function($r) {
                                        return $r->historico_rejeicao ? count($r->historico_rejeicao) : 0;
                                    });
                                @endphp
                                <div class="mt-3 sm:ml-12 border-l-2 border-green-200 pl-3">
                                    {{-- Resumo das respostas --}}
                                    <div class="flex flex-wrap items-center gap-2 mb-2">
                                        <span class="text-xs font-semibold text-slate-700">Respostas gerais ({{ $respostasGerais->count() }})</span>
                                        @if($respostasAprovadas->count() > 0)
                                        <span class="px-1.5 py-0.5 text-[10px] font-medium bg-green-100 text-green-700 rounded">{{ $respostasAprovadas->count() }} verificado(s)</span>
                                        @endif
                                        @if($respostasPendentes->count() > 0)
                                        <span class="px-1.5 py-0.5 text-[10px] font-medium bg-yellow-100 text-yellow-700 rounded">{{ $respostasPendentes->count() }} pendente(s)</span>
                                        @endif
                                        @if($respostasRejeitadas->count() > 0)
                                        <span class="px-1.5 py-0.5 text-[10px] font-medium bg-red-100 text-red-700 rounded">{{ $respostasRejeitadas->count() }} rejeitado(s)</span>
                                        @endif
                                    </div>
                                    
                                    @foreach($respostasGerais as $resposta)
                                    <div class="flex flex-col gap-2 py-2 {{ !$loop->last ? 'border-b border-slate-100' : '' }} sm:flex-row sm:items-center sm:justify-between">
                                        <div class="flex items-start gap-2 min-w-0">
                                            <svg class="w-4 h-4 {{ $resposta->status === 'aprovado' ? 'text-green-500' : ($resposta->status === 'rejeitado' ? 'text-red-500' : 'text-yellow-500') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                            <div class="min-w-0">
                                                <p class="text-xs font-medium text-slate-700 break-words">{{ $resposta->nome_original }}</p>
                                                <p class="text-[10px] text-slate-500">
                                                    {{ $resposta->tamanho_formatado }}
                                                    <span class="mx-1">•</span>
                                                    {{ $resposta->created_at->format('d/m H:i') }}
                                                    <span class="mx-1">•</span>
                                                    {{ $resposta->usuarioExterno->nome ?? 'Usuário' }}
                                                    @if($resposta->status === 'aprovado' && $resposta->avaliadoPor)
                                                    <span class="mx-1">•</span>
                                                    <span class="text-green-600">Verificado por {{ $resposta->avaliadoPor->nome }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2 sm:justify-end">
                                            <span class="px-2 py-0.5 text-[10px] font-medium rounded-full
                                                @if($resposta->status === 'pendente') bg-yellow-100 text-yellow-700
                                                @elseif($resposta->status === 'aprovado') bg-green-100 text-green-700
                                                @else bg-red-100 text-red-700
                                                @endif">
                                                {{ $resposta->status === 'aprovado' ? 'Verificado' : ($resposta->status === 'rejeitado' ? 'Rejeitado' : 'Pendente') }}
                                            </span>
                                            <a href="{{ route('company.processos.documento-digital.resposta.visualizar', [$processo->id, $docDigital->id, $resposta->id]) }}"
                                               target="_blank"
                                               class="p-1 text-blue-600 hover:bg-blue-50 rounded" title="Visualizar">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </a>
                                            <a href="{{ route('company.processos.documento-digital.resposta.download', [$processo->id, $docDigital->id, $resposta->id]) }}"
                                               class="p-1 text-slate-600 hover:bg-slate-100 rounded" title="Download">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                                </svg>
                                            </a>
                                            @if($resposta->status === 'pendente')
                                            <form action="{{ route('company.processos.documento-digital.resposta.excluir', [$processo->id, $docDigital->id, $resposta->id]) }}" 
                                                  method="POST" 
                                                  class="inline"
                                                  onsubmit="return confirm('Tem certeza que deseja excluir esta resposta?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" 
                                                        class="p-1 text-red-600 hover:bg-red-50 rounded" 
                                                        title="Excluir resposta">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                    </svg>
                                                </button>
                                            </form>
                                            @endif
                                        </div>
                                    </div>
                                    @if($resposta->status === 'rejeitado' && $resposta->motivo_rejeicao)
                                    <div class="py-1.5 px-3 mb-2 bg-red-50 border border-red-200 rounded-lg text-xs text-red-700">
                                        <div class="flex items-start gap-2">
                                            <svg class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <div>
                                                <strong>Motivo da rejeição:</strong> {{ $resposta->motivo_rejeicao }}
                                            </div>
                                        </div>
                                    </div>
                                    @endif
                                    @endforeach
                                    
                                    {{-- Histórico de Rejeições Consolidado --}}
                                    @if($totalRejeicoes > 0)
                                    <div class="mt-3 p-3 bg-orange-50 border border-orange-200 rounded-lg" x-data="{ showHistorico: false }">
                                        <button type="button" @click="showHistorico = !showHistorico" class="flex items-center gap-2 text-orange-800 hover:text-orange-900 w-full">
                                            <svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <span class="text-xs font-semibold">Histórico de rejeições ({{ $totalRejeicoes }})</span>
                                            <svg class="w-3 h-3 ml-auto transition-transform" :class="{ 'rotate-180': showHistorico }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                            </svg>
                                        </button>
                                        <div x-show="showHistorico" x-transition class="mt-3 space-y-2" style="display: none;">
                                            @foreach($respostasGerais as $resposta)
                                                @if($resposta->historico_rejeicao && count($resposta->historico_rejeicao) > 0)
                                                    @foreach($resposta->historico_rejeicao as $index => $rejeicao)
                                                    <div class="p-2.5 bg-white rounded-lg border border-orange-200">
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="px-1.5 py-0.5 text-[10px] font-medium bg-orange-100 text-orange-700 rounded">Tentativa {{ $index + 1 }}</span>
                                                            <span class="text-[10px] text-slate-500">{{ \Carbon\Carbon::parse($rejeicao['rejeitado_em'])->format('d/m/Y H:i') }}</span>
                                                        </div>
                                                        <p class="text-xs text-slate-700">
                                                            <strong>Arquivo:</strong> {{ $rejeicao['arquivo_anterior'] }}
                                                        </p>
                                                        <p class="text-xs text-red-600 mt-1">
                                                            <strong>Motivo:</strong> {{ $rejeicao['motivo'] }}
                                                        </p>
                                                    </div>
                                                    @endforeach
                                                @endif
                                            @endforeach
                                        </div>
                                    </div>
                                    @endif
                                </div>
                                @endif
                            </div>
                        @else
                            @php 
                                $documento = $item['documento'];
                                // Determinar cor da borda para arquivos do usuário
                                // Se o tipo é 'aprovado' (vem da collection documentosAprovados), é verde
                                // Caso contrário, verifica o status_aprovacao
                                if ($item['tipo'] === 'aprovado') {
                                    $corBordaArquivo = 'border-green-500';
                                } elseif ($documento->status_aprovacao === 'rejeitado') {
                                    $corBordaArquivo = 'border-red-500';
                                } elseif ($documento->status_aprovacao === 'pendente') {
                                    $corBordaArquivo = 'border-orange-500';
                                } elseif ($documento->status_aprovacao === 'aprovado') {
                                    $corBordaArquivo = 'border-green-500';
                                } else {
                                    $corBordaArquivo = 'border-slate-300';
                                }
                            @endphp
                            <div x-show="pastaAtiva === null || pastaAtiva === {{ $item['pasta_id'] ?? 'null' }}"
                                 class="px-4 py-3 hover:bg-slate-50 border-l-4 {{ $corBordaArquivo }}">
                                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                <button type="button" 
                                        @click="documentoUrl = '{{ route('company.processos.documento.visualizar', [$processo->id, $documento->id]) }}'; documentoNome = '{{ $documento->nome_original }}'; documentoExtensao = '{{ $documento->extensao }}'; modalVisualizador = true"
                                        class="flex items-start gap-3 text-left flex-1 min-w-0">
                                    <span class="text-xl flex-shrink-0">{{ $documento->icone }}</span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-slate-900 hover:text-blue-600 break-words leading-tight">{{ $documento->nome_original }}</p>
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mt-1 text-xs text-slate-500">
                                            <span>{{ $documento->tamanho_formatado }}</span>
                                            <span>{{ $documento->created_at->format('d/m/Y H:i') }}</span>
                                            @if($documento->tipo_usuario === 'externo')
                                            <span class="text-green-600 font-medium">Usuário Externo</span>
                                            @else
                                            <span class="text-blue-600 font-medium">Vigilância Sanitária</span>
                                            @endif
                                        </div>
                                    </div>
                                </button>
                                <div class="flex flex-wrap items-center gap-2 flex-shrink-0 lg:justify-end">
                                    <a href="{{ route('company.processos.download', [$processo->id, $documento->id]) }}" 
                                       class="px-3 py-1.5 bg-slate-100 text-slate-700 text-xs font-medium rounded-lg hover:bg-slate-200 transition-colors inline-flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                        </svg>
                                        Download
                                    </a>
                                </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
                @else
                <div class="px-4 py-8 text-center">
                    <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                    <p class="mt-2 text-sm text-slate-500">Nenhum documento no processo</p>
                    @if($processo->status !== 'arquivado')
                    <button @click="modalUpload = true" class="mt-3 text-sm text-blue-600 hover:text-blue-700 font-medium">
                        Enviar primeiro arquivo →
                    </button>
                    @endif
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Modal Upload --}}
    @include('company.processos.partials.modal-upload')

    {{-- Modal Nova Unidade --}}
    @if(isset($tipoProcessoTemUnidades) && $tipoProcessoTemUnidades)
    <div x-ref="modalNovaUnidade" class="hidden fixed inset-0 z-50 overflow-y-auto">
        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="$refs.modalNovaUnidade.classList.add('hidden')"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
                <div class="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-violet-50 to-purple-50">
                    <h3 class="text-lg font-semibold text-slate-900">Solicitar Nova Unidade</h3>
                    <p class="text-xs text-slate-500 mt-1">Selecione a unidade que deseja adicionar ao processo</p>
                    <div class="mt-3 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        <p class="text-xs text-amber-800 leading-relaxed">
                            ⚠️ Solicitação de nova unidade para análise de projeto arquitetônico. Após adicionar, será necessário enviar os documentos obrigatórios para análise da Vigilância Sanitária.
                        </p>
                    </div>
                </div>
                @if(isset($unidadesDisponiveis) && $unidadesDisponiveis->count() > 0)
                <form action="{{ route('company.processos.adicionar-unidade', $processo->id) }}" method="POST" class="p-6">
                    @csrf
                    <div class="space-y-2">
                        @foreach($unidadesDisponiveis as $unidade)
                        <label class="flex items-center gap-3 p-3 border border-slate-200 rounded-lg cursor-pointer hover:border-violet-300 hover:bg-violet-50/50 has-[:checked]:border-violet-500 has-[:checked]:bg-violet-50 transition-all">
                            <input type="radio" name="unidade_id" value="{{ $unidade->id }}" required
                                   class="h-4 w-4 text-violet-600 border-slate-300 focus:ring-violet-500">
                            <div>
                                <span class="text-sm font-medium text-slate-900">{{ $unidade->nome }}</span>
                                @if($unidade->descricao)
                                    <p class="text-xs text-slate-500">{{ $unidade->descricao }}</p>
                                @endif
                            </div>
                        </label>
                        @endforeach
                    </div>
                    <div class="mt-4">
                        <label for="nome_unidade_company" class="block text-sm font-medium text-slate-700 mb-1">Nome da unidade <span class="text-slate-400 font-normal">(opcional)</span></label>
                        <input type="text" id="nome_unidade_company" name="nome_unidade" maxlength="255"
                               placeholder="Ex.: UTI Pediátrica, PS Infantil, Centro Cirúrgico"
                               class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                        <p class="text-xs text-slate-500 mt-1">Identifique a unidade. Se deixar em branco, será usado o nome do tipo selecionado.</p>
                    </div>
                    <div class="flex items-center gap-3 mt-6 pt-4 border-t border-slate-200">
                        <button type="submit" class="px-4 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition">
                            Adicionar Unidade
                        </button>
                        <button type="button" @click="$refs.modalNovaUnidade.classList.add('hidden')" class="px-4 py-2 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-200 transition">
                            Cancelar
                        </button>
                    </div>
                </form>
                @else
                <div class="p-6 text-center">
                    <svg class="w-12 h-12 text-green-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <p class="text-sm text-slate-600">Todas as unidades disponíveis já foram adicionadas ao processo.</p>
                    <button type="button" @click="$refs.modalNovaUnidade.classList.add('hidden')" class="mt-4 px-4 py-2 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-200 transition">
                        Fechar
                    </button>
                </div>
                @endif
            </div>
        </div>
    </div>
    @endif

    {{-- Modal Resposta a Documento --}}
    <div x-show="modalResposta" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="modalResposta = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl" @click.stop
                 x-data="{
                     arquivosResposta: [],
                     maxArquivos: 6,
                     dragover: false,
                     enviando: false,
                     handleFiles(e) {
                         const files = e.target.files || (e.dataTransfer && e.dataTransfer.files);
                         if (files) {
                             for (let i = 0; i < files.length && this.arquivosResposta.length < this.maxArquivos; i++) {
                                 const file = files[i];
                                 if (file.size > 30 * 1024 * 1024) {
                                     alert('O arquivo ' + file.name + ' excede o limite de 30MB.');
                                     continue;
                                 }
                                 const jaExiste = this.arquivosResposta.some(f => f.name === file.name && f.size === file.size);
                                 if (!jaExiste) {
                                     this.arquivosResposta.push({
                                         file: file,
                                         name: file.name,
                                         size: (file.size / 1024 / 1024).toFixed(2) + ' MB'
                                     });
                                 }
                             }
                         }
                         if (e.target && e.target.value) e.target.value = '';
                         this.dragover = false;
                     },
                     removeFile(index) {
                         this.arquivosResposta.splice(index, 1);
                     },
                     async enviarRespostas() {
                         if (this.arquivosResposta.length === 0 || this.enviando) return;
                         
                         this.enviando = true;
                         let sucessos = 0;
                         let erros = 0;
                         const observacoes = '';
                         
                         for (let i = 0; i < this.arquivosResposta.length; i++) {
                             const arquivo = this.arquivosResposta[i];
                             const formData = new FormData();
                             formData.append('arquivo', arquivo.file);
                             formData.append('observacoes', observacoes);
                             formData.append('_token', '{{ csrf_token() }}');
                             if (arquivo.tipoRespostaId) {
                                 formData.append('tipo_documento_resposta_id', arquivo.tipoRespostaId);
                             }
                             
                             try {
                                 const response = await fetch(`{{ url('/company/processos/' . $processo->id . '/documentos-vigilancia') }}/${docRespostaId}/resposta`, {
                                     method: 'POST',
                                     body: formData,
                                     headers: {
                                         'X-Requested-With': 'XMLHttpRequest',
                                         'Accept': 'application/json'
                                     }
                                 });
                                 
                                 if (response.ok) {
                                     sucessos++;
                                 } else {
                                     erros++;
                                 }
                             } catch (error) {
                                 console.error('Erro:', error);
                                 erros++;
                             }
                         }
                         
                         this.enviando = false;
                         this.arquivosResposta = [];
                         
                         if (erros === 0) {
                             alert(`${sucessos} resposta(s) enviada(s) com sucesso!`);
                             modalResposta = false;
                             location.reload();
                         } else {
                             alert(`${sucessos} resposta(s) enviada(s). ${erros} erro(s).`);
                         }
                     }
                 }">
                {{-- Header --}}
                <div class="px-6 py-4 border-b border-slate-100 bg-gradient-to-r from-green-50 to-emerald-50 rounded-t-2xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center">
                                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Responder Notificação</h3>
                                <p class="text-xs text-slate-500">Anexe os documentos de resposta</p>
                            </div>
                        </div>
                        <button type="button" @click="modalResposta = false" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="px-6 py-5 space-y-4">
                    {{-- Info do documento --}}
                    <div class="bg-green-50 border border-green-200 rounded-xl p-4">
                        <p class="text-sm text-green-800 font-medium flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Respondendo a: <span x-text="docRespostaNome" class="text-green-900"></span>
                        </p>
                    </div>

                    {{-- Upload com tipos definidos --}}
                    <template x-if="docRespostaTipos.length > 0">
                        <div class="space-y-2">
                            <template x-for="(tipoResp, idx) in docRespostaTipos" :key="tipoResp.id">
                                <div class="flex items-center gap-3 p-3 rounded-lg border"
                                     :class="docRespostaEnviados.includes(tipoResp.id) ? 'bg-slate-50 border-slate-200 opacity-60' : 'bg-white border-slate-200'">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                         :class="docRespostaEnviados.includes(tipoResp.id) ? 'bg-slate-100' : 'bg-green-100'">
                                        <template x-if="!docRespostaEnviados.includes(tipoResp.id)">
                                            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                            </svg>
                                        </template>
                                        <template x-if="docRespostaEnviados.includes(tipoResp.id)">
                                            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </template>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium" :class="docRespostaEnviados.includes(tipoResp.id) ? 'text-slate-500' : 'text-slate-900'" x-text="tipoResp.nome"></p>
                                        <template x-if="docRespostaEnviados.includes(tipoResp.id)">
                                            <p class="text-[10px] text-slate-400">Já enviado — aguardando análise</p>
                                        </template>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        {{-- Não enviado ainda --}}
                                        <template x-if="!docRespostaEnviados.includes(tipoResp.id) && !arquivosResposta.some(f => f.tipoRespostaId === tipoResp.id)">
                                            <label class="px-3 py-1.5 bg-green-600 text-white text-xs font-medium rounded-lg hover:bg-green-700 transition cursor-pointer inline-flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                                Anexar
                                                <input type="file" accept=".pdf" class="hidden"
                                                       @change="
                                                           const file = $event.target.files[0];
                                                           if (file && file.size > 30*1024*1024) { alert('Máximo 30MB'); $event.target.value=''; return; }
                                                           if (file) {
                                                               arquivosResposta = arquivosResposta.filter(f => f.tipoRespostaId !== tipoResp.id);
                                                               arquivosResposta.push({ file, name: tipoResp.nome + '.pdf', size: (file.size/1024/1024).toFixed(2)+' MB', tipoRespostaId: tipoResp.id });
                                                           }
                                                           $event.target.value = '';
                                                       ">
                                            </label>
                                        </template>
                                        {{-- Arquivo selecionado --}}
                                        <template x-if="!docRespostaEnviados.includes(tipoResp.id) && arquivosResposta.some(f => f.tipoRespostaId === tipoResp.id)">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-xs text-green-700 font-medium flex items-center gap-1">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                    Anexado
                                                </span>
                                                <button type="button" @click="arquivosResposta = arquivosResposta.filter(f => f.tipoRespostaId !== tipoResp.id)"
                                                        class="p-1 text-red-400 hover:text-red-600 hover:bg-red-50 rounded transition">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    
                    {{-- Upload livre (sem tipos definidos) --}}
                    <template x-if="docRespostaTipos.length === 0">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Arquivos de Resposta * 
                                <span class="text-slate-500 font-normal">(<span x-text="arquivosResposta.length"></span>/6 selecionados)</span>
                            </label>
                        
                        {{-- Área de drop --}}
                        <div class="relative mb-3"
                             @dragover.prevent="dragover = true"
                             @dragleave.prevent="dragover = false"
                             @drop.prevent="handleFiles($event)">
                            <input type="file" 
                                   @change="handleFiles($event)"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                   accept=".pdf"
                                   multiple
                                   :disabled="arquivosResposta.length >= maxArquivos">
                            <div class="border-2 border-dashed rounded-xl p-6 text-center transition-all"
                                 :class="dragover ? 'border-green-500 bg-green-50' : (arquivosResposta.length >= maxArquivos ? 'border-slate-200 bg-slate-50' : 'border-slate-300 bg-slate-50 hover:border-green-400 hover:bg-green-50')">
                                <template x-if="arquivosResposta.length < maxArquivos">
                                    <div>
                                        <svg class="w-12 h-12 mx-auto text-green-500 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                        </svg>
                                        <p class="text-sm text-slate-600 mb-1">
                                            <span class="text-green-600 font-semibold">Clique para selecionar</span> ou arraste os arquivos
                                        </p>
                                        <p class="text-xs text-slate-500">Apenas PDF • Máx. 30MB cada • Até 6 arquivos</p>
                                        <p class="text-xs text-amber-600 mt-2 font-medium">💡 Dica: Documentos com muitas folhas devem ter no máximo 5MB</p>
                                    </div>
                                </template>
                                <template x-if="arquivosResposta.length >= maxArquivos">
                                    <div>
                                        <svg class="w-12 h-12 mx-auto text-slate-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        <p class="text-sm text-slate-500 font-medium">Limite de 6 arquivos atingido</p>
                                    </div>
                                </template>
                            </div>
                        </div>
                        
                        {{-- Lista de arquivos selecionados --}}
                        <template x-if="arquivosResposta.length > 0">
                            <div class="space-y-2 max-h-40 overflow-y-auto">
                                <template x-for="(arquivo, index) in arquivosResposta" :key="index">
                                    <div class="flex items-center justify-between p-3 bg-green-50 border border-green-200 rounded-lg">
                                        <div class="flex items-center gap-3 min-w-0">
                                            <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                </svg>
                                            </div>
                                            <div class="min-w-0">
                                                <p class="text-sm font-medium text-slate-900 truncate" x-text="arquivo.name"></p>
                                                <p class="text-xs text-slate-500" x-text="arquivo.size"></p>
                                            </div>
                                        </div>
                                        <button type="button" @click="removeFile(index)" 
                                                class="p-1.5 text-red-500 hover:bg-red-100 rounded-lg transition-colors flex-shrink-0" title="Remover">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    </template>
                    
                </div>
                
                {{-- Footer --}}
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 rounded-b-2xl flex flex-row-reverse gap-3">
                    <button type="button" @click="enviarRespostas()"
                            :disabled="arquivosResposta.length === 0 || enviando"
                            class="px-5 py-2.5 bg-green-600 text-white text-sm font-medium rounded-xl hover:bg-green-700 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="!enviando">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                            </svg>
                        </template>
                        <template x-if="enviando">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="enviando ? 'Enviando...' : 'Enviar ' + arquivosResposta.length + ' Resposta(s)'"></span>
                    </button>
                    <button type="button" @click="modalResposta = false" class="px-4 py-2.5 bg-white text-slate-700 text-sm font-medium rounded-xl border border-slate-300 hover:bg-slate-50 transition-colors">
                        Cancelar
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal Alertas --}}
    <div x-show="modalAlertas" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="fixed inset-0 bg-black bg-opacity-50" @click="modalAlertas = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-lg shadow-xl w-full max-w-lg" @click.stop>
                <div class="px-6 py-4 border-b border-slate-200 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-5 h-5 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        Alertas do Processo
                    </h3>
                    <button type="button" @click="modalAlertas = false" class="text-slate-400 hover:text-slate-500">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="max-h-96 overflow-y-auto">
                    @if($alertas->count() > 0)
                    <div class="divide-y divide-slate-100">
                        @foreach($alertas as $alerta)
                        <div class="px-6 py-4 {{ $alerta->status === 'pendente' ? ($alerta->isVencido() ? 'bg-red-50' : ($alerta->isProximo() ? 'bg-yellow-50' : '')) : 'bg-slate-50' }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1">
                                    <p class="text-sm text-slate-900">{{ $alerta->descricao }}</p>
                                    <div class="flex items-center gap-3 mt-1">
                                        <p class="text-xs text-slate-500 flex items-center gap-1">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            {{ $alerta->data_alerta->format('d/m/Y') }}
                                        </p>
                                        @if($alerta->usuarioCriador)
                                        <p class="text-xs text-slate-400">
                                            por {{ $alerta->usuarioCriador->nome }}
                                        </p>
                                        @endif
                                    </div>
                                    @if($alerta->status === 'concluido' && $alerta->concluido_em)
                                    <p class="text-xs text-green-600 mt-1">
                                        Resolvido em {{ $alerta->concluido_em->format('d/m/Y H:i') }}
                                    </p>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="px-2 py-0.5 text-xs font-medium rounded
                                        @if($alerta->status === 'pendente')
                                            @if($alerta->isVencido()) bg-red-100 text-red-700
                                            @elseif($alerta->isProximo()) bg-yellow-100 text-yellow-700
                                            @else bg-blue-100 text-blue-700
                                            @endif
                                        @elseif($alerta->status === 'concluido') bg-green-100 text-green-700
                                        @else bg-slate-100 text-slate-700
                                        @endif">
                                        @if($alerta->status === 'pendente')
                                            @if($alerta->isVencido()) Vencido
                                            @elseif($alerta->isProximo()) Próximo
                                            @else Pendente
                                            @endif
                                        @elseif($alerta->status === 'concluido') Concluído
                                        @else {{ ucfirst($alerta->status) }}
                                        @endif
                                    </span>
                                    @if($alerta->status !== 'concluido')
                                    <form action="{{ route('company.processos.alertas.concluir', [$processo->id, $alerta->id]) }}" method="POST" class="inline">
                                        @csrf
                                        <button type="submit" 
                                            class="p-1.5 text-green-600 hover:text-green-700 hover:bg-green-50 rounded-lg transition-colors"
                                            title="Marcar como resolvido"
                                            onclick="return confirm('Confirma que este alerta foi resolvido?')">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                            </svg>
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @else
                    <div class="px-6 py-8 text-center">
                        <svg class="mx-auto h-10 w-10 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <p class="mt-2 text-sm text-slate-500">Nenhum alerta cadastrado</p>
                    </div>
                    @endif
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                    <button type="button" @click="modalAlertas = false" class="w-full px-4 py-2 bg-white text-slate-700 text-sm font-medium rounded-lg border border-slate-300 hover:bg-slate-50">Fechar</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal Visualizador de Documento --}}
    <div x-show="modalVisualizador" x-cloak class="fixed inset-0 z-50 overflow-hidden" style="display: none;">
        <div class="fixed inset-0 bg-black bg-opacity-75" @click="modalVisualizador = false"></div>
        <div class="fixed inset-4 flex flex-col">
            {{-- Header --}}
            <div class="bg-white rounded-t-lg px-4 py-3 flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <span class="text-xl">📄</span>
                    <span class="text-sm font-medium text-slate-900" x-text="documentoNome"></span>
                </div>
                <div class="flex items-center gap-2">
                    <a :href="documentoUrl.replace('/visualizar', '/download')" class="px-3 py-1.5 bg-blue-600 text-white text-xs font-medium rounded hover:bg-blue-700 flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        Download
                    </a>
                    <button type="button" @click="modalVisualizador = false" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
            {{-- Content --}}
            <div class="flex-1 bg-slate-100 rounded-b-lg overflow-hidden">
                <template x-if="['pdf'].includes(documentoExtensao.toLowerCase())">
                    <iframe :src="documentoUrl" class="w-full h-full border-0"></iframe>
                </template>
                <template x-if="['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(documentoExtensao.toLowerCase())">
                    <div class="w-full h-full flex items-center justify-center p-4 overflow-auto">
                        <img :src="documentoUrl" :alt="documentoNome" class="max-w-full max-h-full object-contain shadow-lg rounded">
                    </div>
                </template>
                <template x-if="!['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'].includes(documentoExtensao.toLowerCase())">
                    <div class="w-full h-full flex flex-col items-center justify-center p-8">
                        <svg class="w-16 h-16 text-slate-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        <p class="text-slate-600 text-center mb-4">Este tipo de arquivo não pode ser visualizado no navegador.</p>
                        <a :href="documentoUrl.replace('/visualizar', '/download')" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">
                            Fazer Download
                        </a>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Modal Reenvio de Documento Rejeitado --}}
    <div x-show="modalReenvio" x-cloak class="fixed inset-0 z-50 overflow-y-auto" style="display: none;"
         x-data="{ 
             fileReenvio: null, 
             fileReenvioSize: '', 
             enviandoReenvio: false,
             dragover: false,
             handleFile(e) {
                 const file = e.target.files[0] || (e.dataTransfer && e.dataTransfer.files[0]);
                 if (file) {
                     this.fileReenvio = file;
                     this.fileReenvioSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';
                 }
                 this.dragover = false;
             },
             resetFile() {
                 this.fileReenvio = null;
                 this.fileReenvioSize = '';
                 this.$refs.fileReenvioInput.value = '';
             },
             async enviarReenvio() {
                 if (!this.fileReenvio || this.enviandoReenvio) return;
                 
                 this.enviandoReenvio = true;
                 const formData = new FormData();
                 formData.append('arquivo', this.fileReenvio);
                 formData.append('documento_id', docReenvioId);
                 formData.append('_token', '{{ csrf_token() }}');
                 
                 try {
                     const response = await fetch('{{ route('company.processos.upload', $processo->id) }}', {
                         method: 'POST',
                         body: formData,
                         headers: {
                             'X-Requested-With': 'XMLHttpRequest',
                             'Accept': 'application/json'
                         }
                     });
                     
                     if (response.ok) {
                         alert('Documento reenviado com sucesso! Aguarde a aprovação.');
                         window.location.reload();
                     } else {
                         const data = await response.json();
                         alert(data.message || 'Erro ao reenviar documento');
                     }
                 } catch (error) {
                     console.error('Erro:', error);
                     alert('Erro ao reenviar documento. Tente novamente.');
                 } finally {
                     this.enviandoReenvio = false;
                 }
             }
         }">
        <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" @click="modalReenvio = false; resetFile()"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg" @click.stop>
                {{-- Header --}}
                <div class="px-6 py-4 border-b border-slate-100 bg-red-50 rounded-t-2xl">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center">
                                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Reenviar Documento</h3>
                                <p class="text-xs text-slate-500">Substitua o documento rejeitado</p>
                            </div>
                        </div>
                        <button type="button" @click="modalReenvio = false; resetFile()" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                {{-- Content --}}
                <div class="p-6 space-y-4">
                    {{-- Info do documento rejeitado --}}
                    <div class="p-4 bg-red-50 border border-red-200 rounded-xl">
                        <p class="text-sm font-medium text-red-800 mb-1">Documento rejeitado:</p>
                        <p class="text-sm text-red-700" x-text="docReenvioNome"></p>
                        <template x-if="docReenvioMotivo">
                            <div class="mt-2 pt-2 border-t border-red-200">
                                <p class="text-xs text-red-600"><strong>Motivo:</strong> <span x-text="docReenvioMotivo"></span></p>
                            </div>
                        </template>
                    </div>
                    
                    {{-- Aviso --}}
                    <div class="flex items-start gap-3 p-3 bg-blue-50 border border-blue-200 rounded-xl">
                        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <p class="text-sm text-blue-800">
                            O novo arquivo substituirá o documento rejeitado. O histórico de rejeições será mantido.
                        </p>
                    </div>
                    
                    {{-- Área de Upload --}}
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Novo Arquivo *</label>
                        <div class="relative"
                             @dragover.prevent="dragover = true"
                             @dragleave.prevent="dragover = false"
                             @drop.prevent="handleFile($event)">
                            <input type="file" x-ref="fileReenvioInput"
                                   @change="handleFile($event)"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                                   accept=".pdf">
                            <div class="border-2 border-dashed rounded-xl p-6 text-center transition-all"
                                 :class="dragover ? 'border-blue-500 bg-blue-50' : (fileReenvio ? 'border-green-400 bg-green-50' : 'border-slate-300 hover:border-blue-400 hover:bg-slate-50')">
                                <template x-if="!fileReenvio">
                                    <div>
                                        <svg class="w-10 h-10 mx-auto text-slate-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                        </svg>
                                        <p class="text-sm text-slate-600 mb-1">
                                            <span class="text-blue-600 font-medium">Clique para selecionar</span> ou arraste o arquivo
                                        </p>
                                        <p class="text-xs text-slate-500">Apenas PDF (máx. 30MB)</p>
                                    </div>
                                </template>
                                <template x-if="fileReenvio">
                                    <div class="flex items-center justify-center gap-3">
                                        <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
                                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        </div>
                                        <div class="text-left">
                                            <p class="text-sm font-medium text-slate-900" x-text="fileReenvio.name"></p>
                                            <p class="text-xs text-slate-500" x-text="fileReenvioSize"></p>
                                        </div>
                                        <button type="button" @click.stop="resetFile()" class="p-1 text-red-500 hover:bg-red-50 rounded">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
                
                {{-- Footer --}}
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 rounded-b-2xl flex justify-end gap-3">
                    <button type="button" @click="modalReenvio = false; resetFile()" 
                            class="px-4 py-2.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-xl hover:bg-slate-50 transition-colors">
                        Cancelar
                    </button>
                    <button type="button" @click="enviarReenvio()"
                            :disabled="!fileReenvio || enviandoReenvio"
                            class="px-5 py-2.5 text-sm font-medium text-white bg-blue-600 rounded-xl hover:bg-blue-700 transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="!enviandoReenvio">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                            </svg>
                        </template>
                        <template x-if="enviandoReenvio">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="enviandoReenvio ? 'Enviando...' : 'Reenviar Documento'"></span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    window.recarregarAposVisualizarDocumentoPrazo = function () {
        if (window.__recarregandoAposVisualizarDocumentoPrazo) {
            return;
        }

        window.__recarregandoAposVisualizarDocumentoPrazo = true;
        window.setTimeout(() => {
            window.location.reload();
        }, 1800);
    };

    document.addEventListener('DOMContentLoaded', () => {
        const pendentesWrapper = document.getElementById('pendentes-wrapper');
        const pendentesList = document.getElementById('pendentes-list');
        const pendentesCount = document.getElementById('pendentes-count');
        const pendentesCountResumo = document.getElementById('pendentes-count-resumo');
        const pendentesEmpty = document.getElementById('pendentes-empty');
        const csrfToken = '{{ csrf_token() }}';

        const abrirModalDocumento = (doc) => {
            const root = document.querySelector('[data-processo-root]');
            if (!root || !window.Alpine) return;
            const data = window.Alpine.$data(root);
            if (!data) return;
            data.documentoUrl = doc.visualizar_url || '';
            data.documentoNome = doc.nome_original || '';
            data.documentoExtensao = doc.extensao || '';
            data.modalVisualizador = true;
        };

        const criarItemPendente = (doc) => {
            const item = document.createElement('div');
            item.className = 'px-4 py-3 flex items-start justify-between hover:bg-yellow-100/50 gap-3';
            item.dataset.pendenteDocId = doc.id;

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'flex items-start gap-3 text-left flex-1 min-w-0';
            button.addEventListener('click', () => abrirModalDocumento(doc));

            const icon = document.createElement('span');
            icon.className = 'text-xl flex-shrink-0';
            icon.textContent = doc.icone || '📄';

            const info = document.createElement('div');
            info.className = 'min-w-0';

            const nome = document.createElement('p');
            nome.className = 'text-sm font-medium text-gray-900 hover:text-blue-600 break-words';
            nome.textContent = doc.nome_original || 'Documento';

            const meta = document.createElement('p');
            meta.className = 'text-xs text-gray-500';
            const metaParts = [];
            if (doc.tamanho_formatado) metaParts.push(doc.tamanho_formatado);
            if (doc.created_at) metaParts.push(doc.created_at);
            meta.textContent = metaParts.join(' • ');

            info.appendChild(nome);
            info.appendChild(meta);
            button.appendChild(icon);
            button.appendChild(info);

            const actions = document.createElement('div');
            actions.className = 'flex items-center gap-2 flex-shrink-0';

            const badge = document.createElement('span');
            badge.className = 'px-2 py-1 bg-yellow-200 text-yellow-800 text-xs font-medium rounded';
            badge.textContent = 'Pendente';
            actions.appendChild(badge);

            if (doc.pode_excluir && doc.delete_url) {
                const form = document.createElement('form');
                form.action = doc.delete_url;
                form.method = 'POST';
                form.setAttribute('onsubmit', 'return confirm(\'Tem certeza que deseja excluir este arquivo?\')');

                const inputToken = document.createElement('input');
                inputToken.type = 'hidden';
                inputToken.name = '_token';
                inputToken.value = csrfToken;

                const inputMethod = document.createElement('input');
                inputMethod.type = 'hidden';
                inputMethod.name = '_method';
                inputMethod.value = 'DELETE';

                const buttonDelete = document.createElement('button');
                buttonDelete.type = 'submit';
                buttonDelete.className = 'p-1.5 text-red-500 hover:text-red-700 hover:bg-red-50 rounded transition-colors';
                buttonDelete.title = 'Excluir';
                buttonDelete.innerHTML = `
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                `;

                form.appendChild(inputToken);
                form.appendChild(inputMethod);
                form.appendChild(buttonDelete);
                actions.appendChild(form);
            }

            item.appendChild(button);
            item.appendChild(actions);

            return item;
        };

        window.addEventListener('company:documento-enviado', (event) => {
            const doc = event.detail && event.detail.documento ? event.detail.documento : null;
            if (!doc || !pendentesList || !pendentesCount) return;
            if (pendentesList.querySelector(`[data-pendente-doc-id="${doc.id}"]`)) return;

            const novoItem = criarItemPendente(doc);
            pendentesList.prepend(novoItem);

            const atual = parseInt(pendentesCount.textContent || '0', 10) || 0;
            const novoTotal = atual + 1;
            pendentesCount.textContent = String(novoTotal);
            if (pendentesCountResumo) pendentesCountResumo.textContent = String(novoTotal);

            if (pendentesWrapper) pendentesWrapper.classList.remove('hidden');
            if (pendentesEmpty) pendentesEmpty.classList.add('hidden');
        });
    });
</script>
@endsection
