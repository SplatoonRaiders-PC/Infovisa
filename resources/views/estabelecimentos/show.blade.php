@extends('layouts.admin')

@section('title', 'Detalhes do Estabelecimento')
@section('page-title', 'Detalhes do Estabelecimento')

@section('content')
<div class="space-y-4">
    {{-- Header com botões --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 px-4 sm:px-5 py-4 flex flex-col md:flex-row md:items-center justify-between gap-4 bg-gradient-to-r from-slate-50 via-white to-white">
        <div class="flex items-center gap-3 min-w-0">
            <a href="{{ route('admin.estabelecimentos.index') }}" title="Voltar"
               class="w-9 h-9 flex-shrink-0 inline-flex items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-500 hover:text-slate-800 hover:bg-slate-50 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div class="w-11 h-11 flex-shrink-0 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center shadow-md shadow-blue-500/25">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
            </div>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">{{ $estabelecimento->tipo_pessoa === 'juridica' ? 'Pessoa Jurídica' : 'Pessoa Física' }}</p>
                <h2 class="text-xl font-bold text-slate-900 tracking-tight truncate">{{ $estabelecimento->nome_fantasia }}</h2>
                <p class="text-xs text-slate-500 tabular-nums">{{ $estabelecimento->documento_formatado }}</p>
            </div>
        </div>

        {{-- Badge de Status --}}
        <div class="flex items-center gap-2 flex-wrap">
            @php
                $statusConfig = [
                    'pendente' => ['bg' => 'bg-yellow-50 ring-yellow-200', 'text' => 'text-yellow-800', 'dot' => 'bg-yellow-500', 'label' => 'Pendente'],
                    'aprovado' => ['bg' => 'bg-green-50 ring-green-200', 'text' => 'text-green-700', 'dot' => 'bg-green-500', 'label' => 'Aprovado'],
                    'rejeitado' => ['bg' => 'bg-red-50 ring-red-200', 'text' => 'text-red-700', 'dot' => 'bg-red-500', 'label' => 'Rejeitado'],
                    'arquivado' => ['bg' => 'bg-slate-100 ring-slate-200', 'text' => 'text-slate-700', 'dot' => 'bg-slate-400', 'label' => 'Arquivado'],
                ];
                $config = $statusConfig[$estabelecimento->status] ?? $statusConfig['pendente'];
            @endphp
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ring-1 ring-inset {{ $config['bg'] }} {{ $config['text'] }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $config['dot'] }}"></span>
                {{ $config['label'] }}
            </span>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold ring-1 ring-inset {{ $estabelecimento->ativo ? 'bg-green-50 text-green-700 ring-green-200' : 'bg-red-50 text-red-700 ring-red-200' }}">
                <span class="w-1.5 h-1.5 rounded-full {{ $estabelecimento->ativo ? 'bg-green-500' : 'bg-red-500' }}"></span>
                {{ $estabelecimento->ativo ? 'Ativo' : 'Inativo' }}
            </span>
            @if($estabelecimento->produtor_rural)
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold bg-green-50 text-green-700 ring-1 ring-inset ring-green-200">
                🌾 Produtor Rural
            </span>
            @endif
            @if($estabelecimento->is_unidade_movel)
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-fuchsia-50 text-fuchsia-700 ring-1 ring-inset ring-fuchsia-200">
                Unidade Móvel
                @if($estabelecimento->status_unidade_movel === 'pendente')
                    · Aguardando aprovação
                @endif
            </span>
            @endif
        </div>
    </div>

    {{-- Alerta: Solicitação de Unidade Móvel pendente (apenas para estabelecimento já aprovado) --}}
    @if($estabelecimento->status_unidade_movel === 'pendente' && $estabelecimento->status === 'aprovado')
    <div class="bg-fuchsia-50 border border-l-4 border-fuchsia-400 px-4 py-3 rounded-xl">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-fuchsia-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div class="ml-3 flex-1">
                <p class="text-sm font-medium text-fuchsia-800">
                    Solicitação de credenciamento de Unidade Móvel pendente
                </p>
                <p class="mt-1 text-sm text-fuchsia-700">
                    Tipo de unidade: <strong>{{ $estabelecimento->tipo_unidade_movel ?? '—' }}</strong>.
                    Revise os municípios de atuação e aprove ou rejeite a solicitação nas ações ao lado.
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- Alerta de Status Pendente/Rejeitado --}}
    @if($estabelecimento->status === 'pendente')
    <div class="bg-yellow-50 border border-l-4 border-yellow-400 px-4 py-3 rounded-xl">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-3 flex-1">
                <p class="text-sm font-medium text-yellow-800">
                    Este estabelecimento está aguardando aprovação
                </p>
                <p class="mt-1 text-sm text-yellow-700">
                    Analise os dados e aprove ou rejeite o cadastro.
                </p>
            </div>
        </div>
    </div>
    @elseif($estabelecimento->status === 'rejeitado')
    <div class="bg-red-50 border border-l-4 border-red-400 px-4 py-3 rounded-xl">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-3 flex-1">
                <p class="text-sm font-medium text-red-800">
                    Este estabelecimento foi rejeitado
                </p>
                @if($estabelecimento->motivo_rejeicao)
                <p class="mt-1 text-sm text-red-700">
                    <strong>Motivo:</strong> {{ $estabelecimento->motivo_rejeicao }}
                </p>
                @endif
                @if($estabelecimento->aprovadoPor)
                <p class="mt-1 text-xs text-red-600">
                    Rejeitado por {{ $estabelecimento->aprovadoPor->nome }} em {{ $estabelecimento->aprovado_em->format('d/m/Y H:i') }}
                </p>
                @endif
            </div>
        </div>
    </div>
    @elseif($estabelecimento->status === 'aprovado' && $estabelecimento->aprovadoPor)
    <div class="bg-green-50 border border-l-4 border-green-400 px-4 py-3 rounded-xl">
        <div class="flex items-start">
            <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-green-700">
                    Aprovado por <strong>{{ $estabelecimento->aprovadoPor->nome }}</strong> em {{ $estabelecimento->aprovado_em->format('d/m/Y H:i') }}
                </p>
            </div>
        </div>
    </div>
    @endif

    {{-- Layout de 2 Colunas --}}
    <style>
        @media (max-width: 768px) {
            .estabelecimento-container {
                flex-direction: column !important;
            }
            .estabelecimento-menu {
                width: 100% !important;
                min-width: unset !important;
            }
            .estabelecimento-menu-sticky {
                position: relative !important;
                top: 0 !important;
            }
        }
    </style>
    <div class="estabelecimento-container" style="display: flex; gap: 1.5rem;">
        {{-- Coluna Esquerda - Menu de Ações --}}
        <div class="estabelecimento-menu space-y-4" style="width: 280px; min-width: 280px;">
            <div class="estabelecimento-menu-sticky bg-white rounded-2xl shadow-sm border border-slate-200/80 p-4 sticky top-20">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-2 px-1">Ações</h3>
                <div class="space-y-0.5">
                    @if($estabelecimento->ativo)
                    {{-- Editar --}}
                    <a href="{{ route('admin.estabelecimentos.edit', $estabelecimento->id) }}" 
                       class="flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Editar Dados
                    </a>

                    {{-- Responsáveis (apenas para pessoa jurídica) --}}
                    @if($estabelecimento->tipo_pessoa === 'juridica')
                    <a href="{{ route('admin.estabelecimentos.responsaveis.index', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                        Responsáveis
                    </a>
                    @endif

                    {{-- Atividades --}}
                    <a href="{{ route('admin.estabelecimentos.atividades.edit', $estabelecimento->id) }}"
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
                        </svg>
                        Atividades
                    </a>

                    {{-- Documentos Obrigatórios (definição manual - vigilância municipal) --}}
                    @if($estabelecimento->usaDocumentosManuais())
                    <a href="{{ route('admin.estabelecimentos.documentos-manuais.edit', $estabelecimento->id) }}"
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-green-50 hover:text-green-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Documentos Obrigatórios
                        <span class="ml-auto text-[10px] px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-semibold">{{ $estabelecimento->documentosManuais()->count() }}</span>
                    </a>
                    @endif

                    {{-- Processos --}}
                    <a href="{{ route('admin.estabelecimentos.processos.index', $estabelecimento->id) }}" class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        Processos
                    </a>

                    {{-- Documentos --}}
                    <a href="{{ route('admin.estabelecimentos.documentos', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                        Documentos
                    </a>

                    {{-- Histórico --}}
                    <a href="{{ route('admin.estabelecimentos.historico', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Histórico
                    </a>

                    {{-- Usuários Vinculados --}}
                    <a href="{{ route('admin.estabelecimentos.usuarios.index', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                        </svg>
                        Usuários Vinculados
                    </a>

                    {{-- Municípios de Atuação (apenas para Unidade Móvel) --}}
                    @if($estabelecimento->is_unidade_movel)
                    <a href="{{ route('admin.estabelecimentos.municipios-atuacao', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-fuchsia-50 hover:text-fuchsia-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-fuchsia-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        <span class="flex-1 text-left">Municípios de Atuação</span>
                        <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-medium bg-fuchsia-100 text-fuchsia-700 rounded-full">
                            {{ $estabelecimento->municipiosAtuacao->count() }}
                        </span>
                    </a>
                    @endif

                    {{-- Equipamentos de Imagem (apenas para estabelecimentos que exigem) --}}
                    @if(isset($exigeEquipamentosRadiacao) && $exigeEquipamentosRadiacao)
                    <a href="{{ route('admin.estabelecimentos.equipamentos-radiacao.index', $estabelecimento->id) }}"
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-orange-50 hover:text-orange-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                        </svg>
                        <span class="flex-1 text-left">Equipamentos de Imagem</span>
                        <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-medium {{ $totalEquipamentosRadiacao > 0 ? 'bg-orange-100 text-orange-700' : 'bg-red-100 text-red-700' }} rounded-full">
                            {{ $totalEquipamentosRadiacao }}
                        </span>
                    </a>
                    @endif

                    <hr class="my-4">

                    {{-- Ações de Aprovação --}}
                    @if($estabelecimento->status === 'pendente')
                        <button onclick="document.getElementById('modal-aprovar').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-white bg-green-600 hover:bg-green-700 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Aprovar
                        </button>

                        <button onclick="document.getElementById('modal-rejeitar').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Rejeitar
                        </button>
                    @elseif($estabelecimento->status === 'rejeitado')
                        <button onclick="document.getElementById('modal-reiniciar').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-white bg-yellow-600 hover:bg-yellow-700 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                            </svg>
                            Reiniciar
                        </button>
                    @endif

                    {{-- Aprovação do módulo Unidade Móvel (solicitação de estabelecimento já aprovado) --}}
                    @if($estabelecimento->status_unidade_movel === 'pendente' && $estabelecimento->status === 'aprovado')
                        <hr class="my-4">
                        <p class="px-1 text-xs font-semibold text-fuchsia-700 uppercase tracking-wide">Solicitação de Unidade Móvel</p>
                        <button onclick="document.getElementById('modal-aprovar-um').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-white bg-fuchsia-600 hover:bg-fuchsia-700 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            Aprovar Unidade Móvel
                        </button>
                        <button onclick="document.getElementById('modal-rejeitar-um').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                            Rejeitar Unidade Móvel
                        </button>
                    @endif

                    @if(auth('interno')->user()->nivel_acesso->isAdmin())
                    <hr class="my-4">

                    {{-- Voltar para Pendente (apenas para aprovados sem processos) --}}
                    @if($estabelecimento->status === 'aprovado' && $estabelecimento->processos()->count() === 0)
                        <button onclick="document.getElementById('modal-voltar-pendente').classList.remove('hidden')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-orange-700 bg-orange-50 hover:bg-orange-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0019 16V8a1 1 0 00-1.6-.8l-5.333 4zM4.066 11.2a1 1 0 000 1.6l5.334 4A1 1 0 0011 16V8a1 1 0 00-1.6-.8l-5.334 4z"/>
                            </svg>
                            Voltar para Pendente
                        </button>
                    @endif

                    {{-- Alterar Competência (apenas para administradores) --}}
                    <button onclick="document.getElementById('modal-alterar-competencia').classList.remove('hidden')"
                            class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-white bg-{{ $competenciaEstadual ? 'purple' : 'blue' }}-600 hover:bg-{{ $competenciaEstadual ? 'purple' : 'blue' }}-700 rounded-lg transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                        </svg>
                        Alterar Competência
                    </button>

                    {{-- Desativar --}}
                    <button onclick="document.getElementById('modal-desativar').classList.remove('hidden')"
                            class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors group">
                        <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                        Desativar
                    </button>
                    @endif

                    @else
                    {{-- Estabelecimento Desativado - Mostrar apenas Histórico, Ativar e Excluir --}}
                    {{-- Histórico --}}
                    <a href="{{ route('admin.estabelecimentos.historico', $estabelecimento->id) }}" 
                       class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-medium text-slate-600 hover:bg-blue-50 hover:text-blue-700 rounded-lg transition-colors group">
                        <svg class="w-[18px] h-[18px] text-slate-400 group-hover:text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Histórico
                    </a>

                    @if(auth('interno')->user()->nivel_acesso->isAdmin())
                    <hr class="my-4">

                    <form action="{{ route('admin.estabelecimentos.ativar', $estabelecimento->id) }}" method="POST">
                        @csrf
                        <button type="submit"
                                onclick="return confirm('Tem certeza que deseja reativar este estabelecimento?')"
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-green-700 bg-green-50 hover:bg-green-100 rounded-lg transition-colors group">
                            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                            </svg>
                            Ativar
                        </button>
                    </form>
                    @endif
                    @endif

                    {{-- Excluir (apenas admin) --}}
                    @if(auth('interno')->user()->nivel_acesso->isAdmin())
                    <form action="{{ route('admin.estabelecimentos.destroy', $estabelecimento->id) }}" 
                          method="POST" 
                          onsubmit="return confirm('⚠️ ATENÇÃO!\n\nTem certeza que deseja EXCLUIR este estabelecimento?\n\nEsta ação é IRREVERSÍVEL e irá:\n- Remover todos os dados do estabelecimento\n- Remover processos, documentos e ordens de serviço\n- Desvincular responsáveis e usuários (sem excluí-los)\n\nDeseja continuar?');"
                          class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" 
                                class="w-full flex items-center gap-2.5 px-3 py-2 text-[13px] font-semibold text-red-700 bg-red-50 hover:bg-red-100 rounded-lg transition-colors group">
                            <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Excluir Estabelecimento
                        </button>
                    </form>
                    @endif
                </div>
            </div>
        </div>

        {{-- Coluna Direita - Dados do Estabelecimento --}}
        <div class="space-y-6 min-w-0" style="flex: 1;">
            {{-- Informações Gerais --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
                <div class="px-4 py-3 bg-gradient-to-r from-blue-50 to-blue-100 border-b border-slate-200">
                    <h3 class="text-xs font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Informações Gerais
                    </h3>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">{{ $estabelecimento->tipo_pessoa === 'juridica' ? 'Razão Social' : 'Nome Completo' }}</label>
                            <p class="text-xs font-medium text-slate-900">{{ $estabelecimento->nome_razao_social }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Nome Fantasia</label>
                            <p class="text-xs font-medium text-slate-900">{{ $estabelecimento->nome_fantasia ?? '-' }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">{{ $estabelecimento->tipo_pessoa === 'juridica' ? 'CNPJ' : 'CPF' }}</label>
                            <p class="text-xs font-mono text-slate-900">{{ $estabelecimento->documento_formatado }}</p>
                        </div>
                        
                        @if($estabelecimento->tipo_pessoa === 'fisica')
                        {{-- Campos específicos de Pessoa Física --}}
                        @if($estabelecimento->rg)
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">RG</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->rg }}</p>
                        </div>
                        @endif
                        @if($estabelecimento->orgao_emissor)
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Órgão Emissor</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->orgao_emissor }}</p>
                        </div>
                        @endif
                        @endif
                        
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Tipo de Setor</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->tipo_setor ? ucfirst($estabelecimento->tipo_setor->value) : '-' }}</p>
                        </div>
                        @if($estabelecimento->telefone)
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Telefone</label>
                            <p class="text-xs font-mono text-slate-900">{{ $estabelecimento->telefone }}</p>
                        </div>
                        @endif
                        @if($estabelecimento->email)
                        <div class="col-span-2">
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">E-mail</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->email }}</p>
                        </div>
                        @endif

                        {{-- Separador e Endereço --}}
                        <div class="col-span-2 mt-3 pt-3 border-t border-slate-200">
                            <h4 class="text-[10px] font-semibold text-slate-700 mb-2 flex items-center gap-1.5">
                                <svg class="w-3 h-3 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                Endereço
                            </h4>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Logradouro</label>
                            <p class="text-xs font-medium text-slate-900">{{ $estabelecimento->endereco }}, {{ $estabelecimento->numero }}</p>
                        </div>
                        @if($estabelecimento->complemento)
                        <div class="col-span-2">
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Complemento</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->complemento }}</p>
                        </div>
                        @endif
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Bairro</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->bairro }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Município</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->cidade }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Estado</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->estado }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">CEP</label>
                            <p class="text-xs font-mono text-slate-900">{{ $estabelecimento->cep }}</p>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Processos em Andamento --}}
            @if($processosAtivos->count() > 0 || $estabelecimento->status === 'aprovado' || $totalProcessos > 0)
            @php
                $statusProcessoConfig = [
                    'aberto'       => ['label' => 'Aberto',       'pill' => 'bg-blue-50 text-blue-700 ring-blue-200',       'dot' => 'bg-blue-500',   'bar' => 'from-blue-500 to-indigo-500'],
                    'em_andamento' => ['label' => 'Em andamento', 'pill' => 'bg-amber-50 text-amber-700 ring-amber-200',    'dot' => 'bg-amber-500',  'bar' => 'from-amber-400 to-orange-500'],
                    'em_analise'   => ['label' => 'Em análise',   'pill' => 'bg-violet-50 text-violet-700 ring-violet-200', 'dot' => 'bg-violet-500', 'bar' => 'from-violet-500 to-purple-500'],
                    'parado'       => ['label' => 'Parado',       'pill' => 'bg-red-50 text-red-700 ring-red-200',          'dot' => 'bg-red-500',    'bar' => 'from-red-500 to-rose-500'],
                ];
                $statusProcessoPadrao = ['label' => null, 'pill' => 'bg-slate-100 text-slate-700 ring-slate-200', 'dot' => 'bg-slate-400', 'bar' => 'from-slate-400 to-slate-500'];
                $resumoStatus = $processosAtivos->groupBy('status')->map->count();
            @endphp
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
                {{-- Cabeçalho --}}
                <div class="px-5 py-4 border-b border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-9 h-9 flex-shrink-0 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white flex items-center justify-center shadow-sm shadow-blue-500/30">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <h3 class="text-sm font-semibold text-slate-900 flex items-center gap-2">
                                Processos Ativos
                                <span class="inline-flex items-center justify-center min-w-[1.5rem] h-5 px-1.5 rounded-full bg-blue-600 text-white text-[11px] font-bold tabular-nums">{{ $totalProcessosAtivos }}</span>
                            </h3>
                            <p class="text-[11px] text-slate-500 mt-0.5">
                                @if($totalProcessosAtivos > $processosAtivos->count())
                                    Exibindo os {{ $processosAtivos->count() }} mais recentes ·
                                @endif
                                {{ $totalProcessos }} {{ $totalProcessos === 1 ? 'processo' : 'processos' }} no total
                            </p>
                        </div>
                    </div>
                    <a href="{{ route('admin.estabelecimentos.processos.index', $estabelecimento->id) }}"
                       class="inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 rounded-lg ring-1 ring-inset ring-blue-200 transition-colors self-start sm:self-auto">
                        Ver todos os processos
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                        </svg>
                    </a>
                </div>

                @if($processosAtivos->isEmpty())
                {{-- Estado vazio --}}
                <div class="px-5 py-10 text-center">
                    <div class="w-12 h-12 mx-auto rounded-2xl bg-slate-100 text-slate-400 flex items-center justify-center mb-3">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>
                    </div>
                    <p class="text-sm font-semibold text-slate-800">Nenhum processo ativo</p>
                    <p class="text-xs text-slate-500 mt-1">
                        {{ $totalProcessos > 0 ? 'Todos os processos deste estabelecimento estão arquivados ou concluídos.' : 'Este estabelecimento ainda não possui processos.' }}
                    </p>
                    <a href="{{ route('admin.estabelecimentos.processos.index', $estabelecimento->id) }}"
                       class="inline-flex items-center gap-1.5 mt-4 px-3.5 py-2 text-xs font-semibold text-white bg-blue-600 hover:bg-blue-700 rounded-lg shadow-sm transition-colors">
                        {{ $totalProcessos > 0 ? 'Ver histórico de processos' : 'Ir para processos' }}
                    </a>
                </div>
                @else
                {{-- Resumo por status --}}
                @if($resumoStatus->count() > 1)
                <div class="px-5 pt-4 flex flex-wrap gap-2">
                    @foreach($resumoStatus as $status => $qtd)
                        @php $cfg = $statusProcessoConfig[$status] ?? $statusProcessoPadrao; @endphp
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md text-[11px] font-medium ring-1 ring-inset {{ $cfg['pill'] }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $cfg['dot'] }}"></span>
                            {{ $cfg['label'] ?? ucfirst(str_replace('_', ' ', $status)) }}
                            <span class="font-bold tabular-nums">{{ $qtd }}</span>
                        </span>
                    @endforeach
                </div>
                @endif

                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 2xl:grid-cols-3 gap-4">
                    @foreach($processosAtivos as $processo)
                    @php
                        $cfg = $statusProcessoConfig[$processo->status] ?? $statusProcessoPadrao;
                        $diasAberto = (int) $processo->created_at->copy()->startOfDay()->diffInDays(now()->startOfDay());
                        $idadeTexto = $diasAberto === 0 ? 'Aberto hoje' : ($diasAberto === 1 ? 'Aberto há 1 dia' : "Aberto há {$diasAberto} dias");

                        $setorNome = $processo->setor_atual_nome;
                        $responsavel = $processo->responsavelAtual;
                        $iniciais = $responsavel
                            ? collect(explode(' ', trim($responsavel->nome)))->filter()->map(fn($p) => mb_substr($p, 0, 1))->take(2)->implode('')
                            : null;

                        $criadoPor = $processo->aberto_por_externo || (!$processo->usuario && $processo->usuarioExterno)
                            ? ($processo->usuarioExterno?->nome ? $processo->usuarioExterno->nome . ' (externo)' : 'Usuário externo')
                            : ($processo->usuario?->nome ?? 'Sistema');

                        $prazo = $processo->prazo_atribuicao;
                        $prazoDias = $prazo ? (int) now()->startOfDay()->diffInDays($prazo->copy()->startOfDay(), false) : null;
                        $prazoClasse = $prazo === null ? null : ($prazoDias < 0 ? 'bg-red-50 text-red-700 ring-red-200' : ($prazoDias <= 3 ? 'bg-amber-50 text-amber-700 ring-amber-200' : 'bg-slate-50 text-slate-600 ring-slate-200'));
                        $prazoTexto = $prazo === null ? null : ($prazoDias < 0 ? 'Prazo vencido há ' . abs($prazoDias) . ' dia(s)' : ($prazoDias === 0 ? 'Prazo vence hoje' : 'Prazo em ' . $prazo->format('d/m')));
                    @endphp
                    <a href="{{ route('admin.estabelecimentos.processos.show', [$estabelecimento->id, $processo->id]) }}"
                       class="group relative flex flex-col bg-white rounded-xl ring-1 ring-slate-200 hover:ring-blue-300 shadow-sm hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 overflow-hidden focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500">
                        {{-- Faixa de status --}}
                        <span class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r {{ $cfg['bar'] }}"></span>

                        <div class="p-4 pt-5 flex-1 flex flex-col gap-3">
                            {{-- Status + Idade --}}
                            <div class="flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-semibold ring-1 ring-inset {{ $cfg['pill'] }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $cfg['dot'] }} {{ $processo->status === 'parado' ? '' : 'animate-pulse' }}"></span>
                                    {{ $cfg['label'] ?? ucfirst(str_replace('_', ' ', $processo->status)) }}
                                </span>
                                <span class="inline-flex items-center gap-1 text-[11px] text-slate-400 whitespace-nowrap" title="Aberto em {{ $processo->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    {{ $idadeTexto }}
                                </span>
                            </div>

                            {{-- Tipo + Número --}}
                            <div>
                                <p class="text-xs font-semibold text-slate-600 leading-snug line-clamp-2" title="{{ $processo->tipo_nome }}">
                                    {{ $processo->tipo_nome }}
                                </p>
                                <p class="mt-1 text-xl font-bold text-slate-900 group-hover:text-blue-700 tabular-nums tracking-tight transition-colors">
                                    <span class="text-slate-300 font-semibold">nº</span> {{ $processo->numero_processo }}
                                </p>
                            </div>

                            {{-- Com quem está --}}
                            <div class="flex items-center gap-2.5 p-2 rounded-lg bg-slate-50 ring-1 ring-inset ring-slate-100">
                                @if($responsavel)
                                    <span class="w-7 h-7 flex-shrink-0 rounded-full bg-gradient-to-br from-slate-600 to-slate-800 text-white text-[10px] font-bold flex items-center justify-center uppercase">{{ $iniciais }}</span>
                                @else
                                    <span class="w-7 h-7 flex-shrink-0 rounded-full {{ $setorNome ? 'bg-slate-200 text-slate-500' : 'bg-amber-100 text-amber-600' }} flex items-center justify-center">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        </svg>
                                    </span>
                                @endif
                                <div class="min-w-0 leading-tight">
                                    <p class="text-[10px] text-slate-400 font-medium">Com quem está</p>
                                    @if($responsavel || $setorNome)
                                        <p class="text-xs font-semibold text-slate-800 truncate" title="{{ $responsavel?->nome }}">{{ $responsavel?->nome ?? $setorNome }}</p>
                                        @if($responsavel && $setorNome)
                                        <p class="text-[10px] text-slate-500 truncate" title="{{ $setorNome }}">{{ $setorNome }}</p>
                                        @endif
                                    @else
                                        <p class="text-xs font-semibold text-amber-700">Não atribuído</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Alertas contextuais --}}
                            @if($processo->status === 'parado' || $prazoTexto || $processo->alertas_pendentes_count > 0)
                            <div class="flex flex-wrap gap-1.5">
                                @if($processo->status === 'parado')
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-red-50 text-red-700 ring-1 ring-inset ring-red-200 max-w-full"
                                      title="{{ $processo->motivo_parada }}">
                                    <svg class="w-3 h-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                    <span class="truncate">Parado{{ $processo->data_parada ? ' desde ' . $processo->data_parada->format('d/m/Y') : '' }}</span>
                                </span>
                                @endif
                                @if($prazoTexto)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold ring-1 ring-inset {{ $prazoClasse }}" title="Prazo de atribuição: {{ $prazo->format('d/m/Y') }}">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    {{ $prazoTexto }}
                                </span>
                                @endif
                                @if($processo->alertas_pendentes_count > 0)
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-orange-50 text-orange-700 ring-1 ring-inset ring-orange-200">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                                    {{ $processo->alertas_pendentes_count }} {{ $processo->alertas_pendentes_count === 1 ? 'alerta' : 'alertas' }}
                                </span>
                                @endif
                            </div>
                            @endif
                        </div>

                        {{-- Rodapé --}}
                        <div class="px-4 py-2.5 border-t border-slate-100 bg-slate-50/60 flex items-center justify-between gap-2 text-[11px] text-slate-500">
                            <span class="min-w-0 truncate" title="Aberto por {{ $criadoPor }}">
                                por <span class="font-medium text-slate-600">{{ $criadoPor }}</span>
                            </span>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="inline-flex items-center gap-1" title="{{ $processo->documentos_count }} documento(s) no processo">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                    <span class="tabular-nums">{{ $processo->documentos_count }}</span>
                                </span>
                                <span class="inline-flex items-center gap-0.5 font-semibold text-blue-600 group-hover:gap-1.5 transition-all">
                                    Abrir
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                                </span>
                            </div>
                        </div>
                    </a>
                    @endforeach
                </div>
                @endif
            </div>
            @endif

            {{-- Municípios de Atuação - Apenas para Unidade Móvel --}}
            @if($estabelecimento->is_unidade_movel)
            <div id="municipios-atuacao" class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
                <div class="px-4 py-3 bg-gradient-to-r from-fuchsia-50 to-fuchsia-100 border-b border-slate-200">
                    <h3 class="text-xs font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-fuchsia-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                        Municípios de Atuação — Unidade Móvel
                        <span class="ml-auto text-[10px] px-2 py-0.5 bg-fuchsia-200 text-fuchsia-700 rounded-full font-medium">
                            {{ $estabelecimento->tipo_unidade_movel ?? 'Unidade Móvel' }}
                        </span>
                    </h3>
                </div>
                <div class="p-4">
                    @php
                        $municipiosAtuacao = $estabelecimento->municipiosAtuacao ?? collect();
                    @endphp
                    @if($municipiosAtuacao->isEmpty())
                        <p class="text-xs text-slate-500 italic">Nenhum município de atuação cadastrado.</p>
                    @else
                        <div class="space-y-2">
                            @foreach($municipiosAtuacao as $mun)
                            <div class="flex items-center justify-between p-2.5 rounded-lg border {{ $mun->competencia === 'estadual' ? 'border-purple-200 bg-purple-50' : ($mun->usa_infovisa ? 'border-blue-200 bg-blue-50' : 'border-slate-200 bg-slate-50') }}">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs font-semibold text-slate-900">{{ $mun->municipio_nome }}</span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-medium {{ $mun->competencia === 'estadual' ? 'bg-purple-100 text-purple-700' : 'bg-blue-100 text-blue-700' }}">
                                            {{ ucfirst($mun->competencia) }}
                                        </span>
                                        @if($mun->competencia === 'municipal' && !$mun->usa_infovisa)
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700 font-medium">Não usa InfoVISA</span>
                                        @endif
                                    </div>
                                    <p class="text-[10px] text-slate-500 mt-0.5">
                                        {{ \Carbon\Carbon::parse($mun->data_inicio)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($mun->data_fim)->format('d/m/Y') }}
                                    </p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- Informações do Sistema --}}
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/80 overflow-hidden">
                <div class="px-4 py-3 bg-gradient-to-r from-gray-50 to-gray-100 border-b border-slate-200">
                    <h3 class="text-xs font-semibold text-slate-900 flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        Informações do Sistema
                    </h3>
                </div>
                <div class="p-4">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Cadastrado em</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</p>
                        </div>
                        <div>
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">Última atualização</label>
                            <p class="text-xs text-slate-900">{{ $estabelecimento->updated_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</p>
                        </div>
                        <div class="col-span-2">
                            <label class="block text-[10px] font-medium text-slate-500 mb-0.5">ID do Sistema</label>
                            <p class="text-xs font-mono text-slate-900">#{{ $estabelecimento->id }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Modal Aprovar --}}
    @if(isset($tiposDocumentoDisponiveis) && $tiposDocumentoDisponiveis->count() > 0)
    {{-- Modal completo com checklist de documentos obrigatórios --}}
    <div id="modal-aprovar"
         x-data="{
            busca: '',
            selecionados: [],
            permitidos: {{ json_encode($documentosPermitidosEstabelecimento ?? []) }},
            exibe(docId) {
                return this.permitidos.length === 0 || this.permitidos.includes(docId);
            },
            toggle(docId) {
                const idx = this.selecionados.indexOf(docId);
                if (idx === -1) this.selecionados.push(docId);
                else this.selecionados.splice(idx, 1);
            }
         }"
         class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onclick="document.getElementById('modal-aprovar').classList.add('hidden')"></div>
        <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-xl z-10 flex flex-col max-h-[85vh]">
            {{-- Header --}}
            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 flex-shrink-0">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-green-100 flex items-center justify-center">
                        <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-slate-900">Aprovar Estabelecimento</h3>
                        <p class="text-xs text-slate-500 mt-0.5 line-clamp-1">{{ $estabelecimento->nome_razao_social }}</p>
                    </div>
                </div>
                <button onclick="document.getElementById('modal-aprovar').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition p-1 rounded-lg hover:bg-slate-100">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form action="{{ route('admin.estabelecimentos.aprovar', $estabelecimento->id) }}" method="POST" class="flex flex-col flex-1 min-h-0">
                @csrf
                <div class="px-6 py-4 flex-1 overflow-hidden flex flex-col min-h-0">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-3 flex-shrink-0">
                        <p class="text-xs text-blue-800">
                            <strong>Documentos obrigatórios:</strong> selecione os documentos que este estabelecimento deverá apresentar
                            no processo de licenciamento. Eles aparecerão no checklist de envio do estabelecimento.
                            Você pode alterar essa lista depois na página do estabelecimento.
                        </p>
                    </div>

                    {{-- Busca --}}
                    <div class="relative mb-3 flex-shrink-0">
                        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" x-model="busca" placeholder="Buscar documento..."
                               class="w-full pl-10 pr-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500">
                    </div>

                    {{-- Lista de documentos --}}
                    <div class="flex-1 overflow-y-auto space-y-1.5 border border-slate-100 rounded-lg p-2 min-h-[200px]">
                        @foreach($tiposDocumentoDisponiveis as $tipoDoc)
                        <label x-show="exibe({{ $tipoDoc->id }}) && (busca === '' || {{ json_encode(mb_strtolower($tipoDoc->nome)) }}.includes(busca.toLowerCase()))"
                               class="flex items-start gap-3 p-2.5 rounded-lg border cursor-pointer transition"
                               :class="selecionados.includes({{ $tipoDoc->id }}) ? 'bg-green-50 border-green-300' : 'bg-white border-slate-200 hover:border-green-200 hover:bg-green-50/50'">
                            <input type="checkbox" name="documentos_manuais[]" value="{{ $tipoDoc->id }}"
                                   @change="toggle({{ $tipoDoc->id }})"
                                   :checked="selecionados.includes({{ $tipoDoc->id }})"
                                   class="h-4 w-4 text-green-600 focus:ring-green-500 border-slate-300 rounded mt-0.5 flex-shrink-0">
                            <span class="min-w-0">
                                <span class="block text-xs font-semibold text-slate-800">{{ $tipoDoc->nome }}</span>
                                @if($tipoDoc->descricao)
                                <span class="block text-[11px] text-slate-500 mt-0.5 line-clamp-2">{{ $tipoDoc->descricao }}</span>
                                @endif
                            </span>
                        </label>
                        @endforeach
                    </div>

                    <p class="text-xs text-slate-500 mt-2 flex-shrink-0">
                        <span class="font-semibold" x-text="selecionados.length"></span> documento(s) selecionado(s)
                        — você pode aprovar sem selecionar documentos e definir depois.
                    </p>
                </div>

                {{-- Footer --}}
                <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex-shrink-0">
                    <button type="button" onclick="document.getElementById('modal-aprovar').classList.add('hidden')"
                            class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Aprovar Estabelecimento
                    </button>
                </div>
            </form>
        </div>
    </div>
    @else
    {{-- Modal simples (sem documentos manuais) --}}
    <div id="modal-aprovar" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Aprovar Estabelecimento</h3>
                    <button onclick="document.getElementById('modal-aprovar').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form action="{{ route('admin.estabelecimentos.aprovar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="observacao" class="block text-sm font-medium text-slate-700 mb-2">Observação (opcional)</label>
                        <textarea id="observacao" name="observacao" rows="3" 
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                                  placeholder="Adicione uma observação sobre a aprovação..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-aprovar').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            Aprovar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif

    {{-- Modal Rejeitar --}}
    <div id="modal-rejeitar" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Rejeitar Estabelecimento</h3>
                    <button onclick="document.getElementById('modal-rejeitar').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form action="{{ route('admin.estabelecimentos.rejeitar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="motivo_rejeicao" class="block text-sm font-medium text-slate-700 mb-2">Motivo da Rejeição *</label>
                        <textarea id="motivo_rejeicao" name="motivo_rejeicao" rows="4" required
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                  placeholder="Descreva o motivo da rejeição..."></textarea>
                    </div>
                    <div class="mb-4">
                        <label for="observacao_rejeitar" class="block text-sm font-medium text-slate-700 mb-2">Observação (opcional)</label>
                        <textarea id="observacao_rejeitar" name="observacao" rows="2" 
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                  placeholder="Observações adicionais..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-rejeitar').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Rejeitar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Aprovar Unidade Móvel --}}
    <div id="modal-aprovar-um" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Aprovar Unidade Móvel</h3>
                    <button onclick="document.getElementById('modal-aprovar-um').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-slate-600 mb-4">Ao aprovar, os processos de credenciamento de Unidade Móvel serão criados automaticamente conforme os municípios de atuação informados.</p>
                <form action="{{ route('admin.estabelecimentos.unidade-movel.aprovar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-aprovar-um').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-fuchsia-600 text-white rounded-lg hover:bg-fuchsia-700">
                            Aprovar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Rejeitar Unidade Móvel --}}
    <div id="modal-rejeitar-um" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Rejeitar Unidade Móvel</h3>
                    <button onclick="document.getElementById('modal-rejeitar-um').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <form action="{{ route('admin.estabelecimentos.unidade-movel.rejeitar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="motivo_rejeicao_unidade_movel" class="block text-sm font-medium text-slate-700 mb-2">Motivo da Rejeição *</label>
                        <textarea id="motivo_rejeicao_unidade_movel" name="motivo_rejeicao_unidade_movel" rows="4" required
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                  placeholder="Descreva o motivo da rejeição da solicitação de Unidade Móvel..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-rejeitar-um').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Rejeitar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Reiniciar --}}
    <div id="modal-reiniciar" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Reiniciar Estabelecimento</h3>
                    <button onclick="document.getElementById('modal-reiniciar').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-slate-600 mb-4">O status do estabelecimento voltará para "Pendente" e poderá ser reanalisado.</p>
                <form action="{{ route('admin.estabelecimentos.reiniciar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="observacao_reiniciar" class="block text-sm font-medium text-slate-700 mb-2">Observação (opcional)</label>
                        <textarea id="observacao_reiniciar" name="observacao" rows="3" 
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-yellow-500"
                                  placeholder="Motivo do reinício..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-reiniciar').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700">
                            Reiniciar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Desativar --}}
    <div id="modal-desativar" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Desativar Estabelecimento</h3>
                    <button onclick="document.getElementById('modal-desativar').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-slate-600 mb-4">O estabelecimento será desativado e ficará inativo no sistema.</p>
                <form action="{{ route('admin.estabelecimentos.desativar', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="motivo_desativar" class="block text-sm font-medium text-slate-700 mb-2">Motivo da Desativação *</label>
                        <textarea id="motivo_desativar" name="motivo" rows="4" required
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-red-500 focus:border-red-500"
                                  placeholder="Descreva o motivo da desativação..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-desativar').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Desativar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Voltar para Pendente --}}
    <div id="modal-voltar-pendente" class="hidden fixed inset-0 bg-slate-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-slate-900">Voltar para Pendente</h3>
                    <button onclick="document.getElementById('modal-voltar-pendente').classList.add('hidden')" class="text-slate-400 hover:text-slate-500">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <p class="text-sm text-slate-600 mb-4">O estabelecimento voltará para o status "Pendente" e poderá ser reanalisado ou rejeitado.</p>
                <form action="{{ route('admin.estabelecimentos.voltar-pendente', $estabelecimento->id) }}" method="POST">
                    @csrf
                    <div class="mb-4">
                        <label for="observacao_voltar" class="block text-sm font-medium text-slate-700 mb-2">Motivo *</label>
                        <textarea id="observacao_voltar" name="observacao" rows="3" required
                                  class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                                  placeholder="Informe o motivo para voltar para pendente..."></textarea>
                    </div>
                    <div class="flex gap-3">
                        <button type="button" onclick="document.getElementById('modal-voltar-pendente').classList.add('hidden')"
                                class="flex-1 px-4 py-2 bg-slate-200 text-slate-800 rounded-lg hover:bg-slate-300">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="flex-1 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700">
                            Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Modal Alterar Competência --}}
    <div id="modal-alterar-competencia" class="hidden fixed inset-0 bg-slate-600/50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-6 w-full max-w-md">
            <div class="bg-white rounded-xl shadow-xl overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100">
                    <div class="flex items-center justify-between">
                        <h3 class="text-lg font-semibold text-slate-900">Alterar Competência</h3>
                        <button onclick="document.getElementById('modal-alterar-competencia').classList.add('hidden')" 
                                class="text-slate-400 hover:text-slate-500 transition-colors">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>
                    <p class="text-xs text-amber-600 mt-1">
                        ⚠️ Use apenas em casos excepcionais.
                    </p>
                </div>

                <form method="POST" action="{{ route('admin.estabelecimentos.alterar-competencia', $estabelecimento->id) }}">
                    @csrf
                    <div class="p-6 space-y-4">
                        <div class="bg-slate-50 p-3 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Competência Atual</p>
                            <p class="font-medium text-{{ $competenciaEstadual ? 'purple' : 'blue' }}-600">
                                {{ $competenciaEstadual ? '🏛️ Estadual' : '🏘️ Municipal' }}
                                @if($estabelecimento->competencia_manual)
                                    <span class="text-xs text-amber-500">(manual)</span>
                                @endif
                            </p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Nova Competência</label>
                            <div class="grid grid-cols-3 gap-2">
                                <label class="relative">
                                    <input type="radio" name="competencia_manual" value="municipal" required
                                           class="sr-only peer">
                                    <div class="p-2 border-2 border-slate-200 rounded-lg text-center cursor-pointer
                                              hover:border-blue-300 peer-checked:border-blue-500 peer-checked:bg-blue-50">
                                        <span class="block text-sm font-medium">🏘️</span>
                                        <span class="text-xs">Municipal</span>
                                    </div>
                                </label>
                                
                                <label class="relative">
                                    <input type="radio" name="competencia_manual" value="estadual" required
                                           class="sr-only peer">
                                    <div class="p-2 border-2 border-slate-200 rounded-lg text-center cursor-pointer
                                              hover:border-purple-300 peer-checked:border-purple-500 peer-checked:bg-purple-50">
                                        <span class="block text-sm font-medium">🏛️</span>
                                        <span class="text-xs">Estadual</span>
                                    </div>
                                </label>
                                
                                <label class="relative">
                                    <input type="radio" name="competencia_manual" value="automatica" required
                                           class="sr-only peer">
                                    <div class="p-2 border-2 border-slate-200 rounded-lg text-center cursor-pointer
                                              hover:border-green-300 peer-checked:border-green-500 peer-checked:bg-green-50">
                                        <span class="block text-sm font-medium">⚙️</span>
                                        <span class="text-xs">Automática</span>
                                    </div>
                                </label>
                            </div>
                        </div>
                        
                        <div>
                            <label for="motivo_alteracao" class="block text-sm font-medium text-slate-700 mb-1">
                                Motivo <span class="text-slate-400 text-xs">(obrigatório)</span>
                            </label>
                            <textarea id="motivo_alteracao" name="motivo_alteracao_competencia" 
                                      rows="2" required minlength="10" maxlength="500"
                                      class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                      placeholder="Ex: Conforme Pactuação"></textarea>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                        <button type="button" 
                                onclick="document.getElementById('modal-alterar-competencia').classList.add('hidden')"
                                class="px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                            Cancelar
                        </button>
                        <button type="submit"
                                class="px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors">
                            Confirmar
                        </button>
                    </div>
                </form>
            </div>
        </div>
            </div>
        </div>
    </div>

</div>
@endsection
