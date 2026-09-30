@extends('layouts.admin')

@section('title', 'Gerenciar Atividades')
@section('page-title', 'Gerenciar Atividades')

@section('content')
<div class="max-w-8xl mx-auto">
    {{-- Header --}}
    <div class="mb-6">
        <div class="flex items-center gap-4">
            <a href="{{ route('admin.estabelecimentos.show', $estabelecimento->id) }}" 
               class="inline-flex items-center justify-center w-10 h-10 rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 hover:text-gray-900 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h1 class="text-2xl font-bold text-gray-900">Gerenciar Atividades Econômicas</h1>
                <p class="text-sm text-gray-600 mt-1">{{ $estabelecimento->nome_fantasia }} - {{ $estabelecimento->documento_formatado }}</p>
            </div>
        </div>
    </div>

    {{-- Mensagem de sucesso --}}
    @if(session('success'))
    <div class="mb-6 bg-green-50 border-l-4 border-green-500 p-4 rounded-lg">
        <div class="flex items-center">
            <svg class="w-5 h-5 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
            </svg>
            <p class="text-sm font-medium text-green-800">{{ session('success') }}</p>
        </div>
    </div>
    @endif

    {{-- Formulário --}}
    <form method="POST" 
          action="{{ route('admin.estabelecimentos.atividades.update', $estabelecimento->id) }}"
          x-data="atividadesForm()"
          x-init="init()"
          class="space-y-6">
        @csrf

        {{-- Card de Instruções --}}
        <div class="bg-blue-50 border border-blue-200 rounded-xl p-5">
            <div class="flex items-start gap-3">
                <svg class="w-6 h-6 text-blue-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <div>
                    <h3 class="text-sm font-semibold text-blue-900 mb-1">Como funciona?</h3>
                    <p class="text-sm text-blue-800">
                        Abaixo estão listadas <strong>todas as atividades econômicas</strong> registradas na Receita Federal para este CNPJ. 
                        Marque apenas as atividades que o estabelecimento <strong>realmente exerce</strong> no dia a dia.
                    </p>
                </div>
            </div>
        </div>

        {{-- Card de Competência e Questionários --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-4">
            <div class="flex items-center justify-between flex-wrap gap-4">
                {{-- Competência Dinâmica --}}
                <div class="flex items-center gap-3">
                    {{-- Ícone e BG dinâmicos --}}
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center flex-shrink-0 transition-colors duration-300"
                         :class="competenciaEstadual ? 'bg-purple-500' : 'bg-blue-500'">
                        
                        {{-- Ícone Estadual --}}
                        <svg x-show="competenciaEstadual" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                        </svg>

                        {{-- Ícone Municipal --}}
                        <svg x-show="!competenciaEstadual" class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                        </svg>
                    </div>
                    
                    <div>
                        <p class="text-xs text-gray-500 font-medium">Competência (Prevista)</p>
                        <p class="text-lg font-bold transition-colors duration-300"
                           :class="competenciaEstadual ? 'text-purple-600' : 'text-blue-600'"
                           x-text="competenciaEstadual ? '🏛️ ESTADUAL' : '🏘️ MUNICIPAL'">
                        </p>
                    </div>
                </div>

                {{-- Respostas dos Questionários --}}
                @if(isset($questionariosRespondidos) && $questionariosRespondidos->count() > 0)
                <div class="bg-yellow-50 px-4 py-3 rounded-lg border border-yellow-200 min-w-[360px]" x-data="{ expandido: false }">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5 text-yellow-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="text-xs font-semibold text-yellow-900">Questionários respondidos</span>
                        <span class="ml-auto text-[10px] px-2 py-0.5 rounded-full bg-yellow-100 text-yellow-800 font-semibold">
                            {{ $questionariosRespondidos->count() }} CNAE(s)
                        </span>
                    </div>

                    <div class="space-y-2 max-h-40 overflow-y-auto pr-1">
                        @foreach($questionariosRespondidos as $index => $quest)
                            <div class="bg-white/70 border border-yellow-100 rounded-md p-2" x-show="expandido || {{ $index < 3 ? 'true' : 'false' }}" x-cloak>
                                <p class="text-[11px] font-semibold text-gray-700 mb-1">
                                    CNAE {{ $quest['cnae'] }}
                                    @if(!empty($quest['descricao']))
                                        - {{ $quest['descricao'] }}
                                    @endif
                                </p>

                                @if(!empty($quest['pergunta']))
                                    <p class="text-[11px] text-gray-700">
                                        <span class="font-medium">Pergunta 1:</span> {{ $quest['pergunta'] }}
                                    </p>
                                @endif
                                @if(!empty($quest['resposta']))
                                    <p class="text-[11px] flex items-center gap-1.5">
                                        <span class="font-medium text-gray-700">Resposta:</span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ strtolower((string) $quest['resposta']) === 'sim' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            @if(strtolower((string) $quest['resposta']) === 'sim')
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                Sim
                                            @else
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                                Não
                                            @endif
                                        </span>
                                    </p>
                                @endif

                                @if(!empty($quest['pergunta2']))
                                    <p class="text-[11px] text-gray-700 mt-1">
                                        <span class="font-medium">Pergunta 2:</span> {{ $quest['pergunta2'] }}
                                    </p>
                                @endif
                                @if(!empty($quest['resposta2']))
                                    <p class="text-[11px] flex items-center gap-1.5">
                                        <span class="font-medium text-gray-700">Resposta 2:</span>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold {{ strtolower((string) $quest['resposta2']) === 'sim' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                                            @if(strtolower((string) $quest['resposta2']) === 'sim')
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                Sim
                                            @else
                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                                Não
                                            @endif
                                        </span>
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @if($questionariosRespondidos->count() > 3)
                        <button type="button"
                                @click="expandido = !expandido"
                                class="mt-2 text-xs font-semibold text-yellow-800 hover:text-yellow-900 underline underline-offset-2">
                            <span x-show="!expandido">Mostrar mais {{ $questionariosRespondidos->count() - 3 }} questionário(s)</span>
                            <span x-show="expandido">Mostrar menos</span>
                        </button>
                    @endif
                </div>
                @endif
            </div>
        </div>

        {{-- Alerta: CNAEs do CNPJ alterados na Receita --}}
        @php
            $alertaCnae = \App\Models\EstabelecimentoVerificacaoCnae::where('estabelecimento_id', $estabelecimento->id)
                ->where('status', 'divergente')->first();
        @endphp
        @if($alertaCnae)
        <div class="rounded-xl border p-4 {{ $alertaCnae->altera_competencia ? 'bg-red-50 border-red-200' : 'bg-amber-50 border-amber-200' }}">
            <div class="flex items-start gap-3">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5 {{ $alertaCnae->altera_competencia ? 'text-red-600' : 'text-amber-600' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                <div class="text-sm {{ $alertaCnae->altera_competencia ? 'text-red-800' : 'text-amber-900' }}">
                    <p class="font-semibold">
                        O CNPJ deste estabelecimento mudou na Receita (detectado em {{ $alertaCnae->detectado_em?->format('d/m/Y') }})
                        @if($alertaCnae->altera_competencia)
                            — a revisão muda a competência de {{ ucfirst($alertaCnae->competencia_atual) }} para {{ ucfirst($alertaCnae->competencia_sugerida) }}
                        @endif
                    </p>
                    <ul class="mt-1 text-xs space-y-0.5 list-disc list-inside">
                        @foreach($alertaCnae->cnaes_removidos ?? [] as $cnae)
                            <li><strong>Saiu do CNPJ:</strong> {{ \App\Models\EstabelecimentoVerificacaoCnae::formatarCnae($cnae['codigo'] ?? '') }} — {{ $cnae['descricao'] ?? '' }} ({{ ucfirst($cnae['competencia'] ?? '') }}). Desmarque se não é mais exercida.</li>
                        @endforeach
                        @foreach($alertaCnae->cnaes_novos ?? [] as $cnae)
                            <li><strong>Novo no CNPJ:</strong> {{ \App\Models\EstabelecimentoVerificacaoCnae::formatarCnae($cnae['codigo'] ?? '') }} — {{ $cnae['descricao'] ?? '' }} ({{ ucfirst($cnae['competencia'] ?? '') }}). Marque se o estabelecimento exerce.</li>
                        @endforeach
                    </ul>
                    <p class="text-xs mt-1.5 opacity-80">Ao salvar as atividades, este alerta é encerrado.</p>
                </div>
            </div>
        </div>
        @endif

        {{-- Lista de Atividades --}}
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
            <div class="flex items-center justify-between mb-5">
                <div>
                    <h2 class="text-lg font-semibold text-gray-900">Atividades Disponíveis</h2>
                    <p class="text-sm text-gray-500 mt-1">Selecione as atividades que o estabelecimento pratica</p>
                    <p class="text-xs text-gray-400 mt-0.5">Cada atividade mostra sua competência pela pactuação. Se pelo menos uma atividade marcada for estadual, o estabelecimento é estadual.</p>
                </div>
                <div class="flex items-center gap-2 bg-blue-50 px-4 py-2 rounded-lg">
                    <svg class="w-5 h-5 text-blue-600" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"/>
                    </svg>
                    <span class="text-sm font-semibold text-blue-900">
                        <span x-text="atividadesSelecionadas.length"></span> selecionada(s)
                    </span>
                </div>
            </div>

            @if(count($atividadesApi) > 0)
            <div class="space-y-3">
                <template x-for="(atividade, index) in atividadesDisponiveis" :key="index">
                    <div class="flex items-start gap-4 p-4 border border-gray-200 rounded-lg hover:border-blue-300 hover:bg-blue-50 transition-all"
                         :class="{'bg-blue-50 border-blue-300': isAtividadeSelecionada(atividade.codigo)}">
                        <div class="flex-shrink-0 pt-1">
                            <input type="checkbox" 
                                   :id="'atividade_' + index"
                                   :value="atividade.codigo"
                                   @change="toggleAtividade(atividade)"
                                   :checked="isAtividadeSelecionada(atividade.codigo)"
                                   class="h-5 w-5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded cursor-pointer">
                        </div>
                        <div class="flex-1">
                            <label :for="'atividade_' + index" class="cursor-pointer">
                                <div class="flex items-center gap-2 mb-2">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                                          :class="atividade.tipo === 'principal' ? 'bg-blue-500 text-white' : (atividade.tipo === 'especial' ? 'bg-indigo-500 text-white' : 'bg-gray-400 text-white')">
                                        <span x-show="atividade.tipo === 'principal'">⭐ Principal</span>
                                        <span x-show="atividade.tipo === 'especial'">Atividade de processo</span>
                                        <span x-show="atividade.tipo === 'secundaria'">Secundária</span>
                                    </span>
                                    <span x-show="atividade.manual" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700">
                                        Manual
                                    </span>
                                    <span x-show="atividade.especial" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-700">
                                        Somente admin
                                    </span>
                                    <span x-show="atividade.fora_receita" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800"
                                          title="Esta atividade está salva no cadastro, mas não consta mais no CNPJ na Receita Federal. Desmarque se o estabelecimento não a exerce.">
                                        ⚠️ Não consta mais na Receita
                                    </span>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-mono font-semibold bg-gray-100 text-gray-800"
                                          x-text="atividade.codigo"></span>
                                    {{-- Competência da atividade pela pactuação (informativo) --}}
                                    <template x-if="atividade.competencia">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold ring-1"
                                              :class="atividade.competencia === 'estadual' ? 'bg-purple-50 text-purple-700 ring-purple-200' : 'bg-emerald-50 text-emerald-700 ring-emerald-200'"
                                              :title="atividade.sem_resposta ? 'Depende da resposta ao questionário (ainda sem resposta)' : ('Pactuação' + (atividade.tabela ? ' — Tabela ' + atividade.tabela : ''))">
                                            <span x-text="atividade.competencia === 'estadual' ? '🏛️ Estadual' : '🏠 Municipal'"></span>
                                            <span x-show="atividade.descentralizado" class="font-normal opacity-80">(descentralizado)</span>
                                            <span x-show="atividade.excecao_hospitalar" class="font-normal opacity-80">(exceção hospitalar)</span>
                                            <span x-show="atividade.sem_resposta" class="font-normal opacity-80">(sem resposta ao questionário)</span>
                                        </span>
                                    </template>
                                </div>
                                <p class="text-sm text-gray-900 font-medium" x-text="atividade.descricao"></p>
                            </label>
                        </div>
                    </div>
                </template>
            </div>
            @else
            <div class="text-center py-12">
                <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                <p class="mt-2 text-sm text-gray-500">Nenhuma atividade encontrada na API da Receita Federal.</p>
                <p class="mt-1 text-xs text-gray-400">Verifique se o CNPJ está correto ou tente novamente mais tarde.</p>
            </div>
            @endif

            @if(auth('interno')->check() && auth('interno')->user()->isAdmin())
            {{-- Busca Manual de CNAE via Pactuação --}}
            <div class="mt-6 bg-white border-2 border-dashed border-blue-300 rounded-lg p-5">
                <div class="flex items-center gap-2 mb-3">
                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                    <h4 class="text-base font-semibold text-blue-800">Adicionar Atividade Manualmente</h4>
                </div>
                <p class="text-sm text-gray-600 mb-4">
                    Busque CNAEs cadastrados na pactuação e adicione manualmente para este estabelecimento.
                </p>

                <div class="flex gap-2">
                    <div class="flex-1 relative">
                        <input type="text"
                               x-model="cnaeBusca"
                               @keydown.enter.prevent="buscarCnaeAdicional"
                               placeholder="Digite código CNAE ou descrição"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        <p x-show="cnaeErro" class="absolute text-xs text-red-600 mt-1" x-text="cnaeErro"></p>
                    </div>
                    <button type="button"
                            @click="buscarCnaeAdicional"
                            :disabled="loadingCnae"
                            class="px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 disabled:opacity-50">
                        <span x-show="!loadingCnae">Buscar</span>
                        <span x-show="loadingCnae">Buscando...</span>
                    </button>
                </div>

                <div x-show="cnaeResultados.length > 0" class="mt-4 space-y-2 max-h-60 overflow-y-auto border border-gray-200 rounded-lg">
                    <template x-for="resultado in cnaeResultados" :key="resultado.codigo + '_' + resultado.descricao">
                        <div class="flex items-start justify-between p-3 hover:bg-gray-50 border-b border-gray-100 last:border-0">
                            <div>
                                <span class="text-xs font-bold bg-blue-100 text-blue-800 px-2 py-0.5 rounded-full" x-text="resultado.codigo"></span>
                                <p class="text-sm text-gray-800 mt-1" x-text="resultado.descricao"></p>
                            </div>
                            <button type="button"
                                    @click="adicionarCnaeManual(resultado)"
                                    class="ml-3 text-sm font-medium text-blue-600 hover:text-blue-800 bg-white border border-blue-200 px-3 py-1 rounded-md hover:bg-blue-50 transition-colors">
                                Adicionar
                            </button>
                        </div>
                    </template>
                </div>
            </div>
            @endif
        </div>

        {{-- Campo hidden com JSON das atividades --}}
        <input type="hidden" name="atividades_exercidas" :value="getAtividadesJSON()">

        {{-- Alerta de Competência Estadual (apenas para usuários municipais) --}}
        @if(auth('interno')->check() && auth('interno')->user()->isMunicipal())
        <div x-show="atividadesSelecionadas.length > 0 && competenciaEstadual" 
             x-cloak
             class="bg-purple-50 border-l-4 border-purple-500 p-5 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0">
                    <svg class="h-8 w-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <p class="text-base font-bold text-purple-900 mb-3">
                        ⚠️ ATENÇÃO: Este estabelecimento PASSARÁ para COMPETÊNCIA ESTADUAL
                    </p>
                    <p class="text-sm text-purple-800 mb-3">
                        <strong>Motivo:</strong> Você selecionou pelo menos uma atividade econômica (CNAE) que está configurada como de competência estadual.
                    </p>
                    
                    {{-- Lista de atividades estaduais selecionadas --}}
                    <div x-show="atividadesEstaduais.length > 0" class="mb-3">
                        <p class="text-sm font-semibold text-purple-900 mb-2">🏛️ Atividades Estaduais Selecionadas:</p>
                        <ul class="list-disc list-inside space-y-1 ml-2">
                            <template x-for="atividade in atividadesEstaduais" :key="atividade.codigo">
                                <li class="text-sm text-purple-800">
                                    <span class="font-mono font-semibold" x-text="atividade.codigo"></span> - 
                                    <span x-text="atividade.descricao"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                    
                    <p class="text-sm text-purple-700 mb-3">
                        <strong>Importante:</strong> Após salvar, este estabelecimento será transferido para a competência <strong>Estadual</strong> e será visível apenas para <strong>Gestores e Técnicos Estaduais</strong>. Você (usuário municipal) <strong>perderá o acesso</strong> a ele.
                    </p>
                    <p class="text-xs text-purple-600">
                        💡 Se isso não estiver correto, desmarque as atividades estaduais antes de salvar ou entre em contato com o administrador.
                    </p>
                </div>
            </div>
        </div>
        @endif

        {{-- Botões de ação --}}
        <div class="flex items-center justify-between gap-4 pt-4">
            <a href="{{ route('admin.estabelecimentos.show', $estabelecimento->id) }}"
               class="px-6 py-2.5 text-sm font-semibold text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">
                Cancelar
            </a>
            <button type="submit"
                    class="px-6 py-2.5 text-sm font-semibold text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors inline-flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Salvar Atividades
            </button>
        </div>
    </form>
</div>

<script>
function atividadesForm() {
    return {
        atividadesDisponiveis: @json($atividadesApi),
        atividadesSelecionadas: [],
        competenciaEstadual: false,
        atividadesEstaduais: [],
        cnaeBusca: '',
        cnaeResultados: [],
        cnaeErro: '',
        loadingCnae: false,

        normalizarCodigo(codigo) {
            const valor = String(codigo || '').trim().toUpperCase();
            const numerico = valor.replace(/[^0-9]/g, '');

            return numerico !== '' ? numerico : valor.replace(/[^A-Z0-9_]/g, '');
        },

        init() {
            // Inicializa com as atividades já salvas
            const atividadesSalvas = @json($estabelecimento->atividades_exercidas ?? []);
            
            console.log('Atividades disponíveis:', this.atividadesDisponiveis);
            console.log('Atividades salvas:', atividadesSalvas);
            
            if (atividadesSalvas && Array.isArray(atividadesSalvas)) {
                this.atividadesSelecionadas = atividadesSalvas.map(a => ({
                    codigo: a.codigo,
                    descricao: a.descricao,
                    principal: a.principal || false,
                    manual: a.manual || false,
                    especial: a.especial || ['PROJ_ARQ', 'ANAL_ROT'].includes(String(a.codigo || '').toUpperCase())
                }));
            }
            
            console.log('Atividades selecionadas após init:', this.atividadesSelecionadas);
            
            // Verifica competência inicial
            this.verificarCompetencia();
            
            // Watcher para verificar competência quando atividades mudarem
            this.$watch('atividadesSelecionadas', () => {
                this.verificarCompetencia();
            });
        },
        
        async verificarCompetencia() {
            if (this.atividadesSelecionadas.length === 0) {
                this.competenciaEstadual = false;
                this.atividadesEstaduais = [];
                return;
            }
            
            const codigos = this.atividadesSelecionadas.map(a => a.codigo);
            
            // Pega as respostas dos questionários já salvas
            const respostasSalvas = @json($estabelecimento->respostas_questionario ?? []);
            
            console.log('🔍 Verificando competência para:', codigos);
            console.log('📝 Respostas:', respostasSalvas);
            
            try {
                const response = await fetch('{{ url('/api/verificar-competencia') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        atividades: codigos,
                        municipio: '{{ $estabelecimento->cidade }}',
                        respostas_questionario: respostasSalvas
                    })
                });
                
                const result = await response.json();
                console.log('✅ Resultado da API:', result);
                
                this.competenciaEstadual = result.competencia === 'estadual';
                
                // Filtra atividades estaduais
            if (result.detalhes) {
                this.atividadesEstaduais = this.atividadesSelecionadas.filter(atividade => {
                        const codigoNormalizado = this.normalizarCodigo(atividade.codigo);
                        const detalhe = result.detalhes.find(d => this.normalizarCodigo(d.cnae) === codigoNormalizado);
                        return detalhe && detalhe.estadual;
                    });
                }
                
                console.log('📊 Competência:', this.competenciaEstadual ? 'ESTADUAL' : 'MUNICIPAL');
                console.log('🏛️ Atividades estaduais:', this.atividadesEstaduais);
            } catch (error) {
                console.error('❌ Erro ao verificar competência:', error);
                this.competenciaEstadual = false;
                this.atividadesEstaduais = [];
            }
        },

        isAtividadeSelecionada(codigo) {
            // Normaliza os códigos removendo caracteres especiais para comparação
            const codigoNormalizado = this.normalizarCodigo(codigo);
            const resultado = this.atividadesSelecionadas.some(a => {
                const codigoAtividadeNormalizado = this.normalizarCodigo(a.codigo);
                return codigoAtividadeNormalizado === codigoNormalizado;
            });
            console.log(`Verificando se ${codigo} está selecionado:`, resultado);
            return resultado;
        },

        toggleAtividade(atividade) {
            // Normaliza códigos para comparação
            const codigoNormalizado = this.normalizarCodigo(atividade.codigo);
            const index = this.atividadesSelecionadas.findIndex(a => {
                const codigoAtividadeNormalizado = this.normalizarCodigo(a.codigo);
                return codigoAtividadeNormalizado === codigoNormalizado;
            });
            
            if (index >= 0) {
                // Remove se já estiver selecionada
                this.atividadesSelecionadas.splice(index, 1);
            } else {
                // Adiciona se não estiver selecionada
                this.atividadesSelecionadas.push({
                    codigo: atividade.codigo,
                    descricao: atividade.descricao,
                    principal: atividade.tipo === 'principal',
                    manual: !!atividade.manual,
                    especial: !!atividade.especial
                });
            }
            
            console.log('Atividades selecionadas após toggle:', this.atividadesSelecionadas);
        },

        getAtividadesJSON() {
            return JSON.stringify(this.atividadesSelecionadas);
        },

        async buscarCnaeAdicional() {
            if (!this.cnaeBusca || this.cnaeBusca.trim().length < 3) {
                this.cnaeErro = 'Digite pelo menos 3 caracteres para buscar';
                return;
            }

            this.cnaeErro = '';
            this.loadingCnae = true;
            this.cnaeResultados = [];

            try {
                const termoBusca = this.cnaeBusca.trim();

                if (/^\d+$/.test(termoBusca)) {
                    const response = await fetch(`https://servicodados.ibge.gov.br/api/v2/cnae/subclasses/${termoBusca}`);
                    if (response.ok) {
                        const data = await response.json();
                        if (data && data.id) {
                            this.cnaeResultados = [{
                                codigo: data.id,
                                descricao: data.descricao
                            }];
                        }
                    }
                } else {
                    const response = await fetch(`{{ route('admin.estabelecimentos.buscar-cnaes') }}?q=${encodeURIComponent(termoBusca)}`);
                    if (response.ok) {
                        this.cnaeResultados = await response.json();
                    } else {
                        this.cnaeErro = 'Erro ao buscar CNAE';
                    }
                }

                if (this.cnaeResultados.length === 0 && !this.cnaeErro) {
                    this.cnaeErro = 'Nenhum CNAE encontrado';
                }
            } catch (error) {
                console.error('Erro na busca manual de CNAE:', error);
                this.cnaeErro = 'Erro ao buscar CNAE';
            } finally {
                this.loadingCnae = false;
            }
        },

        adicionarCnaeManual(cnae) {
            const codigo = String(cnae.codigo || '').trim();
            if (!codigo) {
                return;
            }

            const codigoNormalizado = this.normalizarCodigo(codigo);

            const existeDisponiveis = this.atividadesDisponiveis.some(a => this.normalizarCodigo(a.codigo) === codigoNormalizado);
            if (!existeDisponiveis) {
                this.atividadesDisponiveis.unshift({
                    codigo: cnae.codigo,
                    descricao: cnae.descricao || '',
                    tipo: 'secundaria',
                    manual: true
                });
            }

            const existeSelecionadas = this.atividadesSelecionadas.some(a => this.normalizarCodigo(a.codigo) === codigoNormalizado);
            if (!existeSelecionadas) {
                this.atividadesSelecionadas.push({
                    codigo: cnae.codigo,
                    descricao: cnae.descricao || '',
                    principal: false,
                    manual: true
                });
            }

            this.cnaeBusca = '';
            this.cnaeResultados = [];
            this.cnaeErro = '';
        }
    }
}
</script>
@endsection
