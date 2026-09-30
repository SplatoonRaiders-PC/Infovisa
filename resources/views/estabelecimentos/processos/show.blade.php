@extends('layouts.admin')

@section('title', 'Detalhes do Processo')
@section('page-title', 'Detalhes do Processo')

@section('content')
<div class="max-w-8xl mx-auto" x-data="processoData()">
    @php
        $documentoDigitalDirecionadoId = request()->integer('documento_digital') ?: null;
        $responsavelCienteEfetivo = $processo->responsavel_ciente_em_efetivo;
        $dataTramitacaoEfetiva = $processo->data_tramitacao_efetiva;
        $documentosPendentesComIA = $processo->documentos
            ->where('tipo_usuario', 'externo')
            ->where('status_aprovacao', 'pendente')
            ->filter(function($d) { return !empty($d->tipoDocumentoObrigatorio?->criterio_ia); })
            ->map(function($d) { return ['id' => $d->id, 'nome' => $d->nome_original]; })
            ->values();
    @endphp

    {{-- Mensagens --}}
    @if(session('success'))
        <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-lg">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <div class="flex items-center">
                <svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                <p class="text-sm font-medium text-red-800">{{ session('error') }}</p>
            </div>
        </div>
    @endif

    {{-- Modal de Notificação de Atribuição (aparece apenas para o responsável que ainda não viu) --}}
    @if($processo->responsavel_atual_id === auth('interno')->id() && !$responsavelCienteEfetivo)
    <div x-data="{ 
        showNotificacao: true,
        marcarCiente() {
            fetch('{{ route("admin.estabelecimentos.processos.ciente", [$estabelecimento->id, $processo->id]) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.showNotificacao = false;
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                this.showNotificacao = false;
            });
        }
    }" x-show="showNotificacao" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
            {{-- Overlay --}}
            <div class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity"></div>

            {{-- Modal Panel --}}
            <div class="relative bg-white rounded-2xl shadow-2xl transform transition-all sm:max-w-lg sm:w-full mx-auto overflow-hidden">
                {{-- Header --}}
                <div class="px-6 py-5 bg-gradient-to-r from-cyan-600 to-cyan-700">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
                            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                        </div>
                        <div class="text-left">
                            <h3 class="text-xl font-bold text-white">Processo Atribuído a Você</h3>
                            <p class="text-cyan-100 text-sm">{{ $processo->numero_processo }}</p>
                        </div>
                    </div>
                </div>
                
                {{-- Content --}}
                <div class="px-6 py-5 space-y-4">
                    @if(!$processo->motivo_atribuicao && !$processo->prazo_atribuicao)
                    <div class="bg-slate-50 rounded-xl p-4">
                        <p class="text-sm text-slate-700">
                            Confirme a ciência desta atribuição para remover o aviso e registrar o recebimento do processo.
                        </p>
                    </div>
                    @endif

                    @if($processo->motivo_atribuicao)
                    <div class="bg-slate-50 rounded-xl p-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-2 flex items-center gap-2">
                            <svg class="w-4 h-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                            </svg>
                            Motivo da Atribuição
                        </h4>
                        <p class="text-slate-700">{{ $processo->motivo_atribuicao }}</p>
                    </div>
                    @endif
                    
                    @if($processo->prazo_atribuicao)
                    @php
                        $prazo = \Carbon\Carbon::parse($processo->prazo_atribuicao);
                        $hoje = \Carbon\Carbon::today();
                        $diasRestantes = $hoje->diffInDays($prazo, false);
                        $vencido = $diasRestantes < 0;
                        $proximo = $diasRestantes >= 0 && $diasRestantes <= 3;
                    @endphp
                    <div class="rounded-xl p-4 {{ $vencido ? 'bg-red-50 border border-red-200' : ($proximo ? 'bg-amber-50 border border-amber-200' : 'bg-cyan-50 border border-cyan-200') }}">
                        <h4 class="text-sm font-semibold mb-2 flex items-center gap-2 {{ $vencido ? 'text-red-700' : ($proximo ? 'text-amber-700' : 'text-cyan-700') }}">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Prazo para Resolução
                        </h4>
                        <p class="text-lg font-bold {{ $vencido ? 'text-red-800' : ($proximo ? 'text-amber-800' : 'text-cyan-800') }}">
                            {{ $prazo->format('d/m/Y') }}
                            @if($vencido)
                                <span class="text-sm font-medium">(Vencido há {{ abs($diasRestantes) }} dia(s))</span>
                            @elseif($diasRestantes == 0)
                                <span class="text-sm font-medium">(Vence hoje!)</span>
                            @elseif($proximo)
                                <span class="text-sm font-medium">({{ $diasRestantes }} dia(s) restante(s))</span>
                            @endif
                        </p>
                    </div>
                    @endif
                    
                    <div class="text-sm text-slate-500 text-center pt-2">
                        Atribuído em {{ $processo->responsavel_desde ? $processo->responsavel_desde->format('d/m/Y \à\s H:i') : '-' }}
                    </div>
                </div>
                
                {{-- Footer --}}
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                    <button type="button" 
                            @click="marcarCiente()"
                            class="w-full px-4 py-3 bg-cyan-600 text-white font-semibold rounded-xl hover:bg-cyan-700 transition-colors flex items-center justify-center gap-2">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                        Estou Ciente
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    {{-- Alerta de Processo Parado --}}
    @if($processo->status === 'parado')
    <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-3 rounded-lg">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-red-800">⚠️ Processo Parado</h3>
                    <p class="text-xs text-red-700 mt-0.5"><strong>Motivo:</strong> {{ $processo->motivo_parada }}</p>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs text-red-600">
                <span>📅 Parado em: {{ $processo->data_parada->format('d/m/Y H:i') }}</span>
                @if($processo->usuarioParada)
                <span>👤 Por: {{ $processo->usuarioParada->nome }}</span>
                @endif
            </div>
        </div>
    </div>
    @endif

    @if($errors->any())
        <div class="mb-6 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
            <div class="flex items-start">
                <svg class="w-5 h-5 text-red-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <p class="text-sm font-medium text-red-800 mb-2">Erro ao enviar arquivo:</p>
                    <ul class="list-disc list-inside text-sm text-red-700">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Aviso de Prazo da Fila Pública --}}
    @if($avisoFilaPublica && $processo->status !== 'arquivado')
        @php
            $dias = $avisoFilaPublica['dias_restantes'];
            $prazoPausado = $avisoFilaPublica['pausado'] ?? false;
            $prazoReiniciado = $avisoFilaPublica['prazo_reiniciado'] ?? false;
            $dataReferenciaPrazo = $avisoFilaPublica['data_referencia_prazo'] ?? $avisoFilaPublica['data_documentos_completos'];
            $corBg = $prazoPausado ? 'bg-slate-50' : ($avisoFilaPublica['atrasado'] ? 'bg-red-50' : ($dias <= 5 ? 'bg-amber-50' : 'bg-cyan-50'));
            $corBorda = $prazoPausado ? 'border-slate-400' : ($avisoFilaPublica['atrasado'] ? 'border-red-400' : ($dias <= 5 ? 'border-amber-400' : 'border-cyan-400'));
            $corTexto = $prazoPausado ? 'text-slate-700' : ($avisoFilaPublica['atrasado'] ? 'text-red-700' : ($dias <= 5 ? 'text-amber-700' : 'text-cyan-700'));
        @endphp
        <div class="mb-4 {{ $corBg }} border-l-4 {{ $corBorda }} px-4 py-2.5 rounded-r-lg">
            <div class="flex items-center gap-2 {{ $corTexto }} text-sm">
                <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span>
                    @if($prazoPausado && $avisoFilaPublica['atrasado'])
                        <strong>Prazo suspenso.</strong> O processo foi parado com atraso de {{ abs($dias) }} {{ abs($dias) == 1 ? 'dia' : 'dias' }} na análise (referência atual: {{ $dataReferenciaPrazo->format('d/m/Y') }})
                    @elseif($prazoPausado)
                        <strong>Prazo suspenso.</strong> Restavam {{ $dias }} {{ $dias == 1 ? 'dia' : 'dias' }} para análise quando o processo foi parado (referência atual: {{ $dataReferenciaPrazo->format('d/m/Y') }})
                    @elseif($avisoFilaPublica['atrasado'])
                        <strong>Prazo vencido!</strong> Atrasado há {{ abs($dias) }} {{ abs($dias) == 1 ? 'dia' : 'dias' }} (referência atual: {{ $dataReferenciaPrazo->format('d/m/Y') }})
                    @elseif($dias <= 5)
                        <strong>Prazo próximo!</strong> Restam {{ $dias }} {{ $dias == 1 ? 'dia' : 'dias' }} para análise (referência atual: {{ $dataReferenciaPrazo->format('d/m/Y') }})
                    @elseif($prazoReiniciado)
                        Prazo reiniciado em {{ $dataReferenciaPrazo->format('d/m/Y') }} • Documentação completa em {{ $avisoFilaPublica['data_documentos_completos']->format('d/m/Y') }} • Prazo: {{ $avisoFilaPublica['prazo'] }} dias • <strong>Restam {{ $dias }} dias</strong>
                    @else
                        Documentação completa em {{ $avisoFilaPublica['data_documentos_completos']->format('d/m/Y') }} • Prazo: {{ $avisoFilaPublica['prazo'] }} dias • <strong>Restam {{ $dias }} dias</strong>
                    @endif
                </span>
            </div>
        </div>
    @endif

    {{-- Avisos de Prazo por Unidade --}}
    @if(isset($avisoFilaPublicaPorUnidade) && $avisoFilaPublicaPorUnidade->count() > 0 && $processo->status !== 'arquivado')
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
                        Prazo suspenso com atraso de {{ abs($diasU) }} {{ abs($diasU) == 1 ? 'dia' : 'dias' }}
                    @elseif($pausadoU)
                        Prazo suspenso • Restavam {{ $diasU }} {{ $diasU == 1 ? 'dia' : 'dias' }}
                    @elseif($avisoU['atrasado'])
                        Prazo vencido! Atrasado há {{ abs($diasU) }} {{ abs($diasU) == 1 ? 'dia' : 'dias' }}
                    @elseif($avisoU['prazo_reiniciado'] ?? false)
                        Prazo reiniciado em {{ $avisoU['data_referencia_prazo']->format('d/m/Y') }} • Prazo: {{ $avisoU['prazo'] }} dias • Restam {{ $diasU }} {{ $diasU == 1 ? 'dia' : 'dias' }}
                    @else
                        Documentação completa em {{ $avisoU['data_documentos_completos']->format('d/m/Y') }} • Prazo: {{ $avisoU['prazo'] }} dias • Restam {{ $diasU }} dias
                    @endif
                </span>
            </div>
        </div>
        @endforeach
    @endif

    {{-- Unidades Paradas (com botão retomar) --}}
    @if($processo->unidades->count() > 0 && $processo->status !== 'arquivado')
        @foreach($processo->unidades as $unidadeParada)
        @if($unidadeParada->pivot->status === 'parado')
        <div class="mb-3 bg-red-50 border border-red-300 rounded-xl px-5 py-4">
            <div class="flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-red-800">{{ $unidadeParada->nome }} — Parada</p>
                        <p class="text-xs text-red-600 mt-0.5 truncate">{{ $unidadeParada->pivot->motivo_parada }}</p>
                    </div>
                </div>
                <form action="{{ route('admin.estabelecimentos.processos.retomar-unidade', [$estabelecimento->id, $processo->id, $unidadeParada->id]) }}" method="POST" class="flex-shrink-0">
                    @csrf
                    <button type="submit" onclick="return confirm('Retomar esta unidade?')"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg shadow-sm transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Retomar Unidade
                    </button>
                </form>
            </div>
        </div>
        @endif
        @endforeach
    @endif

    {{-- Pastas/Unidades Paradas: retomar (continua o prazo) ou reiniciar o prazo da unidade --}}
    @if($processo->status !== 'arquivado')
        @foreach($processo->pastas->where('status', 'parado')->sortBy('ordem') as $pastaParada)
        <div class="mb-3 bg-red-50 border border-red-200 rounded-xl px-4 py-3">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="w-9 h-9 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-red-800">{{ $pastaParada->nome }} — Parada <span class="font-normal text-red-600">(prazo da unidade suspenso)</span></p>
                        <p class="text-xs text-red-700 mt-0.5 break-words"><strong>Motivo:</strong> {{ $pastaParada->motivo_parada }}</p>
                        @if($pastaParada->data_parada)
                        <p class="text-[11px] text-red-500 mt-0.5">Parada em {{ $pastaParada->data_parada->format('d/m/Y H:i') }}@if($pastaParada->usuarioParada) • por {{ $pastaParada->usuarioParada->nome }}@endif</p>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <form action="{{ route('admin.estabelecimentos.processos.pasta.retomar', [$estabelecimento->id, $processo->id, $pastaParada->id]) }}" method="POST">
                        @csrf
                        <input type="hidden" name="modo" value="continuar">
                        <button type="submit" onclick="return confirm('Retomar a unidade {{ addslashes($pastaParada->nome) }}? O prazo continua de onde parou.')"
                                title="O prazo volta a contar de onde parou (o tempo parado não conta)"
                                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Retomar
                        </button>
                    </form>
                    <form action="{{ route('admin.estabelecimentos.processos.pasta.retomar', [$estabelecimento->id, $processo->id, $pastaParada->id]) }}" method="POST">
                        @csrf
                        <input type="hidden" name="modo" value="reiniciar">
                        <button type="submit" onclick="return confirm('Reiniciar o prazo da unidade {{ addslashes($pastaParada->nome) }}? O prazo volta a contar do zero a partir de agora.')"
                                title="O prazo da unidade recomeça do zero a partir de agora (igual ao Reiniciar Processo)"
                                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg shadow-sm transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            Reiniciar prazo
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    @endif

    {{-- Gerenciar Conclusão de Pastas/Unidades (apenas processos de Projeto Arquitetônico com pastas e que ainda não estão arquivados) --}}
    @php
        // Arquivos rejeitados não impedem a exclusão da unidade (são removidos junto)
        $pastasGerenciaveis = $processo->pastas()
            ->withCount([
                'documentos as documentos_validos_count' => fn ($q) => $q->where(fn ($s) => $s->whereNull('status_aprovacao')->orWhere('status_aprovacao', '!=', 'rejeitado')),
                'documentos as documentos_rejeitados_count' => fn ($q) => $q->where('status_aprovacao', 'rejeitado'),
                'documentosDigitais',
            ])
            ->with('unidade')
            ->orderBy('ordem')
            ->get();
        $osPorPasta = \App\Models\OrdemServico::whereIn('pasta_id', $pastasGerenciaveis->pluck('id'))
            ->selectRaw('pasta_id, count(*) as total')->groupBy('pasta_id')->pluck('total', 'pasta_id');
        $isProjetoArquitetonico = $processo->tipoProcesso && $processo->tipoProcesso->codigo === 'projeto_arquitetonico';
        $podeGerenciarUnidadesProcesso = in_array(auth('interno')->user()->nivel_acesso->value, ['administrador', 'gestor_estadual', 'gestor_municipal', 'tecnico_estadual', 'tecnico_municipal']);
    @endphp
    @if(($isProjetoArquitetonico || ($tipoProcessoTemUnidades ?? false)) && $pastasGerenciaveis->count() > 0 && $processo->status !== 'arquivado' && $podeGerenciarUnidadesProcesso)
    <div class="mb-4 bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden" x-data="{ aberto: false }">
        <button @click="aberto = !aberto" type="button"
                class="w-full flex items-center justify-between px-5 py-3 hover:bg-slate-50 transition-colors">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-emerald-100 flex items-center justify-center">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="text-left">
                    <p class="text-sm font-semibold text-slate-900">Unidades do Processo</p>
                    <p class="text-xs text-slate-500">
                        @php
                            $totalPastasG = $pastasGerenciaveis->count();
                            $concluidasG = $pastasGerenciaveis->where('status', 'concluida')->count();
                        @endphp
                        {{ $concluidasG }}/{{ $totalPastasG }} concluídas — edite, exclua ou defira individualmente cada unidade
                    </p>
                </div>
            </div>
            <svg class="w-5 h-5 text-slate-400 transition-transform" :class="aberto ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="aberto" x-cloak x-collapse class="border-t border-slate-100 px-5 py-4 space-y-2">
            @if(($tipoProcessoTemUnidades ?? false) && in_array($processo->status, ['aberto', 'parado']))
            <div class="flex justify-end">
                <button type="button" onclick="document.getElementById('modal-nova-unidade-admin').classList.remove('hidden')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-violet-700 bg-violet-50 hover:bg-violet-100 border border-violet-200 rounded-lg transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Adicionar unidade
                </button>
            </div>
            @endif
            @foreach($pastasGerenciaveis as $pastaG)
            @php
                $itensPastaG = ($pastaG->documentos_validos_count ?? 0) + ($pastaG->documentos_digitais_count ?? 0) + (int) ($osPorPasta[$pastaG->id] ?? 0);
                $rejeitadosPastaG = (int) ($pastaG->documentos_rejeitados_count ?? 0);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 p-3 rounded-lg {{ $pastaG->status === 'concluida' ? 'bg-emerald-50 border border-emerald-200' : 'bg-slate-50 border border-slate-200' }}">
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <span class="w-3 h-3 rounded-full flex-shrink-0" style="background-color: {{ $pastaG->cor ?? '#3B82F6' }}"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-slate-900">
                            {{ $pastaG->nome }}
                            @if($pastaG->unidade && $pastaG->unidade->nome !== $pastaG->nome)
                                <span class="ml-1 text-[11px] font-medium text-violet-600">({{ $pastaG->unidade->nome }})</span>
                            @endif
                        </p>
                        @if($pastaG->status === 'concluida')
                            <p class="text-xs text-emerald-700 mt-0.5">
                                ✓ Concluída em {{ $pastaG->data_conclusao?->format('d/m/Y H:i') }} —
                                <span class="font-medium">{{ $pastaG->motivo_conclusao }}</span>
                            </p>
                        @elseif($pastaG->status === 'parado')
                            <p class="text-xs text-red-600 mt-0.5">⏸ Parada — {{ $pastaG->motivo_parada }}</p>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-2 flex-shrink-0">
                    @if($pastaG->unidade_id)
                        <button type="button"
                                @click="$dispatch('open-modal-editar-unidade', { pastaId: {{ $pastaG->id }}, nome: @js($pastaG->nome), unidadeId: {{ (int) $pastaG->unidade_id }} })"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Editar
                        </button>
                        @if($itensPastaG > 0)
                            <button type="button" disabled
                                    title="A unidade possui {{ $itensPastaG }} {{ $itensPastaG == 1 ? 'item' : 'itens' }}. Mova ou exclua os documentos antes de excluir a unidade."
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-slate-400 bg-white border border-slate-200 rounded-lg cursor-not-allowed">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                Excluir
                            </button>
                        @else
                            <form action="{{ route('admin.estabelecimentos.processos.unidade.excluir', [$estabelecimento->id, $processo->id, $pastaG->id]) }}" method="POST" class="inline">
                                @csrf
                                <button type="submit" onclick="return confirm('Excluir a unidade {{ addslashes($pastaG->nome) }} do processo?{{ $rejeitadosPastaG > 0 ? ' Os ' . $rejeitadosPastaG . ' arquivo(s) rejeitado(s) desta unidade também serão removidos.' : '' }} Esta ação não pode ser desfeita.')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-white border border-red-200 rounded-lg hover:bg-red-50 transition">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    Excluir
                                </button>
                            </form>
                        @endif
                    @endif
                    @if($pastaG->status === 'concluida')
                        <span class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-emerald-700 bg-emerald-100 rounded-full">
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Concluída
                        </span>
                        <form action="{{ route('admin.estabelecimentos.processos.pasta.reabrir', [$estabelecimento->id, $processo->id, $pastaG->id]) }}" method="POST" class="inline">
                            @csrf
                            <button type="submit" onclick="return confirm('Reabrir esta pasta? Se o processo foi arquivado automaticamente, ele também será reaberto.')"
                                    class="px-3 py-1.5 text-xs font-medium text-orange-700 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition">
                                Reabrir
                            </button>
                        </form>
                    @else
                        <button type="button"
                                @click="$dispatch('open-modal-concluir-pasta', { pastaId: {{ $pastaG->id }}, pastaNome: '{{ addslashes($pastaG->nome) }}' })"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg shadow-sm transition">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                            Concluir/Deferir
                        </button>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Modal: Editar Unidade --}}
    @php
        $opcoesUnidadeEdicao = ($unidadesDisponiveis ?? collect())
            ->merge($pastasGerenciaveis->pluck('unidade')->filter())
            ->unique('id')
            ->sortBy('nome')
            ->values();
    @endphp
    <div x-data="{ aberto: false, pastaId: null, nome: '', unidadeId: '' }"
        @open-modal-editar-unidade.window="aberto = true; pastaId = $event.detail.pastaId; nome = $event.detail.nome; unidadeId = String($event.detail.unidadeId)"
        @keydown.escape.window="aberto = false"
        x-show="aberto" x-cloak
        class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-black/50" @click="aberto = false"></div>
            <div x-show="aberto" x-transition class="relative bg-white rounded-xl shadow-xl max-w-md w-full">
                <form :action="`{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/unidades') }}/${pastaId}/editar`" method="POST">
                    @csrf
                    <div class="px-6 py-5 border-b border-slate-200">
                        <h3 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                            <svg class="w-5 h-5 text-violet-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            Editar Unidade
                        </h3>
                        <p class="text-xs text-slate-500 mt-1">A alteração fica registrada no histórico do processo.</p>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Nome da unidade *</label>
                            <input type="text" name="nome" x-model="nome" required maxlength="255"
                                   class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                            <p class="text-xs text-slate-500 mt-1">Ex.: "PS Infantil", "UTI Adulto (2)".</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Tipo de unidade *</label>
                            <select name="unidade_id" x-model="unidadeId" required
                                    class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                                @foreach($opcoesUnidadeEdicao as $opcaoUnidade)
                                    <option value="{{ $opcaoUnidade->id }}">{{ $opcaoUnidade->nome }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex justify-end gap-3 rounded-b-xl">
                        <button type="button" @click="aberto = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
                            Cancelar
                        </button>
                        <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-violet-600 hover:bg-violet-700 rounded-lg">
                            Salvar alterações
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal: Concluir Pasta --}}
    <div x-data="{
        aberto: false,
        pastaId: null,
        pastaNome: '',
        motivo: ''
    }"
        @open-modal-concluir-pasta.window="aberto = true; pastaId = $event.detail.pastaId; pastaNome = $event.detail.pastaNome; motivo = ''"
        x-show="aberto" x-cloak
        class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div x-show="aberto" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 class="fixed inset-0 bg-black/50" @click="aberto = false"></div>
            <div x-show="aberto" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                 class="relative bg-white rounded-xl shadow-xl max-w-md w-full">
                <form :action="`{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/pastas') }}/${pastaId}/concluir`" method="POST">
                    @csrf
                    <div class="px-6 py-5 border-b border-slate-200">
                        <h3 class="text-lg font-semibold text-slate-900 flex items-center gap-2">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Concluir Pasta/Unidade
                        </h3>
                        <p class="text-sm text-slate-500 mt-1">Pasta: <strong x-text="pastaNome"></strong></p>
                    </div>
                    <div class="px-6 py-5 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Motivo da Conclusão *</label>
                            <textarea name="motivo_conclusao" x-model="motivo" rows="3" required minlength="5"
                                      placeholder="Ex: Deferido — projeto aprovado em 25/05/2026"
                                      class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"></textarea>
                            <p class="text-xs text-slate-500 mt-1">Esta informação ficará registrada no histórico do processo.</p>
                        </div>
                        <div class="bg-amber-50 border border-amber-200 rounded-lg p-3">
                            <p class="text-xs text-amber-800">
                                <strong>Atenção:</strong> Quando todas as unidades/pastas forem concluídas, o processo será arquivado automaticamente e sairá da fila pública.
                            </p>
                        </div>
                    </div>
                    <div class="px-6 py-4 border-t border-slate-200 flex items-center justify-end gap-3">
                        <button type="button" @click="aberto = false"
                                class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition">
                            Concluir Pasta
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- Card Superior: Dados do Estabelecimento e Processo --}}
    @php
        $statusBadgeProcesso = match($processo->status_cor) {
            'blue' => 'bg-blue-50 text-blue-700 ring-blue-200',
            'yellow' => 'bg-yellow-50 text-yellow-700 ring-yellow-200',
            'orange' => 'bg-orange-50 text-orange-700 ring-orange-200',
            'green' => 'bg-green-50 text-green-700 ring-green-200',
            'red' => 'bg-red-50 text-red-700 ring-red-200',
            default => 'bg-slate-100 text-slate-700 ring-slate-200',
        };
        $statusDotProcesso = match($processo->status_cor) {
            'blue' => 'bg-blue-500', 'yellow' => 'bg-yellow-500', 'orange' => 'bg-orange-500',
            'green' => 'bg-green-500', 'red' => 'bg-red-500', default => 'bg-slate-400',
        };
    @endphp
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 mb-4 overflow-hidden">
        {{-- Faixa do processo --}}
        <div class="px-4 sm:px-5 py-4 bg-gradient-to-r from-slate-50 via-white to-white border-b border-slate-100 flex flex-col lg:flex-row lg:items-center gap-4">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <a href="{{ route('admin.estabelecimentos.processos.index', $estabelecimento->id) }}"
                   title="Voltar"
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
                    <div class="flex items-center gap-2 flex-wrap">
                        <h2 class="text-xl font-bold text-slate-900 tracking-tight tabular-nums">{{ $processo->numero_processo }}</h2>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold ring-1 ring-inset {{ $statusBadgeProcesso }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $statusDotProcesso }}"></span>
                            {{ $processo->status_nome }}
                        </span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold bg-slate-100 text-slate-600">Ano {{ $processo->ano }}</span>
                    </div>
                </div>
            </div>

            {{-- Botão Acompanhar --}}
            <div class="flex-shrink-0 lg:max-w-xs" x-data="{ showModal: false }">
                @php
                    $acompanhamentoAtual = $processo->acompanhamentos->where('usuario_interno_id', Auth::guard('interno')->user()->id)->first();
                @endphp
                @if($acompanhamentoAtual)
                    {{-- Já acompanhando --}}
                    <div class="space-y-2 lg:text-right">
                        <form action="{{ route('admin.estabelecimentos.processos.toggleAcompanhamento', [$estabelecimento->id, $processo->id]) }}" method="POST" class="inline-block">
                            @csrf
                            <button type="submit" class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold text-red-600 bg-red-50 ring-1 ring-inset ring-red-200 rounded-lg hover:bg-red-100 transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
                                </svg>
                                Parar de Acompanhar
                            </button>
                        </form>
                        @if($acompanhamentoAtual->descricao)
                            <div class="bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 text-left">
                                <p class="text-xs text-indigo-700"><span class="font-semibold">Nota:</span> {{ $acompanhamentoAtual->descricao }}</p>
                            </div>
                        @endif
                    </div>
                @else
                    {{-- Não acompanhando --}}
                    <button @click="showModal = true" type="button" class="inline-flex items-center gap-2 px-3.5 py-2 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 shadow-sm shadow-blue-600/20 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Acompanhar Processo
                    </button>

                    {{-- Modal --}}
                    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showModal = false">
                        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm" @click="showModal = false"></div>
                        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4" @click.stop>
                            <h3 class="text-base font-semibold text-slate-900">Acompanhar Processo</h3>
                            <p class="text-sm text-slate-500">Adicione uma nota para lembrar o motivo do acompanhamento.</p>
                            <form action="{{ route('admin.estabelecimentos.processos.toggleAcompanhamento', [$estabelecimento->id, $processo->id]) }}" method="POST" class="space-y-3">
                                @csrf
                                <div>
                                    <label class="text-xs font-medium text-slate-600">Nota (opcional)</label>
                                    <input type="text" name="descricao" placeholder="Ex: Fazer Notificação..."
                                           class="w-full mt-1 px-3 py-2 text-sm bg-slate-50 border border-slate-200 rounded-lg focus:bg-white focus:ring-2 focus:ring-blue-500/30 focus:border-blue-500" maxlength="255">
                                </div>
                                <div class="flex gap-2 justify-end">
                                    <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition">Cancelar</button>
                                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm transition">Confirmar</button>
                                </div>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- Dados do Estabelecimento + Processo --}}
        <div class="px-4 sm:px-5 py-4 grid grid-cols-1 lg:grid-cols-3 gap-5">
            {{-- Dados do Estabelecimento --}}
            <div class="lg:col-span-2 min-w-0">
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                    Estabelecimento
                </p>
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="{{ route('admin.estabelecimentos.show', $estabelecimento->id) }}" class="text-base font-bold text-blue-700 hover:text-blue-900 hover:underline underline-offset-2">{{ $estabelecimento->nome_fantasia ?? $estabelecimento->nome_razao_social }}</a>
                    @php $grupoRisco = $estabelecimento->getGrupoRisco(); @endphp
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold
                        {{ $grupoRisco === 'alto' ? 'bg-red-100 text-red-700' : ($grupoRisco === 'medio' ? 'bg-amber-100 text-amber-700' : ($grupoRisco === 'baixo' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600')) }}">
                        {{ $grupoRisco === 'alto' ? 'Alto Risco' : ($grupoRisco === 'medio' ? 'Médio Risco' : ($grupoRisco === 'baixo' ? 'Baixo Risco' : 'Indefinido')) }}
                    </span>
                    <button type="button" onclick="document.getElementById('modal-atividades-estab').classList.remove('hidden')"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-200 hover:bg-blue-100 transition-colors cursor-pointer">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                        Atividades
                    </button>
                    @if($estabelecimento->temAtividadesManuais())
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-semibold bg-amber-100 text-amber-800 border border-amber-200"
                          title="Este estabelecimento possui atividades inseridas manualmente que não constam no CNPJ">
                        <svg class="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                        </svg>
                        Atividades manuais fora do CNPJ ({{ $estabelecimento->getAtividadesManuais()->count() }})
                    </span>
                    @endif
                </div>
                <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <label class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">{{ $estabelecimento->tipo_pessoa === 'juridica' ? 'CNPJ' : 'CPF' }}</label>
                        <p class="text-[13px] font-medium text-slate-800 tabular-nums">{{ $estabelecimento->documento_formatado }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2">
                        <label class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Telefone(s)</label>
                        <p class="text-[13px] font-medium text-slate-800">{{ $estabelecimento->telefone_formatado ?? 'Não informado' }}</p>
                    </div>
                    <div class="rounded-xl bg-slate-50 px-3 py-2 sm:col-span-1">
                        <label class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Endereço</label>
                        <p class="text-[13px] font-medium text-slate-800 leading-snug">{{ $estabelecimento->endereco }}, {{ $estabelecimento->numero }}{{ $estabelecimento->complemento ? ', ' . $estabelecimento->complemento : '' }} - {{ $estabelecimento->bairro }}, {{ $estabelecimento->cidade }} - {{ $estabelecimento->estado }}</p>
                    </div>
                </div>
            </div>

            {{-- Dados do Processo --}}
            <div class="min-w-0 lg:border-l lg:border-slate-100 lg:pl-5">
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-1.5 flex items-center gap-1.5">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Dados do Processo
                </p>
                <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                    <div>
                        <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Tipo de Processo</dt>
                        <dd class="text-[13px] font-medium text-slate-800">{{ $processo->tipo_nome }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Número</dt>
                        <dd class="text-[13px] font-medium text-slate-800 tabular-nums">{{ $processo->numero_processo }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Status</dt>
                        <dd class="text-[13px] font-medium text-slate-800">{{ $processo->status_nome }}</dd>
                    </div>
                    <div>
                        <dt class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Ano</dt>
                        <dd class="text-[13px] font-medium text-slate-800">{{ $processo->ano }}</dd>
                    </div>
                </dl>
                @if($processo->observacoes)
                    <div class="mt-2">
                        <p class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Observações</p>
                        <p class="text-[13px] text-slate-600">{{ $processo->observacoes }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Card Setor/Responsável Atual --}}
    @php
        $processoArquivado = $processo->status === 'arquivado';
        $temDestinoExibicao = $processoArquivado
            ? ($processo->setor_antes_arquivar || $processo->responsavelAntesArquivar)
            : ($processo->setor_atual || $processo->responsavel_atual_id);
        $setorExibicaoNome = $processoArquivado ? $processo->setor_antes_arquivar_nome : $processo->setor_atual_nome;
        $responsavelExibicao = $processoArquivado ? $processo->responsavelAntesArquivar : $processo->responsavelAtual;
        $classesCardAtribuicao = $processoArquivado
            ? 'border-orange-200'
            : ($temDestinoExibicao ? 'border-cyan-200' : 'border-slate-200');
        $classesIconeAtribuicao = $processoArquivado
            ? 'bg-orange-100 text-orange-600'
            : ($temDestinoExibicao ? 'bg-cyan-100 text-cyan-600' : 'bg-slate-100 text-slate-400');
    @endphp
    <div class="bg-white rounded-2xl shadow-sm border {{ $classesCardAtribuicao }} p-3 sm:p-4 mb-6 overflow-hidden">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex items-start gap-2.5 sm:gap-3 min-w-0">
                <div class="w-9 h-9 sm:w-10 sm:h-10 shrink-0 rounded-lg {{ explode(' ', $classesIconeAtribuicao)[0] }} flex items-center justify-center">
                    <svg class="w-5 h-5 {{ explode(' ', $classesIconeAtribuicao)[1] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-medium text-slate-500 uppercase">{{ $processoArquivado ? 'Situação' : 'Com (Setor/Responsável)' }}</p>
                    @if($processoArquivado)
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-orange-50 text-orange-700 text-sm font-semibold">
                                Arquivado
                            </span>
                            @if($processo->data_arquivamento)
                                <span class="text-xs text-slate-500">em {{ $processo->data_arquivamento->format('d/m/Y H:i') }}</span>
                            @endif
                        </div>
                        @if($temDestinoExibicao)
                            <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-0.5 sm:gap-2 mt-2 min-w-0">
                                <span class="text-xs font-medium text-slate-500">Última tramitação:</span>
                                @if($setorExibicaoNome)
                                    <span class="text-sm font-semibold text-orange-700 break-words">{{ $setorExibicaoNome }}</span>
                                @endif
                                @if($responsavelExibicao)
                                    <span class="text-sm text-slate-700 break-words">{{ $setorExibicaoNome ? '- ' : '' }}{{ $responsavelExibicao->nome }}</span>
                                @endif
                            </div>
                        @endif
                        @if($processo->motivo_arquivamento)
                            <p class="mt-2 text-xs text-slate-500 break-words leading-5">Motivo: {{ $processo->motivo_arquivamento }}</p>
                        @endif
                    @elseif($temDestinoExibicao)
                        <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-0.5 sm:gap-2 mt-1 min-w-0">
                            @if($setorExibicaoNome)
                                <span class="text-sm font-semibold text-cyan-700 break-words">{{ $setorExibicaoNome }}</span>
                            @endif
                            @if($responsavelExibicao)
                                <span class="text-sm text-slate-700 break-words">
                                    {{ $setorExibicaoNome ? '- ' : '' }}{{ $responsavelExibicao->nome }}
                                </span>
                            @endif
                        </div>
                        <div class="flex flex-col items-start gap-2 mt-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-3 min-w-0">
                            @if(!$processo->responsavel_atual_id && $dataTramitacaoEfetiva)
                                <p class="text-xs text-teal-600 break-words leading-5">
                                    Tramitado para o setor em {{ $dataTramitacaoEfetiva->format('d/m/Y H:i') }}
                                </p>
                            @elseif($responsavelCienteEfetivo)
                                <p class="text-xs text-slate-500 break-words leading-5">
                                    Ciência em {{ $responsavelCienteEfetivo->format('d/m/Y H:i') }} ({{ $responsavelCienteEfetivo->locale('pt_BR')->diffForHumans() }})
                                </p>
                            @elseif($dataTramitacaoEfetiva)
                                <p class="text-xs text-amber-600 break-words leading-5">
                                    Tramitado em {{ $dataTramitacaoEfetiva->format('d/m/Y H:i') }} (aguardando ciência)
                                </p>
                            @endif
                            @if($responsavelCienteEfetivo && $dataTramitacaoEfetiva)
                                <p class="text-xs text-slate-400 break-words leading-5">
                                    tramitado em {{ $dataTramitacaoEfetiva->format('d/m/Y H:i') }}
                                </p>
                            @endif
                            @if($processo->prazo_atribuicao)
                                @php
                                    $prazoVencido = $processo->prazo_atribuicao->isPast();
                                    $prazoProximo = !$prazoVencido && $processo->prazo_atribuicao->diffInDays(now()) <= 3;
                                @endphp
                                <span class="inline-flex max-w-full items-center gap-1 px-2 py-1 rounded text-xs font-medium leading-5 {{ $prazoVencido ? 'bg-red-100 text-red-700' : ($prazoProximo ? 'bg-amber-100 text-amber-700' : 'bg-cyan-100 text-cyan-700') }}">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                    </svg>
                                    <span class="break-words">Prazo: {{ $processo->prazo_atribuicao->format('d/m/Y') }}
                                    @if($prazoVencido)
                                        (Vencido)
                                    @endif</span>
                                </span>
                            @endif
                        </div>
                    @else
                        <p class="text-sm text-slate-500 italic">Não atribuído</p>
                    @endif
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 w-full lg:w-auto lg:flex lg:items-center">
                {{-- Botão Ver Histórico de Atribuições --}}
                <button @click="modalHistoricoAtribuicoes = true" 
                        class="w-full whitespace-nowrap inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors"
                        title="Ver histórico de atribuições">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Histórico
                </button>
                @if($processo->status !== 'arquivado' && $processo->status !== 'parado')
                <button @click="modalAtribuir = true" class="w-full whitespace-nowrap inline-flex items-center justify-center gap-1.5 px-3 py-2 text-xs font-semibold text-white bg-cyan-600 hover:bg-cyan-700 shadow-sm shadow-cyan-600/20 rounded-lg transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                    </svg>
                    Tramitar Processo
                </button>
                @endif
            </div>
        </div>
    </div>

    {{-- Alerta de Documentos com Prazo em Aberto --}}
    @php
        $documentosComPrazoAberto = $documentosDigitais->filter(function($doc) {
            return $doc->temPrazo() && !$doc->isPrazoFinalizado() && $doc->status === 'assinado';
        });
    @endphp
    @if($documentosComPrazoAberto->count() > 0)
    <div class="bg-white border border-amber-200 rounded-xl overflow-hidden mb-6">
        <div class="px-4 py-3 bg-amber-50 border-b border-amber-200 flex items-center gap-2">
            <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-sm font-semibold text-amber-800">{{ $documentosComPrazoAberto->count() }} {{ $documentosComPrazoAberto->count() === 1 ? 'documento com prazo' : 'documentos com prazo' }}</span>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($documentosComPrazoAberto as $doc)
                @php
                    $temResposta = $doc->respostas->where('status', '!=', 'rejeitado')->count() > 0;
                    $corBadge = $doc->cor_status_prazo ?? 'gray';
                    $classesBadge = [
                        'red' => 'bg-red-100 text-red-700',
                        'yellow' => 'bg-amber-100 text-amber-700',
                        'green' => 'bg-green-100 text-green-700',
                        'blue' => 'bg-blue-100 text-blue-700',
                        'gray' => 'bg-slate-100 text-slate-600',
                    ];
                    $classeBadge = $classesBadge[$corBadge] ?? $classesBadge['gray'];
                @endphp
                <a href="#documento-digital-{{ $doc->id }}" onclick="scrollParaDocumento({{ $doc->id }})"
                   class="flex items-center gap-3 px-4 py-3 hover:bg-amber-50/50 transition cursor-pointer group">
                    <div class="w-8 h-8 rounded-lg {{ str_contains($classeBadge, 'red') ? 'bg-red-100' : 'bg-amber-100' }} flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 {{ str_contains($classeBadge, 'red') ? 'text-red-600' : 'text-amber-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-900 group-hover:text-blue-600 transition">{{ $doc->tipoDocumento->nome ?? 'Documento' }}</p>
                        <p class="text-[11px] text-slate-400">Nº {{ $doc->numero_documento }} · Clique para ir ao documento</p>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        @if($temResposta)
                            <span class="text-[10px] px-1.5 py-0.5 bg-green-100 text-green-700 rounded-full font-medium">Respondido</span>
                        @endif
                        <span class="text-[11px] font-semibold px-2 py-0.5 rounded-full {{ $classeBadge }}">{{ $doc->texto_status_prazo }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Duas Colunas: Menu/Ações (esquerda) e Documentos (direita) --}}
    <style>
        @media (max-width: 768px) {
            .processo-container {
                flex-direction: column !important;
            }
            .processo-menu {
                width: 100% !important;
                min-width: unset !important;
            }
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    </style>
    <div class="processo-container" style="display: flex; gap: 1.5rem;">
        {{-- Coluna Esquerda: Menus e Ações --}}
        <div class="processo-menu space-y-6" style="width: 25%; min-width: 280px;">
            {{-- Checklist de Documentos Obrigatórios --}}
            @if(isset($documentosObrigatorios) && $documentosObrigatorios->count() > 0)
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6" x-data="{ checklistAberto: false }">
                <div class="flex items-center justify-between cursor-pointer" @click="checklistAberto = !checklistAberto">
                    <h3 class="text-sm font-semibold text-slate-900 uppercase flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>
                        </svg>
                        @php
                            $tipoProcessoCodigo = $processo->tipoProcesso->codigo ?? $processo->tipo ?? 'licenciamento';
                            $tituloChecklist = match($tipoProcessoCodigo) {
                                'projeto_arquitetonico' => 'Docs. Projeto Arq.',
                                'analise_rotulagem' => 'Docs. Rotulagem',
                                default => 'Docs. Licenciamento'
                            };
                        @endphp
                        {{ $tituloChecklist }}
                        @php
                            // Docs base (sem unidade) - sempre existem
                            $totalObrigatorios = $documentosObrigatorios->count();
                            $totalOk = $documentosObrigatorios->where('status', 'aprovado')->count();
                            $totalPendente = $documentosObrigatorios->where('status', 'pendente')->count();
                            $totalRejeitado = $documentosObrigatorios->where('status', 'rejeitado')->count();
                            $totalNaoEnviado = $documentosObrigatorios->whereNull('status')->count();
                            $totalEnviados = $documentosObrigatorios->whereIn('status', ['pendente', 'aprovado'])->count();

                            // Soma docs das unidades (adicionais)
                            if (!empty($documentosObrigatoriosPorUnidade) && count($documentosObrigatoriosPorUnidade) > 0) {
                                foreach ($documentosObrigatoriosPorUnidade as $info) {
                                    $docsObrig = $info['documentos']->where('obrigatorio', true);
                                    $totalObrigatorios += $docsObrig->count();
                                    $totalOk += $docsObrig->where('status', 'aprovado')->count();
                                    $totalPendente += $docsObrig->where('status', 'pendente')->count();
                                    $totalRejeitado += $docsObrig->where('status', 'rejeitado')->count();
                                    $totalNaoEnviado += $docsObrig->whereNull('status')->count();
                                    $totalEnviados += $docsObrig->whereIn('status', ['pendente', 'aprovado'])->count();
                                }
                            }
                            $percentualEnviados = $totalObrigatorios > 0 ? round(($totalEnviados / $totalObrigatorios) * 100) : 0;
                            $percentualAprovados = $totalObrigatorios > 0 ? round(($totalOk / $totalObrigatorios) * 100) : 0;
                            $todosAprovados = ($totalOk == $totalObrigatorios && $totalObrigatorios > 0);
                            $todosEnviados = ($percentualEnviados == 100 && $totalObrigatorios > 0);
                        @endphp
                        <span class="px-2 py-0.5 text-xs font-medium rounded {{ $todosAprovados ? 'bg-green-100 text-green-800' : ($todosEnviados ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800') }}">
                            {{ $totalEnviados }}/{{ $totalObrigatorios }}
                        </span>
                    </h3>
                    <svg class="w-4 h-4 text-slate-500 transition-transform" :class="{ 'rotate-180': checklistAberto }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </div>

                {{-- Barra de Progresso Compacta --}}
                <div class="mt-3 px-1">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="text-[11px] font-medium text-slate-600">Progresso de Aprovação</span>
                        <span class="text-xs font-bold px-1.5 py-0.5 rounded {{ $todosAprovados ? 'bg-green-100 text-green-700' : ($todosEnviados ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700') }}">
                            {{ $percentualEnviados }}%
                        </span>
                    </div>
                    <div class="relative mb-2">
                        <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden shadow-inner flex">
                            @if($totalOk > 0)
                            <div class="h-full transition-all duration-500 ease-out bg-gradient-to-r from-green-400 to-green-600 {{ $totalPendente == 0 && $totalRejeitado == 0 && $totalNaoEnviado == 0 ? 'rounded-full' : 'rounded-l-full' }}" 
                                 style="width: {{ $percentualAprovados }}%">
                            </div>
                            @endif
                            @if($totalPendente > 0)
                            <div class="h-full transition-all duration-500 ease-out bg-gradient-to-r from-amber-400 to-amber-500 {{ $totalOk == 0 ? 'rounded-l-full' : '' }} {{ $totalRejeitado == 0 && $totalNaoEnviado == 0 ? 'rounded-r-full' : '' }}" 
                                 style="width: {{ $totalObrigatorios > 0 ? round(($totalPendente / $totalObrigatorios) * 100) : 0 }}%">
                            </div>
                            @endif
                        </div>
                        @if($todosAprovados)
                        <div class="absolute -top-0.5 -right-0.5">
                            <span class="flex h-3 w-3">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-3 w-3 bg-green-500 items-center justify-center">
                                    <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                    </svg>
                                </span>
                            </span>
                        </div>
                        @elseif($todosEnviados && $totalPendente > 0)
                        <div class="absolute -top-0.5 -right-0.5">
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-amber-500 items-center justify-center animate-pulse">
                                <svg class="w-2 h-2 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </span>
                        </div>
                        @endif
                    </div>
                    {{-- Status textual --}}
                    @if($todosAprovados)
                    <p class="text-[11px] text-green-600 font-medium flex items-center gap-1 mb-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Todos os documentos foram aprovados!
                    </p>
                    @elseif($todosEnviados && $totalPendente > 0)
                    <p class="text-[11px] text-amber-600 font-medium flex items-center gap-1 mb-2">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Todos enviados! <span class="font-bold">{{ $totalPendente }}</span> aguardando aprovação
                    </p>
                    @endif
                    <div class="flex flex-wrap gap-1.5 text-[10px]">
                        @if($totalOk > 0)
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-green-50 text-green-700 rounded-full border border-green-200">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                            </svg>
                            {{ $totalOk }} aprovado{{ $totalOk > 1 ? 's' : '' }}
                        </span>
                        @endif
                        @if($totalPendente > 0)
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded-full border border-amber-200">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            {{ $totalPendente }} pendente{{ $totalPendente > 1 ? 's' : '' }}
                        </span>
                        @endif
                        @if($totalRejeitado > 0)
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-red-50 text-red-700 rounded-full border border-red-200">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            {{ $totalRejeitado }} rejeitado{{ $totalRejeitado > 1 ? 's' : '' }}
                        </span>
                        @endif
                        @if($totalNaoEnviado > 0)
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-slate-50 text-slate-600 rounded-full border border-slate-200">
                            <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                            </svg>
                            {{ $totalNaoEnviado }} não enviado{{ $totalNaoEnviado > 1 ? 's' : '' }}
                        </span>
                        @endif
                    </div>
                </div>

                {{-- Progresso por Unidade --}}
                @if(!empty($documentosObrigatoriosPorUnidade) && count($documentosObrigatoriosPorUnidade) > 0)
                <div class="mt-3 pt-3 border-t border-slate-100 space-y-2">
                    <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Por Unidade</p>
                    @foreach($documentosObrigatoriosPorUnidade as $pastaId => $info)
                    @php
                        $pctUnidade = $info['total'] > 0 ? round(($info['aprovados'] / $info['total']) * 100) : 0;
                        $unidadeEnviados = $info['documentos']->where('obrigatorio', true)->whereIn('status', ['pendente', 'aprovado'])->count();
                        $pctUnidadeEnvio = $info['total'] > 0 ? round(($unidadeEnviados / $info['total']) * 100) : 0;
                        $unidadePendentes = $info['documentos']->where('obrigatorio', true)->where('status', 'pendente')->count();
                        $unidadeRejeitados = $info['documentos']->where('obrigatorio', true)->where('status', 'rejeitado')->count();
                        $unidadeNaoEnviados = $info['documentos']->where('obrigatorio', true)->whereNull('status')->count();
                        $unidadeTodosAprovados = ($info['aprovados'] == $info['total'] && $info['total'] > 0);
                        $unidadeTodosEnviados = ($pctUnidadeEnvio == 100 && $info['total'] > 0);
                    @endphp
                    <div x-data="{ aberto: false }">
                        <div class="flex items-center gap-2 cursor-pointer hover:bg-slate-50 rounded-lg p-1 -mx-1 transition-colors" @click="aberto = !aberto">
                            <svg class="w-3.5 h-3.5 text-slate-400 transition-transform flex-shrink-0" :class="{ 'rotate-90': aberto }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                            </svg>
                            <span class="text-xs text-slate-600 truncate" title="{{ $info['nome'] }}">{{ $info['nome'] }}</span>
                            @if($unidadePendentes > 0 && $unidadeTodosEnviados)
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 flex-shrink-0">{{ $unidadePendentes }} pendente{{ $unidadePendentes > 1 ? 's' : '' }}</span>
                            @elseif($unidadeNaoEnviados > 0)
                            <span class="text-[9px] font-bold px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-600 flex-shrink-0">{{ $unidadeNaoEnviados }} não enviado{{ $unidadeNaoEnviados > 1 ? 's' : '' }}</span>
                            @endif
                            <div class="flex-1 bg-slate-200 rounded-full h-2 overflow-hidden">
                                <div class="h-full rounded-full transition-all {{ $unidadeTodosAprovados ? 'bg-green-500' : ($unidadeTodosEnviados ? 'bg-amber-500' : 'bg-violet-500') }}" style="width: {{ $pctUnidadeEnvio }}%"></div>
                            </div>
                            <span class="text-[10px] font-bold flex-shrink-0 {{ $unidadeTodosAprovados ? 'text-green-600' : ($unidadeTodosEnviados ? 'text-amber-600' : 'text-violet-600') }}">{{ $unidadeEnviados }}/{{ $info['total'] }}</span>
                        </div>
                        {{-- Documentos da Unidade (expandível) --}}
                        <div x-show="aberto" x-collapse x-cloak class="ml-4 mt-1 space-y-1">
                            @foreach($info['documentos']->where('obrigatorio', true) as $docU)
                            @php
                                $statusDocU = $docU['status'] ?? null;
                                $isAprovadoU = $statusDocU === 'aprovado';
                                $isPendenteU = $statusDocU === 'pendente';
                                $isRejeitadoU = $statusDocU === 'rejeitado';
                            @endphp
                            <div class="flex items-center gap-2 p-1.5 rounded text-xs
                                {{ $isAprovadoU ? 'bg-green-50' : '' }}
                                {{ $isPendenteU ? 'bg-amber-50' : '' }}
                                {{ $isRejeitadoU ? 'bg-red-50' : '' }}
                                {{ !$statusDocU ? 'bg-slate-50' : '' }}">
                                @if($isAprovadoU)
                                    <span class="w-4 h-4 rounded-full bg-green-100 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-2.5 h-2.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                @elseif($isPendenteU)
                                    <span class="w-4 h-4 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-2.5 h-2.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    </span>
                                @elseif($isRejeitadoU)
                                    <span class="w-4 h-4 rounded-full bg-red-100 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-2.5 h-2.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </span>
                                @else
                                    <span class="w-4 h-4 rounded-full bg-slate-200 flex items-center justify-center flex-shrink-0">
                                        <svg class="w-2.5 h-2.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                                    </span>
                                @endif
                                <span class="flex-1 text-slate-700 leading-tight break-words">{{ $docU['nome'] }}</span>
                                <span class="flex-shrink-0 text-[10px] font-medium
                                    {{ $isAprovadoU ? 'text-green-600' : '' }}
                                    {{ $isPendenteU ? 'text-amber-600' : '' }}
                                    {{ $isRejeitadoU ? 'text-red-600' : '' }}
                                    {{ !$statusDocU ? 'text-slate-400' : '' }}">
                                    @if($isAprovadoU) ✓ OK
                                    @elseif($isPendenteU) Pendente
                                    @elseif($isRejeitadoU) Rejeitado
                                    @else Não enviado
                                    @endif
                                </span>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                <div x-show="checklistAberto" x-transition class="mt-4 space-y-2">
                    {{-- Resumo --}}
                    <div class="flex flex-wrap gap-2 mb-3 text-xs">
                        @if($totalOk > 0)
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full">✓ {{ $totalOk }} OK</span>
                        @endif
                        @if($totalPendente > 0)
                        <span class="px-2 py-1 bg-amber-100 text-amber-700 rounded-full">⏳ {{ $totalPendente }} Pendente(s)</span>
                        @endif
                        @if($totalRejeitado > 0)
                        <span class="px-2 py-1 bg-red-100 text-red-700 rounded-full">✗ {{ $totalRejeitado }} Rejeitado(s)</span>
                        @endif
                        @if($totalNaoEnviado > 0)
                        <span class="px-2 py-1 bg-slate-100 text-slate-700 rounded-full">○ {{ $totalNaoEnviado }} Não enviado(s)</span>
                        @endif
                    </div>

                    {{-- Lista de Documentos --}}
                    @foreach($documentosObrigatorios as $doc)
                    @php
                        $statusDoc = $doc['status'] ?? null;
                        $isAprovado = $statusDoc === 'aprovado';
                        $isPendente = $statusDoc === 'pendente';
                        $isRejeitado = $statusDoc === 'rejeitado';
                    @endphp
                    <div class="flex items-center gap-2 p-2 rounded-lg text-sm
                        {{ $isAprovado ? 'bg-green-50' : '' }}
                        {{ $isPendente ? 'bg-amber-50' : '' }}
                        {{ $isRejeitado ? 'bg-red-50' : '' }}
                        {{ !$statusDoc ? 'bg-slate-50' : '' }}">
                        {{-- Ícone de Status --}}
                        <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0
                            {{ $isAprovado ? 'bg-green-100' : '' }}
                            {{ $isPendente ? 'bg-amber-100' : '' }}
                            {{ $isRejeitado ? 'bg-red-100' : '' }}
                            {{ !$statusDoc ? 'bg-slate-200' : '' }}">
                            @if($isAprovado)
                                <svg class="w-3.5 h-3.5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            @elseif($isPendente)
                                <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            @elseif($isRejeitado)
                                <svg class="w-3.5 h-3.5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @else
                                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                </svg>
                            @endif
                        </div>
                        
                        {{-- Nome do Documento --}}
                        <div class="flex-1">
                            <span class="text-slate-900 block text-sm leading-tight break-words">{{ $doc['nome'] }}</span>
                            @if($doc['obrigatorio'])
                            <span class="text-[10px] text-red-500">Obrigatório</span>
                            @endif
                        </div>
                        
                        {{-- Badge de Status --}}
                        <div class="flex-shrink-0">
                            @if($isAprovado)
                                <span class="text-xs text-green-600 font-medium">✓ OK</span>
                            @elseif($isPendente)
                                <span class="text-xs text-amber-600 font-medium">Pendente</span>
                            @elseif($isRejeitado)
                                <span class="text-xs text-red-600 font-medium">Rejeitado</span>
                            @else
                                <span class="text-xs text-slate-500">Não enviado</span>
                            @endif
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Menu de Opções --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6">
                <h3 class="text-sm font-semibold text-slate-900 uppercase mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>
                    Menu de Opções
                </h3>
                <div class="space-y-2">
                    @if($processo->status !== 'arquivado')
                    <button @click="modalUpload = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                        </svg>
                        Upload de Arquivos
                    </button>
                    <a href="{{ route('admin.documentos.create', ['processo_id' => $processo->id]) }}" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Criar Documento Digital
                    </a>
                    @endif
                    @if($processo->status !== 'parado')
                    @if(auth('interno')->user()->isAdmin() || auth('interno')->user()->isGestor())
                    <a href="{{ route('admin.ordens-servico.create', ['estabelecimento_id' => $estabelecimento->id, 'processo_id' => $processo->id]) }}" 
                       class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        Ordem de Serviço
                    </a>
                    @endif
                    @endif
                    <button @click="modalAlertas = true" 
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        Alertas
                        @if($alertas->where('status', 'pendente')->count() > 0)
                        <span class="ml-auto px-2 py-0.5 bg-red-100 text-red-700 text-xs font-semibold rounded-full">
                            {{ $alertas->where('status', 'pendente')->count() }}
                        </span>
                        @endif
                    </button>
                </div>
            </div>

            {{-- Ações do Processo --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 p-6">
                <h3 class="text-sm font-semibold text-slate-900 uppercase mb-4 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    Ações do Processo
                </h3>
                <div class="space-y-2">
                    <a href="{{ route('admin.estabelecimentos.processos.integra', [$estabelecimento->id, $processo->id]) }}" 
                       class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Processo na Íntegra
                    </a>
                    
                    @if($processo->status !== 'arquivado' && $processo->status !== 'parado')
                    <button @click="modalAtribuir = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        Tramitar Processo
                    </button>
                    
                    <button @click="modalPastas = true; carregarPastas()" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-purple-700 bg-purple-50 hover:bg-purple-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                        </svg>
                        Pastas Processo
                    </button>
                    @endif

                    {{-- Adicionar unidade: também disponível com o processo parado --}}
                    @if(($tipoProcessoTemUnidades ?? false) && in_array($processo->status, ['aberto', 'parado']))
                    <button type="button" onclick="document.getElementById('modal-nova-unidade-admin').classList.remove('hidden')"
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-violet-700 bg-violet-50 hover:bg-violet-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Adicionar Unidade
                    </button>
                    @endif

                    @if($processo->status !== 'arquivado' && $processo->status !== 'parado')
                    <button @click="modalParar = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Parar Processo
                    </button>
                    @endif

                    @if($processo->status === 'parado')
                    <form action="{{ route('admin.estabelecimentos.processos.reiniciar', [$estabelecimento->id, $processo->id]) }}" method="POST" class="w-full">
                        @csrf
                        <button type="submit" style="animation: reiniciarPulse 1.6s ease-in-out infinite;" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-white bg-green-500 hover:bg-green-600 rounded-lg shadow-md shadow-green-300 transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Reiniciar Processo
                        </button>
                        <style>
                            @keyframes reiniciarPulse {
                                0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(34,197,94,0.5); }
                                50% { transform: scale(1.04); box-shadow: 0 0 0 8px rgba(34,197,94,0); }
                            }
                        </style>
                    </form>
                    @endif

                    @if($processo->status === 'arquivado')
                    <form action="{{ route('admin.estabelecimentos.processos.desarquivar', [$estabelecimento->id, $processo->id]) }}" method="POST" class="w-full">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-green-700 bg-green-50 hover:bg-green-100 rounded-lg transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Desarquivar Processo
                        </button>
                    </form>
                    @elseif($processo->status !== 'parado')
                    <button @click="modalArquivar = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                        </svg>
                        Arquivar Processo
                    </button>
                    @endif
                               <button @click="modalHistorico = true" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-green-700 bg-green-50 hover:bg-green-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Histórico
                    </button>
                    <button type="button" @click="$dispatch('abrir-linha-tempo')" class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                        </svg>
                        Tempo por etapa
                    </button>
                    @if(auth('interno')->user()->isAdmin())
                    <button @click="analisarDocumentosIA()" 
                            :disabled="iaAnalisandoLote"
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-violet-700 bg-violet-50 hover:bg-violet-100 rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                        <template x-if="!iaAnalisandoLote">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                            </svg>
                        </template>
                        <template x-if="iaAnalisandoLote">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </template>
                        <span x-text="iaAnalisandoLote ? `Analisando ${iaLoteProgresso}/${iaLoteTotal}...` : 'Analisar Documentos com IA'"></span>
                    </button>
                    @endif

                    {{-- Resultado da análise em lote --}}
                    <template x-if="iaLoteResultados.length > 0">
                        <div class="mt-2 p-3 bg-slate-50 rounded-lg border border-slate-200 space-y-1.5">
                            <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide">Resultado da Análise IA</p>
                            <template x-for="r in iaLoteResultados" :key="r.id">
                                <div class="flex items-start gap-2 text-xs py-1">
                                    <span x-show="r.decisao === 'aprovado'" class="flex-shrink-0 w-4 h-4 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center mt-0.5">
                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                    </span>
                                    <span x-show="r.decisao === 'rejeitado'" class="flex-shrink-0 w-4 h-4 rounded-full bg-red-100 text-red-600 flex items-center justify-center mt-0.5">
                                        <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </span>
                                    <span x-show="r.decisao === 'erro'" class="flex-shrink-0 w-4 h-4 rounded-full bg-amber-100 text-amber-600 flex items-center justify-center mt-0.5">!</span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-800 truncate" x-text="r.nome"></p>
                                        <p class="text-slate-500 leading-snug" x-text="r.motivo"></p>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    @if(auth('interno')->user()->isAdmin())
                    <button @click="modalExcluirProcesso = true" 
                            class="w-full flex items-center gap-3 px-3 py-2 text-sm font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                        Excluir Processo
                    </button>
                    @endif
                </div>
            </div>

        </div>

        {{-- Coluna Direita: Lista de Documentos/Arquivos --}}
        <div class="min-w-0" style="flex: 1;">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80">
                {{-- Header da Lista de Documentos --}}
            <div class="p-4 sm:p-6 border-b border-slate-200">
                    @php
                        $pendentesDigitais = $documentosDigitais->filter(function ($docDigital) {
                            return $docDigital->respostas && $docDigital->respostas->where('status', 'pendente')->count() > 0;
                        })->count();
                        $pendentesArquivos = $processo->documentos
                            ->where('tipo_documento', '!=', 'documento_digital')
                            ->where('tipo_usuario', 'externo')
                            ->where('status_aprovacao', 'pendente')
                            ->count();
                        $totalPendentes = $pendentesDigitais + $pendentesArquivos;
                    @endphp
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-base sm:text-lg font-bold text-slate-900 flex items-center gap-3 min-w-0">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <span class="break-words">Lista de Documentos/Arquivos</span>
                        </h2>
                        <button type="button"
                                @click="statusFiltro = statusFiltro === 'pendente' ? null : 'pendente'"
                                :class="statusFiltro === 'pendente' ? 'text-yellow-700 bg-yellow-100 border-yellow-200' : 'text-slate-600 bg-slate-50 border-slate-200 hover:bg-slate-100'"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-3 py-2 text-xs font-semibold border rounded-lg transition-colors whitespace-nowrap">
                            <i class="far fa-clock" style="font-size: 12px;"></i>
                            Pendentes
                            <span class="px-2 py-0.5 text-[10px] rounded-full"
                                  :class="statusFiltro === 'pendente' ? 'bg-yellow-200 text-yellow-800' : 'bg-slate-200 text-slate-700'">
                                {{ $totalPendentes }}
                            </span>
                        </button>
                    </div>
                </div>

                {{-- Tabs de Documentos --}}
                @php
                    // Unidades que podem ser excluídas direto pela aba: sem documentos válidos, digitais ou OS.
                    // O valor é a quantidade de arquivos rejeitados (removidos junto na exclusão).
                    $unidadesExcluiveisAba = ($podeGerenciarUnidadesProcesso ?? false) && $processo->status !== 'arquivado'
                        ? $pastasGerenciaveis->filter(fn ($pg) => $pg->unidade_id
                                && (($pg->documentos_validos_count ?? 0) + ($pg->documentos_digitais_count ?? 0) + (int) ($osPorPasta[$pg->id] ?? 0)) === 0)
                            ->mapWithKeys(fn ($pg) => [$pg->id => (int) ($pg->documentos_rejeitados_count ?? 0)])
                        : collect();
                @endphp
                <form id="form-excluir-unidade-aba" method="POST" class="hidden">@csrf</form>
                <div class="border-b border-slate-200 bg-slate-50">
                    <nav class="flex px-3 sm:px-6 overflow-x-auto" aria-label="Tabs"
                         x-data="{
                            unidadesExcluiveisAba: @js((object) $unidadesExcluiveisAba->all()),
                            excluirUnidadeAba(pasta) {
                                const rejeitados = this.unidadesExcluiveisAba[pasta.id] || 0;
                                let msg = `Excluir a unidade &quot;${pasta.nome}&quot; do processo?`;
                                if (rejeitados > 0) msg += ` Os ${rejeitados} arquivo(s) rejeitado(s) desta unidade também serão removidos.`;
                                msg += ' Esta ação não pode ser desfeita.';
                                if (!confirm(msg)) return;
                                const form = document.getElementById('form-excluir-unidade-aba');
                                form.action = @js(url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/unidades')) + '/' + pasta.id + '/excluir';
                                form.submit();
                            }
                         }">
                        <button @click="pastaAtiva = null" 
                                :class="pastaAtiva === null ? 'text-blue-600 border-blue-600' : 'text-slate-600 border-transparent hover:text-slate-800 hover:border-slate-300'"
                                class="px-3 sm:px-4 py-3 sm:py-4 text-xs sm:text-sm font-semibold border-b-2 transition-colors whitespace-nowrap">
                            Todos
                            <span class="ml-2 px-2.5 py-0.5 text-xs font-semibold rounded-full"
                                  :class="pastaAtiva === null ? 'bg-blue-100 text-blue-700' : 'bg-slate-200 text-slate-700'">
                                {{ $todosDocumentos->count() }}
                            </span>
                        </button>
                        
                        {{-- Pastas Dinâmicas --}}
                        <template x-for="pasta in pastas" :key="pasta.id">
                            <div class="flex items-center border-b-2 transition-colors"
                                 :class="pastaAtiva === pasta.id ? '' : 'border-transparent hover:border-slate-300'"
                                 :style="pastaAtiva === pasta.id ? `border-color: ${pasta.cor}` : ''">
                                <button @click="pastaAtiva = pasta.id"
                                        :class="pastaAtiva === pasta.id ? '' : 'text-slate-600 hover:text-slate-800'"
                                        :style="pastaAtiva === pasta.id ? `color: ${pasta.cor}` : ''"
                                        class="pl-3 sm:pl-4 pr-2 py-3 sm:py-4 text-xs sm:text-sm font-semibold transition-colors whitespace-nowrap flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                    </svg>
                                    <span x-text="pasta.nome"></span>
                                    <span class="ml-1 px-2.5 py-0.5 text-xs font-semibold rounded-full"
                                          :style="`background-color: ${pasta.cor}20; color: ${pasta.cor}`"
                                          x-text="contarDocumentosPorPasta(pasta.id)">
                                    </span>
                                </button>
                                {{-- Excluir unidade sem documentos (também com o processo parado) --}}
                                <button type="button"
                                        x-show="unidadesExcluiveisAba[pasta.id] !== undefined"
                                        @click.stop="excluirUnidadeAba(pasta)"
                                        title="Excluir esta unidade (sem documentos)"
                                        class="mr-2 sm:mr-3 p-1 rounded-md text-slate-400 hover:text-red-600 hover:bg-red-50 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </template>
                    </nav>
                </div>

                {{-- Lista de Documentos --}}
                <div class="p-3 sm:p-4">
                    @if($todosDocumentos->isEmpty())
                        <div class="text-center py-16">
                            <svg class="w-16 h-16 text-slate-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                            </svg>
                            <p class="text-base font-semibold text-slate-700 mb-2">Nenhum documento anexado</p>
                            <p class="text-sm text-slate-500">Comece a adicionar documentos usando o menu de opções</p>
                        </div>
                    @else
                        @php
                            $pastasDoProcesso = $processo->pastas()
                                ->orderBy('ordem')
                                ->orderBy('nome')
                                ->get(['id', 'nome', 'cor'])
                                ->keyBy('id');

                            $contagemPorGrupoPasta = $todosDocumentos
                                ->groupBy(fn($item) => $item['documento']->pasta_id ?? 'sem_pasta')
                                ->map(fn($itens) => $itens->count());
                        @endphp
                        <div class="space-y-2">
                            {{-- Lista Unificada de Documentos (Digitais e Arquivos Externos) --}}
                            @foreach($todosDocumentos as $indice => $item)
                                @php
                                    $pastaAtualId = $item['documento']->pasta_id ?? null;
                                    $chaveGrupoAtual = $pastaAtualId ?? 'sem_pasta';

                                    $itemAnterior = $indice > 0 ? $todosDocumentos[$indice - 1] : null;
                                    $pastaAnteriorId = $itemAnterior ? ($itemAnterior['documento']->pasta_id ?? null) : '__inicio__';
                                    $chaveGrupoAnterior = $pastaAnteriorId ?? 'sem_pasta';

                                    $mostrarCabecalhoGrupo = $indice === 0 || $chaveGrupoAtual !== $chaveGrupoAnterior;

                                    $pastaAtual = $pastaAtualId ? $pastasDoProcesso->get($pastaAtualId) : null;
                                    $nomeGrupo = $pastaAtual ? $pastaAtual->nome : 'Sem pasta';
                                    $corGrupo = $pastaAtual ? $pastaAtual->cor : '#9CA3AF';
                                    $contagemGrupo = $contagemPorGrupoPasta[$chaveGrupoAtual] ?? 0;
                                @endphp

                                @if($mostrarCabecalhoGrupo)
                                    <div x-show="pastaAtiva === null && statusFiltro === null"
                                         class="flex items-center justify-between px-3 py-2 mt-4 mb-2 bg-slate-50 border border-slate-200 rounded-lg"
                                         style="display: none;">
                                        <div class="flex items-center gap-2">
                                            <span class="w-2.5 h-2.5 rounded-full" style="background-color: {{ $corGrupo }}"></span>
                                            <span class="text-xs font-semibold text-slate-700">{{ $nomeGrupo }}</span>
                                        </div>
                                        <span class="px-2 py-0.5 text-[10px] font-semibold bg-white border border-slate-200 text-slate-600 rounded-full">
                                            {{ $contagemGrupo }}
                                        </span>
                                    </div>
                                @endif

                                @if($item['tipo'] === 'digital')
                                    @php
                                        $docDigital = $item['documento'];
                                        $assinaturas = ($docDigital->assinaturas ?? collect())->sortBy('ordem')->values();
                                        $assinaturasPendentes = $assinaturas->where('status', 'pendente')->count();
                                        $todasAssinaturas = $assinaturas->count();
                                        $temAssinaturasPendentes = $assinaturasPendentes > 0;
                                        $podeProrrogarPrazo = $docDigital->podeProrrogarPrazo();
                                        $diasProrrogacaoDisponiveis = $docDigital->dias_prorrogacao_disponiveis;
                                        $podeDefinirPrazo = ($docDigital->tipoDocumento?->tem_prazo ?? false)
                                            && !$docDigital->prazo_dias
                                            && !$docDigital->data_vencimento
                                            && $docDigital->status === 'assinado'
                                            && $docDigital->todasAssinaturasCompletas();
                                        
                                        // Verificar se o usuário logado precisa assinar este documento
                                        $usuarioLogado = auth('interno')->user();
                                        $assinaturaUsuario = $assinaturas->first(function($ass) use ($usuarioLogado) {
                                            return $ass->usuario_interno_id == $usuarioLogado->id && $ass->status === 'pendente';
                                        });
                                        $usuarioPrecisaAssinar = $assinaturaUsuario !== null && $docDigital->status !== 'rascunho';

                                        $assinaturasRealizadasLista = $assinaturas->filter(fn($ass) => $ass->status === 'assinado')->values();
                                        $assinaturasPendentesLista = $assinaturas->filter(fn($ass) => $ass->status === 'pendente')->values();
                                    @endphp
                                @php
                                    // Determinar cor da borda baseado no status do documento
                                    $temPrazoAberto = $docDigital->temPrazo() && !$docDigital->isPrazoFinalizado() && $docDigital->todasAssinaturasCompletas() && $docDigital->status === 'assinado';
                                    $temRespostasPendentes = $docDigital->respostas && $docDigital->respostas->where('status', 'pendente')->count() > 0;
                                    $temRespostasAprovadas = $docDigital->respostas && $docDigital->respostas->where('status', 'aprovado')->count() > 0;
                                    $totalRespostas = $docDigital->respostas ? $docDigital->respostas->count() : 0;
                                    $prazoFinalizado = $docDigital->isPrazoFinalizado();
                                    
                                    // Definir status geral do documento para exibição
                                    if ($docDigital->status === 'rascunho') {
                                        $corBorda = 'border-slate-300';
                                        $statusGeral = 'rascunho';
                                    } elseif ($temAssinaturasPendentes) {
                                        $corBorda = 'border-orange-500';
                                        $statusGeral = 'aguardando_assinatura';
                                    } elseif ($temRespostasPendentes) {
                                        $corBorda = 'border-yellow-500';
                                        $statusGeral = 'resposta_pendente';
                                    } elseif ($temPrazoAberto) {
                                        $corBorda = 'border-amber-500';
                                        $statusGeral = 'prazo_aberto';
                                    } elseif ($prazoFinalizado && $temRespostasAprovadas) {
                                        $corBorda = 'border-green-500';
                                        $statusGeral = 'finalizado';
                                    } elseif ($docDigital->status === 'assinado' && $docDigital->todasAssinaturasCompletas()) {
                                        $corBorda = 'border-green-500';
                                        $statusGeral = 'concluido';
                                    } else {
                                        $corBorda = 'border-slate-300';
                                        $statusGeral = 'outros';
                                    }
                                @endphp
                                  <div id="documento-digital-{{ $docDigital->id }}"
                                      data-documento-digital-id="{{ $docDigital->id }}"
                                      x-data="{ pastaDocumento: {{ $docDigital->pasta_id ?? 'null' }}, expanded: {{ $temRespostasPendentes || $documentoDigitalDirecionadoId === $docDigital->id ? 'true' : 'false' }}, statusPendente: {{ $temRespostasPendentes ? 'true' : 'false' }}, destacado: {{ $documentoDigitalDirecionadoId === $docDigital->id ? 'true' : 'false' }} }"
                                      x-show="(pastaAtiva === null || pastaAtiva === pastaDocumento) && (statusFiltro === null || (statusFiltro === 'pendente' && statusPendente))"
                                     :class="destacado ? 'ring-2 ring-emerald-300 bg-emerald-50/60 shadow-md scroll-mt-24' : ''"
                                     class="documento-digital-item bg-white rounded-lg border border-slate-200 border-l-4 {{ $corBorda }} hover:shadow-md transition-all"
                                     style="border-top-color: #e5e7eb; border-right-color: #e5e7eb; border-bottom-color: #e5e7eb;">
                                    
                                    {{-- Layout Flex Principal --}}
                                    <div class="p-3 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        {{-- ESQUERDA: Ícone + Nome + Data --}}
                                        <div class="flex items-start gap-2 min-w-0 flex-1">
                                            {{-- Ícone com indicador de status --}}
                                            <div class="relative flex-shrink-0">
                                                <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center">
                                                    @if($statusGeral === 'rascunho')
                                                        <i class="far fa-edit fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @elseif($statusGeral === 'aguardando_assinatura')
                                                        <i class="fas fa-file-signature fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @elseif($statusGeral === 'finalizado')
                                                        <i class="far fa-check-circle fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @elseif($statusGeral === 'resposta_pendente')
                                                        <i class="far fa-comment-dots fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @elseif($statusGeral === 'prazo_aberto')
                                                        <i class="far fa-clock fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @elseif($statusGeral === 'concluido')
                                                        <i class="fas fa-clipboard-check fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @else
                                                        <i class="far fa-file-alt fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                    @endif
                                                </div>
                                                @if($totalRespostas > 0)
                                                    <span class="absolute -top-1 -right-1 w-4 h-4 rounded-full text-[9px] font-bold flex items-center justify-center
                                                        {{ $temRespostasPendentes ? 'bg-yellow-500 text-white' : 'bg-green-500 text-white' }}">
                                                        {{ $totalRespostas }}
                                                    </span>
                                                @endif
                                            </div>
                                            
                                            {{-- Nome, Status e Data --}}
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-2 min-w-0">
                                                    @if($docDigital->podeEditar())
                                                        <a href="{{ route('admin.documentos.edit', $docDigital->id) }}" class="text-xs sm:text-sm font-semibold text-slate-900 hover:text-blue-600 truncate">{{ $docDigital->nome ?? $docDigital->tipoDocumento->nome }}</a>
                                                    @elseif($docDigital->status !== 'rascunho')
                                                        <span @click="pdfUrl = '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}'; modalVisualizador = true" class="text-xs sm:text-sm font-semibold text-slate-900 hover:text-blue-600 cursor-pointer truncate">{{ $docDigital->nome ?? $docDigital->tipoDocumento->nome }}</span>
                                                    @else
                                                        <span class="text-xs sm:text-sm font-semibold text-slate-900 truncate">{{ $docDigital->nome ?? $docDigital->tipoDocumento->nome }}</span>
                                                    @endif
                                                    <span class="text-[10px] sm:text-[11px] text-slate-400 flex-shrink-0">{{ $docDigital->created_at->format('d/m/Y') }}</span>
                                                </div>
                                                
                                                <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                                    <span class="text-[11px] sm:text-xs text-slate-500">{{ $docDigital->numero_documento }}</span>
                                                    
                                                    {{-- Badge de Status Principal --}}
                                                    @if($statusGeral === 'rascunho')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold">
                                                            <i class="far fa-edit" style="font-size: 10px;"></i>
                                                            Rascunho
                                                        </span>
                                                    @elseif($statusGeral === 'aguardando_assinatura')
                                                        @php
                                                            $assinaturasRealizadas = $todasAssinaturas - $assinaturasPendentes;
                                                        @endphp
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold">
                                                            <i class="fas fa-file-signature" style="font-size: 10px;"></i>
                                                            {{ $assinaturasRealizadas }}/{{ $todasAssinaturas }} assinado
                                                        </span>
                                                    @elseif($statusGeral === 'finalizado')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold">
                                                            <i class="far fa-check-circle" style="font-size: 10px;"></i>
                                                            Finalizado
                                                        </span>
                                                    @elseif($statusGeral === 'resposta_pendente')
                                                        <button type="button"
                                                                @click.stop="abrirModalRespostas({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}')"
                                                                class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-yellow-100 text-yellow-700 rounded text-[10px] font-bold animate-pulse hover:bg-yellow-200 transition-colors cursor-pointer">
                                                            <i class="far fa-comment-dots" style="font-size: 10px;"></i>
                                                            Avaliar {{ $docDigital->respostas->where('status', 'pendente')->count() }}
                                                        </button>
                                                    @elseif($statusGeral === 'prazo_aberto')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold" title="Aguardando resposta do estabelecimento">
                                                            <i class="far fa-clock" style="font-size: 10px;"></i>
                                                            Ag. Resposta
                                                        </span>
                                                    @elseif($statusGeral === 'concluido')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold">
                                                            <i class="fas fa-clipboard-check" style="font-size: 10px;"></i>
                                                            Assinado
                                                        </span>
                                                    @endif
                                                    
                                                    {{-- Badge de OS vinculada --}}
                                                    @if($docDigital->os_id && $docDigital->ordemServico)
                                                        <a href="{{ route('admin.ordens-servico.show', $docDigital->ordemServico) }}"
                                                           class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-blue-50 text-blue-700 rounded text-[10px] font-bold hover:bg-blue-100 transition-colors"
                                                           title="Vinculado à OS #{{ $docDigital->ordemServico->numero }}"
                                                           @click.stop>
                                                            <i class="fas fa-clipboard-check" style="font-size: 10px;"></i>
                                                            OS #{{ $docDigital->ordemServico->numero }}
                                                        </a>
                                                    @endif

                                                    {{-- Badge de Sigiloso --}}
                                                    @if($docDigital->sigiloso)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-red-50 text-red-700 rounded text-[10px] font-bold" title="Documento sigiloso - não visível para o estabelecimento">
                                                            <i class="fas fa-lock" style="font-size: 9px;"></i>
                                                            Sigiloso
                                                        </span>
                                                    @endif

                                                    {{-- Badge de Físico --}}
                                                    @if($docDigital->tipo_origem === 'fisico')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-amber-50 text-amber-700 rounded text-[10px] font-bold" title="Documento físico (entregue em loco)">
                                                            <i class="fas fa-file-alt" style="font-size: 9px;"></i>
                                                            Físico
                                                        </span>
                                                    @endif

                                                    {{-- Indicador de visualização --}}
                                                    @if($docDigital->primeiraVisualizacao && $statusGeral !== 'rascunho' && $statusGeral !== 'aguardando_assinatura')
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded text-[10px] font-bold" title="Visto por {{ $docDigital->primeiraVisualizacao->usuarioExterno->nome ?? 'N/D' }}">
                                                            <i class="far fa-eye" style="font-size: 10px;"></i>
                                                            Visto
                                                        </span>
                                                    @endif

                                                    @if($docDigital->temPrazo() && !$docDigital->isPrazoFinalizado() && ($docDigital->prazo_iniciado_em || !$docDigital->prazo_notificacao || $docDigital->primeiraVisualizacao))
                                                        @php
                                                            $classesCorPrazo = [
                                                                'red' => 'bg-red-100 text-red-700',
                                                                'yellow' => 'bg-amber-100 text-amber-700',
                                                                'green' => 'bg-green-100 text-green-700',
                                                                'blue' => 'bg-blue-100 text-blue-700',
                                                                'gray' => 'bg-slate-100 text-slate-600',
                                                            ];
                                                            $classePrazoDocumento = $classesCorPrazo[$docDigital->cor_status_prazo] ?? $classesCorPrazo['gray'];
                                                        @endphp
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold {{ $classePrazoDocumento }}"
                                                              title="Prazo do documento{{ $docDigital->data_vencimento ? ' até ' . $docDigital->data_vencimento->format('d/m/Y') : '' }}">
                                                            <i class="far fa-clock" style="font-size: 10px;"></i>
                                                            {{ $docDigital->texto_status_prazo }}
                                                        </span>
                                                    @endif

                                                    {{-- Badge do Prazo de Análise Interna (quando há resposta pendente do regulado) --}}
                                                    @php
                                                        $respostaPendenteAnalise = $docDigital->respostas
                                                            ? $docDigital->respostas->where('status', 'pendente')->sortByDesc('created_at')->first()
                                                            : null;
                                                    @endphp
                                                    @if($respostaPendenteAnalise && $respostaPendenteAnalise->prazo_analise_data_limite)
                                                        @php
                                                            $classesCorAnalise = [
                                                                'red' => 'bg-red-100 text-red-700 border border-red-300',
                                                                'amber' => 'bg-amber-100 text-amber-700 border border-amber-300',
                                                                'blue' => 'bg-indigo-50 text-indigo-700 border border-indigo-200',
                                                                'green' => 'bg-green-50 text-green-700 border border-green-200',
                                                                'gray' => 'bg-slate-100 text-slate-600',
                                                            ];
                                                            $corAnalise = $respostaPendenteAnalise->cor_prazo_analise;
                                                            $classeAnalise = $classesCorAnalise[$corAnalise] ?? $classesCorAnalise['gray'];
                                                            $tituloAnalise = 'Prazo para análise da resposta do estabelecimento'
                                                                . ($respostaPendenteAnalise->prazo_analise_data_limite
                                                                    ? ' até ' . $respostaPendenteAnalise->prazo_analise_data_limite->format('d/m/Y')
                                                                    : '');
                                                        @endphp
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[10px] font-bold {{ $classeAnalise }}"
                                                              title="{{ $tituloAnalise }}">
                                                            <i class="fas fa-gavel" style="font-size: 9px;"></i>
                                                            {{ $respostaPendenteAnalise->texto_prazo_analise }}
                                                        </span>
                                                    @endif

                                                    @if(($docDigital->prazo_prorrogado_dias ?? 0) > 0)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-indigo-50 text-indigo-700 rounded text-[10px] font-bold"
                                                              title="Prorrogação acumulada nesta notificação">
                                                            <i class="fas fa-plus" style="font-size: 9px;"></i>
                                                            {{ $docDigital->prazo_prorrogado_dias }} dia(s)
                                                        </span>
                                                    @endif
                                                </div>

                                                @if(($docDigital->prazo_prorrogado_dias ?? 0) > 0 && ($docDigital->usuarioProrrogouPrazo || $docDigital->prazo_prorrogado_motivo))
                                                                     <p class="mt-1 text-[9px] sm:text-[10px] leading-tight text-slate-500 truncate"
                                                       title="{{ $docDigital->usuarioProrrogouPrazo?->nome ? 'Prorrogado por ' . $docDigital->usuarioProrrogouPrazo->nome . '. ' : '' }}{{ $docDigital->prazo_prorrogado_motivo }}">
                                                        @if($docDigital->usuarioProrrogouPrazo)
                                                            <span class="font-medium text-slate-600">{{ Str::words($docDigital->usuarioProrrogouPrazo->nome, 2, '') }}</span>
                                                        @endif
                                                        @if($docDigital->usuarioProrrogouPrazo && $docDigital->prazo_prorrogado_motivo)
                                                            <span> • </span>
                                                        @endif
                                                        @if($docDigital->prazo_prorrogado_motivo)
                                                            <span>{{ $docDigital->prazo_prorrogado_motivo }}</span>
                                                        @endif
                                                    </p>
                                                @endif

                                                @if($statusGeral === 'aguardando_assinatura')
                                                    <div class="mt-1 space-y-1">
                                                        <p class="text-[9px] sm:text-[10px] text-slate-600 leading-tight">
                                                            <span class="font-semibold text-green-700">Assinaram:</span>
                                                            @if($assinaturasRealizadasLista->count() > 0)
                                                                {{ $assinaturasRealizadasLista->map(fn($ass) => Str::upper($ass->usuarioInterno->nome ?? 'Usuário'))->implode(', ') }}
                                                            @else
                                                                <span class="text-slate-400">ninguém ainda</span>
                                                            @endif
                                                        </p>
                                                        <p class="text-[9px] sm:text-[10px] text-orange-700 leading-tight">
                                                            <span class="font-semibold">Faltam assinar:</span>
                                                            @if($assinaturasPendentesLista->count() > 0)
                                                                {{ $assinaturasPendentesLista->map(fn($ass) => Str::upper($ass->usuarioInterno->nome ?? 'Usuário'))->implode(', ') }}
                                                            @else
                                                                <span class="text-slate-400">nenhum</span>
                                                            @endif
                                                        </p>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                        
                                        {{-- DIREITA: Opções --}}
                                        <div class="flex flex-wrap items-center justify-start lg:justify-end gap-1 flex-shrink-0 w-full lg:w-auto">
                                            @php
                                                $visualizarPrimeiro = in_array($docDigital->status, ['rascunho', 'aguardando_assinatura'], true);
                                            @endphp

                                            @if($visualizarPrimeiro)
                                                {{-- Botão Visualizar Documento (disponível em qualquer status, inclusive rascunho) --}}
                                                <button type="button"
                                                        @click="pdfUrl = '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}'; modalVisualizador = true"
                                                        class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                        title="Visualizar documento">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                </button>
                                            @endif

                                            {{-- Botão Editar (se pode editar) --}}
                                            @if($docDigital->podeEditar())
                                                <a href="{{ route('admin.documentos.edit', $docDigital->id) }}" 
                                                   class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                   title="Editar documento">
                                                    <i class="far fa-edit fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </a>
                                            @endif

                                            @if(!$visualizarPrimeiro)
                                                {{-- Botão Visualizar Documento (disponível em qualquer status, inclusive rascunho) --}}
                                                <button type="button"
                                                        @click="pdfUrl = '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}'; modalVisualizador = true"
                                                        class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                        title="Visualizar documento">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                </button>
                                            @endif

                                            {{-- Botão Assinar --}}
                                            @if($usuarioPrecisaAssinar)
                                                <button type="button"
                                                   @click="abrirModalAssinar({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ $assinaturaUsuario->ordem }}', {{ json_encode($docDigital->assinaturas->map(fn($a) => ['nome' => $a->usuarioInterno->nome ?? 'Usuário', 'status' => $a->status, 'ordem' => $a->ordem, 'isCurrentUser' => $a->usuario_interno_id === auth('interno')->id()])->sortBy('ordem')->values()) }})"
                                                   class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                   title="Assinar documento">
                                                    <svg class="w-4 h-4 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 3H7a2 2 0 00-2 2v14a2 2 0 002 2h10a2 2 0 002-2V8l-5-5z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M14 3v5h5"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 16c1.2-1.6 2.4-2.4 3.7-2.4.8 0 1.2.4 1.8 1 .6.6 1 .9 1.7.9.6 0 1.1-.2 1.8-.7"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 19h8"/>
                                                    </svg>
                                                </button>
                                            @endif
                                            
                                            {{-- Botão Encerrar Prazo --}}
                                            @php
                                                $temRespostaAprovada = $docDigital->respostas && $docDigital->respostas->where('status', 'aprovado')->count() > 0;
                                                $podeEncerrarPrazoInline = $docDigital->temPrazo() && !$docDigital->isPrazoFinalizado() && ($temRespostaAprovada || $docDigital->vencido);
                                            @endphp
                                            @if($podeEncerrarPrazoInline)
                                                <form action="{{ route('admin.estabelecimentos.processos.documento-digital.finalizar-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" 
                                                            class="inline-flex items-center gap-1 px-2 py-1 text-[11px] font-semibold text-green-700 bg-green-50 hover:bg-green-100 border border-green-200 rounded-lg transition-colors"
                                                            onclick="return confirm('{{ $temRespostaAprovada ? 'Encerrar prazo deste documento?' : 'Este documento está vencido e não possui resposta aprovada. Deseja encerrar o prazo mesmo assim?' }}')">
                                                        <i class="far fa-check-circle" style="font-size: 12px;"></i>
                                                        Encerrar Prazo
                                                    </button>
                                                </form>
                                            @endif

                                            @if($podeProrrogarPrazo)
                                                <button type="button"
                                                        @click="abrirModalProrrogarPrazo({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ $docDigital->data_vencimento?->format('d/m/Y') }}', {{ $diasProrrogacaoDisponiveis }}, '{{ route('admin.estabelecimentos.processos.documento-digital.prorrogar-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}')"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-[11px] font-semibold text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg transition-colors"
                                                        title="Prorrogar prazo restante da notificação">
                                                    <i class="far fa-calendar-plus" style="font-size: 12px;"></i>
                                                    Prorrogar
                                                </button>
                                            @endif

                                            @if($podeDefinirPrazo)
                                                <button type="button"
                                                        @click="abrirModalDefinirPrazo({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ route('admin.estabelecimentos.processos.documento-digital.definir-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}')"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-[11px] font-semibold text-cyan-700 bg-cyan-50 hover:bg-cyan-100 border border-cyan-200 rounded-lg transition-colors"
                                                        title="Definir prazo para documento já assinado">
                                                    <i class="far fa-clock" style="font-size: 12px;"></i>
                                                    Definir Prazo
                                                </button>
                                            @endif
                                            
                                            {{-- Botão Download PDF --}}
                                            @if($docDigital->status !== 'rascunho' && $docDigital->arquivo_pdf)
                                                <a href="{{ route('admin.documentos.pdf', $docDigital->id) }}" 
                                                   class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                   title="Baixar PDF">
                                                    <i class="fas fa-download fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </a>
                                            @endif

                                            {{-- Botão Expandir Detalhes --}}
                                            <button @click.stop="expanded = !expanded" 
                                                    class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                    title="Ver detalhes">
                                                <i class="fas fa-chevron-down fa-fw text-slate-500 transition-transform" :class="{ 'rotate-180': expanded }" style="font-size: 13px;"></i>
                                            </button>
                                            
                                            {{-- Menu 3 Pontos --}}
                                            <div class="relative" x-data="{ menuAberto: false }">
                                                <button @click.stop="menuAberto = !menuAberto"
                                                        class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                        title="Mais opções">
                                                    <i class="fas fa-ellipsis-h fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </button>
                                                   <div x-show="menuAberto" @click.away="menuAberto = false" x-transition
                                                       class="absolute right-0 top-full mt-2 w-[min(12rem,calc(100vw-2rem))] sm:w-48 max-w-[calc(100vw-2rem)] bg-white rounded-lg shadow-xl border border-slate-200 z-[9999] py-1"
                                                     style="display: none;">
                                                    @if($docDigital->podeEditar())
                                                        <a href="{{ route('admin.documentos.edit', $docDigital->id) }}"
                                                            class="flex items-center gap-2.5 px-3 sm:px-4 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                                            <i class="far fa-edit fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                            Editar
                                                        </a>
                                                    @endif
                                                    @if($docDigital->status !== 'rascunho')
                                                        <button @click="moverParaPasta({{ $docDigital->id }}, 'documento', null, $el); menuAberto = false"
                                                                class="w-full flex items-center gap-2.5 px-3 sm:px-4 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                                            <i class="far fa-times-circle fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                            Remover da pasta
                                                        </button>
                                                        <template x-for="pasta in pastas" :key="pasta.id">
                                                            <button @click="moverParaPasta({{ $docDigital->id }}, 'documento', pasta.id, $el); menuAberto = false"
                                                                    class="w-full flex items-center gap-2.5 px-3 sm:px-4 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                                                <span class="w-2 h-2 rounded-full" :style="`background-color: ${pasta.cor}`"></span>
                                                                <span x-text="pasta.nome"></span>
                                                            </button>
                                                        </template>
                                                    @endif
                                                    @if($docDigital->temPrazo() || $docDigital->data_vencimento)
                                                        @if($docDigital->isPrazoFinalizado())
                                                            <form action="{{ route('admin.estabelecimentos.processos.documento-digital.reabrir-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}" method="POST">
                                                                @csrf
                                                                <button type="submit" onclick="return confirm('Reabrir prazo?')"
                                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                                                    <i class="fas fa-redo fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                                    Reabrir Prazo
                                                                </button>
                                                            </form>
                                                        @elseif(($docDigital->respostas && $docDigital->respostas->where('status', 'aprovado')->count() > 0) || $docDigital->vencido)
                                                            @php $temRespAprovadaDrop = $docDigital->respostas && $docDigital->respostas->where('status', 'aprovado')->count() > 0; @endphp
                                                            <form action="{{ route('admin.estabelecimentos.processos.documento-digital.finalizar-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}" method="POST">
                                                                @csrf
                                                                <button type="submit" onclick="return confirm('{{ $temRespAprovadaDrop ? 'Encerrar prazo deste documento?' : 'Este documento está vencido e não possui resposta aprovada. Deseja encerrar o prazo mesmo assim?' }}')"
                                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-green-600 hover:text-green-700 hover:bg-green-50 transition-colors">
                                                                    <i class="far fa-check-circle fa-fw" style="font-size: 13px;"></i>
                                                                    Encerrar Prazo
                                                                </button>
                                                            </form>
                                                            @if($podeProrrogarPrazo)
                                                                <button type="button"
                                                                        @click="abrirModalProrrogarPrazo({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ $docDigital->data_vencimento?->format('d/m/Y') }}', {{ $diasProrrogacaoDisponiveis }}, '{{ route('admin.estabelecimentos.processos.documento-digital.prorrogar-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}'); menuAberto = false"
                                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-indigo-600 hover:text-indigo-700 hover:bg-indigo-50 transition-colors">
                                                                    <i class="far fa-calendar-plus fa-fw" style="font-size: 13px;"></i>
                                                                    Prorrogar Prazo
                                                                </button>
                                                            @endif
                                                        @elseif($docDigital->primeiraVisualizacao)
                                                            <form action="{{ route('admin.estabelecimentos.processos.documento-digital.finalizar-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}" method="POST">
                                                                @csrf
                                                                <button type="submit" onclick="return confirm('Finalizar prazo?')"
                                                                        class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 transition-colors">
                                                                    <i class="far fa-check-circle fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                                    Finalizar Prazo
                                                                </button>
                                                            </form>
                                                        @else
                                                            <span class="flex items-center gap-2.5 px-4 py-2 text-sm text-slate-400 cursor-not-allowed" title="O documento precisa ser visualizado pelo estabelecimento antes de finalizar o prazo">
                                                                <i class="far fa-clock fa-fw" style="font-size: 13px;"></i>
                                                                Finalizar (aguardando)
                                                            </span>
                                                        @endif
                                                    @elseif($podeDefinirPrazo)
                                                        <button type="button"
                                                                @click="abrirModalDefinirPrazo({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ route('admin.estabelecimentos.processos.documento-digital.definir-prazo', [$estabelecimento->id, $processo->id, $docDigital->id]) }}'); menuAberto = false"
                                                                class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-cyan-600 hover:text-cyan-700 hover:bg-cyan-50 transition-colors">
                                                            <i class="far fa-clock fa-fw" style="font-size: 13px;"></i>
                                                            Definir Prazo
                                                        </button>
                                                    @endif
                                                    <button @click="excluirDocumentoDigital({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }} - {{ $docDigital->numero_documento }}'); menuAberto = false"
                                                            class="w-full flex items-center gap-2.5 px-4 py-2 text-sm text-red-500 hover:text-red-600 hover:bg-red-50 transition-colors">
                                                        <i class="far fa-trash-alt fa-fw" style="font-size: 13px;"></i>
                                                        Excluir
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    @if($docDigital->todasAssinaturasCompletas())
                                        @include('estabelecimentos.processos.partials.itens-atendimento', ['docDigital' => $docDigital])
                                    @endif

                                    {{-- Seção Expandível: Timeline do Documento --}}
                                    <div x-show="expanded" x-collapse class="border-t border-slate-100 bg-slate-50">
                                        <div class="p-3">
                                            {{-- Timeline Visual --}}
                                            <div class="relative">
                                                {{-- Linha vertical da timeline --}}
                                                <div class="absolute left-3 top-2 bottom-2 w-0.5 bg-slate-200"></div>
                                                
                                                <div class="space-y-3">
                                                    {{-- Evento: Criação do Documento --}}
                                                    <div class="flex items-start gap-3 relative">
                                                        <div class="w-6 h-6 rounded-full bg-blue-100 flex items-center justify-center z-10 flex-shrink-0">
                                                            <svg class="w-3 h-3 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-medium text-slate-900">Documento criado</p>
                                                            <p class="text-[10px] text-slate-500">{{ $docDigital->created_at->format('d/m/Y H:i') }} • {{ $docDigital->usuarioCriador->nome }}</p>
                                                        </div>
                                                    </div>
                                                    
                                                    {{-- Evento: Assinaturas --}}
                                                    @if($todasAssinaturas > 0)
                                                    <div class="flex items-start gap-3 relative">
                                                        <div class="w-6 h-6 rounded-full {{ $temAssinaturasPendentes ? 'bg-orange-100' : 'bg-green-100' }} flex items-center justify-center z-10 flex-shrink-0">
                                                            <svg class="w-3 h-3 {{ $temAssinaturasPendentes ? 'text-orange-600' : 'text-green-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-medium text-slate-900">
                                                                @if($temAssinaturasPendentes)
                                                                    Aguardando {{ $assinaturasPendentes }} de {{ $todasAssinaturas }} assinatura(s)
                                                                @else
                                                                    ✓ Todas {{ $todasAssinaturas }} assinatura(s) coletadas
                                                                @endif
                                                            </p>
                                                            <div class="mt-1.5 space-y-1">
                                                                @foreach($assinaturas as $ass)
                                                                <div class="flex items-center gap-2 text-[10px]">
                                                                    @if($ass->status === 'assinado')
                                                                        <svg class="w-3 h-3 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                                        <span class="text-slate-700">{{ $ass->usuarioInterno->nome ?? 'N/D' }}</span>
                                                                        <span class="text-slate-400">{{ $ass->assinado_em ? $ass->assinado_em->format('d/m/Y H:i') : '' }}</span>
                                                                    @else
                                                                        <svg class="w-3 h-3 text-orange-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                                                        <span class="text-orange-600">{{ $ass->usuarioInterno->nome ?? 'N/D' }}</span>
                                                                        <span class="text-orange-400">Pendente</span>
                                                                    @endif
                                                                </div>
                                                                @endforeach
                                                            </div>
                                                        </div>
                                                    </div>
                                                    @endif
                                                    
                                                    {{-- Evento: Visualização --}}
                                                    @if($docDigital->primeiraVisualizacao)
                                                    <div class="flex items-start gap-3 relative">
                                                        <div class="w-6 h-6 rounded-full bg-emerald-100 flex items-center justify-center z-10 flex-shrink-0">
                                                            <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-medium text-slate-900">Visualizado pelo estabelecimento</p>
                                                            <p class="text-[10px] text-slate-500">{{ $docDigital->primeiraVisualizacao->created_at->format('d/m/Y H:i') }} • {{ $docDigital->primeiraVisualizacao->usuarioExterno->nome ?? 'N/D' }}</p>
                                                        </div>
                                                    </div>
                                                    @endif
                                                    
                                                    {{-- Eventos: Respostas --}}
                                                    @if($docDigital->respostas && $docDigital->respostas->count() > 0)
                                                        @foreach($docDigital->respostas->sortBy('created_at') as $resposta)
                                                        <div class="flex items-start gap-3 relative resposta-item" x-data="{ showRejeitar: false }" data-status="{{ $resposta->status }}" data-resposta-id="{{ $resposta->id }}">
                                                            <div class="w-6 h-6 rounded-full flex items-center justify-center z-10 flex-shrink-0 resposta-status-icon
                                                                {{ $resposta->status === 'pendente' ? 'bg-yellow-100' : ($resposta->status === 'aprovado' ? 'bg-green-100' : 'bg-red-100') }}">
                                                                <svg class="w-3 h-3 resposta-status-svg
                                                                    {{ $resposta->status === 'pendente' ? 'text-yellow-600' : ($resposta->status === 'aprovado' ? 'text-green-600' : 'text-red-600') }}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path class="resposta-status-path" stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                                          d="{{ $resposta->status === 'pendente' ? 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' : ($resposta->status === 'aprovado' ? 'M5 13l4 4L19 7' : 'M6 18L18 6M6 6l12 12') }}" />
                                                                </svg>
                                                            </div>
                                                            <div class="flex-1 min-w-0 bg-white rounded-lg border resposta-status-border {{ $resposta->status === 'pendente' ? 'border-yellow-200' : ($resposta->status === 'aprovado' ? 'border-green-200' : 'border-red-200') }} p-2">
                                                                <div class="flex items-center justify-between gap-2">
                                                                    <div class="min-w-0 flex-1">
                                                                        <div class="flex items-center gap-1.5 flex-wrap">
                                                                            @if($resposta->itemAtendimento)
                                                                                <span class="text-[10px] px-1.5 py-0.5 rounded font-bold bg-amber-100 text-amber-800">
                                                                                    Item {{ $resposta->itemAtendimento->ordem }}
                                                                                </span>
                                                                            @endif
                                                                                <button type="button" 
                                                                                    @click="abrirModalRespostas({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}', {{ $resposta->id }})"
                                                                                    class="text-xs font-semibold text-blue-600 hover:text-blue-800 hover:underline truncate">
                                                                                📎 {{ $resposta->nome_original }}
                                                                            </button>
                                                                            <span class="text-[10px] px-1.5 py-0.5 rounded font-medium resposta-status-badge
                                                                                {{ $resposta->status === 'pendente' ? 'bg-yellow-100 text-yellow-700' : ($resposta->status === 'aprovado' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700') }}">
                                                                                {{ $resposta->status === 'pendente' ? 'Pendente' : ($resposta->status === 'aprovado' ? 'Aprovado' : 'Rejeitado') }}
                                                                            </span>
                                                                        </div>
                                                                        <p class="text-[10px] text-slate-500 mt-0.5">
                                                                            {{ $resposta->created_at->format('d/m/Y H:i') }} • {{ $resposta->usuarioExterno->nome ?? 'N/D' }}
                                                                            @if($resposta->status === 'aprovado' && $resposta->avaliadoPor)
                                                                                • <span class="text-green-600">✓ {{ $resposta->avaliadoPor->nome }}</span>
                                                                            @endif
                                                                        </p>
                                                                        @if($resposta->status === 'rejeitado' && $resposta->motivo_rejeicao)
                                                                        <p class="text-[10px] text-red-600 mt-0.5 leading-relaxed">
                                                                            <span class="font-semibold">Motivo:</span> {{ $resposta->motivo_rejeicao }}
                                                                        </p>
                                                                        @endif
                                                                    </div>
                                                                    
                                                                    {{-- Ações da resposta --}}
                                                                    <div class="flex items-center gap-0.5 flex-shrink-0">
                                                                          <button type="button"
                                                                              @click="abrirModalRespostas({{ $docDigital->id }}, '{{ addslashes($docDigital->nome ?? $docDigital->tipoDocumento->nome) }}', '{{ $docDigital->numero_documento }}', '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id]) }}', {{ $resposta->id }})"
                                                                               class="p-1 text-blue-600 hover:bg-blue-100 rounded transition-colors" title="Comparar com documento original">
                                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                                            </svg>
                                                                        </button>
                                                                        <a href="{{ route('admin.estabelecimentos.processos.documento-digital.resposta.download', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) }}"
                                                                           class="p-1 text-slate-600 hover:bg-slate-200 rounded transition-colors" title="Download">
                                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                                                            </svg>
                                                                        </a>
                                                                        <button type="button"
                                                                                class="p-1 text-slate-600 hover:bg-slate-200 rounded transition-colors btn-revalidar-resposta {{ $resposta->status === 'pendente' ? 'hidden' : '' }}" 
                                                                                title="Revalidar"
                                                                                data-revalidar-url="{{ route('admin.estabelecimentos.processos.documento-digital.resposta.revalidar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) }}">
                                                                            <i class="fas fa-redo fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                                        </button>
                                                                        <div class="resposta-actions {{ $resposta->status === 'pendente' ? '' : 'hidden' }}">
                                                                        <form action="{{ route('admin.estabelecimentos.processos.documento-digital.resposta.aprovar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) }}" method="POST" class="inline js-resposta-aprovar" data-resposta-id="{{ $resposta->id }}">
                                                                            @csrf
                                                                            <button type="submit" 
                                                                                    class="p-1 text-green-600 hover:bg-green-100 rounded transition-colors" 
                                                                                    title="Aprovar"
                                                                                    onclick="return confirm('Aprovar esta resposta?')">
                                                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                                                </svg>
                                                                            </button>
                                                                        </form>
                                                                        <button @click="showRejeitar = !showRejeitar" 
                                                                                class="p-1 text-red-600 hover:bg-red-100 rounded transition-colors" 
                                                                                title="Rejeitar">
                                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                                            </svg>
                                                                        </button>
                                                                        </div>
                                                                        <button type="button"
                                                                                @click="abrirModalExclusao('resposta', {{ $resposta->id }}, '{{ addslashes($resposta->nome_arquivo) }}', '{{ route('admin.estabelecimentos.processos.documento-digital.resposta.excluir', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) }}')"
                                                                                class="p-1 text-slate-400 hover:bg-red-100 hover:text-red-600 rounded transition-colors" 
                                                                                title="Excluir">
                                                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                                            </svg>
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                                
                                                                {{-- Formulário de rejeição inline --}}
                                                                <div x-show="showRejeitar" x-transition class="mt-2 pt-2 border-t border-slate-100 resposta-rejeicao-box">
                                                                    <form action="{{ route('admin.estabelecimentos.processos.documento-digital.resposta.rejeitar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) }}" method="POST" class="js-resposta-rejeitar" data-resposta-id="{{ $resposta->id }}">
                                                                        @csrf
                                                                        <textarea name="motivo_rejeicao" rows="2" class="w-full text-xs border border-slate-300 rounded px-2 py-1 focus:ring-1 focus:ring-red-500 focus:border-red-500" placeholder="Motivo da rejeição..." required></textarea>
                                                                        <div class="flex justify-end gap-1 mt-1">
                                                                            <button type="button" @click="showRejeitar = false" class="px-2 py-1 text-[10px] text-slate-600 hover:bg-slate-100 rounded">Cancelar</button>
                                                                            <button type="submit" class="px-2 py-1 text-[10px] bg-red-600 text-white rounded hover:bg-red-700">Rejeitar</button>
                                                                        </div>
                                                                    </form>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        @endforeach
                                                    @endif
                                                    
                                                    {{-- Evento: Prazo Finalizado --}}
                                                    @if($prazoFinalizado)
                                                    <div class="flex items-start gap-3 relative">
                                                        <div class="w-6 h-6 rounded-full bg-green-100 flex items-center justify-center z-10 flex-shrink-0">
                                                            <svg class="w-3 h-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <p class="text-xs font-medium text-green-700">✅ Documento finalizado</p>
                                                        </div>
                                                    </div>
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                @elseif($item['tipo'] === 'ordem_servico')
                                    @php
                                        $os = $item['documento'];
                                    @endphp
                                  <div x-data="{ pastaDocumento: {{ $os->pasta_id ?? 'null' }} }"
                                      x-show="(pastaAtiva === null || pastaAtiva === pastaDocumento) && statusFiltro === null"
                                      class="p-3 bg-white rounded-lg border border-slate-200 border-l-4 border-l-blue-500 hover:shadow-md transition-all"
                                     style="border-top-color: #e5e7eb; border-right-color: #e5e7eb; border-bottom-color: #e5e7eb;">
                                    
                                    {{-- Layout Flex: Título+Data | Opções --}}
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                        {{-- ESQUERDA: Ícone + Nome + Data --}}
                                        <a href="{{ route('admin.ordens-servico.show', $os) }}" class="flex items-center gap-2 min-w-0 flex-1">
                                            <div class="w-9 h-9 bg-slate-50 rounded-lg flex items-center justify-center flex-shrink-0">
                                                <i class="fas fa-clipboard-check fa-fw text-slate-500" style="font-size: 16px;"></i>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-2">
                                                    <span class="text-xs sm:text-sm font-semibold text-slate-900 hover:text-slate-600 truncate">OS #{{ $os->numero }}</span>
                                                    <span class="text-[10px] sm:text-[11px] text-slate-400 flex-shrink-0">{{ $os->created_at->format('d/m/Y') }}</span>
                                                </div>
                                                <div class="flex items-center gap-1.5 flex-wrap mt-0.5">
                                                    {!! $os->status_badge !!}
                                                    {!! $os->competencia_badge !!}
                                                    @if($os->municipio)
                                                    <span class="text-[11px] sm:text-xs text-slate-500">{{ $os->municipio->nome }}/{{ $os->municipio->uf }}</span>
                                                    @endif
                                                </div>
                                            </div>
                                        </a>
                                        
                                        {{-- DIREITA: Opções --}}
                                        <div class="flex flex-wrap items-center justify-start lg:justify-end gap-1 flex-shrink-0 w-full lg:w-auto">
                                            <a href="{{ route('admin.ordens-servico.show', $os) }}" 
                                               class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                               title="Ver OS">
                                                <i class="far fa-eye fa-fw text-slate-500" style="font-size: 15px;"></i>
                                            </a>

                                            <div class="relative" x-data="{ menuAberto: false }">
                                                <button @click.stop="menuAberto = !menuAberto"
                                                        class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors"
                                                        title="Mais opções">
                                                    <i class="fas fa-ellipsis-h fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </button>
                                                   <div x-show="menuAberto" @click.away="menuAberto = false" x-transition
                                                       class="absolute right-0 top-full mt-1 w-[min(13rem,calc(100vw-2rem))] sm:w-52 max-w-[calc(100vw-2rem)] bg-white rounded-lg shadow-xl border z-[9999] py-1"
                                                     style="display: none;">
                                                    <button @click="moverParaPasta({{ $os->id }}, 'ordem_servico', null, $el); menuAberto = false"
                                                            class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                        <i class="far fa-times-circle fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                        Remover da pasta
                                                    </button>
                                                    <template x-for="pasta in pastas" :key="pasta.id">
                                                        <button @click="moverParaPasta({{ $os->id }}, 'ordem_servico', pasta.id, $el); menuAberto = false"
                                                                class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                            <span class="w-2 h-2 rounded-full" :style="`background-color: ${pasta.cor}`"></span>
                                                            <span x-text="pasta.nome"></span>
                                                        </button>
                                                    </template>
                                                    <hr class="my-1">
                                                    <a href="{{ route('admin.ordens-servico.edit', $os) }}"
                                                       class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                        <i class="far fa-edit fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                        Editar OS
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                @elseif($item['tipo'] === 'arquivo')
                                    @php
                                        $documento = $item['documento'];
                                        $isCorrecao = $documento->isSubstituicao();
                                        $historicoRejeicoes = $isCorrecao ? $documento->getHistoricoRejeicoes() : collect();
                                    @endphp
                                             <div x-data="{ pastaDocumento: {{ $documento->pasta_id ?? 'null' }}, showHistorico: false, statusPendente: {{ $documento->status_aprovacao === 'pendente' ? 'true' : 'false' }} }"
                                                 x-show="(pastaAtiva === null || pastaAtiva === pastaDocumento) && (statusFiltro === null || (statusFiltro === 'pendente' && statusPendente))"
                                      class="p-3 bg-white rounded-lg border border-gray-200 border-l-4 documento-item {{ $documento->tipo_usuario === 'interno' ? 'border-l-blue-500' : ($documento->status_aprovacao === 'rejeitado' ? 'border-l-red-500' : ($documento->status_aprovacao === 'pendente' ? 'border-l-orange-500' : ($documento->status_aprovacao === 'aprovado' ? 'border-l-green-500' : 'border-l-gray-300'))) }} hover:shadow-md transition-all"
                                      data-doc-id="{{ $documento->id }}"
                                      data-status="{{ $documento->status_aprovacao ?? '' }}"
                                     style="border-top-color: #e5e7eb; border-right-color: #e5e7eb; border-bottom-color: #e5e7eb;">
                                    
                                    {{-- Layout Flex: Título+Data | Opções --}}
                                    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                                        {{-- ESQUERDA: Ícone + Nome + Data --}}
                                        <div @click="abrirVisualizadorAnotacoes({{ $documento->id }}, '{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $documento->id]) }}', {{ $documento->tipo_usuario === 'externo' && $documento->status_aprovacao === 'pendente' ? 'true' : 'false' }}, '{{ addslashes($documento->nome_original) }}', {{ !empty($documento->tipoDocumentoObrigatorio?->criterio_ia) ? 'true' : 'false' }})"
                                             class="flex items-start gap-2 cursor-pointer min-w-0 flex-1">
                                            {{-- Ícone por tipo de arquivo --}}
                                            <div class="w-9 h-9 rounded-lg bg-slate-50 flex items-center justify-center flex-shrink-0 mt-0.5">
                                                @php $ext = strtolower($documento->extensao ?? ''); @endphp
                                                @if(in_array($ext, ['pdf']))
                                                    <i class="far fa-file-pdf fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                @elseif(in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp']))
                                                    <i class="far fa-file-image fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                @elseif(in_array($ext, ['doc', 'docx']))
                                                    <i class="far fa-file-word fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                @elseif(in_array($ext, ['xls', 'xlsx', 'csv']))
                                                    <i class="far fa-file-excel fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                @else
                                                    <i class="fas fa-paperclip fa-fw text-slate-500" style="font-size: 16px;"></i>
                                                @endif
                                            </div>
                                            {{-- Nome, Status e Data --}}
                                            <div class="min-w-0 flex-1">
                                                <p class="text-xs sm:text-sm font-semibold text-slate-900 hover:text-blue-600 break-words leading-tight">{{ $documento->nome_original }}</p>
                                                <div class="flex items-center gap-1.5 flex-wrap mt-1">
                                                    <span class="text-[10px] sm:text-[11px] text-slate-400">{{ $documento->created_at->format('d/m/Y') }}</span>
                                                    <span class="text-[11px] sm:text-xs text-slate-500">{{ $documento->tamanho_formatado }}</span>
                                                    <span class="px-1.5 py-0.5 text-[10px] rounded {{ $documento->tipo_usuario === 'interno' ? 'bg-slate-200 text-slate-700 font-semibold' : 'bg-blue-100 text-blue-700 font-semibold' }}">
                                                        {{ $documento->tipo_usuario === 'interno' ? 'Int' : 'Ext' }}
                                                    </span>
                                                    @if($documento->os_id && $documento->ordemServico)
                                                        @php
                                                            $atividadeDocumentoOs = null;
                                                            if ($documento->atividade_index !== null) {
                                                                $atividadeDocumentoOs = $documento->ordemServico->atividades_tecnicos[$documento->atividade_index]['nome_atividade'] ?? null;
                                                            }
                                                        @endphp
                                                        <a href="{{ route('admin.ordens-servico.show', $documento->ordemServico) }}"
                                                           class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-blue-50 text-blue-700 text-[10px] rounded font-bold hover:bg-blue-100 transition-colors"
                                                           @click.stop>
                                                            <i class="fas fa-clipboard-check" style="font-size: 10px;"></i>
                                                            OS #{{ $documento->ordemServico->numero }}
                                                        </a>
                                                        @if($atividadeDocumentoOs)
                                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-indigo-50 text-indigo-700 text-[10px] rounded font-bold">
                                                                <i class="far fa-check-square" style="font-size: 10px;"></i>
                                                                {{ $atividadeDocumentoOs }}
                                                            </span>
                                                        @endif
                                                    @endif
                                                    @if($documento->tipo_usuario === 'externo' && $documento->status_aprovacao)
                                                        @if($documento->status_aprovacao === 'pendente')
                                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-[10px] rounded font-bold documento-status-badge">
                                                                <i class="far fa-clock" style="font-size: 10px;"></i>
                                                                Pendente
                                                            </span>
                                                            @if($isCorrecao)
                                                                <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-slate-100 text-slate-600 text-[10px] rounded font-bold">
                                                                    <i class="fas fa-redo" style="font-size: 10px;"></i>
                                                                    Correção #{{ $documento->tentativas_envio ?? 1 }}
                                                                </span>
                                                            @endif
                                                        @elseif($documento->status_aprovacao === 'aprovado')
                                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-[10px] rounded font-bold documento-status-badge" title="{{ $documento->aprovadoPor ? 'Aprovado por ' . $documento->aprovadoPor->nome . ($documento->aprovado_em ? ' em ' . \Carbon\Carbon::parse($documento->aprovado_em)->format('d/m/Y H:i') : '') : '' }}">
                                                                <i class="fas fa-check" style="font-size: 10px;"></i>
                                                                Aprovado{{ $documento->aprovadoPor ? ' - ' . Str::upper(Str::words($documento->aprovadoPor->nome, 1, '')) : '' }}
                                                            </span>
                                                        @elseif($documento->status_aprovacao === 'rejeitado')
                                                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-gray-100 text-gray-600 text-[10px] rounded font-bold documento-status-badge">
                                                                <i class="fas fa-times" style="font-size: 10px;"></i>
                                                                Rejeitado
                                                            </span>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        
                                        {{-- DIREITA: Opções --}}
                                        <div class="flex flex-wrap items-center justify-start lg:justify-end gap-1 flex-shrink-0 documento-actions w-full lg:w-auto">
                                            @if($documento->tipo_usuario === 'externo' && $documento->status_aprovacao)
                                            <form action="{{ route('admin.estabelecimentos.processos.documento.aprovar', [$estabelecimento->id, $processo->id, $documento->id]) }}" method="POST" class="inline js-doc-aprovar {{ $documento->status_aprovacao === 'pendente' ? '' : 'hidden' }}" data-doc-id="{{ $documento->id }}">
                                                @csrf
                                                <button type="submit" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors" title="Aprovar">
                                                    <i class="fas fa-check fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </button>
                                            </form>
                                            <button type="button" @click="documentoRejeitando = {{ $documento->id }}; modalRejeitar = true" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors btn-doc-rejeitar {{ $documento->status_aprovacao === 'pendente' ? '' : 'hidden' }}" title="Rejeitar" data-doc-id="{{ $documento->id }}">
                                                <i class="fas fa-times fa-fw text-slate-500" style="font-size: 15px;"></i>
                                            </button>
                                            <button type="button" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors btn-doc-revalidar {{ $documento->status_aprovacao === 'pendente' ? 'hidden' : '' }}" title="Revalidar"
                                                    data-doc-id="{{ $documento->id }}"
                                                    data-revalidar-url="{{ route('admin.estabelecimentos.processos.documento.revalidar', [$estabelecimento->id, $processo->id, $documento->id]) }}">
                                                <i class="fas fa-redo fa-fw text-slate-500" style="font-size: 15px;"></i>
                                            </button>
                                            @endif
                                            
                                            <a href="{{ route('admin.estabelecimentos.processos.download', [$estabelecimento->id, $processo->id, $documento->id]) }}" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors" title="Download">
                                                <i class="fas fa-download fa-fw text-slate-500" style="font-size: 15px;"></i>
                                            </a>
                                            
                                            {{-- Menu 3 pontos --}}
                                            <div class="relative" x-data="{ menuAberto: false }">
                                                <button @click.stop="menuAberto = !menuAberto" class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors" title="Mais opções">
                                                    <i class="fas fa-ellipsis-h fa-fw text-slate-500" style="font-size: 15px;"></i>
                                                </button>
                                                <div x-show="menuAberto" @click.away="menuAberto = false" x-transition class="absolute right-0 top-full mt-1 w-[min(12rem,calc(100vw-2rem))] sm:w-48 max-w-[calc(100vw-2rem)] bg-white rounded-lg shadow-xl border z-[9999] py-1" style="display: none;">
                                                    @if($documento->tipoDocumentoObrigatorio && $documento->tipoDocumentoObrigatorio->carimboManual() && $documento->status_aprovacao === 'aprovado' && strtolower((string) $documento->extensao) === 'pdf')
                                                    <form action="{{ route('admin.estabelecimentos.processos.documento.carimbar', [$estabelecimento->id, $processo->id, $documento->id]) }}" method="POST">
                                                        @csrf
                                                        <button type="submit" onclick="return confirm('Gerar versão carimbada com QR Code de validação para este documento?')"
                                                                class="w-full text-left px-3 py-2 text-xs sm:text-sm text-green-700 hover:text-green-800 hover:bg-green-50 flex items-center gap-2">
                                                            <i class="fas fa-stamp fa-fw text-green-500" style="font-size: 13px;"></i>
                                                            Carimbar validação
                                                        </button>
                                                    </form>
                                                    @endif
                                                    <button @click="moverParaPasta({{ $documento->id }}, 'arquivo', null, $el); menuAberto = false" class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                        <i class="far fa-times-circle fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                        Remover da pasta
                                                    </button>
                                                    <template x-for="pasta in pastas" :key="pasta.id">
                                                        <button @click="moverParaPasta({{ $documento->id }}, 'arquivo', pasta.id, $el); menuAberto = false" class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                            <span class="w-2 h-2 rounded-full" :style="`background-color: ${pasta.cor}`"></span>
                                                            <span x-text="pasta.nome"></span>
                                                        </button>
                                                    </template>
                                                    <hr class="my-1">
                                                    <button @click="documentoEditando = {{ $documento->id }}; nomeEditando = '{{ $documento->nome_original }}'; modalEditarNome = true; menuAberto = false" class="w-full text-left px-3 py-2 text-xs sm:text-sm text-slate-600 hover:text-slate-900 hover:bg-slate-50 flex items-center gap-2">
                                                        <i class="far fa-edit fa-fw text-slate-400" style="font-size: 13px;"></i>
                                                        Renomear
                                                    </button>
                                                    @if(($podeVincularDocObrigatorio ?? false) && isset($documentosObrigatorios) && $documentosObrigatorios->count() > 0)
                                                    <button @click="documentoVinculando = {{ $documento->id }}; modalVincularObrigatorio = true; menuAberto = false" class="w-full text-left px-3 py-2 text-xs sm:text-sm text-blue-600 hover:text-blue-800 hover:bg-blue-50 flex items-center gap-2">
                                                        <i class="fas fa-link fa-fw text-blue-400" style="font-size: 13px;"></i>
                                                        Vincular a Doc. Obrigatório
                                                    </button>
                                                    @endif
                                                    <button type="button" 
                                                            @click="abrirModalExclusao('documento', {{ $documento->id }}, '{{ addslashes($documento->nome_original) }}', '{{ route('admin.estabelecimentos.processos.deleteArquivo', [$estabelecimento->id, $processo->id, $documento->id]) }}'); menuAberto = false"
                                                            class="w-full text-left px-3 py-2 text-xs sm:text-sm text-red-500 hover:text-red-700 hover:bg-slate-50 flex items-center gap-2">
                                                        <i class="far fa-trash-alt fa-fw" style="font-size: 13px;"></i>
                                                        Excluir
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    {{-- Motivo da Rejeição e Histórico --}}
                                    @if($documento->status_aprovacao === 'rejeitado' && $documento->motivo_rejeicao)
                                    <div class="mt-2 p-2 bg-red-50 border border-red-200 rounded-lg ml-10 documento-motivo">
                                        <p class="text-xs text-red-700"><span class="font-semibold">Motivo:</span> {{ $documento->motivo_rejeicao }}</p>
                                    </div>
                                    @endif
                                    @if($isCorrecao && $historicoRejeicoes->count() > 0)
                                    <button @click.stop="showHistorico = !showHistorico" class="ml-10 mt-1 text-xs text-red-600 hover:text-red-700 font-semibold flex items-center gap-1">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                        </svg>
                                        Ver histórico ({{ $historicoRejeicoes->count() }})
                                        <svg class="w-3 h-3 transition-transform" :class="{ 'rotate-180': showHistorico }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    </button>
                                    @endif
                                    
                                    {{-- Histórico Expandido --}}
                                    @if($isCorrecao && $historicoRejeicoes->count() > 0)
                                    <div x-show="showHistorico" x-transition class="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg" style="display: none;">
                                        <p class="text-xs font-semibold text-red-700 mb-2">Histórico de Rejeições:</p>
                                        @foreach($historicoRejeicoes as $docRejeitado)
                                        <div class="p-2 bg-white border border-red-100 rounded text-xs mb-2 last:mb-0">
                                            <div class="flex items-center justify-between">
                                                <span class="font-semibold text-slate-700">{{ $docRejeitado->nome_original }}</span>
                                                <span class="text-slate-500">{{ $docRejeitado->created_at ? $docRejeitado->created_at->format('d/m/Y H:i') : '' }}</span>
                                            </div>
                                            @if($docRejeitado->motivo_rejeicao)
                                            <p class="text-red-600 mt-1"><strong>Motivo:</strong> {{ $docRejeitado->motivo_rejeicao }}</p>
                                            @endif
                                            @if(isset($docRejeitado->id))
                                            <a href="{{ route('admin.estabelecimentos.processos.visualizar', [$estabelecimento->id, $processo->id, $docRejeitado->id]) }}" target="_blank" class="text-blue-600 hover:underline mt-1 inline-block">Ver documento →</a>
                                            @endif
                                        </div>
                                        @endforeach
                                    </div>
                                    @endif
                                </div>
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Modal de Upload --}}
    <template x-teleport="body">
        <div x-show="modalUpload" 
             x-cloak
             @keydown.escape.window="modalUpload = false"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Overlay --}}
            <div @click="modalUpload = false"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5);"></div>
            
            {{-- Modal Content --}}
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100%; max-width: 500px; padding: 0 1rem;">
                <div class="bg-white rounded-xl shadow-2xl p-6" @click.stop>
                    {{-- Close Button --}}
                    <button @click="modalUpload = false"
                            class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- Header --}}
                    <div class="mb-6">
                        <h3 class="text-xl font-bold text-slate-900">Upload de Arquivo</h3>
                        <p class="text-sm text-slate-600 mt-1">Envie um arquivo PDF para este processo</p>
                    </div>

                    {{-- Form --}}
                    <form method="POST" action="{{ route('admin.estabelecimentos.processos.upload', [$estabelecimento->id, $processo->id]) }}" enctype="multipart/form-data">
                        @csrf
                        
                        {{-- Tipo de Documento --}}
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Tipo de Documento <span class="text-red-500">*</span>
                            </label>
                            <select name="tipo_documento" 
                                    required
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm bg-white">
                                <option value="" disabled selected>Selecione o tipo de documento</option>
                                <option value="Termo de Vistoria">Termo de Vistoria</option>
                                <option value="Auto de Infração">Auto de Infração</option>
                                <option value="Notificação">Notificação</option>
                                <option value="Usar nome do arquivo">Usar nome do arquivo PDF</option>
                            </select>
                        </div>

                        {{-- Upload de Arquivo --}}
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Arquivo PDF <span class="text-red-500">*</span>
                            </label>
                            <input type="file" 
                                   name="arquivo" 
                                   accept=".pdf"
                                   required
                                   id="inputArquivoUpload"
                                   onchange="validarTamanhoArquivo(this)"
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                            <p class="mt-1 text-xs text-slate-500">
                                Apenas arquivos PDF. Tamanho máximo: 10MB
                            </p>
                            <p id="erroTamanhoArquivo" class="mt-1 text-xs text-red-600 hidden"></p>
                        </div>

                        {{-- Info --}}
                        <div class="mb-6 bg-blue-50 border border-blue-200 rounded-lg p-3">
                            <div class="flex items-start gap-2">
                                <svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="text-xs text-blue-700">
                                    O documento será identificado pelo tipo selecionado acima.
                                </p>
                            </div>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click="modalUpload = false"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit"
                                    id="btnEnviarArquivo"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                                Enviar Arquivo
                            </button>
                        </div>
                        
                        <script>
                        function validarTamanhoArquivo(input) {
                            const maxSize = 10 * 1024 * 1024; // 10MB
                            const erroEl = document.getElementById('erroTamanhoArquivo');
                            const btnEnviar = document.getElementById('btnEnviarArquivo');
                            
                            if (input.files && input.files[0]) {
                                const file = input.files[0];
                                const sizeMB = (file.size / 1024 / 1024).toFixed(2);
                                
                                if (file.size > maxSize) {
                                    erroEl.textContent = `Arquivo muito grande (${sizeMB}MB). O tamanho máximo permitido é 10MB.`;
                                    erroEl.classList.remove('hidden');
                                    btnEnviar.disabled = true;
                                    input.value = '';
                                } else if (!file.name.toLowerCase().endsWith('.pdf')) {
                                    erroEl.textContent = 'Apenas arquivos PDF são permitidos.';
                                    erroEl.classList.remove('hidden');
                                    btnEnviar.disabled = true;
                                    input.value = '';
                                } else {
                                    erroEl.classList.add('hidden');
                                    btnEnviar.disabled = false;
                                }
                            }
                        }
                        </script>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Visualização de PDF --}}
    <template x-teleport="body">
        <div x-show="modalVisualizador" 
             x-cloak
             @keydown.escape.window="modalVisualizador = false"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Overlay --}}
            <div @click="modalVisualizador = false"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.75);"></div>
            
            {{-- Modal Content --}}
            <div style="position: absolute; top: 2%; left: 2%; right: 2%; bottom: 2%; max-width: 1200px; margin: 0 auto;">
                <div class="bg-white rounded-xl shadow-2xl h-full flex flex-col" @click.stop>
                    {{-- Header --}}
                    <div class="flex items-center justify-between p-4 border-b border-slate-200">
                        <h3 class="text-lg font-bold text-slate-900">Visualizar Documento</h3>
                        <button @click="modalVisualizador = false"
                                class="text-slate-400 hover:text-slate-600 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- PDF Viewer --}}
                    <div class="flex-1 overflow-hidden">
                        <iframe :src="pdfUrl" 
                                class="w-full h-full border-0"
                                style="min-height: 500px;">
                        </iframe>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <template x-if="modalProrrogarPrazo">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalProrrogarPrazo" style="display: none;">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-900/50" @click="modalProrrogarPrazo = false"></div>

                <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Prorrogar Prazo</p>
                                <h3 class="mt-1 text-lg font-semibold text-slate-900" x-text="prorrogarPrazoDocumentoNome"></h3>
                                <p class="mt-1 text-xs text-slate-500" x-text="prorrogarPrazoDocumentoNumero"></p>
                            </div>
                            <button type="button" @click="modalProrrogarPrazo = false" class="rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <form :action="prorrogarPrazoUrl" method="POST" class="px-6 py-5 space-y-4">
                        @csrf
                        <div class="rounded-xl border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
                            <p><span class="font-semibold">Prazo atual:</span> <span x-text="prorrogarPrazoDataAtual"></span></p>
                            <p class="mt-1"><span class="font-semibold">Limite restante nesta notificação:</span> <span x-text="prorrogarPrazoDiasDisponiveis"></span> dia(s)</p>
                        </div>

                        <div>
                            <label for="prorrogar_prazo_dias" class="block text-sm font-medium text-slate-700 mb-2">Dias para prorrogar</label>
                            <input type="number"
                                   id="prorrogar_prazo_dias"
                                   name="dias"
                                   x-model="prorrogarPrazoDias"
                                   min="1"
                                   :max="prorrogarPrazoDiasDisponiveis"
                                   required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                            <p class="mt-1 text-xs text-slate-500">A mesma notificação pode ser prorrogada no máximo em 30 dias no total.</p>
                        </div>

                        <div>
                            <label for="prorrogar_prazo_motivo" class="block text-sm font-medium text-slate-700 mb-2">Motivo da prorrogação</label>
                            <textarea id="prorrogar_prazo_motivo"
                                      name="motivo"
                                      rows="3"
                                      minlength="10"
                                      required
                                      class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                      placeholder="Informe o motivo da prorrogação com no mínimo 10 caracteres"></textarea>
                        </div>

                        <div>
                            <label for="prorrogar_prazo_senha" class="block text-sm font-medium text-slate-700 mb-2">Senha de assinatura digital</label>
                            <input type="password"
                                   id="prorrogar_prazo_senha"
                                   name="senha_assinatura"
                                   required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500/20"
                                   placeholder="Digite sua senha de assinatura">
                            <p class="mt-1 text-xs text-slate-500">Use a mesma senha configurada em <a href="{{ route('admin.assinatura.configurar-senha') }}" class="text-blue-600 hover:underline" target="_blank">Configurar Senha de Assinatura</a>.</p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button" @click="modalProrrogarPrazo = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                                <i class="far fa-calendar-plus" style="font-size: 12px;"></i>
                                Confirmar Prorrogação
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <template x-if="modalDefinirPrazo">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalDefinirPrazo" style="display: none;">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="fixed inset-0 bg-slate-900/50" @click="modalDefinirPrazo = false"></div>

                <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl">
                    <div class="border-b border-slate-200 px-6 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wide text-cyan-600">Definir Prazo</p>
                                <h3 class="mt-1 text-lg font-semibold text-slate-900" x-text="definirPrazoDocumentoNome"></h3>
                                <p class="mt-1 text-xs text-slate-500" x-text="definirPrazoDocumentoNumero"></p>
                            </div>
                            <button type="button" @click="modalDefinirPrazo = false" class="rounded-full p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <form :action="definirPrazoUrl" method="POST" class="px-6 py-5 space-y-4">
                        @csrf
                        <div class="rounded-xl border border-cyan-100 bg-cyan-50 px-4 py-3 text-sm text-cyan-800">
                            <p class="font-semibold">O documento já está assinado.</p>
                            <p class="mt-1">Ao salvar, o prazo começa a contar imediatamente no ambiente admin e no ambiente company.</p>
                        </div>

                        <div>
                            <label for="definir_prazo_dias" class="block text-sm font-medium text-slate-700 mb-2">Prazo em dias</label>
                            <input type="number"
                                   id="definir_prazo_dias"
                                   name="prazo_dias"
                                   x-model="definirPrazoDias"
                                   min="1"
                                   max="3650"
                                   required
                                   class="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm focus:border-cyan-500 focus:outline-none focus:ring-2 focus:ring-cyan-500/20"
                                   placeholder="Ex: 30">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Tipo de prazo</label>
                            <div class="space-y-2">
                                <label class="flex items-center cursor-pointer rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 transition-colors">
                                    <input type="radio" name="tipo_prazo" value="corridos" x-model="definirPrazoTipo" required class="h-4 w-4 text-cyan-600 focus:ring-cyan-500">
                                    <span class="ml-2"><strong>Dias corridos</strong> - conta todos os dias</span>
                                </label>
                                <label class="flex items-center cursor-pointer rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 transition-colors">
                                    <input type="radio" name="tipo_prazo" value="uteis" x-model="definirPrazoTipo" required class="h-4 w-4 text-cyan-600 focus:ring-cyan-500">
                                    <span class="ml-2"><strong>Dias úteis</strong> - exclui finais de semana</span>
                                </label>
                            </div>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button" @click="modalDefinirPrazo = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-cyan-600 rounded-lg hover:bg-cyan-700 transition-colors">
                                <i class="far fa-clock" style="font-size: 12px;"></i>
                                Salvar Prazo
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Visualização de Documento com Respostas - Split View --}}
    <template x-teleport="body">
        <div x-show="modalRespostas" 
             x-data="{ 
                respostas: [],
                respostaAtualIndex: 0,
                showRejeitar: false,
                viewMode: 'split',
                splitPercent: 50,
                isDragging: false,
                get respostaAtual() { return this.respostas[this.respostaAtualIndex] || null; },
                aplicarRespostaAtualSelecionada() {
                    if (!this.respostas.length) {
                        this.respostaAtualIndex = 0;
                        return;
                    }

                    const respostaId = this.respostasDocumentoRespostaId;
                    if (respostaId) {
                        const respostaIdx = this.respostas.findIndex(r => Number(r.id) === Number(respostaId));
                        if (respostaIdx >= 0) {
                            this.respostaAtualIndex = respostaIdx;
                            return;
                        }
                    }

                    const pendIdx = this.respostas.findIndex(r => r.status === 'pendente');
                    this.respostaAtualIndex = pendIdx >= 0 ? pendIdx : 0;
                },
                init() {
                    this.$watch('respostasDocumentoId', (id) => {
                        if (!id) return;
                        this.showRejeitar = false;
                        this.viewMode = 'split';
                        this.splitPercent = 50;
                        const allRespostas = JSON.parse(document.getElementById('respostas-data-' + id)?.textContent || '[]');
                        this.respostas = allRespostas;
                        this.aplicarRespostaAtualSelecionada();
                    });
                    this.$watch('respostasDocumentoRespostaId', () => {
                        this.aplicarRespostaAtualSelecionada();
                    });
                    const onMouseMove = (e) => {
                        if (!this.isDragging) return;
                        const container = this.$refs.splitContainer;
                        if (!container) return;
                        const rect = container.getBoundingClientRect();
                        let pct = ((e.clientX - rect.left) / rect.width) * 100;
                        pct = Math.max(20, Math.min(80, pct));
                        this.splitPercent = pct;
                    };
                    const onMouseUp = () => { this.isDragging = false; document.body.style.cursor = ''; };
                    document.addEventListener('mousemove', onMouseMove);
                    document.addEventListener('mouseup', onMouseUp);
                },
                startDrag(e) {
                    e.preventDefault();
                    this.isDragging = true;
                    document.body.style.cursor = 'col-resize';
                },
                navegar(dir) {
                    const novo = this.respostaAtualIndex + dir;
                    if (novo >= 0 && novo < this.respostas.length) {
                        this.respostaAtualIndex = novo;
                        this.showRejeitar = false;
                    }
                }
             }"
             x-cloak
             @keydown.escape.window="fecharModalRespostas()"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Overlay --}}
              <div @click="fecharModalRespostas()"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.85);"></div>
            
            {{-- Modal Content --}}
            <div style="position: absolute; top: 1%; left: 1%; right: 1%; bottom: 1%;">
                <div class="bg-white rounded-xl shadow-2xl h-full flex flex-col" @click.stop>
                    {{-- Header compacto --}}
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-slate-200 bg-gradient-to-r from-blue-50 to-amber-50">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-slate-900"><span x-text="respostasDocumentoNome"></span></h3>
                                <p class="text-[10px] text-slate-500"><span x-text="respostasDocumentoNumero"></span></p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            {{-- Botões de modo de visualização --}}
                            <div class="flex items-center bg-slate-100 rounded-lg p-0.5 gap-0.5">
                                <button @click="viewMode = 'notificacao'"
                                        :class="viewMode === 'notificacao' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200'"
                                        class="px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all flex items-center gap-1"
                                        title="Ver só a Notificação">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    Notificação
                                </button>
                                <button @click="viewMode = 'split'"
                                        :class="viewMode === 'split' ? 'bg-blue-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200'"
                                        class="px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all flex items-center gap-1"
                                        title="Comparar lado a lado">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7"/>
                                    </svg>
                                    Comparar
                                </button>
                                <button @click="viewMode = 'resposta'"
                                        :class="viewMode === 'resposta' ? 'bg-amber-600 text-white shadow-sm' : 'text-slate-500 hover:text-slate-700 hover:bg-slate-200'"
                                        class="px-2.5 py-1 text-[11px] font-semibold rounded-md transition-all flex items-center gap-1"
                                        title="Ver só a Resposta">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                    </svg>
                                    Resposta
                                </button>
                            </div>

                                <button @click="fecharModalRespostas()"
                                    
                                    class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Conteúdo --}}
                    <div class="flex-1 flex overflow-hidden" x-ref="splitContainer" :class="isDragging ? 'select-none' : ''">
                        {{-- Coluna Esquerda: Documento Original --}}
                        <div x-show="viewMode === 'split' || viewMode === 'notificacao'"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             :style="viewMode === 'notificacao' ? 'width: 100%' : 'width: ' + splitPercent + '%'"
                             class="flex flex-col flex-shrink-0">
                            <div class="px-3 py-1.5 bg-blue-50 border-b border-blue-200 flex items-center gap-2">
                                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                <span class="text-xs font-semibold text-blue-800">Documento Original (Notificação)</span>
                            </div>
                            <div class="flex-1 overflow-hidden bg-slate-100">
                                <iframe :src="respostasDocumentoPdfUrl" class="w-full h-full border-0" :style="isDragging ? 'pointer-events: none' : ''"></iframe>
                            </div>
                        </div>

                        {{-- Divisor arrastável --}}
                        <div x-show="viewMode === 'split'"
                             @mousedown="startDrag($event)"
                             class="w-1.5 flex-shrink-0 bg-slate-300 hover:bg-blue-400 active:bg-blue-500 cursor-col-resize relative group transition-colors border-x border-slate-200"
                             :class="isDragging ? 'bg-blue-500' : ''">
                            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 flex flex-col gap-0.5">
                                <span class="block w-1 h-1 rounded-full bg-slate-500 group-hover:bg-white transition-colors" :class="isDragging ? 'bg-white' : ''"></span>
                                <span class="block w-1 h-1 rounded-full bg-slate-500 group-hover:bg-white transition-colors" :class="isDragging ? 'bg-white' : ''"></span>
                                <span class="block w-1 h-1 rounded-full bg-slate-500 group-hover:bg-white transition-colors" :class="isDragging ? 'bg-white' : ''"></span>
                            </div>
                        </div>

                        {{-- Coluna Direita: Resposta da Empresa --}}
                        <div x-show="viewMode === 'split' || viewMode === 'resposta'"
                             x-transition:enter="transition ease-out duration-200"
                             x-transition:enter-start="opacity-0"
                             x-transition:enter-end="opacity-100"
                             :style="viewMode === 'resposta' ? 'width: 100%' : 'width: ' + (100 - splitPercent) + '%'"
                             class="flex flex-col flex-shrink-0">
                            {{-- Header da resposta com navegação --}}
                            <div class="px-3 py-1.5 bg-amber-50 border-b border-amber-200">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        <span class="text-xs font-semibold text-amber-800">Resposta da Empresa</span>
                                    </div>
                                    {{-- Navegação entre respostas --}}
                                    <div x-show="respostas.length > 1" class="flex items-center gap-1">
                                        <button @click="navegar(-1)" :disabled="respostaAtualIndex === 0"
                                                class="p-1 rounded hover:bg-amber-100 transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                                            <svg class="w-3.5 h-3.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                            </svg>
                                        </button>
                                        <span class="text-[10px] font-bold text-amber-700" x-text="(respostaAtualIndex + 1) + '/' + respostas.length"></span>
                                        <button @click="navegar(1)" :disabled="respostaAtualIndex === respostas.length - 1"
                                                class="p-1 rounded hover:bg-amber-100 transition-colors disabled:opacity-30 disabled:cursor-not-allowed">
                                            <svg class="w-3.5 h-3.5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                                {{-- Info da resposta atual --}}
                                <template x-if="respostaAtual">
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-[10px] text-slate-600 truncate" x-text="'📎 ' + respostaAtual.nome"></span>
                                        <span class="px-1.5 py-0.5 text-[9px] font-bold rounded-full flex-shrink-0"
                                              :class="{
                                                  'bg-yellow-100 text-yellow-700': respostaAtual.status === 'pendente',
                                                  'bg-green-100 text-green-700': respostaAtual.status === 'aprovado',
                                                  'bg-red-100 text-red-700': respostaAtual.status === 'rejeitado'
                                              }"
                                              x-text="respostaAtual.status === 'pendente' ? 'Pendente' : (respostaAtual.status === 'aprovado' ? 'Aprovado' : 'Rejeitado')">
                                        </span>
                                        <span class="text-[10px] text-slate-400 flex-shrink-0" x-text="respostaAtual.data + ' • ' + respostaAtual.usuario"></span>
                                    </div>
                                </template>
                            </div>

                            {{-- PDF da Resposta --}}
                            <div class="flex-1 overflow-hidden bg-slate-100 relative">
                                <template x-if="respostaAtual">
                                    <iframe :src="respostaAtual.url" class="w-full h-full border-0" :style="isDragging ? 'pointer-events: none' : ''"></iframe>
                                </template>
                                <template x-if="!respostaAtual">
                                    <div class="flex items-center justify-center h-full text-slate-400 text-sm">
                                        Nenhuma resposta encontrada
                                    </div>
                                </template>
                            </div>

                            {{-- Barra de Ações fixa no rodapé --}}
                            <template x-if="respostaAtual">
                                <div class="border-t border-slate-200 bg-white">
                                    {{-- Formulário de rejeição --}}
                                    <div x-show="showRejeitar" x-transition class="px-4 py-3 bg-red-50 border-b border-red-200">
                                        <form :action="respostaAtual.urlRejeitar" method="POST">
                                            @csrf
                                            <label class="block text-xs font-semibold text-red-800 mb-1.5">Motivo da Rejeição *</label>
                                            <textarea name="motivo_rejeicao" required rows="3" 
                                                      class="w-full px-3 py-2 text-sm border border-red-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500 resize-none"
                                                      placeholder="Descreva o motivo da rejeição..."></textarea>
                                            <div class="flex gap-2 mt-2">
                                                <button type="submit" class="flex-1 px-3 py-2 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors">
                                                    Confirmar Rejeição
                                                </button>
                                                <button type="button" @click="showRejeitar = false" class="px-3 py-2 bg-slate-200 text-slate-700 text-xs font-semibold rounded-lg hover:bg-slate-300 transition-colors">
                                                    Cancelar
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    {{-- Botões de ação --}}
                                    <div class="px-4 py-2.5 flex items-center justify-between gap-3">
                                        <div class="flex items-center gap-2">
                                            <a :href="respostaAtual.urlDownload"
                                               class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg border border-slate-200 transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                                                </svg>
                                                Download
                                            </a>
                                            <template x-if="respostaAtual.status === 'aprovado'">
                                                <span class="text-xs text-green-600 font-medium">
                                                    <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                    </svg>
                                                    Aprovado <span x-text="respostaAtual.avaliadoPor ? 'por ' + respostaAtual.avaliadoPor : ''"></span>
                                                </span>
                                            </template>
                                            <template x-if="respostaAtual.status === 'rejeitado'">
                                                <div class="w-full">
                                                    <div class="p-2.5 bg-red-50 border border-red-200 rounded-lg">
                                                        <div class="flex items-start gap-2">
                                                            <svg class="w-4 h-4 text-red-500 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                            <div class="min-w-0">
                                                                <p class="text-xs font-semibold text-red-700">Rejeitado</p>
                                                                <p class="text-xs text-red-600 mt-0.5 leading-relaxed" x-text="respostaAtual.motivoRejeicao || 'Sem motivo informado'"></p>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>

                                        <template x-if="respostaAtual.status === 'pendente'">
                                            <div class="flex items-center gap-2">
                                                <form :action="respostaAtual.urlAprovar" method="POST" class="inline">
                                                    @csrf
                                                    <button type="submit" 
                                                            class="inline-flex items-center gap-1.5 px-5 py-2 text-sm font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg shadow-sm transition-colors"
                                                            onclick="return confirm('Aprovar esta resposta?')">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                        </svg>
                                                        Aprovar
                                                    </button>
                                                </form>
                                                <button @click="showRejeitar = !showRejeitar" 
                                                        class="inline-flex items-center gap-1.5 px-5 py-2 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg shadow-sm transition-colors">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                    Rejeitar
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- JSON data das respostas por documento (hidden, lido pelo JS) --}}
    @foreach($documentosDigitais as $docDigital)
        @if($docDigital->respostas && $docDigital->respostas->count() > 0)
        @php
            $respostasData = $docDigital->respostas->sortBy('created_at')->values()->map(function($resposta) use ($estabelecimento, $processo, $docDigital) {
                return [
                    'id' => $resposta->id,
                    'nome' => ($resposta->itemAtendimento ? 'Item ' . $resposta->itemAtendimento->ordem . ' — ' : '') . $resposta->nome_original,
                    'status' => $resposta->status,
                    'data' => $resposta->created_at->format('d/m/Y H:i'),
                    'usuario' => $resposta->usuarioExterno->nome ?? 'N/D',
                    'avaliadoPor' => $resposta->avaliadoPor->nome ?? null,
                    'motivoRejeicao' => $resposta->motivo_rejeicao,
                    'url' => route('admin.estabelecimentos.processos.documento-digital.resposta.visualizar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]) . '?v=' . ($resposta->updated_at?->timestamp ?? $resposta->id),
                    'urlDownload' => route('admin.estabelecimentos.processos.documento-digital.resposta.download', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]),
                    'urlAprovar' => route('admin.estabelecimentos.processos.documento-digital.resposta.aprovar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]),
                    'urlRejeitar' => route('admin.estabelecimentos.processos.documento-digital.resposta.rejeitar', [$estabelecimento->id, $processo->id, $docDigital->id, $resposta->id]),
                ];
            });
        @endphp
        <script type="application/json" id="respostas-data-{{ $docDigital->id }}">
            {!! json_encode($respostasData) !!}
        </script>
        @endif
    @endforeach

    {{-- Modal de Visualização de PDF com Anotações --}}
    <template x-teleport="body">
        <div x-show="modalVisualizadorAnotacoes" 
             x-cloak
             @keydown.escape.window="fecharModalPDF()"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Modal Content - Tela Toda --}}
            <div class="bg-white h-full flex flex-col" @click.stop
                 x-data="{ mostrarAtividades: false, mostrarResponsaveis: false }">
                    {{-- Header Compacto --}}
                    <div class="flex items-center justify-between px-4 py-2 border-b border-slate-200 bg-slate-50 relative">
                        <div class="flex items-center gap-3 min-w-0 flex-1">
                            <svg class="w-5 h-5 text-purple-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold text-slate-900">Visualizar PDF</span>
                                    <template x-if="documentoNomeAnotacoes">
                                        <span class="text-xs text-purple-700 bg-purple-50 px-2 py-0.5 rounded font-medium truncate max-w-[300px]" x-text="documentoNomeAnotacoes"></span>
                                    </template>
                                </div>
                                <div class="flex items-center gap-2 text-[11px] text-slate-500 mt-0.5 flex-wrap">
                                    <span class="font-medium text-slate-700">{{ $estabelecimento->nome_fantasia ?? $estabelecimento->nome_razao_social }}</span>
                                    <span class="text-slate-300">|</span>
                                    <span>{{ $estabelecimento->tipo_pessoa === 'juridica' ? 'CNPJ' : 'CPF' }}: {{ $estabelecimento->documento_formatado }}</span>
                                    <span class="text-slate-300">|</span>
                                    <span class="truncate max-w-[400px]">{{ $estabelecimento->endereco }}, {{ $estabelecimento->numero }} - {{ $estabelecimento->bairro }}, {{ $estabelecimento->cidade }}/{{ $estabelecimento->estado }}</span>
                                    <span class="text-slate-300">|</span>
                                    {{-- Botão Ver Atividades --}}
                                    <button @click.stop="mostrarAtividades = !mostrarAtividades; mostrarResponsaveis = false" 
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium transition-colors"
                                            :class="mostrarAtividades ? 'bg-purple-100 text-purple-700' : 'bg-slate-100 text-slate-600 hover:bg-purple-50 hover:text-purple-600'">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                        </svg>
                                        Atividades ({{ count($estabelecimento->atividades_exercidas ?? []) }})
                                    </button>
                                    {{-- Botão Ver Responsáveis --}}
                                    <button @click.stop="mostrarResponsaveis = !mostrarResponsaveis; mostrarAtividades = false" 
                                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium transition-colors"
                                            :class="mostrarResponsaveis ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-600 hover:bg-blue-50 hover:text-blue-600'">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                        Responsáveis
                                    </button>
                                </div>
                            </div>
                        </div>

                        {{-- Dropdown Atividades --}}
                        <div x-show="mostrarAtividades" x-transition.origin.top.left
                             @click.outside="mostrarAtividades = false"
                             class="absolute top-full left-4 mt-1 w-[500px] max-h-[400px] overflow-y-auto bg-white rounded-xl shadow-2xl border border-slate-200 z-50">
                            <div class="sticky top-0 bg-purple-50 px-4 py-2 border-b border-purple-100 flex items-center justify-between">
                                <h4 class="text-xs font-bold text-purple-800 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                                    </svg>
                                    Atividades Econômicas Exercidas
                                </h4>
                                <span class="text-[10px] bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-semibold">{{ count($estabelecimento->atividades_exercidas ?? []) }}</span>
                            </div>
                            <div class="p-3 space-y-1.5">
                                @if($estabelecimento->atividades_exercidas && count($estabelecimento->atividades_exercidas) > 0)
                                    @foreach($estabelecimento->atividades_exercidas as $atividade)
                                    <div class="flex items-start gap-2 p-2 rounded-lg bg-slate-50 border border-slate-100">
                                        @if(isset($atividade['principal']) && $atividade['principal'])
                                        <span class="flex-shrink-0 px-1.5 py-0.5 text-[9px] font-bold bg-blue-500 text-white rounded">Principal</span>
                                        @else
                                        <span class="flex-shrink-0 px-1.5 py-0.5 text-[9px] font-bold bg-slate-300 text-slate-700 rounded">Sec.</span>
                                        @endif
                                        @if(!empty($atividade['manual']))
                                        <span class="flex-shrink-0 px-1.5 py-0.5 text-[9px] font-bold bg-green-100 text-green-700 rounded">Manual</span>
                                        @endif
                                        <div class="min-w-0 flex-1">
                                            <span class="text-[11px] font-bold text-slate-800">{{ $atividade['codigo'] ?? 'N/A' }}</span>
                                            <span class="text-[11px] text-slate-600 ml-1">{{ $atividade['descricao'] ?? 'Sem descrição' }}</span>
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                    <p class="text-xs text-slate-500 text-center py-4">Nenhuma atividade cadastrada</p>
                                @endif
                            </div>
                        </div>

                        {{-- Dropdown Responsáveis --}}
                        <div x-show="mostrarResponsaveis" x-transition.origin.top.left
                             @click.outside="mostrarResponsaveis = false"
                             class="absolute top-full left-4 mt-1 w-[500px] max-h-[400px] overflow-y-auto bg-white rounded-xl shadow-2xl border border-slate-200 z-50">
                            {{-- Responsáveis Legais --}}
                            <div class="sticky top-0 bg-blue-50 px-4 py-2 border-b border-blue-100">
                                <h4 class="text-xs font-bold text-blue-800 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                    </svg>
                                    Responsáveis Legais
                                </h4>
                            </div>
                            <div class="p-3 space-y-2">
                                @if($estabelecimento->responsaveisLegais && $estabelecimento->responsaveisLegais->count() > 0)
                                    @foreach($estabelecimento->responsaveisLegais as $resp)
                                    <div class="p-2.5 rounded-lg bg-blue-50/50 border border-blue-100">
                                        <p class="text-xs font-bold text-slate-900">{{ $resp->nome }}</p>
                                        <div class="flex items-center gap-3 mt-1 text-[11px] text-slate-600">
                                            <span>CPF: {{ $resp->cpf_formatado }}</span>
                                            @if($resp->email)
                                            <span>{{ $resp->email }}</span>
                                            @endif
                                            @if($resp->telefone)
                                            <span>{{ $resp->telefone_formatado }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                    <p class="text-xs text-slate-500 text-center py-2">Nenhum responsável legal cadastrado</p>
                                @endif
                            </div>

                            {{-- Responsáveis Técnicos --}}
                            <div class="sticky top-0 bg-green-50 px-4 py-2 border-b border-green-100 border-t">
                                <h4 class="text-xs font-bold text-green-800 flex items-center gap-1.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                    </svg>
                                    Responsáveis Técnicos
                                </h4>
                            </div>
                            <div class="p-3 space-y-2">
                                @if($estabelecimento->responsaveisTecnicos && $estabelecimento->responsaveisTecnicos->count() > 0)
                                    @foreach($estabelecimento->responsaveisTecnicos as $resp)
                                    <div class="p-2.5 rounded-lg bg-green-50/50 border border-green-100">
                                        <p class="text-xs font-bold text-slate-900">{{ $resp->nome }}</p>
                                        <div class="flex items-center gap-3 mt-1 text-[11px] text-slate-600 flex-wrap">
                                            <span>CPF: {{ $resp->cpf_formatado }}</span>
                                            @if($resp->conselho)
                                            <span class="font-medium text-green-700">{{ $resp->conselho }} {{ $resp->numero_registro_conselho }}</span>
                                            @endif
                                            @if($resp->email)
                                            <span>{{ $resp->email }}</span>
                                            @endif
                                            @if($resp->telefone)
                                            <span>{{ $resp->telefone_formatado }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    @endforeach
                                @else
                                    <p class="text-xs text-slate-500 text-center py-2">Nenhum responsável técnico cadastrado</p>
                                @endif
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            {{-- Botões de Navegação entre Documentos Pendentes --}}
                            <template x-if="documentoPendente && documentosPendentesLista.length > 1">
                                <div class="flex items-center gap-1 mr-2 border-r border-slate-300 pr-2">
                                    <button @click="navegarDocumentoPendente('anterior')" 
                                            :disabled="indiceDocumentoPendenteAtual === 0"
                                            :class="indiceDocumentoPendenteAtual === 0 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-100'"
                                            class="p-1.5 text-slate-600 rounded-lg transition-colors" 
                                            title="Documento Anterior">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                                        </svg>
                                    </button>
                                    <span class="text-xs text-slate-600 font-medium px-2" x-text="`${indiceDocumentoPendenteAtual + 1} de ${documentosPendentesLista.length}`"></span>
                                    <button @click="navegarDocumentoPendente('proximo')" 
                                            :disabled="indiceDocumentoPendenteAtual === documentosPendentesLista.length - 1"
                                            :class="indiceDocumentoPendenteAtual === documentosPendentesLista.length - 1 ? 'opacity-40 cursor-not-allowed' : 'hover:bg-slate-100'"
                                            class="p-1.5 text-slate-600 rounded-lg transition-colors" 
                                            title="Próximo Documento">
                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    </button>
                                </div>
                            </template>
                            
                            {{-- Botões Aprovar/Rejeitar/IA (só aparecem se documento é externo e pendente) --}}
                            <template x-if="documentoPendente">
                                <div class="flex items-center gap-2">
                                    {{-- Botão Analisar com IA (só aparece se tipo de documento tem critérios configurados) --}}
                                    <button type="button" x-show="iaTemCriterio"
                                            @click="analisarDocumentoComIA()"
                                            :disabled="iaAnalisando"
                                            class="px-3 py-1.5 bg-purple-600 text-white text-xs font-medium rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-1 disabled:opacity-60 disabled:cursor-not-allowed">
                                        <template x-if="!iaAnalisando">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.346.346a51.8 51.8 0 00-1.228 1.2 1 1 0 01-1.415 0 51.8 51.8 0 00-1.228-1.2l-.346-.346z"/>
                                            </svg>
                                        </template>
                                        <template x-if="iaAnalisando">
                                            <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                            </svg>
                                        </template>
                                        <span x-text="iaAnalisando ? 'Analisando...' : 'Analisar IA'"></span>
                                    </button>
                                    <button type="button"
                                            @click="aprovarDocumentoNoModal()"
                                            class="px-3 py-1.5 bg-green-600 text-white text-xs font-medium rounded-lg hover:bg-green-700 transition-colors flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                        Aprovar
                                    </button>
                                    <button type="button"
                                            @click="documentoRejeitando = documentoIdAnotacoes; modalRejeitar = true"
                                            class="px-3 py-1.5 bg-red-600 text-white text-xs font-medium rounded-lg hover:bg-red-700 transition-colors flex items-center gap-1">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Rejeitar
                                    </button>
                                </div>
                            </template>
                            
                            <button @click="fecharModalPDF()"
                                    class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Painel Resultado IA --}}
                    <template x-if="iaResultado">
                        <div class="flex-shrink-0 border-b border-slate-200"
                             :class="iaResultado.decisao === 'aprovado' ? 'bg-green-50' : (iaResultado.error ? 'bg-red-50' : 'bg-red-50')">
                            <div class="px-4 py-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="flex items-start gap-2 flex-1 min-w-0">
                                        {{-- Ícone da decisão --}}
                                        <div class="flex-shrink-0 mt-0.5">
                                            <template x-if="iaResultado.decisao === 'aprovado'">
                                                <div class="w-7 h-7 rounded-full bg-green-500 flex items-center justify-center">
                                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                </div>
                                            </template>
                                            <template x-if="iaResultado.decisao === 'rejeitado'">
                                                <div class="w-7 h-7 rounded-full bg-red-500 flex items-center justify-center">
                                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                </div>
                                            </template>
                                            <template x-if="iaResultado.error">
                                                <div class="w-7 h-7 rounded-full bg-orange-400 flex items-center justify-center">
                                                    <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                                                    </svg>
                                                </div>
                                            </template>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="text-xs font-bold uppercase tracking-wide"
                                                      :class="iaResultado.decisao === 'aprovado' ? 'text-green-700' : (iaResultado.error ? 'text-orange-700' : 'text-red-700')"
                                                      x-text="iaResultado.error ? 'Erro na análise' : ('IA sugere: ' + (iaResultado.decisao === 'aprovado' ? 'APROVAR' : 'REJEITAR'))"></span>
                                                <template x-if="iaResultado.usou_visao">
                                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 bg-purple-100 text-purple-700 text-[10px] rounded font-medium">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                        </svg>
                                                        Visão (PDF scaneado)
                                                    </span>
                                                </template>
                                            </div>
                                            <p class="text-xs mt-1 leading-relaxed"
                                               :class="iaResultado.decisao === 'aprovado' ? 'text-green-800' : (iaResultado.error ? 'text-orange-800' : 'text-red-800')"
                                               x-text="iaResultado.error || iaResultado.motivo"></p>
                                        </div>
                                    </div>
                                    {{-- Botões de ação rápida baseados na sugestão --}}
                                    <div class="flex items-center gap-1.5 flex-shrink-0">
                                        <template x-if="iaResultado.decisao === 'aprovado' && !iaResultado.error">
                                            <button type="button"
                                                    @click="aprovarDocumentoNoModal()"
                                                    class="px-2.5 py-1.5 bg-green-600 text-white text-xs font-semibold rounded-lg hover:bg-green-700 transition-colors flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                Confirmar Aprovação
                                            </button>
                                        </template>
                                        <template x-if="iaResultado.decisao === 'rejeitado' && !iaResultado.error">
                                            <button type="button"
                                                    @click="documentoRejeitando = documentoIdAnotacoes; motivoRejeicao = iaResultado.motivo; modalRejeitar = true"
                                                    class="px-2.5 py-1.5 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors flex items-center gap-1">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                                Confirmar Rejeição
                                            </button>
                                        </template>
                                        <button type="button"
                                                @click="iaResultado = null"
                                                class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-white rounded-lg transition-colors"
                                                title="Fechar">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- PDF Viewer (Visualização Rápida) --}}
                    <div class="flex-1 overflow-hidden">
                        <div class="h-full flex flex-col">
                            <div class="px-3 py-1.5 bg-gradient-to-r from-green-50 to-blue-50 border-b border-slate-200 flex items-center gap-2">
                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                                </svg>
                                <span class="text-xs font-medium text-green-800">Visualização Rápida</span>
                            </div>
                            <div class="flex-1 overflow-hidden bg-slate-100" x-show="pdfUrlAnotacoes">
                                <iframe :src="pdfUrlAnotacoes" class="w-full h-full border-0"></iframe>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Editar Nome --}}
    <template x-teleport="body">
        <div x-show="modalEditarNome" 
             x-cloak
             @keydown.escape.window="modalEditarNome = false"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Overlay --}}
            <div @click="modalEditarNome = false"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5);"></div>
            
            {{-- Modal Content --}}
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100%; max-width: 500px; padding: 0 1rem;">
                <div class="bg-white rounded-xl shadow-2xl p-6" @click.stop>
                    {{-- Close Button --}}
                    <button @click="modalEditarNome = false"
                            class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- Header --}}
                    <div class="mb-6">
                        <h3 class="text-xl font-bold text-slate-900">Editar Nome do Arquivo</h3>
                        <p class="text-sm text-slate-600 mt-1">Altere o nome de exibição do arquivo</p>
                    </div>

                    {{-- Form --}}
                    <form method="POST" :action="`{{ route('admin.estabelecimentos.processos.show', [$estabelecimento->id, $processo->id]) }}`.replace('/processos/{{ $processo->id }}', `/processos/{{ $processo->id }}/documentos/${documentoEditando}/nome`)">
                        @csrf
                        @method('PATCH')
                        
                        {{-- Nome do Arquivo --}}
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Nome do Arquivo <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   name="nome_original" 
                                   x-model="nomeEditando"
                                   required
                                   class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm"
                                   placeholder="Ex: Relatório Anual 2025.pdf">
                            <p class="mt-1 text-xs text-slate-500">
                                Este é o nome que aparecerá na lista de documentos
                            </p>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click="modalEditarNome = false"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                                Salvar Alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Vincular a Documento Obrigatório --}}
    @if(($podeVincularDocObrigatorio ?? false) && isset($documentosObrigatorios) && $documentosObrigatorios->count() > 0)
    <template x-teleport="body">
        <div x-show="modalVincularObrigatorio" 
             x-cloak
             @keydown.escape.window="modalVincularObrigatorio = false"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            <div @click="modalVincularObrigatorio = false"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5);"></div>
            
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100%; max-width: 500px; padding: 0 1rem;">
                <div class="bg-white rounded-xl shadow-2xl p-6" @click.stop>
                    <button @click="modalVincularObrigatorio = false"
                            class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    <div class="mb-4">
                        <h3 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                            <i class="fas fa-link text-blue-500"></i>
                            Vincular a Documento Obrigatório
                        </h3>
                        <p class="text-sm text-slate-600 mt-1">Selecione qual documento obrigatório este arquivo corresponde</p>
                    </div>

                    <form method="POST" 
                          :action="`{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/documentos') }}/${documentoVinculando}/vincular-obrigatorio`">
                        @csrf
                        
                        <div class="mb-4 space-y-2 max-h-72 overflow-y-auto">
                            @foreach($documentosObrigatorios as $docObrig)
                            <label class="flex items-start gap-3 p-3 rounded-lg border border-slate-200 hover:bg-blue-50 hover:border-blue-300 cursor-pointer transition-colors">
                                <input type="radio" name="tipo_documento_obrigatorio_id" value="{{ $docObrig['id'] }}" 
                                       class="mt-0.5 text-blue-600 focus:ring-blue-500" required>
                                <div class="flex-1 min-w-0">
                                    <span class="text-sm font-medium text-slate-900 block leading-tight">{{ $docObrig['nome'] }}</span>
                                    <div class="flex items-center gap-2 mt-1">
                                        @if($docObrig['obrigatorio'])
                                        <span class="text-[10px] text-red-500 font-medium">Obrigatório</span>
                                        @endif
                                        @if($docObrig['status'] === 'aprovado')
                                        <span class="text-[10px] px-1.5 py-0.5 bg-green-100 text-green-700 rounded font-medium">✓ Aprovado</span>
                                        @elseif($docObrig['status'] === 'pendente')
                                        <span class="text-[10px] px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded font-medium">Pendente</span>
                                        @elseif($docObrig['status'] === 'rejeitado')
                                        <span class="text-[10px] px-1.5 py-0.5 bg-red-100 text-red-700 rounded font-medium">Rejeitado</span>
                                        @else
                                        <span class="text-[10px] px-1.5 py-0.5 bg-slate-100 text-slate-600 rounded font-medium">Não enviado</span>
                                        @endif
                                    </div>
                                </div>
                            </label>
                            @endforeach
                        </div>

                        <div class="flex items-center gap-3 pt-3 border-t border-slate-200">
                            <button type="button"
                                    @click="modalVincularObrigatorio = false"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors flex items-center justify-center gap-2">
                                <i class="fas fa-link" style="font-size: 12px;"></i>
                                Vincular
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
    @endif

    {{-- Modal de Criar Documento Digital --}}
    <template x-teleport="body">
        <div x-show="modalDocumentoDigital" 
             x-cloak
             @keydown.escape.window="modalDocumentoDigital = false"
             style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 9999;">
            
            {{-- Overlay --}}
            <div @click="modalDocumentoDigital = false"
                 style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(0, 0, 0, 0.5);"></div>
            
            {{-- Modal Content --}}
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 100%; max-width: 600px; padding: 0 1rem;">
                <div class="bg-white rounded-xl shadow-2xl p-6" @click.stop>
                    {{-- Close Button --}}
                    <button @click="modalDocumentoDigital = false"
                            class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>

                    {{-- Header --}}
                    <div class="mb-6">
                        <h3 class="text-xl font-bold text-slate-900 flex items-center gap-2">
                            <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            Criar Documento Digital
                        </h3>
                        <p class="text-sm text-slate-600 mt-1">Selecione um modelo para gerar o documento</p>
                    </div>

                    {{-- Form --}}
                    <form method="POST" action="{{ route('admin.estabelecimentos.processos.gerarDocumento', [$estabelecimento->id, $processo->id]) }}">
                        @csrf
                        
                        {{-- Selecionar Modelo --}}
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Modelo de Documento <span class="text-red-500">*</span>
                            </label>
                            <select name="modelo_documento_id" 
                                    required
                                    class="w-full px-4 py-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-sm">
                                <option value="">Selecione um modelo</option>
                                @foreach($modelosDocumento as $modelo)
                                    <option value="{{ $modelo->id }}">
                                        {{ $modelo->tipoDocumento->nome }}
                                        @if($modelo->descricao)
                                            - {{ Str::limit($modelo->descricao, 40) }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-slate-500">
                                O documento será gerado em PDF e adicionado à lista de arquivos
                            </p>
                        </div>

                        {{-- Buttons --}}
                        <div class="flex items-center gap-3">
                            <button type="button"
                                    @click="modalDocumentoDigital = false"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit"
                                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                                Gerar Documento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Pastas do Processo --}}
    <template x-if="modalPastas">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalPastas" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalPastas = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-4xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    {{-- Header --}}
                    <div class="px-6 py-4 bg-gradient-to-r from-purple-600 to-purple-700">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                </svg>
                                Gerenciar Pastas do Processo
                            </h3>
                            <button @click="modalPastas = false" class="text-white hover:text-slate-200 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Body --}}
                    <div class="px-6 py-4">
                        {{-- Formulário de Nova Pasta --}}
                        <div class="mb-6 p-4 bg-slate-50 rounded-lg border border-slate-200">
                            <h4 class="text-sm font-semibold text-slate-900 mb-3">
                                <span x-show="!pastaEditando">Nova Pasta</span>
                                <span x-show="pastaEditando">Editar Pasta</span>
                            </h4>
                            <form @submit.prevent="salvarPasta()" class="space-y-3">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-700 mb-1">Nome da Pasta *</label>
                                        <input type="text" x-model="nomePasta" required
                                               class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                               placeholder="Ex: Documentos Técnicos">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-700 mb-1">Cor</label>
                                        <input type="color" x-model="corPasta"
                                               class="w-full h-10 px-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-slate-700 mb-1">Descrição</label>
                                    <textarea x-model="descricaoPasta" rows="2"
                                              class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                                              placeholder="Descrição opcional da pasta"></textarea>
                                </div>
                                <div class="flex gap-2">
                                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition-colors">
                                        <span x-show="!pastaEditando">Criar Pasta</span>
                                        <span x-show="pastaEditando">Salvar Alterações</span>
                                    </button>
                                    <button type="button" x-show="pastaEditando" @click="cancelarEdicao()"
                                            class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>

                        {{-- Lista de Pastas --}}
                        <div>
                            <h4 class="text-sm font-semibold text-slate-900 mb-3">Pastas Criadas</h4>
                            <div class="space-y-2 max-h-96 overflow-y-auto">
                                <template x-if="pastas.length === 0">
                                    <div class="text-center py-8 text-slate-500">
                                        <svg class="w-12 h-12 mx-auto mb-2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                        </svg>
                                        <p class="text-sm">Nenhuma pasta criada ainda</p>
                                    </div>
                                </template>
                                <template x-for="pasta in pastas" :key="pasta.id">
                                    <div class="flex items-center justify-between p-3 bg-white border border-slate-200 rounded-lg hover:shadow-sm transition-shadow">
                                        <div class="flex items-center gap-3 flex-1">
                                            <div class="w-10 h-10 rounded-lg flex items-center justify-center" :style="`background-color: ${pasta.cor}20`">
                                                <svg class="w-5 h-5" :style="`color: ${pasta.cor}`" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/>
                                                </svg>
                                            </div>
                                            <div class="flex-1">
                                                <h5 class="text-sm font-medium text-slate-900 flex items-center gap-2">
                                                    <span x-text="pasta.nome"></span>
                                                    <template x-if="pasta.unidade_id">
                                                        <span class="px-2 py-0.5 text-[10px] font-semibold bg-violet-100 text-violet-700 rounded-full">Unidade vinculada</span>
                                                    </template>
                                                </h5>
                                                <p class="text-xs text-slate-500" x-text="pasta.descricao || 'Sem descrição'"></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if($tipoProcessoTemUnidades ?? false)
                                            <button @click="vincularPastaUnidade(pasta)" class="p-2 text-violet-600 hover:bg-violet-50 rounded-lg transition-colors" title="Vincular a unidade">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                                                </svg>
                                            </button>
                                            @endif
                                            <button @click="editarPasta(pasta)" class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                                </svg>
                                            </button>
                                            <button @click="excluirPasta(pasta.id)" x-show="!pasta.protegida" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                        <button @click="modalPastas = false" class="w-full px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Parar Processo --}}
    <template x-if="modalParar">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalParar" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalParar = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-lg my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl"
                     x-data="{ escopoParada: 'principal' }">
                    <form action="{{ route('admin.estabelecimentos.processos.parar', [$estabelecimento->id, $processo->id]) }}" method="POST"
                          x-ref="formParar"
                          @submit="
                              if (escopoParada === 'unidades' && $refs.formParar.querySelectorAll('input[name=\'pastas_parar[]\']:checked').length > 0) {
                                  let radioUnidades = $refs.formParar.querySelector('input[name=escopo_parada][value=unidades]');
                                  if (radioUnidades) radioUnidades.value = 'pastas';
                              }
                          ">
                        @csrf
                        
                        {{-- Header --}}
                        <div class="px-6 py-4 bg-gradient-to-r from-red-600 to-red-700 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Parar Processo
                            </h3>
                            <button type="button" @click="modalParar = false" class="text-white hover:text-slate-200 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Conteúdo --}}
                        <div class="px-6 py-6">
                            <p class="text-sm text-slate-600 mb-4">
                                Você está prestes a parar o processo <strong>{{ $processo->numero_processo }}</strong>. 
                                Por favor, informe o motivo da parada.
                            </p>

                            {{-- Escopo da parada (só aparece se tem unidades) --}}
                            @if($processo->unidades->count() > 0)
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-slate-700 mb-2">O que deseja parar?</label>
                                <div class="space-y-2">
                                    <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition"
                                           :class="escopoParada === 'principal' ? 'border-red-400 bg-red-50' : 'border-slate-200 hover:bg-slate-50'">
                                        <input type="radio" name="escopo_parada" value="principal" x-model="escopoParada" class="text-red-600">
                                        <div>
                                            <span class="text-sm font-medium text-slate-900">Processo inteiro</span>
                                            <p class="text-xs text-slate-500">Para o processo e todas as unidades</p>
                                        </div>
                                    </label>
                                    <label class="flex items-center gap-3 p-3 border rounded-lg cursor-pointer transition"
                                           :class="escopoParada === 'unidades' ? 'border-violet-400 bg-violet-50' : 'border-slate-200 hover:bg-slate-50'">
                                        <input type="radio" name="escopo_parada" value="unidades" x-model="escopoParada" class="text-violet-600">
                                        <div>
                                            <span class="text-sm font-medium text-slate-900">Apenas pasta(s)/unidade(s) específica(s)</span>
                                            <p class="text-xs text-slate-500">O processo continua, só a(s) unidade(s) selecionada(s) para(m)</p>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            {{-- Seleção de unidades --}}
                            {{-- Seleção de unidades/pastas --}}
                            <div x-show="escopoParada === 'unidades'" x-cloak class="mb-4">
                                <label class="block text-sm font-medium text-slate-700 mb-2">Selecione as pastas/unidades para parar:</label>
                                <div class="space-y-1.5 max-h-40 overflow-y-auto border border-slate-200 rounded-lg p-3">
                                    @php
                                        $pastasDoProcesso = $processo->pastas()->orderBy('ordem')->get();
                                    @endphp
                                    @if($pastasDoProcesso->isNotEmpty())
                                        @foreach($pastasDoProcesso as $pastaItem)
                                            @if(($pastaItem->status ?? 'ativo') !== 'parado')
                                            <label class="flex items-center gap-2 p-2 hover:bg-slate-50 rounded cursor-pointer">
                                                <input type="checkbox" name="pastas_parar[]" value="{{ $pastaItem->id }}" class="text-violet-600 rounded">
                                                <span class="w-3 h-3 rounded-full" style="background-color: {{ $pastaItem->cor ?? '#3B82F6' }}"></span>
                                                <span class="text-sm text-slate-700">{{ $pastaItem->nome }}</span>
                                            </label>
                                            @else
                                            <div class="flex items-center gap-2 p-2 opacity-50">
                                                <input type="checkbox" disabled checked class="text-slate-400 rounded">
                                                <span class="w-3 h-3 rounded-full" style="background-color: {{ $pastaItem->cor ?? '#3B82F6' }}"></span>
                                                <span class="text-sm text-slate-500">{{ $pastaItem->nome }} <span class="text-xs text-red-500">(já parada)</span></span>
                                            </div>
                                            @endif
                                        @endforeach
                                        {{-- Hidden input para indicar que escopo é pastas --}}
                                        <input type="hidden" name="escopo_real" value="pastas" x-ref="escopoReal">
                                    @else
                                        @foreach($processo->unidades as $unidade)
                                        @if($unidade->pivot->status !== 'parado')
                                        <label class="flex items-center gap-2 p-2 hover:bg-slate-50 rounded cursor-pointer">
                                            <input type="checkbox" name="unidades_parar[]" value="{{ $unidade->id }}" class="text-violet-600 rounded">
                                            <span class="text-sm text-slate-700">{{ $unidade->nome }}</span>
                                        </label>
                                        @else
                                        <div class="flex items-center gap-2 p-2 opacity-50">
                                            <input type="checkbox" disabled checked class="text-slate-400 rounded">
                                            <span class="text-sm text-slate-500">{{ $unidade->nome }} <span class="text-xs text-red-500">(já parada)</span></span>
                                        </div>
                                        @endif
                                        @endforeach
                                    @endif
                                </div>
                            </div>
                            @endif

                            <div class="mb-4">
                                <label for="motivo_parada" class="block text-sm font-medium text-slate-700 mb-2">
                                    Motivo da Parada <span class="text-red-500">*</span>
                                </label>
                                <textarea 
                                    name="motivo_parada" 
                                    id="motivo_parada" 
                                    rows="4"
                                    required
                                    minlength="10"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-transparent resize-none"
                                    placeholder="Descreva o motivo da parada (mínimo 10 caracteres)..."></textarea>
                                
                                @error('motivo_parada')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                                <div class="flex">
                                    <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="ml-3">
                                        <p class="text-sm text-yellow-700">
                                            <strong>Atenção:</strong> Esta ação ficará registrada no histórico do processo.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Footer --}}
                        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3">
                            <button type="button" @click="modalParar = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                                Parar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Arquivar Processo --}}
    <template x-if="modalArquivar">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalArquivar" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalArquivar = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-lg my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    <form action="{{ route('admin.estabelecimentos.processos.arquivar', [$estabelecimento->id, $processo->id]) }}" method="POST">
                        @csrf
                        
                        {{-- Header --}}
                        <div class="px-6 py-4 bg-gradient-to-r from-orange-600 to-orange-700 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                </svg>
                                Arquivar Processo
                            </h3>
                            <button type="button" @click="modalArquivar = false" class="text-white hover:text-slate-200 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Conteúdo --}}
                        <div class="px-6 py-6">
                            <div class="mb-4">
                                <p class="text-sm text-slate-600 mb-4">
                                    Você está prestes a arquivar o processo <strong>{{ $processo->numero_processo }}</strong>. 
                                    Por favor, informe o motivo do arquivamento.
                                </p>
                                
                                <label for="motivo_arquivamento" class="block text-sm font-medium text-slate-700 mb-2">
                                    Motivo do Arquivamento <span class="text-red-500">*</span>
                                </label>
                                <textarea 
                                    name="motivo_arquivamento" 
                                    id="motivo_arquivamento" 
                                    rows="4"
                                    required
                                    minlength="10"
                                    class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent resize-none"
                                    placeholder="Descreva o motivo do arquivamento (mínimo 10 caracteres)..."></textarea>
                                
                                @error('motivo_arquivamento')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded">
                                <div class="flex">
                                    <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="ml-3">
                                        <p class="text-sm text-yellow-700">
                                            <strong>Atenção:</strong> O processo será marcado como arquivado e esta ação ficará registrada no histórico.
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Footer --}}
                        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3">
                            <button type="button" @click="modalArquivar = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-orange-600 rounded-lg hover:bg-orange-700 transition-colors">
                                Arquivar Processo
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Excluir Processo --}}
    <template x-if="modalExcluirProcesso">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalExcluirProcesso" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalExcluirProcesso = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-lg my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    <form action="{{ route('admin.estabelecimentos.processos.destroy', [$estabelecimento->id, $processo->id]) }}" method="POST">
                        @csrf
                        @method('DELETE')
                        
                        {{-- Header --}}
                        <div class="px-6 py-4 bg-gradient-to-r from-red-600 to-red-700 flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Excluir Processo
                            </h3>
                            <button type="button" @click="modalExcluirProcesso = false" class="text-white hover:text-slate-200 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>

                        {{-- Conteúdo --}}
                        <div class="px-6 py-6">
                            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded mb-4">
                                <div class="flex">
                                    <svg class="h-5 w-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                    </svg>
                                    <div class="ml-3">
                                        <p class="text-sm font-bold text-red-800">ATENÇÃO: Esta ação é irreversível!</p>
                                    </div>
                                </div>
                            </div>

                            <p class="text-sm text-slate-600 mb-4">
                                Você está prestes a excluir permanentemente o processo <strong class="text-red-600">{{ $processo->numero_processo }}</strong>.
                            </p>
                            
                            <div class="bg-slate-50 rounded-lg p-4 mb-4">
                                <p class="text-sm font-medium text-slate-700 mb-2">Serão excluídos:</p>
                                <ul class="text-sm text-slate-600 space-y-1">
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        O processo e todos os seus dados
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Todos os arquivos/documentos vinculados
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Arquivos físicos do storage
                                    </li>
                                    <li class="flex items-center gap-2">
                                        <svg class="w-4 h-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                        </svg>
                                        Histórico e eventos do processo
                                    </li>
                                </ul>
                            </div>

                            <p class="text-sm text-slate-500 italic">
                                Esta ação não pode ser desfeita. Certifique-se de que realmente deseja excluir este processo.
                            </p>
                        </div>

                        {{-- Footer --}}
                        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3">
                            <button type="button" @click="modalExcluirProcesso = false" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                                Excluir Permanentemente
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Assinatura de Documento --}}
    <template x-if="modalAssinar">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalAssinar" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4">
                {{-- Overlay --}}
                <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" @click="modalAssinar = false"></div>

                {{-- Modal --}}
                <div class="relative w-full max-w-sm transform transition-all bg-white rounded-2xl shadow-2xl overflow-hidden">
                    
                    {{-- Header com ícone central --}}
                    <div class="relative px-6 pt-6 pb-4">
                        <button @click="modalAssinar = false" class="absolute top-4 right-4 w-8 h-8 flex items-center justify-center rounded-full text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                            <i class="fas fa-times" style="font-size: 14px;"></i>
                        </button>
                        
                        <div class="flex flex-col items-center text-center">
                            <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center mb-3 shadow-lg shadow-blue-500/25">
                                <i class="fas fa-file-signature text-white" style="font-size: 22px;"></i>
                            </div>
                            <h3 class="text-lg font-bold text-slate-900">Assinar Documento</h3>
                            <p class="text-xs text-slate-500 mt-0.5 max-w-[260px] truncate" x-text="assinarDocumentoNome"></p>
                        </div>
                    </div>

                    {{-- Info cards --}}
                    <div class="px-6 pb-3">
                        <div class="flex gap-2">
                            <div class="flex-1 bg-slate-50 rounded-xl px-3 py-2.5 text-center">
                                <p class="text-[10px] text-slate-400 uppercase tracking-wider font-medium">Documento</p>
                                <p class="text-sm text-slate-800 font-semibold mt-0.5" x-text="assinarDocumentoNumero"></p>
                            </div>
                            <div class="flex-1 bg-blue-50 rounded-xl px-3 py-2.5 text-center">
                                <p class="text-[10px] text-blue-400 uppercase tracking-wider font-medium">Sua posição</p>
                                <p class="text-sm text-blue-700 font-semibold mt-0.5"><span x-text="assinarOrdem"></span>º assinante</p>
                            </div>
                        </div>
                    </div>

                    {{-- Lista de assinantes --}}
                    <div class="px-6 pb-3">
                        <p class="text-[11px] text-slate-400 uppercase tracking-wider font-medium mb-2">Assinantes</p>
                        <div class="space-y-1.5">
                            <template x-for="ass in assinarAssinaturas" :key="ass.ordem">
                                <div class="flex items-center gap-2.5 px-3 py-2 rounded-lg"
                                     :class="ass.status === 'assinado' ? 'bg-green-50' : (ass.isCurrentUser ? 'bg-blue-50 ring-1 ring-blue-200' : 'bg-slate-50')">
                                    {{-- Ícone de status --}}
                                    <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0"
                                         :class="ass.status === 'assinado' ? 'bg-green-500' : (ass.isCurrentUser ? 'bg-blue-500' : 'bg-slate-300')">
                                        <template x-if="ass.status === 'assinado'">
                                            <i class="fas fa-check text-white" style="font-size: 10px;"></i>
                                        </template>
                                        <template x-if="ass.status !== 'assinado' && ass.isCurrentUser">
                                            <i class="fas fa-pen text-white" style="font-size: 9px;"></i>
                                        </template>
                                        <template x-if="ass.status !== 'assinado' && !ass.isCurrentUser">
                                            <i class="fas fa-clock text-white" style="font-size: 9px;"></i>
                                        </template>
                                    </div>
                                    {{-- Nome e status --}}
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium truncate"
                                           :class="ass.status === 'assinado' ? 'text-green-800' : (ass.isCurrentUser ? 'text-blue-800' : 'text-slate-600')"
                                           x-text="ass.nome"></p>
                                    </div>
                                    {{-- Badge de status --}}
                                    <template x-if="ass.status === 'assinado'">
                                        <span class="text-[10px] font-medium text-green-600">Assinado</span>
                                    </template>
                                    <template x-if="ass.status !== 'assinado' && ass.isCurrentUser">
                                        <span class="flex items-center gap-1 text-[10px] font-medium text-blue-600">
                                            <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                                            Você
                                        </span>
                                    </template>
                                    <template x-if="ass.status !== 'assinado' && !ass.isCurrentUser">
                                        <span class="text-[10px] font-medium text-slate-400">Pendente</span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    {{-- Separador --}}
                    <div class="mx-6 border-t border-slate-100"></div>

                    {{-- Formulário --}}
                    <div class="px-6 py-4">
                        <label class="block text-[11px] text-slate-400 uppercase tracking-wider font-medium mb-2">Senha de Assinatura</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-lock text-slate-300" style="font-size: 13px;"></i>
                            </div>
                            <input type="password" 
                                   x-model="assinarSenha"
                                   @keydown.enter.prevent="processarAssinatura()"
                                   class="w-full pl-10 pr-4 py-2.5 text-sm border rounded-xl bg-slate-50 focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                                   :class="assinarErro ? 'border-red-300 bg-red-50 focus:ring-red-500 focus:border-red-500' : 'border-slate-200'"
                                   placeholder="Digite sua senha de assinatura"
                                   autofocus>
                        </div>
                        <div x-show="assinarErro" x-transition class="mt-2 flex items-center gap-1.5 text-xs text-red-500">
                            <i class="fas fa-exclamation-circle" style="font-size: 12px;"></i>
                            <span x-text="assinarErro"></span>
                        </div>

                        {{-- Botões --}}
                        <div class="flex flex-col gap-2 mt-4">
                            <button type="button" 
                                    @click="processarAssinatura()"
                                    :disabled="assinarCarregando || !assinarSenha"
                                    class="w-full px-4 py-2.5 text-sm font-semibold text-white rounded-xl transition-all flex items-center justify-center gap-2 shadow-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none"
                                    :class="assinarCarregando ? 'bg-blue-400' : 'bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 shadow-blue-500/25 hover:shadow-blue-500/40'">
                                <template x-if="assinarCarregando">
                                    <svg class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                </template>
                                <template x-if="!assinarCarregando">
                                    <i class="fas fa-file-signature" style="font-size: 13px;"></i>
                                </template>
                                <span x-text="assinarCarregando ? 'Processando assinatura...' : 'Assinar Documento'"></span>
                            </button>
                            <button type="button" 
                                    @click="modalAssinar = false"
                                    :disabled="assinarCarregando"
                                    class="w-full px-4 py-2 text-sm font-medium text-slate-500 hover:text-slate-700 hover:bg-slate-50 rounded-xl transition-colors disabled:opacity-50">
                                Cancelar
                            </button>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-3 bg-slate-50/80 border-t border-slate-100">
                        <p class="text-[10px] text-slate-400 flex items-center justify-center gap-1.5">
                            <i class="fas fa-shield-alt text-green-400" style="font-size: 11px;"></i>
                            Assinatura digital protegida por criptografia
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Exclusão com Senha de Assinatura --}}
    <template x-if="modalExcluirComSenha">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalExcluirComSenha" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalExcluirComSenha = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-md my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    {{-- Header --}}
                    <div class="px-6 py-4 bg-gradient-to-r from-red-600 to-red-700 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                            </svg>
                            Confirmar Exclusão
                        </h3>
                        <button type="button" @click="modalExcluirComSenha = false" class="text-white hover:text-slate-200 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Conteúdo --}}
                    <div class="px-6 py-6">
                        <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded mb-4">
                            <div class="flex">
                                <svg class="h-5 w-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                <div class="ml-3">
                                    <p class="text-sm font-bold text-red-800">Atenção!</p>
                                    <p class="text-sm text-red-700 mt-1">Esta ação será registrada no histórico do processo.</p>
                                </div>
                            </div>
                        </div>

                        <p class="text-sm text-slate-600 mb-4">
                            Você está prestes a excluir: <strong class="text-red-600" x-text="exclusaoNome"></strong>
                        </p>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Senha de Assinatura Digital <span class="text-red-500">*</span>
                            </label>
                            <input type="password" 
                                   x-model="senhaExclusao"
                                   @keyup.enter="executarExclusao()"
                                   class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                   :class="{ 'border-red-500': exclusaoErro }"
                                   placeholder="Digite sua senha de assinatura"
                                   autofocus>
                            <p class="mt-1 text-xs text-slate-500">
                                Use a mesma senha configurada em <a href="{{ route('admin.assinatura.configurar-senha') }}" class="text-blue-600 hover:underline" target="_blank">Configurar Senha de Assinatura</a>
                            </p>
                            <p x-show="exclusaoErro" x-text="exclusaoErro" class="mt-1 text-sm text-red-600"></p>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3">
                        <button type="button" 
                                @click="modalExcluirComSenha = false" 
                                class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
                                :disabled="exclusaoCarregando">
                            Cancelar
                        </button>
                        <button type="button" 
                                @click="executarExclusao()"
                                class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors flex items-center justify-center gap-2"
                                :disabled="exclusaoCarregando || !senhaExclusao">
                            <svg x-show="exclusaoCarregando" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span x-text="exclusaoCarregando ? 'Excluindo...' : 'Confirmar Exclusão'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Rejeitar Documento --}}
    <template x-teleport="body">
    <template x-if="modalRejeitar">
        <div class="fixed inset-0 overflow-y-auto" x-show="modalRejeitar" @fechar-modal-rejeitar.window="modalRejeitar = false; motivoRejeicao = ''" style="display: none; z-index: 10050;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalRejeitar = false"></div>
                <div class="inline-block w-full max-w-md my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    <div class="px-6 py-4 bg-gradient-to-r from-red-600 to-red-700 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Rejeitar Documento
                        </h3>
                        <button type="button" @click="modalRejeitar = false" class="text-white hover:text-slate-200 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <form id="formRejeitarDocumento" class="js-doc-rejeitar-form" :action="`{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/documentos') }}/${documentoRejeitando}/rejeitar`" method="POST">
                        @csrf
                        <div class="px-6 py-4">
                            <p class="text-sm text-slate-600 mb-4">Informe o motivo da rejeição do documento. O usuário externo será notificado.</p>
                            
                            {{-- Dropdown de textos predefinidos --}}
                            <div class="mb-3">
                                <label class="block text-sm font-medium text-slate-700 mb-2">Texto Predefinido</label>
                                <select @change="if($event.target.value !== 'personalizado') { motivoRejeicao = $event.target.value }"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500">
                                    <option value="personalizado">Personalizado (digite abaixo)</option>
                                    <option value="Conforme o art. 4º, inciso II, alínea &quot;d&quot;, da Portaria nº 1153/2025/SES/GASEC, a taxa de licença sanitária é cumulativa para todas as atividades sujeitas ao controle sanitário constantes no CNPJ, independentemente de serem exercidas ou não.

Verificou-se pagamento de DARE referente a apenas uma atividade. É obrigatória a emissão e o pagamento de DARE para todas as atividades de interesse à saúde constantes no CNPJ.

Todos os boletos do DARE deverão ser enviados em um único arquivo.">Boleto DARE</option>
                                    <option value="Conforme o art. 4º, inciso II, alínea &quot;d&quot;, da Portaria nº 1153/2025/SES/GASEC, a taxa de licença sanitária é cumulativa para todas as atividades sujeitas ao controle sanitário constantes no CNPJ, independentemente de serem exercidas ou não.

Verificou-se comprovante de pagamento referente a apenas uma atividade. É obrigatório o pagamento do DARE para todas as atividades de interesse à saúde constantes no CNPJ.

Os comprovantes de pagamento dos DAREs devem ser juntados em um único arquivo.">Comprovante de Pagamento DARE</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-2">Motivo da Rejeição *</label>
                                <textarea name="motivo_rejeicao" x-model="motivoRejeicao" rows="4" required
                                          class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                          placeholder="Ex: Documento ilegível, formato incorreto, informações incompletas..."></textarea>
                            </div>
                        </div>
                        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex gap-3">
                            <button type="button" @click="modalRejeitar = false; motivoRejeicao = ''" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Cancelar
                            </button>
                            <button type="submit" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors">
                                Rejeitar Documento
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
    </template>

    {{-- Modal "Tempo por etapa" (linha do tempo do processo) --}}
    @include('estabelecimentos.processos.partials.linha-tempo')

    {{-- Modal de Histórico do Processo --}}
    <template x-if="modalHistorico">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalHistorico" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalHistorico = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-3xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    {{-- Header --}}
                    <div class="px-6 py-4 bg-gradient-to-r from-green-600 to-green-700 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Histórico do Processo
                        </h3>
                        <button @click="modalHistorico = false" class="text-white hover:text-slate-200 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Conteúdo --}}
                    <div class="px-6 py-6 max-h-[70vh] overflow-y-auto">
                        {{-- Buscar eventos do histórico --}}
                        @php
                            try {
                                $eventos = $processo->eventos()->with('usuario')->get();
                            } catch (\Exception $e) {
                                // Tabela ainda não existe - migration não foi executada
                                $eventos = collect();
                            }
                        @endphp

                        {{-- Linha do Tempo --}}
                        <div class="relative">
                            @forelse($eventos as $evento)
                            <div class="flex gap-4 pb-8 {{ $loop->last ? '' : 'border-l-2 border-slate-200' }} ml-4">
                                {{-- Ícone do Evento --}}
                                <div class="absolute left-0 flex items-center justify-center w-8 h-8 rounded-full border-2 border-white
                                    @if($evento->cor === 'blue') bg-blue-100
                                    @elseif($evento->cor === 'purple') bg-purple-100
                                    @elseif($evento->cor === 'green') bg-green-100
                                    @elseif($evento->cor === 'red') bg-red-100
                                    @elseif($evento->cor === 'yellow') bg-yellow-100
                                    @elseif($evento->cor === 'cyan') bg-cyan-100
                                    @elseif($evento->cor === 'indigo') bg-indigo-100
                                    @else bg-slate-100
                                    @endif">
                                    @if($evento->icone === 'plus')
                                    <svg class="w-4 h-4 @if($evento->cor === 'blue') text-blue-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                    </svg>
                                    @elseif($evento->icone === 'upload')
                                    <svg class="w-4 h-4 @if($evento->cor === 'purple') text-purple-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                    </svg>
                                    @elseif($evento->icone === 'document')
                                    <svg class="w-4 h-4 @if($evento->cor === 'green') text-green-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                    </svg>
                                    @elseif($evento->icone === 'trash')
                                    <svg class="w-4 h-4 @if($evento->cor === 'red') text-red-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    @elseif($evento->icone === 'refresh')
                                    <svg class="w-4 h-4 @if($evento->cor === 'yellow') text-yellow-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                    @elseif($evento->icone === 'archive')
                                    <svg class="w-4 h-4 @if($evento->cor === 'orange') text-orange-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                                    </svg>
                                    @elseif($evento->icone === 'check')
                                    <svg class="w-4 h-4 @if($evento->cor === 'green') text-green-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    @elseif($evento->icone === 'pause')
                                    <svg class="w-4 h-4 @if($evento->cor === 'red') text-red-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    @elseif($evento->icone === 'play')
                                    <svg class="w-4 h-4 @if($evento->cor === 'green') text-green-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                    @elseif($evento->icone === 'x')
                                    <svg class="w-4 h-4 @if($evento->cor === 'red') text-red-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                    @elseif($evento->icone === 'arrow-right')
                                    <svg class="w-4 h-4 @if($evento->cor === 'cyan') text-cyan-600 @elseif($evento->cor === 'indigo') text-indigo-600 @endif" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                    </svg>
                                    @endif
                                </div>

                                {{-- Conteúdo do Evento --}}
                                <div class="flex-1 ml-12">
                                    <div class="bg-slate-50 rounded-lg p-3 border border-slate-200">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex-1 min-w-0">
                                                <h4 class="text-sm font-semibold text-slate-900">{{ $evento->titulo }}</h4>
                                                <p class="text-xs text-slate-600 mt-0.5">{{ $evento->descricao }}</p>
                                                
                                                {{-- Detalhes adicionais baseados no tipo de evento --}}
                                                @if($evento->dados_adicionais)
                                                    @if(in_array($evento->tipo_evento, ['documento_excluido', 'documento_digital_excluido']) && isset($evento->dados_adicionais['nome_arquivo']))
                                                    <p class="text-xs text-red-600 mt-1 flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                        Arquivo: {{ $evento->dados_adicionais['nome_arquivo'] }}
                                                    </p>
                                                    @endif
                                                    
                                                    @if($evento->tipo_evento === 'resposta_aprovada')
                                                    <div class="mt-1.5 p-2 bg-green-50 rounded border border-green-200">
                                                        <p class="text-xs text-green-700 flex items-center gap-1">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                            Arquivo aprovado: <strong>{{ $evento->dados_adicionais['nome_arquivo'] ?? 'N/D' }}</strong>
                                                        </p>
                                                        @if(isset($evento->dados_adicionais['usuario_externo']))
                                                        <p class="text-[10px] text-green-600 mt-0.5">Enviado por: {{ $evento->dados_adicionais['usuario_externo'] }}</p>
                                                        @endif
                                                    </div>
                                                    @endif
                                                    
                                                    @if($evento->tipo_evento === 'resposta_rejeitada')
                                                    <div class="mt-1.5 p-2 bg-red-50 rounded border border-red-200">
                                                        <p class="text-xs text-red-700 flex items-center gap-1">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                            </svg>
                                                            Arquivo rejeitado: <strong>{{ $evento->dados_adicionais['nome_arquivo'] ?? 'N/D' }}</strong>
                                                        </p>
                                                        @if(isset($evento->dados_adicionais['motivo_rejeicao']))
                                                        <p class="text-[10px] text-red-600 mt-0.5">Motivo: {{ $evento->dados_adicionais['motivo_rejeicao'] }}</p>
                                                        @endif
                                                    </div>
                                                    @endif
                                                    
                                                    @if($evento->tipo_evento === 'processo_atribuido')
                                                    <div class="mt-1.5 p-2 bg-cyan-50 rounded border border-cyan-200">
                                                        <div class="flex items-center gap-2 text-xs text-cyan-700">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                                            </svg>
                                                            <span>
                                                                @if(isset($evento->dados_adicionais['setor_anterior_nome']) || isset($evento->dados_adicionais['responsavel_anterior']))
                                                                    <strong>De:</strong> 
                                                                    {{ $evento->dados_adicionais['setor_anterior_nome'] ?? 'Sem setor' }}
                                                                    {{ isset($evento->dados_adicionais['responsavel_anterior']) ? ' - ' . $evento->dados_adicionais['responsavel_anterior'] : '' }}
                                                                    →
                                                                @endif
                                                                <strong>Para:</strong> 
                                                                {{ $evento->dados_adicionais['setor_novo_nome'] ?? 'Sem setor' }}
                                                                {{ isset($evento->dados_adicionais['responsavel_novo']) ? ' - ' . $evento->dados_adicionais['responsavel_novo'] : '' }}
                                                            </span>
                                                        </div>
                                                        @if(isset($evento->dados_adicionais['prazo']) && $evento->dados_adicionais['prazo'])
                                                        <div class="mt-1 flex items-center gap-1 text-[10px] text-cyan-600">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                            </svg>
                                                            <strong>Prazo:</strong> {{ \Carbon\Carbon::parse($evento->dados_adicionais['prazo'])->format('d/m/Y') }}
                                                        </div>
                                                        @endif
                                                        @if(isset($evento->dados_adicionais['motivo']) && $evento->dados_adicionais['motivo'])
                                                        <div class="mt-1.5 pt-1.5 border-t border-cyan-200">
                                                            <p class="text-[10px] text-cyan-600"><strong>Motivo:</strong> {{ $evento->dados_adicionais['motivo'] }}</p>
                                                        </div>
                                                        @endif
                                                        @if(isset($evento->dados_adicionais['ciente_em']) && $evento->dados_adicionais['ciente_em'])
                                                        <div class="mt-1.5 pt-1.5 border-t border-cyan-200 flex items-center gap-1.5">
                                                            <svg class="w-3 h-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                                            </svg>
                                                            <span class="text-[10px] text-green-600">
                                                                <strong>Ciente:</strong> {{ $evento->dados_adicionais['ciente_por_nome'] ?? 'Responsável' }} em {{ \Carbon\Carbon::parse($evento->dados_adicionais['ciente_em'])->format('d/m/Y H:i') }}
                                                            </span>
                                                        </div>
                                                        @endif
                                                    </div>
                                                    @endif

                                                    @if($evento->tipo_evento === 'prazo_prorrogado')
                                                    <div class="mt-1.5 p-2 bg-indigo-50 rounded border border-indigo-200">
                                                        <div class="flex items-center gap-2 text-xs text-indigo-700">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                            </svg>
                                                            <span>
                                                                <strong>Documento:</strong>
                                                                {{ $evento->dados_adicionais['numero_documento'] ?? ($evento->dados_adicionais['nome_documento'] ?? 'Documento') }}
                                                            </span>
                                                        </div>
                                                        <div class="mt-1 flex flex-wrap items-center gap-3 text-[10px] text-indigo-600">
                                                            @if(isset($evento->dados_adicionais['prorrogado_por_nome']))
                                                            <span><strong>Por:</strong> {{ $evento->dados_adicionais['prorrogado_por_nome'] }}</span>
                                                            @endif
                                                            @if(isset($evento->dados_adicionais['prazo_anterior']))
                                                            <span><strong>Prazo anterior:</strong> {{ \Carbon\Carbon::parse($evento->dados_adicionais['prazo_anterior'])->format('d/m/Y') }}</span>
                                                            @endif
                                                            @if(isset($evento->dados_adicionais['prazo']))
                                                            <span><strong>Novo prazo:</strong> {{ \Carbon\Carbon::parse($evento->dados_adicionais['prazo'])->format('d/m/Y') }}</span>
                                                            @endif
                                                            @if(isset($evento->dados_adicionais['dias_prorrogados']))
                                                            <span><strong>Prorrogado:</strong> {{ $evento->dados_adicionais['dias_prorrogados'] }} dia(s)</span>
                                                            @endif
                                                            @if(isset($evento->dados_adicionais['dias_prorrogados_total']))
                                                            <span><strong>Total acumulado:</strong> {{ $evento->dados_adicionais['dias_prorrogados_total'] }} dia(s)</span>
                                                            @endif
                                                        </div>
                                                        @if(isset($evento->dados_adicionais['motivo']) && $evento->dados_adicionais['motivo'])
                                                        <p class="mt-1 text-[10px] text-indigo-700"><strong>Motivo:</strong> {{ $evento->dados_adicionais['motivo'] }}</p>
                                                        @endif
                                                    </div>
                                                    @endif
                                                    
                                                    @if(in_array($evento->tipo_evento, ['documento_anexado', 'documento_digital_criado']) && isset($evento->dados_adicionais['nome_arquivo']))
                                                    <p class="text-xs text-slate-500 mt-1 flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                                        </svg>
                                                        {{ $evento->dados_adicionais['nome_arquivo'] }}
                                                    </p>
                                                    @endif
                                                @endif
                                                
                                                <div class="flex items-center gap-3 mt-2 text-xs text-slate-500">
                                                    <span class="flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                                        </svg>
                                                        {{ $evento->usuario->nome ?? 'Sistema' }}
                                                    </span>
                                                    <span class="flex items-center gap-1">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                                        </svg>
                                                        {{ $evento->created_at->format('d/m/Y H:i') }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            @empty
                            <div class="text-center py-8">
                                <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                <p class="mt-2 text-sm text-slate-500">Nenhum evento registrado</p>
                            </div>
                            @endforelse
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                        <button @click="modalHistorico = false" class="w-full px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Modal de Histórico de Atribuições --}}
    <template x-if="modalHistoricoAtribuicoes">
        <div class="fixed inset-0 z-50 overflow-y-auto" x-show="modalHistoricoAtribuicoes" style="display: none;">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                {{-- Overlay --}}
                <div class="fixed inset-0 transition-opacity bg-slate-500 bg-opacity-75" @click="modalHistoricoAtribuicoes = false"></div>

                {{-- Modal --}}
                <div class="inline-block w-full max-w-2xl my-8 overflow-hidden text-left align-middle transition-all transform bg-white rounded-lg shadow-xl">
                    {{-- Header --}}
                    <div class="px-6 py-4 bg-gradient-to-r from-cyan-600 to-cyan-700 flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            Histórico de Atribuições
                        </h3>
                        <button @click="modalHistoricoAtribuicoes = false" class="text-white hover:text-slate-200 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    {{-- Conteúdo --}}
                    <div class="px-6 py-6 max-h-[70vh] overflow-y-auto">
                        @php
                            try {
                                $eventosAtribuicao = $processo->eventos()
                                    ->whereIn('tipo_evento', ['processo_atribuido', 'processo_arquivado', 'processo_desarquivado'])
                                    ->with('usuario')
                                    ->orderBy('created_at', 'desc')
                                    ->get();
                            } catch (\Exception $e) {
                                $eventosAtribuicao = collect();
                            }
                        @endphp

                        @forelse($eventosAtribuicao as $evento)
                        @php
                            $dadosEvento = $evento->dados_adicionais ?? [];
                            $isAtribuicao = $evento->tipo_evento === 'processo_atribuido';
                            $isArquivamento = $evento->tipo_evento === 'processo_arquivado';
                            $isDesarquivamento = $evento->tipo_evento === 'processo_desarquivado';
                            $classeIconeEvento = $isArquivamento
                                ? 'bg-orange-100 text-orange-600 border-orange-200'
                                : ($isDesarquivamento ? 'bg-green-100 text-green-600 border-green-200' : 'bg-cyan-100 text-cyan-600 border-cyan-200');
                        @endphp
                        <div class="mb-4 last:mb-0">
                            <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                                {{-- Header do evento --}}
                                <div class="flex items-start justify-between gap-3 mb-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center {{ $isArquivamento ? 'bg-orange-100' : ($isDesarquivamento ? 'bg-green-100' : 'bg-cyan-100') }}">
                                            @if($isArquivamento)
                                                <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8l2-3h10l2 3m-1 4v6a2 2 0 01-2 2H8a2 2 0 01-2-2v-6m12 0H6"/>
                                                </svg>
                                            @elseif($isDesarquivamento)
                                                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14m-4-4l4 4-4 4"/>
                                                </svg>
                                            @else
                                                <svg class="w-4 h-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                                </svg>
                                            @endif
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900">{{ $evento->titulo }}</p>
                                            <p class="text-xs text-slate-500">
                                                por {{ $evento->usuario->nome ?? 'Sistema' }} em {{ $evento->created_at->format('d/m/Y H:i') }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                
                                {{-- Detalhes da atribuição --}}
                                <div class="bg-white rounded-lg p-3 border {{ $isArquivamento ? 'border-orange-200' : ($isDesarquivamento ? 'border-green-200' : 'border-cyan-200') }}">
                                    <div class="grid grid-cols-2 gap-4">
                                        {{-- De --}}
                                        <div>
                                            <p class="text-xs font-medium text-slate-500 uppercase mb-1">De</p>
                                            @if(isset($dadosEvento['setor_anterior_nome']) || isset($dadosEvento['responsavel_anterior']))
                                                <p class="text-sm text-slate-700">
                                                    {{ $dadosEvento['setor_anterior_nome'] ?? 'Sem setor' }}
                                                </p>
                                                @if(isset($dadosEvento['responsavel_anterior']))
                                                <p class="text-xs text-slate-500">{{ $dadosEvento['responsavel_anterior'] }}</p>
                                                @endif
                                            @else
                                                <p class="text-sm text-slate-400 italic">Não atribuído</p>
                                            @endif
                                        </div>
                                        
                                        {{-- Para --}}
                                        <div>
                                            <p class="text-xs font-medium text-slate-500 uppercase mb-1">Para</p>
                                            @if($isArquivamento)
                                                <p class="text-sm text-orange-700 font-medium">Arquivado</p>
                                                @if(isset($dadosEvento['data_arquivamento']))
                                                <p class="text-xs text-orange-600">{{ \Carbon\Carbon::parse($dadosEvento['data_arquivamento'])->format('d/m/Y H:i') }}</p>
                                                @endif
                                            @elseif(isset($dadosEvento['setor_novo_nome']) || isset($dadosEvento['responsavel_novo']) || isset($dadosEvento['setor_restaurado_nome']) || isset($dadosEvento['responsavel_restaurado']))
                                                <p class="text-sm {{ $isDesarquivamento ? 'text-green-700' : 'text-cyan-700' }} font-medium">
                                                    {{ $dadosEvento['setor_novo_nome'] ?? $dadosEvento['setor_restaurado_nome'] ?? 'Sem setor' }}
                                                </p>
                                                @if(isset($dadosEvento['responsavel_novo']) || isset($dadosEvento['responsavel_restaurado']))
                                                <p class="text-xs {{ $isDesarquivamento ? 'text-green-600' : 'text-cyan-600' }}">{{ $dadosEvento['responsavel_novo'] ?? $dadosEvento['responsavel_restaurado'] }}</p>
                                                @endif
                                            @else
                                                <p class="text-sm text-slate-400 italic">Atribuição removida</p>
                                            @endif
                                        </div>
                                    </div>
                                    
                                    {{-- Motivo --}}
                                    @if(isset($dadosEvento['motivo']) && $dadosEvento['motivo'])
                                    <div class="mt-3 pt-3 border-t border-slate-200">
                                        <p class="text-xs font-medium text-slate-500 uppercase mb-1">{{ $isArquivamento ? 'Motivo do Arquivamento' : 'Motivo da Atribuição' }}</p>
                                        <p class="text-sm text-slate-700 {{ $isArquivamento ? 'bg-orange-50 border-orange-100' : 'bg-cyan-50 border-cyan-100' }} rounded p-2 border">
                                            {{ $dadosEvento['motivo'] }}
                                        </p>
                                    </div>
                                    @endif
                                    
                                    {{-- Prazo --}}
                                    @if(isset($dadosEvento['prazo']) && $dadosEvento['prazo'])
                                    <div class="mt-3 pt-3 border-t border-slate-200">
                                        <p class="text-xs font-medium text-slate-500 uppercase mb-1">Prazo para Resolução</p>
                                        <p class="text-sm text-slate-700 flex items-center gap-2">
                                            <svg class="w-4 h-4 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            {{ \Carbon\Carbon::parse($dadosEvento['prazo'])->format('d/m/Y') }}
                                        </p>
                                    </div>
                                    @endif
                                    
                                    {{-- Ciência --}}
                                    @if(isset($dadosEvento['ciente_em']) && $dadosEvento['ciente_em'])
                                    <div class="mt-3 pt-3 border-t border-slate-200">
                                        <div class="flex items-center gap-2 bg-green-50 rounded-lg p-2 border border-green-200">
                                            <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            <div>
                                                <p class="text-xs font-medium text-green-700">Ciente</p>
                                                <p class="text-xs text-green-600">
                                                    {{ $dadosEvento['ciente_por_nome'] ?? 'Responsável' }} 
                                                    em {{ \Carbon\Carbon::parse($dadosEvento['ciente_em'])->format('d/m/Y \à\s H:i') }}
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    @endif

                                    @if($isDesarquivamento && (isset($dadosEvento['motivo_arquivamento_anterior']) || isset($dadosEvento['data_arquivamento_anterior'])))
                                    <div class="mt-3 pt-3 border-t border-slate-200">
                                        <p class="text-xs font-medium text-slate-500 uppercase mb-1">Arquivamento anterior</p>
                                        <div class="bg-slate-50 rounded-lg p-2 border border-slate-200 text-sm text-slate-700 space-y-1">
                                            @if(isset($dadosEvento['data_arquivamento_anterior']) && $dadosEvento['data_arquivamento_anterior'])
                                                <p>Arquivado em {{ \Carbon\Carbon::parse($dadosEvento['data_arquivamento_anterior'])->format('d/m/Y H:i') }}</p>
                                            @endif
                                            @if(isset($dadosEvento['motivo_arquivamento_anterior']) && $dadosEvento['motivo_arquivamento_anterior'])
                                                <p>{{ $dadosEvento['motivo_arquivamento_anterior'] }}</p>
                                            @endif
                                        </div>
                                    </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-8">
                            <svg class="mx-auto h-12 w-12 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                            </svg>
                            <p class="mt-2 text-sm text-slate-500">Nenhuma tramitação registrada</p>
                            <p class="text-xs text-slate-400 mt-1">O histórico de tramitação, arquivamento e restauração aparecerá aqui.</p>
                        </div>
                        @endforelse
                    </div>

                    {{-- Footer --}}
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-200">
                        <button @click="modalHistoricoAtribuicoes = false" class="w-full px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Fechar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Scripts Alpine.js --}}
    <script>
        function processoData() {
            return {
                // Modais
                modalUpload: false,
                modalVisualizador: false,
                modalVisualizadorAnotacoes: false,
                modalEditarNome: false,
                modalVincularObrigatorio: false,
                documentoVinculando: null,
                tipoDocObrigatorioSelecionado: null,
                modalDocumentoDigital: false,
                modalPastas: false,
                modalHistorico: false,
                modalHistoricoAtribuicoes: false,
                modalArquivar: false,
                modalParar: false,
                modalOrdemServico: false,
                modalAlertas: false,
                modalRejeitar: false,
                modalExcluirProcesso: false,
                modalExcluirComSenha: false,
                modalAtribuir: false,
                modalRespostas: false,
                modalAssinar: false,
                modalProrrogarPrazo: false,
                modalDefinirPrazo: false,
                
                // Modal de Assinatura
                assinarDocumentoId: null,
                assinarDocumentoNome: '',
                assinarDocumentoNumero: '',
                assinarOrdem: '',
                assinarAssinaturas: [],
                assinarSenha: '',
                assinarErro: '',
                assinarCarregando: false,

                // Modal de Prorrogação de Prazo
                prorrogarPrazoDocumentoId: null,
                prorrogarPrazoDocumentoNome: '',
                prorrogarPrazoDocumentoNumero: '',
                prorrogarPrazoDataAtual: '',
                prorrogarPrazoDiasDisponiveis: 0,
                prorrogarPrazoDias: 1,
                prorrogarPrazoUrl: '',

                // Modal de Definição de Prazo
                definirPrazoDocumentoId: null,
                definirPrazoDocumentoNome: '',
                definirPrazoDocumentoNumero: '',
                definirPrazoDias: 1,
                definirPrazoTipo: 'corridos',
                definirPrazoUrl: '',
                
                // Modal de Respostas
                respostasDocumentoId: null,
                respostasDocumentoNome: '',
                respostasDocumentoNumero: '',
                respostasDocumentoPdfUrl: '',
                respostasDocumentoRespostaId: null,
                
                // Exclusão com senha
                exclusaoTipo: '', // 'resposta', 'documento', 'documento_digital'
                exclusaoId: null,
                exclusaoNome: '',
                exclusaoUrl: '',
                senhaExclusao: '',
                exclusaoErro: '',
                exclusaoCarregando: false,
                
                // Atribuir processo
                setorAtribuir: '{{ $processo->setor_atual ?? '' }}',
                responsavelAtribuir: '{{ $processo->responsavel_atual_id ?? '' }}',
                usuariosParaAtribuir: [],
                
                // Rejeição de documento
                documentoRejeitando: null,
                motivoRejeicao: '',

                // Análise por IA
                iaAnalisando: false,
                iaResultado: null, // { decisao, motivo, usou_visao, error? }
                iaTemCriterio: false, // Se o tipo de documento tem criterio_ia configurado
                
                // Análise IA em lote
                iaAnalisandoLote: false,
                iaLoteProgresso: 0,
                iaLoteTotal: 0,
                iaLoteResultados: [],
                
                // Dados gerais
                pdfUrl: '',
                pdfUrlAnotacoes: '',
                documentoIdAnotacoes: null,
                documentoNomeAnotacoes: '',
                documentoDigitalDestinoId: @json($documentoDigitalDirecionadoId),
                documentoPendente: false, // Se o documento é externo e pendente de aprovação
                documentosPendentesLista: [], // Lista de documentos pendentes para navegação
                indiceDocumentoPendenteAtual: 0, // Índice do documento atual na lista
                documentoEditando: null,
                nomeEditando: '',
                selecionarMultiplos: false, // Para seleção múltipla de documentos
                
                // Pastas
                pastas: [],
                pastaAtiva: null, // null = Todos, ou ID da pasta
                statusFiltro: null, // null = todos, 'pendente' = apenas pendentes
                pastaEditando: null,
                nomePasta: '',
                descricaoPasta: '',
                corPasta: '#3B82F6',
                
                // Designação
                setores: [],
                usuariosPorSetor: [],
                usuariosDesignados: [],
                descricaoTarefa: '',
                dataLimite: '',
                isCompetenciaEstadual: false,
                
                // Documentos (para contagem) - incluindo documentos digitais e arquivos
                documentos: [
                    @foreach($documentosDigitais as $docDigital)
                        { id: {{ $docDigital->id }}, pasta_id: {{ $docDigital->pasta_id ?? 'null' }}, tipo: 'digital' },
                    @endforeach
                    @foreach($processo->documentos->where('tipo_documento', '!=', 'documento_digital') as $documento)
                        { id: {{ $documento->id }}, pasta_id: {{ $documento->pasta_id ?? 'null' }}, tipo: 'arquivo' },
                    @endforeach
                    @foreach($todosDocumentos->where('tipo', 'ordem_servico') as $itemOrdemServico)
                        { id: {{ $itemOrdemServico['documento']->id }}, pasta_id: {{ $itemOrdemServico['documento']->pasta_id ?? 'null' }}, tipo: 'ordem_servico' },
                    @endforeach
                ],

                // Inicialização
                init() {
                    this.carregarPastas();

                    if (this.documentoDigitalDestinoId) {
                        this.$nextTick(() => this.destacarDocumentoDigitalDirecionado());
                    }

                    window.addEventListener('documento-avaliado', (event) => {
                        const detalhe = event.detail || {};
                        if (!detalhe.docId || !detalhe.status) return;

                        if (this.modalVisualizadorAnotacoes && this.documentoPendente) {
                            this.tratarDocumentoAvaliadoNoModal(parseInt(detalhe.docId), detalhe.status);
                        }
                    });
                },

                destacarDocumentoDigitalDirecionado() {
                    if (!this.documentoDigitalDestinoId) {
                        return;
                    }

                    this.pastaAtiva = null;
                    this.statusFiltro = null;

                    const seletor = `#documento-digital-${this.documentoDigitalDestinoId}`;

                    setTimeout(() => {
                        const item = document.querySelector(seletor);

                        if (!item) {
                            return;
                        }

                        item.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        item.classList.add('ring-4', 'ring-emerald-200');

                        setTimeout(() => {
                            item.classList.remove('ring-4', 'ring-emerald-200');
                        }, 2200);
                    }, 180);
                },

                // Função para mostrar notificações
                mostrarNotificacao(mensagem, tipo = 'success') {
                    const container = document.createElement('div');
                    container.className = `fixed top-4 right-4 z-50 max-w-sm w-full bg-white rounded-lg shadow-lg border-l-4 ${tipo === 'success' ? 'border-green-500' : 'border-red-500'} p-4 animate-slide-in`;
                    container.style.animation = 'slideIn 0.3s ease-out';
                    
                    container.innerHTML = `
                        <div class="flex items-center">
                            <svg class="w-5 h-5 ${tipo === 'success' ? 'text-green-500' : 'text-red-500'} mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                ${tipo === 'success' 
                                    ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>'
                                    : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>'}
                            </svg>
                            <p class="text-sm font-medium ${tipo === 'success' ? 'text-green-800' : 'text-red-800'}">${mensagem}</p>
                        </div>
                    `;
                    
                    document.body.appendChild(container);
                    
                    setTimeout(() => {
                        container.style.animation = 'slideOut 0.3s ease-in';
                        setTimeout(() => container.remove(), 300);
                    }, 3000);
                },

                // Métodos de Pastas
                carregarPastas() {
                    fetch('{{ route('admin.estabelecimentos.processos.pastas.index', [$estabelecimento->id, $processo->id]) }}')
                        .then(response => response.json())
                        .then(data => {
                            this.pastas = data;
                        })
                        .catch(error => console.error('Erro ao carregar pastas:', error));
                },

                salvarPasta() {
                    const url = this.pastaEditando 
                        ? '{{ route('admin.estabelecimentos.processos.pastas.update', [$estabelecimento->id, $processo->id, ':id']) }}'.replace(':id', this.pastaEditando.id)
                        : '{{ route('admin.estabelecimentos.processos.pastas.store', [$estabelecimento->id, $processo->id]) }}';

                    const formData = new FormData();
                    formData.append('nome', this.nomePasta);
                    formData.append('descricao', this.descricaoPasta || '');
                    formData.append('cor', this.corPasta || '#3B82F6');

                    if (this.pastaEditando) {
                        formData.append('_method', 'PUT');
                    }

                    fetch(url, {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: formData
                    })
                    .then(async response => {
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            const text = await response.text();
                            throw new Error(`Resposta inesperada do servidor (${response.status}).`);
                        }
                        const json = await response.json();
                        if (!response.ok) {
                            throw new Error(json?.message || 'Erro ao salvar pasta.');
                        }
                        return json;
                    })
                    .then(result => {
                        if (result.success) {
                            this.cancelarEdicao();
                            this.carregarPastas();
                            // Pequeno delay para garantir que as pastas foram carregadas antes de fechar
                            setTimeout(() => {
                                alert(result.message);
                            }, 100);
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao salvar pasta:', error);
                        alert(error.message || 'Erro ao salvar pasta.');
                    });
                },

                editarPasta(pasta) {
                    this.pastaEditando = pasta;
                    this.nomePasta = pasta.nome;
                    this.descricaoPasta = pasta.descricao || '';
                    this.corPasta = pasta.cor;
                },

                cancelarEdicao() {
                    this.pastaEditando = null;
                    this.nomePasta = '';
                    this.descricaoPasta = '';
                    this.corPasta = '#3B82F6';
                },

                excluirPasta(pastaId) {
                    if (!confirm('Tem certeza que deseja excluir esta pasta? Os documentos e arquivos serão movidos para "Todos".')) {
                        return;
                    }

                    const formData = new FormData();
                    formData.append('_method', 'DELETE');

                    fetch('{{ route('admin.estabelecimentos.processos.pastas.destroy', [$estabelecimento->id, $processo->id, ':id']) }}'.replace(':id', pastaId), {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': '{{ csrf_token() }}',
                            'Accept': 'application/json'
                        },
                        body: formData
                    })
                    .then(async response => {
                        const contentType = response.headers.get('content-type') || '';
                        if (!contentType.includes('application/json')) {
                            const text = await response.text();
                            throw new Error(`Resposta inesperada do servidor (${response.status}).`);
                        }
                        const json = await response.json();
                        if (!response.ok) {
                            throw new Error(json?.message || 'Erro ao excluir pasta.');
                        }
                        return json;
                    })
                    .then(result => {
                        if (result.success) {
                            this.carregarPastas();
                            alert(result.message);
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao excluir pasta:', error);
                        alert(error.message || 'Erro ao excluir pasta.');
                    });
                },

                async vincularPastaUnidade(pasta) {
                    const unidades = @json($unidadesDisponiveis ?? collect()->map(fn($u) => ['id' => $u->id, 'nome' => $u->nome])->values());
                    if (!unidades || unidades.length === 0) {
                        alert('Este tipo de processo não possui unidades configuradas.');
                        return;
                    }

                    let opcoesTexto = 'Digite o número da unidade:\n\n';
                    opcoesTexto += '0 - Remover vínculo (deixar como pasta avulsa)\n';
                    unidades.forEach((u, idx) => {
                        opcoesTexto += `${idx + 1} - ${u.nome}\n`;
                    });

                    const escolha = prompt(opcoesTexto, '');
                    if (escolha === null) return;

                    const numero = parseInt(escolha);
                    let unidadeId = null;
                    if (numero === 0) {
                        unidadeId = null;
                    } else if (numero >= 1 && numero <= unidades.length) {
                        unidadeId = unidades[numero - 1].id;
                    } else {
                        alert('Opção inválida.');
                        return;
                    }

                    try {
                        const response = await fetch(`{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/pastas') }}/${pasta.id}/vincular-unidade`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json',
                            },
                            body: JSON.stringify({ unidade_id: unidadeId }),
                        });
                        const result = await response.json();
                        if (result.success) {
                            alert(result.message);
                            this.carregarPastas();
                        } else {
                            alert(result.message || 'Erro ao vincular unidade.');
                        }
                    } catch (error) {
                        alert('Erro ao vincular unidade: ' + error.message);
                    }
                },

                moverParaPasta(itemId, tipo, pastaId, element) {
                    fetch('{{ route('admin.estabelecimentos.processos.pastas.mover', [$estabelecimento->id, $processo->id]) }}', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': '{{ csrf_token() }}'
                        },
                        body: JSON.stringify({
                            tipo: tipo,
                            item_id: itemId,
                            pasta_id: pastaId
                        })
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            // Encontrar o elemento pai com x-data
                            const docElement = element.closest('[x-data]');
                            if (docElement && docElement.__x) {
                                // Atualizar a variável pastaDocumento do Alpine.js
                                docElement.__x.$data.pastaDocumento = pastaId;
                            }
                            
                            // Atualizar o array de documentos
                            const tipoDocumento = tipo === 'documento' ? 'digital' : tipo;
                            const docIndex = this.documentos.findIndex(doc => doc.id === itemId && doc.tipo === tipoDocumento);
                            if (docIndex !== -1) {
                                this.documentos[docIndex].pasta_id = pastaId;
                            }
                            
                            // Mostrar mensagem de sucesso
                            this.mostrarNotificacao(result.message, 'success');
                        }
                    })
                    .catch(error => {
                        console.error('Erro ao mover item:', error);
                        this.mostrarNotificacao('Erro ao mover o item. Tente novamente.', 'error');
                    });
                },

                contarDocumentosPorPasta(pastaId) {
                    return this.documentos.filter(doc => doc.pasta_id === pastaId).length;
                },

                // Métodos para Documentos Digitais
                moverDocumentoDigitalParaPasta(documentoId, pastaId, element) {
                    fetch(`${window.APP_URL}/admin/documentos/${documentoId}/mover-pasta`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ pasta_id: pastaId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Encontrar o elemento pai com x-data
                            const docElement = element.closest('[x-data]');
                            if (docElement && docElement.__x) {
                                // Atualizar a variável pastaDocumento do Alpine.js
                                docElement.__x.$data.pastaDocumento = pastaId;
                            }
                            
                            // Atualizar o array de documentos
                            const docIndex = this.documentos.findIndex(doc => doc.id === documentoId && doc.tipo === 'digital');
                            if (docIndex !== -1) {
                                this.documentos[docIndex].pasta_id = pastaId;
                            }
                            
                            // Mostrar mensagem de sucesso
                            this.mostrarNotificacao(data.message || 'Documento movido com sucesso!', 'success');
                        } else {
                            this.mostrarNotificacao(data.message || 'Erro ao mover documento', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        this.mostrarNotificacao('Erro ao mover documento', 'error');
                    });
                },

                renomearDocumentoDigital(documentoId, novoNome) {
                    fetch(`${window.APP_URL}/admin/documentos/${documentoId}/renomear`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                        },
                        body: JSON.stringify({ nome: novoNome })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.location.reload();
                        } else {
                            alert(data.message || 'Erro ao renomear documento');
                        }
                    })
                    .catch(error => {
                        console.error('Erro:', error);
                        alert('Erro ao renomear documento');
                    });
                },

                // Abre modal de exclusão com senha
                abrirModalExclusao(tipo, id, nome, url) {
                    this.exclusaoTipo = tipo;
                    this.exclusaoId = id;
                    this.exclusaoNome = nome;
                    this.exclusaoUrl = url;
                    this.senhaExclusao = '';
                    this.exclusaoErro = '';
                    this.exclusaoCarregando = false;
                    this.modalExcluirComSenha = true;
                },

                // Executa exclusão com validação de senha
                async executarExclusao() {
                    if (!this.senhaExclusao) {
                        this.exclusaoErro = 'Digite sua senha de assinatura';
                        return;
                    }

                    this.exclusaoCarregando = true;
                    this.exclusaoErro = '';

                    try {
                        // Usa POST com _method=DELETE para compatibilidade com servidores que não aceitam DELETE
                        const response = await fetch(this.exclusaoUrl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                _method: 'DELETE',
                                senha_assinatura: this.senhaExclusao
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.modalExcluirComSenha = false;
                            window.location.reload();
                        } else {
                            this.exclusaoErro = data.message || 'Erro ao excluir';
                        }
                    } catch (error) {
                        console.error('Erro:', error);
                        this.exclusaoErro = 'Erro ao processar exclusão';
                    } finally {
                        this.exclusaoCarregando = false;
                    }
                },

                excluirDocumentoDigital(documentoId, nomeDocumento) {
                    this.abrirModalExclusao(
                        'documento_digital',
                        documentoId,
                        nomeDocumento || 'Documento Digital',
                        `{{ url('/admin/documentos') }}/${documentoId}`
                    );
                },

                gerarUrlSemCache(url, chave = null) {
                    if (!url) return '';

                    const separador = url.includes('?') ? '&' : '?';
                    const token = encodeURIComponent(chave ?? Date.now());

                    return `${url}${separador}v=${token}`;
                },

                fecharModalRespostas() {
                    this.modalRespostas = false;
                    this.respostasDocumentoId = null;
                    this.respostasDocumentoNome = '';
                    this.respostasDocumentoNumero = '';
                    this.respostasDocumentoPdfUrl = '';
                    this.respostasDocumentoRespostaId = null;
                },

                // Abre modal de visualização de documento com respostas
                abrirModalRespostas(documentoId, nomeDocumento, numeroDocumento, pdfUrl, respostaId = null) {
                    this.respostasDocumentoId = null;
                    this.respostasDocumentoNome = nomeDocumento;
                    this.respostasDocumentoNumero = numeroDocumento;
                    this.respostasDocumentoPdfUrl = this.gerarUrlSemCache(pdfUrl, `doc-${documentoId}`);
                    this.respostasDocumentoRespostaId = respostaId;
                    this.modalRespostas = true;

                    this.$nextTick(() => {
                        this.respostasDocumentoId = documentoId;
                    });
                },

                // Abre modal de assinatura
                abrirModalAssinar(documentoId, nomeDocumento, numeroDocumento, ordem, assinaturas) {
                    this.assinarDocumentoId = documentoId;
                    this.assinarDocumentoNome = nomeDocumento;
                    this.assinarDocumentoNumero = numeroDocumento;
                    this.assinarOrdem = ordem;
                    this.assinarAssinaturas = assinaturas;
                    this.assinarSenha = '';
                    this.assinarErro = '';
                    this.assinarCarregando = false;
                    this.modalAssinar = true;
                },

                abrirModalProrrogarPrazo(documentoId, nomeDocumento, numeroDocumento, dataAtual, diasDisponiveis, url) {
                    this.prorrogarPrazoDocumentoId = documentoId;
                    this.prorrogarPrazoDocumentoNome = nomeDocumento;
                    this.prorrogarPrazoDocumentoNumero = numeroDocumento;
                    this.prorrogarPrazoDataAtual = dataAtual;
                    this.prorrogarPrazoDiasDisponiveis = Number(diasDisponiveis) || 0;
                    this.prorrogarPrazoDias = this.prorrogarPrazoDiasDisponiveis > 0 ? 1 : 0;
                    this.prorrogarPrazoUrl = url;
                    this.modalProrrogarPrazo = true;
                },

                abrirModalDefinirPrazo(documentoId, nomeDocumento, numeroDocumento, url) {
                    this.definirPrazoDocumentoId = documentoId;
                    this.definirPrazoDocumentoNome = nomeDocumento;
                    this.definirPrazoDocumentoNumero = numeroDocumento;
                    this.definirPrazoDias = 1;
                    this.definirPrazoTipo = 'corridos';
                    this.definirPrazoUrl = url;
                    this.modalDefinirPrazo = true;
                },

                // Processa assinatura via AJAX
                async processarAssinatura() {
                    if (!this.assinarSenha) {
                        this.assinarErro = 'Digite sua senha de assinatura';
                        return;
                    }
                    
                    this.assinarCarregando = true;
                    this.assinarErro = '';
                    
                    try {
                        const response = await fetch(`{{ url('/admin/assinatura/processar') }}/${this.assinarDocumentoId}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json'
                            },
                            body: JSON.stringify({
                                senha_assinatura: this.assinarSenha,
                                acao: 'assinar'
                            })
                        });
                        
                        const data = await response.json();
                        
                        if (response.ok && data.success) {
                            this.modalAssinar = false;
                            // Mostrar notificação de sucesso
                            if (typeof Swal !== 'undefined') {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Documento assinado!',
                                    text: data.message || 'Assinatura realizada com sucesso.',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    window.location.reload();
                                });
                            } else {
                                alert(data.message || 'Documento assinado com sucesso!');
                                window.location.reload();
                            }
                        } else {
                            this.assinarErro = data.message || data.error || 'Erro ao assinar documento';
                        }
                    } catch (error) {
                        console.error('Erro:', error);
                        this.assinarErro = 'Erro de conexão. Tente novamente.';
                    } finally {
                        this.assinarCarregando = false;
                    }
                },

                // Abre o visualizador de PDF com ferramentas de anotação
                async abrirVisualizadorAnotacoes(documentoId, pdfUrl, isPendente = false, nomeDocumento = '', temCriterioIA = false) {
                    this.documentoIdAnotacoes = documentoId;
                    this.pdfUrlAnotacoes = pdfUrl;
                    this.documentoPendente = isPendente;
                    this.documentoNomeAnotacoes = nomeDocumento;
                    this.modalVisualizadorAnotacoes = true;
                    this.iaTemCriterio = temCriterioIA;
                    this.iaResultado = null; // Limpa resultado de IA anterior ao abrir novo documento
                    
                    // Se é um documento pendente, constrói a lista de documentos pendentes
                    if (isPendente) {
                        this.construirListaDocumentosPendentes(documentoId);
                    }
                    
                    // Notificar que o modal PDF foi aberto
                    window.dispatchEvent(new CustomEvent('pdf-modal-aberto'));
                    
                    // Carrega automaticamente o documento na IA
                    await this.carregarDocumentoNaIA();
                },

                // Constrói a lista de documentos pendentes para navegação
                construirListaDocumentosPendentes(documentoIdAtual) {
                    this.documentosPendentesLista = [];
                    
                    // Busca todos os documentos pendentes no DOM (incluindo os ocultos por Alpine)
                    const documentosPendentes = document.querySelectorAll('.documento-item[data-status="pendente"]');
                    
                    console.log('Documentos pendentes encontrados:', documentosPendentes.length);
                    
                    documentosPendentes.forEach((el) => {
                        const docId = parseInt(el.getAttribute('data-doc-id'));
                        
                        // Busca o elemento com @click que contém abrirVisualizadorAnotacoes
                        const linkElement = el.querySelector('[\\@click*="abrirVisualizadorAnotacoes"], [x-on\\:click*="abrirVisualizadorAnotacoes"]');
                        
                        if (linkElement) {
                            // Pega o atributo @click ou x-on:click
                            const clickAttr = linkElement.getAttribute('@click') || linkElement.getAttribute('x-on:click');
                            console.log('@click encontrado:', clickAttr);
                            
                            // Regex para capturar os parâmetros (incluindo 5º: temCriterioIA)
                            const matches = clickAttr.match(/abrirVisualizadorAnotacoes\((\d+),\s*'([^']+)',\s*(true|false),\s*'([^']*)',\s*(true|false)\)/);

                            if (matches) {
                                const [, id, url, isPending, nome, temIA] = matches;
                                this.documentosPendentesLista.push({
                                    id: parseInt(id),
                                    url: url,
                                    nome: nome,
                                    temCriterioIA: temIA === 'true',
                                });
                                
                                console.log('Documento adicionado:', { id: parseInt(id), nome });
                                
                                // Define o índice atual
                                if (parseInt(id) === documentoIdAtual) {
                                    this.indiceDocumentoPendenteAtual = this.documentosPendentesLista.length - 1;
                                }
                            }
                        }
                    });
                    
                    console.log('Lista final de documentos pendentes:', this.documentosPendentesLista);
                    console.log('Índice atual:', this.indiceDocumentoPendenteAtual);
                },

                // Navega entre documentos pendentes
                navegarDocumentoPendente(direcao) {
                    if (direcao === 'proximo' && this.indiceDocumentoPendenteAtual < this.documentosPendentesLista.length - 1) {
                        this.indiceDocumentoPendenteAtual++;
                    } else if (direcao === 'anterior' && this.indiceDocumentoPendenteAtual > 0) {
                        this.indiceDocumentoPendenteAtual--;
                    } else {
                        return; // Não faz nada se já está no limite
                    }
                    
                    // Carrega o documento na nova posição
                    const doc = this.documentosPendentesLista[this.indiceDocumentoPendenteAtual];
                    this.documentoIdAnotacoes = doc.id;
                    this.pdfUrlAnotacoes = doc.url;
                    this.documentoNomeAnotacoes = doc.nome;
                    this.iaTemCriterio = doc.temCriterioIA ?? false;
                    this.iaResultado = null; // Limpa resultado de IA ao navegar

                    // Recarrega o documento na IA
                    this.carregarDocumentoNaIA();
                },

                tratarDocumentoAvaliadoNoModal(docId, status) {
                    if (!this.documentosPendentesLista || this.documentosPendentesLista.length === 0) return;

                    const indiceAvaliado = this.documentosPendentesLista.findIndex(doc => parseInt(doc.id) === parseInt(docId));
                    if (indiceAvaliado === -1) return;

                    this.documentosPendentesLista.splice(indiceAvaliado, 1);

                    if (this.documentosPendentesLista.length === 0) {
                        this.documentoPendente = false;
                        this.indiceDocumentoPendenteAtual = 0;
                        this.mostrarNotificacao(`Documento ${status === 'aprovado' ? 'aprovado' : 'rejeitado'} com sucesso! Não há mais pendentes nesta lista.`, 'success');
                        return;
                    }

                    let novoIndice = indiceAvaliado;
                    if (novoIndice >= this.documentosPendentesLista.length) {
                        novoIndice = this.documentosPendentesLista.length - 1;
                    }

                    this.indiceDocumentoPendenteAtual = novoIndice;
                    const proximoDoc = this.documentosPendentesLista[novoIndice];

                    this.documentoIdAnotacoes = proximoDoc.id;
                    this.pdfUrlAnotacoes = proximoDoc.url;
                    this.documentoNomeAnotacoes = proximoDoc.nome;

                    this.carregarDocumentoNaIA();
                    this.mostrarNotificacao(`Documento ${status === 'aprovado' ? 'aprovado' : 'rejeitado'} com sucesso!`, 'success');
                },

                async analisarDocumentoComIA() {
                    if (!this.documentoIdAnotacoes) return;

                    this.iaAnalisando = true;
                    this.iaResultado  = null;

                    const url = `{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/documentos') }}/${this.documentoIdAnotacoes}/analisar-ia`;

                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept':       'application/json',
                                'Content-Type': 'application/json',
                            },
                        });

                        const data = await response.json();

                        if (!response.ok || data.error) {
                            this.iaResultado = { error: data.error || 'Erro desconhecido ao analisar o documento.' };
                        } else {
                            this.iaResultado = data;
                        }
                    } catch (e) {
                        this.iaResultado = { error: 'Falha de comunicação com o servidor: ' + e.message };
                    } finally {
                        this.iaAnalisando = false;
                    }
                },

                async analisarDocumentosIA() {
                    if (this.iaAnalisandoLote) return;

                    const documentosComIA = @json($documentosPendentesComIA);

                    if (documentosComIA.length === 0) {
                        alert('Nenhum documento pendente com critérios de IA configurados.');
                        return;
                    }

                    if (!confirm(`Analisar e processar ${documentosComIA.length} documento(s) pendente(s) com IA?\n\nA IA vai avaliar cada documento e executar a ação automaticamente:\n• Aprovados → serão aprovados\n• Rejeitados → serão rejeitados com o motivo da IA`)) {
                        return;
                    }

                    this.iaAnalisandoLote = true;
                    this.iaLoteProgresso = 0;
                    this.iaLoteTotal = documentosComIA.length;
                    this.iaLoteResultados = [];

                    const baseUrl = `{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/documentos') }}`;
                    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

                    for (const doc of documentosComIA) {
                        this.iaLoteProgresso++;
                        try {
                            // 1. Analisar com IA
                            const response = await fetch(`${baseUrl}/${doc.id}/analisar-ia`, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                            });
                            const data = await response.json();

                            if (!response.ok || data.error) {
                                let motivo = data.error || 'Erro ao analisar';
                                if (response.status === 402) motivo = 'Créditos da API esgotados.';
                                else if (response.status === 429) motivo = 'Limite de requisições atingido.';
                                this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'erro', motivo });
                                if (response.status === 402 || response.status === 429) break;
                                continue;
                            }

                            // 2. Executar ação baseada na decisão da IA
                            if (data.decisao === 'aprovado') {
                                const aprResponse = await fetch(`${baseUrl}/${doc.id}/aprovar`, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                                });
                                const aprData = await aprResponse.json();
                                if (aprData.success) {
                                    this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'aprovado', motivo: data.motivo });
                                    if (window.atualizarDocumentoUI) window.atualizarDocumentoUI(doc.id, 'aprovado');
                                } else {
                                    this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'erro', motivo: 'IA aprovou mas erro ao salvar: ' + (aprData.message || '') });
                                }
                            } else if (data.decisao === 'rejeitado') {
                                const rejResponse = await fetch(`${baseUrl}/${doc.id}/rejeitar`, {
                                    method: 'POST',
                                    headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json', 'Content-Type': 'application/json' },
                                    body: JSON.stringify({ motivo_rejeicao: data.motivo || 'Rejeitado pela análise de IA' }),
                                });
                                const rejData = await rejResponse.json();
                                if (rejData.success) {
                                    this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'rejeitado', motivo: data.motivo });
                                    if (window.atualizarDocumentoUI) window.atualizarDocumentoUI(doc.id, 'rejeitado');
                                } else {
                                    this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'erro', motivo: 'IA rejeitou mas erro ao salvar: ' + (rejData.message || '') });
                                }
                            } else {
                                this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'erro', motivo: 'Decisão inesperada: ' + data.decisao });
                            }
                        } catch (e) {
                            this.iaLoteResultados.push({ id: doc.id, nome: doc.nome, decisao: 'erro', motivo: 'Falha de conexão' });
                        }
                        // Delay entre chamadas
                        if (this.iaLoteProgresso < this.iaLoteTotal) {
                            await new Promise(r => setTimeout(r, 1500));
                        }
                    }

                    this.iaAnalisandoLote = false;
                },

                async aprovarDocumentoNoModal() {
                    if (!this.documentoIdAnotacoes) return;
                    if (!confirm('Aprovar este documento?')) return;

                    const url = `{{ url('admin/estabelecimentos/' . $estabelecimento->id . '/processos/' . $processo->id . '/documentos') }}/${this.documentoIdAnotacoes}/aprovar`;

                    try {
                        const response = await fetch(url, {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'Accept': 'application/json'
                            }
                        });

                        const data = await response.json();

                        if (!data.success) {
                            this.mostrarNotificacao(data.message || 'Erro ao aprovar documento', 'error');
                            return;
                        }

                        if (window.atualizarDocumentoUI) {
                            window.atualizarDocumentoUI(this.documentoIdAnotacoes, 'aprovado');
                        }

                        window.dispatchEvent(new CustomEvent('documento-avaliado', {
                            detail: { docId: this.documentoIdAnotacoes, status: 'aprovado' }
                        }));
                    } catch (error) {
                        console.error('Erro ao aprovar documento no modal:', error);
                        this.mostrarNotificacao('Erro ao aprovar documento', 'error');
                    }
                },

                // Carrega documento na IA para perguntas
                async carregarDocumentoNaIA() {
                    // Verifica se o assistente de IA está ativo (se o elemento existe no DOM)
                    const assistenteAtivo = document.getElementById('assistente-documento-chat');
                    if (!assistenteAtivo) {
                        console.log('Assistente de IA desativado - não será carregado documento');
                        return;
                    }

                    if (!this.documentoIdAnotacoes) {
                        alert('Nenhum documento selecionado');
                        return;
                    }

                    // Mostra loading
                    const loadingMsg = 'Carregando documento na IA...';
                    console.log(loadingMsg);

                    try {
                        // Chama endpoint para extrair texto do PDF
                        const response = await fetch(`{{ route('admin.assistente-ia.extrair-pdf') }}`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({
                                documento_id: this.documentoIdAnotacoes,
                                estabelecimento_id: {{ $estabelecimento->id }},
                                processo_id: {{ $processo->id }}
                            })
                        });

                        const data = await response.json();

                        if (data.success) {
                            // Dispara evento customizado para o componente do chat
                            window.dispatchEvent(new CustomEvent('documento-carregado', {
                                detail: {
                                    documento_id: this.documentoIdAnotacoes,
                                    nome_documento: data.nome_documento,
                                    conteudo: data.conteudo,
                                    total_caracteres: data.total_caracteres,
                                    processo_id: {{ $processo->id }},
                                    estabelecimento_id: {{ $estabelecimento->id }}
                                }
                            }));

                            // NÃO fecha o modal - mantém aberto para visualização
                            // this.modalVisualizadorAnotacoes = false;

                            // Não mostra alert - IA já mostra mensagem no chat
                            // alert('✅ Documento carregado! Agora você pode fazer perguntas sobre ele no chat da IA.');
                        } else {
                            alert('❌ ' + (data.message || 'Erro ao carregar documento'));
                        }
                    } catch (error) {
                        console.error('Erro ao carregar documento:', error);
                        alert('❌ Erro ao carregar documento na IA');
                    }
                },

                // Fecha o modal PDF e dispara evento para fechar assistente de documento
                fecharModalPDF() {
                    this.modalVisualizadorAnotacoes = false;
                    // Limpa as variáveis do documento para forçar recarregamento
                    this.documentoIdAnotacoes = null;
                    this.pdfUrlAnotacoes = '';
                    this.documentoNomeAnotacoes = '';
                    // Dispara evento para notificar que o modal PDF foi fechado
                    window.dispatchEvent(new CustomEvent('pdf-modal-fechado'));
                },

                // Carrega setores e usuários para designação
                carregarUsuarios() {
                    fetch(`{{ route('admin.estabelecimentos.processos.usuarios.designacao', [$estabelecimento->id, $processo->id]) }}`)
                        .then(response => response.json())
                        .then(data => {
                            this.setores = data.setores || [];
                            this.usuariosPorSetor = data.usuariosPorSetor || [];
                            this.isCompetenciaEstadual = data.isCompetenciaEstadual || false;
                        })
                        .catch(error => {
                            console.error('Erro ao carregar setores e usuários:', error);
                            alert('Erro ao carregar setores e usuários');
                        });
                },
                
                // Carrega usuários para atribuição de processo
                carregarUsuariosAtribuir() {
                    fetch(`{{ route('admin.estabelecimentos.processos.usuarios.designacao', [$estabelecimento->id, $processo->id]) }}`)
                        .then(response => response.json())
                        .then(data => {
                            this.usuariosParaAtribuir = data.usuariosPorSetor || [];
                            this.setores = data.setores || [];
                        })
                        .catch(error => {
                            console.error('Erro ao carregar usuários:', error);
                        });
                }
            }
        }
    </script>

    {{-- Modal Passar Processo (Atribuir Setor/Responsável) --}}
    <div x-show="modalAtribuir" 
         x-cloak
         x-init="$watch('modalAtribuir', value => { if(value) carregarUsuariosAtribuir() })"
         class="fixed inset-0 z-50 overflow-y-auto" 
         aria-labelledby="modal-title" 
         role="dialog" 
         aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            {{-- Overlay --}}
            <div x-show="modalAtribuir" 
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" 
                 @click="modalAtribuir = false"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            {{-- Modal Panel --}}
            <div x-show="modalAtribuir"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                
                <form action="{{ route('admin.estabelecimentos.processos.atribuir', [$estabelecimento->id, $processo->id]) }}" method="POST">
                    @csrf
                    
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-cyan-100 flex items-center justify-center">
                                <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-slate-900">Tramitar Processo</h3>
                                <p class="text-sm text-slate-500">Atribua o processo a um setor e/ou responsável</p>
                            </div>
                        </div>

                        {{-- Alerta: Documentos com prazo aberto --}}
                        @php
                            $docsComPrazoAberto = $documentosDigitais->filter(function ($doc) {
                                return $doc->temPrazo() 
                                    && !$doc->isPrazoFinalizado() 
                                    && $doc->status === 'assinado'
                                    && $doc->todasAssinaturasCompletas();
                            });
                        @endphp
                        @if($docsComPrazoAberto->count() > 0)
                        <div class="mb-4 p-3 bg-amber-50 border border-amber-300 rounded-lg">
                            <div class="flex items-start gap-2">
                                <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.072 16.5c-.77.833.192 2.5 1.732 2.5z"/>
                                </svg>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-amber-800">Atenção: Existem documentos com prazo em aberto</p>
                                    <p class="text-xs text-amber-700 mt-1">Antes de tramitar, verifique se os prazos dos documentos abaixo podem ser encerrados:</p>
                                    <ul class="mt-2 space-y-1">
                                        @foreach($docsComPrazoAberto as $docPrazo)
                                        <li class="text-xs text-amber-800 flex items-center gap-1.5">
                                            <span class="w-1.5 h-1.5 bg-amber-500 rounded-full flex-shrink-0"></span>
                                            <strong>{{ $docPrazo->nome }}</strong>
                                            <span class="text-amber-600">({{ $docPrazo->numero_documento }})</span>
                                            @if($docPrazo->data_vencimento)
                                                — vence {{ $docPrazo->data_vencimento->format('d/m/Y') }}
                                            @endif
                                            @if($docPrazo->respostas->where('status', 'aprovado')->count() > 0)
                                                <span class="px-1.5 py-0.5 text-[9px] font-bold bg-green-100 text-green-700 rounded">Resposta aprovada</span>
                                            @endif
                                        </li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>
                        </div>
                        @endif
                        
                        <div class="space-y-4">
                            {{-- Setor --}}
                            <div>
                                <label for="setor_atual" class="block text-sm font-medium text-slate-700 mb-1">
                                    Setor
                                </label>
                                <select name="setor_atual" id="setor_atual" x-model="setorAtribuir"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 text-sm">
                                    <option value="">Selecione um setor (opcional)</option>
                                    <template x-for="setor in setores" :key="setor.codigo">
                                        <option :value="setor.codigo" x-text="setor.nome"></option>
                                    </template>
                                </select>
                            </div>
                            
                            {{-- Responsável --}}
                            <div>
                                <label for="responsavel_atual_id" class="block text-sm font-medium text-slate-700 mb-1">
                                    Responsável
                                </label>
                                <select name="responsavel_atual_id" id="responsavel_atual_id" x-model="responsavelAtribuir"
                                        class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 text-sm">
                                    <option value="">Selecione um responsável (opcional)</option>
                                    <template x-for="grupo in usuariosParaAtribuir" :key="grupo.setor.codigo">
                                        <template x-if="!setorAtribuir || grupo.setor.codigo === setorAtribuir">
                                            <optgroup :label="grupo.setor.nome">
                                                <template x-for="usuario in grupo.usuarios" :key="usuario.id">
                                                    <option :value="usuario.id" x-text="usuario.nome"></option>
                                                </template>
                                            </optgroup>
                                        </template>
                                    </template>
                                </select>
                                <p class="text-xs text-slate-500 mt-1">
                                    <template x-if="setorAtribuir">
                                        <span>Mostrando usuários do setor selecionado</span>
                                    </template>
                                    <template x-if="!setorAtribuir">
                                        <span>Mostrando todos os usuários</span>
                                    </template>
                                </p>
                            </div>
                            
                            {{-- Info atual --}}
                            @if($processo->setor_atual || $processo->responsavel_atual_id)
                            <div class="p-3 bg-slate-50 rounded-lg text-sm">
                                <p class="text-slate-600">
                                    <strong>Atualmente com:</strong> 
                                    {{ $processo->setor_atual_nome ?? '' }}
                                    {{ $processo->setor_atual && $processo->responsavelAtual ? ' - ' : '' }}
                                    {{ $processo->responsavelAtual->nome ?? '' }}
                                </p>
                            </div>
                            @endif
                            
                            {{-- Motivo/Descrição da Atribuição --}}
                            <div>
                                <label for="motivo_atribuicao" class="block text-sm font-medium text-slate-700 mb-1">
                                    Motivo da Atribuição <span class="text-slate-400 font-normal">(opcional)</span>
                                </label>
                                <textarea name="motivo_atribuicao" id="motivo_atribuicao" rows="3"
                                          placeholder="Descreva o motivo da atribuição para que o responsável saiba o que precisa ser feito..."
                                          class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 text-sm resize-none"></textarea>
                                <p class="text-xs text-slate-500 mt-1">Esta informação ficará visível no histórico de atribuições do processo.</p>
                            </div>
                            
                            {{-- Prazo para Resolução - Apenas para Gestores e Admin --}}
                            @if(in_array(auth('interno')->user()->nivel_acesso->value, ['administrador', 'gestor_estadual', 'gestor_municipal']))
                            <div>
                                <label for="prazo_atribuicao" class="block text-sm font-medium text-slate-700 mb-1">
                                    Prazo para Resolução <span class="text-slate-400 font-normal">(opcional)</span>
                                </label>
                                <input type="date" name="prazo_atribuicao" id="prazo_atribuicao"
                                       min="{{ date('Y-m-d') }}"
                                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 text-sm">
                                <p class="text-xs text-slate-500 mt-1">Defina uma data limite para o responsável resolver a demanda.</p>
                            </div>
                            @endif
                        </div>
                    </div>
                    
                    <div class="bg-slate-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse gap-2">
                        <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-cyan-600 text-base font-medium text-white hover:bg-cyan-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-cyan-500 sm:w-auto sm:text-sm">
                            Atribuir
                        </button>
                        <button type="button" @click="modalAtribuir = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-slate-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-slate-500 sm:mt-0 sm:w-auto sm:text-sm">
                            Cancelar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Criar Ordem de Serviço --}}
    <div x-show="modalOrdemServico" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto" 
         aria-labelledby="modal-title" 
         role="dialog" 
         aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            {{-- Overlay --}}
            <div x-show="modalOrdemServico" 
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-slate-500 bg-opacity-75 transition-opacity" 
                 @click="modalOrdemServico = false"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            {{-- Modal Panel --}}
            <div x-show="modalOrdemServico"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
                
                <form action="{{ route('admin.ordens-servico.store') }}" method="POST">
                    @csrf
                    
                    {{-- Campos ocultos --}}
                    <input type="hidden" name="tipo_vinculacao" value="com_estabelecimento">
                    <input type="hidden" name="estabelecimento_id" value="{{ $estabelecimento->id }}">
                    <input type="hidden" name="processo_id" value="{{ $processo->id }}">
                    <input type="hidden" name="municipio_id" value="{{ $processo->estabelecimento->municipio_id }}">
                    
                    {{-- Header --}}
                    <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-6 py-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                                </svg>
                                Nova Ordem de Serviço
                            </h3>
                            <button type="button" 
                                    @click="modalOrdemServico = false" 
                                    class="text-white hover:text-slate-200 transition-colors">
                                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    {{-- Body --}}
                    <div class="px-6 py-4 space-y-4">
                        {{-- Informações do Processo (Read-only) --}}
                        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
                            <h4 class="text-sm font-semibold text-purple-900 mb-3 flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                                Vinculado ao Processo
                            </h4>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div>
                                    <span class="text-slate-600">Estabelecimento:</span>
                                    <p class="font-medium text-slate-900">{{ $estabelecimento->nome_fantasia }}</p>
                                </div>
                                <div>
                                    <span class="text-slate-600">Processo:</span>
                                    <p class="font-medium text-slate-900">{{ $processo->numero_processo }}</p>
                                </div>
                            </div>
                        </div>

                        {{-- Período de Execução --}}
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    Data Início
                                </label>
                                <input type="date" 
                                       name="data_inicio" 
                                       value="{{ date('Y-m-d') }}"
                                        @if(!auth('interno')->user()?->isAdmin()) min="{{ now()->toDateString() }}" @endif
                                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">
                                    Data Fim
                                </label>
                                <input type="date" 
                                       name="data_fim" 
                                       value="{{ date('Y-m-d') }}"
                                        @if(!auth('interno')->user()?->isAdmin()) min="{{ now()->toDateString() }}" @endif
                                       class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm">
                            </div>
                        </div>

                        {{-- Tipos de Ação --}}
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Tipos de Ação <span class="text-red-500">*</span>
                            </label>
                            <select name="tipos_acao_ids[]" 
                                    id="tipos-acao-select"
                                    class="w-full" 
                                    multiple="multiple" 
                                    required>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Digite para pesquisar tipos de ação</p>
                        </div>

                        {{-- Técnicos --}}
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Técnicos Responsáveis <span class="text-red-500">*</span>
                            </label>
                            <select name="tecnicos_ids[]" 
                                    id="tecnicos-select"
                                    class="w-full" 
                                    multiple="multiple" 
                                    required>
                            </select>
                            <p class="mt-1 text-xs text-slate-500">Digite para pesquisar técnicos</p>
                        </div>

                        {{-- Observações --}}
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">
                                Observações
                            </label>
                            <textarea name="observacoes" 
                                      rows="3"
                                      placeholder="Observações sobre a ordem de serviço..."
                                      class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent text-sm resize-none"></textarea>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="bg-slate-50 px-6 py-4 flex items-center justify-end gap-3">
                        <button type="button" 
                                @click="modalOrdemServico = false"
                                class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-purple-600 rounded-lg hover:bg-purple-700 transition-colors flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Criar Ordem de Serviço
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Alertas --}}
    <div x-show="modalAlertas" 
         x-cloak
         class="fixed inset-0 z-50 overflow-y-auto" 
         aria-labelledby="modal-title" 
         role="dialog" 
         aria-modal="true">
        <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
            {{-- Overlay --}}
            <div x-show="modalAlertas" 
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0"
                 x-transition:enter-end="opacity-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="fixed inset-0 bg-slate-900 bg-opacity-75 transition-opacity" 
                 @click="modalAlertas = false"></div>

            {{-- Modal --}}
            <div x-show="modalAlertas"
                 x-transition:enter="ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave="ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                 class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full">
                
                {{-- Header --}}
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            Alertas do Processo
                        </h3>
                        <button type="button" 
                                @click="modalAlertas = false" 
                                class="text-white hover:text-slate-200 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                </div>

                {{-- Body --}}
                <div class="px-6 py-6">
                    {{-- Form Criar Alerta --}}
                    <form method="POST" action="{{ route('admin.estabelecimentos.processos.alertas.criar', [$estabelecimento->id, $processo->id]) }}" class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
                        @csrf
                        <h4 class="text-sm font-semibold text-slate-900 mb-3">Criar Novo Alerta</h4>

                        <div class="mb-3 bg-yellow-50 border border-yellow-200 rounded-lg p-3">
                            <p class="text-sm text-yellow-800">
                                Este alerta é destinado à empresa (usuários externos vinculados ao estabelecimento deste processo) e não aos usuários internos da Vigilância Sanitária.
                            </p>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                            <div class="md:col-span-2">
                                <label class="block text-xs font-medium text-slate-700 mb-1">Descrição *</label>
                                <input type="text" 
                                       name="descricao" 
                                       required
                                       maxlength="500"
                                       placeholder="Ex: Verificar documentação pendente"
                                       class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-700 mb-1">Data do Alerta *</label>
                                <input type="date" 
                                       name="data_alerta" 
                                       required
                                       min="{{ date('Y-m-d') }}"
                                       class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                            </div>
                        </div>
                        
                        <div class="mt-3 flex justify-end">
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 transition-colors flex items-center gap-2">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                                </svg>
                                Adicionar Alerta
                            </button>
                        </div>
                    </form>

                    {{-- Lista de Alertas --}}
                    <div class="space-y-3 max-h-96 overflow-y-auto">
                        @forelse($alertas as $alerta)
                        <div class="border rounded-lg p-4 {{ $alerta->isVencido() ? 'bg-red-50 border-red-200' : ($alerta->isProximo() ? 'bg-orange-50 border-orange-200' : 'bg-white border-slate-200') }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-2">
                                        @if($alerta->status === 'pendente')
                                            @if($alerta->isVencido())
                                                <span class="px-2 py-0.5 bg-red-100 text-red-700 text-xs font-semibold rounded-full">Vencido</span>
                                            @elseif($alerta->isProximo())
                                                <span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs font-semibold rounded-full">Próximo</span>
                                            @else
                                                <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs font-semibold rounded-full">Pendente</span>
                                            @endif
                                        @elseif($alerta->status === 'visualizado')
                                            <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 text-xs font-semibold rounded-full">Visualizado</span>
                                        @else
                                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs font-semibold rounded-full">Concluído</span>
                                        @endif
                                        
                                        <span class="text-xs text-slate-500">
                                            <svg class="w-3 h-3 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            {{ $alerta->data_alerta->format('d/m/Y') }}
                                        </span>
                                    </div>
                                    
                                    <p class="text-sm text-slate-900 mb-1">{{ $alerta->descricao }}</p>
                                    
                                    <p class="text-xs text-slate-500">
                                        Criado por {{ $alerta->usuarioCriador->nome }} • {{ $alerta->created_at->diffForHumans() }}
                                    </p>
                                </div>
                                
                                <div class="flex items-center gap-1">
                                    @if($alerta->status === 'pendente')
                                        <form method="POST" action="{{ route('admin.estabelecimentos.processos.alertas.visualizar', [$estabelecimento->id, $processo->id, $alerta->id]) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" 
                                                    title="Marcar como visualizado"
                                                    class="p-1.5 text-yellow-600 hover:bg-yellow-100 rounded transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                    
                                    @if($alerta->status !== 'concluido')
                                        <form method="POST" action="{{ route('admin.estabelecimentos.processos.alertas.concluir', [$estabelecimento->id, $processo->id, $alerta->id]) }}" class="inline">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" 
                                                    title="Marcar como concluído"
                                                    class="p-1.5 text-green-600 hover:bg-green-100 rounded transition-colors">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                            </button>
                                        </form>
                                    @endif
                                    
                                    <form method="POST" action="{{ route('admin.estabelecimentos.processos.alertas.excluir', [$estabelecimento->id, $processo->id, $alerta->id]) }}" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir este alerta?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" 
                                                title="Excluir alerta"
                                                class="p-1.5 text-red-600 hover:bg-red-100 rounded transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        @empty
                        <div class="text-center py-8 text-slate-500">
                            <svg class="w-12 h-12 mx-auto mb-3 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <p class="text-sm">Nenhum alerta cadastrado</p>
                        </div>
                        @endforelse
                    </div>
                </div>

                {{-- Footer --}}
                <div class="bg-slate-50 px-6 py-4 flex justify-end">
                    <button type="button" 
                            @click="modalAlertas = false"
                            class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                        Fechar
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Select2 CSS --}}
@push('styles')
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    /* Container do Select2 */
    .select2-container--default .select2-selection--multiple {
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        min-height: 42px;
        padding: 4px;
        background-color: #ffffff;
        transition: all 0.2s ease;
    }
    
    /* Estado de foco - borda roxa com sombra suave */
    .select2-container--default.select2-container--focus .select2-selection--multiple {
        border-color: #9333ea;
        box-shadow: 0 0 0 3px rgba(147, 51, 234, 0.1);
        background-color: #ffffff;
        outline: none;
    }
    
    /* Tags selecionadas - roxo com texto branco */
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #9333ea !important;
        border: none !important;
        color: #ffffff !important;
        padding: 6px 10px !important;
        border-radius: 0.375rem !important;
        font-weight: 500 !important;
        text-decoration: none !important; /* Remove qualquer linha cortando */
        line-height: 1.5 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 6px !important;
        margin: 2px !important;
    }
    
    /* Garante que o texto dentro do chip não tenha decoração */
    .select2-container--default .select2-selection--multiple .select2-selection__choice__display {
        text-decoration: none !important;
        color: #ffffff !important;
        font-size: 0.875rem !important;
    }
    
    /* Botão de remover tag */
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
        color: #ffffff !important;
        background-color: transparent !important;
        border: none !important;
        font-size: 1.25rem !important;
        font-weight: bold !important;
        line-height: 1 !important;
        padding: 0 !important;
        margin: 0 !important;
        margin-right: 4px !important;
        text-decoration: none !important; /* Remove qualquer linha */
        cursor: pointer !important;
        transition: all 0.2s ease !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        width: 16px !important;
        height: 16px !important;
        border-radius: 50% !important;
    }
    
    .select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover {
        color: #fca5a5 !important;
        background-color: rgba(255, 255, 255, 0.2) !important;
        text-decoration: none !important;
        transform: scale(1.1);
    }
    
    /* Dropdown */
    .select2-dropdown {
        border: 1px solid #d1d5db;
        border-radius: 0.5rem;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    }
    
    /* Opções no dropdown - estado normal */
    .select2-container--default .select2-results__option {
        padding: 8px 12px;
        transition: all 0.15s ease;
    }
    
    /* Opções destacadas (hover/foco) - CORREÇÃO DE ACESSIBILIDADE */
    .select2-container--default .select2-results__option--highlighted[aria-selected] {
        background-color: #f3e8ff !important; /* Roxo muito claro */
        color: #581c87 !important; /* Roxo escuro para alto contraste */
        font-weight: 500;
    }
    
    /* Opções já selecionadas */
    .select2-container--default .select2-results__option[aria-selected="true"] {
        background-color: #ede9fe;
        color: #6b21a8;
    }
    
    /* Campo de busca dentro do select */
    .select2-container--default .select2-search--inline .select2-search__field {
        color: #1f2937;
        font-size: 0.875rem;
    }
    
    .select2-container--default .select2-search--inline .select2-search__field::placeholder {
        color: #9ca3af;
    }
    
    /* Placeholder quando vazio */
    .select2-container--default .select2-selection--multiple .select2-selection__placeholder {
        color: #9ca3af;
    }
    
    /* Mensagem "Nenhum resultado" */
    .select2-container--default .select2-results__option--no-results {
        color: #6b7280;
        font-style: italic;
    }
</style>
@endpush

{{-- Modal Atividades do Estabelecimento (somente visualização) --}}
<div id="modal-atividades-estab" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="document.getElementById('modal-atividades-estab').classList.add('hidden')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden" onclick="event.stopPropagation()">
            <div class="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-blue-50 to-indigo-50 flex items-center justify-between">
                <div>
                    <h3 class="text-lg font-semibold text-slate-900">Atividades Econômicas</h3>
                    <p class="text-xs text-slate-500">{{ $estabelecimento->nome_fantasia ?? $estabelecimento->razao_social }}</p>
                </div>
                <div class="flex items-center gap-2">
                    @php
                        $competenciaEstab = $estabelecimento->isCompetenciaEstadual() ? 'Estadual' : 'Municipal';
                        $corCompetencia = $competenciaEstab === 'Estadual' ? 'bg-indigo-100 text-indigo-700' : 'bg-teal-100 text-teal-700';
                    @endphp
                    <span class="px-2.5 py-1 rounded-full text-xs font-bold {{ $corCompetencia }}">{{ $competenciaEstab }}</span>
                    <button type="button" onclick="document.getElementById('modal-atividades-estab').classList.add('hidden')" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>
            <div class="p-6 overflow-y-auto" style="max-height: calc(80vh - 120px);">
                @if($estabelecimento->temAtividadesManuais())
                <div class="mb-4 flex items-start gap-2 p-3 rounded-lg bg-amber-50 border border-amber-200">
                    <svg class="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    <p class="text-xs text-amber-800">
                        <strong>Atenção:</strong> este estabelecimento possui
                        <strong>{{ $estabelecimento->getAtividadesManuais()->count() }} atividade(s) manual(is)</strong>
                        inserida(s) pela Vigilância Sanitária ou pelo estabelecimento que <strong>não constam no CNPJ</strong> da Receita Federal.
                    </p>
                </div>
                @endif
                @php
                    $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
                    $cnaePrincipal = $estabelecimento->cnae_fiscal ? preg_replace('/[^0-9]/', '', $estabelecimento->cnae_fiscal) : null;
                    $cnaesSecundarios = $estabelecimento->cnaes_secundarios ?? [];

                    // Monta lista de todos os CNAEs (principal + secundários da Receita)
                    $todosCodigosReceita = collect();
                    if ($cnaePrincipal) {
                        $todosCodigosReceita->push(['codigo' => $cnaePrincipal, 'descricao' => $estabelecimento->cnae_fiscal_descricao ?? '', 'tipo' => 'principal']);
                    }
                    foreach ($cnaesSecundarios as $cnae) {
                        $cod = preg_replace('/[^0-9]/', '', $cnae['codigo'] ?? '');
                        if ($cod && $cod !== $cnaePrincipal) {
                            $todosCodigosReceita->push(['codigo' => $cod, 'descricao' => $cnae['descricao'] ?? '', 'tipo' => 'secundaria']);
                        }
                    }

                    // Códigos exercidos
                    $codigosExercidos = collect($atividadesExercidas)->map(function($a) {
                        $codigo = is_array($a) ? ($a['codigo'] ?? '') : $a;
                        return preg_replace('/[^0-9A-Z_]/', '', $codigo);
                    })->filter()->values()->toArray();
                @endphp

                {{-- Atividades Exercidas (marcadas) --}}
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-2">
                    <svg class="w-3.5 h-3.5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Atividades Exercidas ({{ count($codigosExercidos) }})
                </h4>
                @if(!empty($codigosExercidos))
                <div class="space-y-1.5 mb-5">
                    @foreach($todosCodigosReceita as $cnaeInfo)
                    @php
                        $codLimpo = $cnaeInfo['codigo'];
                        $exercida = in_array($codLimpo, $codigosExercidos);
                        if (!$exercida) continue;
                        $codigoFmt = strlen($codLimpo) === 7 ? substr($codLimpo,0,2).'.'.substr($codLimpo,2,2).'-'.substr($codLimpo,4,1).'-'.substr($codLimpo,5,2) : $codLimpo;
                        $pactuacao = \App\Models\Pactuacao::where('cnae_codigo', $codLimpo)->where('ativo', true)->first();
                        $risco = $pactuacao ? strtolower($pactuacao->classificacao_risco ?? '') : '';
                        $compCnae = $pactuacao ? ($pactuacao->competencia ?? '') : '';
                    @endphp
                    <div class="flex items-center gap-3 p-2.5 rounded-lg border {{ $cnaeInfo['tipo'] === 'principal' ? 'border-blue-200 bg-blue-50' : 'border-green-200 bg-green-50' }}">
                        <svg class="w-4 h-4 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-mono font-bold text-slate-700">{{ $codigoFmt }}</span>
                                @if($cnaeInfo['tipo'] === 'principal')
                                <span class="px-1.5 py-0.5 text-[9px] font-bold bg-blue-200 text-blue-800 rounded">Principal</span>
                                @endif
                                @if($risco)
                                <span class="px-1.5 py-0.5 text-[9px] font-bold rounded {{ $risco === 'alto' ? 'bg-red-100 text-red-700' : ($risco === 'medio' || $risco === 'médio' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') }}">{{ ucfirst($risco) }}</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-slate-600 truncate">{{ $cnaeInfo['descricao'] }}</p>
                        </div>
                    </div>
                    @endforeach
                    {{-- Atividades exercidas que não estão na Receita (manuais) --}}
                    @foreach($codigosExercidos as $codExerc)
                    @php
                        if (in_array($codExerc, ['PROJ_ARQ', 'ANAL_ROT'])) continue;
                        $codExercLimpo = preg_replace('/[^0-9]/', '', $codExerc);
                        if ($todosCodigosReceita->contains('codigo', $codExercLimpo)) continue;
                        $codigoFmt = strlen($codExercLimpo) === 7 ? substr($codExercLimpo,0,2).'.'.substr($codExercLimpo,2,2).'-'.substr($codExercLimpo,4,1).'-'.substr($codExercLimpo,5,2) : $codExercLimpo;
                        $atividadeSalva = collect($atividadesExercidas)->first(function($a) use ($codExercLimpo) {
                            $cod = preg_replace('/[^0-9]/', '', is_array($a) ? ($a['codigo'] ?? '') : $a);
                            return $cod === $codExercLimpo;
                        });
                        $descExerc = is_array($atividadeSalva) ? ($atividadeSalva['descricao'] ?? '') : '';
                        if (!$descExerc) {
                            $atividadeModel = \App\Models\Atividade::where('codigo_cnae', $codExercLimpo)->first();
                            $descExerc = $atividadeModel->descricao ?? '';
                        }
                        $ehManual = is_array($atividadeSalva) && !empty($atividadeSalva['manual']);
                    @endphp
                    <div class="flex items-center gap-3 p-2.5 rounded-lg border border-orange-200 bg-orange-50">
                        <svg class="w-4 h-4 text-orange-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/></svg>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="text-xs font-mono font-bold text-slate-700">{{ $codigoFmt }}</span>
                                <span class="px-1.5 py-0.5 text-[9px] font-bold bg-green-100 text-green-700 rounded">Manual</span>
                                @if(!$ehManual)
                                <span class="px-1.5 py-0.5 text-[9px] font-bold bg-orange-200 text-orange-800 rounded">Fora do CNPJ</span>
                                @endif
                                @php
                                    $pactuacaoManual = \App\Models\Pactuacao::where('cnae_codigo', $codExercLimpo)->where('ativo', true)->first();
                                    $riscoManual = $pactuacaoManual ? strtolower($pactuacaoManual->classificacao_risco ?? '') : '';
                                @endphp
                                @if($riscoManual)
                                <span class="px-1.5 py-0.5 text-[9px] font-bold rounded {{ $riscoManual === 'alto' ? 'bg-red-100 text-red-700' : ($riscoManual === 'medio' || $riscoManual === 'médio' ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700') }}">{{ ucfirst($riscoManual) }}</span>
                                @endif
                            </div>
                            <p class="text-[11px] text-slate-600 truncate">{{ $descExerc ?: $codExerc }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Atividades NÃO exercidas (na Receita mas não marcadas) --}}
                @php
                    $naoExercidas = $todosCodigosReceita->filter(function($cnae) use ($codigosExercidos) {
                        return !in_array($cnae['codigo'], $codigosExercidos);
                    });
                @endphp
                @if($naoExercidas->count() > 0)
                <h4 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-2 flex items-center gap-2">
                    <svg class="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    Na Receita mas não exercidas ({{ $naoExercidas->count() }})
                </h4>
                <div class="space-y-1.5">
                    @foreach($naoExercidas as $cnaeInfo)
                    @php
                        $codLimpo = $cnaeInfo['codigo'];
                        $codigoFmt = strlen($codLimpo) === 7 ? substr($codLimpo,0,2).'.'.substr($codLimpo,2,2).'-'.substr($codLimpo,4,1).'-'.substr($codLimpo,5,2) : $codLimpo;
                    @endphp
                    <div class="flex items-center gap-3 p-2.5 rounded-lg border border-slate-200 bg-slate-50 opacity-60">
                        <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        <div class="flex-1 min-w-0">
                            <span class="text-xs font-mono font-medium text-slate-500">{{ $codigoFmt }}</span>
                            <p class="text-[11px] text-slate-400 truncate">{{ $cnaeInfo['descricao'] }}</p>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Select2 JS --}}
@push('scripts')
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Inicializa Select2 para Tipos de Ação
    $('#tipos-acao-select').select2({
        ajax: {
            url: '{{ route("admin.ordens-servico.api.search-tipos-acao") }}',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term,
                    page: params.page || 1
                };
            },
            processResults: function (data) {
                return {
                    results: data.results.map(function(item) {
                        return {
                            id: item.id,
                            text: item.text,
                            codigo: item.codigo
                        };
                    }),
                    pagination: data.pagination
                };
            },
            cache: true
        },
        placeholder: 'Digite para pesquisar tipos de ação...',
        minimumInputLength: 0,
        allowClear: true,
        width: '100%',
        language: {
            inputTooShort: function() {
                return 'Digite para pesquisar...';
            },
            searching: function() {
                return 'Buscando...';
            },
            noResults: function() {
                return 'Nenhum resultado encontrado';
            },
            loadingMore: function() {
                return 'Carregando mais resultados...';
            }
        },
        templateResult: function(item) {
            if (item.loading) return item.text;
            
            var $result = $('<div class="py-2">' +
                '<div class="font-medium text-gray-900">' + item.text + '</div>' +
                (item.codigo ? '<div class="text-xs text-gray-500">Código: ' + item.codigo + '</div>' : '') +
                '</div>');
            return $result;
        },
        templateSelection: function(item) {
            return item.text;
        }
    });

    // Inicializa Select2 para Técnicos
    $('#tecnicos-select').select2({
        ajax: {
            url: '{{ route("admin.ordens-servico.api.search-tecnicos") }}',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    q: params.term,
                    page: params.page || 1
                };
            },
            processResults: function (data) {
                return {
                    results: data.results.map(function(item) {
                        return {
                            id: item.id,
                            text: item.text,
                            email: item.email,
                            nivel: item.nivel
                        };
                    }),
                    pagination: data.pagination
                };
            },
            cache: true
        },
        placeholder: 'Digite para pesquisar técnicos...',
        minimumInputLength: 0,
        allowClear: true,
        width: '100%',
        language: {
            inputTooShort: function() {
                return 'Digite para pesquisar...';
            },
            searching: function() {
                return 'Buscando...';
            },
            noResults: function() {
                return 'Nenhum resultado encontrado';
            },
            loadingMore: function() {
                return 'Carregando mais resultados...';
            }
        },
        templateResult: function(item) {
            if (item.loading) return item.text;
            
            var $result = $('<div class="py-2">' +
                '<div class="font-medium text-gray-900">' + item.text + '</div>' +
                (item.email ? '<div class="text-xs text-gray-500">' + item.email + '</div>' : '') +
                '</div>');
            return $result;
        },
        templateSelection: function(item) {
            return item.text;
        }
    });

    // Carrega dados iniciais quando o modal é aberto
    const modalOrdemServico = document.querySelector('[x-show="modalOrdemServico"]');
    if (modalOrdemServico) {
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'style') {
                    const isVisible = !modalOrdemServico.style.display || modalOrdemServico.style.display !== 'none';
                    if (isVisible) {
                        // Trigger para carregar dados iniciais
                        $('#tipos-acao-select').select2('open');
                        $('#tipos-acao-select').select2('close');
                        $('#tecnicos-select').select2('open');
                        $('#tecnicos-select').select2('close');
                    }
                }
            });
        });
        observer.observe(modalOrdemServico, { attributes: true });
    }
});

// ========================================
// AJAX para Aprovação/Rejeição de Documentos
// ========================================
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    const atualizarDocumentoUI = (docId, status) => {
        const item = document.querySelector(`.documento-item[data-doc-id="${docId}"]`);
        if (!item) return;

        item.dataset.status = status;

        item.classList.remove('border-l-red-500', 'border-l-green-500', 'border-l-orange-500', 'border-l-gray-300');
        if (status === 'aprovado') item.classList.add('border-l-green-500');
        if (status === 'rejeitado') item.classList.add('border-l-red-500');
        if (status === 'pendente') item.classList.add('border-l-orange-500');

        const badge = item.querySelector('.documento-status-badge');
        if (badge) {
            badge.classList.remove('bg-gray-100', 'text-gray-600');
            badge.classList.add('bg-gray-100', 'text-gray-600');
            if (status === 'pendente') {
                badge.innerHTML = '<i class="far fa-clock" style="font-size: 10px;"></i> Pendente';
            } else if (status === 'aprovado') {
                badge.innerHTML = '<i class="fas fa-check" style="font-size: 10px;"></i> Aprovado';
            } else if (status === 'rejeitado') {
                badge.innerHTML = '<i class="fas fa-times" style="font-size: 10px;"></i> Rejeitado';
            }
        }

        const motivo = item.querySelector('.documento-motivo');
        if (motivo) {
            if (status === 'rejeitado') {
                motivo.classList.remove('hidden');
            } else {
                motivo.classList.add('hidden');
            }
        }

        const btnAprovar = item.querySelector('.js-doc-aprovar');
        const btnRejeitar = item.querySelector('.btn-doc-rejeitar');
        const btnRevalidar = item.querySelector('.btn-doc-revalidar');

        if (status === 'pendente') {
            if (btnAprovar) btnAprovar.classList.remove('hidden');
            if (btnRejeitar) btnRejeitar.classList.remove('hidden');
            if (btnRevalidar) btnRevalidar.classList.add('hidden');
        } else {
            if (btnAprovar) btnAprovar.classList.add('hidden');
            if (btnRejeitar) btnRejeitar.classList.add('hidden');
            if (btnRevalidar) btnRevalidar.classList.remove('hidden');
        }
    };

    window.atualizarDocumentoUI = atualizarDocumentoUI;

    const atualizarRespostaUI = (respostaItem, status) => {
        if (!respostaItem) return;
        respostaItem.dataset.status = status;

        const badge = respostaItem.querySelector('.resposta-status-badge');
        if (badge) {
            badge.textContent = status === 'pendente' ? 'Pendente' : (status === 'aprovado' ? 'Aprovado' : 'Rejeitado');
            badge.classList.remove('bg-green-100', 'text-green-700', 'bg-red-100', 'text-red-700', 'bg-yellow-100', 'text-yellow-700');
            if (status === 'pendente') badge.classList.add('bg-yellow-100', 'text-yellow-700');
            if (status === 'aprovado') badge.classList.add('bg-green-100', 'text-green-700');
            if (status === 'rejeitado') badge.classList.add('bg-red-100', 'text-red-700');
        }

        const icon = respostaItem.querySelector('.resposta-status-icon');
        if (icon) {
            icon.classList.remove('bg-green-100', 'bg-red-100', 'bg-yellow-100');
            if (status === 'pendente') icon.classList.add('bg-yellow-100');
            if (status === 'aprovado') icon.classList.add('bg-green-100');
            if (status === 'rejeitado') icon.classList.add('bg-red-100');
        }

        const svg = respostaItem.querySelector('.resposta-status-svg');
        if (svg) {
            svg.classList.remove('text-green-600', 'text-red-600', 'text-yellow-600');
            if (status === 'pendente') svg.classList.add('text-yellow-600');
            if (status === 'aprovado') svg.classList.add('text-green-600');
            if (status === 'rejeitado') svg.classList.add('text-red-600');
        }

        const path = respostaItem.querySelector('.resposta-status-path');
        if (path) {
            const d = status === 'pendente'
                ? 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'
                : (status === 'aprovado'
                    ? 'M5 13l4 4L19 7'
                    : 'M6 18L18 6M6 6l12 12');
            path.setAttribute('d', d);
        }

        const actions = respostaItem.querySelector('.resposta-actions');
        if (actions) {
            if (status === 'pendente') {
                actions.classList.remove('hidden');
            } else {
                actions.classList.add('hidden');
            }
        }

        const btnRevalidar = respostaItem.querySelector('.btn-revalidar-resposta');
        if (btnRevalidar) {
            if (status === 'pendente') btnRevalidar.classList.add('hidden');
            else btnRevalidar.classList.remove('hidden');
        }
    };

    document.querySelectorAll('.js-doc-aprovar').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const docId = this.getAttribute('data-doc-id');
            fetch(this.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: new FormData(this)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    mostrarNotificacao(data.message || 'Erro ao aprovar documento', 'error');
                    return;
                }
                atualizarDocumentoUI(docId, 'aprovado');
                window.dispatchEvent(new CustomEvent('documento-avaliado', {
                    detail: { docId: parseInt(docId), status: 'aprovado' }
                }));
                mostrarNotificacao('Documento aprovado com sucesso!', 'success');
            })
            .catch(() => mostrarNotificacao('Erro ao aprovar documento', 'error'));
        });
    });

    document.addEventListener('click', function(event) {
        const btnDoc = event.target.closest('.btn-doc-revalidar');
        if (btnDoc) {
            event.preventDefault();
            if (!confirm('Deseja revalidar este documento? Ele voltara para o status Pendente.')) return;

            const url = btnDoc.getAttribute('data-revalidar-url');
            const docId = btnDoc.getAttribute('data-doc-id');
            if (!url || !docId) return;

            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        mostrarNotificacao(data.message || 'Erro ao revalidar documento', 'error');
                        return;
                    }
                    atualizarDocumentoUI(docId, 'pendente');
                    mostrarNotificacao('Documento revalidado. Voltou para pendente.', 'success');
                })
                .catch(() => mostrarNotificacao('Erro ao revalidar documento', 'error'));
            return;
        }

        const btnResposta = event.target.closest('.btn-revalidar-resposta');
        if (btnResposta) {
            event.preventDefault();
            if (!confirm('Revalidar esta resposta? Ela voltara para pendente.')) return;

            const url = btnResposta.getAttribute('data-revalidar-url');
            if (!url) return;

            fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        mostrarNotificacao(data.message || 'Erro ao revalidar resposta', 'error');
                        return;
                    }
                    const respostaItem = btnResposta.closest('.resposta-item');
                    atualizarRespostaUI(respostaItem, 'pendente');
                    mostrarNotificacao('Resposta revalidada. Voltou para pendente.', 'success');
                })
                .catch(() => mostrarNotificacao('Erro ao revalidar resposta', 'error'));
        }
    });

    document.addEventListener('submit', function(event) {
        const form = event.target.closest('.js-doc-rejeitar-form');
        if (!form) return;

        event.preventDefault();
        const actionUrl = form.action;
        const docId = actionUrl.match(/documentos\/(\d+)\/rejeitar/);
        const docIdValue = docId ? docId[1] : null;

        fetch(actionUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: new FormData(form)
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                mostrarNotificacao(data.message || 'Erro ao rejeitar documento', 'error');
                return;
            }
            if (docIdValue) atualizarDocumentoUI(docIdValue, 'rejeitado');
            window.dispatchEvent(new CustomEvent('fechar-modal-rejeitar'));
            if (docIdValue) {
                window.dispatchEvent(new CustomEvent('documento-avaliado', {
                    detail: { docId: parseInt(docIdValue), status: 'rejeitado' }
                }));
            }
            mostrarNotificacao('Documento rejeitado com sucesso!', 'success');
        })
        .catch(() => mostrarNotificacao('Erro ao rejeitar documento', 'error'));
    });

    document.querySelectorAll('.js-resposta-aprovar').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const respostaId = this.getAttribute('data-resposta-id');
            fetch(this.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: new FormData(this)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    mostrarNotificacao(data.message || 'Erro ao aprovar resposta', 'error');
                    return;
                }
                const item = document.querySelector(`.resposta-item[data-resposta-id="${respostaId}"]`);
                atualizarRespostaUI(item, 'aprovado');
                mostrarNotificacao('Resposta aprovada com sucesso!', 'success');
            })
            .catch(() => mostrarNotificacao('Erro ao aprovar resposta', 'error'));
        });
    });

    document.querySelectorAll('.js-resposta-rejeitar').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const respostaId = this.getAttribute('data-resposta-id');
            fetch(this.action, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
                body: new FormData(this)
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    mostrarNotificacao(data.message || 'Erro ao rejeitar resposta', 'error');
                    return;
                }
                const item = document.querySelector(`.resposta-item[data-resposta-id="${respostaId}"]`);
                atualizarRespostaUI(item, 'rejeitado');
                if (item) {
                    const box = item.querySelector('.resposta-rejeicao-box');
                    if (box) {
                        box.classList.add('hidden');
                        const textarea = box.querySelector('textarea');
                        if (textarea) textarea.value = '';
                    }
                }
                mostrarNotificacao('Resposta rejeitada com sucesso!', 'success');
            })
            .catch(() => mostrarNotificacao('Erro ao rejeitar resposta', 'error'));
        });
    });
});

// Função para mostrar notificações
function mostrarNotificacao(mensagem, tipo = 'success') {
    const cor = tipo === 'success' ? 'green' : 'red';
    const icone = tipo === 'success' 
        ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>'
        : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>';
    
    const notificacao = document.createElement('div');
    notificacao.className = `fixed top-4 right-4 z-[9999] bg-${cor}-50 border-l-4 border-${cor}-500 p-4 rounded-lg shadow-lg transform transition-all duration-300 translate-x-full`;
    notificacao.innerHTML = `
        <div class="flex items-center">
            <svg class="w-5 h-5 text-${cor}-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                ${icone}
            </svg>
            <p class="text-sm font-medium text-${cor}-800">${mensagem}</p>
        </div>
    `;
    
    document.body.appendChild(notificacao);
    
    // Anima entrada
    setTimeout(() => {
        notificacao.classList.remove('translate-x-full');
    }, 10);
    
    // Remove após 3 segundos
    setTimeout(() => {
        notificacao.classList.add('translate-x-full');
        setTimeout(() => {
            notificacao.remove();
        }, 300);
    }, 3000);
}

function scrollParaDocumento(docId) {
    const el = document.getElementById('documento-digital-' + docId);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('ring-2', 'ring-amber-400', 'bg-amber-50/60', 'shadow-md');
        setTimeout(() => { el.classList.remove('ring-2', 'ring-amber-400', 'bg-amber-50/60', 'shadow-md'); }, 3000);
    }
}
</script>
@endpush

{{-- Modal Adicionar Unidade (Admin/Gestor/Tecnico) --}}
@if(($tipoProcessoTemUnidades ?? false) && in_array($processo->status, ['aberto', 'parado']))
<div id="modal-nova-unidade-admin" class="hidden fixed inset-0 z-50 overflow-y-auto">
    <div class="fixed inset-0 bg-black bg-opacity-50 backdrop-blur-sm" onclick="document.getElementById('modal-nova-unidade-admin').classList.add('hidden')"></div>
    <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" onclick="event.stopPropagation()">
            <div class="px-6 py-4 border-b border-slate-200 bg-gradient-to-r from-violet-50 to-purple-50">
                <h3 class="text-lg font-semibold text-slate-900">Adicionar Nova Unidade</h3>
                <p class="text-xs text-slate-500 mt-1">Selecione a unidade que deseja adicionar ao processo</p>
            </div>
            @if(isset($unidadesDisponiveis) && $unidadesDisponiveis->count() > 0)
            <form action="{{ route('admin.estabelecimentos.processos.adicionar-unidade', [$estabelecimento->id, $processo->id]) }}" method="POST" class="p-6">
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
                    <label for="nome_unidade_admin" class="block text-sm font-medium text-slate-700 mb-1">Nome da unidade <span class="text-slate-400 font-normal">(opcional)</span></label>
                    <input type="text" id="nome_unidade_admin" name="nome_unidade" maxlength="255"
                           placeholder="Ex.: UTI Pediátrica, PS Infantil, Centro Cirúrgico"
                           class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500">
                    <p class="text-xs text-slate-500 mt-1">Se deixar em branco, será usado o nome do tipo selecionado.</p>
                </div>
                <div class="flex items-center gap-3 mt-6 pt-4 border-t border-slate-200">
                    <button type="submit" class="px-4 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition">
                        Adicionar Unidade
                    </button>
                    <button type="button" onclick="document.getElementById('modal-nova-unidade-admin').classList.add('hidden')"
                            class="px-4 py-2 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-200 transition">
                        Cancelar
                    </button>
                </div>
            </form>
            @else
            <div class="p-6 text-center">
                <p class="text-sm text-slate-600">Nenhuma unidade disponível.</p>
                <button type="button" onclick="document.getElementById('modal-nova-unidade-admin').classList.add('hidden')"
                        class="mt-4 px-4 py-2 bg-slate-100 text-slate-700 text-sm font-medium rounded-lg hover:bg-slate-200 transition">
                    Fechar
                </button>
            </div>
            @endif
        </div>
    </div>
</div>
@endif

@endsection
