<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Auth\RegistroController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\CadastroUsuarioInternoController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\EstabelecimentoController;

/*
|--------------------------------------------------------------------------
| Rotas Públicas
|--------------------------------------------------------------------------
*/

// Página Inicial
Route::get('/', [HomeController::class, 'index'])->name('home');

// Consultar Processo
Route::post('/consultar-processo', [HomeController::class, 'consultarProcesso'])->name('consultar.processo');

// Verificar Documento
Route::post('/verificar-documento', [HomeController::class, 'verificarDocumento'])->name('verificar.documento');

// Fila de Processos Pública
Route::get('/fila-processos', [HomeController::class, 'filaProcessos'])->name('fila.processos');

// Verificar Autenticidade de Documento
Route::get('/verificar-autenticidade', [\App\Http\Controllers\AutenticidadeController::class, 'index'])->name('verificar.autenticidade.form');
Route::post('/verificar-autenticidade', [\App\Http\Controllers\AutenticidadeController::class, 'verificar'])->name('verificar.autenticidade.verificar');
Route::get('/verificar-autenticidade/{codigo}', [\App\Http\Controllers\AutenticidadeController::class, 'verificar'])->name('verificar.autenticidade');
Route::get('/documento-autenticado/{codigo}/pdf', [\App\Http\Controllers\AutenticidadeController::class, 'visualizarPdf'])->name('documento.autenticado.pdf');

// Validar Arquivo Aprovado (QR Code do carimbo de validação)
Route::get('/validar-arquivo/{codigo}', [\App\Http\Controllers\AutenticidadeController::class, 'validarArquivo'])->name('validar.arquivo');
Route::get('/validar-arquivo/{codigo}/pdf', [\App\Http\Controllers\AutenticidadeController::class, 'visualizarArquivoValidado'])->name('validar.arquivo.pdf');

// Pesquisa de Satisfação - Acesso público via link/slug
Route::get('/pesquisa/{slug}', [\App\Http\Controllers\PesquisaPublicaController::class, 'show'])->name('pesquisa.show');
Route::post('/pesquisa/{slug}', [\App\Http\Controllers\PesquisaPublicaController::class, 'responder'])->name('pesquisa.responder');
Route::get('/pesquisa/{slug}/obrigado', [\App\Http\Controllers\PesquisaPublicaController::class, 'obrigado'])->name('pesquisa.obrigado');

// Pesquisa de Satisfação Interna - Resposta via AJAX (autenticado)
Route::post('/pesquisa-interna/responder', [\App\Http\Controllers\PesquisaPublicaController::class, 'responderInterno'])
    ->name('pesquisa.responder.interno')
    ->middleware('auth:interno');

// Treinamentos - acesso público
Route::get('/treinamentos/inscricao/{token}', [\App\Http\Controllers\TreinamentoPublicoController::class, 'inscricao'])->name('treinamentos.public.inscricao');
Route::post('/treinamentos/inscricao/{token}', [\App\Http\Controllers\TreinamentoPublicoController::class, 'salvarInscricao'])->name('treinamentos.public.inscricao.salvar');
Route::get('/treinamentos/pergunta/{token}', [\App\Http\Controllers\TreinamentoPublicoController::class, 'pergunta'])->name('treinamentos.public.pergunta');
Route::post('/treinamentos/pergunta/{token}', [\App\Http\Controllers\TreinamentoPublicoController::class, 'responderPergunta'])->name('treinamentos.public.pergunta.responder');
Route::get('/treinamentos/pergunta/{token}/obrigado', [\App\Http\Controllers\TreinamentoPublicoController::class, 'obrigado'])->name('treinamentos.public.pergunta.obrigado');
Route::get('/ci/{token}', [CadastroUsuarioInternoController::class, 'show'])->name('cadastro-interno.show');
Route::post('/ci/{token}', [CadastroUsuarioInternoController::class, 'store'])->name('cadastro-interno.store');

/*
|--------------------------------------------------------------------------
| Rotas de Autenticação - Login Unificado
|--------------------------------------------------------------------------
*/

// Registro (somente usuários externos)
Route::middleware('guest:externo,interno')->group(function () {
    Route::get('/registro', [RegistroController::class, 'showRegistroForm'])->name('registro');
    Route::post('/registro', [RegistroController::class, 'registro'])->middleware('throttle:10,1')->name('registro.submit');
    
    // Login Unificado (detecta automaticamente o tipo de usuário)
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.submit');
});

// Logout (funciona para ambos os guards)
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Recuperação de Senha (usuário externo)
Route::get('/recuperar-senha', [\App\Http\Controllers\Auth\RecuperarSenhaController::class, 'showForm'])->name('recuperar-senha.form');
Route::post('/recuperar-senha', [\App\Http\Controllers\Auth\RecuperarSenhaController::class, 'enviarLink'])->name('recuperar-senha.enviar');
Route::get('/recuperar-senha/redefinir', [\App\Http\Controllers\Auth\RecuperarSenhaController::class, 'showRedefinir'])->name('recuperar-senha.redefinir.form');
Route::post('/recuperar-senha/redefinir', [\App\Http\Controllers\Auth\RecuperarSenhaController::class, 'redefinir'])->name('recuperar-senha.redefinir');

/*
|--------------------------------------------------------------------------
| Rotas Protegidas - Área da Empresa
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:externo', 'no-cache-auth'])->prefix('company')->name('company.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [\App\Http\Controllers\Company\DashboardController::class, 'index'])->name('dashboard');
    
    // Meu Perfil
    Route::get('/perfil', [\App\Http\Controllers\Company\PerfilController::class, 'index'])->name('perfil.index');
    Route::put('/perfil/dados', [\App\Http\Controllers\Company\PerfilController::class, 'updateDados'])->name('perfil.update-dados');
    Route::put('/perfil/senha', [\App\Http\Controllers\Company\PerfilController::class, 'updateSenha'])->name('perfil.update-senha');
    
    // Estabelecimentos
    Route::get('/estabelecimentos', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'index'])->name('estabelecimentos.index');
    Route::get('/estabelecimentos/create', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'create'])->name('estabelecimentos.create');
    Route::get('/estabelecimentos/create/juridica', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'createJuridica'])->name('estabelecimentos.create.juridica');
    Route::get('/estabelecimentos/create/fisica', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'createFisica'])->name('estabelecimentos.create.fisica');
    Route::get('/estabelecimentos/create/unidade-movel', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'createUnidadeMovel'])->name('estabelecimentos.create.unidade-movel');
    Route::post('/estabelecimentos', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'store'])->name('estabelecimentos.store');
    Route::post('/estabelecimentos/buscar-questionarios', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'buscarQuestionarios'])->name('estabelecimentos.buscar-questionarios');
    Route::get('/estabelecimentos/buscar-cnaes', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'buscarCnaes'])->name('estabelecimentos.buscar-cnaes');
    Route::get('/estabelecimentos/verificar-nome-fantasia', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'verificarNomeFantasia'])->name('estabelecimentos.verificar-nome-fantasia');
    // PJ Unidade Móvel: competência + usa_infovisa por município em tempo real (P4)
    Route::post('/estabelecimentos/verificar-competencia-municipio', [\App\Http\Controllers\Api\CnpjController::class, 'verificarCompetenciaMunicipio'])->name('estabelecimentos.verificar-competencia-municipio');
    
    // API de notificações para o app Android
    Route::get('/api/notificacoes', [\App\Http\Controllers\Api\NotificacaoAppController::class, 'index'])->name('api.notificacoes');
    Route::get('/estabelecimentos/{id}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'show'])->name('estabelecimentos.show');
    Route::get('/estabelecimentos/{id}/edit', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'edit'])->name('estabelecimentos.edit');
    Route::put('/estabelecimentos/{id}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'update'])->name('estabelecimentos.update');
    
    // Estabelecimentos - Atividades
    Route::get('/estabelecimentos/{id}/atividades', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'editAtividades'])->name('estabelecimentos.atividades.edit');
    Route::put('/estabelecimentos/{id}/atividades', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'updateAtividades'])->name('estabelecimentos.atividades.update');
    
    // Estabelecimentos - Responsáveis
    Route::get('/estabelecimentos/{id}/responsaveis', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisIndex'])->name('estabelecimentos.responsaveis.index');
    Route::get('/estabelecimentos/{id}/responsaveis/create/{tipo?}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisCreate'])->name('estabelecimentos.responsaveis.create');
    Route::get('/estabelecimentos/{id}/responsaveis/{responsavelId}/edit/{tipo?}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisEdit'])->name('estabelecimentos.responsaveis.edit');
    Route::post('/estabelecimentos/{id}/responsaveis', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisStore'])->name('estabelecimentos.responsaveis.store');
    Route::put('/estabelecimentos/{id}/responsaveis/{responsavelId}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisUpdate'])->name('estabelecimentos.responsaveis.update');
    Route::delete('/estabelecimentos/{id}/responsaveis/{responsavelId}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'responsaveisDestroy'])->name('estabelecimentos.responsaveis.destroy');
    
    // Estabelecimentos - Usuários Vinculados
    Route::get('/estabelecimentos/{id}/usuarios', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'usuariosIndex'])->name('estabelecimentos.usuarios.index');
    Route::post('/estabelecimentos/{id}/usuarios', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'usuariosStore'])->name('estabelecimentos.usuarios.store');
    Route::put('/estabelecimentos/{id}/usuarios/{usuarioId}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'usuariosUpdate'])->name('estabelecimentos.usuarios.update');
    Route::delete('/estabelecimentos/{id}/usuarios/{usuarioId}', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'usuariosDestroy'])->name('estabelecimentos.usuarios.destroy');
    
    // Estabelecimentos - Equipamentos de Radiação Ionizante
    Route::get('/estabelecimentos/{id}/equipamentos-radiacao', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'index'])->name('estabelecimentos.equipamentos-radiacao.index');
    Route::post('/estabelecimentos/{id}/equipamentos-radiacao', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'store'])->name('estabelecimentos.equipamentos-radiacao.store');
    // Rotas específicas ANTES das rotas com parâmetros dinâmicos
    Route::post('/estabelecimentos/{id}/equipamentos-radiacao/declarar-sem-equipamentos', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'declararSemEquipamentos'])->name('estabelecimentos.equipamentos-radiacao.declarar-sem-equipamentos');
    Route::delete('/estabelecimentos/{id}/equipamentos-radiacao/revogar-declaracao', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'revogarDeclaracao'])->name('estabelecimentos.equipamentos-radiacao.revogar-declaracao');
    // Rotas com parâmetros dinâmicos
    Route::put('/estabelecimentos/{id}/equipamentos-radiacao/{equipamentoId}', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'update'])->name('estabelecimentos.equipamentos-radiacao.update');
    Route::patch('/estabelecimentos/{id}/equipamentos-radiacao/{equipamentoId}/status', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'updateStatus'])->name('estabelecimentos.equipamentos-radiacao.update-status');
    Route::delete('/estabelecimentos/{id}/equipamentos-radiacao/{equipamentoId}', [\App\Http\Controllers\Company\EquipamentoRadiacaoController::class, 'destroy'])->name('estabelecimentos.equipamentos-radiacao.destroy');
    
    // Estabelecimentos - Processos
    Route::get('/estabelecimentos/{id}/processos', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'processosIndex'])->name('estabelecimentos.processos.index');
    Route::get('/estabelecimentos/{id}/processos/create', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'processosCreate'])->name('estabelecimentos.processos.create');
    Route::post('/estabelecimentos/{id}/processos', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'processosStore'])->name('estabelecimentos.processos.store');

    // Unidade Móvel - Municípios de Atuação
    Route::get('/estabelecimentos/{id}/municipios-atuacao', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'municipiosAtuacaoIndex'])->name('estabelecimentos.municipios-atuacao');
    Route::post('/estabelecimentos/{id}/adicionar-municipio-atuacao', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'adicionarMunicipioAtuacao'])->name('estabelecimentos.adicionar-municipio-atuacao');

    // Unidade Móvel - Solicitação de credenciamento para estabelecimento já existente
    Route::get('/estabelecimentos/{id}/solicitar-unidade-movel', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'solicitarUnidadeMovelForm'])->name('estabelecimentos.solicitar-unidade-movel');
    Route::post('/estabelecimentos/{id}/solicitar-unidade-movel', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'solicitarUnidadeMovelStore'])->name('estabelecimentos.solicitar-unidade-movel.store');
    
    // Processos
    Route::get('/processos', [\App\Http\Controllers\Company\ProcessoController::class, 'index'])->name('processos.index');
    Route::get('/processos/{id}', [\App\Http\Controllers\Company\ProcessoController::class, 'show'])->name('processos.show');
    Route::post('/processos/{id}/upload', [\App\Http\Controllers\Company\ProcessoController::class, 'uploadDocumento'])->name('processos.upload');
    Route::post('/processos/{id}/adicionar-unidade', [\App\Http\Controllers\Company\ProcessoController::class, 'adicionarUnidade'])->name('processos.adicionar-unidade');
    Route::get('/processos/{id}/documentos/{documento}/download', [\App\Http\Controllers\Company\ProcessoController::class, 'downloadDocumento'])->name('processos.download');
    Route::get('/processos/{id}/documentos/{documento}/visualizar', [\App\Http\Controllers\Company\ProcessoController::class, 'visualizarDocumento'])->name('processos.documento.visualizar');
    Route::delete('/processos/{id}/documentos/{documento}', [\App\Http\Controllers\Company\ProcessoController::class, 'deleteDocumento'])->name('processos.documento.delete');
    Route::post('/processos/{id}/documentos/{documento}/reenviar', [\App\Http\Controllers\Company\ProcessoController::class, 'reenviarDocumento'])->name('processos.reenviar');
    
    // Documentos digitais da vigilância (notificações, etc)
    Route::get('/processos/{id}/documentos-vigilancia/{documento}/visualizar', [\App\Http\Controllers\Company\ProcessoController::class, 'visualizarDocumentoDigital'])->name('processos.documento-digital.visualizar');
    Route::get('/processos/{id}/documentos-vigilancia/{documento}/download', [\App\Http\Controllers\Company\ProcessoController::class, 'downloadDocumentoDigital'])->name('processos.documento-digital.download');
    
    // Respostas a documentos digitais (notificações, etc)
    Route::post('/processos/{id}/documentos-vigilancia/{documento}/resposta', [\App\Http\Controllers\Company\ProcessoController::class, 'enviarRespostaDocumento'])->name('processos.documento-digital.resposta');
    Route::get('/processos/{id}/documentos-vigilancia/{documento}/respostas/{resposta}/download', [\App\Http\Controllers\Company\ProcessoController::class, 'downloadRespostaDocumento'])->name('processos.documento-digital.resposta.download');
    Route::get('/processos/{id}/documentos-vigilancia/{documento}/respostas/{resposta}/visualizar', [\App\Http\Controllers\Company\ProcessoController::class, 'visualizarRespostaDocumento'])->name('processos.documento-digital.resposta.visualizar');
    Route::delete('/processos/{id}/documentos-vigilancia/{documento}/respostas/{resposta}', [\App\Http\Controllers\Company\ProcessoController::class, 'excluirRespostaDocumento'])->name('processos.documento-digital.resposta.excluir');
    
    // Protocolo de Abertura do Processo (PDF)
    Route::get('/processos/{id}/protocolo', [\App\Http\Controllers\Company\ProcessoController::class, 'protocoloAbertura'])->name('processos.protocolo');
    
    // Documento de Ajuda (PDF)
    Route::get('/processos/{id}/documento-ajuda/{documento}', [\App\Http\Controllers\Company\ProcessoController::class, 'visualizarDocumentoAjuda'])->name('processos.documento-ajuda');
    
    // Documentos de Ajuda (listagem global e visualização)
    Route::get('/documentos-ajuda/{documento}/visualizar', [\App\Http\Controllers\Company\DashboardController::class, 'visualizarDocumentoAjuda'])->name('documentos-ajuda.visualizar');
    
    // Alertas do processo
    Route::get('/alertas', [\App\Http\Controllers\Company\ProcessoController::class, 'alertasIndex'])->name('alertas.index');
    Route::post('/processos/{id}/alertas/{alerta}/concluir', [\App\Http\Controllers\Company\ProcessoController::class, 'concluirAlerta'])->name('processos.alertas.concluir');
    
    // Busca de usuários externos para vincular
    Route::get('/usuarios-externos/buscar', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'buscarUsuariosExternos'])->name('usuarios-externos.buscar');
    
    // Busca de responsável por CPF
    Route::post('/responsaveis/buscar-cpf', [\App\Http\Controllers\Company\EstabelecimentoController::class, 'buscarResponsavelPorCpf'])->name('responsaveis.buscar-cpf');

    // Assistente IA (usuário externo)
    Route::post('/ia/chat', [\App\Http\Controllers\AssistenteIAController::class, 'chatExterno'])->name('ia.chat');
});

/*
|--------------------------------------------------------------------------
| Rotas Protegidas - Área Administrativa
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:interno', 'no-cache-auth'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/tarefas', [DashboardController::class, 'tarefasPaginadas'])->name('dashboard.tarefas');
    Route::get('/dashboard/processos-atribuidos', [DashboardController::class, 'processosAtribuidosPaginados'])->name('dashboard.processos-atribuidos');
    Route::get('/dashboard/processos-sob-minha-responsabilidade', [DashboardController::class, 'processosSobMinhaResponsabilidade'])->name('dashboard.processos-responsabilidade');
    Route::get('/dashboard/ordens-servico-vencidas', [DashboardController::class, 'ordensServicoVencidas'])->name('dashboard.ordens-servico-vencidas');
    Route::get('/dashboard/atividades-alteradas', [DashboardController::class, 'atividadesAlteradas'])->name('dashboard.atividades-alteradas');
    Route::get('/dashboard/respostas-atrasadas-analise', [DashboardController::class, 'respostasAtrasadasParaAnalise'])->name('dashboard.respostas-atrasadas-analise');
    Route::get('/dashboard/todas-tarefas', [DashboardController::class, 'todasTarefas'])->name('dashboard.todas-tarefas');
    Route::get('/dashboard/todas-tarefas-paginadas', [DashboardController::class, 'todasTarefasPaginadas'])->name('dashboard.todas-tarefas-paginadas');
    Route::get('/minhas-pendencias', [DashboardController::class, 'minhasPendencias'])->name('minhas-pendencias');

    // Treinamentos
    Route::get('/treinamentos', [\App\Http\Controllers\Admin\TreinamentoController::class, 'index'])->name('treinamentos.index');
    Route::get('/treinamentos/create', [\App\Http\Controllers\Admin\TreinamentoController::class, 'create'])->name('treinamentos.create');
    Route::post('/treinamentos', [\App\Http\Controllers\Admin\TreinamentoController::class, 'store'])->name('treinamentos.store');
    Route::get('/treinamentos/{evento}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'show'])->name('treinamentos.show');
    Route::get('/treinamentos/{evento}/edit', [\App\Http\Controllers\Admin\TreinamentoController::class, 'edit'])->name('treinamentos.edit');
    Route::put('/treinamentos/{evento}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'update'])->name('treinamentos.update');
    Route::delete('/treinamentos/{evento}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'destroy'])->name('treinamentos.destroy');
    Route::post('/treinamentos/{evento}/apresentacoes', [\App\Http\Controllers\Admin\TreinamentoController::class, 'storeApresentacao'])->name('treinamentos.apresentacoes.store');
    Route::get('/treinamentos/apresentacoes/{apresentacao}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'showApresentacao'])->name('treinamentos.apresentacoes.show');
    Route::put('/treinamentos/apresentacoes/{apresentacao}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'updateApresentacao'])->name('treinamentos.apresentacoes.update');
    Route::delete('/treinamentos/apresentacoes/{apresentacao}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'destroyApresentacao'])->name('treinamentos.apresentacoes.destroy');
    Route::get('/treinamentos/apresentacoes/{apresentacao}/slides/create', [\App\Http\Controllers\Admin\TreinamentoController::class, 'createSlide'])->name('treinamentos.slides.create');
    Route::post('/treinamentos/apresentacoes/{apresentacao}/slides', [\App\Http\Controllers\Admin\TreinamentoController::class, 'storeSlide'])->name('treinamentos.slides.store');
    Route::get('/treinamentos/slides/{slide}/edit', [\App\Http\Controllers\Admin\TreinamentoController::class, 'editSlide'])->name('treinamentos.slides.edit');
    Route::put('/treinamentos/slides/{slide}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'updateSlide'])->name('treinamentos.slides.update');
    Route::delete('/treinamentos/slides/{slide}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'destroySlide'])->name('treinamentos.slides.destroy');
    Route::get('/treinamentos/slides/{slide}/perguntas/create', [\App\Http\Controllers\Admin\TreinamentoController::class, 'createPergunta'])->name('treinamentos.perguntas.create');
    Route::post('/treinamentos/slides/{slide}/perguntas', [\App\Http\Controllers\Admin\TreinamentoController::class, 'storePergunta'])->name('treinamentos.perguntas.store');
    Route::get('/treinamentos/perguntas/{pergunta}/edit', [\App\Http\Controllers\Admin\TreinamentoController::class, 'editPergunta'])->name('treinamentos.perguntas.edit');
    Route::put('/treinamentos/perguntas/{pergunta}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'updatePergunta'])->name('treinamentos.perguntas.update');
    Route::delete('/treinamentos/perguntas/{pergunta}', [\App\Http\Controllers\Admin\TreinamentoController::class, 'destroyPergunta'])->name('treinamentos.perguntas.destroy');
    Route::get('/treinamentos/apresentacoes/{apresentacao}/apresentar', [\App\Http\Controllers\Admin\TreinamentoController::class, 'apresentar'])->name('treinamentos.apresentacoes.apresentar');
    Route::post('/treinamentos/upload-imagem', [\App\Http\Controllers\Admin\TreinamentoController::class, 'uploadImagem'])->name('treinamentos.upload-imagem');
    Route::post('/treinamentos/apresentacoes/{apresentacao}/importar-pptx', [\App\Http\Controllers\Admin\TreinamentoController::class, 'importarPowerPoint'])->name('treinamentos.apresentacoes.importar-pptx');
    Route::get('/treinamentos/perguntas/{pergunta}/resultados', [\App\Http\Controllers\Admin\TreinamentoController::class, 'resultadosPergunta'])->name('treinamentos.perguntas.resultados');
    Route::get('/treinamentos/{evento}/relatorios/inscritos', [\App\Http\Controllers\Admin\TreinamentoController::class, 'relatorioInscritos'])->name('treinamentos.relatorios.inscritos');
    Route::get('/treinamentos/{evento}/relatorios/respostas', [\App\Http\Controllers\Admin\TreinamentoController::class, 'relatorioRespostas'])->name('treinamentos.relatorios.respostas');

    // Meu Perfil
    Route::get('/perfil', [\App\Http\Controllers\PerfilController::class, 'index'])->name('perfil.index');
    Route::put('/perfil/dados', [\App\Http\Controllers\PerfilController::class, 'updateDados'])->name('perfil.update-dados');
    Route::put('/perfil/senha', [\App\Http\Controllers\PerfilController::class, 'updateSenha'])->name('perfil.update-senha');
    Route::match(['get', 'post'], '/perfil/atualizar-nascimento', [\App\Http\Controllers\PerfilController::class, 'atualizarNascimento'])->name('perfil.atualizar-nascimento');

    // Chat Interno
    Route::prefix('chat')->name('chat.')->group(function () {
        Route::get('/usuarios', [\App\Http\Controllers\ChatInternoController::class, 'usuarios'])->name('usuarios');
        Route::get('/usuarios/buscar', [\App\Http\Controllers\ChatInternoController::class, 'buscarUsuarios'])->name('usuarios.buscar');
        Route::get('/conversas', [\App\Http\Controllers\ChatInternoController::class, 'conversas'])->name('conversas');
        Route::get('/mensagens/{usuarioId}', [\App\Http\Controllers\ChatInternoController::class, 'mensagens'])->name('mensagens');
        Route::post('/enviar', [\App\Http\Controllers\ChatInternoController::class, 'enviar'])->name('enviar');
        Route::post('/heartbeat', [\App\Http\Controllers\ChatInternoController::class, 'heartbeat'])->name('heartbeat');
        Route::get('/verificar-novas', [\App\Http\Controllers\ChatInternoController::class, 'verificarNovas'])->name('verificar-novas');
        Route::delete('/mensagem/{mensagemId}', [\App\Http\Controllers\ChatInternoController::class, 'apagarMensagem'])->name('mensagem.apagar');
        Route::get('/suporte/mensagens', [\App\Http\Controllers\ChatInternoController::class, 'suporteMensagens'])->name('suporte.mensagens');
        Route::get('/suporte/nao-lidos', [\App\Http\Controllers\ChatInternoController::class, 'suporteNaoLidos'])->name('suporte.nao-lidos');
        Route::get('/assistente', [\App\Http\Controllers\ChatInternoController::class, 'assistente'])->name('assistente');
    });

    // Atalhos Rápidos
    Route::get('/atalhos-rapidos', [\App\Http\Controllers\Admin\AtalhoRapidoController::class, 'index'])->name('atalhos-rapidos.index');
    Route::post('/atalhos-rapidos', [\App\Http\Controllers\Admin\AtalhoRapidoController::class, 'store'])->name('atalhos-rapidos.store');
    Route::put('/atalhos-rapidos/{atalho}', [\App\Http\Controllers\Admin\AtalhoRapidoController::class, 'update'])->name('atalhos-rapidos.update');
    Route::delete('/atalhos-rapidos/{atalho}', [\App\Http\Controllers\Admin\AtalhoRapidoController::class, 'destroy'])->name('atalhos-rapidos.destroy');
    Route::post('/atalhos-rapidos/reorder', [\App\Http\Controllers\Admin\AtalhoRapidoController::class, 'reorder'])->name('atalhos-rapidos.reorder');

    // Estabelecimentos
    Route::post('/estabelecimentos/verificar-duplicidade-publico', [EstabelecimentoController::class, 'verificarDuplicidadePublico'])->name('estabelecimentos.verificar-duplicidade-publico');
    Route::get('/estabelecimentos/pendentes', [EstabelecimentoController::class, 'pendentes'])->name('estabelecimentos.pendentes');
    Route::get('/estabelecimentos/rejeitados', [EstabelecimentoController::class, 'rejeitados'])->name('estabelecimentos.rejeitados');
    Route::get('/estabelecimentos/desativados', [EstabelecimentoController::class, 'desativados'])->name('estabelecimentos.desativados');
    Route::get('/estabelecimentos/create/juridica', [EstabelecimentoController::class, 'createJuridica'])->name('estabelecimentos.create.juridica');
    Route::get('/estabelecimentos/create/fisica', [EstabelecimentoController::class, 'createFisica'])->name('estabelecimentos.create.fisica');
    Route::get('/estabelecimentos/buscar-cnaes', [EstabelecimentoController::class, 'buscarCnaesPactuacao'])->name('estabelecimentos.buscar-cnaes');
    Route::post('/estabelecimentos/buscar-questionarios', [\App\Http\Controllers\Admin\PactuacaoController::class, 'buscarQuestionarios'])->name('estabelecimentos.buscar-questionarios');
    Route::get('/estabelecimentos/buscar-por-cpf/{cpf}', [EstabelecimentoController::class, 'buscarPorCpf'])->name('estabelecimentos.buscar-cpf');
    Route::get('/estabelecimentos/{id}/atividades', [EstabelecimentoController::class, 'editAtividades'])->name('estabelecimentos.atividades.edit');
    Route::post('/estabelecimentos/{id}/atividades', [EstabelecimentoController::class, 'updateAtividades'])->name('estabelecimentos.atividades.update');
    Route::get('/estabelecimentos/{id}/documentos-manuais', [EstabelecimentoController::class, 'editDocumentosManuais'])->name('estabelecimentos.documentos-manuais.edit');
    Route::post('/estabelecimentos/{id}/documentos-manuais', [EstabelecimentoController::class, 'updateDocumentosManuais'])->name('estabelecimentos.documentos-manuais.update');
    Route::post('/estabelecimentos/{id}/atualizar-api', [EstabelecimentoController::class, 'atualizarPelaApi'])->name('estabelecimentos.atualizar-api');
    Route::post('/estabelecimentos/{id}/alterar-competencia', [EstabelecimentoController::class, 'alterarCompetencia'])->name('estabelecimentos.alterar-competencia');
    Route::get('/estabelecimentos/{id}/historico', [EstabelecimentoController::class, 'historico'])->name('estabelecimentos.historico');
    Route::get('/estabelecimentos/{id}/documentos', [EstabelecimentoController::class, 'documentos'])->name('estabelecimentos.documentos');
    Route::post('/estabelecimentos/{id}/aprovar', [EstabelecimentoController::class, 'aprovar'])->name('estabelecimentos.aprovar');
    Route::post('/estabelecimentos/{id}/rejeitar', [EstabelecimentoController::class, 'rejeitar'])->name('estabelecimentos.rejeitar');
    Route::post('/estabelecimentos/{id}/reiniciar', [EstabelecimentoController::class, 'reiniciar'])->name('estabelecimentos.reiniciar');
    Route::post('/estabelecimentos/{id}/voltar-pendente', [EstabelecimentoController::class, 'voltarPendente'])->name('estabelecimentos.voltar-pendente');
    Route::post('/estabelecimentos/{id}/desativar', [EstabelecimentoController::class, 'desativar'])->name('estabelecimentos.desativar');
    Route::post('/estabelecimentos/{id}/ativar', [EstabelecimentoController::class, 'ativar'])->name('estabelecimentos.ativar');
    
    // Usuários Vinculados ao Estabelecimento
    Route::get('/estabelecimentos/{id}/usuarios', [EstabelecimentoController::class, 'usuariosIndex'])->name('estabelecimentos.usuarios.index');
    Route::post('/estabelecimentos/{id}/usuarios/vincular', [EstabelecimentoController::class, 'vincularUsuario'])->name('estabelecimentos.usuarios.vincular');
    Route::delete('/estabelecimentos/{id}/usuarios/{usuario_id}', [EstabelecimentoController::class, 'desvincularUsuario'])->name('estabelecimentos.usuarios.desvincular');
    Route::put('/estabelecimentos/{id}/usuarios/{usuario_id}', [EstabelecimentoController::class, 'atualizarVinculo'])->name('estabelecimentos.usuarios.atualizar');
    Route::delete('/estabelecimentos/{id}/remover-criador', [EstabelecimentoController::class, 'removerCriador'])->name('estabelecimentos.remover-criador');
    Route::get('/usuarios-externos/buscar', [EstabelecimentoController::class, 'buscarUsuarios'])->name('usuarios-externos.buscar');

    // Municípios de Atuação - Unidade Móvel
    Route::get('/estabelecimentos/{id}/municipios-atuacao', [EstabelecimentoController::class, 'municipiosAtuacao'])->name('estabelecimentos.municipios-atuacao');

    // Módulo Unidade Móvel - aprovação/rejeição da solicitação de estabelecimento já existente
    Route::post('/estabelecimentos/{id}/unidade-movel/aprovar', [EstabelecimentoController::class, 'aprovarUnidadeMovel'])->name('estabelecimentos.unidade-movel.aprovar');
    Route::post('/estabelecimentos/{id}/unidade-movel/rejeitar', [EstabelecimentoController::class, 'rejeitarUnidadeMovel'])->name('estabelecimentos.unidade-movel.rejeitar');
    
    // Equipamentos de Radiação do Estabelecimento
    Route::get('/estabelecimentos/{id}/equipamentos-radiacao', [EstabelecimentoController::class, 'equipamentosRadiacaoIndex'])->name('estabelecimentos.equipamentos-radiacao.index');
    
    // Responsáveis
    Route::get('/estabelecimentos/{id}/responsaveis', [\App\Http\Controllers\ResponsavelController::class, 'index'])->name('estabelecimentos.responsaveis.index');
    Route::get('/estabelecimentos/{id}/responsaveis/create/{tipo}', [\App\Http\Controllers\ResponsavelController::class, 'create'])->name('estabelecimentos.responsaveis.create');
    Route::post('/estabelecimentos/{id}/responsaveis', [\App\Http\Controllers\ResponsavelController::class, 'store'])->name('estabelecimentos.responsaveis.store');
    Route::delete('/estabelecimentos/{estabelecimento}/responsaveis/{responsavel}', [\App\Http\Controllers\ResponsavelController::class, 'destroy'])->name('estabelecimentos.responsaveis.destroy');
    Route::post('/responsaveis/buscar-cpf', [\App\Http\Controllers\ResponsavelController::class, 'buscarPorCpf'])->name('responsaveis.buscar-cpf');
    Route::get('/responsaveis/{responsavel}/documento-identificacao', [\App\Http\Controllers\ResponsavelController::class, 'visualizarDocumentoIdentificacao'])->name('responsaveis.documento-identificacao');
    Route::get('/responsaveis/{responsavel}/carteirinha-conselho', [\App\Http\Controllers\ResponsavelController::class, 'visualizarCarteirinhaConselho'])->name('responsaveis.carteirinha-conselho');
    
    // Rotas de Responsáveis Globais
    Route::get('/responsaveis', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'index'])->name('responsaveis.index');
    Route::get('/responsaveis/{id}', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'show'])->name('responsaveis.show');
    Route::get('/responsaveis/{id}/edit', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'edit'])->name('responsaveis.edit');
    Route::put('/responsaveis/{id}', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'update'])->name('responsaveis.update');
    Route::delete('/responsaveis/{id}/documento', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'removerDocumento'])->name('responsaveis.remover-documento');
    Route::delete('/responsaveis/{id}/carteirinha', [\App\Http\Controllers\Admin\ResponsavelGlobalController::class, 'removerCarteirinha'])->name('responsaveis.remover-carteirinha');
    
    // Documentos Digitais
    Route::get('/documentos', [\App\Http\Controllers\DocumentoDigitalController::class, 'index'])->name('documentos.index');
    Route::get('/documentos/create', [\App\Http\Controllers\DocumentoDigitalController::class, 'create'])->name('documentos.create');
    Route::get('/documentos/create-fisico', [\App\Http\Controllers\DocumentoDigitalController::class, 'createFisico'])->name('documentos.create-fisico');
    Route::post('/documentos/fisico', [\App\Http\Controllers\DocumentoDigitalController::class, 'storeFisico'])->name('documentos.store-fisico');
    Route::post('/documentos/upload-imagem', [\App\Http\Controllers\DocumentoDigitalController::class, 'uploadImagem'])->name('documentos.upload-imagem');
    Route::post('/documentos', [\App\Http\Controllers\DocumentoDigitalController::class, 'store'])->name('documentos.store');
    Route::get('/documentos/{id}', [\App\Http\Controllers\DocumentoDigitalController::class, 'show'])->where('id', '[0-9]+')->name('documentos.show');
    Route::get('/documentos/{id}/edit', [\App\Http\Controllers\DocumentoDigitalController::class, 'edit'])->where('id', '[0-9]+')->name('documentos.edit');
    Route::put('/documentos/{id}', [\App\Http\Controllers\DocumentoDigitalController::class, 'update'])->where('id', '[0-9]+')->name('documentos.update');
    Route::delete('/documentos/{id}', [\App\Http\Controllers\DocumentoDigitalController::class, 'destroy'])->where('id', '[0-9]+')->name('documentos.destroy');
    Route::post('/documentos/{id}/excluir', [\App\Http\Controllers\DocumentoDigitalController::class, 'destroy'])->where('id', '[0-9]+')->name('documentos.excluir');
    Route::post('/documentos/{id}/mover-pasta', [\App\Http\Controllers\DocumentoDigitalController::class, 'moverPasta'])->name('documentos.mover-pasta');
    Route::post('/documentos/{id}/renomear', [\App\Http\Controllers\DocumentoDigitalController::class, 'renomear'])->name('documentos.renomear');
    Route::get('/documentos/modelos/{tipoId}', [\App\Http\Controllers\DocumentoDigitalController::class, 'buscarModelos'])->name('documentos.modelos');
    Route::get('/documentos/prazo-tipo/{tipoId}', [\App\Http\Controllers\DocumentoDigitalController::class, 'buscarPrazoTipo'])->name('documentos.prazo-tipo');
    
    // Assinatura Digital
    Route::get('/assinatura/configurar-senha', [\App\Http\Controllers\AssinaturaDigitalController::class, 'configurarSenha'])->name('assinatura.configurar-senha');
    Route::post('/assinatura/salvar-senha', [\App\Http\Controllers\AssinaturaDigitalController::class, 'salvarSenha'])->name('assinatura.salvar-senha');
    Route::get('/assinatura/pendentes', [\App\Http\Controllers\AssinaturaDigitalController::class, 'documentosPendentes'])->name('assinatura.pendentes');
    Route::get('/assinatura/assinar/{documentoId}', [\App\Http\Controllers\AssinaturaDigitalController::class, 'assinar'])->name('assinatura.assinar');
    Route::get('/assinatura/visualizar-pdf/{documentoId}', [\App\Http\Controllers\AssinaturaDigitalController::class, 'visualizarPdf'])->name('assinatura.visualizar-pdf');
    Route::post('/assinatura/processar/{documentoId}', [\App\Http\Controllers\AssinaturaDigitalController::class, 'processar'])->name('assinatura.processar');
    Route::post('/assinatura/processar-lote', [\App\Http\Controllers\AssinaturaDigitalController::class, 'processarLote'])->name('assinatura.processar-lote');
    Route::get('/documentos/{id}/pdf', [\App\Http\Controllers\DocumentoDigitalController::class, 'gerarPdf'])->name('documentos.pdf');
    Route::get('/documentos/{id}/visualizar-pdf', [\App\Http\Controllers\DocumentoDigitalController::class, 'visualizarPdf'])->name('documentos.visualizar-pdf');
    Route::post('/documentos/{id}/assinar', [\App\Http\Controllers\DocumentoDigitalController::class, 'assinar'])->name('documentos.assinar');
    Route::post('/documentos/{id}/versoes/{versao}/restaurar', [\App\Http\Controllers\DocumentoDigitalController::class, 'restaurarVersao'])->name('documentos.restaurarVersao');
    Route::get('/documentos/{id}/versoes/{versao}/conteudo', [\App\Http\Controllers\DocumentoDigitalController::class, 'obterVersaoConteudo'])->name('documentos.versoes.conteudo');
    Route::post('/documentos/{id}/gerenciar-assinantes', [\App\Http\Controllers\DocumentoDigitalController::class, 'gerenciarAssinantes'])->name('documentos.gerenciar-assinantes');
    Route::delete('/documentos/assinaturas/{id}', [\App\Http\Controllers\DocumentoDigitalController::class, 'removerAssinante'])->name('documentos.remover-assinante');
    Route::post('/documentos/assinaturas/{id}/remover', [\App\Http\Controllers\DocumentoDigitalController::class, 'removerAssinante'])->name('documentos.remover-assinante-post');
    
    // Controle de edição simultânea de documentos
    Route::post('/documentos/{id}/registrar-edicao', [\App\Http\Controllers\DocumentoDigitalController::class, 'registrarEdicao'])->name('documentos.registrar-edicao');
    Route::get('/documentos/{id}/verificar-edicao', [\App\Http\Controllers\DocumentoDigitalController::class, 'verificarEdicao'])->name('documentos.verificar-edicao');
    Route::post('/documentos/{id}/liberar-edicao', [\App\Http\Controllers\DocumentoDigitalController::class, 'liberarEdicao'])->name('documentos.liberar-edicao');
    
    // Edição colaborativa de documentos (para edit.blade.php)
    Route::post('/documentos/{id}/iniciar-edicao', [\App\Http\Controllers\DocumentoDigitalController::class, 'iniciarEdicao'])->name('documentos.iniciar-edicao');
    Route::post('/documentos/{id}/salvar-auto', [\App\Http\Controllers\DocumentoDigitalController::class, 'salvarAuto'])->name('documentos.salvar-auto');
    Route::get('/documentos/{id}/editores-ativos', [\App\Http\Controllers\DocumentoDigitalController::class, 'editoresAtivos'])->name('documentos.editores-ativos');
    Route::get('/documentos/{id}/obter-conteudo', [\App\Http\Controllers\DocumentoDigitalController::class, 'obterConteudo'])->name('documentos.obter-conteudo');
    Route::post('/documentos/{id}/finalizar-edicao', [\App\Http\Controllers\DocumentoDigitalController::class, 'finalizarEdicao'])->name('documentos.finalizar-edicao');
    
    // Processos - Listagem Geral
    Route::get('/processos', [\App\Http\Controllers\ProcessoController::class, 'indexGeral'])->name('processos.index-geral');
    Route::get('/alertas-processos', [\App\Http\Controllers\ProcessoController::class, 'alertasProcessosIndex'])->name('alertas-processos.index');
    
    // Documentos Pendentes de Aprovação
    Route::get('/documentos-pendentes', [\App\Http\Controllers\ProcessoController::class, 'documentosPendentes'])->name('documentos-pendentes.index');
    
    // Processos por Estabelecimento
    Route::get('/estabelecimentos/{id}/processos', [\App\Http\Controllers\ProcessoController::class, 'index'])->name('estabelecimentos.processos.index');
    Route::get('/estabelecimentos/{id}/processos/create', [\App\Http\Controllers\ProcessoController::class, 'create'])->name('estabelecimentos.processos.create');
    Route::post('/estabelecimentos/{id}/processos', [\App\Http\Controllers\ProcessoController::class, 'store'])->name('estabelecimentos.processos.store');
    Route::get('/estabelecimentos/{id}/processos/{processo}', [\App\Http\Controllers\ProcessoController::class, 'show'])->name('estabelecimentos.processos.show');
    Route::get('/estabelecimentos/{id}/processos/{processo}/integra', [\App\Http\Controllers\ProcessoController::class, 'integra'])->name('estabelecimentos.processos.integra');
    Route::patch('/estabelecimentos/{id}/processos/{processo}/status', [\App\Http\Controllers\ProcessoController::class, 'updateStatus'])->name('estabelecimentos.processos.updateStatus');
    Route::post('/estabelecimentos/{id}/processos/{processo}/acompanhar', [\App\Http\Controllers\ProcessoController::class, 'toggleAcompanhamento'])->name('estabelecimentos.processos.toggleAcompanhamento');
    Route::get('/estabelecimentos/{id}/processos/{processo}/acompanhar', function ($id, $processo) {
        return redirect()->route('admin.estabelecimentos.processos.show', [$id, $processo]);
    });
    Route::post('/estabelecimentos/{id}/processos/{processo}/arquivar', [\App\Http\Controllers\ProcessoController::class, 'arquivar'])->name('estabelecimentos.processos.arquivar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/desarquivar', [\App\Http\Controllers\ProcessoController::class, 'desarquivar'])->name('estabelecimentos.processos.desarquivar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/parar', [\App\Http\Controllers\ProcessoController::class, 'parar'])->name('estabelecimentos.processos.parar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/adicionar-unidade', [\App\Http\Controllers\ProcessoController::class, 'adicionarUnidade'])->name('estabelecimentos.processos.adicionar-unidade');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}/concluir', [\App\Http\Controllers\ProcessoController::class, 'concluirPasta'])->name('estabelecimentos.processos.pasta.concluir');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}/reabrir', [\App\Http\Controllers\ProcessoController::class, 'reabrirPasta'])->name('estabelecimentos.processos.pasta.reabrir');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}/retomar', [\App\Http\Controllers\ProcessoController::class, 'retomarPasta'])->name('estabelecimentos.processos.pasta.retomar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/unidades/{pasta}/editar', [\App\Http\Controllers\ProcessoController::class, 'editarUnidade'])->name('estabelecimentos.processos.unidade.editar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/unidades/{pasta}/excluir', [\App\Http\Controllers\ProcessoController::class, 'excluirUnidade'])->name('estabelecimentos.processos.unidade.excluir');
    Route::post('/estabelecimentos/{id}/processos/{processo}/reiniciar', [\App\Http\Controllers\ProcessoController::class, 'reiniciar'])->name('estabelecimentos.processos.reiniciar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/retomar-unidade/{unidade}', [\App\Http\Controllers\ProcessoController::class, 'retomarUnidade'])->name('estabelecimentos.processos.retomar-unidade');
    Route::delete('/estabelecimentos/{id}/processos/{processo}', [\App\Http\Controllers\ProcessoController::class, 'destroy'])->name('estabelecimentos.processos.destroy');
    
    // Upload de arquivos em processos
    Route::post('/estabelecimentos/{id}/processos/{processo}/upload', [\App\Http\Controllers\ProcessoController::class, 'uploadArquivo'])->name('estabelecimentos.processos.upload');
    Route::get('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/visualizar', [\App\Http\Controllers\ProcessoController::class, 'visualizarArquivo'])->name('estabelecimentos.processos.visualizar');
    Route::get('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/download', [\App\Http\Controllers\ProcessoController::class, 'downloadArquivo'])->name('estabelecimentos.processos.download');
    Route::patch('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/nome', [\App\Http\Controllers\ProcessoController::class, 'updateNomeArquivo'])->name('estabelecimentos.processos.updateNome');
    Route::delete('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}', [\App\Http\Controllers\ProcessoController::class, 'deleteArquivo'])->name('estabelecimentos.processos.deleteArquivo');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/aprovar', [\App\Http\Controllers\ProcessoController::class, 'aprovarDocumento'])->name('estabelecimentos.processos.documento.aprovar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/rejeitar', [\App\Http\Controllers\ProcessoController::class, 'rejeitarDocumento'])->name('estabelecimentos.processos.documento.rejeitar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/revalidar', [\App\Http\Controllers\ProcessoController::class, 'revalidarDocumento'])->name('estabelecimentos.processos.documento.revalidar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/carimbar', [\App\Http\Controllers\ProcessoController::class, 'carimbarDocumento'])->name('estabelecimentos.processos.documento.carimbar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/analisar-ia', [\App\Http\Controllers\ProcessoController::class, 'analisarDocumentoIA'])->name('estabelecimentos.processos.documento.analisar-ia');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos/{documento}/vincular-obrigatorio', [\App\Http\Controllers\ProcessoController::class, 'vincularDocumentoObrigatorio'])->name('estabelecimentos.processos.documento.vincular-obrigatorio');
    
    // Respostas a documentos digitais (notificações, etc)
    Route::get('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/visualizar', [\App\Http\Controllers\ProcessoController::class, 'visualizarRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.visualizar');
    Route::get('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/download', [\App\Http\Controllers\ProcessoController::class, 'downloadRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.download');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/aprovar', [\App\Http\Controllers\ProcessoController::class, 'aprovarRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.aprovar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/rejeitar', [\App\Http\Controllers\ProcessoController::class, 'rejeitarRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.rejeitar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/revalidar', [\App\Http\Controllers\ProcessoController::class, 'revalidarRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.revalidar');
    Route::delete('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/respostas/{resposta}/excluir', [\App\Http\Controllers\ProcessoController::class, 'excluirRespostaDocumento'])->name('estabelecimentos.processos.documento-digital.resposta.excluir');
    
    // Definir/Finalizar/Reabrir prazo de documentos digitais
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/definir-prazo', [\App\Http\Controllers\ProcessoController::class, 'definirPrazoDocumento'])->name('estabelecimentos.processos.documento-digital.definir-prazo');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/finalizar-prazo', [\App\Http\Controllers\ProcessoController::class, 'finalizarPrazoDocumento'])->name('estabelecimentos.processos.documento-digital.finalizar-prazo');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/reabrir-prazo', [\App\Http\Controllers\ProcessoController::class, 'reabrirPrazoDocumento'])->name('estabelecimentos.processos.documento-digital.reabrir-prazo');
    Route::post('/estabelecimentos/{id}/processos/{processo}/documentos-digitais/{documento}/prorrogar-prazo', [\App\Http\Controllers\ProcessoController::class, 'prorrogarPrazoDocumento'])->name('estabelecimentos.processos.documento-digital.prorrogar-prazo');
    
    // Anotações em PDFs
    Route::get('/processos/documentos/{documento}/anotacoes', [\App\Http\Controllers\ProcessoController::class, 'carregarAnotacoes'])->name('processos.documentos.anotacoes.carregar');
    Route::post('/processos/documentos/{documento}/anotacoes', [\App\Http\Controllers\ProcessoController::class, 'salvarAnotacoes'])->name('processos.documentos.anotacoes.salvar');
    
    // Designação de Responsável
    Route::get('/estabelecimentos/{id}/processos/{processo}/usuarios-designacao', [\App\Http\Controllers\ProcessoController::class, 'buscarUsuariosParaDesignacao'])->name('estabelecimentos.processos.usuarios.designacao');
    Route::post('/estabelecimentos/{id}/processos/{processo}/designar', [\App\Http\Controllers\ProcessoController::class, 'designarResponsavel'])->name('estabelecimentos.processos.designar');
    Route::post('/estabelecimentos/{id}/processos/{processo}/atribuir', [\App\Http\Controllers\ProcessoController::class, 'atribuirProcesso'])->name('estabelecimentos.processos.atribuir');
    Route::post('/estabelecimentos/{id}/processos/{processo}/ciente', [\App\Http\Controllers\ProcessoController::class, 'marcarCiente'])->name('estabelecimentos.processos.ciente');
    Route::get('/estabelecimentos/{id}/processos/{processo}/linha-tempo', [\App\Http\Controllers\ProcessoController::class, 'linhaTempo'])->name('estabelecimentos.processos.linha-tempo');
    Route::patch('/estabelecimentos/{id}/processos/{processo}/designacoes/{designacao}', [\App\Http\Controllers\ProcessoController::class, 'atualizarDesignacao'])->name('estabelecimentos.processos.designacoes.atualizar');
    Route::put('/estabelecimentos/{id}/processos/{processo}/designacoes/{designacao}/concluir', [\App\Http\Controllers\ProcessoController::class, 'concluirDesignacao'])->name('estabelecimentos.processos.designacoes.concluir');
    
    // Alertas do Processo
    Route::post('/estabelecimentos/{id}/processos/{processo}/alertas', [\App\Http\Controllers\ProcessoController::class, 'criarAlerta'])->name('estabelecimentos.processos.alertas.criar');
    Route::patch('/estabelecimentos/{id}/processos/{processo}/alertas/{alerta}/visualizar', [\App\Http\Controllers\ProcessoController::class, 'visualizarAlerta'])->name('estabelecimentos.processos.alertas.visualizar');
    Route::patch('/estabelecimentos/{id}/processos/{processo}/alertas/{alerta}/concluir', [\App\Http\Controllers\ProcessoController::class, 'concluirAlerta'])->name('estabelecimentos.processos.alertas.concluir');
    Route::delete('/estabelecimentos/{id}/processos/{processo}/alertas/{alerta}', [\App\Http\Controllers\ProcessoController::class, 'excluirAlerta'])->name('estabelecimentos.processos.alertas.excluir');
    
    // Gerar documento digital
    Route::post('/estabelecimentos/{id}/processos/{processo}/gerar-documento', [\App\Http\Controllers\ProcessoController::class, 'gerarDocumento'])->name('estabelecimentos.processos.gerarDocumento');
    
    // Pastas do Processo
    Route::get('/estabelecimentos/{id}/processos/{processo}/pastas', [\App\Http\Controllers\ProcessoPastaController::class, 'index'])->name('estabelecimentos.processos.pastas.index');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas', [\App\Http\Controllers\ProcessoPastaController::class, 'store'])->name('estabelecimentos.processos.pastas.store');
    Route::put('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}', [\App\Http\Controllers\ProcessoPastaController::class, 'update'])->name('estabelecimentos.processos.pastas.update');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}/vincular-unidade', [\App\Http\Controllers\ProcessoPastaController::class, 'vincularUnidade'])->name('estabelecimentos.processos.pastas.vincular-unidade');
    Route::delete('/estabelecimentos/{id}/processos/{processo}/pastas/{pasta}', [\App\Http\Controllers\ProcessoPastaController::class, 'destroy'])->name('estabelecimentos.processos.pastas.destroy');
    Route::post('/estabelecimentos/{id}/processos/{processo}/pastas/mover', [\App\Http\Controllers\ProcessoPastaController::class, 'moverItem'])->name('estabelecimentos.processos.pastas.mover');
    
    Route::resource('/estabelecimentos', EstabelecimentoController::class)->names([
        'index' => 'estabelecimentos.index',
        'create' => 'estabelecimentos.create',
        'store' => 'estabelecimentos.store',
        'show' => 'estabelecimentos.show',
        'edit' => 'estabelecimentos.edit',
        'update' => 'estabelecimentos.update',
        'destroy' => 'estabelecimentos.destroy',
    ]);

    // Ordens de Serviço - Rotas especiais ANTES do resource
    // API para buscar estabelecimentos com autocomplete
    Route::get('ordens-servico/api/buscar-estabelecimentos', 
        [\App\Http\Controllers\OrdemServicoController::class, 'buscarEstabelecimentos']
    )->name('ordens-servico.api.buscar-estabelecimentos');
    
    // API para buscar processos do estabelecimento
    Route::get('ordens-servico/estabelecimento/{estabelecimentoId}/processos', 
        [\App\Http\Controllers\OrdemServicoController::class, 'getProcessosEstabelecimento']
    )->name('ordens-servico.estabelecimento.processos');
    
    Route::resource('ordens-servico', \App\Http\Controllers\OrdemServicoController::class)->parameters([
        'ordens-servico' => 'ordemServico'
    ]);
    
    // API para buscar processos do estabelecimento (rota antiga - manter compatibilidade)
    Route::get('ordens-servico/api/processos-estabelecimento/{estabelecimentoId}', 
        [\App\Http\Controllers\OrdemServicoController::class, 'getProcessosPorEstabelecimento']
    )->name('ordens-servico.api.processos-estabelecimento');
    
    // API para autocomplete de tipos de ação
    Route::get('ordens-servico/api/search-tipos-acao', 
        [\App\Http\Controllers\OrdemServicoController::class, 'searchTiposAcao']
    )->name('ordens-servico.api.search-tipos-acao');
    
    // API para autocomplete de técnicos
    Route::get('ordens-servico/api/search-tecnicos', 
        [\App\Http\Controllers\OrdemServicoController::class, 'searchTecnicos']
    )->name('ordens-servico.api.search-tecnicos');
    
    // Finalizar OS
    Route::post('ordens-servico/{ordemServico}/finalizar', 
        [\App\Http\Controllers\OrdemServicoController::class, 'finalizar']
    )->name('ordens-servico.finalizar');
    
    // Finalizar Atividade Individual (técnico finaliza apenas sua atividade)
    Route::get('ordens-servico/{ordemServico}/finalizar-atividade/{atividadeIndex}', 
        [\App\Http\Controllers\OrdemServicoController::class, 'showFinalizarAtividade']
    )->name('ordens-servico.show-finalizar-atividade');
    
    Route::post('ordens-servico/{ordemServico}/finalizar-atividade', 
        [\App\Http\Controllers\OrdemServicoController::class, 'finalizarAtividade']
    )->name('ordens-servico.finalizar-atividade');

    Route::post('ordens-servico/{ordemServico}/finalizar-atividade/upload-arquivo',
        [\App\Http\Controllers\OrdemServicoController::class, 'uploadArquivoExternoAtividade']
    )->name('ordens-servico.upload-arquivo-atividade');

    Route::post('ordens-servico/{ordemServico}/assinar-gestor',
        [\App\Http\Controllers\OrdemServicoController::class, 'assinarGestor']
    )->name('ordens-servico.assinar-gestor');

    Route::get('ordens-servico/{ordemServico}/arquivos-externos/{documento}/visualizar',
        [\App\Http\Controllers\OrdemServicoController::class, 'visualizarArquivoExternoAtividade']
    )->name('ordens-servico.arquivos-externos.visualizar');

    Route::get('ordens-servico/{ordemServico}/documento-anexo',
        [\App\Http\Controllers\OrdemServicoController::class, 'visualizarDocumentoAnexo']
    )->name('ordens-servico.documento-anexo');
    
    // Obter minhas atividades na OS
    Route::get('ordens-servico/{ordemServico}/minhas-atividades', 
        [\App\Http\Controllers\OrdemServicoController::class, 'getMinhasAtividades']
    )->name('ordens-servico.minhas-atividades');
    
    // Gerar PDF da OS
    Route::get('ordens-servico/{ordemServico}/pdf', 
        [\App\Http\Controllers\OrdemServicoController::class, 'gerarPdf']
    )->name('ordens-servico.pdf');

    // Gerar PDF consolidado (todos estabelecimentos)
    Route::get('ordens-servico/{ordemServico}/pdf-todos', 
        [\App\Http\Controllers\OrdemServicoController::class, 'gerarPdfTodos']
    )->name('ordens-servico.pdf-todos');
    
    // Reiniciar OS
    Route::post('ordens-servico/{ordemServico}/reiniciar', 
        [\App\Http\Controllers\OrdemServicoController::class, 'reiniciar']
    )->name('ordens-servico.reiniciar');
    
    // Reiniciar atividade individual (gestores)
    Route::post('ordens-servico/{ordemServico}/reiniciar-atividade', 
        [\App\Http\Controllers\OrdemServicoController::class, 'reiniciarAtividade']
    )->name('ordens-servico.reiniciar-atividade');
    
    // Cancelar OS
    Route::post('ordens-servico/{ordemServico}/cancelar', 
        [\App\Http\Controllers\OrdemServicoController::class, 'cancelar']
    )->name('ordens-servico.cancelar');
    
    // Reativar OS Cancelada
    Route::post('ordens-servico/{ordemServico}/reativar', 
        [\App\Http\Controllers\OrdemServicoController::class, 'reativar']
    )->name('ordens-servico.reativar');

    // Pesquisas de Satisfação - Respostas (módulo do menu lateral)
    Route::prefix('pesquisas-satisfacao/respostas')->name('pesquisas-satisfacao.respostas.')->middleware('admin')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\PesquisaSatisfacaoRespostaController::class, 'index'])->name('index');
        Route::get('/{resposta}', [\App\Http\Controllers\Admin\PesquisaSatisfacaoRespostaController::class, 'show'])->name('show');
        Route::delete('/{resposta}', [\App\Http\Controllers\Admin\PesquisaSatisfacaoRespostaController::class, 'destroy'])->name('destroy');
    });

    // Notificações
    Route::get('notificacoes', [\App\Http\Controllers\NotificacaoController::class, 'index'])->name('notificacoes.index');
    Route::get('notificacoes/nao-lidas', [\App\Http\Controllers\NotificacaoController::class, 'naoLidas'])->name('notificacoes.nao-lidas');
    Route::post('notificacoes/{id}/marcar-lida', [\App\Http\Controllers\NotificacaoController::class, 'marcarComoLida'])->name('notificacoes.marcar-lida');
    Route::post('notificacoes/marcar-todas-lidas', [\App\Http\Controllers\NotificacaoController::class, 'marcarTodasComoLidas'])->name('notificacoes.marcar-todas-lidas');

    // Receituários
    Route::prefix('receituarios')->name('receituarios.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ReceituarioController::class, 'index'])->name('index');
        Route::get('create', [\App\Http\Controllers\ReceituarioController::class, 'create'])->name('create');
        Route::post('/', [\App\Http\Controllers\ReceituarioController::class, 'store'])->name('store');
        Route::get('buscar-cnpj', [\App\Http\Controllers\ReceituarioController::class, 'buscarCnpj'])->name('buscar-cnpj');
        Route::get('{id}/pdf-gerado', [\App\Http\Controllers\ReceituarioController::class, 'pdfGerado'])->name('pdf-gerado');
        Route::get('{id}/pdf', [\App\Http\Controllers\ReceituarioController::class, 'gerarPdf'])->name('gerar-pdf');
        Route::get('{id}', [\App\Http\Controllers\ReceituarioController::class, 'show'])->name('show');
        Route::get('{id}/edit', [\App\Http\Controllers\ReceituarioController::class, 'edit'])->name('edit');
        Route::put('{id}', [\App\Http\Controllers\ReceituarioController::class, 'update'])->name('update');
        Route::delete('{id}', [\App\Http\Controllers\ReceituarioController::class, 'destroy'])->name('destroy');
        Route::post('{id}/criar-processo', [\App\Http\Controllers\ReceituarioController::class, 'criarProcesso'])->name('criar-processo');
    });

    // Usuários Internos
    Route::post('usuarios-internos/convites', [\App\Http\Controllers\UsuarioInternoController::class, 'storeConvite'])->name('usuarios-internos.convites.store');
    Route::delete('usuarios-internos/convites/{convite}', [\App\Http\Controllers\UsuarioInternoController::class, 'destroyConvite'])->name('usuarios-internos.convites.destroy');
    Route::get('usuarios-internos/{usuario}/detalhes-atividade', [\App\Http\Controllers\UsuarioInternoController::class, 'detalhesAtividade'])->name('usuarios-internos.detalhes-atividade');
    Route::post('usuarios-internos/{usuarioInterno}/aprovar-cadastro', [\App\Http\Controllers\UsuarioInternoController::class, 'aprovarCadastro'])->name('usuarios-internos.aprovar-cadastro');
    Route::post('usuarios-internos/{usuarioInterno}/rejeitar-cadastro', [\App\Http\Controllers\UsuarioInternoController::class, 'rejeitarCadastro'])->name('usuarios-internos.rejeitar-cadastro');
    Route::resource('usuarios-internos', \App\Http\Controllers\UsuarioInternoController::class)->parameters([
        'usuarios-internos' => 'usuarioInterno'
    ]);
    
    // Usuários Externos
    Route::resource('usuarios-externos', \App\Http\Controllers\UsuarioExternoController::class)->parameters([
        'usuarios-externos' => 'usuarioExterno'
    ]);

    Route::prefix('configuracoes')->name('configuracoes.')->middleware('admin.gestor')->group(function () {
        Route::get('/', [\App\Http\Controllers\ConfiguracaoController::class, 'index'])->name('index');

        Route::resource('modelos-documento', \App\Http\Controllers\ModeloDocumentoController::class)->parameters([
            'modelos-documento' => 'modeloDocumento'
        ]);

        Route::prefix('municipios')->name('municipios.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\MunicipioController::class, 'index'])->name('index');
            Route::get('/{id}/edit', [\App\Http\Controllers\Admin\MunicipioController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\Admin\MunicipioController::class, 'update'])->name('update');
        });
    });

    // Configurações - Acesso para ADMINISTRADORES e GESTOR ESTADUAL (rotas específicas)
    Route::prefix('configuracoes')->name('configuracoes.')->middleware('admin.gestor.estadual')->group(function () {
        // Tipos de Documento - Admin e Gestor Estadual
        Route::resource('tipos-documento', \App\Http\Controllers\TipoDocumentoController::class)->parameters([
            'tipos-documento' => 'tipoDocumento'
        ]);
        Route::post('tipos-documento/reordenar', [\App\Http\Controllers\TipoDocumentoController::class, 'reordenar'])->name('tipos-documento.reordenar');

        // Tipos de Documento Resposta - Admin e Gestor Estadual
        Route::resource('tipos-documento-resposta', \App\Http\Controllers\TipoDocumentoRespostaController::class);
        Route::post('tipos-documento/{tipoDocumento}/vincular-respostas', [\App\Http\Controllers\TipoDocumentoController::class, 'vincularRespostas'])->name('tipos-documento.vincular-respostas');
        
        // Avisos do Sistema - Admin e Gestor Estadual
        Route::resource('avisos', \App\Http\Controllers\Admin\AvisoController::class);
        Route::patch('avisos/{aviso}/toggle', [\App\Http\Controllers\Admin\AvisoController::class, 'toggleAtivo'])->name('avisos.toggle');
        
        // Pactuação (Competências Municipais e Estaduais) - Admin e Gestor Estadual
        Route::prefix('pactuacao')->name('pactuacao.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PactuacaoController::class, 'index'])->name('index');
            Route::get('buscar-cnaes', [\App\Http\Controllers\Admin\PactuacaoController::class, 'buscarCnaes'])->name('buscar-cnaes');
            Route::post('buscar-questionarios', [\App\Http\Controllers\Admin\PactuacaoController::class, 'buscarQuestionarios'])->name('buscar-questionarios');
            Route::get('pesquisar', [\App\Http\Controllers\Admin\PactuacaoController::class, 'pesquisar'])->name('pesquisar');
            Route::post('/', [\App\Http\Controllers\Admin\PactuacaoController::class, 'store'])->name('store');
            Route::post('multiple', [\App\Http\Controllers\Admin\PactuacaoController::class, 'storeMultiple'])->name('store-multiple');
            Route::get('{id}', [\App\Http\Controllers\Admin\PactuacaoController::class, 'show'])->name('show');
            Route::put('{id}', [\App\Http\Controllers\Admin\PactuacaoController::class, 'update'])->name('update');
            Route::post('{id}/toggle', [\App\Http\Controllers\Admin\PactuacaoController::class, 'toggleStatus'])->name('toggle');
            Route::post('{id}/toggle-unidade-movel', [\App\Http\Controllers\Admin\PactuacaoController::class, 'toggleUnidadeMovel'])->name('toggle-unidade-movel');
            Route::post('{id}/toggle-pessoa-fisica', [\App\Http\Controllers\Admin\PactuacaoController::class, 'togglePessoaFisica'])->name('toggle-pessoa-fisica');
            Route::post('{id}/adicionar-excecao', [\App\Http\Controllers\Admin\PactuacaoController::class, 'adicionarExcecao'])->name('adicionar-excecao');
            Route::post('{id}/remover-excecao', [\App\Http\Controllers\Admin\PactuacaoController::class, 'removerExcecao'])->name('remover-excecao');
            Route::delete('{id}', [\App\Http\Controllers\Admin\PactuacaoController::class, 'destroy'])->name('destroy');
        });
        
        // Documentos Obrigatórios para PJ Unidade Móvel (antes do resource para evitar conflito com {listas_documento})
        Route::prefix('listas-documento/unidade-movel')->name('listas-documento.unidade-movel.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ListaDocumentoController::class, 'unidadeMovelIndex'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\ListaDocumentoController::class, 'unidadeMovelStore'])->name('store');
            Route::delete('/{id}', [\App\Http\Controllers\Admin\ListaDocumentoController::class, 'unidadeMovelDestroy'])->name('destroy');
            Route::post('/{id}/destroy', [\App\Http\Controllers\Admin\ListaDocumentoController::class, 'unidadeMovelDestroy'])->name('destroy-post');
        });

        // Listas de Documentos por Atividade - Admin e Gestor Estadual
        Route::resource('listas-documento', \App\Http\Controllers\Admin\ListaDocumentoController::class);
        Route::post('listas-documento/{listas_documento}/duplicate', [\App\Http\Controllers\Admin\ListaDocumentoController::class, 'duplicate'])->name('listas-documento.duplicate');

        // Tipos de Documento Obrigatório (integrado na página de listas-documento) - Admin e Gestor Estadual
        // As rotas de tipos-documento-obrigatorio agora são acessadas via aba na página listas-documento
        Route::post('tipos-documento-obrigatorio/store-multiple', [\App\Http\Controllers\Admin\TipoDocumentoObrigatorioController::class, 'storeMultiple'])->name('tipos-documento-obrigatorio.store-multiple');
        Route::delete('tipos-documento-obrigatorio/destroy-multiple', [\App\Http\Controllers\Admin\TipoDocumentoObrigatorioController::class, 'destroyMultiple'])->name('tipos-documento-obrigatorio.destroy-multiple');
        Route::post('tipos-documento-obrigatorio/destroy-multiple', [\App\Http\Controllers\Admin\TipoDocumentoObrigatorioController::class, 'destroyMultiple'])->name('tipos-documento-obrigatorio.destroy-multiple-post');
        Route::post('tipos-documento-obrigatorio/{id}/destroy', [\App\Http\Controllers\Admin\TipoDocumentoObrigatorioController::class, 'destroyPost'])->name('tipos-documento-obrigatorio.destroy-post');
        Route::resource('tipos-documento-obrigatorio', \App\Http\Controllers\Admin\TipoDocumentoObrigatorioController::class);
        
        // Nova estrutura: Documentos por Atividade (simplificada) - Admin e Gestor Estadual
        Route::prefix('atividade-documento')->name('atividade-documento.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'index'])->name('index');
            Route::get('{atividade}', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'show'])->name('show');
            Route::put('{atividade}', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'update'])->name('update');
            Route::post('{atividade}/adicionar', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'adicionarDocumento'])->name('adicionar');
            Route::delete('{atividade}/remover/{documento}', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'removerDocumento'])->name('remover');
            Route::post('{atividade}/copiar', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'copiarDocumentos'])->name('copiar');
            Route::post('aplicar-lote', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'aplicarEmLote'])->name('aplicar-lote');
            Route::get('{atividade}/documentos', [\App\Http\Controllers\Admin\AtividadeDocumentoController::class, 'getDocumentos'])->name('get-documentos');
        });

        // Tipos de Serviço - Admin e Gestor Estadual
        Route::get('tipos-servico/buscar-cnaes-municipio/{municipio_id}', [\App\Http\Controllers\Admin\TipoServicoController::class, 'buscarCnaesMunicipio'])->name('tipos-servico.buscar-cnaes-municipio');
        Route::resource('tipos-servico', \App\Http\Controllers\Admin\TipoServicoController::class);

        // Atividades - Admin e Gestor Estadual
        Route::resource('atividades', \App\Http\Controllers\Admin\AtividadeController::class);
        Route::post('atividades/store-multiple', [\App\Http\Controllers\Admin\AtividadeController::class, 'storeMultiple'])->name('atividades.store-multiple');
        
        // Tipos de Ações - Admin e Gestor Estadual
        Route::resource('tipo-acoes', \App\Http\Controllers\Admin\TipoAcaoController::class)->parameters([
            'tipo-acoes' => 'tipoAcao'
        ]);
        
        // Subações - rotas aninhadas
        Route::prefix('tipo-acoes/{tipoAcao}/sub-acoes')->name('tipo-acoes.sub-acoes.')->group(function () {
            Route::post('/', [\App\Http\Controllers\Admin\TipoAcaoController::class, 'storeSubAcao'])->name('store');
            Route::put('/{subAcao}', [\App\Http\Controllers\Admin\TipoAcaoController::class, 'updateSubAcao'])->name('update');
            Route::delete('/{subAcao}', [\App\Http\Controllers\Admin\TipoAcaoController::class, 'destroySubAcao'])->name('destroy');
        });
        
        // API: Buscar subações de uma ação
        Route::get('tipo-acoes/{tipoAcao}/sub-acoes/json', [\App\Http\Controllers\Admin\TipoAcaoController::class, 'getSubAcoes'])->name('tipo-acoes.sub-acoes.json');
        
        // Equipamentos de Radiação Ionizante - Admin e Gestor Estadual
        Route::prefix('equipamentos-radiacao')->name('equipamentos-radiacao.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'index'])->name('index');
            Route::get('create', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'store'])->name('store');
            Route::get('{equipamentos_radiacao}/edit', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'edit'])->name('edit');
            Route::put('{equipamentos_radiacao}', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'update'])->name('update');
            Route::delete('{equipamentos_radiacao}', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'destroy'])->name('destroy');
            Route::post('{equipamentos_radiacao}/toggle', [\App\Http\Controllers\Admin\AtividadeEquipamentoRadiacaoController::class, 'toggleStatus'])->name('toggle');
        });

        // Responsável Técnico por Atividade - Admin e Gestor Estadual
        Route::prefix('responsaveis-tecnicos')->name('responsaveis-tecnicos.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'index'])->name('index');
            Route::get('create', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'store'])->name('store');
            Route::get('{responsavel_tecnico}/edit', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'edit'])->name('edit');
            Route::put('{responsavel_tecnico}', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'update'])->name('update');
            Route::delete('{responsavel_tecnico}', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'destroy'])->name('destroy');
            Route::post('{responsavel_tecnico}/toggle', [\App\Http\Controllers\Admin\AtividadeResponsavelTecnicoController::class, 'toggleStatus'])->name('toggle');
        });
    });
    
    // Configurações - RESTRITO APENAS A ADMINISTRADORES
    Route::prefix('configuracoes')->name('configuracoes.')->middleware('admin')->group(function () {
        // Tipos de Processo - Apenas Admin
        Route::resource('tipos-processo', \App\Http\Controllers\TipoProcessoController::class)->parameters([
            'tipos-processo' => 'tipoProcesso'
        ]);

        // Unidades - Apenas Admin
        Route::resource('unidades', \App\Http\Controllers\Admin\UnidadeController::class)->parameters([
            'unidades' => 'unidade'
        ]);
        Route::post('unidades/{unidade}/toggle-status', [\App\Http\Controllers\Admin\UnidadeController::class, 'toggleStatus'])->name('unidades.toggle-status');
        
        // Tipos de Setor - Apenas Admin
        Route::resource('tipo-setores', \App\Http\Controllers\Admin\TipoSetorController::class)->parameters([
            'tipo-setores' => 'tipoSetor'
        ]);
        Route::post('tipo-setores/{tipoSetor}/toggle-status', [\App\Http\Controllers\Admin\TipoSetorController::class, 'toggleStatus'])->name('tipo-setores.toggle-status');
        
        // Municípios - Apenas Admin
        Route::prefix('municipios')->name('municipios.')->group(function () {
            Route::get('/create', [\App\Http\Controllers\Admin\MunicipioController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\MunicipioController::class, 'store'])->name('store');
            Route::get('/{id}', [\App\Http\Controllers\Admin\MunicipioController::class, 'show'])->name('show');
            Route::post('/{id}/toggle', [\App\Http\Controllers\Admin\MunicipioController::class, 'toggleStatus'])->name('toggle');
            Route::delete('/{id}', [\App\Http\Controllers\Admin\MunicipioController::class, 'destroy'])->name('destroy');
            Route::get('/buscar', [\App\Http\Controllers\Admin\MunicipioController::class, 'buscar'])->name('buscar');
        });
        
        // Configurações do Sistema - Apenas Admin
        Route::prefix('sistema')->name('sistema.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ConfiguracaoSistemaController::class, 'index'])->name('index');
            Route::put('/', [\App\Http\Controllers\Admin\ConfiguracaoSistemaController::class, 'update'])->name('update');
            Route::post('/permissoes-sigiloso', [\App\Http\Controllers\Admin\ConfiguracaoSistemaController::class, 'salvarPermissoesSigiloso'])->name('salvar-permissoes-sigiloso');
        });
        
        // Documentos POPs/IA - Apenas Admin
        Route::prefix('documentos-pops')->name('documentos-pops.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'store'])->name('store');
            Route::get('/{documentoPop}/edit', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'edit'])->name('edit');
            Route::put('/{documentoPop}', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'update'])->name('update');
            Route::delete('/{documentoPop}', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'destroy'])->name('destroy');
            Route::get('/{documentoPop}/download', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'download'])->name('download');
            Route::get('/{documentoPop}/visualizar', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'visualizar'])->name('visualizar');
            Route::post('/{documentoPop}/reindexar', [\App\Http\Controllers\Admin\DocumentoPopController::class, 'reindexar'])->name('reindexar');
        });
        
        // Categorias de POPs - Apenas Admin
        Route::prefix('categorias-pops')->name('categorias-pops.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'store'])->name('store');
            Route::get('/{categoriaPop}/edit', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'edit'])->name('edit');
            Route::put('/{categoriaPop}', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'update'])->name('update');
            Route::delete('/{categoriaPop}', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'destroy'])->name('destroy');
            Route::get('/listar', [\App\Http\Controllers\Admin\CategoriaPopController::class, 'listar'])->name('listar');
        });
        
        // Documentos de Ajuda - Apenas Admin
        Route::prefix('documentos-ajuda')->name('documentos-ajuda.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'store'])->name('store');
            Route::get('/{id}/edit', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'edit'])->name('edit');
            Route::put('/{id}', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'update'])->name('update');
            Route::delete('/{id}', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'destroy'])->name('destroy');
            Route::get('/{id}/visualizar', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'visualizar'])->name('visualizar');
            Route::get('/{id}/download', [\App\Http\Controllers\Admin\DocumentoAjudaController::class, 'download'])->name('download');
        });

        // Chat Broadcast (Suporte InfoVISA) - Apenas Admin
        Route::prefix('chat-broadcast')->name('chat-broadcast.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\ChatBroadcastController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\ChatBroadcastController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\ChatBroadcastController::class, 'store'])->name('store');
            Route::delete('/{chatBroadcast}', [\App\Http\Controllers\Admin\ChatBroadcastController::class, 'destroy'])->name('destroy');
            Route::get('/{chatBroadcast}/estatisticas', [\App\Http\Controllers\Admin\ChatBroadcastController::class, 'estatisticas'])->name('estatisticas');
        });

        // Pesquisas de Satisfação - Apenas Admin
        Route::prefix('pesquisas-satisfacao')->name('pesquisas-satisfacao.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'index'])->name('index');
            Route::get('/create', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'create'])->name('create');
            Route::post('/', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'store'])->name('store');
            Route::get('/{pesquisasSatisfacao}/edit', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'edit'])->name('edit');
            Route::put('/{pesquisasSatisfacao}', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'update'])->name('update');
            Route::delete('/{pesquisasSatisfacao}', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'destroy'])->name('destroy');
            Route::post('/{pesquisasSatisfacao}/toggle', [\App\Http\Controllers\Admin\PesquisaSatisfacaoController::class, 'toggleAtivo'])->name('toggle');
        });
    });
    
    // WhatsApp - Configuração e Painel - Apenas Admin (fora do grupo configuracoes)
    Route::prefix('whatsapp')->name('whatsapp.')->middleware('admin')->group(function () {
        // Configuração
        Route::get('/configuracao', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'index'])->name('configuracao');
        Route::post('/configuracao', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'salvar'])->name('configuracao.salvar');
        Route::post('/restaurar-template', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'restaurarTemplate'])->name('restaurar-template');
        Route::get('/status', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'verificarStatus'])->name('status');
        Route::post('/iniciar-sessao', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'iniciarSessao'])->name('iniciar-sessao');
        Route::post('/encerrar-sessao', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'encerrarSessao'])->name('encerrar-sessao');
        Route::post('/enviar-teste', [\App\Http\Controllers\Admin\WhatsappConfiguracaoController::class, 'enviarTeste'])->name('enviar-teste');

        // Painel de Mensagens
        Route::get('/painel', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'index'])->name('painel');
        Route::get('/mensagens/{id}/detalhes', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'detalhes'])->name('mensagens.detalhes');
        Route::post('/mensagens/{id}/reenviar', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'reenviar'])->name('mensagens.reenviar');
        Route::post('/reenviar-todas', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'reenviarTodas'])->name('reenviar-todas');
        Route::delete('/mensagens/{id}', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'destroy'])->name('mensagens.destroy');
        Route::get('/exportar', [\App\Http\Controllers\Admin\WhatsappPainelController::class, 'exportar'])->name('exportar');
    });
    
    // Assistente IA
    Route::post('/ia/chat', [\App\Http\Controllers\AssistenteIAController::class, 'chat'])->name('ia.chat');
    Route::post('/ia/extrair-pdf', [\App\Http\Controllers\AssistenteIAController::class, 'extrairPdf'])->name('assistente-ia.extrair-pdf');
    Route::post('/ia/chat-edicao-documento', [\App\Http\Controllers\AssistenteIAController::class, 'chatEdicaoDocumento'])->name('ia.chat-edicao-documento');
    Route::get('/ia/documentos-processo/{estabelecimento}/{processo}', [\App\Http\Controllers\AssistenteIAController::class, 'listarDocumentosProcesso']);
    Route::post('/ia/extrair-multiplos-pdfs', [\App\Http\Controllers\AssistenteIAController::class, 'extrairMultiplosPdfs']);
    
    
    // Relatórios
    Route::prefix('relatorios')->name('relatorios.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\RelatorioController::class, 'index'])->name('index');
        Route::get('/estabelecimentos', [\App\Http\Controllers\Admin\RelatorioEstabelecimentoController::class, 'index'])->name('estabelecimentos');
        Route::get('/estabelecimentos/export', [\App\Http\Controllers\Admin\RelatorioEstabelecimentoController::class, 'export'])->name('estabelecimentos.export');
        Route::get('/estabelecimentos-cnae', [\App\Http\Controllers\Admin\RelatorioController::class, 'estabelecimentosPorCnae'])->name('estabelecimentos-cnae');
        Route::get('/documentos-gerados', [\App\Http\Controllers\Admin\RelatorioController::class, 'documentosGerados'])->name('documentos-gerados');
        Route::get('/equipamentos-radiacao', [\App\Http\Controllers\Admin\RelatorioController::class, 'equipamentosRadiacao'])->name('equipamentos-radiacao');
        Route::get('/equipamentos-radiacao/export', [\App\Http\Controllers\Admin\RelatorioController::class, 'equipamentosRadiacaoExport'])->name('equipamentos-radiacao.export');
        Route::get('/equipamentos-radiacao/declaracoes', [\App\Http\Controllers\Admin\RelatorioController::class, 'declaracoesSemEquipamentos'])->name('equipamentos-radiacao.declaracoes');
        Route::get('/pesquisa-satisfacao', [\App\Http\Controllers\Admin\RelatorioController::class, 'pesquisaSatisfacao'])->name('pesquisa-satisfacao')->middleware('admin.gestor.estadual');
        Route::post('/pesquisa-satisfacao/analise-ia', [\App\Http\Controllers\Admin\RelatorioController::class, 'pesquisaSatisfacaoAnaliseIA'])->name('pesquisa-satisfacao.analise-ia')->middleware('admin.gestor.estadual');
        Route::get('/processos', [\App\Http\Controllers\Admin\RelatorioController::class, 'processos'])->name('processos');
        Route::get('/usuarios', [\App\Http\Controllers\Admin\RelatorioController::class, 'usuarios'])->name('usuarios');
        Route::get('/acoes-atividade', [\App\Http\Controllers\Admin\RelatorioController::class, 'acoesPorAtividade'])->name('acoes-atividade');
    });
    
    // Sugestões do Sistema
    Route::prefix('sugestoes')->name('sugestoes.')->group(function () {
        Route::get('/', [\App\Http\Controllers\Admin\SugestaoController::class, 'index'])->name('index');
        Route::post('/', [\App\Http\Controllers\Admin\SugestaoController::class, 'store'])->name('store');
        Route::get('/estatisticas', [\App\Http\Controllers\Admin\SugestaoController::class, 'estatisticas'])->name('estatisticas');
        Route::get('/{sugestao}', [\App\Http\Controllers\Admin\SugestaoController::class, 'show'])->name('show');
        Route::put('/{sugestao}', [\App\Http\Controllers\Admin\SugestaoController::class, 'update'])->name('update');
        Route::delete('/{sugestao}', [\App\Http\Controllers\Admin\SugestaoController::class, 'destroy'])->name('destroy');
        Route::post('/{sugestao}/checklist/toggle', [\App\Http\Controllers\Admin\SugestaoController::class, 'toggleChecklistItem'])->name('checklist.toggle');
        Route::post('/{sugestao}/checklist/add', [\App\Http\Controllers\Admin\SugestaoController::class, 'addChecklistItem'])->name('checklist.add');
        Route::post('/{sugestao}/checklist/remove', [\App\Http\Controllers\Admin\SugestaoController::class, 'removeChecklistItem'])->name('checklist.remove');
    });
});

// Rota temporária para consulta de CNPJ (sem middleware CSRF para AJAX)
Route::post('/api/consultar-cnpj', [App\Http\Controllers\Api\CnpjController::class, 'consultar'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
Route::post('/api/consultar-cnpj-atualizado', [App\Http\Controllers\Api\CnpjController::class, 'consultarAtualizado'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// Rotas da API como rotas web para resolver problema de subdiretório
Route::get('/api/verificar-cnpj/{cnpj}', [App\Http\Controllers\Api\CnpjController::class, 'verificarExistente'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
Route::post('/api/verificar-competencia', [App\Http\Controllers\Api\CnpjController::class, 'verificarCompetencia'])->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);

// EXEMPLO: Rota para testar documentos aplicáveis a um estabelecimento
Route::get('/api/estabelecimentos/{id}/documentos-aplicaveis', [App\Http\Controllers\EstabelecimentoController::class, 'buscarDocumentosAplicaveis'])->name('estabelecimentos.documentos-aplicaveis');

// ROTA DE TESTE - DEBUG DE AUTENTICAÇÃO (REMOVER EM PRODUÇÃO)
Route::get('/test-auth-debug', function () {
    return response()->json([
        'interno' => [
            'autenticado' => auth('interno')->check(),
            'usuario_id' => auth('interno')->id(),
            'usuario_nome' => auth('interno')->user()?->nome,
            'usuario_email' => auth('interno')->user()?->email,
        ],
        'externo' => [
            'autenticado' => auth('externo')->check(),
            'usuario_id' => auth('externo')->id(),
        ],
        'web' => [
            'autenticado' => auth('web')->check(),
        ],
        'session' => [
            'has_session' => session()->has('_token'),
            'session_id' => session()->getId(),
        ],
        'guards_disponiveis' => array_keys(config('auth.guards')),
        'default_guard' => config('auth.defaults.guard'),
    ]);
});

/*
|--------------------------------------------------------------------------
| Rotas Protegidas - Área da Empresa (Usuários Externos)
|--------------------------------------------------------------------------
*/
