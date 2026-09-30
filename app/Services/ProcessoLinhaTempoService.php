<?php

namespace App\Services;

use App\Models\DocumentoDigital;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoEvento;
use App\Models\TipoSetor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Linha do tempo de um processo: quanto tempo levou cada etapa (abertura → envio da empresa →
 * documentação completa → alvará → arquivamento) e quanto tempo ficou em cada setor.
 *
 * Fontes: data de criação do processo, documentos enviados pela empresa (processo_documentos),
 * aprovação dos documentos obrigatórios, Alvará Sanitário assinado (documentos_digitais) e os eventos
 * de tramitação/arquivamento (processo_eventos).
 */
class ProcessoLinhaTempoService
{
    public const MARCOS = [
        'abertura' => ['titulo' => 'Processo aberto', 'curto' => 'Abertura', 'icone' => '📂'],
        'primeiro_envio' => ['titulo' => 'Empresa enviou os primeiros documentos', 'curto' => '1º envio da empresa', 'icone' => '📤'],
        'doc_completa' => ['titulo' => 'Documentação obrigatória completa (aprovada)', 'curto' => 'Documentação completa', 'icone' => '✅'],
        'alvara' => ['titulo' => 'Alvará Sanitário emitido', 'curto' => 'Alvará emitido', 'icone' => '🏅'],
        'arquivamento' => ['titulo' => 'Processo arquivado', 'curto' => 'Arquivamento', 'icone' => '🗄️'],
    ];

    /** Intervalos usados nas médias do relatório. */
    public const INTERVALOS = [
        'abertura_envio' => ['de' => 'abertura', 'ate' => 'primeiro_envio', 'titulo' => 'Da abertura ao 1º envio da empresa', 'quem' => 'empresa'],
        'envio_completa' => ['de' => 'primeiro_envio', 'ate' => 'doc_completa', 'titulo' => 'Do 1º envio até a documentação completa', 'quem' => 'ambos'],
        'completa_alvara' => ['de' => 'doc_completa', 'ate' => 'alvara', 'titulo' => 'Da documentação completa até o alvará', 'quem' => 'vigilancia'],
        'abertura_alvara' => ['de' => 'abertura', 'ate' => 'alvara', 'titulo' => 'Tempo total até o alvará', 'quem' => 'total'],
    ];

    private const EVENTOS_TRAMITACAO = ['processo_atribuido', 'processo_arquivado', 'processo_desarquivado'];

    private ?Collection $nomesSetores = null;

    /**
     * @param array $opcoes Dados pré-carregados (usados no relatório para evitar consultas por processo):
     *   - eventos: Collection de ProcessoEvento de tramitação, em ordem cronológica
     *   - documentos: Collection de ProcessoDocumento do processo
     *   - data_alvara: ?Carbon (false = não tem alvará)
     *   - doc_completa: ?bool (null = calcular pelo checklist)
     */
    public function calcular(Processo $processo, array $opcoes = []): array
    {
        $agora = now();
        $documentos = $opcoes['documentos'] ?? ProcessoDocumento::where('processo_id', $processo->id)
            ->get(['id', 'processo_id', 'tipo_usuario', 'created_at', 'updated_at', 'status_aprovacao', 'aprovado_em', 'tipo_documento_obrigatorio_id']);
        $eventos = $opcoes['eventos'] ?? ProcessoEvento::where('processo_id', $processo->id)
            ->whereIn('tipo_evento', self::EVENTOS_TRAMITACAO)
            ->orderBy('created_at')
            ->get();

        // ---- Marcos ----
        $marcos = ['abertura' => $processo->created_at->copy()];

        $primeiroEnvio = $documentos->where('tipo_usuario', 'externo')->min('created_at');
        if ($primeiroEnvio) {
            $marcos['primeiro_envio'] = Carbon::parse($primeiroEnvio);
        }

        $docCompleta = $opcoes['doc_completa'] ?? $this->documentacaoCompleta($processo);
        if ($docCompleta) {
            $data = $this->dataDocumentacaoCompleta($documentos);
            if ($data) {
                $marcos['doc_completa'] = $data;
            }
        }

        $dataAlvara = array_key_exists('data_alvara', $opcoes) ? $opcoes['data_alvara'] : $this->datasAlvara(collect([$processo->id]))->get($processo->id);
        if ($dataAlvara) {
            $marcos['alvara'] = Carbon::parse($dataAlvara);
        }

        $arquivado = $processo->status === 'arquivado';
        if ($arquivado && $processo->data_arquivamento) {
            $marcos['arquivamento'] = $processo->data_arquivamento->copy();
        }

        $fim = $marcos['arquivamento'] ?? $agora;
        asort($marcos); // ordem cronológica (em dados reais a ordem pode variar)

        // ---- Etapas (entre marcos consecutivos) ----
        $chaves = array_keys($marcos);
        $etapas = [];
        foreach ($chaves as $i => $chave) {
            $proxima = $chaves[$i + 1] ?? null;
            if (!$proxima && $chave === 'arquivamento') {
                break;
            }
            $inicio = $marcos[$chave];
            $termino = $proxima ? $marcos[$proxima] : $fim;
            $etapas[] = [
                'de' => $chave,
                'ate' => $proxima,
                'titulo' => self::MARCOS[$chave]['curto'] . ' → ' . ($proxima ? self::MARCOS[$proxima]['curto'] : 'hoje'),
                'inicio' => $inicio,
                'fim' => $termino,
                'segundos' => max(0, $termino->getTimestamp() - $inicio->getTimestamp()),
                'em_andamento' => !$proxima,
            ];
        }

        // ---- Trajeto pelos setores ----
        $trajeto = $this->trajeto($processo, $eventos, $fim);

        $setores = collect($trajeto)
            ->reject(fn ($t) => $t['arquivado'])
            ->groupBy('setor')
            ->map(fn ($itens, $setor) => [
                'setor' => $setor,
                'nome' => $itens->first()['nome'],
                'segundos' => $itens->sum('segundos'),
                'passagens' => $itens->count(),
                'atual' => $itens->contains('atual', true),
            ])
            ->sortByDesc('segundos')
            ->values()
            ->all();

        return [
            'marcos' => collect($marcos)->map(fn ($data, $chave) => self::MARCOS[$chave] + ['chave' => $chave, 'data' => $data])->values()->all(),
            'marcos_por_chave' => $marcos,
            'etapas' => $etapas,
            'trajeto' => $trajeto,
            'setores' => $setores,
            'inicio' => $marcos['abertura'],
            'fim' => $fim,
            'em_andamento' => !$arquivado,
            'segundos_total' => max(0, $fim->getTimestamp() - $marcos['abertura']->getTimestamp()),
            'segundos_parado' => (int) $processo->getTempoTotalParadoConsiderandoParadaAtual(),
            // Alvará só existe no licenciamento
            'faltando' => array_values(array_diff(
                $processo->tipo === 'licenciamento' ? ['primeiro_envio', 'doc_completa', 'alvara'] : ['primeiro_envio', 'doc_completa'],
                array_keys($marcos)
            )),
        ];
    }

    /**
     * Médias do relatório: tempo médio entre marcos e tempo médio em cada setor.
     *
     * @param Collection $processos Processos (Eloquent)
     * @param array<int, bool> $docCompletaPorProcesso processo_id => documentação obrigatória completa?
     */
    public function resumo(Collection $processos, array $docCompletaPorProcesso = []): array
    {
        if ($processos->isEmpty()) {
            return ['total' => 0, 'intervalos' => [], 'setores' => []];
        }

        $ids = $processos->pluck('id');
        $eventos = ProcessoEvento::whereIn('processo_id', $ids)
            ->whereIn('tipo_evento', self::EVENTOS_TRAMITACAO)
            ->orderBy('created_at')
            ->get(['id', 'processo_id', 'tipo_evento', 'dados_adicionais', 'created_at'])
            ->groupBy('processo_id');
        $documentos = ProcessoDocumento::whereIn('processo_id', $ids)
            ->get(['id', 'processo_id', 'tipo_usuario', 'created_at', 'updated_at', 'status_aprovacao', 'aprovado_em', 'tipo_documento_obrigatorio_id'])
            ->groupBy('processo_id');
        $alvaras = $this->datasAlvara($ids);

        $linhas = $processos->map(fn ($p) => $this->calcular($p, [
            'eventos' => $eventos->get($p->id, collect()),
            'documentos' => $documentos->get($p->id, collect()),
            'data_alvara' => $alvaras->get($p->id) ?? false,
            'doc_completa' => $docCompletaPorProcesso[$p->id] ?? false,
        ]));

        $intervalos = collect(self::INTERVALOS)->map(function ($info, $chave) use ($linhas) {
            $duracoes = $linhas
                ->map(function ($l) use ($info) {
                    $de = $l['marcos_por_chave'][$info['de']] ?? null;
                    $ate = $l['marcos_por_chave'][$info['ate']] ?? null;

                    return $de && $ate && $ate->greaterThanOrEqualTo($de) ? $ate->getTimestamp() - $de->getTimestamp() : null;
                })
                ->filter(fn ($s) => $s !== null)
                ->sort()
                ->values();

            return $info + [
                'chave' => $chave,
                'processos' => $duracoes->count(),
                'media' => $duracoes->isNotEmpty() ? (int) round($duracoes->avg()) : null,
                'mediana' => $duracoes->isNotEmpty() ? (int) $duracoes->median() : null,
                'maximo' => $duracoes->max(),
            ];
        })->all();

        // Tempo médio que um processo fica em cada setor (entre os que passaram por ele)
        $setores = $linhas
            ->flatMap(fn ($l) => $l['setores'])
            ->groupBy('setor')
            ->map(fn ($itens) => [
                'nome' => $itens->first()['nome'],
                'processos' => $itens->count(),
                'media' => (int) round($itens->avg('segundos')),
                'agora' => $itens->where('atual', true)->count(),
            ])
            ->sortByDesc('media')
            ->values()
            ->all();

        return ['total' => $processos->count(), 'intervalos' => $intervalos, 'setores' => $setores];
    }

    /**
     * Data do primeiro Alvará Sanitário assinado de cada processo.
     */
    public function datasAlvara(Collection $processoIds): Collection
    {
        return DocumentoDigital::query()
            ->whereIn('processo_id', $processoIds)
            ->where('status', 'assinado')
            ->whereHas('tipoDocumento', fn ($q) => $q->where('codigo', 'alvara_sanitario'))
            ->withMax('assinaturas', 'assinado_em')
            ->get(['id', 'processo_id', 'finalizado_em', 'updated_at'])
            ->map(fn ($d) => [
                'processo_id' => $d->processo_id,
                'data' => Carbon::parse($d->finalizado_em ?? $d->assinaturas_max_assinado_em ?? $d->updated_at),
            ])
            ->groupBy('processo_id')
            ->map(fn ($itens) => $itens->min('data'));
    }

    /**
     * Formata segundos de forma legível: "35 min", "5 h", "12 dias", "3 meses e 4 dias".
     */
    public static function formatarDuracao(?int $segundos): string
    {
        if ($segundos === null) {
            return '—';
        }
        if ($segundos < 3600) {
            return max(1, (int) round($segundos / 60)) . ' min';
        }
        if ($segundos < 86400) {
            return (int) round($segundos / 3600) . ' h';
        }

        $dias = (int) floor($segundos / 86400);
        if ($dias < 60) {
            return $dias . ($dias === 1 ? ' dia' : ' dias');
        }

        $meses = intdiv($dias, 30);
        $resto = $dias % 30;

        return $meses . ' meses' . ($resto ? " e {$resto} " . ($resto === 1 ? 'dia' : 'dias') : '');
    }

    /**
     * Segmentos por setor a partir dos eventos de tramitação e arquivamento.
     */
    private function trajeto(Processo $processo, Collection $eventos, Carbon $fim): array
    {
        $primeiraAtribuicao = $eventos->firstWhere('tipo_evento', 'processo_atribuido');
        $setor = $primeiraAtribuicao
            ? data_get($primeiraAtribuicao->dados_adicionais, 'setor_anterior')
            : ($processo->setor_atual ?? $processo->setor_antes_arquivar);
        $responsavel = $primeiraAtribuicao
            ? data_get($primeiraAtribuicao->dados_adicionais, 'responsavel_anterior')
            : ($processo->relationLoaded('responsavelAtual') ? $processo->responsavelAtual?->nome : null);

        $segmentos = [];
        $cursor = $processo->created_at->copy();
        $arquivado = false;
        $setorAntesArquivar = $setor;

        $fechar = function (Carbon $ate) use (&$segmentos, &$cursor, &$setor, &$responsavel, &$arquivado) {
            if ($ate->lessThanOrEqualTo($cursor)) {
                return;
            }
            $chave = $arquivado ? '__arquivado' : ($setor ?: '__sem_setor');
            $ultimo = end($segmentos);

            // Mudança só de responsável no mesmo setor: continua o mesmo trecho
            if ($ultimo && $ultimo['setor'] === $chave) {
                $segmentos[key($segmentos)]['fim'] = $ate->copy();
                $segmentos[key($segmentos)]['segundos'] += $ate->getTimestamp() - $cursor->getTimestamp();
                if ($responsavel && !in_array($responsavel, $segmentos[key($segmentos)]['responsaveis'], true)) {
                    $segmentos[key($segmentos)]['responsaveis'][] = $responsavel;
                }
            } else {
                $segmentos[] = [
                    'setor' => $chave,
                    'nome' => $this->nomeSetor($chave),
                    'inicio' => $cursor->copy(),
                    'fim' => $ate->copy(),
                    'segundos' => $ate->getTimestamp() - $cursor->getTimestamp(),
                    'responsaveis' => $responsavel ? [$responsavel] : [],
                    'arquivado' => $arquivado,
                    'atual' => false,
                ];
            }
            $cursor = $ate->copy();
        };

        foreach ($eventos as $evento) {
            $quando = $evento->created_at->copy();
            if ($quando->greaterThan($fim)) {
                break;
            }
            $fechar($quando);

            switch ($evento->tipo_evento) {
                case 'processo_atribuido':
                    $dados = $evento->dados_adicionais ?? [];
                    $setor = array_key_exists('setor_novo', $dados) ? $dados['setor_novo'] : $setor;
                    $responsavel = $dados['responsavel_novo'] ?? null;
                    break;
                case 'processo_arquivado':
                    $setorAntesArquivar = $setor;
                    $arquivado = true;
                    break;
                case 'processo_desarquivado':
                    $arquivado = false;
                    $setor = $setorAntesArquivar;
                    break;
            }
        }

        // Trecho final (até hoje ou até o arquivamento definitivo)
        if (!$arquivado) {
            $fechar($fim);
            if ($processo->status !== 'arquivado' && $segmentos) {
                $segmentos[array_key_last($segmentos)]['atual'] = true;
            }
        }

        // Descarta trechos de menos de 1 minuto (ex.: atribuição corrigida logo em seguida) e junta
        // os trechos vizinhos do mesmo setor que ficaram separados por eles
        $filtrados = count($segmentos) > 1 ? array_filter($segmentos, fn ($s) => $s['segundos'] >= 60) : $segmentos;
        $resultado = [];
        foreach ($filtrados as $segmento) {
            $ultimo = $resultado ? $resultado[array_key_last($resultado)] : null;
            if ($ultimo && $ultimo['setor'] === $segmento['setor']) {
                $indice = array_key_last($resultado);
                $resultado[$indice]['fim'] = $segmento['fim'];
                $resultado[$indice]['segundos'] += $segmento['segundos'];
                $resultado[$indice]['responsaveis'] = array_values(array_unique(array_merge($ultimo['responsaveis'], $segmento['responsaveis'])));
                $resultado[$indice]['atual'] = $ultimo['atual'] || $segmento['atual'];
            } else {
                $resultado[] = $segmento;
            }
        }

        return $resultado;
    }

    private function nomeSetor(string $codigo): string
    {
        if ($codigo === '__arquivado') {
            return 'Arquivado (temporariamente)';
        }
        if ($codigo === '__sem_setor') {
            return 'Sem setor definido';
        }

        $this->nomesSetores ??= TipoSetor::pluck('nome', 'codigo');

        return $this->nomesSetores[$codigo] ?? ucwords(str_replace('_', ' ', $codigo));
    }

    /**
     * Documentação obrigatória completa segundo o checklist (mesma regra da tela do processo).
     */
    private function documentacaoCompleta(Processo $processo): bool
    {
        $obrigatorios = $processo->getDocumentosObrigatoriosChecklist()->where('obrigatorio', true);

        return $obrigatorios->isNotEmpty() && $obrigatorios->every(fn ($d) => $d['status'] === 'aprovado');
    }

    /**
     * Data em que a documentação ficou completa: aprovação mais recente entre os documentos obrigatórios aprovados.
     */
    private function dataDocumentacaoCompleta(Collection $documentos): ?Carbon
    {
        $data = $documentos
            ->whereNotNull('tipo_documento_obrigatorio_id')
            ->where('status_aprovacao', 'aprovado')
            ->map(fn ($d) => $d->aprovado_em ?? $d->updated_at)
            ->filter()
            ->max();

        return $data ? Carbon::parse($data) : null;
    }
}
