<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$accessToken = getenv('MP_ACCESS_TOKEN');
if (!$accessToken) {
    http_response_code(503);
    echo json_encode(['error' => 'MercadoPago todavía no está configurado en el servidor.']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!$input || empty($input['items'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Datos inválidos']);
    exit;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '';
$siteUrl = $protocol . '://' . $host;

$customer = $input['customer'] ?? [];
$payer = [
    'name'    => (string)($customer['nombre']    ?? ''),
    'surname' => (string)($customer['apellido']  ?? ''),
    'phone'   => ['number' => (string)($customer['telefono'] ?? '')],
];

$items = array_map(function ($i) {
    return [
        'title'       => (string)($i['name']  ?? 'Producto'),
        'quantity'    => (int)   ($i['qty']   ?? 1),
        'unit_price'  => (float) ($i['price'] ?? 0),
        'currency_id' => 'ARS',
    ];
}, $input['items']);

$preferenceData = [
    'items'              => $items,
    'payer'              => $payer,
    'back_urls'          => [
        'success' => $siteUrl . '/pago-exitoso.html',
        'pending' => $siteUrl . '/pago-pendiente.html',
        'failure' => $siteUrl . '/pago-rechazado.html',
    ],
    'auto_return'        => 'approved',
    'statement_descriptor' => 'SHINE AURA',
    'external_reference' => 'sa_' . time(),
    'metadata'           => [
        'direccion' => (string)($customer['direccion'] ?? ''),
        'barrio'    => (string)($customer['barrio']    ?? ''),
        'cp'        => (string)($customer['cp']        ?? ''),
        'nota'      => (string)($input['nota']         ?? ''),
    ],
];

$ch = curl_init('https://api.mercadopago.com/checkout/preferences');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($preferenceData),
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

if ($httpCode >= 200 && $httpCode < 300) {
    $data = json_decode($response, true);
    echo json_encode([
        'init_point' => $data['init_point'] ?? null,
        'id'         => $data['id'] ?? null,
    ]);
    exit;
}

http_response_code(502);
echo json_encode([
    'error'     => 'No se pudo crear la preferencia en MercadoPago',
    'http_code' => $httpCode,
    'details'   => json_decode($response, true) ?: $response,
    'curl'      => $curlErr,
]);
