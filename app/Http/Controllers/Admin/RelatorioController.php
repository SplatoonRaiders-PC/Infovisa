<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Estabelecimento;
use App\Models\Processo;
use App\Models\OrdemServico;
use App\Models\DocumentoDigital;
use App\Models\TipoDocumento;
use App\Models\UsuarioInterno;
use App\Models\Atividade;
use App\Models\AtividadeEquipamentoRadiacao;
use App\Models\EquipamentoRadiacao;
use App\Models\Municipio;
use App\Support\CnaeCatalogo;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\PesquisaSatisfacao;
use App\Models\TipoAcao;
use App\Models\PesquisaSatisfacaoResposta;
use App\Models\ConfiguracaoSistema;
use App\Models\DocumentoAssinatura;
use App\Models\DocumentoResposta;
use Illuminate\Support\Facades\Http;

class RelatorioController extends Controller
{
    private function normalizarCodigoCnae(?string $codigo): ?string
    {
        if (!$codigo) {
            return null;
        }

        $normalizado = preg_replace('/[^0-9]/', '', (string) $codigo);

        return $normalizado !== '' ? $normalizado : null;
    }

    private function formatarCodigoCnae(?string $codigo): string
    {
        $normalizado = $this->normalizarCodigoCnae($codigo);

        if (!$normalizado) {
            return '-';
        }

        if (strlen($normalizado) === 7) {
            return substr($normalizado, 0, 4) . '-' . substr($normalizado, 4, 1) . '/' . substr($normalizado, 5, 2);
        }

        return $normalizado;
    }

    private function estabelecimentoDentroEscopoRelatorio(Estabelecimento $estabelecimento, UsuarioInterno $usuario, ?string $competenciaFiltro = null): bool
    {
        if ($usuario->isMunicipal()) {
            if (!$usuario->municipio_id || (int) $estabelecimento->municipio_id !== (int) $usuario->municipio_id) {
                return false;
            }

            return $estabelecimento->isCompetenciaMunicipal();
        }

        if ($usuario->isEstadual()) {
            return $estabelecimento->isCompetenciaEstadual();
        }

        if ($competenciaFiltro === 'estadual') {
            return $estabelecimento->isCompetenciaEstadual();
        }

        if ($competenciaFiltro === 'municipal') {
            return $estabelecimento->isCompetenciaMunicipal();
        }

        return true;
    }

    private function aplicarFiltroCompetenciaDocumentosGerados($query, UsuarioInterno $usuario, ?string $competencia): void
    {
        if ($usuario->isEstadual()) {
            $competencia = 'estadual';
        } elseif ($usuario->isMunicipal()) {
            $competencia = 'municipal';
        } elseif (!$usuario->isAdmin()) {
            $query->whereRaw('1 = 0');

            return;
        }

        if ($competencia === 'estadual') {
            $query->whereHas('usuarioCriador', function ($q) {
                $q->whereIn('nivel_acesso', [
                    \App\Enums\NivelAcesso::GestorEstadual->value,
                    \App\Enums\NivelAcesso::TecnicoEstadual->value,
                ]);
            })->whereHas('processo.estabelecimento');

            return;
        }

        if ($competencia === 'municipal') {
            $query->whereHas('usuarioCriador', function ($q) use ($usuario) {
                $q->whereIn('nivel_acesso', [
                    \App\Enums\NivelAcesso::GestorMunicipal->value,
                    \App\Enums\NivelAcesso::TecnicoMunicipal->value,
                ]);

                if ($usuario->isMunicipal() && $usuario->municipio_id) {
                    $q->where('municipio_id', $usuario->municipio_id);
                }
            });

            if ($usuario->isMunicipal() && $usuario->municipio_id) {
                $query->whereHas('processo.estabelecimento', function ($q) use ($usuario) {
                    $q->where('municipio_id', $usuario->municipio_id);
                });
            } else {
                $query->whereHas('processo.estabelecimento');
            }
        }
    }

    private function obterDescricaoCnaeDoEstabelecimento(Estabelecimento $estabelecimento, string $codigo, array $catalogoAtividades): string
    {
        foreach (($estabelecimento->atividades_exercidas ?? []) as $atividade) {
            if (!is_array($atividade)) {
                continue;
            }

            $codigoAtividade = $this->normalizarCodigoCnae($atividade['codigo'] ?? null);

            if ($codigoAtividade !== $codigo) {
                continue;
            }

            $descricao = trim((string) ($atividade['descricao'] ?? $atividade['nome'] ?? ''));

            if ($descricao !== '') {
                return $descricao;
            }
        }

        if ($this->normalizarCodigoCnae($estabelecimento->cnae_fiscal) === $codigo && !empty($estabelecimento->cnae_fiscal_descricao)) {
            return $estabelecimento->cnae_fiscal_descricao;
        }

        foreach (($estabelecimento->cnaes_secundarios ?? []) as $cnaeSecundario) {
            if (!is_array($cnaeSecundario)) {
                continue;
            }

            $codigoSecundario = $this->normalizarCodigoCnae($cnaeSecundario['codigo'] ?? null);

            if ($codigoSecundario !== $codigo) {
                continue;
            }

            $descricao = trim((string) ($cnaeSecundario['descricao'] ?? $cnaeSecundario['nome'] ?? ''));

            if ($descricao !== '') {
                return $descricao;
            }
        }

        return $catalogoAtividades[$codigo] ?? 'CNAE sem descrição cadastrada';
    }

    private function montarCnaesRelatorioEstabelecimento(Estabelecimento $estabelecimento, array $catalogoAtividades): array
    {
        return collect($estabelecimento->getTodasAtividades())
            ->map(fn($codigo) => $this->normalizarCodigoCnae($codigo))
            ->filter()
            ->unique()
            ->map(function ($codigo) use ($estabelecimento, $catalogoAtividades) {
                return [
                    'codigo' => $codigo,
                    'codigo_formatado' => $this->formatarCodigoCnae($codigo),
                    'descricao' => $this->obterDescricaoCnaeDoEstabelecimento($estabelecimento, $codigo, $catalogoAtividades),
                ];
            })
            ->sortBy('codigo_formatado')
            ->values()
            ->all();
    }

    private function paginarColecao($itens, int $porPagina, Request $request, string $pageName = 'page'): LengthAwarePaginator
    {
        $paginaAtual = LengthAwarePaginator::resolveCurrentPage($pageName);
        $colecao = $itens instanceof \Illuminate\Support\Collection ? $itens->values() : collect($itens)->values();
        $fatia = $colecao->slice(($paginaAtual - 1) * $porPagina, $porPagina)->values();

        return new LengthAwarePaginator(
            $fatia,
            $colecao->count(),
            $porPagina,
            $paginaAtual,
            [
                'path' => url($request->path()),
                'pageName' => $pageName,
                'query' => $request->query(),
            ]
        );
    }

    private function calcularMediaRespostaPesquisa(PesquisaSatisfacaoResposta $resposta): ?float
    {
        $perguntasEscalaIds = $resposta->pesquisa?->perguntas
            ?->where('tipo', 'escala_1_5')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all() ?? [];

        if (empty($perguntasEscalaIds)) {
            return null;
        }

        $mapPerguntas = array_flip($perguntasEscalaIds);
        $respostasJson = is_array($resposta->respostas) ? $resposta->respostas : [];
        $somaNotas = 0;
        $totalNotas = 0;

        foreach ($respostasJson as $item) {
            $perguntaId = (int) ($item['pergunta_id'] ?? 0);
            $valor = $item['valor'] ?? null;

            if (!isset($mapPerguntas[$perguntaId])) {
                continue;
            }

            $nota = is_numeric($valor) ? (int) $valor : null;

            if ($nota === null || $nota < 1 || $nota > 5) {
                continue;
            }

            $somaNotas += $nota;
            $totalNotas++;
        }

        return $totalNotas > 0 ? round($somaNotas / $totalNotas, 1) : null;
    }

    private function calcularMediaGeralNotasPesquisa($respostasFiltradas): ?float
    {
        if ($respostasFiltradas->isEmpty()) {
            return null;
        }

        $pesquisaIds = $respostasFiltradas->pluck('pesquisa_id')->filter()->unique()->values();

        if ($pesquisaIds->isEmpty()) {
            return null;
        }

        $perguntasEscalaIds = \App\Models\PesquisaSatisfacaoPergunta::whereIn('pesquisa_id', $pesquisaIds)
            ->where('tipo', 'escala_1_5')
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (empty($perguntasEscalaIds)) {
            return null;
        }

        $perguntasEscalaMap = array_flip($perguntasEscalaIds);
        $somaNotas = 0;
        $totalNotas = 0;

        foreach ($respostasFiltradas as $resposta) {
            $respostasJson = is_array($resposta->respostas) ? $resposta->respostas : [];

            foreach ($respostasJson as $item) {
                $perguntaId = (int) ($item['pergunta_id'] ?? 0);
                $valor = $item['valor'] ?? null;

                if (!isset($perguntasEscalaMap[$perguntaId])) {
                    continue;
                }

                $nota = is_numeric($valor) ? (int) $valor : null;

                if ($nota === null || $nota < 1 || $nota > 5) {
                    continue;
                }

                $somaNotas += $nota;
                $totalNotas++;
            }
        }

        return $totalNotas > 0 ? round($somaNotas / $totalNotas, 1) : null;
    }

    private function montarQueryRespostasPesquisaSatisfacao(Request $request)
    {
        $query = PesquisaSatisfacaoResposta::with([
            'pesquisa.perguntas', 'ordemServico', 'estabelecimento',
            'usuarioInterno', 'usuarioExterno',
        ])->orderByDesc('created_at');

        if ($request->filled('pesquisa_id')) {
            $query->where('pesquisa_id', $request->pesquisa_id);
        }

        if ($request->filled('tipo_respondente')) {
            $query->where('tipo_respondente', $request->tipo_respondente);
        }

        if ($request->filled('ordem_servico_id')) {
            $query->where('ordem_servico_id', $request->ordem_servico_id);
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->data_inicio);
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->data_fim);
        }

        if ($request->filled('busca')) {
            $busca = $request->busca;
            $query->where(function ($q) use ($busca) {
                $q->where('respondente_nome', 'ilike', "%{$busca}%")
                    ->orWhere('respondente_email', 'ilike', "%{$busca}%");
            });
        }

        return $query;
    }

    /**
     * Exibe a página principal de relatórios
     */
    public function index()
    {
        return view('admin.relatorios.index');
    }

    /**
     * Relatório de estabelecimentos por CNAE / tipo de atividade, com escopo automático por perfil.
     *
     * Filtros de CNAE (atividade, divisão, CNAE exato) selecionam os estabelecimentos que exercem
     * pelo menos uma atividade correspondente; os totais por CNAE consideram só as atividades correspondentes.
     */
    public function estabelecimentosPorCnae(Request $request)
    {
        $usuario = auth('interno')->user();

        $filtros = [
            'atividade' => (string) $request->input('atividade', ''),
            'divisao' => preg_replace('/\D/', '', (string) $request->input('divisao', '')),
            'cnae' => $this->normalizarCodigoCnae($request->input('cnae')),
            'considerar' => $request->input('considerar') === 'principal' ? 'principal' : 'todas',
            'situacao' => in_array($request->input('situacao'), ['todos', 'pendentes', 'rejeitados', 'inativos'], true)
                ? $request->input('situacao')
                : 'ativos',
            'competencia' => $usuario->isAdmin() && in_array($request->input('competencia'), ['estadual', 'municipal'], true)
                ? $request->input('competencia')
                : null,
            'municipio_id' => !$usuario->isMunicipal() && $request->filled('municipio_id') ? $request->integer('municipio_id') : null,
            'busca' => trim((string) $request->input('busca_estabelecimento', '')),
        ];

        [$filtroArea, $filtroTipo] = [null, null];
        if (str_starts_with($filtros['atividade'], 'area:') && isset(CnaeCatalogo::AREAS[substr($filtros['atividade'], 5)])) {
            $filtroArea = substr($filtros['atividade'], 5);
        } elseif (str_starts_with($filtros['atividade'], 'tipo:') && isset(CnaeCatalogo::TIPOS[substr($filtros['atividade'], 5)])) {
            $filtroTipo = substr($filtros['atividade'], 5);
        } else {
            $filtros['atividade'] = '';
        }

        $catalogoAtividades = Atividade::ativas()
            ->get(['codigo_cnae', 'descricao', 'nome'])
            ->mapWithKeys(function ($atividade) {
                $codigo = preg_replace('/[^0-9]/', '', (string) $atividade->codigo_cnae);
                $descricao = trim((string) ($atividade->descricao ?: $atividade->nome));

                return $codigo !== '' ? [$codigo => $descricao] : [];
            })
            ->toArray();

        // 1) Estabelecimentos do escopo (perfil + filtros que não são de CNAE)
        $query = Estabelecimento::query()
            ->with('municipio')
            ->orderByRaw('COALESCE(nome_fantasia, razao_social, nome_completo) asc');

        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $query->where('municipio_id', $usuario->municipio_id);
        }

        if ($filtros['municipio_id']) {
            $query->where('municipio_id', $filtros['municipio_id']);
        }

        match ($filtros['situacao']) {
            'ativos' => $query->where('status', 'aprovado')->where('ativo', true),
            'pendentes' => $query->where('status', 'pendente'),
            'rejeitados' => $query->where('status', 'rejeitado'),
            'inativos' => $query->where('ativo', false),
            default => null,
        };

        if ($filtros['busca'] !== '') {
            $busca = $filtros['busca'];
            $buscaDigitos = preg_replace('/\D/', '', $busca);

            $query->where(function ($subQuery) use ($busca, $buscaDigitos) {
                $subQuery->where('nome_fantasia', 'ilike', "%{$busca}%")
                    ->orWhere('razao_social', 'ilike', "%{$busca}%")
                    ->orWhere('nome_completo', 'ilike', "%{$busca}%");

                if (strlen($buscaDigitos) >= 3) {
                    $subQuery->orWhere('cnpj', 'ilike', "%{$buscaDigitos}%")
                        ->orWhere('cpf', 'ilike', "%{$buscaDigitos}%");
                }
            });
        }

        $escopo = $query->get()
            ->filter(fn($estabelecimento) => $this->estabelecimentoDentroEscopoRelatorio($estabelecimento, $usuario, $filtros['competencia']))
            ->map(function ($estabelecimento) use ($catalogoAtividades, $filtros) {
                $principal = $this->codigoCnaePrincipal($estabelecimento);

                $cnaes = collect($this->montarCnaesRelatorioEstabelecimento($estabelecimento, $catalogoAtividades))
                    ->map(fn($cnae) => $cnae + [
                        'tipo' => CnaeCatalogo::tipoDoCnae($cnae['codigo']),
                        'divisao' => substr($cnae['codigo'], 0, 2),
                        'principal' => $cnae['codigo'] === $principal,
                    ]);

                $estabelecimento->cnaes_relatorio = $cnaes;
                $estabelecimento->cnaes_considerados = $filtros['considerar'] === 'principal'
                    ? $cnaes->where('principal', true)->values()
                    : $cnaes;
                $estabelecimento->competencia_relatorio = $estabelecimento->isCompetenciaEstadual() ? 'estadual' : 'municipal';

                return $estabelecimento;
            })
            ->filter(fn($estabelecimento) => $estabelecimento->cnaes_considerados->isNotEmpty())
            ->values();

        // 2) Aplica filtros de CNAE
        $cnaeCorresponde = function (array $cnae) use ($filtroArea, $filtroTipo, $filtros) {
            if ($filtroTipo && $cnae['tipo'] !== $filtroTipo) {
                return false;
            }
            if ($filtroArea && CnaeCatalogo::areaDoTipo($cnae['tipo']) !== $filtroArea) {
                return false;
            }
            if ($filtros['divisao'] !== '' && $cnae['divisao'] !== $filtros['divisao']) {
                return false;
            }
            if ($filtros['cnae'] && $cnae['codigo'] !== $filtros['cnae']) {
                return false;
            }

            return true;
        };

        $filtrados = $escopo
            ->map(function ($estabelecimento) use ($cnaeCorresponde) {
                $estabelecimento->cnaes_correspondentes = $estabelecimento->cnaes_considerados->filter($cnaeCorresponde)->values();

                return $estabelecimento;
            })
            ->filter(fn($estabelecimento) => $estabelecimento->cnaes_correspondentes->isNotEmpty())
            ->values();

        if ($request->input('exportar') === 'csv') {
            return $this->exportarEstabelecimentosPorCnaeCsv($filtrados);
        }

        $totalFiltrados = $filtrados->count();
        $percentual = fn(int $valor) => $totalFiltrados > 0 ? round($valor * 100 / $totalFiltrados, 1) : 0;

        // 3) Agregações
        $resumoCnaes = $filtrados
            ->flatMap(fn($estabelecimento) => $estabelecimento->cnaes_correspondentes->map(fn($cnae) => $cnae + [
                'estabelecimento_id' => $estabelecimento->id,
                'competencia' => $estabelecimento->competencia_relatorio,
            ]))
            ->groupBy('codigo')
            ->map(function ($itens) use ($percentual) {
                $primeiro = $itens->first();
                $total = $itens->pluck('estabelecimento_id')->unique()->count();

                return [
                    'codigo' => $primeiro['codigo'],
                    'codigo_formatado' => $primeiro['codigo_formatado'],
                    'descricao' => $primeiro['descricao'],
                    'tipo' => $primeiro['tipo'],
                    'tipo_nome' => CnaeCatalogo::nomeTipo($primeiro['tipo']),
                    'total' => $total,
                    'como_principal' => $itens->where('principal', true)->pluck('estabelecimento_id')->unique()->count(),
                    'estadual' => $itens->where('competencia', 'estadual')->pluck('estabelecimento_id')->unique()->count(),
                    'municipal' => $itens->where('competencia', 'municipal')->pluck('estabelecimento_id')->unique()->count(),
                    'percentual' => $percentual($total),
                ];
            })
            ->sortByDesc('total')
            ->values();

        $contarPorTipo = fn($colecao, string $campo) => $colecao
            ->flatMap(fn($estabelecimento) => $estabelecimento->{$campo}->pluck('tipo')->map(fn($tipo) => $tipo ?? 'outros')->unique())
            ->countBy()
            ->sortDesc();

        $porTipo = $contarPorTipo($filtrados, 'cnaes_correspondentes')
            ->map(fn($total, $slug) => [
                'slug' => $slug,
                'nome' => $slug === 'outros' ? 'Outras atividades' : CnaeCatalogo::nomeTipo($slug),
                'area' => CnaeCatalogo::areaDoTipo($slug),
                'total' => $total,
                'percentual' => $percentual($total),
            ])
            ->sortBy(fn($item) => $item['slug'] === 'outros' ? 1 : 0) // "Outras atividades" sempre por último
            ->values();

        $porMunicipio = $filtrados
            ->groupBy(fn($estabelecimento) => mb_strtoupper($estabelecimento->municipio->nome ?? ($estabelecimento->cidade ?: 'Não informado'), 'UTF-8'))
            ->map->count()
            ->sortDesc();

        $totais = [
            'estabelecimentos' => $totalFiltrados,
            'escopo' => $escopo->count(),
            'cnaes' => $resumoCnaes->count(),
            'municipios' => $porMunicipio->count(),
            'estadual' => $filtrados->where('competencia_relatorio', 'estadual')->count(),
            'municipal' => $filtrados->where('competencia_relatorio', 'municipal')->count(),
        ];

        // 4) Opções dos filtros (contagens sobre o escopo, sem os filtros de CNAE)
        $contagemTiposEscopo = $contarPorTipo($escopo, 'cnaes_considerados');
        $contagemAreasEscopo = $escopo
            ->flatMap(fn($estabelecimento) => $estabelecimento->cnaes_considerados->map(fn($cnae) => CnaeCatalogo::areaDoTipo($cnae['tipo']))->filter()->unique())
            ->countBy();

        $divisoesEscopo = $escopo
            ->flatMap(fn($estabelecimento) => $estabelecimento->cnaes_considerados->pluck('divisao')->unique())
            ->countBy()
            ->sortKeys()
            ->map(fn($total, $divisao) => [
                'codigo' => str_pad((string) $divisao, 2, '0', STR_PAD_LEFT),
                'nome' => CnaeCatalogo::nomeDivisao(str_pad((string) $divisao, 2, '0', STR_PAD_LEFT)),
                'total' => $total,
            ])
            ->values();

        $cnaesEscopo = $escopo
            ->flatMap(fn($estabelecimento) => $estabelecimento->cnaes_considerados)
            ->unique('codigo')
            ->sortBy('codigo')
            ->map(fn($cnae) => ['codigo' => $cnae['codigo'], 'rotulo' => $cnae['codigo_formatado'] . ' - ' . $cnae['descricao']])
            ->values();

        $atalhos = $contagemTiposEscopo->except('outros')->take(8)
            ->map(fn($total, $slug) => ['slug' => $slug, 'nome' => CnaeCatalogo::nomeTipo($slug), 'area' => CnaeCatalogo::areaDoTipo($slug), 'total' => $total])
            ->values();

        // 5) Gráficos
        $detalharPorCnae = $filtroTipo || $filtros['cnae'] || $filtros['divisao'] !== '';
        $graficoPrincipal = $detalharPorCnae
            ? [
                'titulo' => 'Estabelecimentos por CNAE',
                'labels' => $resumoCnaes->take(15)->map(fn($item) => $item['codigo_formatado'] . ' ' . \Illuminate\Support\Str::limit($item['descricao'], 45))->values(),
                'valores' => $resumoCnaes->take(15)->pluck('total')->values(),
            ]
            : [
                'titulo' => 'Estabelecimentos por tipo de atividade',
                'labels' => $porTipo->take(15)->pluck('nome')->values(),
                'valores' => $porTipo->take(15)->pluck('total')->values(),
            ];

        $graficos = [
            'principal' => $graficoPrincipal,
            'municipios' => [
                'labels' => $porMunicipio->take(10)->keys()->values(),
                'valores' => $porMunicipio->take(10)->values(),
            ],
            'competencia' => [
                'labels' => ['Estadual', 'Municipal'],
                'valores' => [$totais['estadual'], $totais['municipal']],
            ],
        ];

        $estabelecimentos = $this->paginarColecao($filtrados, 20, $request, 'est_page');

        $municipios = !$usuario->isMunicipal()
            ? Municipio::query()->orderBy('nome')->get(['id', 'nome'])
            : collect();

        $escopoVisual = $usuario->isAdmin()
            ? 'Todos os municípios e competências'
            : ($usuario->isMunicipal()
                ? 'Município do usuário e competência municipal'
                : 'Competência estadual');

        $tituloFiltro = match (true) {
            (bool) $filtros['cnae'] => 'CNAE ' . $this->formatarCodigoCnae($filtros['cnae']),
            (bool) $filtroTipo => CnaeCatalogo::nomeTipo($filtroTipo),
            (bool) $filtroArea => CnaeCatalogo::AREAS[$filtroArea]['nome'],
            $filtros['divisao'] !== '' => 'Divisão ' . $filtros['divisao'] . ' - ' . CnaeCatalogo::nomeDivisao($filtros['divisao']),
            default => null,
        };

        return view('admin.relatorios.estabelecimentos-cnae', compact(
            'filtros',
            'resumoCnaes',
            'porTipo',
            'estabelecimentos',
            'totais',
            'graficos',
            'municipios',
            'escopoVisual',
            'tituloFiltro',
            'atalhos',
            'contagemTiposEscopo',
            'contagemAreasEscopo',
            'divisoesEscopo',
            'cnaesEscopo',
            'detalharPorCnae'
        ));
    }

    /**
     * Código (somente dígitos) da atividade principal do estabelecimento.
     */
    private function codigoCnaePrincipal(Estabelecimento $estabelecimento): ?string
    {
        foreach (($estabelecimento->atividades_exercidas ?? []) as $atividade) {
            if (is_array($atividade) && !empty($atividade['principal'])) {
                return $this->normalizarCodigoCnae($atividade['codigo'] ?? null);
            }
        }

        return $this->normalizarCodigoCnae($estabelecimento->cnae_fiscal);
    }

    private function exportarEstabelecimentosPorCnaeCsv($estabelecimentos)
    {
        $nomeArquivo = 'estabelecimentos-por-cnae-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($estabelecimentos) {
            $saida = fopen('php://output', 'w');
            fwrite($saida, "\xEF\xBB\xBF"); // BOM para o Excel reconhecer UTF-8

            fputcsv($saida, [
                'Nome fantasia', 'Razão social / Nome', 'CNPJ/CPF', 'Município', 'Competência', 'Status', 'Ativo',
                'CNAE principal', 'CNAEs correspondentes ao filtro', 'Tipos de atividade', 'Todos os CNAEs',
            ], ';');

            foreach ($estabelecimentos as $estabelecimento) {
                $principal = $estabelecimento->cnaes_relatorio->firstWhere('principal', true);
                $formatarLista = fn($cnaes) => $cnaes->map(fn($cnae) => $cnae['codigo_formatado'] . ' ' . $cnae['descricao'])->implode(' | ');

                fputcsv($saida, [
                    $estabelecimento->nome_fantasia,
                    $estabelecimento->razao_social ?: $estabelecimento->nome_completo,
                    $estabelecimento->cnpj ?: $estabelecimento->cpf,
                    $estabelecimento->municipio->nome ?? $estabelecimento->cidade,
                    ucfirst($estabelecimento->competencia_relatorio),
                    ucfirst((string) $estabelecimento->status),
                    $estabelecimento->ativo ? 'Sim' : 'Não',
                    $principal ? $principal['codigo_formatado'] . ' ' . $principal['descricao'] : '',
                    $formatarLista($estabelecimento->cnaes_correspondentes),
                    $estabelecimento->cnaes_correspondentes->map(fn($cnae) => CnaeCatalogo::nomeTipo($cnae['tipo']))->unique()->implode(' | '),
                    $formatarLista($estabelecimento->cnaes_relatorio),
                ], ';');
            }

            fclose($saida);
        }, $nomeArquivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Relatório de Equipamentos de Imagem
     */
    public function equipamentosRadiacao()
    {
        $usuario = auth('interno')->user();

        // Códigos das atividades que exigem equipamentos de radiação (normalizados)
        $codigosAtividadesRadiacao = AtividadeEquipamentoRadiacao::where('ativo', true)
            ->pluck('codigo_atividade')
            ->map(fn($c) => preg_replace('/[^0-9]/', '', $c))
            ->unique()
            ->filter()
            ->toArray();

        // Buscar todos os estabelecimentos e filtrar por atividades em PHP
        $query = Estabelecimento::query()
            ->whereNotNull('atividades_exercidas')
            ->with('municipio') // Carregar relacionamento para o mapa
            ->withCount('equipamentosRadiacao as equipamentos_count');

        // Filtro por município se for usuário municipal
        if ($usuario->isMunicipal()) {
            $query->where('municipio_id', $usuario->municipio_id);
        }

        $todosEstabelecimentos = $query->orderBy('nome_fantasia')->get();

        // Filtrar estabelecimentos que têm atividades de radiação
        // E EXCLUIR os que declararam não ter equipamentos
        $estabelecimentos = $todosEstabelecimentos->filter(function($est) use ($codigosAtividadesRadiacao) {
            // Excluir estabelecimentos que declararam não ter equipamentos
            if ($est->declaracao_sem_equipamentos_imagem) {
                return false;
            }
            
            $atividadesEstabelecimento = $est->getTodasAtividades();
            foreach ($atividadesEstabelecimento as $codigo) {
                if (in_array($codigo, $codigosAtividadesRadiacao)) {
                    return true;
                }
            }
            return false;
        });

        // Adicionar as atividades de radiação encontradas em cada estabelecimento
        $estabelecimentos = $estabelecimentos->map(function($est) use ($codigosAtividadesRadiacao) {
            $atividadesEstabelecimento = $est->getTodasAtividades();
            $codigosRadiacaoDoEst = array_intersect($atividadesEstabelecimento, $codigosAtividadesRadiacao);
            
            // Buscar as atividades de radiação correspondentes
            $est->atividades_radiacao = AtividadeEquipamentoRadiacao::where('ativo', true)
                ->where(function($q) use ($codigosRadiacaoDoEst) {
                    foreach ($codigosRadiacaoDoEst as $codigo) {
                        $q->orWhereRaw("REPLACE(REPLACE(codigo_atividade, '.', ''), '-', '') = ?", [$codigo]);
                    }
                })
                ->get();
            
            return $est;
        })->values();

        // Calcular totais (exclui os que declararam não ter equipamentos)
        $totalDeclaracoesSemEquipamentos = $todosEstabelecimentos->where('declaracao_sem_equipamentos_imagem', true)->count();
        
        $totais = [
            'total' => $estabelecimentos->count(),
            'com_equipamentos' => $estabelecimentos->where('equipamentos_count', '>', 0)->count(),
            'sem_equipamentos' => $estabelecimentos->where('equipamentos_count', 0)->count(),
            'total_equipamentos' => $estabelecimentos->sum('equipamentos_count'),
            'declaracoes_sem_equipamentos' => $totalDeclaracoesSemEquipamentos,
        ];

        // Atividades que exigem equipamentos (para filtro)
        $atividades = AtividadeEquipamentoRadiacao::where('ativo', true)
            ->orderBy('descricao_atividade')
            ->get();

        return view('admin.relatorios.equipamentos-radiacao', compact(
            'estabelecimentos',
            'totais',
            'atividades'
        ));
    }

    /**
     * Exportar relatório de equipamentos de radiação para Excel
     */
    public function equipamentosRadiacaoExport(Request $request)
    {
        $usuario = auth('interno')->user();

        // Códigos das atividades que exigem equipamentos de radiação (normalizados)
        $codigosAtividadesRadiacao = AtividadeEquipamentoRadiacao::where('ativo', true)
            ->pluck('codigo_atividade')
            ->map(fn($c) => preg_replace('/[^0-9]/', '', $c))
            ->unique()
            ->filter()
            ->toArray();

        // Filtro por atividade específica
        if ($request->filled('atividade')) {
            $atividadeFiltro = AtividadeEquipamentoRadiacao::find($request->atividade);
            if ($atividadeFiltro) {
                $codigosAtividadesRadiacao = [preg_replace('/[^0-9]/', '', $atividadeFiltro->codigo_atividade)];
            }
        }

        // Buscar todos os estabelecimentos
        $query = Estabelecimento::query()
            ->whereNotNull('atividades_exercidas')
            ->with('equipamentosRadiacao')
            ->withCount('equipamentosRadiacao as equipamentos_count');

        // Filtro por município
        if ($usuario->isMunicipal()) {
            $query->where('municipio_id', $usuario->municipio_id);
        }

        $todosEstabelecimentos = $query->orderBy('nome_fantasia')->get();

        // Filtrar estabelecimentos que têm atividades de radiação
        $estabelecimentos = $todosEstabelecimentos->filter(function($est) use ($codigosAtividadesRadiacao) {
            $atividadesEstabelecimento = $est->getTodasAtividades();
            foreach ($atividadesEstabelecimento as $codigo) {
                if (in_array($codigo, $codigosAtividadesRadiacao)) {
                    return true;
                }
            }
            return false;
        });

        // Aplicar filtro de status
        if ($request->filled('status')) {
            if ($request->status === 'com') {
                $estabelecimentos = $estabelecimentos->where('equipamentos_count', '>', 0);
            } elseif ($request->status === 'sem') {
                $estabelecimentos = $estabelecimentos->where('equipamentos_count', '=', 0);
            }
        }

        // Adicionar as atividades de radiação encontradas
        $estabelecimentos = $estabelecimentos->map(function($est) use ($codigosAtividadesRadiacao) {
            $atividadesEstabelecimento = $est->getTodasAtividades();
            $codigosRadiacaoDoEst = array_intersect($atividadesEstabelecimento, $codigosAtividadesRadiacao);
            
            $est->atividades_radiacao_nomes = AtividadeEquipamentoRadiacao::where('ativo', true)
                ->where(function($q) use ($codigosRadiacaoDoEst) {
                    foreach ($codigosRadiacaoDoEst as $codigo) {
                        $q->orWhereRaw("REPLACE(REPLACE(codigo_atividade, '.', ''), '-', '') = ?", [$codigo]);
                    }
                })
                ->pluck('descricao_atividade')
                ->implode(', ');
            
            return $est;
        })->values();

        // Gerar CSV
        $filename = 'relatorio-equipamentos-radiacao-' . now()->format('Y-m-d-His') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($estabelecimentos) {
            $file = fopen('php://output', 'w');
            
            // BOM para UTF-8
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Cabeçalho
            fputcsv($file, [
                'Estabelecimento',
                'Razão Social',
                'CNPJ',
                'Atividades com Radiação',
                'Qtd. Equipamentos',
                'Status',
            ], ';');

            foreach ($estabelecimentos as $est) {
                $cnpj = $est->cnpj ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $est->cnpj) : '';
                $atividades = $est->atividades_radiacao_nomes ?? '';
                $status = $est->equipamentos_count > 0 ? 'Cadastrado' : 'Pendente';

                fputcsv($file, [
                    $est->nome_fantasia ?? $est->razao_social,
                    $est->razao_social,
                    $cnpj,
                    $atividades,
                    $est->equipamentos_count,
                    $status,
                ], ';');
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
    
    /**
     * Obtém estatísticas gerais do sistema
     */
    private function obterEstatisticasGerais($usuario)
    {
        $stats = [];
        
        // Total de estabelecimentos
        $queryEstabelecimentos = Estabelecimento::query();
        if ($usuario->isMunicipal()) {
            $queryEstabelecimentos->where('municipio_id', $usuario->municipio_id);
        }
        $stats['total_estabelecimentos'] = $queryEstabelecimentos->count();
        
        // Total de processos
        $queryProcessos = Processo::query();
        if ($usuario->isMunicipal()) {
            $queryProcessos->whereHas('estabelecimento', function($q) use ($usuario) {
                $q->where('municipio_id', $usuario->municipio_id);
            });
        }
        $stats['total_processos'] = $queryProcessos->count();
        $stats['processos_abertos'] = (clone $queryProcessos)->where('status', 'aberto')->count();
        
        // Total de ordens de serviço
        $stats['total_ordens_servico'] = OrdemServico::count();
        $stats['ordens_em_andamento'] = OrdemServico::where('status', 'em_andamento')->count();
        
        // Total de documentos digitais
        $stats['total_documentos'] = DocumentoDigital::count();
        
        return $stats;
    }

    /**
     * Listar estabelecimentos que declararam não ter equipamentos
     */
    public function declaracoesSemEquipamentos()
    {
        $usuario = auth('interno')->user();

        $query = Estabelecimento::query()
            ->where('declaracao_sem_equipamentos_imagem', true)
            ->with(['municipio', 'declaracaoSemEquipamentosUsuario'])
            ->orderBy('nome_fantasia');

        // Filtro por município se for usuário municipal
        if ($usuario->isMunicipal()) {
            $query->where('municipio_id', $usuario->municipio_id);
        }

        $declaracoes = $query->paginate(15);

        return view('admin.relatorios.declaracoes-sem-equipamentos', compact(
            'declaracoes'
        ));
    }

    /**
     * Relatório de documentos digitais gerados
     *
     * Regras de visibilidade:
     * - Admin, Gestor Estadual e Técnico Estadual: visualizam documentos do estado
     * - Gestor Municipal e Técnico Municipal: visualizam apenas documentos do seu município
     */
    public function documentosGerados(Request $request)
    {
        $usuario = auth('interno')->user();
        $podeVerApagados = $usuario->isAdmin();
        $podeEscolherCompetencia = $usuario->isAdmin();

        $competenciaAtual = $usuario->isEstadual()
            ? 'estadual'
            : ($usuario->isMunicipal() ? 'municipal' : $request->input('competencia'));

        if ($competenciaAtual && !in_array($competenciaAtual, ['estadual', 'municipal'], true)) {
            $competenciaAtual = null;
        }

        $escopoVisual = match (true) {
            $usuario->isMunicipal() => 'Competência municipal — ' . ($usuario->municipioRelacionado->nome ?? 'seu município'),
            $usuario->isEstadual() => 'Competência estadual — documentos gerados pela equipe do estado',
            $competenciaAtual === 'estadual' => 'Competência estadual — documentos gerados pela equipe do estado',
            $competenciaAtual === 'municipal' => 'Competência municipal — documentos gerados pela equipe municipal',
            default => 'Todos os documentos — estadual e municipal',
        };

        $tiposDocumento = TipoDocumento::orderBy('nome')->get(['id', 'nome']);

        // Subcategorias do tipo selecionado (se houver)
        $subcategorias = collect();
        if ($request->filled('tipo_documento_id')) {
            $subcategorias = \App\Models\TipoDocumentoSubcategoria::where('tipo_documento_id', $request->tipo_documento_id)
                ->where('ativo', true)
                ->orderBy('ordem')
                ->orderBy('nome')
                ->get(['id', 'nome']);
        }

        $query = DocumentoDigital::query()
            ->when($podeVerApagados, fn ($q) => $q->withTrashed())
            ->with([
                'tipoDocumento:id,nome',
                'subcategoria:id,nome',
                'usuarioCriador:id,nome,municipio_id,nivel_acesso',
                'usuarioCriador.municipioRelacionado:id,nome',
                'processo' => fn ($q) => $podeVerApagados
                    ? $q->withTrashed()->select(['id', 'numero_processo', 'estabelecimento_id', 'deleted_at'])
                    : $q->select(['id', 'numero_processo', 'estabelecimento_id']),
                'processo.estabelecimento:id,nome_fantasia,razao_social,municipio_id',
                'processo.estabelecimento.municipio:id,nome',
            ])
            ->whereNotNull('numero_documento');

        $this->aplicarFiltroCompetenciaDocumentosGerados($query, $usuario, $competenciaAtual);

        // Filtros opcionais
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('tipo_documento_id')) {
            $query->where('tipo_documento_id', $request->tipo_documento_id);
        }

        if ($request->filled('subcategoria_id')) {
            $query->where('subcategoria_id', $request->subcategoria_id);
        }

        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->data_inicio);
        }

        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->data_fim);
        }

        if ($request->filled('busca')) {
            $busca = trim($request->busca);

            $query->where(function ($q) use ($busca) {
                $q->where('numero_documento', 'like', "%{$busca}%")
                    ->orWhere('nome', 'like', "%{$busca}%")
                    ->orWhereHas('tipoDocumento', function ($tipoQ) use ($busca) {
                        $tipoQ->where('nome', 'like', "%{$busca}%");
                    })
                    ->orWhereHas('processo', function ($processoQ) use ($busca) {
                        $processoQ->where('numero_processo', 'like', "%{$busca}%");
                    })
                    ->orWhereHas('processo.estabelecimento', function ($estQ) use ($busca) {
                        $estQ->where('nome_fantasia', 'like', "%{$busca}%")
                            ->orWhere('razao_social', 'like', "%{$busca}%");
                    });
            });
        }

        $modoImpressao = $request->boolean('imprimir');

        if ($modoImpressao) {
            $documentos = $query
                ->orderByDesc('created_at')
                ->limit(5000)
                ->get();
        } else {
            $documentos = $query
                ->orderByDesc('created_at')
                ->paginate(10)
                ->withQueryString();
        }

        $totais = [
            'total' => (clone $query)->count(),
            'assinados' => (clone $query)->where('status', 'assinado')->count(),
            'aguardando_assinatura' => (clone $query)->where('status', 'aguardando_assinatura')->count(),
            'rascunhos' => (clone $query)->where('status', 'rascunho')->count(),
        ];

        return view('admin.relatorios.documentos-gerados', compact(
            'documentos',
            'totais',
            'tiposDocumento',
            'subcategorias',
            'podeVerApagados',
            'podeEscolherCompetencia',
            'competenciaAtual',
            'escopoVisual',
            'modoImpressao',
        ));
    }

    /**
     * Relatório de Pesquisa de Satisfação com gráficos
     * Suporta seleção de múltiplas pesquisas (pesquisa_ids[])
     */
    public function pesquisaSatisfacao(Request $request)
    {
        $pesquisas = PesquisaSatisfacao::withCount('respostas')->orderBy('titulo')->get();
        $aba = $request->get('aba', 'relatorio');

        // Suporta array de IDs (múltiplas) ou ID único (retrocompatível)
        $pesquisaIds = $request->input('pesquisa_ids', []);
        if (empty($pesquisaIds) && $request->filled('pesquisa_id')) {
            $pesquisaIds = [$request->input('pesquisa_id')];
        }
        $pesquisaIds = array_filter(array_map('intval', (array) $pesquisaIds));

        $pesquisasSelecionadas = collect();
        $dados = null;

        if (!empty($pesquisaIds)) {
            $pesquisasSelecionadas = PesquisaSatisfacao::with('perguntas.opcoes')
                ->whereIn('id', $pesquisaIds)
                ->get();

            if ($pesquisasSelecionadas->isEmpty()) {
                abort(404);
            }

            // Buscar respostas de TODAS as pesquisas selecionadas
            $queryRespostas = PesquisaSatisfacaoResposta::whereIn('pesquisa_id', $pesquisaIds);

            if ($request->filled('data_inicio')) {
                $queryRespostas->whereDate('created_at', '>=', $request->data_inicio);
            }
            if ($request->filled('data_fim')) {
                $queryRespostas->whereDate('created_at', '<=', $request->data_fim);
            }

            $respostas = $queryRespostas->orderByDesc('created_at')->get();

            $dados = [
                'total_respostas' => $respostas->count(),
                'por_tipo_respondente' => [
                    'interno' => $respostas->where('tipo_respondente', 'interno')->count(),
                    'externo' => $respostas->where('tipo_respondente', 'externo')->count()
                        + $respostas->filter(fn($r) => !$r->tipo_respondente && ($r->respondente_nome || $r->respondente_email))->count(),
                    'anonimo' => $respostas->filter(fn($r) => !$r->tipo_respondente && !$r->respondente_nome && !$r->respondente_email)->count(),
                ],
                'por_mes' => [],
                'por_pesquisa' => [],
                'perguntas' => [],
            ];

            // Respostas por mês (últimos 6 meses)
            for ($i = 5; $i >= 0; $i--) {
                $mes = now()->subMonths($i);
                $count = $respostas->filter(function ($r) use ($mes) {
                    return $r->created_at->format('Y-m') === $mes->format('Y-m');
                })->count();
                $dados['por_mes'][] = [
                    'label' => $mes->translatedFormat('M/Y'),
                    'count' => $count,
                ];
            }

            // Respostas por pesquisa (para gráfico comparativo)
            foreach ($pesquisasSelecionadas as $ps) {
                $dados['por_pesquisa'][] = [
                    'titulo' => \Str::limit($ps->titulo, 30),
                    'count' => $respostas->where('pesquisa_id', $ps->id)->count(),
                ];
            }

            // Análise por pergunta (de todas as pesquisas selecionadas)
            foreach ($pesquisasSelecionadas as $ps) {
                $respostasDaPesquisa = $respostas->where('pesquisa_id', $ps->id);
                $prefixo = count($pesquisaIds) > 1 ? '[' . \Str::limit($ps->titulo, 25) . '] ' : '';

                foreach ($ps->perguntas as $pergunta) {
                    $perguntaDados = [
                        'id' => $pergunta->id,
                        'texto' => $prefixo . $pergunta->texto,
                        'tipo' => $pergunta->tipo,
                        'pesquisa' => $ps->titulo,
                        'distribuicao' => [],
                        'media' => null,
                        'textos_livres' => [],
                    ];

                    if ($pergunta->tipo === 'escala_1_5') {
                        $contagem = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
                        $soma = 0;
                        $total = 0;
                        foreach ($respostasDaPesquisa as $resp) {
                            $respostasJson = is_array($resp->respostas) ? $resp->respostas : [];
                            foreach ($respostasJson as $r) {
                                if (($r['pergunta_id'] ?? null) == $pergunta->id && isset($r['valor'])) {
                                    $val = (int) $r['valor'];
                                    if ($val >= 1 && $val <= 5) {
                                        $contagem[$val]++;
                                        $soma += $val;
                                        $total++;
                                    }
                                }
                            }
                        }
                        $perguntaDados['distribuicao'] = $contagem;
                        $perguntaDados['media'] = $total > 0 ? round($soma / $total, 1) : 0;
                        $perguntaDados['total'] = $total;
                    } elseif ($pergunta->tipo === 'multipla_escolha') {
                        $contagem = [];
                        foreach ($pergunta->opcoes as $opcao) {
                            $contagem[$opcao->id] = ['texto' => $opcao->texto, 'count' => 0];
                        }
                        foreach ($respostasDaPesquisa as $resp) {
                            $respostasJson = is_array($resp->respostas) ? $resp->respostas : [];
                            foreach ($respostasJson as $r) {
                                if (($r['pergunta_id'] ?? null) == $pergunta->id && isset($r['opcao_id'])) {
                                    $opcaoId = $r['opcao_id'];
                                    if (isset($contagem[$opcaoId])) {
                                        $contagem[$opcaoId]['count']++;
                                    }
                                }
                            }
                        }
                        $perguntaDados['distribuicao'] = array_values($contagem);
                    } elseif ($pergunta->tipo === 'texto_livre') {
                        foreach ($respostasDaPesquisa as $resp) {
                            $respostasJson = is_array($resp->respostas) ? $resp->respostas : [];
                            foreach ($respostasJson as $r) {
                                if (($r['pergunta_id'] ?? null) == $pergunta->id && !empty($r['valor'])) {
                                    $perguntaDados['textos_livres'][] = [
                                        'texto' => $r['valor'],
                                        'respondente' => $resp->nome_respondente,
                                        'data' => $resp->created_at->format('d/m/Y'),
                                    ];
                                }
                            }
                        }
                    }

                    $dados['perguntas'][] = $perguntaDados;
                }
            }
        }

        $respostasQuery = $this->montarQueryRespostasPesquisaSatisfacao($request);
        $respostasFiltradas = (clone $respostasQuery)->get(['id', 'pesquisa_id', 'respostas']);
        $respostas = $respostasQuery->paginate(20)->withQueryString();
        $respostas->getCollection()->transform(function ($resposta) {
            $resposta->media_notas = $this->calcularMediaRespostaPesquisa($resposta);
            return $resposta;
        });

        $totalRespostas = PesquisaSatisfacaoResposta::count();
        $respostasInterno = PesquisaSatisfacaoResposta::where('tipo_respondente', 'interno')->count();
        $respostasExterno = PesquisaSatisfacaoResposta::where('tipo_respondente', 'externo')->count();
        $mediaGeralNotas = $this->calcularMediaGeralNotasPesquisa($respostasFiltradas);

        $iaConfigurada = !empty(ConfiguracaoSistema::obter('ia_api_key')) && !empty(ConfiguracaoSistema::obter('ia_api_url'));
        $iaPesquisaSatisfacaoAtiva = $iaConfigurada;

        return view('admin.relatorios.pesquisa-satisfacao', compact(
            'aba',
            'pesquisas',
            'pesquisasSelecionadas',
            'dados',
            'respostas',
            'totalRespostas',
            'respostasInterno',
            'respostasExterno',
            'mediaGeralNotas',
            'iaPesquisaSatisfacaoAtiva'
        ));
    }

    /**
     * Análise de IA para Pesquisa de Satisfação
     */
    public function pesquisaSatisfacaoAnaliseIA(Request $request)
    {
        $request->validate([
            'dados_relatorio' => 'required|string',
        ]);

        $iaPesquisaSatisfacaoAtiva = ConfiguracaoSistema::obter('ia_pesquisa_satisfacao_ativa', 'true');
        if ($iaPesquisaSatisfacaoAtiva === 'false') {
            return response()->json([
                'success' => false,
                'error' => 'O Assistente de IA para Pesquisa de Satisfação está desativado. Ative-o em Configurações > Sistema.',
            ], 403);
        }

        $apiKey = ConfiguracaoSistema::obter('ia_api_key');
        $apiUrl = ConfiguracaoSistema::obter('ia_api_url');
        $model = ConfiguracaoSistema::obter('ia_model');

        if (empty($apiKey) || empty($apiUrl) || empty($model)) {
            return response()->json([
                'success' => false,
                'error' => 'Configurações da IA não encontradas. Configure a API em Configurações > Sistema.',
            ], 500);
        }

        $dadosRelatorio = $request->input('dados_relatorio');

        // Verifica se há um prompt customizado configurado
        $promptCustomizado = trim((string) ConfiguracaoSistema::obter('ia_pesquisa_satisfacao_prompt', ''));

        $systemPrompt = "Você é um analista especialista em gestão da qualidade e satisfação de clientes do sistema InfoVISA (Vigilância Sanitária).\n\n"
            . "Sua função é analisar os dados de pesquisas de satisfação e fornecer uma análise estratégica completa para tomada de decisão.\n\n"
            . "FORMATO OBRIGATÓRIO DA RESPOSTA (use exatamente estas seções com os títulos em markdown):\n\n"
            . "## 📊 Resumo Executivo\n"
            . "Síntese geral dos resultados em 2-3 frases.\n\n"
            . "## ✅ Pontos Fortes\n"
            . "Liste os aspectos com melhor avaliação e o que está funcionando bem.\n\n"
            . "## ⚠️ Pontos de Atenção\n"
            . "Liste os aspectos com pior avaliação ou que precisam de melhorias urgentes.\n\n"
            . "## 📈 Tendências Identificadas\n"
            . "Analise a evolução mensal das respostas e identifique padrões.\n\n"
            . "## 🎯 Recomendações de Ação\n"
            . "Liste ações concretas e priorizadas que a gestão deve tomar, baseadas nos dados.\n\n"
            . "## 🔮 Projeção\n"
            . "Com base nos dados atuais, projete o cenário caso as ações sejam (ou não) implementadas.\n\n"
            . "REGRAS:\n"
            . "- Responda SEMPRE em português do Brasil\n"
            . "- Baseie-se EXCLUSIVAMENTE nos dados fornecidos, não invente dados\n"
            . "- Seja objetivo e direto, focando em insights acionáveis\n"
            . "- Use linguagem profissional adequada para relatórios de gestão\n"
            . "- Se houver textos livres dos respondentes, extraia os temas mais recorrentes\n"
            . "- Considere que a escala é de 1 a 5 (1=Péssimo, 2=Ruim, 3=Regular, 4=Bom, 5=Ótimo)\n"
            . "- Notas médias abaixo de 3.0 são críticas, entre 3.0 e 3.9 requerem atenção, acima de 4.0 são positivas";

        if (!empty($promptCustomizado)) {
            $systemPrompt .= "\n\nINSTRUÇÕES ADICIONAIS DO ADMINISTRADOR:\n" . $promptCustomizado;
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Analise os seguintes dados da pesquisa de satisfação e forneça insights para tomada de decisão:\n\n" . $dadosRelatorio],
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post($apiUrl, [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 2000,
                'temperature' => 0.3,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $resposta = $data['choices'][0]['message']['content'] ?? null;

                if ($resposta) {
                    return response()->json([
                        'success' => true,
                        'analise' => $resposta,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'error' => 'A IA não retornou uma resposta válida.',
                ], 500);
            }

            \Log::error('Erro na API da IA - Análise Pesquisa Satisfação', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro ao comunicar com a IA. Tente novamente.',
            ], 500);
        } catch (\Exception $e) {
            \Log::error('Exceção na análise IA da pesquisa de satisfação', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Erro ao processar análise: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Relatório de Processos
     */
    public function processos(Request $request)
    {
        $usuario = auth('interno')->user();

        // Tipos de processo disponíveis
        $tiposProcesso = \App\Models\TipoProcesso::ativos()->paraUsuario($usuario)->ordenado()->get();
        $statusDisponiveis = Processo::statusDisponiveis();
        $anos = Processo::select('ano')->distinct()->orderBy('ano', 'desc')->pluck('ano');

        // Admin vê todos os municípios. Gestor/técnico estadual e municipal não precisa
        // de filtro de município (o escopo já é aplicado automaticamente).
        $municipios = $usuario->isAdmin()
            ? Municipio::where('usa_infovisa', true)->orderBy('nome')->get()
            : collect();

        // Lista de técnicos/gestores disponíveis para o filtro "Processos com técnico".
        // Respeita o escopo do usuário logado.
        $tecnicosDisponiveis = $this->tecnicosDisponiveisParaRelatorioProcessos($usuario);

        // Query base
        $query = Processo::with(['estabelecimento', 'tipoProcesso', 'responsavelAtual', 'ultimoEventoAtribuicao']);

        // Filtro por escopo do usuário (pré-filtro SQL para reduzir volume)
        if (!$usuario->isAdmin()) {
            if ($usuario->isMunicipal() && $usuario->municipio_id) {
                $query->whereHas('estabelecimento', function ($q) use ($usuario) {
                    $q->where('municipio_id', $usuario->municipio_id);
                });
            } elseif ($usuario->isEstadual()) {
                // Pré-filtro: exclui estabelecimentos explicitamente municipais
                $query->whereHas('estabelecimento', function ($q) {
                    $q->where('competencia_manual', '!=', 'municipal')
                      ->orWhereNull('competencia_manual');
                });
            }
        }

        // Filtros
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('ano')) {
            $query->where('ano', $request->ano);
        }
        // Filtro de município só vale para admin; gestores têm escopo fechado.
        if ($usuario->isAdmin() && $request->filled('municipio_id')) {
            $query->whereHas('estabelecimento', function ($q) use ($request) {
                $q->where('municipio_id', $request->municipio_id);
            });
        }
        if ($request->filled('data_inicio')) {
            $query->whereDate('created_at', '>=', $request->data_inicio);
        }
        if ($request->filled('data_fim')) {
            $query->whereDate('created_at', '<=', $request->data_fim);
        }

        // Filtro: processos que estão com um técnico específico.
        // Só aplica se o técnico pertence à lista permitida para o usuário logado (escopo).
        $tecnicoFiltrado = null;
        if ($request->filled('tecnico_id')) {
            $tecnicoId = (int) $request->tecnico_id;
            if ($tecnicosDisponiveis->contains('id', $tecnicoId)) {
                $query->where('responsavel_atual_id', $tecnicoId);
                $tecnicoFiltrado = $tecnicosDisponiveis->firstWhere('id', $tecnicoId);
            }
        }

        // Para usuários não-admin, aplica filtro de competência em memória
        // (a lógica de competência depende de atividades/pactuação e não pode ser feita em SQL puro)
        if (!$usuario->isAdmin()) {
            $todosProcessos = $query->orderByDesc('created_at')->get();

            // Filtra por competência real
            $todosProcessos = $todosProcessos->filter(function ($processo) use ($usuario) {
                $escopoCompetencia = $processo->resolverEscopoCompetencia();

                if ($usuario->isEstadual()) {
                    return $escopoCompetencia === 'estadual';
                }
                if ($usuario->isMunicipal()) {
                    return $escopoCompetencia === 'municipal';
                }
                return true;
            })->values();

            $totalProcessos = $todosProcessos->count();

            // Contagem por status
            $porStatus = $todosProcessos->groupBy('status')->map->count()->toArray();

            // Paginação manual
            $page = $request->input('page', 1);
            $perPage = 15;
            $processos = new \Illuminate\Pagination\LengthAwarePaginator(
                $todosProcessos->forPage($page, $perPage)->values(),
                $totalProcessos,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()]
            );
        } else {
            $totalProcessos = (clone $query)->count();

            // Contagem por status
            $porStatus = (clone $query)->select('status', DB::raw('count(*) as total'))
                ->groupBy('status')->pluck('total', 'status')->toArray();

            // Listagem paginada
            $processos = (clone $query)->orderByDesc('created_at')->paginate(15)->withQueryString();
        }

        return view('admin.relatorios.processos', compact(
            'processos', 'tiposProcesso', 'statusDisponiveis', 'anos', 'municipios',
            'totalProcessos', 'porStatus', 'tecnicosDisponiveis', 'tecnicoFiltrado'
        ));
    }

    /**
     * Retorna os técnicos/gestores elegíveis para o filtro "Processos com técnico"
     * no relatório de processos, respeitando o escopo do usuário logado.
     *
     * - Admin: todos os usuários internos ativos.
     * - Gestor/Técnico Estadual: apenas usuários com nível estadual + administradores.
     * - Gestor/Técnico Municipal: apenas usuários do próprio município.
     */
    private function tecnicosDisponiveisParaRelatorioProcessos(UsuarioInterno $usuario)
    {
        $query = UsuarioInterno::where('ativo', true);

        if ($usuario->isAdmin()) {
            // sem restrição
        } elseif ($usuario->isEstadual()) {
            $query->whereIn('nivel_acesso', [
                \App\Enums\NivelAcesso::Administrador->value,
                \App\Enums\NivelAcesso::GestorEstadual->value,
                \App\Enums\NivelAcesso::TecnicoEstadual->value,
            ]);
        } elseif ($usuario->isMunicipal() && $usuario->municipio_id) {
            $query->where('municipio_id', $usuario->municipio_id)
                ->whereIn('nivel_acesso', [
                    \App\Enums\NivelAcesso::GestorMunicipal->value,
                    \App\Enums\NivelAcesso::TecnicoMunicipal->value,
                ]);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $query->orderBy('nome')->get(['id', 'nome', 'nivel_acesso', 'municipio_id']);
    }

    /**
     * Relatório de Ações por Atividade (Ordens de Serviço)
     */
    public function acoesPorAtividade(Request $request)
    {
        $usuario = auth('interno')->user();

        $filtroCompetencia = $request->input('competencia');
        $filtroMunicipio = $request->input('municipio_id');
        $filtroUsuario = $request->input('usuario_id');
        $filtroStatus = $request->input('status');
        $filtroAtividade = $request->input('atividade_status', 'todas');
        $filtroTipoAcao = $request->input('tipo_acao_id');
        $filtroDataInicio = $request->input('data_inicio');
        $filtroDataFim = $request->input('data_fim');

        $query = OrdemServico::query()->with(['estabelecimento.municipio', 'municipio']);

        // Escopo por perfil
        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $query->where('municipio_id', $usuario->municipio_id);
        } elseif ($usuario->isEstadual()) {
            $query->where('competencia', 'estadual');
        }

        if ($filtroCompetencia) $query->where('competencia', $filtroCompetencia);
        if ($filtroMunicipio) {
            $query->where(function ($q) use ($filtroMunicipio) {
                $q->where('municipio_id', $filtroMunicipio)
                  ->orWhereHas('estabelecimento', function ($eq) use ($filtroMunicipio) {
                      $eq->where('municipio_id', $filtroMunicipio);
                  });
            });
        }
        if ($filtroStatus) $query->where('status', $filtroStatus);
        if ($filtroDataInicio) $query->whereDate('data_abertura', '>=', $filtroDataInicio);
        if ($filtroDataFim) $query->whereDate('data_abertura', '<=', $filtroDataFim);

        if ($filtroUsuario) {
            $query->where(function ($q) use ($filtroUsuario) {
                $q->whereJsonContains('tecnicos_ids', (int) $filtroUsuario)
                  ->orWhereJsonContains('tecnicos_ids', (string) $filtroUsuario);
            });
        }

        $ordensServico = $query->orderByDesc('data_abertura')->get();

        // === Extrair TODAS as atividades individuais de todas as OS ===
        $todasAtividades = collect();
        foreach ($ordensServico as $os) {
            // Município: usa o da OS, senão pega do estabelecimento
            $munId = $os->municipio_id ?? $os->estabelecimento?->municipio_id;
            $munNome = $os->municipio->nome 
                ?? $os->estabelecimento?->municipio?->nome 
                ?? $os->estabelecimento?->cidade 
                ?? 'Estado';
            
            foreach ($os->atividades_tecnicos ?? [] as $ativ) {
                $statusAtiv = $ativ['status'] ?? 'pendente';
                $todasAtividades->push([
                    'tipo_acao_id' => $ativ['tipo_acao_id'] ?? null,
                    'nome' => $ativ['nome_atividade'] ?? 'Sem nome',
                    'status' => $statusAtiv,
                    'tecnicos' => $ativ['tecnicos'] ?? [],
                    'responsavel_id' => $ativ['responsavel_id'] ?? null,
                    'finalizada_por' => $ativ['finalizada_por'] ?? null,
                    'finalizada_em' => $ativ['finalizada_em'] ?? null,
                    'os_id' => $os->id,
                    'os_numero' => $os->numero,
                    'os_competencia' => $os->competencia,
                    'municipio_id' => $munId ?? 0,
                    'municipio_nome' => $munNome,
                    'data_abertura' => $os->data_abertura,
                ]);
            }
        }

        // Filtra atividades por status se solicitado
        $atividadesFiltradas = $todasAtividades;
        if ($filtroAtividade === 'finalizada') {
            $atividadesFiltradas = $atividadesFiltradas->where('status', 'finalizada');
        } elseif ($filtroAtividade === 'pendente') {
            $atividadesFiltradas = $atividadesFiltradas->where('status', 'pendente');
        }

        // === Regiões de Saúde do Tocantins (PDR 2014) ===
        $regioesSaudeNomes = ['Médio Norte Araguaia','Bico do Papagaio','Capim Dourado','Cerrado Tocantins Araguaia','Ilha do Bananal','Cantão','Amor Perfeito','Sudeste'];

        $normalizarNome = function ($nome) {
            $nome = mb_strtoupper(trim($nome));
            $nome = strtr($nome, [
                'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A',
                'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
                'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
                'Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
                'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
                'Ç'=>'C','Ñ'=>'N',
            ]);
            $nome = preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $nome);
            return $nome;
        };

        $regioesSaude = [
            'Médio Norte Araguaia' => ['Aragominas','Araguaína','Araguanã','Babaçulândia','Barra do Ouro','Campos Lindos','Carmolândia','Darcinópolis','Filadélfia','Goiatins','Muricilândia','Nova Olinda','Pau D\'Arco','Piraquê','Santa Fé do Araguaia','Wanderlândia','Xambioá'],
            'Bico do Papagaio' => ['Aguiarnópolis','Ananás','Angico','Araguatins','Augustinópolis','Axixá do Tocantins','Buriti do Tocantins','Cachoeirinha','Carrasco Bonito','Esperantina','Itaguatins','Luzinópolis','Maurilândia do Tocantins','Nazaré','Palmeiras do Tocantins','Praia Norte','Riachinho','Sampaio','Santa Terezinha do Tocantins','São Bento do Tocantins','São Miguel do Tocantins','São Sebastião do Tocantins','Sítio Novo do Tocantins','Tocantinópolis'],
            'Capim Dourado' => ['Aparecida do Rio Negro','Fortaleza do Tabocão','Lagoa do Tocantins','Lajeado','Lizarda','Miracema do Tocantins','Miranorte','Novo Acordo','Palmas','Rio dos Bois','Rio Sono','Santa Tereza do Tocantins','São Félix do Tocantins','Tocantínia'],
            'Cerrado Tocantins Araguaia' => ['Arapoema','Bandeirantes do Tocantins','Bernardo Sayão','Bom Jesus do Tocantins','Brasilândia do Tocantins','Centenário','Colinas do Tocantins','Colméia','Couto Magalhães','Goianorte','Guaraí','Itacajá','Itapiratins','Itaporã do Tocantins','Juarina','Palmeirante','Pedro Afonso','Pequizeiro','Presidente Kennedy','Recursolândia','Santa Maria do Tocantins','Tupirama','Tupiratins'],
            'Ilha do Bananal' => ['Aliança do Tocantins','Alvorada','Araguaçu','Cariri do Tocantins','Crixás do Tocantins','Dueré','Figueirópolis','Formoso do Araguaia','Gurupi','Jaú do Tocantins','Palmeirópolis','Peixe','Sandolândia','Santa Rita do Tocantins','São Salvador do Tocantins','São Valério','Sucupira','Talismã'],
            'Cantão' => ['Abreulândia','Araguacema','Barrolândia','Caseara','Chapada de Areia','Cristalândia','Divinópolis do Tocantins','Dois Irmãos do Tocantins','Lagoa da Confusão','Marianópolis do Tocantins','Monte Santo do Tocantins','Nova Rosalândia','Paraíso do Tocantins','Pium','Pugmil'],
            'Amor Perfeito' => ['Brejinho de Nazaré','Chapada da Natividade','Fátima','Ipueiras','Mateiros','Monte do Carmo','Natividade','Oliveira de Fátima','Pindorama do Tocantins','Ponte Alta do Tocantins','Porto Nacional','Santa Rosa do Tocantins','Silvanópolis'],
            'Sudeste' => ['Almas','Arraias','Aurora do Tocantins','Combinado','Conceição do Tocantins','Dianópolis','Lavandeira','Novo Alegre','Novo Jardim','Paranã','Ponte Alta do Bom Jesus','Porto Alegre do Tocantins','Rio da Conceição','Taguatinga','Taipas do Tocantins'],
        ];

        $municipioParaRegiao = [];
        foreach ($regioesSaude as $regiao => $municipiosRegiao) {
            foreach ($municipiosRegiao as $nomeMun) {
                $municipioParaRegiao[$normalizarNome($nomeMun)] = $regiao;
            }
        }

        $buscarRegiao = function ($nomeMunicipio) use ($municipioParaRegiao, $normalizarNome) {
            $nomeNorm = $normalizarNome($nomeMunicipio);
            if (isset($municipioParaRegiao[$nomeNorm])) return $municipioParaRegiao[$nomeNorm];
            foreach ($municipioParaRegiao as $chave => $regiao) {
                if (str_starts_with($nomeNorm, $chave) || str_starts_with($chave, $nomeNorm)) return $regiao;
            }
            return 'Outros';
        };

        // Filtro por região de saúde
        $filtroRegiao = $request->input('regiao');
        if ($filtroRegiao && $filtroRegiao !== '') {
            $atividadesFiltradas = $atividadesFiltradas->filter(function ($ativ) use ($buscarRegiao, $filtroRegiao) {
                return $buscarRegiao($ativ['municipio_nome']) === $filtroRegiao;
            });
        }

        // Filtro por tipo de ação (atividade)
        if ($filtroTipoAcao) {
            $atividadesFiltradas = $atividadesFiltradas->where('tipo_acao_id', (int) $filtroTipoAcao);
        }

        // === KPIs (baseados nas atividades filtradas) ===
        $totalOS = $ordensServico->count();
        $totalOSConcluidas = $ordensServico->where('status', 'concluida')->count();
        $totalAtividades = $atividadesFiltradas->count();
        $totalAtivFinalizadas = $atividadesFiltradas->where('status', 'finalizada')->count();
        $totalAtivPendentes = $atividadesFiltradas->where('status', 'pendente')->count();
        $totalEstadual = $ordensServico->where('competencia', 'estadual')->count();
        $totalMunicipal = $ordensServico->where('competencia', 'municipal')->count();
        $pctConclusao = $totalAtividades > 0 ? round(($totalAtivFinalizadas / $totalAtividades) * 100) : 0;

        // === Atividades por tipo de ação ===
        $porTipoAcao = $atividadesFiltradas->groupBy('tipo_acao_id')
            ->map(function ($grupo) {
                return [
                    'nome' => $grupo->first()['nome'],
                    'total' => $grupo->count(),
                    'finalizadas' => $grupo->where('status', 'finalizada')->count(),
                    'pendentes' => $grupo->where('status', 'pendente')->count(),
                ];
            })->sortByDesc('total')->values();

        // === Atividades por município ===
        $porMunicipio = $atividadesFiltradas->groupBy('municipio_id')
            ->map(function ($grupo) {
                return [
                    'nome' => $grupo->first()['municipio_nome'],
                    'total' => $grupo->count(),
                    'finalizadas' => $grupo->where('status', 'finalizada')->count(),
                    'pendentes' => $grupo->where('status', 'pendente')->count(),
                    'estadual' => $grupo->where('os_competencia', 'estadual')->count(),
                    'municipal' => $grupo->where('os_competencia', 'municipal')->count(),
                ];
            })->sortByDesc('total')->values();

        // === Estabelecimentos por município (ativos e aprovados) ===
        $estabQuery = Estabelecimento::where('status', 'aprovado')->where('ativo', true);
        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $estabQuery->where('municipio_id', $usuario->municipio_id);
        }
        $todosEstabelecimentosMun = $estabQuery->select('id', 'municipio_id', 'cidade')
            ->with('municipio:id,nome')
            ->get();

        $estabelecimentosPorMunicipio = $todosEstabelecimentosMun->groupBy('municipio_id')
            ->map(function ($grupo) {
                $mun = $grupo->first()->municipio;
                return [
                    'nome' => $mun->nome ?? $grupo->first()->cidade ?? 'Sem município',
                    'total' => $grupo->count(),
                ];
            })->sortByDesc('total')->values();

        // === Regiões de Saúde — agrupamento por região ===
        $porRegiao = collect();
        if ($usuario->isAdmin() || $usuario->isEstadual()) {
            $porRegiao = $atividadesFiltradas->groupBy(function ($ativ) use ($buscarRegiao) {
                return $buscarRegiao($ativ['municipio_nome']);
            })->map(function ($grupo, $regiao) {
                return [
                    'nome' => $regiao,
                    'total' => $grupo->count(),
                    'finalizadas' => $grupo->where('status', 'finalizada')->count(),
                    'pendentes' => $grupo->where('status', 'pendente')->count(),
                ];
            })->sortByDesc('total')->values();
        }

        // === Atividades por técnico ===
        $porUsuario = [];
        foreach ($atividadesFiltradas as $ativ) {
            foreach ($ativ['tecnicos'] ?? [] as $tecId) {
                $tecId = is_array($tecId) ? ($tecId['id'] ?? null) : $tecId;
                if (!$tecId) continue;
                if (!isset($porUsuario[$tecId])) {
                    $porUsuario[$tecId] = ['total' => 0, 'finalizadas' => 0];
                }
                $porUsuario[$tecId]['total']++;
                if ($ativ['status'] === 'finalizada') {
                    $porUsuario[$tecId]['finalizadas']++;
                }
            }
        }
        $usuariosMap = UsuarioInterno::whereIn('id', array_keys($porUsuario))->get()->keyBy('id');
        $porUsuarioFormatado = collect($porUsuario)
            ->map(fn($data, $id) => [
                'id' => $id,
                'nome' => $usuariosMap[$id]->nome ?? "Usuário #$id",
                'nivel' => isset($usuariosMap[$id]) ? $usuariosMap[$id]->nivel_acesso->label() : '',
                'total' => $data['total'],
                'finalizadas' => $data['finalizadas'],
            ])
            ->sortByDesc('total')->values();

        // === Evolução mensal (atividades) ===
        $porMes = $atividadesFiltradas->groupBy(fn($a) => $a['data_abertura']?->format('Y-m'))
            ->map(fn($grupo, $mes) => [
                'mes' => $mes,
                'total' => $grupo->count(),
                'finalizadas' => $grupo->where('status', 'finalizada')->count(),
            ])->sortKeys()->values();

        // === Top 5 ações mais executadas (para destaque) ===
        $topAcoes = $porTipoAcao->take(5);

        // === Dados para filtros (escopo por perfil) ===
        $municipiosQuery = Municipio::where('ativo', true)->orderBy('nome');
        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $municipiosQuery->where('id', $usuario->municipio_id);
        }
        $municipios = $municipiosQuery->get(['id', 'nome']);

        $usuariosQuery = UsuarioInterno::where('ativo', true)->orderBy('nome');
        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $usuariosQuery->where('municipio_id', $usuario->municipio_id);
        } elseif ($usuario->isEstadual()) {
            $usuariosQuery->whereIn('nivel_acesso', ['administrador', 'gestor_estadual', 'tecnico_estadual']);
        }
        $usuarios = $usuariosQuery->get(['id', 'nome']);

        // Tipos de ação para filtro
        $tiposAcaoFiltro = TipoAcao::where('ativo', true)->orderBy('descricao')->get(['id', 'descricao']);

        $escopoVisual = 'Escopo: Estadual — todos os dados';
        if ($usuario->isMunicipal()) {
            $escopoVisual = 'Escopo: ' . ($usuario->municipioRelacionado->nome ?? 'Município');
        } elseif ($usuario->isEstadual()) {
            $escopoVisual = 'Escopo: Competência estadual';
        }

        // === Estabelecimentos por região de saúde ===
        $estabelecimentosPorRegiao = collect();
        if ($usuario->isAdmin() || $usuario->isEstadual()) {
            $todosEstabelecimentos = Estabelecimento::where('status', 'aprovado')
                ->where('ativo', true)
                ->select('id', 'nome_fantasia', 'cidade', 'municipio_id')
                ->with('municipio:id,nome')
                ->get();

            $estabelecimentosPorRegiao = $todosEstabelecimentos->groupBy(function ($est) use ($buscarRegiao) {
                $nomeMun = $est->municipio->nome ?? $est->cidade ?? 'SEM';
                return $buscarRegiao($nomeMun);
            })->map(function ($grupo, $regiao) {
                return [
                    'nome' => $regiao,
                    'total' => $grupo->count(),
                ];
            })->sortByDesc('total')->values();
        }

        return view('admin.relatorios.acoes-atividade', compact(
            'totalOS', 'totalOSConcluidas', 'totalAtividades', 'totalAtivFinalizadas', 'totalAtivPendentes',
            'totalEstadual', 'totalMunicipal', 'pctConclusao',
            'porTipoAcao', 'porMunicipio', 'porRegiao', 'porUsuarioFormatado', 'porMes', 'topAcoes',
            'municipios', 'usuarios', 'regioesSaudeNomes', 'escopoVisual', 'estabelecimentosPorRegiao',
            'estabelecimentosPorMunicipio', 'tiposAcaoFiltro'
        ));
    }

    /**
     * Relatório de Pendências por Usuário
     * Mostra todas as pendências vinculadas a cada técnico/gestor:
     * - Assinaturas pendentes
     * - Processos sob responsabilidade (abertos/parados)
     * - Respostas pendentes de análise
     * - Ordens de Serviço com atividades pendentes
     */
    public function usuarios(Request $request)
    {
        $usuario = auth('interno')->user();

        // Lista de usuários disponíveis conforme escopo
        $queryUsuarios = UsuarioInterno::where('ativo', true)->orderBy('nome');

        if ($usuario->isMunicipal() && $usuario->municipio_id) {
            $queryUsuarios->where('municipio_id', $usuario->municipio_id)
                ->whereIn('nivel_acesso', [
                    \App\Enums\NivelAcesso::GestorMunicipal->value,
                    \App\Enums\NivelAcesso::TecnicoMunicipal->value,
                ]);
        } elseif ($usuario->isEstadual()) {
            $queryUsuarios->whereIn('nivel_acesso', [
                \App\Enums\NivelAcesso::Administrador->value,
                \App\Enums\NivelAcesso::GestorEstadual->value,
                \App\Enums\NivelAcesso::TecnicoEstadual->value,
            ]);
        }

        $usuariosDisponiveis = $queryUsuarios->get();

        // Se um usuário específico foi selecionado
        $usuarioSelecionado = null;
        $pendencias = null;

        if ($request->filled('usuario_id')) {
            $usuarioId = (int) $request->usuario_id;
            $usuarioSelecionado = $usuariosDisponiveis->firstWhere('id', $usuarioId);

            if ($usuarioSelecionado) {
                $pendencias = $this->calcularPendenciasUsuario($usuarioSelecionado, $usuario);
            }
        }

        // Resumo geral: pendências por usuário (para a tabela inicial)
        $resumoUsuarios = $usuariosDisponiveis->map(function ($u) use ($usuario) {
            return $this->calcularResumoPendenciasUsuario($u, $usuario);
        })->filter(fn($r) => $r !== null)->sortByDesc('total_pendencias')->values();

        return view('admin.relatorios.usuarios', compact(
            'usuariosDisponiveis', 'usuarioSelecionado', 'pendencias', 'resumoUsuarios'
        ));
    }

    /**
     * Calcula resumo rápido de pendências de um usuário (para tabela geral)
     */
    private function calcularResumoPendenciasUsuario(UsuarioInterno $usuarioAlvo, UsuarioInterno $usuarioLogado): ?array
    {
        // Assinaturas pendentes
        $assinaturasPendentes = DocumentoAssinatura::where('usuario_interno_id', $usuarioAlvo->id)
            ->where('status', 'pendente')
            ->count();

        // Processos sob responsabilidade (abertos ou parados)
        $processosQuery = Processo::where('responsavel_atual_id', $usuarioAlvo->id)
            ->whereIn('status', ['aberto', 'parado']);

        // Aplicar filtro de competência
        if ($usuarioLogado->isEstadual()) {
            $processosQuery->whereHas('estabelecimento', function ($q) {
                $q->where('competencia_manual', '!=', 'municipal')
                  ->orWhereNull('competencia_manual');
            });
        } elseif ($usuarioLogado->isMunicipal() && $usuarioLogado->municipio_id) {
            $processosQuery->whereHas('estabelecimento', function ($q) use ($usuarioLogado) {
                $q->where('municipio_id', $usuarioLogado->municipio_id);
            });
        }

        $processosAbertos = $processosQuery->count();

        // Respostas pendentes de análise (documentos que o usuário assinou mas resposta não foi analisada)
        $respostasPendentes = DocumentoResposta::where('status', 'pendente')
            ->whereHas('documentoDigital.assinaturas', function ($q) use ($usuarioAlvo) {
                $q->where('usuario_interno_id', $usuarioAlvo->id)
                  ->where('status', 'assinado');
            })
            ->count();

        // OS com atividades pendentes para este usuário
        $ordensComPendencia = OrdemServico::where('status', 'em_andamento')
            ->get()
            ->filter(function ($os) use ($usuarioAlvo) {
                return count($os->getAtividadesPendentesParaTecnico($usuarioAlvo->id)) > 0;
            })
            ->count();

        $totalPendencias = $assinaturasPendentes + $processosAbertos + $respostasPendentes + $ordensComPendencia;

        return [
            'id' => $usuarioAlvo->id,
            'nome' => $usuarioAlvo->nome,
            'nivel_acesso' => $usuarioAlvo->nivel_acesso->label(),
            'setor' => $usuarioAlvo->setor,
            'assinaturas_pendentes' => $assinaturasPendentes,
            'processos_abertos' => $processosAbertos,
            'respostas_pendentes' => $respostasPendentes,
            'os_pendentes' => $ordensComPendencia,
            'total_pendencias' => $totalPendencias,
        ];
    }

    /**
     * Calcula pendências detalhadas de um usuário específico
     */
    private function calcularPendenciasUsuario(UsuarioInterno $usuarioAlvo, UsuarioInterno $usuarioLogado): array
    {
        // 1. Assinaturas pendentes (com detalhes do documento)
        $assinaturas = DocumentoAssinatura::where('usuario_interno_id', $usuarioAlvo->id)
            ->where('status', 'pendente')
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.tipoDocumento'])
            ->get()
            ->map(function ($ass) {
                $doc = $ass->documentoDigital;
                $processo = $doc?->processo;
                $estab = $processo?->estabelecimento;
                return [
                    'documento_id' => $doc?->id,
                    'tipo_documento' => $doc?->tipoDocumento?->nome ?? $doc?->nome ?? 'Documento',
                    'numero_documento' => $doc?->numero_documento,
                    'processo_numero' => $processo?->numero_processo,
                    'estabelecimento' => $estab?->nome_fantasia ?? $estab?->razao_social ?? '-',
                    'criado_em' => $doc?->created_at,
                    'url' => $estab && $processo
                        ? route('admin.estabelecimentos.processos.show', [$estab->id, $processo->id]) . '#documento-digital-' . $doc->id
                        : '#',
                ];
            });

        // 2. Processos sob responsabilidade
        $processosQuery = Processo::where('responsavel_atual_id', $usuarioAlvo->id)
            ->whereIn('status', ['aberto', 'parado'])
            ->with(['estabelecimento', 'tipoProcesso']);

        if ($usuarioLogado->isEstadual()) {
            $processosQuery->whereHas('estabelecimento', function ($q) {
                $q->where('competencia_manual', '!=', 'municipal')
                  ->orWhereNull('competencia_manual');
            });
        } elseif ($usuarioLogado->isMunicipal() && $usuarioLogado->municipio_id) {
            $processosQuery->whereHas('estabelecimento', function ($q) use ($usuarioLogado) {
                $q->where('municipio_id', $usuarioLogado->municipio_id);
            });
        }

        $processos = $processosQuery->orderByDesc('created_at')->get()->map(function ($p) {
            return [
                'id' => $p->id,
                'numero_processo' => $p->numero_processo,
                'tipo' => $p->tipoProcesso?->nome ?? ucfirst($p->tipo),
                'status' => $p->status,
                'estabelecimento' => $p->estabelecimento?->nome_fantasia ?? $p->estabelecimento?->razao_social ?? '-',
                'criado_em' => $p->created_at,
                'url' => route('admin.estabelecimentos.processos.show', [$p->estabelecimento_id, $p->id]),
            ];
        });

        // 3. Respostas pendentes de análise (documentos assinados pelo usuário com resposta não analisada)
        $respostas = DocumentoResposta::where('status', 'pendente')
            ->whereHas('documentoDigital.assinaturas', function ($q) use ($usuarioAlvo) {
                $q->where('usuario_interno_id', $usuarioAlvo->id)
                  ->where('status', 'assinado');
            })
            ->with(['documentoDigital.processo.estabelecimento', 'documentoDigital.tipoDocumento'])
            ->get()
            ->map(function ($resp) {
                $doc = $resp->documentoDigital;
                $processo = $doc?->processo;
                $estab = $processo?->estabelecimento;
                return [
                    'id' => $resp->id,
                    'arquivo' => $resp->nome_original,
                    'tipo_documento' => $doc?->tipoDocumento?->nome ?? 'Documento',
                    'numero_documento' => $doc?->numero_documento,
                    'processo_numero' => $processo?->numero_processo,
                    'estabelecimento' => $estab?->nome_fantasia ?? $estab?->razao_social ?? '-',
                    'data_resposta' => $resp->created_at,
                    'prazo_analise' => $resp->prazo_analise_data_limite,
                    'dias_restantes' => $resp->dias_restantes_analise,
                    'atrasado' => $resp->isPrazoAnaliseVencido(),
                    'url' => $estab && $processo
                        ? route('admin.estabelecimentos.processos.show', [$estab->id, $processo->id]) . '#documento-digital-' . $doc->id
                        : '#',
                ];
            });

        // 4. Ordens de Serviço com atividades pendentes
        $ordensServico = OrdemServico::where('status', 'em_andamento')
            ->with(['estabelecimento'])
            ->get()
            ->filter(function ($os) use ($usuarioAlvo) {
                return count($os->getAtividadesPendentesParaTecnico($usuarioAlvo->id)) > 0;
            })
            ->map(function ($os) use ($usuarioAlvo) {
                $atividadesPendentes = $os->getAtividadesPendentesParaTecnico($usuarioAlvo->id);
                return [
                    'id' => $os->id,
                    'numero' => $os->numero,
                    'estabelecimento' => $os->estabelecimento?->nome_fantasia ?? $os->estabelecimento?->razao_social ?? '-',
                    'data_abertura' => $os->data_abertura,
                    'data_fim' => $os->data_fim,
                    'atividades_pendentes' => count($atividadesPendentes),
                    'url' => route('admin.ordens-servico.show', $os->id),
                ];
            })->values();

        return [
            'assinaturas' => $assinaturas,
            'processos' => $processos,
            'respostas' => $respostas,
            'ordens_servico' => $ordensServico,
        ];
    }

}

