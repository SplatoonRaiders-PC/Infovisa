<?php

namespace App\Http\Requests;

use App\Models\UsuarioExterno;
use App\Support\NomePessoaHelper;
use Illuminate\Foundation\Http\FormRequest;

class RegistroUsuarioExternoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Qualquer pessoa pode se cadastrar
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Os dados chegam normalizados (ver prepareForValidation):
     * CPF e telefone somente dígitos, nome em maiúsculas sem espaços duplicados e e-mail em minúsculas.
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'min:5', 'max:255', function ($attribute, $value, $fail) {
                if ($erro = NomePessoaHelper::erroNomeCompleto($value)) {
                    $fail($erro);
                }
            }],
            'cpf' => ['required', 'digits:11', function ($attribute, $value, $fail) {
                if (!self::cpfValido($value)) {
                    $fail('O CPF informado é inválido.');
                    return;
                }

                if (UsuarioExterno::whereRaw("regexp_replace(cpf, '[^0-9]', '', 'g') = ?", [$value])->exists()) {
                    $fail('Este CPF já está cadastrado. Faça login para acessar.');
                }
            }],
            'email' => ['required', 'string', 'email', 'max:255', function ($attribute, $value, $fail) {
                if (UsuarioExterno::whereRaw('LOWER(email) = ?', [$value])->exists()) {
                    $fail('Este e-mail já está cadastrado.');
                }
            }],
            'telefone' => ['required', 'digits_between:10,11'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed', function ($attribute, $value, $fail) {
                if (!preg_match('/\pL/u', $value)) {
                    $fail('A senha deve conter pelo menos uma letra.');
                }

                if (!preg_match('/\d/', $value)) {
                    $fail('A senha deve conter pelo menos um número.');
                }
            }],
            'aceite_termos' => ['accepted'],
        ];
    }

    /**
     * Valida se o CPF é matematicamente válido
     */
    public static function cpfValido(string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', $cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $soma = 0;
            for ($i = 0; $i < $t; $i++) {
                $soma += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digito = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$t] !== $digito) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'O nome é obrigatório.',
            'nome.min' => 'O nome deve ter no mínimo 5 caracteres.',
            'nome.max' => 'O nome deve ter no máximo 255 caracteres.',

            'cpf.required' => 'O CPF é obrigatório.',
            'cpf.digits' => 'O CPF deve conter 11 dígitos.',

            'email.required' => 'O e-mail é obrigatório.',
            'email.email' => 'Digite um e-mail válido.',
            'email.max' => 'O e-mail deve ter no máximo 255 caracteres.',

            'telefone.required' => 'O telefone é obrigatório.',
            'telefone.digits_between' => 'Informe um telefone válido com DDD: (00) 00000-0000.',

            'password.required' => 'A senha é obrigatória.',
            'password.min' => 'A senha deve ter no mínimo 8 caracteres.',
            'password.confirmed' => 'As senhas não conferem.',

            'aceite_termos.accepted' => 'Você deve ler e aceitar os termos e condições para continuar.',
        ];
    }

    /**
     * Normaliza os dados antes de validar
     */
    protected function prepareForValidation(): void
    {
        $nome = preg_replace('/\s+/u', ' ', trim((string) $this->input('nome', '')));

        $this->merge([
            'nome' => mb_strtoupper($nome, 'UTF-8'),
            'cpf' => preg_replace('/\D/', '', (string) $this->input('cpf', '')),
            'email' => mb_strtolower(trim((string) $this->input('email', '')), 'UTF-8'),
            'telefone' => preg_replace('/\D/', '', (string) $this->input('telefone', '')),
        ]);
    }
}
