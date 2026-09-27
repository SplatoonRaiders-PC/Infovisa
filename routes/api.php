<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CnpjController;
use App\Http\Controllers\Api\CpfController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Rotas da API para consulta de CNPJ
Route::post('/consultar-cnpj', [CnpjController::class, 'consultar']);
Route::get('/verificar-cnpj/{cnpj}', [CnpjController::class, 'verificarExistente']);

// Rota para consulta de CPF (registro de usuário externo)
Route::post('/consultar-cpf', [CpfController::class, 'consultar'])->middleware('throttle:30,1');

// Rota para verificar competência de atividades
Route::post('/verificar-competencia', [CnpjController::class, 'verificarCompetencia']);
