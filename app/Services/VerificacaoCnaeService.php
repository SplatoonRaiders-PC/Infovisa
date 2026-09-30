<?php

namespace App\Services;

use App\Models\Estabelecimento;
use App\Models\EstabelecimentoVerificacaoCnae;
use App\Models\Pactuacao;
use Illuminate\Support\Facades\Log;

/**
 * Compara os CNAEs atuais do CNPJ (Receita) com as atividades marcadas no
 * estabelecimento e registra divergências que podem alterar a competência:
 * - atividade marcada que saiu do CNPJ
 * - CNAE novo no CNPJ (ainda não revisado pela equipe)
 */
class VerificacaoCnaeService
{
    private const CODIGOS_ESPECIAIS = ['PROJ_ARQ', 'ANAL_ROT'];

    public function __construct(private CnpjService $cnpjService)
    {
    }

    /**
     * Verifica um estabelecimento. $dadosReceita permite reaproveitar uma consulta já feita.
     */
    public function verificar(Estabelecimento $estabelecimento, ?array $dadosReceita = null): EstabelecimentoVerificacaoCnae
    {
        $verificacao = EstabelecimentoVerificacaoCnae::firstOrNew(['estabelecimento_id' => $estabelecimento->id]);

        $dadosReceita ??= $this->cnpjService->consultarCnpjFontesGratuitas((string) $estabelecimento->cnpj);
        $cnaesReceita = $dadosReceita ? $this->extrairCnaesReceita($dadosReceita) : [];

        // Sem resposta confiável (API fora ou sem CNAE principal): não gera alerta falso
        if (empty($cnaesReceita)) {
            $verificacao->fill([
                'status' => $verificacao->exists ? $verificacao->status : 'erro',
                'erro' => 'Não foi possível obter os CNAEs do CNPJ nas fontes gratuitas.',
                'verificado_em' => now(),
            ])->save();

            return $verificacao;
        }

        // Códigos sempre como texto (chaves numéricas de array viram int no PHP)
        $codigosReceita = array_map('strval', array_keys($cnaesReceita));

        // Base revisada: na 1ª verificação, os CNAEs gravados no cadastro
        $base = array_map('strval', $verificacao->cnaes_base ?? $this->cnaesDoCadastro($estabelecimento));
        $ignorados = array_map('strval', $verificacao->cnaes_ignorados ?? []);

        $municipio = $this->municipioDaRegra($estabelecimento);

        // 1) Atividades marcadas (vindas da Receita, não manuais) que saíram do CNPJ
        $removidos = [];
        foreach ($this->atividadesMarcadas($estabelecimento) as $codigo => $descricao) {
            $codigo = (string) $codigo;
            if (!in_array($codigo, $codigosReceita, true) && !in_array($codigo, $ignorados, true)) {
                $removidos[] = [
                    'codigo' => $codigo,
                    'descricao' => $descricao,
                    'competencia' => $this->competenciaDoCnae($estabelecimento, $codigo, $municipio),
                ];
            }
        }

        // 2) CNAEs que entraram no CNPJ desde a última revisão
        $novos = [];
        foreach ($cnaesReceita as $codigo => $descricao) {
            $codigo = (string) $codigo;
            if (!in_array($codigo, $base, true)) {
                $novos[] = [
                    'codigo' => $codigo,
                    'descricao' => $descricao,
                    'competencia' => $this->competenciaDoCnae($estabelecimento, $codigo, $municipio),
                ];
            }
        }

        $competenciaAtual = $estabelecimento->isCompetenciaEstadual() ? 'estadual' : 'municipal';
        $competenciaSugerida = empty($removidos)
            ? $competenciaAtual
            : $this->competenciaSemAtividades($estabelecimento, array_column($removidos, 'codigo'));

        $divergente = !empty($removidos) || !empty($novos);

        $verificacao->fill([
            'status' => $divergente ? 'divergente' : 'ok',
            'cnaes_receita' => $codigosReceita,
            'cnaes_base' => $base,
            'cnaes_ignorados' => $ignorados,
            'cnaes_removidos' => $removidos,
            'cnaes_novos' => $novos,
            'competencia_atual' => $competenciaAtual,
            'competencia_sugerida' => $competenciaSugerida,
            'altera_competencia' => $competenciaAtual !== $competenciaSugerida,
            'novo_cnae_estadual' => collect($novos)->contains('competencia', 'estadual'),
            'fonte' => $dadosReceita['api_source'] ?? null,
            'erro' => null,
            'verificado_em' => now(),
            // Mantém a data da 1ª detecção enquanto o alerta continuar aberto
            'detectado_em' => $divergente
                ? ($verificacao->status === 'divergente' && $verificacao->detectado_em ? $verificacao->detectado_em : now())
                : null,
        ])->save();

        return $verificacao;
    }

    /**
     * Chamado quando a equipe salva as atividades: encerra o alerta.
     * CNAEs novos passam a ser a base revisada; atividades que continuaram marcadas
     * mesmo fora do CNPJ não voltam a alertar.
     */
    public function marcarRevisado(Estabelecimento $estabelecimento, ?int $usuarioId = null): void
    {
        $verificacao = EstabelecimentoVerificacaoCnae::where('estabelecimento_id', $estabelecimento->id)->first();

        if (!$verificacao) {
            return;
        }

        $marcadas = array_map('strval', array_keys($this->atividadesMarcadas($estabelecimento->fresh() ?? $estabelecimento)));
        $receita = array_map('strval', $verificacao->cnaes_receita ?? []);
        $mantidasForaDoCnpj = empty($receita) ? [] : array_values(array_diff($marcadas, $receita));

        $verificacao->fill([
            'status' => 'ok',
            'cnaes_base' => $receita ?: $verificacao->cnaes_base,
            'cnaes_ignorados' => $mantidasForaDoCnpj,
            'cnaes_removidos' => [],
            'cnaes_novos' => [],
            'altera_competencia' => false,
            'novo_cnae_estadual' => false,
            'detectado_em' => null,
            'revisado_em' => now(),
            'revisado_por' => $usuarioId,
        ])->save();
    }

    /**
     * [codigo => descricao] dos CNAEs retornados pela API (principal + secundários)
     */
    private function extrairCnaesReceita(array $dados): array
    {
        $cnaes = [];
        $principal = preg_replace('/[^0-9]/', '', (string) ($dados['cnae_fiscal'] ?? ''));

        // Sem CNAE principal a resposta não é confiável
        if (strlen($principal) !== 7) {
            return [];
        }

        $cnaes[$principal] = (string) ($dados['cnae_fiscal_descricao'] ?? '');

        foreach (($dados['cnaes_secundarios'] ?? []) as $secundario) {
            $codigo = preg_replace('/[^0-9]/', '', (string) (is_array($secundario) ? ($secundario['codigo'] ?? '') : $secundario));
            if (strlen($codigo) === 7 && !isset($cnaes[$codigo])) {
                $cnaes[$codigo] = (string) (is_array($secundario) ? ($secundario['descricao'] ?? '') : '');
            }
        }

        return $cnaes;
    }

    /**
     * CNAEs do CNPJ gravados no cadastro (ignora os adicionados manualmente)
     */
    private function cnaesDoCadastro(Estabelecimento $estabelecimento): array
    {
        $codigos = [preg_replace('/[^0-9]/', '', (string) $estabelecimento->cnae_fiscal)];

        foreach (($estabelecimento->cnaes_secundarios ?? []) as $cnae) {
            if (is_array($cnae) && !empty($cnae['manual'])) {
                continue;
            }
            $codigos[] = preg_replace('/[^0-9]/', '', (string) (is_array($cnae) ? ($cnae['codigo'] ?? '') : $cnae));
        }

        return array_values(array_unique(array_filter($codigos, fn ($c) => strlen($c) === 7)));
    }

    /**
     * [codigo => descricao] das atividades marcadas que deveriam constar no CNPJ
     * (exclui atividades manuais e as especiais PROJ_ARQ/ANAL_ROT)
     */
    private function atividadesMarcadas(Estabelecimento $estabelecimento): array
    {
        $marcadas = [];

        foreach (($estabelecimento->atividades_exercidas ?? []) as $atividade) {
            $codigoOriginal = is_array($atividade) ? (string) ($atividade['codigo'] ?? '') : (string) $atividade;

            if (in_array(strtoupper($codigoOriginal), self::CODIGOS_ESPECIAIS, true)) {
                continue;
            }
            if (is_array($atividade) && !empty($atividade['manual'])) {
                continue;
            }

            $codigo = preg_replace('/[^0-9]/', '', $codigoOriginal);
            if (strlen($codigo) === 7) {
                $marcadas[$codigo] = is_array($atividade) ? (string) ($atividade['descricao'] ?? '') : '';
            }
        }

        return $marcadas;
    }

    private function municipioDaRegra(Estabelecimento $estabelecimento): ?string
    {
        // Mesma normalização de Estabelecimento::isCompetenciaEstadual()
        return $estabelecimento->cidade
            ? trim(preg_replace('/\s*[-\/]\s*TO\s*$/i', '', $estabelecimento->cidade))
            : null;
    }

    private function competenciaDoCnae(Estabelecimento $estabelecimento, string $codigo, ?string $municipio): string
    {
        try {
            $resultado = Pactuacao::verificarCompetenciaAvancada(
                $codigo,
                $municipio,
                $estabelecimento->respostas_questionario[$codigo] ?? null,
                $estabelecimento->respostas_questionario2[$codigo] ?? null
            );

            return $resultado['competencia'] ?? 'municipal';
        } catch (\Throwable $e) {
            Log::warning('Falha ao calcular competência do CNAE na verificação', ['cnae' => $codigo, 'erro' => $e->getMessage()]);
            return 'municipal';
        }
    }

    /**
     * Competência que o estabelecimento teria sem as atividades informadas
     */
    private function competenciaSemAtividades(Estabelecimento $estabelecimento, array $codigosRemover): string
    {
        $codigosRemover = array_map('strval', $codigosRemover);
        $simulado = clone $estabelecimento;
        $simulado->atividades_exercidas = collect($estabelecimento->atividades_exercidas ?? [])
            ->reject(function ($atividade) use ($codigosRemover) {
                $codigo = preg_replace('/[^0-9]/', '', (string) (is_array($atividade) ? ($atividade['codigo'] ?? '') : $atividade));
                return in_array($codigo, $codigosRemover, true);
            })
            ->values()
            ->all();

        return $simulado->isCompetenciaEstadual() ? 'estadual' : 'municipal';
    }
}
