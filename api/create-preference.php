<?php
require_once __DIR__ . '/db.php';

// Logging de errores inesperados (fatales) para que el cliente reciba JSON y no HTML.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        @file_put_contents(__DIR__ . '/../data/mp-preference.log',
            '[' . date('Y-m-d H:i:s') . "] FATAL: " . $err['message'] . ' at ' . $err['file'] . ':' . $err['line'] . "\n",
            FILE_APPEND);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Error interno del servidor', 'detail' => $err['message']]);
        }
    }
});

function sa_pref_log(string $msg): void {
    @file_put_contents(__DIR__ . '/../data/mp-preference.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

// Crea la preferencia de MP para una venta ya registrada en la BD.
// Body: {orderCode: "SA-XXX-XXX"}  — la venta ya debe existir (fue creada por /api/sales.php).
// Devuelve {init_point, preferenceId, orderCode}.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sa_fail('Método no permitido', 405);

if (!function_exists('curl_init')) sa_fail('El servidor no tiene la extensión cURL de PHP instalada', 500);

$accessToken = getenv('MP_ACCESS_TOKEN');
if (!$accessToken) sa_fail('MercadoPago todavía no está configurado en el servidor (falta MP_ACCESS_TOKEN).', 503);

$in = sa_read_json_body();
$orderCode = trim((string)($in['orderCode'] ?? ''));
if ($orderCode === '') sa_fail('Falta orderCode', 400);

$pdo = sa_db();
$stmt = $pdo->prepare("SELECT * FROM sales WHERE order_code = :c");
$stmt->execute([':c' => $orderCode]);
$sale = $stmt->fetch();
if (!$sale) sa_fail('Orden no encontrada', 404);
if ($sale['payment_method'] !== 'mp') sa_fail('Esta orden no es de MercadoPago', 400);

$items = json_decode($sale['items'], true) ?: [];
$siteUrl = sa_site_url();

$mpItems = array_map(function ($i) {
    return [
        'title'       => (string)($i['name']  ?? 'Producto'),
        'quantity'    => (int)($i['qty']   ?? 1),
        'unit_price'  => (float)($i['price'] ?? 0),
        'currency_id' => 'ARS',
    ];
}, $items);

// MP permite sumar el envío como item separado si existiera.
if ((int)$sale['shipping'] > 0) {
    $mpItems[] = [
        'title'       => 'Envío',
        'quantity'    => 1,
        'unit_price'  => (float)$sale['shipping'],
        'currency_id' => 'ARS',
    ];
}

$preferenceData = [
    'items'                => $mpItems,
    'payer'                => [
        'name'    => (string)$sale['customer_name'],
        'surname' => (string)$sale['customer_lastname'],
        'phone'   => ['number' => (string)$sale['customer_phone']],
    ],
    'back_urls'            => [
        'success' => $siteUrl . '/pago-exitoso.html?order=' . urlencode($orderCode),
        'pending' => $siteUrl . '/pago-pendiente.html?order=' . urlencode($orderCode),
        'failure' => $siteUrl . '/pago-rechazado.html?order=' . urlencode($orderCode),
    ],
    'auto_return'          => 'approved',
    'statement_descriptor' => 'SHINE AURA',
    'external_reference'   => $orderCode,
    'notification_url'     => $siteUrl . '/api/mp-webhook.php',
    'metadata'             => [
        'order_code' => $orderCode,
        'direccion'  => (string)$sale['customer_address'],
        'barrio'     => (string)$sale['customer_district'],
        'cp'         => (string)$sale['customer_zip'],
        'nota'       => (string)$sale['customer_notes'],
    ],
];

sa_pref_log("REQUEST orderCode={$orderCode} siteUrl={$siteUrl}");

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($preferenceData, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'User-Agent: ShineAura/1.0',
    ],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_FOLLOWLOCATION => false,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
$curlErrno = curl_errno($ch);
curl_close($ch);

sa_pref_log("RESPONSE http={$httpCode} curlErrno={$curlErrno} curlErr={$curlErr} body=" . substr((string)$response, 0, 800));

if ($curlErrno !== 0) {
    sa_fail('No pudimos conectar con MercadoPago: ' . $curlErr, 502, [
        'curl_errno' => $curlErrno,
    ]);
}

if ($httpCode < 200 || $httpCode >= 300) {
    $parsed = json_decode($response, true);
    $mpMsg = is_array($parsed) && isset($parsed['message']) ? $parsed['message'] : 'Error desconocido';
    sa_fail('MercadoPago rechazó la preferencia: ' . $mpMsg, 502, [
        'http_code' => $httpCode,
        'details'   => $parsed ?: $response,
    ]);
}

$data = json_decode($response, true);
$prefId = $data['id'] ?? null;
$initPoint = $data['init_point'] ?? null;

if ($prefId) {
    $upd = $pdo->prepare("UPDATE sales SET mp_preference_id = :p, updated_at = datetime('now') WHERE id = :id");
    $upd->execute([':p' => $prefId, ':id' => $sale['id']]);
}

sa_json([
    'init_point'   => $initPoint,
    'preferenceId' => $prefId,
    'orderCode'    => $orderCode,
]);
