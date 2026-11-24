<?php
declare(strict_types=1);

// Credentials and cached tokens stay OUTSIDE the public website directory.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-NVC-Tracking-Version: 20261105-auto');

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(int $status, string $message): never { respond($status, ['ok' => false, 'message' => $message]); }
function writeState($file, array $state): void {
    rewind($file); ftruncate($file, 0);
    fwrite($file, json_encode($state)); fflush($file);
}
function readState($file): array {
    rewind($file); return json_decode(stream_get_contents($file), true) ?: [];
}
function requestJamef(string $url, ?string $token = null, ?array $body = null): array {
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 22, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $failed = $raw === false;
    $curlCode = curl_errno($ch);
    curl_close($ch);
    if ($failed) throw new RuntimeException('carrier_network_' . $curlCode);
    return [$status, json_decode((string) $raw, true)];
}
function normalizeShipments(array $payload): array {
    $result = [];
    foreach (($payload['dado'] ?? []) as $group) {
        foreach (($group['rastreamento'] ?? []) as $shipment) {
            $events = [];
            foreach (($shipment['eventosRastreio'] ?? []) as $event) {
                $events[] = ['date' => (string)($event['data'] ?? ''),
                    'status' => (string)($event['status'] ?? ''),
                    'city' => (string)($event['localOrigem']['cidade'] ?? ''),
                    'state' => (string)($event['localOrigem']['uf'] ?? '')];
            }
            usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
            // Do not expose names, document keys, freight prices or delivery signatures.
            $result[] = ['invoice' => (string)($shipment['notaFiscal']['numero'] ?? ''),
                'origin' => ['city' => (string)($shipment['remetente']['cidade'] ?? ''), 'state' => (string)($shipment['remetente']['uf'] ?? '')],
                'destination' => ['city' => (string)($shipment['destinatario']['cidade'] ?? ''), 'state' => (string)($shipment['destinatario']['uf'] ?? '')],
                'estimatedDelivery' => (string)($shipment['frete']['previsaoEntrega'] ?? ''), 'events' => $events];
        }
    }
    return $result;
}
function trackingQuery(string $sender, string $document, string $invoice): array {
    $nvcSenders = ['02781113000105', '20459052000138', '30972851000167'];
    if (in_array($document, $nvcSenders, true)) {
        return ['documentoRemetente' => $document, 'numeroNotaFiscal' => $invoice];
    }
    $query = ['documentoRemetente' => $sender, 'numeroNotaFiscal' => $invoice];
    if ($document !== $sender) $query['documentoDestinatario'] = $document;
    return $query;
}
function authenticationToken(int $status, ?array $payload): ?string {
    $token = $payload['dado'][0]['accessToken'] ?? null;
    return in_array($status, [200, 201], true) && is_string($token) && $token !== '' ? $token : null;
}

// Lets the local verification script test normalization without contacting the carrier.
if (defined('NVC_TRACKING_LIBRARY_ONLY')) return;
try {
    require_once __DIR__ . '/envios-store.php';
    require_once __DIR__ . '/braspress-tracking.php';
} catch (Throwable $error) {
    error_log('NVC tracking: incomplete public deployment');
    fail(503, 'A atualização do rastreamento está incompleta. A Natu Vale precisa conferir os arquivos da integração. Referência: NVC-COMPONENTS.');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); fail(405, 'Use o formulário de rastreamento.'); }
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048) fail(413, 'Solicitação inválida.');
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = parse_url($_SERVER['HTTP_ORIGIN']);
    $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
    if (!is_array($origin) || strcasecmp($origin['host'] ?? '', $host) !== 0) fail(403, 'Origem não permitida.');
}
$input = json_decode(file_get_contents('php://input', false, null, 0, 2049), true);
if (!is_array($input)) fail(400, 'Solicitação inválida.');
$document = preg_replace('/\D/', '', (string)($input['cnpj'] ?? ''));
$invoice = trim((string)($input['nf'] ?? ''));
if (!preg_match('/^\d{14}$/D', $document) || !preg_match('/^\d{1,9}$/D', $invoice)) fail(422, 'Informe o CNPJ com 14 dígitos e uma NF válida.');

try {
    $publicRoot = realpath(dirname(__DIR__));
    $privateDir = getenv('NVC_PRIVATE_DIR') ?: dirname($publicRoot) . '/nvc-private';
    $privateReal = realpath($privateDir);
    // Refuse to put secrets or token caches inside the published website.
    if (!$privateReal || str_starts_with(str_replace('\\', '/', $privateReal) . '/', str_replace('\\', '/', $publicRoot) . '/')) fail(503, 'O rastreamento está sendo configurado. Tente novamente em breve.');
    $cacheDir = $privateReal . '/cache';
    if (!is_dir($cacheDir) && !mkdir($cacheDir, 0700)) throw new RuntimeException('cache_missing');
    // Per-IP abuse limit; use REMOTE_ADDR, never an untrusted forwarded header.
    $rate = fopen($cacheDir . '/rate-' . hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown') . '.json', 'c+');
    if (!$rate || !flock($rate, LOCK_EX)) throw new RuntimeException('rate_lock');
    $hits = array_values(array_filter(readState($rate), fn($time) => is_int($time) && $time > time() - 600));
    if (count($hits) >= 20) { fclose($rate); header('Retry-After: 600'); fail(429, 'Muitas consultas. Aguarde alguns minutos para tentar novamente.'); }
    $hits[] = time(); writeState($rate, $hits); fclose($rate);
    $route = nvcFindCarrier($privateReal, $invoice);
    if ($route !== null) {
        if ($route['kind'] === 'missing') fail(404, 'Esta nota fiscal ainda não consta na lista de envios. Confira o número ou entre em contato com a Natu Vale.');
        if ($route['kind'] === 'ambiguous') fail(409, 'Há mais de uma transportadora registrada para esta nota fiscal. Entre em contato com a Natu Vale para confirmar o envio.');
        if ($route['kind'] === 'other') respond(200, ['ok'=>true, 'carrier'=>$route['carrier'], 'shipments'=>[], 'updatedAt'=>$route['updatedAt'], 'message'=>'Transportadora do pedido: ' . $route['carrier'] . '. O acompanhamento automático desta transportadora ainda não está disponível no site. Entre em contato com a Natu Vale.']);
    }
    try { $provider = nvcTrackingProvider($route); }
    catch (InvalidArgumentException $error) {
        fail(503, 'A lista de envios está sendo atualizada. Tente novamente em alguns minutos. Referência: NVC-ENVIOS.');
    }
    if ($provider === 'braspress') respond(200, consultBraspress($privateReal, $cacheDir, $document, $invoice));
    $configFile = $privateReal . '/jamef.php';
    if (!is_file($configFile)) fail(503, 'O rastreamento está sendo configurado. Tente novamente em breve.');
    $config = require $configFile;
    if (!is_array($config) || empty($config['username']) || empty($config['password'])) fail(503, 'O rastreamento está sendo configurado. Tente novamente em breve.');
    if (!extension_loaded('curl')) throw new RuntimeException('curl_missing');
    $sender = preg_replace('/\D/', '', (string)($config['sender_cnpj'] ?? ''));
    if (!preg_match('/^\d{14}$/D', $sender)) throw new RuntimeException('sender_missing');
    $key = hash('sha256', $document . ':' . $invoice);
    $resultFile = $cacheDir . '/result-' . $key . '.json';
    $cached = is_file($resultFile) ? json_decode(file_get_contents($resultFile), true) : null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time()) respond(200, $cached['response']);
    // Serialize auth and carrier calls across clients; honor the carrier's global quota.
    $lock = fopen($cacheDir . '/jamef-state.json', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { header('Retry-After: 3'); fail(429, 'Há uma consulta em andamento. Aguarde alguns segundos e tente novamente.'); }
    $state = readState($lock);
    $base = ($config['environment'] ?? 'production') === 'homologation' ? 'https://api-qa.jamef.com.br' : 'https://api.jamef.com.br';
    $identity = hash('sha256', $base . ':' . $config['username'] . ':' . $config['password']);
    if (($state['identity'] ?? '') !== $identity) $state = ['identity' => $identity, 'lastLogin' => $state['lastLogin'] ?? 0, 'lastQuery' => $state['lastQuery'] ?? 0];
    if (empty($state['token']) || ($state['expires'] ?? 0) < time() + 60) {
        if (($state['lastLogin'] ?? 0) > time() - 61) { fclose($lock); header('Retry-After: 61'); fail(503, 'A conexão com a transportadora está sendo renovada. Tente novamente em um minuto.'); }
        $state['lastLogin'] = time(); writeState($lock, $state);
        [$status, $auth] = requestJamef($base . '/auth/v1/login', null, ['username' => $config['username'], 'password' => $config['password']]);
        $token = authenticationToken($status, is_array($auth) ? $auth : null);
        if ($token === null) throw new RuntimeException('carrier_auth_' . $status);
        $state['token'] = $token; $state['expires'] = time() + min(3600, (int)($auth['dado'][0]['expiresIn'] ?? 3600));
        writeState($lock, $state);
    }
    if (($state['lastQuery'] ?? 0) > microtime(true) - 2.1) { fclose($lock); header('Retry-After: 3'); fail(429, 'Aguarde alguns segundos entre as consultas.'); }
    $state['lastQuery'] = microtime(true); writeState($lock, $state);
    $query = http_build_query(trackingQuery($sender, $document, $invoice));
    [$status, $payload] = requestJamef($base . '/consulta/v1/rastreamento?' . $query, $state['token']);
    if ($status === 401) { unset($state['token']); writeState($lock, $state); }
    fclose($lock);
    if ($status === 429) { header('Retry-After: 5'); fail(429, 'A transportadora está recebendo muitas consultas. Aguarde e tente novamente.'); }
    if ($status === 404) fail(404, 'Nenhuma entrega encontrada para esse CNPJ e NF. Confira os dados.');
    if ($status !== 200 || !is_array($payload) || (isset($payload['situacao']) && (int)$payload['situacao'] !== 200)) throw new RuntimeException('carrier_query_' . $status);
    $shipments = normalizeShipments($payload);
    if (!$shipments) fail(404, 'Nenhuma entrega encontrada para esse CNPJ e NF. Confira os dados.');
    $response = ['ok' => true, 'carrier' => 'Jamef', 'shipments' => $shipments, 'updatedAt' => gmdate('c')];
    file_put_contents($resultFile, json_encode(['expires' => time() + 120, 'response' => $response]), LOCK_EX);
    respond(200, $response);
} catch (Throwable $error) {
    if ($error instanceof BraspressTrackingError) {
        error_log('NVC tracking unavailable: ' . $error->reference);
        if ($error->httpStatus === 429) header('Retry-After: 5');
        fail($error->httpStatus, $error->getMessage() . ' Referência: ' . $error->reference . '.');
    }
    // No API response, password, CNPJ or token is written to the public response or logs.
    $reason = $error->getMessage();
    $code = 'NVC-SERVER';
    $message = 'Não foi possível consultar a Jamef agora. Tente novamente em alguns minutos.';
    if ($error instanceof ParseError) {
        $code = 'NVC-CONFIG'; $message = 'A configuração do rastreamento precisa ser revisada pela Natu Vale.';
    } elseif (preg_match('/^carrier_auth_(\d+)$/D', $reason, $matches)) {
        $code = 'JAMEF-LOGIN-' . $matches[1];
        $message = $matches[1] === '429' ? 'A Jamef limitou temporariamente o login. Aguarde um minuto e tente novamente.' : 'Não foi possível autenticar a integração na Jamef. A Natu Vale precisa verificar o acesso de produção.';
    } elseif (preg_match('/^carrier_query_(\d+)$/D', $reason, $matches)) {
        $code = 'JAMEF-QUERY-' . $matches[1];
    } elseif (preg_match('/^carrier_network_(\d+)$/D', $reason, $matches)) {
        $code = 'JAMEF-CONNECTION-' . $matches[1];
        $message = 'O servidor não conseguiu se conectar à Jamef. Tente novamente em alguns minutos.';
    } elseif (in_array($reason, ['cache_missing', 'rate_lock', 'curl_missing', 'sender_missing'], true)) {
        $code = ['cache_missing' => 'NVC-CACHE', 'rate_lock' => 'NVC-CACHE', 'curl_missing' => 'NVC-CURL', 'sender_missing' => 'NVC-CONFIG'][$reason];
        $message = 'A configuração do rastreamento precisa ser revisada pela Natu Vale.';
    }
    error_log('NVC tracking unavailable: ' . $code);
    fail(503, $message . ' Referência: ' . $code . '.');
}
