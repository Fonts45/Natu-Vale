<?php
declare(strict_types=1);

final class BraspressTrackingError extends RuntimeException {
    public function __construct(public readonly int $httpStatus, public readonly string $reference, string $publicMessage) {
        parent::__construct($publicMessage);
    }
}
function braspressDate(mixed $value): string {
    if (!is_string($value) || trim($value) === '') return '';
    $value = trim($value);
    foreach (['!d/m/Y H:i:s'=>'Y-m-d\TH:i:s', '!d/m/Y H:i'=>'Y-m-d\TH:i:s', '!d/m/Y'=>'Y-m-d', '!Y-m-d H:i:s'=>'Y-m-d\TH:i:s', '!Y-m-d'=>'Y-m-d'] as $format=>$output) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date->format($output);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?$/D', $value)) {
        try { return (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s'); } catch (Throwable) {}
    }
    return '';
}
function normalizeBraspressShipments(array $payload, string $invoice): array {
    if (!isset($payload['conhecimentos']) || !is_array($payload['conhecimentos'])) {
        throw new BraspressTrackingError(502, 'BRASPRESS-RESPONSE', 'A Braspress retornou uma resposta que não foi possível interpretar.');
    }
    $result = [];
    foreach ($payload['conhecimentos'] as $shipment) {
        if (!is_array($shipment)) continue;
        $matchesInvoice = false;
        foreach (($shipment['notasFiscais'] ?? []) as $note) {
            if (!is_array($note)) continue;
            $number = trim((string)($note['numero'] ?? ''));
            if (preg_match('/^\d{1,9}$/D', $number) && nvcInvoice($number) === nvcInvoice($invoice)) $matchesInvoice = true;
        }
        // The carrier can include several notes in a knowledge; only return the requested one.
        if (!$matchesInvoice) continue;
        $events = [];
        foreach (array_merge($shipment['timeLine'] ?? $shipment['timeline'] ?? [], $shipment['ocorrencias'] ?? []) as $event) {
            if (!is_array($event)) continue;
            $description = trim((string)($event['descricao'] ?? ''));
            if ($description === '') continue;
            $date = braspressDate($event['data'] ?? '');
            $key = $date . ':' . $description;
            $events[$key] = ['date'=>$date, 'status'=>$description, 'city'=>'', 'state'=>''];
        }
        if (!$events && !empty($shipment['status'])) {
            $events[] = ['date'=>braspressDate($shipment['dataOcorrencia'] ?? ''), 'status'=>(string)($shipment['ultimaOcorrencia'] ?? $shipment['status']), 'city'=>'', 'state'=>''];
        }
        $events = array_values($events);
        usort($events, fn($a,$b)=>strcmp($a['date'], $b['date']));
        $result[] = ['invoice'=>$invoice, 'carrier'=>'Braspress',
            'origin'=>['city'=>(string)($shipment['cidadeColeta'] ?? ''), 'state'=>(string)($shipment['ufColeta'] ?? '')],
            'destination'=>['city'=>(string)($shipment['cidade'] ?? ''), 'state'=>(string)($shipment['uf'] ?? '')],
            'estimatedDelivery'=>braspressDate($shipment['previsaoEntrega'] ?? ''),
            'deliveredAt'=>braspressDate($shipment['dataEntrega'] ?? ''), 'events'=>$events];
    }
    // No names, invoice keys, prices, weights or private documents are sent to the browser.
    return $result;
}
function consultBraspress(string $private, string $cacheDir, string $document, string $invoice): array {
    $file = $private . '/braspress.php';
    if (!is_file($file)) throw new BraspressTrackingError(503, 'BRASPRESS-CONFIG', 'O rastreamento da Braspress está sendo configurado.');
    $config = require $file;
    if (!is_array($config) || !is_string($config['username'] ?? null) || !is_string($config['password'] ?? null) || $config['username'] === '' || $config['password'] === '' || str_contains($config['username'], ':')) {
        throw new BraspressTrackingError(503, 'BRASPRESS-CONFIG', 'A configuração da Braspress precisa ser revisada pela Natu Vale.');
    }
    if (!extension_loaded('curl')) throw new BraspressTrackingError(503, 'NVC-CURL', 'A configuração do rastreamento precisa ser revisada pela Natu Vale.');
    $identity = hash('sha256', $config['username'] . ':' . $config['password']);
    $key = hash('sha256', $identity . ':' . $document . ':' . nvcInvoice($invoice));
    $resultFile = $cacheDir . '/braspress-result-' . $key . '.json';
    $cached = is_file($resultFile) ? json_decode(file_get_contents($resultFile), true) : null;
    if (is_array($cached) && ($cached['expires'] ?? 0) > time()) return $cached['response'];
    $lock = fopen($cacheDir . '/braspress-state.json', 'c+');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) throw new BraspressTrackingError(429, 'BRASPRESS-BUSY', 'Há uma consulta em andamento. Aguarde alguns segundos e tente novamente.');
    try {
        $state = json_decode(stream_get_contents($lock), true) ?: [];
        if (($state['lastQuery'] ?? 0) > microtime(true) - 2) throw new BraspressTrackingError(429, 'BRASPRESS-LIMIT', 'Aguarde alguns segundos entre as consultas.');
        rewind($lock); ftruncate($lock, 0); fwrite($lock, json_encode(['lastQuery'=>microtime(true)])); fflush($lock);
        $url = 'https://api.braspress.com/v3/tracking/byNf/' . rawurlencode($document) . '/' . rawurlencode(nvcInvoice($invoice)) . '/json';
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>25,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_HTTPAUTH=>CURLAUTH_BASIC,
            CURLOPT_USERPWD=>$config['username'] . ':' . $config['password'], CURLOPT_HTTPHEADER=>['Accept: application/json']]);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlCode = curl_errno($ch); curl_close($ch);
        if ($raw === false) throw new BraspressTrackingError(503, 'BRASPRESS-CONNECTION-' . $curlCode, 'O servidor não conseguiu se conectar à Braspress. Tente novamente em alguns minutos.');
        if (in_array($status, [401,403], true)) throw new BraspressTrackingError(503, 'BRASPRESS-LOGIN-' . $status, 'A Braspress recusou o acesso. A Natu Vale precisa verificar as credenciais e a liberação do CNPJ do tomador do frete.');
        if ($status === 429) throw new BraspressTrackingError(429, 'BRASPRESS-LIMIT', 'A Braspress limitou temporariamente as consultas. Aguarde e tente novamente.');
        if ($status === 404) throw new BraspressTrackingError(404, 'BRASPRESS-NOT-FOUND', 'Nenhuma entrega encontrada na Braspress para esse CNPJ e NF. Confira o CNPJ do tomador do frete; a consulta abrange os últimos 90 dias de emissão do conhecimento.');
        if ($status !== 200) throw new BraspressTrackingError(503, 'BRASPRESS-QUERY-' . $status, 'Não foi possível consultar a Braspress agora. Tente novamente em alguns minutos.');
        $payload = json_decode($raw, true);
        if (!is_array($payload) || isset($payload['statusCode'])) throw new BraspressTrackingError(502, 'BRASPRESS-RESPONSE', 'A Braspress não retornou os dados da entrega. Confira o CNPJ do tomador do frete e a NF.');
        $shipments = normalizeBraspressShipments($payload, $invoice);
        if (!$shipments) throw new BraspressTrackingError(404, 'BRASPRESS-NOT-FOUND', 'Nenhuma entrega encontrada na Braspress para esse CNPJ e NF. Confira o CNPJ do tomador do frete; a consulta abrange os últimos 90 dias de emissão do conhecimento.');
        $response = ['ok'=>true, 'carrier'=>'Braspress', 'shipments'=>$shipments, 'updatedAt'=>gmdate('c'), 'message'=>'Informações fornecidas pela Braspress.'];
        if (file_put_contents($resultFile, json_encode(['expires'=>time()+120, 'response'=>$response]), LOCK_EX) === false) error_log('NVC Braspress: result cache unavailable');
        return $response;
    } finally { fclose($lock); }
}
