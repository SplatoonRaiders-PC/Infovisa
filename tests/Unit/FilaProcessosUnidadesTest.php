<?php

namespace Tests\Unit;

use App\Http\Controllers\Public\HomeController;
use App\Models\Estabelecimento;
use App\Models\Processo;
use App\Models\ProcessoDocumento;
use App\Models\ProcessoPasta;
use App\Models\TipoProcesso;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class FilaProcessosUnidadesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-28 11:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function processo(): Processo
    {
        // Sem acesso ao banco: exercita a elegibilidade e os cálculos reais usando
        // um checklist fixo e relações carregadas, inclusive o último envio.
        $processo = $this->getMockBuilder(Processo::class)
            ->onlyMethods(['getDocumentosObrigatoriosChecklist', 'getPrazoFilaPublicaReiniciadoEmEfetivo'])
            ->getMock();
        $processo->method('getDocumentosObrigatoriosChecklist')->willReturn(collect([
            ['id' => 1, 'obrigatorio' => true],
            ['id' => 2, 'obrigatorio' => true],
            ['id' => 3, 'obrigatorio' => true],
        ]));
        $processo->method('getPrazoFilaPublicaReiniciadoEmEfetivo')->willReturn(null);
        $processo->setDateFormat('Y-m-d H:i:s');
        $processo->forceFill([
            'numero_processo' => '2026/00336', 'status' => 'aberto',
            'created_at' => '2026-03-31 10:03:00',
        ]);
        $estabelecimento = $this->getMockBuilder(Estabelecimento::class)
            ->onlyMethods(['getGrupoRisco'])->getMock();
        $estabelecimento->method('getGrupoRisco')->willReturn('alto');
        $estabelecimento->nome_fantasia = 'HOSPITAL GERAL DE PALMAS';
        $processo->setRelation('estabelecimento', $estabelecimento);
        $processo->setRelation('unidades', new Collection());
        $processo->setRelation('pastas', new Collection());
        $processo->setRelation('documentos', new Collection());
        $this->adicionarUnidade($processo, 1, 'PS INFANTIL');

        return $processo;
    }

    private function adicionarUnidade(Processo $processo, int $id, string $nome): ProcessoPasta
    {
        $pasta = new ProcessoPasta();
        $pasta->setDateFormat('Y-m-d H:i:s');
        $pasta->forceFill([
            'id' => $id, 'unidade_id' => $id, 'nome' => $nome, 'status' => 'ativo',
            'ordem' => $id, 'prazo_fila_publica_reiniciado_em' => '2026-09-28 11:00:00',
        ]);
        $processo->pastas->push($pasta);
        foreach ([1, 2, 3] as $tipo) {
            $documento = new ProcessoDocumento();
            $documento->setDateFormat('Y-m-d H:i:s');
            $documento->forceFill([
                'pasta_id' => $id, 'tipo_documento_obrigatorio_id' => $tipo,
                'status_aprovacao' => 'aprovado', 'created_at' => '2026-08-13 10:00:00',
                'aprovado_em' => '2026-08-14 10:00:00',
            ]);
            $processo->documentos->push($documento);
        }

        return $pasta;
    }

    private function entradas(Processo $processo, bool $raizCompleta = false): array
    {
        $tipo = new TipoProcesso();
        $tipo->forceFill(['prazo_fila_publica' => 30, 'prazo_fila_publica_alto' => 60]);

        return (new ReflectionMethod(HomeController::class, 'montarEntradasFila'))
            ->invoke(new HomeController(), $processo, $tipo, [
                'completo' => $raizCompleta,
                'data_ultimo_aprovado' => $raizCompleta ? Carbon::parse('2026-09-20 11:00:00') : null,
            ]);
    }

    private function entrada(Processo $processo, bool $raizCompleta = false): ?array
    {
        return $this->entradas($processo, $raizCompleta)[0] ?? null;
    }

    public function test_unidade_completa_entra_mesmo_com_raiz_e_outra_unidade_pendentes(): void
    {
        $processo = $this->processo();
        $this->adicionarUnidade($processo, 2, 'Nova Unidade');
        $processo->documentos->last()->status_aprovacao = 'pendente';
        $entrada = $this->entrada($processo);

        $this->assertNotNull($entrada);
        $this->assertCount(1, $entrada['unidades_prazo']);
        $this->assertSame('PS INFANTIL', $entrada['unidade_referencia']);
        $this->assertSame('28/09/2026 11:00', $entrada['data_referencia_prazo']);
        $this->assertSame(60, $entrada['prazo']);
        $this->assertSame(60, $entrada['dias_restantes']);
        $this->assertTrue($entrada['prazo_reiniciado']);
        $this->assertSame('0h', $entrada['tempo_formatado']);
    }

    public function test_unidade_concluida_nao_entra_na_fila(): void
    {
        $processo = $this->processo();
        $processo->pastas->first()->status = 'concluida';
        $this->assertNull($this->entrada($processo));
    }

    public function test_reenvio_pendente_impede_entrada_apesar_da_aprovacao_antiga(): void
    {
        $processo = $this->processo();
        $reenvio = clone $processo->documentos->first();
        $reenvio->created_at = '2026-09-28 10:00:00';
        $reenvio->status_aprovacao = 'pendente';
        $processo->documentos->push($reenvio);
        $this->assertNull($this->entrada($processo));
    }

    public function test_prazo_da_unidade_parada_permanece_congelado(): void
    {
        $processo = $this->processo();
        $pasta = $processo->pastas->first();
        $pasta->status = 'parado';
        $pasta->data_parada = '2026-09-28 11:00:00';
        Carbon::setTestNow('2026-09-30 11:00:00');
        $entrada = $this->entrada($processo);

        $this->assertTrue($entrada['pausado']);
        $this->assertSame('parado', $entrada['status']);
        $this->assertSame('aberto', $processo->status);
        $this->assertSame(60, $entrada['dias_restantes']);
        $this->assertSame('0h', $entrada['tempo_formatado']);
    }

    public function test_unidade_ativa_define_referencia_quando_outra_esta_suspensa(): void
    {
        $processo = $this->processo();
        $pasta = $processo->pastas->first();
        $pasta->status = 'parado';
        $pasta->data_parada = '2026-09-20 11:00:00';
        $pasta->prazo_fila_publica_reiniciado_em = '2026-09-20 11:00:00';
        $this->adicionarUnidade($processo, 2, 'BANCO DE OLHOS');
        $entradas = $this->entradas($processo);
        $entrada = $entradas[0];

        $this->assertCount(2, $entradas);
        $this->assertCount(1, $entrada['unidades_prazo']);
        $this->assertSame('BANCO DE OLHOS', $entrada['unidade_referencia']);
        $this->assertFalse($entrada['pausado']);
        $this->assertSame('aberto', $entrada['status']);
        $this->assertSame('parado', $entradas[1]['status']);
        $this->assertCount(1, $entradas[1]['unidades_prazo']);
        $this->assertSame('PS INFANTIL', $entradas[1]['unidades_prazo'][0]['nome']);
    }

    public function test_raiz_completa_nao_mistura_unidade_suspensa_com_abertos(): void
    {
        $processo = $this->processo();
        $processo->pastas->first()->status = 'parado';
        $entradas = $this->entradas($processo, true);

        $this->assertCount(2, $entradas);
        $this->assertSame('aberto', $entradas[0]['status']);
        $this->assertSame([], $entradas[0]['unidades_prazo']);
        $this->assertSame('parado', $entradas[1]['status']);
        $this->assertTrue($entradas[1]['pausado']);
    }

    public function test_processo_parado_coloca_todas_as_unidades_em_parado(): void
    {
        $processo = $this->processo();
        $processo->status = 'parado';
        $processo->data_parada = '2026-09-28 11:00:00';
        $this->adicionarUnidade($processo, 2, 'BANCO DE OLHOS');
        $entradas = $this->entradas($processo);

        $this->assertCount(1, $entradas);
        $this->assertSame('parado', $entradas[0]['status']);
        $this->assertCount(2, $entradas[0]['unidades_prazo']);
        $this->assertTrue($entradas[0]['unidades_prazo'][0]['pausado']);
        $this->assertTrue($entradas[0]['unidades_prazo'][1]['pausado']);
    }

    public function test_processo_sem_unidades_preserva_prazo_da_raiz(): void
    {
        $processo = $this->processo();
        $processo->setRelation('pastas', new Collection());
        $entrada = $this->entrada($processo, true);

        $this->assertSame([], $entrada['unidades_prazo']);
        $this->assertNull($entrada['unidade_referencia']);
        $this->assertSame(52, $entrada['dias_restantes']);
        $this->assertSame('20/09/2026 11:00', $entrada['data_referencia_prazo']);
    }
}
