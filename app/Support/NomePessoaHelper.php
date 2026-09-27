<?php

namespace App\Support;

class NomePessoaHelper
{
    /**
     * Palavras (maiúsculas, sem acentos) que indicam razão social / nome fantasia, não nome de pessoa.
     *
     * @var list<string>
     */
    public const TERMOS_EMPRESA = [
        // Natureza / porte
        'LTDA', 'EIRELI', 'EPP', 'ME', 'MEI', 'CIA', 'HOLDING', 'GRUPO', 'EMPRESA', 'EMPREENDIMENTOS',
        'ASSOCIACAO', 'INSTITUTO', 'FUNDACAO', 'CONDOMINIO', 'COOPERATIVA', 'SINDICATO',
        'PREFEITURA', 'SECRETARIA', 'MUNICIPIO', 'MUNICIPAL', 'ESTADUAL', 'FEDERAL', 'GOVERNO', 'IGREJA',
        // Ramo de atividade
        'COMERCIO', 'COMERCIAL', 'SERVICO', 'SERVICOS', 'INDUSTRIA', 'INDUSTRIAL',
        'DISTRIBUIDORA', 'DISTRIBUIDOR', 'DISTRIBUICAO', 'ATACADO', 'ATACADISTA', 'VAREJO', 'VAREJISTA',
        'IMPORTACAO', 'EXPORTACAO', 'REPRESENTACAO', 'REPRESENTACOES', 'LOJA', 'PRODUTOS', 'EQUIPAMENTOS',
        'FARMACIA', 'DROGARIA', 'MEDICAMENTOS', 'COSMETICOS', 'ALIMENTOS', 'BEBIDAS',
        'RESTAURANTE', 'LANCHONETE', 'LANCHES', 'PIZZARIA', 'SORVETERIA', 'PADARIA', 'PANIFICADORA', 'CONFEITARIA',
        'SUPERMERCADO', 'MERCADO', 'MINIMERCADO', 'MERCEARIA', 'ACOUGUE', 'FRIGORIFICO', 'HORTIFRUTI',
        'CLINICA', 'HOSPITAL', 'HOSPITALAR', 'HOSPITALARES', 'LABORATORIO', 'CONSULTORIO', 'ODONTOLOGIA', 'ODONTOLOGICA',
        'SAUDE', 'ESTETICA', 'SALAO', 'BARBEARIA', 'PETSHOP', 'VETERINARIA', 'AGROPECUARIA',
        'HOTEL', 'POUSADA', 'MOTEL', 'ACADEMIA', 'ESCOLA', 'COLEGIO', 'CRECHE', 'CENTRO',
        'TRANSPORTES', 'TRANSPORTADORA', 'LOGISTICA', 'CONSTRUTORA', 'ENGENHARIA',
    ];

    /**
     * Retorna a palavra que faz o nome parecer de empresa, ou null se parecer nome de pessoa.
     */
    public static function termoEmpresa(?string $nome): ?string
    {
        $normalizado = self::normalizar((string) $nome);

        if ($normalizado === '') {
            return null;
        }

        // "S/A", "S.A.", "&" e números (CNPJ, filial) não aparecem em nome de pessoa
        if (preg_match('/\bS\s*[\/.]\s*A\b\.?/', $normalizado, $m)) {
            return trim($m[0]);
        }
        if (str_contains($normalizado, '&')) {
            return '&';
        }
        if (preg_match('/\d/', $normalizado)) {
            return 'números';
        }

        $palavras = preg_split('/[^A-Z]+/', $normalizado, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($palavras as $palavra) {
            if (in_array($palavra, self::TERMOS_EMPRESA, true)) {
                return $palavra;
            }
        }

        return null;
    }

    public static function pareceEmpresa(?string $nome): bool
    {
        return self::termoEmpresa($nome) !== null;
    }

    /**
     * Valida um nome completo de pessoa física. Retorna a mensagem de erro ou null se válido.
     * Espera o nome já normalizado (sem espaços duplicados).
     */
    public static function erroNomeCompleto(string $nome): ?string
    {
        if ($termo = self::termoEmpresa($nome)) {
            return "O nome informado parece ser de uma empresa (\"{$termo}\"). Informe o seu nome completo, como consta no CPF.";
        }

        if (!preg_match('/^[\pL\s\'\.-]+$/u', $nome)) {
            return 'O nome deve conter apenas letras.';
        }

        if (count(explode(' ', trim($nome))) < 2) {
            return 'Informe seu nome completo (nome e sobrenome).';
        }

        return null;
    }

    /**
     * Maiúsculas, sem acentos e com espaços simples.
     */
    public static function normalizar(string $nome): string
    {
        $nome = mb_strtoupper(trim($nome), 'UTF-8');
        $nome = strtr($nome, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);

        return preg_replace('/\s+/', ' ', $nome);
    }
}
