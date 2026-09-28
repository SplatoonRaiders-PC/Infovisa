<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\TipoProcesso;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ListaDocumento;
use App\Models\Atividade;
use Carbon\Carbon;

class HomeController extends Controller
{
    /**
     * Exibe a página inicial pública
     */
    public function index()
    {
        return view('public.home');
    }

    /**
     * Exibe a página de fila de processos
     */
    public function filaProcessos()
    {
        // Busca tipos de processo com fila pública ativada
        $tiposComFilaPublica = TipoProcesso::where('exibir_fila_publica', true)
            ->where('ativo', true)
            ->ordenado()
            ->get();

        // Para cada tipo, busca os processos que têm todos os documentos obrigatórios aprovados
        $filaProcessos = [];
        foreach ($tiposComFilaPublica as $tipo) {
            $processos = Processo::where('tipo', $tipo->codigo)
                ->whereIn('status', ['aberto', 'em_analise', 'pendente', 'parado'])
                ->with(['estabelecimento', 'tipoProcesso', 'unidades', 'pastas', 'documentos'])
                ->orderBy('created_at', 'asc') // Mais antigo primeiro
                ->get();

            $processosAptos = [];
            foreach ($processos as $processo) {
                // Se o processo tem pastas, verifica se todas estão concluídas
                // Se todas concluídas, processo sai da fila (não é apto)
                $todasPastas = $processo->pastas;
                if ($todasPastas->isNotEmpty()) {
                    $pastasAtivas = $todasPastas->where('status', '!=', 'concluida');
                    if ($pastasAtivas->isEmpty()) {
                        // Todas as pastas/unidades foram concluídas — sai da fila
                        continue;
                    }
                }

                // Verifica se todos os documentos obrigatórios estão aprovados
                $statusDocs = $this->verificarDocumentosObrigatorios($processo, $tipo);

                $entrada = $this->montarEntradaFila($processo, $tipo, $statusDocs);
                if ($entrada !== null) {
                    $processosAptos[] = $entrada;
                }
            }

            // Ordena pela referência atual do prazo (mais antiga primeiro) e adiciona posição
            usort($processosAptos, function($a, $b) {
                return $a['data_referencia_prazo_sort'] <=> $b['data_referencia_prazo_sort'];
            });

            foreach ($processosAptos as $index => &$proc) {
                $proc['posicao'] = $index + 1;
            }
            unset($proc);

            if (count($processosAptos) > 0) {
                $filaProcessos[] = [
                    'tipo' => $tipo->nome,
                    'codigo' => $tipo->codigo,
                    'prazo_analise' => $tipo->prazo_fila_publica,
                    'processos' => $processosAptos
                ];
            }
        }

        return view('public.fila-processos', compact('filaProcessos'));
    }

    /**
     * Uma unidade apta entra na fila mesmo com pendências na raiz ou em outras unidades.
     */
    private function montarEntradaFila(Processo $processo, TipoProcesso $tipo, array $statusDocs): ?array
    {
        $grupoRisco = $processo->estabelecimento?->getGrupoRisco();
        $prazo = $tipo->getPrazoFilaPublicaPorRisco($grupoRisco);
        $unidadesPrazo = $this->calcularPrazosUnidades($processo, $prazo);

        if (!$statusDocs['completo'] && empty($unidadesPrazo)) {
            return null;
        }

        $unidadeReferencia = null;
        if (!$statusDocs['completo']) {
            // Representa a unidade em análise mais antiga; se todas estão suspensas,
            // usa a mais antiga delas. Nunca cria um prazo fictício para a raiz.
            $unidades = collect($unidadesPrazo)->sortBy('data_referencia_prazo_sort');
            $unidadeReferencia = $unidades->firstWhere('pausado', false) ?? $unidades->first();
        }

        if ($unidadeReferencia) {
            $dataDocumentosCompletos = $unidadeReferencia['data_documentos_completos'];
            $dataRef = $unidadeReferencia['referencia']->copy();
            $tempoTotalSegundos = $unidadeReferencia['tempo_segundos'];
            $diasRestantes = $unidadeReferencia['dias_restantes'];
            $pausado = $unidadeReferencia['pausado'];
            $reiniciado = $unidadeReferencia['prazo_reiniciado'];
        } else {
            $dataDocumentosCompletos = $statusDocs['data_ultimo_aprovado'] ?? $processo->created_at;
            $dataRef = $processo->getDataReferenciaFilaPublica($dataDocumentosCompletos);
            $hoje = Carbon::now();
            $tempoTotalSegundos = max(0, $dataRef->diffInSeconds($hoje) - $processo->getTempoTotalParadoConsiderandoParadaAtual());
            if ($prazo) {
                $dataLimite = $processo->calcularDataLimiteFilaPublica($dataRef, $prazo);
                $diasRestantes = (int) round($hoje->diffInDays($dataLimite, false));
            } else {
                $diasRestantes = null;
            }
            $pausado = $processo->status === 'parado';
            $reiniciado = $processo->prazoFilaPublicaFoiReiniciado($dataDocumentosCompletos);
        }

        $dias = intdiv((int) $tempoTotalSegundos, 86400);
        $horas = intdiv((int) $tempoTotalSegundos % 86400, 3600);

        return [
            'numero_processo' => $processo->numero_processo,
            'estabelecimento' => $processo->estabelecimento
                ? ($processo->estabelecimento->nome_fantasia ?? $processo->estabelecimento->nome_completo ?? 'Não informado')
                : 'Não vinculado',
            'status' => $processo->status,
            'data_abertura' => Carbon::parse($processo->created_at)->format('d/m/Y H:i'),
            'data_documentos_completos' => Carbon::parse($dataDocumentosCompletos)->format('d/m/Y H:i'),
            'data_referencia_prazo' => $dataRef->format('d/m/Y H:i'),
            'dias_decorridos' => $dias,
            'horas_decorridas' => $horas,
            'tempo_formatado' => $dias > 0 ? "{$dias}d {$horas}h" : "{$horas}h",
            'prazo' => $prazo,
            'dias_restantes' => $diasRestantes,
            'atrasado' => $prazo ? $diasRestantes < 0 : false,
            'pausado' => $pausado,
            'prazo_reiniciado' => $reiniciado,
            'data_referencia_prazo_sort' => $dataRef->timestamp,
            'unidade_referencia' => $unidadeReferencia['nome'] ?? null,
            'unidades_prazo' => $unidadesPrazo,
        ];
    }

    /**
     * Calcula prazos por unidade para um processo
     */
    private function calcularPrazosUnidades($processo, $prazo)
    {
        $unidadesPrazo = [];
        // Pastas de unidade ainda não concluídas (mesma regra da tela do processo)
        $pastasUnidade = $processo->pastas
            ->whereNotNull('unidade_id')
            ->where('status', '!=', 'concluida')
            ->sortBy('ordem');
        if ($pastasUnidade->isEmpty() || !$prazo) return $unidadesPrazo;

        $checklist = $processo->getDocumentosObrigatoriosChecklist();

        foreach ($pastasUnidade as $pasta) {
            // Todos os obrigatórios aprovados na pasta da unidade (envio mais recente de cada um)
            $dataDocsCompletos = $processo->getDataDocumentacaoCompletaPasta($pasta, $checklist);
            if (!$dataDocsCompletos) continue;

            // Congela o prazo se o processo OU a unidade estiver parado; respeita reinício da unidade
            $prazoUnidade = $processo->calcularPrazoFilaPublicaUnidade($pasta, $dataDocsCompletos, (int) $prazo);

            $unidadesPrazo[] = [
                'nome' => $pasta->nome,
                'prazo' => $prazo,
                'data_documentos_completos' => $dataDocsCompletos,
                'referencia' => $prazoUnidade['data_referencia_prazo'],
                'data_referencia_prazo_sort' => $prazoUnidade['data_referencia_prazo']->timestamp,
                'tempo_segundos' => max(0, (int) ($prazo * 86400 - now()->diffInSeconds($prazoUnidade['data_limite'], false))),
                'data_referencia_prazo' => $prazoUnidade['data_referencia_prazo']->format('d/m/Y'),
                'dias_restantes' => $prazoUnidade['dias_restantes'],
                'atrasado' => $prazoUnidade['atrasado'],
                'pausado' => $prazoUnidade['pausado'],
                'prazo_reiniciado' => $prazoUnidade['prazo_reiniciado'],
            ];
        }

        return $unidadesPrazo;
    }

    /**
     * Verifica se todos os documentos obrigatórios de um processo estão aprovados
     * Retorna array com status e data do último documento aprovado
     */
    private function verificarDocumentosObrigatorios($processo, $tipoProcesso)
    {
        $estabelecimento = $processo->estabelecimento;
        $tipoProcessoId = $tipoProcesso->id ?? null;
        
        if (!$tipoProcessoId || !$estabelecimento) {
            return ['completo' => false, 'data_ultimo_aprovado' => null];
        }

        // Verifica se é um processo especial (Projeto Arquitetônico ou Análise de Rotulagem)
        $isProcessoEspecial = in_array($tipoProcesso->codigo, ['projeto_arquitetonico', 'analise_rotulagem']);

        // Pega as atividades exercidas do estabelecimento
        $atividadesExercidas = $estabelecimento->atividades_exercidas ?? [];
        
        // Para processos especiais, não precisa de atividades
        if (!$isProcessoEspecial && empty($atividadesExercidas)) {
            return ['completo' => false, 'data_ultimo_aprovado' => null];
        }

        $atividadeIds = collect();
        
        // Só busca atividades se não for processo especial e tiver atividades exercidas
        if (!$isProcessoEspecial && !empty($atividadesExercidas)) {
            $codigosCnae = collect($atividadesExercidas)->map(function($atividade) {
                $codigo = is_array($atividade) ? ($atividade['codigo'] ?? null) : $atividade;
                return $codigo ? preg_replace('/[^0-9]/', '', $codigo) : null;
            })->filter()->values()->toArray();

            if (!empty($codigosCnae)) {
                $atividadeIds = Atividade::where('ativo', true)
                    ->where(function($query) use ($codigosCnae) {
                        foreach ($codigosCnae as $codigo) {
                            $query->orWhere('codigo_cnae', $codigo);
                        }
                    })
                    ->pluck('id');
            }
        }

        // Busca listas de documentos aplicáveis
        $listasQuery = ListaDocumento::where('ativo', true)
            ->where('tipo_processo_id', $tipoProcessoId)
            ->with(['tiposDocumentoObrigatorio' => function($q) {
                $q->orderBy('lista_documento_tipo.ordem');
            }]);
            
        // Para processos especiais: busca listas SEM atividades vinculadas
        // Para processos normais: busca listas COM atividades que correspondem às do estabelecimento
        if ($isProcessoEspecial) {
            $listasQuery->whereDoesntHave('atividades');
        } else {
            if ($atividadeIds->isEmpty()) {
                return ['completo' => false, 'data_ultimo_aprovado' => null];
            }
            $listasQuery->whereHas('atividades', function($q) use ($atividadeIds) {
                $q->whereIn('atividades.id', $atividadeIds);
            });
        }

        // Filtra por escopo (estadual ou do município do estabelecimento)
        $listasQuery->where(function($q) use ($estabelecimento) {
            $q->where('escopo', 'estadual');
            if ($estabelecimento->municipio_id) {
                $q->orWhere(function($q2) use ($estabelecimento) {
                    $q2->where('escopo', 'municipal')
                       ->where('municipio_id', $estabelecimento->municipio_id);
                });
            }
        });

        $listas = $listasQuery->get();

        // Coleta todos os tipos de documento obrigatório das listas
        $docsObrigatorios = collect();
        foreach ($listas as $lista) {
            foreach ($lista->tiposDocumentoObrigatorio as $tipoDoc) {
                // Só adiciona se for obrigatório (pivot) e aplicável ao tipo de setor do estabelecimento
                $tipoSetorEnum = $estabelecimento->tipo_setor;
                $tipoSetor = $tipoSetorEnum instanceof \App\Enums\TipoSetor ? $tipoSetorEnum->value : ($tipoSetorEnum ?? 'privado');
                
                if ($tipoDoc->pivot->obrigatorio && $tipoDoc->aplicaAoTipoSetor($tipoSetor) && !$docsObrigatorios->contains('id', $tipoDoc->id)) {
                    $docsObrigatorios->push($tipoDoc);
                }
            }
        }

        if ($docsObrigatorios->isEmpty()) {
            // Se não tem documentos obrigatórios, considera como completo pela data de abertura
            return ['completo' => true, 'data_ultimo_aprovado' => $processo->created_at];
        }

        // Verifica o status de cada documento obrigatório no processo
        // Considera apenas documentos que NÃO estão em pasta vinculada a unidade
        // (docs do processo principal/raiz) e NÃO estão em pasta concluída
        $pastasUnidadeIds = $processo->pastas->whereNotNull('unidade_id')->pluck('id')->toArray();
        $pastasConcluidasIds = $processo->pastas->where('status', 'concluida')->pluck('id')->toArray();

        $todosAprovados = true;
        $dataUltimoAprovado = null;
        
        foreach ($docsObrigatorios as $docObrigatorio) {
            $documentoQuery = ProcessoDocumento::where('processo_id', $processo->id)
                ->where('tipo_documento_obrigatorio_id', $docObrigatorio->id)
                ->where('status_aprovacao', 'aprovado');

            if (!empty($pastasUnidadeIds)) {
                $documentoQuery->where(function ($q) use ($pastasUnidadeIds) {
                    $q->whereNull('pasta_id')
                      ->orWhereNotIn('pasta_id', $pastasUnidadeIds);
                });
            }

            if (!empty($pastasConcluidasIds)) {
                $documentoQuery->where(function ($q) use ($pastasConcluidasIds) {
                    $q->whereNull('pasta_id')
                      ->orWhereNotIn('pasta_id', $pastasConcluidasIds);
                });
            }

            $documento = $documentoQuery
                ->orderByRaw('COALESCE(aprovado_em, updated_at) DESC')
                ->first();
            
            if (!$documento) {
                $todosAprovados = false;
                break;
            }
            
            // Guarda a data mais recente de aprovação
            $dataReferenciaAprovacao = $documento->aprovado_em ?? $documento->updated_at;

            if ($dataReferenciaAprovacao && (!$dataUltimoAprovado || $dataReferenciaAprovacao > $dataUltimoAprovado)) {
                $dataUltimoAprovado = $dataReferenciaAprovacao;
            }
        }

        return [
            'completo' => $todosAprovados,
            'data_ultimo_aprovado' => $dataUltimoAprovado
        ];
    }

    /**
     * Consulta processo por CNPJ
     */
    public function consultarProcesso(Request $request)
    {
        $request->validate([
            'cnpj' => 'required|string|min:14|max:18',
        ]);

        // TODO: Implementar lógica de consulta
        return redirect()->back()->with('success', 'Consulta realizada com sucesso!');
    }

    /**
     * Verifica autenticidade de documento
     */
    public function verificarDocumento(Request $request)
    {
        $request->validate([
            'codigo_verificador' => 'required|string|min:6',
        ]);

        // TODO: Implementar lógica de verificação
        return redirect()->back()->with('success', 'Documento verificado com sucesso!');
    }
}
