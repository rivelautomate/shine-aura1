<?php
// Helper de notificaciones por Telegram.
// Lee credenciales de variables de entorno:
//   TELEGRAM_BOT_TOKEN  → token del bot (ej: 7891234567:AAEXxxxxxxxx)
//   TELEGRAM_CHAT_ID    → ID del chat al que mandar los mensajes (puede ser personal o de un grupo)
//
// Si alguna falta, sa_telegram_send() loguea y devuelve false sin romper nada.
// Nunca lanza excepciones — está pensada para usarse desde webhooks de MP donde
// queremos seguir devolviendo 200 OK aunque la notificación falle.

function sa_telegram_log(string $msg): void {
    @file_put_contents(__DIR__ . '/../data/telegram.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

function sa_telegram_send(string $text): bool {
    $token = getenv('TELEGRAM_BOT_TOKEN');
    $chatId = getenv('TELEGRAM_CHAT_ID');
    if (!$token || !$chatId) {
        sa_telegram_log('SKIP — token o chat_id no configurados');
        return false;
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        sa_telegram_log("ERR curl: $curlErr");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        sa_telegram_log("ERR http=$httpCode body=" . substr((string)$response, 0, 400));
        return false;
    }
    sa_telegram_log("OK");
    return true;
}

// Notificación amigable para una venta confirmada.
function sa_telegram_notify_sale(array $sale): bool {
    $items = is_array($sale['items'] ?? null) ? $sale['items'] : (json_decode($sale['items'] ?? '[]', true) ?: []);
    $itemsTxt = [];
    foreach ($items as $i) {
        $extras = [];
        if (!empty($i['size']))  $extras[] = 'T:' . $i['size'];
        if (!empty($i['color'])) $extras[] = 'C:' . $i['color'];
        $extraStr = $extras ? ' (' . implode(' ', $extras) . ')' : '';
        $itemsTxt[] = '• ' . ($i['qty'] ?? 1) . '× ' . htmlspecialchars($i['name'] ?? 'Producto') . $extraStr;
    }
    $totalFmt = '$' . number_format((int)($sale['total'] ?? 0), 0, ',', '.');
    $phone = (string)($sale['customer_phone'] ?? $sale['phone'] ?? '');
    $phoneDigits = preg_replace('/\D/', '', $phone);
    $name = trim((string)($sale['customer_name'] ?? '') . ' ' . (string)($sale['customer_lastname'] ?? ''));
    $address = (string)($sale['customer_address'] ?? '');
    $district = (string)($sale['customer_district'] ?? '');
    $zip = (string)($sale['customer_zip'] ?? '');
    $orderCode = (string)($sale['order_code'] ?? '');

    $msg  = "🎉 <b>¡Nueva venta confirmada!</b>\n\n";
    $msg .= "📦 Pedido: <code>" . htmlspecialchars($orderCode) . "</code>\n";
    $msg .= "💰 Total: <b>{$totalFmt}</b>\n\n";
    $msg .= "👤 <b>" . htmlspecialchars($name) . "</b>\n";
    if ($phone) {
        $msg .= "📱 " . htmlspecialchars($phone);
        if ($phoneDigits) {
            $msg .= " — <a href=\"https://wa.me/{$phoneDigits}\">abrir WhatsApp</a>";
        }
        $msg .= "\n";
    }
    if ($address)  $msg .= "🏠 " . htmlspecialchars($address) . "\n";
    if ($district) $msg .= "📍 " . htmlspecialchars($district) . ($zip ? " (CP {$zip})" : '') . "\n";
    $msg .= "\n<b>Productos:</b>\n" . implode("\n", $itemsTxt);

    return sa_telegram_send($msg);
}
