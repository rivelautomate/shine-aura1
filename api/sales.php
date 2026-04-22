<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// POST /api/sales.php                        → crear venta (público). Body: customer, items, paymentMethod, notes
// GET  /api/sales.php                        → listar (auth). Soporta ?status=pending|paid|... y ?limit=N
// GET  /api/sales.php?orderCode=XXX          → buscar por código (público, solo devuelve datos resumen seguros)
// PUT  /api/sales.php?id=N                   → actualizar estado (auth). Body: orderStatus, paymentStatus
// DELETE /api/sales.php?id=N                 → borrar (auth)

function sa_generate_order_code(): string {
    // SA-YYMMDD-XXXXX (5 hex mayúsculas al final). Corto, legible, único.
    return 'SA-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function sa_validate_items(array $items): array {
    if (empty($items)) sa_fail('El carrito está vacío', 400);
    $out = [];
    foreach ($items as $it) {
        $name  = trim((string)($it['name'] ?? ''));
        $qty   = (int)($it['qty'] ?? 0);
        $price = (int)($it['price'] ?? 0);
        $size  = trim((string)($it['size'] ?? ''));
        if ($name === '' || $qty <= 0 || $price < 0) sa_fail('Item inválido en el carrito', 400);
        $row = ['name' => $name, 'qty' => $qty, 'price' => $price];
        if ($size !== '') $row['size'] = $size;
        if (!empty($it['productId'])) $row['productId'] = (int)$it['productId'];
        $out[] = $row;
    }
    return $out;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$orderCode = $_GET['orderCode'] ?? null;
$pdo = sa_db();

if ($method === 'POST') {
    $in = sa_read_json_body();
    $customer = is_array($in['customer'] ?? null) ? $in['customer'] : [];
    $items = sa_validate_items($in['items'] ?? []);
    $method_payment = $in['paymentMethod'] ?? '';
    if (!in_array($method_payment, ['mp', 'cash'], true)) sa_fail('Método de pago inválido', 400);

    $required = ['name', 'lastname', 'phone', 'address', 'district', 'zip'];
    foreach ($required as $k) {
        if (empty(trim((string)($customer[$k] ?? '')))) sa_fail("Falta $k del cliente", 400);
    }

    $subtotal = 0;
    foreach ($items as $it) $subtotal += $it['price'] * $it['qty'];
    $shipping = (int)($in['shipping'] ?? 0);
    $total = $subtotal + $shipping;

    $orderCode = sa_generate_order_code();
    $stmt = $pdo->prepare("
        INSERT INTO sales (order_code, customer_name, customer_lastname, customer_phone, customer_address, customer_district, customer_zip, customer_notes, items, subtotal, shipping, total, payment_method, payment_status, order_status)
        VALUES (:code, :n, :l, :p, :addr, :dist, :zip, :notes, :items, :sub, :ship, :total, :pm, 'pending', 'new')
    ");
    $stmt->execute([
        ':code'  => $orderCode,
        ':n'     => trim((string)$customer['name']),
        ':l'     => trim((string)$customer['lastname']),
        ':p'     => trim((string)$customer['phone']),
        ':addr'  => trim((string)$customer['address']),
        ':dist'  => trim((string)$customer['district']),
        ':zip'   => trim((string)$customer['zip']),
        ':notes' => trim((string)($customer['notes'] ?? $in['notes'] ?? '')),
        ':items' => json_encode($items, JSON_UNESCAPED_UNICODE),
        ':sub'   => $subtotal,
        ':ship'  => $shipping,
        ':total' => $total,
        ':pm'    => $method_payment,
    ]);
    $newId = (int)$pdo->lastInsertId();
    $row = $pdo->query("SELECT * FROM sales WHERE id = $newId")->fetch();
    sa_json(['sale' => sa_sale_to_api($row)], 201);
}

if ($method === 'GET' && $orderCode) {
    // Público — para que pago-exitoso.html pueda consultar el resumen de la orden sin login.
    // Devuelve versión limitada (sin datos sensibles, solo lo que necesita la pantalla).
    $stmt = $pdo->prepare("SELECT * FROM sales WHERE order_code = :c");
    $stmt->execute([':c' => $orderCode]);
    $row = $stmt->fetch();
    if (!$row) sa_fail('Orden no encontrada', 404);
    $sale = sa_sale_to_api($row);
    unset($sale['customer']['phone'], $sale['customer']['address']); // reduce exposición
    sa_json(['sale' => $sale]);
}

if ($method === 'GET') {
    sa_require_auth();
    $status = $_GET['status'] ?? '';
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 200)));
    if ($status !== '') {
        $stmt = $pdo->prepare("SELECT * FROM sales WHERE payment_status = :s ORDER BY created_at DESC LIMIT $limit");
        $stmt->execute([':s' => $status]);
    } else {
        $stmt = $pdo->query("SELECT * FROM sales ORDER BY created_at DESC LIMIT $limit");
    }
    $rows = $stmt->fetchAll();
    sa_json(['sales' => array_map('sa_sale_to_api', $rows)]);
}

if ($method === 'PUT') {
    sa_require_auth();
    if ($id <= 0) sa_fail('ID inválido', 400);
    $in = sa_read_json_body();

    $fields = [];
    $params = [':id' => $id];
    if (isset($in['paymentStatus'])) {
        if (!in_array($in['paymentStatus'], ['pending','paid','failed','cancelled'], true)) sa_fail('paymentStatus inválido', 400);
        $fields[] = "payment_status = :ps"; $params[':ps'] = $in['paymentStatus'];
    }
    if (isset($in['orderStatus'])) {
        if (!in_array($in['orderStatus'], ['new','processing','shipped','delivered','cancelled'], true)) sa_fail('orderStatus inválido', 400);
        $fields[] = "order_status = :os"; $params[':os'] = $in['orderStatus'];
    }
    if (!$fields) sa_fail('Nada para actualizar', 400);
    $fields[] = "updated_at = datetime('now')";

    $sql = "UPDATE sales SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $row = $pdo->query("SELECT * FROM sales WHERE id = $id")->fetch();
    if (!$row) sa_fail('Orden no encontrada', 404);
    sa_json(['sale' => sa_sale_to_api($row)]);
}

if ($method === 'DELETE') {
    sa_require_auth();
    if ($id <= 0) sa_fail('ID inválido', 400);
    $stmt = $pdo->prepare("DELETE FROM sales WHERE id = :id");
    $stmt->execute([':id' => $id]);
    sa_json(['ok' => true]);
}

sa_fail('Método no permitido', 405);
