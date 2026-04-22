<?php
require_once __DIR__ . '/db.php';

// Webhook de MercadoPago: cuando MP confirma/actualiza un pago, nos notifica acá.
// Configurar en MP developers la URL: https://<dominio>/api/mp-webhook.php
// MP nos pasa ?type=payment&data.id=PAYMENT_ID (y también payload en body).
// Nosotros consultamos el pago, vemos su external_reference (que guardamos como el order_code),
// y actualizamos la venta en la BD.

// Responder SIEMPRE 200 rápido — MP reintenta si no, y cualquier error interno lo logueamos pero igual devolvemos OK.
// De todas formas, devolvemos 200 al final.

function sa_log_webhook(string $msg): void {
    $dir = __DIR__ . '/../data';
    @file_put_contents($dir . '/mp-webhook.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

try {
    $raw = file_get_contents('php://input');
    sa_log_webhook('IN query=' . json_encode($_GET) . ' body=' . substr($raw, 0, 2000));

    $type = $_GET['type'] ?? $_GET['topic'] ?? '';
    $paymentId = $_GET['data']['id'] ?? ($_GET['id'] ?? null);
    if (!$paymentId && $raw) {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $type = $type ?: ($json['type'] ?? $json['topic'] ?? '');
            $paymentId = $json['data']['id'] ?? ($json['resource'] ?? $paymentId);
        }
    }

    if ($type !== 'payment' && $type !== 'merchant_order') {
        sa_log_webhook("IGNORE type=$type");
        http_response_code(200); echo 'ignored'; exit;
    }
    if (!$paymentId) {
        sa_log_webhook('MISSING paymentId');
        http_response_code(200); echo 'no-id'; exit;
    }

    $accessToken = getenv('MP_ACCESS_TOKEN');
    if (!$accessToken) {
        sa_log_webhook('NO_ACCESS_TOKEN');
        http_response_code(200); echo 'no-token'; exit;
    }

    $url = $type === 'payment'
        ? "https://api.mercadopago.com/v1/payments/$paymentId"
        : "https://api.mercadopago.com/merchant_orders/$paymentId";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        sa_log_webhook("FETCH_FAIL http=$httpCode body=" . substr((string)$response, 0, 500));
        http_response_code(200); echo 'fetch-fail'; exit;
    }

    $data = json_decode($response, true);
    $orderCode = null;
    $status = null;
    $mpPaymentId = null;
    $mpPaymentType = null;

    if ($type === 'payment') {
        $orderCode = $data['external_reference'] ?? null;
        $status = $data['status'] ?? null;
        $mpPaymentId = (string)($data['id'] ?? '');
        $mpPaymentType = $data['payment_type_id'] ?? null;
    } else {
        // merchant_order puede traer múltiples payments.
        $orderCode = $data['external_reference'] ?? null;
        $payments = $data['payments'] ?? [];
        foreach ($payments as $p) {
            if (($p['status'] ?? '') === 'approved') { $status = 'approved'; $mpPaymentId = (string)($p['id'] ?? ''); break; }
            $status = $p['status'] ?? $status;
            $mpPaymentId = (string)($p['id'] ?? $mpPaymentId);
        }
    }

    if (!$orderCode) {
        sa_log_webhook('NO_ORDER_CODE payment=' . $paymentId);
        http_response_code(200); echo 'no-ref'; exit;
    }

    $map = [
        'approved'   => 'paid',
        'pending'    => 'pending',
        'in_process' => 'pending',
        'rejected'   => 'failed',
        'cancelled'  => 'cancelled',
        'refunded'   => 'cancelled',
    ];
    $paymentStatus = $map[$status] ?? 'pending';

    $pdo = sa_db();
    $stmt = $pdo->prepare("
        UPDATE sales
        SET payment_status = :ps,
            mp_payment_id = COALESCE(:pid, mp_payment_id),
            mp_payment_type = COALESCE(:ptype, mp_payment_type),
            updated_at = datetime('now')
        WHERE order_code = :code
    ");
    $stmt->execute([
        ':ps'    => $paymentStatus,
        ':pid'   => $mpPaymentId ?: null,
        ':ptype' => $mpPaymentType ?: null,
        ':code'  => $orderCode,
    ]);
    sa_log_webhook("OK code=$orderCode status=$status -> $paymentStatus mpId=$mpPaymentId");

    http_response_code(200); echo 'ok';
} catch (Throwable $e) {
    sa_log_webhook('EXC ' . $e->getMessage());
    http_response_code(200); echo 'err-logged';
}
