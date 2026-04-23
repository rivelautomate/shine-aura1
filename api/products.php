<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// GET  /api/products.php         → listado público (solo active=1)
// GET  /api/products.php?all=1   → listado completo (requiere auth)
// GET  /api/products.php?id=N    → un producto (público si active=1, auth si no)
// POST /api/products.php         → crear (auth)
// PUT  /api/products.php?id=N    → editar (auth)
// DELETE /api/products.php?id=N  → borrar (auth)

function sa_slugify(string $s): string {
    $s = iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return $s ?: 'item';
}

function sa_unique_slug(PDO $pdo, string $base, ?int $ignoreId = null): string {
    $slug = $base;
    $i = 2;
    while (true) {
        $q = "SELECT id FROM products WHERE slug = :s" . ($ignoreId ? " AND id != :ig" : "");
        $stmt = $pdo->prepare($q);
        $params = [':s' => $slug];
        if ($ignoreId) $params[':ig'] = $ignoreId;
        $stmt->execute($params);
        if (!$stmt->fetchColumn()) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

// Normaliza la lista de colores: cada item queda como {name: string, hex: '#RRGGBB'}.
function sa_sanitize_colors($raw): array {
    if (!is_array($raw)) return [];
    $out = [];
    foreach ($raw as $c) {
        if (!is_array($c)) continue;
        $name = isset($c['name']) ? trim((string)$c['name']) : '';
        $hex  = isset($c['hex'])  ? trim((string)$c['hex'])  : '';
        if ($hex !== '' && $hex[0] !== '#') $hex = '#' . $hex;
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) continue;
        if ($name === '') continue;
        $out[] = ['name' => $name, 'hex' => strtoupper($hex)];
        if (count($out) >= 20) break;
    }
    return $out;
}

function sa_validate_product_payload(array $in, bool $partial = false): array {
    $required = ['name', 'cat', 'price'];
    foreach ($required as $k) {
        if (!$partial && (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null)) {
            sa_fail("Falta el campo $k", 400);
        }
    }
    if (isset($in['price']) && (!is_numeric($in['price']) || $in['price'] < 0)) {
        sa_fail('Precio inválido', 400);
    }
    if (isset($in['old']) && $in['old'] !== null && $in['old'] !== '' && (!is_numeric($in['old']) || $in['old'] < 0)) {
        sa_fail('Precio anterior inválido', 400);
    }
    return $in;
}

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo = sa_db();

if ($method === 'GET') {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) sa_fail('Producto no encontrado', 404);
        if (!$row['active'] && !sa_current_user()) sa_fail('Producto no encontrado', 404);
        sa_json(['product' => sa_product_to_api($row)]);
    }

    $all = !empty($_GET['all']);
    if ($all) sa_require_auth();

    $where = $all ? '' : 'WHERE active = 1';
    $rows = $pdo->query("SELECT * FROM products $where ORDER BY sort_order ASC, id ASC")->fetchAll();
    $out = array_map('sa_product_to_api', $rows);
    sa_json(['products' => $out]);
}

if ($method === 'POST') {
    sa_require_auth();
    $in = sa_validate_product_payload(sa_read_json_body());
    $slug = sa_unique_slug($pdo, sa_slugify($in['slug'] ?? $in['name']));

    $stmt = $pdo->prepare("
        INSERT INTO products (slug, name, cat, tag, featured, description, long_description, sizes, colors, price, old_price, images, installments, stock, active, sort_order)
        VALUES (:slug, :name, :cat, :tag, :featured, :desc, :long, :sizes, :colors, :price, :old, :images, :inst, :stock, :active, :ord)
    ");
    $stmt->execute([
        ':slug'     => $slug,
        ':name'     => trim((string)$in['name']),
        ':cat'      => trim((string)$in['cat']),
        ':tag'      => !empty($in['tag']) ? (string)$in['tag'] : null,
        ':featured' => !empty($in['featured']) ? 1 : 0,
        ':desc'     => (string)($in['desc'] ?? ''),
        ':long'     => (string)($in['longDesc'] ?? ''),
        ':sizes'    => json_encode(is_array($in['sizes'] ?? null) ? $in['sizes'] : []),
        ':colors'   => json_encode(sa_sanitize_colors($in['colors'] ?? [])),
        ':price'    => (int)$in['price'],
        ':old'      => isset($in['old']) && $in['old'] !== '' && $in['old'] !== null ? (int)$in['old'] : null,
        ':images'   => json_encode(is_array($in['images'] ?? null) ? $in['images'] : []),
        ':inst'     => (string)($in['installments'] ?? ''),
        ':stock'    => (int)($in['stock'] ?? 0),
        ':active'   => isset($in['active']) ? ((int)!!$in['active']) : 1,
        ':ord'      => (int)($in['sortOrder'] ?? 0),
    ]);
    $newId = (int)$pdo->lastInsertId();
    $row = $pdo->query("SELECT * FROM products WHERE id = $newId")->fetch();
    sa_json(['product' => sa_product_to_api($row)], 201);
}

if ($method === 'PUT') {
    sa_require_auth();
    if ($id <= 0) sa_fail('ID inválido', 400);
    $in = sa_validate_product_payload(sa_read_json_body(), true);

    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) sa_fail('Producto no encontrado', 404);

    $slug = $row['slug'];
    if (isset($in['slug']) && $in['slug'] !== '' && $in['slug'] !== $row['slug']) {
        $slug = sa_unique_slug($pdo, sa_slugify($in['slug']), $id);
    } elseif (isset($in['name']) && $in['name'] !== $row['name'] && !isset($in['slug'])) {
        // Si cambia el nombre y no mandan slug, lo regeneramos.
        $slug = sa_unique_slug($pdo, sa_slugify($in['name']), $id);
    }

    $fields = [
        'slug'             => $slug,
        'name'             => $in['name']         ?? $row['name'],
        'cat'              => $in['cat']          ?? $row['cat'],
        'tag'              => array_key_exists('tag', $in) ? ($in['tag'] ?: null) : $row['tag'],
        'featured'         => array_key_exists('featured', $in) ? ((int)!!$in['featured']) : (int)$row['featured'],
        'description'      => $in['desc']         ?? $row['description'],
        'long_description' => $in['longDesc']     ?? $row['long_description'],
        'sizes'            => isset($in['sizes']) ? json_encode(is_array($in['sizes']) ? $in['sizes'] : []) : $row['sizes'],
        'colors'           => isset($in['colors']) ? json_encode(sa_sanitize_colors($in['colors'])) : ($row['colors'] ?? '[]'),
        'price'            => isset($in['price']) ? (int)$in['price'] : (int)$row['price'],
        'old_price'        => array_key_exists('old', $in) ? ($in['old'] !== '' && $in['old'] !== null ? (int)$in['old'] : null) : $row['old_price'],
        'images'           => isset($in['images']) ? json_encode(is_array($in['images']) ? $in['images'] : []) : $row['images'],
        'installments'     => $in['installments'] ?? $row['installments'],
        'stock'            => isset($in['stock'])  ? (int)$in['stock']  : (int)$row['stock'],
        'active'           => array_key_exists('active', $in) ? ((int)!!$in['active']) : (int)$row['active'],
        'sort_order'       => isset($in['sortOrder']) ? (int)$in['sortOrder'] : (int)$row['sort_order'],
    ];

    $sets = [];
    foreach ($fields as $k => $v) $sets[] = "$k = :$k";
    $sets[] = "updated_at = datetime('now')";
    $sql = "UPDATE products SET " . implode(', ', $sets) . " WHERE id = :id";
    $params = $fields;
    $params['id'] = $id;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $updated = $pdo->query("SELECT * FROM products WHERE id = $id")->fetch();
    sa_json(['product' => sa_product_to_api($updated)]);
}

if ($method === 'DELETE') {
    sa_require_auth();
    if ($id <= 0) sa_fail('ID inválido', 400);
    $stmt = $pdo->prepare("DELETE FROM products WHERE id = :id");
    $stmt->execute([':id' => $id]);
    sa_json(['ok' => true]);
}

sa_fail('Método no permitido', 405);
