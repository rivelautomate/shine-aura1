<?php
// Helper central de BD (SQLite). Todos los endpoints hacen require_once de este archivo.

if (!defined('SA_DB_BOOTSTRAPPED')) {
    define('SA_DB_BOOTSTRAPPED', true);

    // La BD vive en /var/www/html/data/shineaura.db — ese path está montado como volumen persistente en Easypanel.
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        @mkdir($dataDir, 0775, true);
    }
    define('SA_DB_PATH', $dataDir . '/shineaura.db');
    define('SA_SCHEMA_PATH', __DIR__ . '/../db/schema.sql');
    define('SA_UPLOADS_DIR', __DIR__ . '/../uploads/products');
    define('SA_UPLOADS_URL_PREFIX', '/uploads/products');

    if (!is_dir(SA_UPLOADS_DIR)) {
        @mkdir(SA_UPLOADS_DIR, 0775, true);
    }
}

function sa_db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $firstRun = !file_exists(SA_DB_PATH);

    $pdo = new PDO('sqlite:' . SA_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');

    if ($firstRun || !sa_table_exists($pdo, 'products')) {
        $sql = file_get_contents(SA_SCHEMA_PATH);
        if ($sql === false) {
            throw new RuntimeException('No se pudo leer el schema SQL');
        }
        $pdo->exec($sql);
        sa_seed_initial_data($pdo);
    }

    return $pdo;
}

function sa_table_exists(PDO $pdo, string $name): bool {
    $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = :n");
    $stmt->execute([':n' => $name]);
    return (bool)$stmt->fetchColumn();
}

function sa_seed_initial_data(PDO $pdo): void {
    // Admin inicial: username=admin, password=shineaura2025.
    // El cliente cambia la password desde el panel la primera vez que entra.
    $check = $pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ((int)$check === 0) {
        $hash = password_hash('shineaura2025', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES ('admin', :h)");
        $stmt->execute([':h' => $hash]);
    }

    // Seed de productos demo para que la tienda no arranque vacía.
    $check = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ((int)$check === 0) {
        $demo = [
            ['top-lencero',       'Top Lencero',        'catsuits',   'Nuevo',    1, 'Satén premium · escote profundo',   'Top estilo lencero en satén premium.',          ['XS','S','M','L'],      35000, null,  '3 cuotas sin interés'],
            ['vestido-minimal',   'Vestido Minimal',    'vestidos',   'Nuevo',    1, 'Cut-out lateral · tela fluida',      'Vestido con corte lateral asimétrico.',         ['XS','S','M','L'],      65000, 80000, '3 cuotas sin interés'],
            ['co-ord-set',        'Co-ord Set',         'conjuntos',  'Limitado', 1, 'Top + falda midi coordinados',       'Conjunto coordinado en tela texturada.',        ['S','M','L'],           95000, null,  '3 cuotas sin interés'],
            ['pollera-asimetrica','Pollera Asimétrica', 'polleras',   null,       0, 'Largo irregular · tela fluida',      'Pollera con largo irregular en tela liviana.',  ['XS','S','M','L','XL'], 48000, null,  '3 cuotas sin interés'],
            ['blazer-oversize',   'Blazer Oversize',    'basicos',    'Nuevo',    0, 'Apto para noche · corte recto',      'Blazer oversize. Se puede usar como vestido corto.', ['S','M','L'],      72000, 90000, '3 cuotas sin interés'],
            ['vestido-drapeado',  'Vestido Drapeado',   'vestidos',   null,       0, 'Un hombro · satinado',               'Vestido drapeado de un hombro en tela satinada.', ['XS','S','M','L'],    78000, null,  '3 cuotas sin interés'],
        ];
        $stmt = $pdo->prepare("
            INSERT INTO products (slug, name, cat, tag, featured, description, long_description, sizes, price, old_price, installments, stock, active, sort_order)
            VALUES (:slug, :name, :cat, :tag, :featured, :desc, :long, :sizes, :price, :old, :inst, 10, 1, :ord)
        ");
        foreach ($demo as $i => $p) {
            $stmt->execute([
                ':slug'     => $p[0],
                ':name'     => $p[1],
                ':cat'      => $p[2],
                ':tag'      => $p[3],
                ':featured' => $p[4],
                ':desc'     => $p[5],
                ':long'     => $p[6],
                ':sizes'    => json_encode($p[7]),
                ':price'    => $p[8],
                ':old'      => $p[9],
                ':inst'     => $p[10],
                ':ord'      => $i,
            ]);
        }
    }
}

// Respuestas JSON estandarizadas.
function sa_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sa_fail(string $msg, int $status = 400, array $extra = []): void {
    sa_json(array_merge(['error' => $msg], $extra), $status);
}

function sa_read_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function sa_site_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

// Serializa una fila de producto al formato que consume el frontend (arrays JS en vez de strings JSON).
function sa_product_to_api(array $row): array {
    return [
        'id'           => 'p' . $row['id'],
        'dbId'         => (int)$row['id'],
        'slug'         => $row['slug'],
        'name'         => $row['name'],
        'cat'          => $row['cat'],
        'tag'          => $row['tag'],
        'featured'     => (bool)$row['featured'],
        'desc'         => $row['description'],
        'longDesc'     => $row['long_description'],
        'sizes'        => json_decode($row['sizes'] ?: '[]', true) ?: [],
        'price'        => (int)$row['price'],
        'old'          => $row['old_price'] !== null ? (int)$row['old_price'] : null,
        'images'       => json_decode($row['images'] ?: '[]', true) ?: [],
        'installments' => $row['installments'],
        'stock'        => (int)$row['stock'],
        'active'       => (bool)$row['active'],
    ];
}

function sa_sale_to_api(array $row): array {
    return [
        'id'            => (int)$row['id'],
        'orderCode'     => $row['order_code'],
        'customer'      => [
            'name'     => $row['customer_name'],
            'lastname' => $row['customer_lastname'],
            'phone'    => $row['customer_phone'],
            'address'  => $row['customer_address'],
            'district' => $row['customer_district'],
            'zip'      => $row['customer_zip'],
            'notes'    => $row['customer_notes'],
        ],
        'items'         => json_decode($row['items'] ?: '[]', true) ?: [],
        'subtotal'      => (int)$row['subtotal'],
        'shipping'      => (int)$row['shipping'],
        'total'         => (int)$row['total'],
        'paymentMethod' => $row['payment_method'],
        'paymentStatus' => $row['payment_status'],
        'orderStatus'   => $row['order_status'],
        'mp'            => [
            'preferenceId' => $row['mp_preference_id'],
            'paymentId'    => $row['mp_payment_id'],
            'paymentType'  => $row['mp_payment_type'],
        ],
        'createdAt'     => $row['created_at'],
        'updatedAt'     => $row['updated_at'],
    ];
}
