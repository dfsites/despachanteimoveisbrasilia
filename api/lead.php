<?php
/**
 * Recebe o pedido do formulário (POST JSON) e grava em /_dados/leads/AAAA-MM.jsonl.
 * Resposta: {"ok":true,"id":"..."}. Nenhum dado pessoal volta na resposta.
 */
define('DIB', 1);
require __DIR__ . '/_comum.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'erro' => 'metodo']);
if (!origem_valida()) responder(403, ['ok' => false, 'erro' => 'origem']);
$bruto = file_get_contents('php://input', false, null, 0, 20000);
$dados = json_decode((string) $bruto, true);
if (!is_array($dados) || empty($dados['consent'])) responder(400, ['ok' => false, 'erro' => 'dados']);
if (!limite_taxa('lead_' . ip_hash(), 6, 3600)) responder(429, ['ok' => false, 'erro' => 'limite']);

$campos = (isset($dados['fields']) && is_array($dados['fields'])) ? $dados['fields'] : [];
$meta = (isset($dados['meta']) && is_array($dados['meta'])) ? $dados['meta'] : [];
if (isset($meta['elapsed_ms']) && (int) $meta['elapsed_ms'] < 2000) responder(400, ['ok' => false, 'erro' => 'tempo']);

$limpo = [];
foreach ($campos as $k => $v) {
  $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
  if ($k === '' || count($limpo) >= 20) continue;
  $v = limpar($v, $k === 'mensagem' ? 800 : 120);
  if ($k !== 'telefone') $v = mascarar_identificadores($v);
  $limpo[$k] = $v;
}
if (empty($limpo['nome']) || empty($limpo['telefone'])) responder(422, ['ok' => false, 'erro' => 'obrigatorios']);

$permitidos = ['form_id', 'form_type', 'service_id', 'service_name', 'content_cluster', 'page_category', 'page_title', 'source_page', 'source_site', 'landing_page', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid', 'wbraid', 'gbraid', 'client_ts'];
$m = [];
foreach ($permitidos as $k) {
  if (isset($meta[$k])) $m[$k] = limpar((string) $meta[$k], 200);
}

$id = date('Ymd-His') . '-' . bin2hex(random_bytes(4));
$registro = [
  'id' => $id,
  'recebido_em' => date('c'),
  'campos' => $limpo,
  'atribuicao' => $m,
  'ip_hash' => ip_hash(),
  'navegador' => limpar(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '', 120),
];
if (!gravar_jsonl('leads', $registro)) responder(500, ['ok' => false, 'erro' => 'gravacao']);

if (LEAD_EMAIL !== '') {
  $assunto = 'Novo pedido: ' . (isset($m['service_name']) ? $m['service_name'] : 'site');
  $linhas = [];
  foreach ($limpo as $k => $v) $linhas[] = $k . ': ' . $v;
  $linhas[] = '';
  foreach ($m as $k => $v) $linhas[] = $k . ': ' . $v;
  @mail(LEAD_EMAIL, '=?UTF-8?B?' . base64_encode($assunto) . '?=', implode("\n", $linhas), "Content-Type: text/plain; charset=UTF-8\r\nFrom: site@" . DOMINIO);
}
responder(200, ['ok' => true, 'id' => $id]);
