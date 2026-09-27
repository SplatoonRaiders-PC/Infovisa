<?php

namespace App\Support;

/**
 * Agrupamentos de CNAE usados nos relatórios.
 *
 * Os "tipos de atividade" são agrupamentos práticos da Vigilância Sanitária (ex.: Hospitais, Farmácias),
 * definidos por prefixos do código CNAE somente com dígitos (divisão = 2, grupo = 3, classe = 4, subclasse = 7).
 */
class CnaeCatalogo
{
    /**
     * Áreas => [nome, cor (Tailwind)].
     */
    public const AREAS = [
        'saude' => ['nome' => 'Serviços de saúde', 'cor' => 'rose'],
        'produtos_saude' => ['nome' => 'Medicamentos e produtos para saúde', 'cor' => 'violet'],
        'alimentos' => ['nome' => 'Alimentos e bebidas', 'cor' => 'amber'],
        'interesse_saude' => ['nome' => 'Serviços de interesse à saúde', 'cor' => 'sky'],
        'ambiente' => ['nome' => 'Saneamento e meio ambiente', 'cor' => 'emerald'],
    ];

    /**
     * Tipos de atividade => [nome, area, prefixos CNAE].
     */
    public const TIPOS = [
        // Serviços de saúde
        'hospitais' => ['nome' => 'Hospitais', 'area' => 'saude', 'prefixos' => ['8610']],
        'urgencia' => ['nome' => 'Urgência, pronto atendimento e remoções', 'area' => 'saude', 'prefixos' => ['8621', '8622']],
        'clinicas' => ['nome' => 'Clínicas e consultórios médicos', 'area' => 'saude', 'prefixos' => ['8630501', '8630502', '8630503', '8630506', '8630507', '8630599']],
        'odontologia' => ['nome' => 'Odontologia e prótese dentária', 'area' => 'saude', 'prefixos' => ['8630504', '3250706']],
        'laboratorios' => ['nome' => 'Laboratórios clínicos e de patologia', 'area' => 'saude', 'prefixos' => ['8640201', '8640202']],
        'imagem' => ['nome' => 'Diagnóstico por imagem e métodos gráficos', 'area' => 'saude', 'prefixos' => ['8640204', '8640205', '8640206', '8640207', '8640208', '8640209']],
        'terapias_alta_complexidade' => ['nome' => 'Diálise, quimio, radio e hemoterapia', 'area' => 'saude', 'prefixos' => ['8640203', '8640210', '8640211', '8640212', '8640213', '8640214']],
        'outros_diagnostico' => ['nome' => 'Outros serviços de diagnóstico e terapia', 'area' => 'saude', 'prefixos' => ['8640299']],
        'profissionais_saude' => ['nome' => 'Fisioterapia, psicologia, nutrição e outros profissionais', 'area' => 'saude', 'prefixos' => ['8650', '8660', '8690']],
        'ilpi' => ['nome' => 'Instituições de longa permanência e comunidades terapêuticas', 'area' => 'saude', 'prefixos' => ['87']],

        // Medicamentos e produtos para saúde
        'farmacias' => ['nome' => 'Farmácias e drogarias', 'area' => 'produtos_saude', 'prefixos' => ['4771']],
        'distribuidoras_medicamentos' => ['nome' => 'Distribuidoras de medicamentos e produtos médicos', 'area' => 'produtos_saude', 'prefixos' => ['4644', '4645', '4664']],
        'comercio_artigos_medicos' => ['nome' => 'Comércio de artigos médicos, ortopédicos e ópticos', 'area' => 'produtos_saude', 'prefixos' => ['4773', '4774']],
        'cosmeticos_comercio' => ['nome' => 'Comércio de cosméticos e perfumaria', 'area' => 'produtos_saude', 'prefixos' => ['4646', '4772']],
        'industria_farmaceutica' => ['nome' => 'Indústria farmacêutica', 'area' => 'produtos_saude', 'prefixos' => ['21']],
        'industria_cosmeticos_saneantes' => ['nome' => 'Indústria de cosméticos e saneantes', 'area' => 'produtos_saude', 'prefixos' => ['2052', '2061', '2062', '2063']],
        'industria_equipamentos_medicos' => ['nome' => 'Indústria de equipamentos e materiais médicos', 'area' => 'produtos_saude', 'prefixos' => ['2660', '3250']],

        // Alimentos e bebidas
        'supermercados' => ['nome' => 'Supermercados, mercados e mercearias', 'area' => 'alimentos', 'prefixos' => ['4711', '4712']],
        'padarias' => ['nome' => 'Padarias e confeitarias', 'area' => 'alimentos', 'prefixos' => ['4721', '1091']],
        'acougues' => ['nome' => 'Açougues, peixarias e hortifrúti', 'area' => 'alimentos', 'prefixos' => ['4722', '4724']],
        'outros_comercio_alimentos' => ['nome' => 'Outros comércios de alimentos e bebidas', 'area' => 'alimentos', 'prefixos' => ['4723', '4729']],
        'restaurantes' => ['nome' => 'Restaurantes, bares e lanchonetes', 'area' => 'alimentos', 'prefixos' => ['5611', '5612']],
        'alimentacao_coletiva' => ['nome' => 'Cozinhas industriais e fornecimento de refeições', 'area' => 'alimentos', 'prefixos' => ['5620']],
        'atacado_alimentos' => ['nome' => 'Atacado e distribuição de alimentos e bebidas', 'area' => 'alimentos', 'prefixos' => ['463']],
        'industria_alimentos' => ['nome' => 'Indústria de alimentos', 'area' => 'alimentos', 'prefixos' => ['10']],
        'industria_bebidas' => ['nome' => 'Indústria de bebidas e água mineral', 'area' => 'alimentos', 'prefixos' => ['11']],

        // Serviços de interesse à saúde
        'estetica' => ['nome' => 'Salões de beleza, estética e tatuagem', 'area' => 'interesse_saude', 'prefixos' => ['9602', '9609206']],
        'academias' => ['nome' => 'Academias e atividades esportivas', 'area' => 'interesse_saude', 'prefixos' => ['9311', '9312', '9313', '9319']],
        'escolas' => ['nome' => 'Escolas e creches', 'area' => 'interesse_saude', 'prefixos' => ['851', '852']],
        'hoteis' => ['nome' => 'Hotéis e hospedagem', 'area' => 'interesse_saude', 'prefixos' => ['55']],
        'veterinaria' => ['nome' => 'Clínicas veterinárias e pet shops', 'area' => 'interesse_saude', 'prefixos' => ['7500', '4789004', '9609208']],
        'lavanderias' => ['nome' => 'Lavanderias', 'area' => 'interesse_saude', 'prefixos' => ['9601']],
        'funerarias' => ['nome' => 'Serviços funerários', 'area' => 'interesse_saude', 'prefixos' => ['9603']],

        // Saneamento e meio ambiente
        'controle_pragas' => ['nome' => 'Controle de pragas e limpeza', 'area' => 'ambiente', 'prefixos' => ['8121', '8122', '8129']],
        'agua_residuos' => ['nome' => 'Água, esgoto e resíduos', 'area' => 'ambiente', 'prefixos' => ['36', '37', '38', '39']],
    ];

    /**
     * Seções CNAE => [nome, primeira divisão, última divisão].
     */
    public const SECOES = [
        'A' => ['Agropecuária, produção florestal, pesca e aquicultura', 1, 3],
        'B' => ['Indústrias extrativas', 5, 9],
        'C' => ['Indústrias de transformação', 10, 33],
        'D' => ['Eletricidade e gás', 35, 35],
        'E' => ['Água, esgoto, gestão de resíduos e descontaminação', 36, 39],
        'F' => ['Construção', 41, 43],
        'G' => ['Comércio; reparação de veículos', 45, 47],
        'H' => ['Transporte, armazenagem e correio', 49, 53],
        'I' => ['Alojamento e alimentação', 55, 56],
        'J' => ['Informação e comunicação', 58, 63],
        'K' => ['Atividades financeiras e seguros', 64, 66],
        'L' => ['Atividades imobiliárias', 68, 68],
        'M' => ['Atividades profissionais, científicas e técnicas', 69, 75],
        'N' => ['Atividades administrativas e serviços complementares', 77, 82],
        'O' => ['Administração pública, defesa e seguridade social', 84, 84],
        'P' => ['Educação', 85, 85],
        'Q' => ['Saúde humana e serviços sociais', 86, 88],
        'R' => ['Artes, cultura, esporte e recreação', 90, 93],
        'S' => ['Outras atividades de serviços', 94, 96],
        'T' => ['Serviços domésticos', 97, 97],
        'U' => ['Organismos internacionais', 99, 99],
    ];

    /**
     * Divisões CNAE 2.3 (2 primeiros dígitos).
     */
    public const DIVISOES = [
        '01' => 'Agricultura, pecuária e serviços relacionados',
        '02' => 'Produção florestal',
        '03' => 'Pesca e aquicultura',
        '05' => 'Extração de carvão mineral',
        '06' => 'Extração de petróleo e gás natural',
        '07' => 'Extração de minerais metálicos',
        '08' => 'Extração de minerais não metálicos',
        '09' => 'Apoio à extração de minerais',
        '10' => 'Fabricação de produtos alimentícios',
        '11' => 'Fabricação de bebidas',
        '12' => 'Fabricação de produtos do fumo',
        '13' => 'Fabricação de produtos têxteis',
        '14' => 'Confecção de vestuário e acessórios',
        '15' => 'Couros, artigos de viagem e calçados',
        '16' => 'Fabricação de produtos de madeira',
        '17' => 'Celulose, papel e produtos de papel',
        '18' => 'Impressão e reprodução de gravações',
        '19' => 'Derivados do petróleo e biocombustíveis',
        '20' => 'Fabricação de produtos químicos',
        '21' => 'Fabricação de produtos farmoquímicos e farmacêuticos',
        '22' => 'Produtos de borracha e material plástico',
        '23' => 'Produtos de minerais não metálicos',
        '24' => 'Metalurgia',
        '25' => 'Produtos de metal',
        '26' => 'Equipamentos de informática, eletrônicos e ópticos',
        '27' => 'Máquinas, aparelhos e materiais elétricos',
        '28' => 'Máquinas e equipamentos',
        '29' => 'Veículos automotores, reboques e carrocerias',
        '30' => 'Outros equipamentos de transporte',
        '31' => 'Fabricação de móveis',
        '32' => 'Fabricação de produtos diversos',
        '33' => 'Manutenção, reparação e instalação de máquinas',
        '35' => 'Eletricidade, gás e outras utilidades',
        '36' => 'Captação, tratamento e distribuição de água',
        '37' => 'Esgoto e atividades relacionadas',
        '38' => 'Coleta, tratamento e disposição de resíduos',
        '39' => 'Descontaminação e gestão de resíduos',
        '41' => 'Construção de edifícios',
        '42' => 'Obras de infraestrutura',
        '43' => 'Serviços especializados para construção',
        '45' => 'Comércio e reparação de veículos',
        '46' => 'Comércio por atacado',
        '47' => 'Comércio varejista',
        '49' => 'Transporte terrestre',
        '50' => 'Transporte aquaviário',
        '51' => 'Transporte aéreo',
        '52' => 'Armazenamento e atividades auxiliares dos transportes',
        '53' => 'Correio e outras atividades de entrega',
        '55' => 'Alojamento',
        '56' => 'Alimentação',
        '58' => 'Edição e edição integrada à impressão',
        '59' => 'Cinema, vídeo, televisão, som e música',
        '60' => 'Rádio e televisão',
        '61' => 'Telecomunicações',
        '62' => 'Tecnologia da informação',
        '63' => 'Prestação de serviços de informação',
        '64' => 'Serviços financeiros',
        '65' => 'Seguros, previdência complementar e planos de saúde',
        '66' => 'Atividades auxiliares dos serviços financeiros',
        '68' => 'Atividades imobiliárias',
        '69' => 'Atividades jurídicas, de contabilidade e auditoria',
        '70' => 'Sedes de empresas e consultoria em gestão',
        '71' => 'Arquitetura e engenharia; testes e análises técnicas',
        '72' => 'Pesquisa e desenvolvimento científico',
        '73' => 'Publicidade e pesquisa de mercado',
        '74' => 'Outras atividades profissionais, científicas e técnicas',
        '75' => 'Atividades veterinárias',
        '77' => 'Aluguéis não imobiliários',
        '78' => 'Seleção, agenciamento e locação de mão de obra',
        '79' => 'Agências de viagens e operadores turísticos',
        '80' => 'Vigilância, segurança e investigação',
        '81' => 'Serviços para edifícios e paisagismo',
        '82' => 'Serviços de escritório e apoio administrativo',
        '84' => 'Administração pública, defesa e seguridade social',
        '85' => 'Educação',
        '86' => 'Atenção à saúde humana',
        '87' => 'Saúde humana integrada com assistência social (residências coletivas)',
        '88' => 'Assistência social sem alojamento',
        '90' => 'Atividades artísticas, criativas e de espetáculos',
        '91' => 'Patrimônio cultural e ambiental',
        '92' => 'Jogos de azar e apostas',
        '93' => 'Atividades esportivas, recreação e lazer',
        '94' => 'Organizações associativas',
        '95' => 'Reparação de equipamentos e objetos pessoais',
        '96' => 'Outras atividades de serviços pessoais',
        '97' => 'Serviços domésticos',
        '99' => 'Organismos internacionais',
    ];

    /**
     * Retorna o slug do tipo de atividade de um CNAE (prefixo mais longo vence), ou null.
     */
    public static function tipoDoCnae(string $codigo): ?string
    {
        static $cache = [];

        if (array_key_exists($codigo, $cache)) {
            return $cache[$codigo];
        }

        $melhor = null;
        $tamanhoMelhor = 0;

        foreach (self::TIPOS as $slug => $tipo) {
            foreach ($tipo['prefixos'] as $prefixo) {
                $tamanho = strlen($prefixo);
                if ($tamanho > $tamanhoMelhor && str_starts_with($codigo, $prefixo)) {
                    $melhor = $slug;
                    $tamanhoMelhor = $tamanho;
                }
            }
        }

        return $cache[$codigo] = $melhor;
    }

    public static function nomeTipo(?string $slug): string
    {
        return self::TIPOS[$slug]['nome'] ?? 'Outras atividades';
    }

    public static function areaDoTipo(?string $slug): ?string
    {
        return self::TIPOS[$slug]['area'] ?? null;
    }

    public static function nomeDivisao(string $divisao): string
    {
        return self::DIVISOES[$divisao] ?? 'Divisão ' . $divisao;
    }

    public static function secaoDaDivisao(string $divisao): ?string
    {
        $numero = (int) $divisao;

        foreach (self::SECOES as $letra => [, $inicio, $fim]) {
            if ($numero >= $inicio && $numero <= $fim) {
                return $letra;
            }
        }

        return null;
    }

    /**
     * Tipos agrupados por área, para montar selects.
     *
     * @return array<string, array{nome: string, cor: string, tipos: array<string, string>}>
     */
    public static function tiposPorArea(): array
    {
        $resultado = [];

        foreach (self::AREAS as $area => $dados) {
            $resultado[$area] = $dados + ['tipos' => []];
        }

        foreach (self::TIPOS as $slug => $tipo) {
            $resultado[$tipo['area']]['tipos'][$slug] = $tipo['nome'];
        }

        return $resultado;
    }
}
