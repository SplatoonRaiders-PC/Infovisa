<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegistroUsuarioExternoRequest;
use App\Enums\VinculoEstabelecimento;
use App\Models\UsuarioExterno;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RegistroController extends Controller
{
    /**
     * Exibe o formulário de registro
     */
    public function showRegistroForm(Request $request)
    {
        // Cadastro habilitado para todos os CPFs
        $cpfFornecido = preg_replace('/\D/', '', (string) $request->query('cpf', ''));

        return view('auth.registro', compact('cpfFornecido'));
    }

    /**
     * Processa o registro do usuário externo
     */
    public function registro(RegistroUsuarioExternoRequest $request)
    {
        // Dados já normalizados pelo FormRequest (CPF/telefone só dígitos, nome maiúsculo, e-mail minúsculo)
        $dados = $request->safe()->only(['nome', 'cpf', 'email', 'telefone', 'password']);
        $dados['vinculo_estabelecimento'] = VinculoEstabelecimento::PROPRIETARIO->value;
        $dados['ativo'] = true;
        $dados['aceite_termos_em'] = now();
        $dados['ip_aceite_termos'] = $request->ip();

        try {
            $usuario = UsuarioExterno::create($dados);
        } catch (QueryException $e) {
            // Corrida entre dois envios simultâneos: a constraint unique do banco é a última barreira
            $mensagem = strtolower($e->getMessage());

            Log::warning('Falha no cadastro de usuário externo (QueryException)', [
                'cpf' => $dados['cpf'],
                'email' => $dados['email'],
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            if (str_contains($mensagem, 'cpf')) {
                return back()->withInput()->withErrors(['cpf' => 'Este CPF já está cadastrado. Faça login para acessar.']);
            }

            if (str_contains($mensagem, 'email')) {
                return back()->withInput()->withErrors(['email' => 'Este e-mail já está cadastrado.']);
            }

            return back()->withInput()->with('error', 'Não foi possível concluir o cadastro. Tente novamente.');
        } catch (\Throwable $e) {
            Log::error('Falha no cadastro de usuário externo', [
                'cpf' => $dados['cpf'],
                'email' => $dados['email'],
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return back()->withInput()->with('error', 'Não foi possível concluir o cadastro. Tente novamente.');
        }

        Log::info('Usuário externo cadastrado', ['id' => $usuario->id, 'ip' => $request->ip()]);

        // Login automático com nova sessão (evita fixação de sessão)
        Auth::guard('externo')->login($usuario);
        $request->session()->regenerate();

        return redirect()->route('company.dashboard')
            ->with('success', 'Cadastro realizado com sucesso! Bem-vindo ao InfoVISA.');
    }
}
