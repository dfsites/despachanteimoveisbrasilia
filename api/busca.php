<?php
/**
 * Registra termos de busca interna SEM RESULTADO, para descoberta editorial.
 * O termo é descartado se parecer dado pessoal (números longos, e-mail, URL).
 */
define('DIB', 1);
require __DIR__ . '/_comum.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false]);
if (!origem_valida()) responder(403, ['ok' => false]);
if (!limite_taxa('busca_' . ip_hash(), 30, 3600)) responder(429, ['ok' => false]);
$dados = json_decode((string) file_get_contents('php://input', false, null, 0, 2000), true);
$termo = (is_array($dados) && isset($dados['termo'])) ? limpar((string) $dados['termo'], 60) : '';
$termo = function_exists('mb_strtolower') ? mb_strtolower($termo, 'UTF-8') : strtolower($termo);
if ($termo === '' || preg_match('/\d{5,}|@|https?:|www\./', $termo)) responder(200, ['ok' => true]);
gravar_jsonl('buscas-sem-resultado', ['termo' => $termo, 'em' => date('c')]);
responder(200, ['ok' => true]);
