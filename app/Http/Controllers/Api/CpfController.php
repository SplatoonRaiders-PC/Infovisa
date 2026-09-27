<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UsuarioExterno;
use App\Models\UsuarioInterno;
use App\Models\Responsavel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CpfController extends Controller
{
    /**
     * Consulta CPF: valida formato, verifica base local e retorna nome se encontrado.
     */
    public function consultar(Request $request): JsonResponse
    {
        $request->validate([
            'cpf' => 'required|string|min:11|max:14',
        ]);

        $cpf = preg_replace('/\D/', '', $request->input('cpf'));

        // 1. Validação algorítmica do CPF
        if (!$this->validarCpf($cpf)) {
            return response()->json([
                'valido'    => false,
                'mensagem'  => 'CPF inválido.',
            ], 422);
        }

        // 2. Verificar se já existe cadastro como usuário externo (impedir duplicidade)
        // Mesma comparação usada na validação do cadastro (ignora máscara salva em registros antigos)
        $externoExistente = UsuarioExterno::whereRaw("regexp_replace(cpf, '[^0-9]', '', 'g') = ?", [$cpf])->exists();
        if ($externoExistente) {
            return response()->json([
                'valido'      => true,
                'cadastrado'  => true,
                'mensagem'    => 'Este CPF já possui cadastro no sistema. Faça login para acessar.',
            ]);
        }

        // 3. Buscar nome na base local (usuários internos, responsáveis)
        $nome = $this->buscarNomeLocal($cpf);

        if ($nome) {
            return response()->json([
                'valido'     => true,
                'cadastrado' => false,
                'encontrado' => true,
                'nome'       => mb_strtoupper($nome, 'UTF-8'),
                'mensagem'   => 'CPF válido. Nome encontrado automaticamente.',
            ]);
        }

        // 4. CPF válido, mas sem nome na base local
        return response()->json([
            'valido'     => true,
            'cadastrado' => false,
            'encontrado' => false,
            'nome'       => null,
            'mensagem'   => 'CPF válido. Preencha seu nome completo.',
        ]);
    }

    /**
     * Busca o nome associado ao CPF nas tabelas locais.
     */
    private function buscarNomeLocal(string $cpf): ?string
    {
        // Usuário interno
        $interno = UsuarioInterno::where('cpf', $cpf)->value('nome');
        if ($interno) {
            return $interno;
        }

        // Responsável técnico / legal
        $responsavel = Responsavel::where('cpf', $cpf)->value('nome');
        if ($responsavel) {
            return $responsavel;
        }

        return null;
    }

    /**
     * Validação algorítmica do CPF (dígitos verificadores).
     */
    private function validarCpf(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        // Rejeitar sequências repetidas (000.000.000-00, 111... etc.)
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        // Cálculo do primeiro dígito verificador
        $soma = 0;
        for ($i = 0; $i < 9; $i++) {
            $soma += intval($cpf[$i]) * (10 - $i);
        }
        $resto = $soma % 11;
        $digito1 = ($resto < 2) ? 0 : (11 - $resto);

        if (intval($cpf[9]) !== $digito1) {
            return false;
        }

        // Cálculo do segundo dígito verificador
        $soma = 0;
        for ($i = 0; $i < 10; $i++) {
            $soma += intval($cpf[$i]) * (11 - $i);
        }
        $resto = $soma % 11;
        $digito2 = ($resto < 2) ? 0 : (11 - $resto);

        return intval($cpf[10]) === $digito2;
    }
}
