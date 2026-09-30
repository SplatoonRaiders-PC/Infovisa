<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Agendamento de tarefas automáticas
 */
Schedule::command('processos:licenciamento-anual')
    ->yearlyOn(1, 1, '00:01') // 1º de janeiro às 00:01
    ->timezone('America/Sao_Paulo')
    ->onSuccess(function () {
        \Log::info('Processos de licenciamento anual criados com sucesso');
    })
    ->onFailure(function () {
        \Log::error('Falha ao criar processos de licenciamento anual');
    });

// Verificação diária de buscas salvas no Diário Oficial
Schedule::command('diario:verificar-novos')
    ->dailyAt('06:00') // Executa todo dia às 06:00
    ->timezone('America/Sao_Paulo');

// Verificação diária dos CNAEs do CNPJ na Receita (alerta de atividades alteradas no dashboard)
// Em lotes, começando pelos nunca verificados; percorre toda a base ao longo dos dias.
// Usa as fontes mais atualizadas (~6 consultas/min): 300 estabelecimentos levam ~50 min.
Schedule::command('estabelecimentos:verificar-cnaes --limite=300')
    ->dailyAt('01:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping(120);

// Verificação de prazos de documentos de notificação (§1º - 5 dias úteis)
// Executa a cada hora para garantir que os prazos sejam iniciados no momento correto
Schedule::command('documentos:verificar-prazos')
    ->hourly()
    ->timezone('America/Sao_Paulo')
    ->onSuccess(function () {
        \Log::info('Verificação de prazos de documentos concluída');
    });
