<?php
declare(strict_types=1);

function nvcPrivateDirectory(): ?string {
    $public = realpath(dirname(__DIR__));
    $dir = realpath(getenv('NVC_PRIVATE_DIR') ?: dirname($public) . '/nvc-private');
    if (!$dir || str_starts_with(str_replace('\\', '/', $dir) . '/', str_replace('\\', '/', $public) . '/')) return null;
    return $dir;
}
function nvcInvoice(string $value): string {
    $value = trim($value);
    if (!preg_match('/^\d{1,9}$/D', $value)) throw new InvalidArgumentException('invoice');
    return ltrim($value, '0') ?: '0';
}
function nvcCarrier(string $value): string {
    $value = preg_replace('/\s+/u', ' ', trim($value));
    if ($value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F<>]/', $value)) throw new InvalidArgumentException('carrier');
    return $value;
}
function nvcCarrierKey(string $value): string {
    $key = strtolower(trim($value));
    return preg_replace('/\s+/', ' ', strtr($key, ['Á'=>'a','á'=>'a','ã'=>'a','Ã'=>'a','é'=>'e','É'=>'e','í'=>'i','Í'=>'i','ó'=>'o','Ó'=>'o','ú'=>'u','Ú'=>'u','ç'=>'c','Ç'=>'c']));
}
function nvcShipmentIndex(array $rows): array {
    if (!$rows || count($rows) > 50000) throw new InvalidArgumentException('rows');
    $index = [];
    foreach ($rows as $row) {
        if (!is_array($row) || count($row) !== 2 || !isset($row['nf'], $row['transportadora']) || !is_string($row['nf']) || !is_string($row['transportadora'])) throw new InvalidArgumentException('row');
        $nf = nvcInvoice($row['nf']); $carrier = nvcCarrier($row['transportadora']);
        $index[$nf][nvcCarrierKey($carrier)] = $carrier;
    }
    foreach ($index as &$carriers) $carriers = array_values($carriers);
    unset($carriers);
    return $index;
}
function nvcFindCarrier(string $private, string $invoice): ?array {
    $file = $private . '/envios.json';
    if (!is_file($file)) return null; // Before activation the existing Jamef integration continues unchanged.
    $data = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data['invoices'] ?? null)) throw new RuntimeException('envios_invalid');
    $carriers = $data['invoices'][nvcInvoice($invoice)] ?? [];
    if (count($carriers) > 1) return ['kind'=>'ambiguous', 'updatedAt'=>$data['updatedAt']];
    if (!$carriers) return ['kind'=>'missing', 'updatedAt'=>$data['updatedAt']];
    $carrierKey = nvcCarrierKey($carriers[0]);
    $kind = $carrierKey === 'jamef' ? 'jamef' : (in_array($carrierKey, ['braspress','brasspress'], true) ? 'braspress' : 'other');
    return ['kind'=>$kind, 'carrier'=>$carriers[0], 'updatedAt'=>$data['updatedAt']];
}
function nvcTrackingProvider(?array $route): string {
    if ($route !== null && in_array($route['kind'] ?? '', ['jamef','braspress'], true)) return $route['kind'];
    throw new InvalidArgumentException('carrier_mapping_required');
}
