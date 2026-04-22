<?php
require_once __DIR__ . '/db.php';

// Crea la preferencia de MP para una venta ya registrada en la BD.
// Body: {orderCode: "SA-XXX-XXX"}  — la venta ya debe existir (fue creada por /api/sales.php).
// Devuelve {init_point, preferenceId, orderCode}.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sa_fail('Método no permitido', 405);

$accessToken = getenv('MP_ACCESS_TOKEN');
if (!$accessToken) sa_fail('MercadoPago todavía no está configurado en el servidor.', 503);

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

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($preferenceData, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($httpCode < 200 || $httpCode >= 300) {
    sa_fail('No se pudo crear la preferencia en MercadoPago', 502, [
        'http_code' => $httpCode,
        'details'   => json_decode($response, true) ?: $response,
        'curl'      => $curlErr,
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
