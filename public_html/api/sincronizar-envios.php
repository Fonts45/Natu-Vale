<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/envios-store.php';
function syncReply(int $status, array $body): never {
    http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); syncReply(405, ['ok'=>false]); }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 4000000) syncReply(413, ['ok'=>false]);
try {
    $private = nvcPrivateDirectory();
    if (!$private || !is_file($private . '/envios-sync.php')) syncReply(503, ['ok'=>false,'message'=>'Sincronização não configurada.']);
    $config = require $private . '/envios-sync.php';
    $secret = $config['secret'] ?? '';
    if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) syncReply(503, ['ok'=>false]);
    $timestamp = $_SERVER['HTTP_X_NVC_TIME'] ?? '';
    $signature = $_SERVER['HTTP_X_NVC_SIGNATURE'] ?? '';
    $body = file_get_contents('php://input', false, null, 0, 4000001);
    if (strlen($body) > 4000000) syncReply(413, ['ok'=>false]);
    if (!preg_match('/^\d{10}$/D', $timestamp) || abs(time() - (int)$timestamp) > 300 || !hash_equals(hash_hmac('sha256', $timestamp . "\n" . $body, $secret), $signature)) syncReply(401, ['ok'=>false,'message'=>'Assinatura inválida ou relógio fora de sincronia.']);
    $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_keys($payload) !== ['rows'] || !is_array($payload['rows'])) syncReply(422, ['ok'=>false]);
    $index = nvcShipmentIndex($payload['rows']);
    $lock = fopen($private . '/envios-sync.lock', 'c+');
    if (!$lock || !flock($lock, LOCK_EX)) throw new RuntimeException('lock');
    $lastTime = (int)stream_get_contents($lock);
    if ((int)$timestamp < $lastTime) { fclose($lock); syncReply(409, ['ok'=>false,'message'=>'Atualização anterior à última recebida.']); }
    $now = gmdate('c');
    $temp = tempnam($private, 'envios-');
    if ($temp === false) throw new RuntimeException('write');
    try {
        $written = file_put_contents($temp, json_encode(['updatedAt'=>$now,'invoices'=>$index], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        if ($written === false || !chmod($temp, 0600) || !rename($temp, $private . '/envios.json')) throw new RuntimeException('write');
    } finally { if (is_file($temp)) unlink($temp); }
    rewind($lock); ftruncate($lock,0); fwrite($lock, $timestamp); fflush($lock); fclose($lock);
    syncReply(200, ['ok'=>true,'invoices'=>count($index),'ambiguous'=>count(array_filter($index,fn($carriers)=>count($carriers)>1)),'updatedAt'=>$now]);
} catch (InvalidArgumentException | JsonException $error) {
    syncReply(422, ['ok'=>false,'message'=>'Conteúdo inválido; o último envio válido foi preservado.']);
} catch (Throwable $error) {
    error_log('NVC shipment sync: storage/configuration unavailable');
    syncReply(503, ['ok'=>false,'message'=>'Não foi possível salvar a atualização.']);
}
