<?php
/**
 * Funções comuns dos endpoints. Sem framework, compatível com PHP 7.2+.
 * Os dados ficam em /www/_dados (bloqueado por .htaccess), em arquivos JSONL
 * mensais, apagados depois de RETENCAO_DIAS.
 */
if (!defined('DIB')) { http_response_code(404); exit; }

const DOMINIO = 'despachanteimoveisbrasilia.com.br';
const RETENCAO_DIAS = 180;
/** E-mail para aviso de novos pedidos. Vazio: só grava no servidor (decisão do proprietário, 26/09/2026). */
const LEAD_EMAIL = '';

function responder($status, $corpo) {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  header('X-Robots-Tag: noindex, nofollow');
  echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
  exit;
}

function pasta_dados($sub) {
  $dir = dirname(__DIR__) . '/_dados/' . $sub;
  if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
  return $dir;
}

function origem_valida() {
  $origem = '';
  if (!empty($_SERVER['HTTP_ORIGIN'])) { $origem = $_SERVER['HTTP_ORIGIN']; }
  elseif (!empty($_SERVER['HTTP_REFERER'])) { $origem = $_SERVER['HTTP_REFERER']; }
  if ($origem === '') return false;
  $host = parse_url($origem, PHP_URL_HOST);
  return $host === DOMINIO || $host === 'www.' . DOMINIO;
}

function ip_hash() {
  $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
  // Hash com sal diário: permite limitar abuso sem guardar o IP.
  return substr(hash('sha256', $ip . '|' . date('Y-m-d') . '|' . DOMINIO), 0, 16);
}

function limite_taxa($chave, $max, $janela_seg) {
  $dir = pasta_dados('limite');
  $arq = $dir . '/' . preg_replace('/[^a-z0-9_]/', '', $chave) . '.json';
  $agora = time();
  $hits = [];
  if (is_file($arq)) {
    $hits = json_decode((string) @file_get_contents($arq), true);
    if (!is_array($hits)) $hits = [];
  }
  $hits = array_values(array_filter($hits, function ($t) use ($agora, $janela_seg) { return $t > $agora - $janela_seg; }));
  if (count($hits) >= $max) return false;
  $hits[] = $agora;
  @file_put_contents($arq, json_encode($hits), LOCK_EX);
  return true;
}

/** Texto limpo: sem tags, sem caracteres de controle, tamanho limitado. */
function limpar($v, $max) {
  if (!is_string($v)) return '';
  $v = strip_tags($v);
  $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v);
  $v = trim((string) $v);
  return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

/** Minimização: remove CPF, CNPJ e sequências numéricas longas digitadas por engano em texto livre. */
function mascarar_identificadores($v) {
  $v = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[removido]', $v);
  $v = preg_replace('/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/', '[removido]', $v);
  return preg_replace('/\b\d{9,}\b/', '[removido]', $v);
}

function gravar_jsonl($sub, $registro) {
  $dir = pasta_dados($sub);
  $ok = @file_put_contents($dir . '/' . date('Y-m') . '.jsonl', json_encode($registro, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
  // Retenção: apaga arquivos mensais mais antigos que RETENCAO_DIAS.
  foreach ((array) glob($dir . '/*.jsonl') as $f) {
    if (is_file($f) && filemtime($f) < time() - RETENCAO_DIAS * 86400) @unlink($f);
  }
  return $ok !== false;
}
