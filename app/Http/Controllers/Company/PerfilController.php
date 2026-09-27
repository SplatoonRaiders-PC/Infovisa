<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Support\NomePessoaHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class PerfilController extends Controller
{
    /**
     * Exibe a página de perfil do usuário
     */
    public function index()
    {
        $usuario = auth('externo')->user();
        return view('company.perfil.index', compact('usuario'));
    }

    /**
     * Atualiza os dados do perfil (email e telefone)
     */
    public function updateDados(Request $request)
    {
        $usuario = auth('externo')->user();

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:usuarios_externos,email,' . $usuario->id],
            'telefone' => ['required', 'string', 'min:10', 'max:15'],
        ], [
            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Informe um e-mail válido.',
            'email.unique' => 'Este e-mail já está em uso.',
            'telefone.required' => 'O telefone é obrigatório.',
            'telefone.min' => 'O telefone deve ter no mínimo 10 dígitos.',
        ]);

        // Remove formatação do telefone
        $validated['telefone'] = preg_replace('/\D/', '', $validated['telefone']);

        // Nome só pode ser corrigido quando o atual parece ser de empresa
        if (NomePessoaHelper::pareceEmpresa($usuario->nome)) {
            $nome = mb_strtoupper(preg_replace('/\s+/u', ' ', trim((string) $request->input('nome', ''))), 'UTF-8');
            $erro = $nome === '' ? 'Informe seu nome completo.' : NomePessoaHelper::erroNomeCompleto($nome);

            if ($erro || mb_strlen($nome) > 255) {
                return back()->withInput()->withErrors(['nome' => $erro ?? 'O nome deve ter no máximo 255 caracteres.']);
            }

            $validated['nome'] = $nome;
        }

        $usuario->update($validated);

        return back()->with('success', 'Dados atualizados com sucesso!');
    }

    /**
     * Atualiza a senha do usuário
     */
    public function updateSenha(Request $request)
    {
        $usuario = auth('externo')->user();

        $validated = $request->validate([
            'senha_atual' => ['required', 'string'],
            'nova_senha' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'senha_atual.required' => 'Informe a senha atual.',
            'nova_senha.required' => 'Informe a nova senha.',
            'nova_senha.min' => 'A nova senha deve ter no mínimo 8 caracteres.',
            'nova_senha.confirmed' => 'A confirmação da senha não confere.',
        ]);

        // Verifica se a senha atual está correta
        if (!Hash::check($validated['senha_atual'], $usuario->password)) {
            return back()->withErrors(['senha_atual' => 'A senha atual está incorreta.']);
        }

        // Verifica se a nova senha é diferente da atual
        if (Hash::check($validated['nova_senha'], $usuario->password)) {
            return back()->withErrors(['nova_senha' => 'A nova senha deve ser diferente da atual.']);
        }

        $usuario->update([
            'password' => Hash::make($validated['nova_senha']),
        ]);

        return back()->with('success', 'Senha alterada com sucesso!');
    }
}
