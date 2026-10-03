<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Endpoints:
//   GET    /api/categories.php          → listar activas (público, para el sitio).
//   GET    /api/categories.php?all=1    → listar todas incluyendo inactivas (admin).
//   GET    /api/categories.php?id=X     → una sola.
//   POST   /api/categories.php          → crear (admin). Body: {name, slug?, active?}
//   PATCH  /api/categories.php?id=X     → editar (admin). Body: {name?, slug?, active?}
//   DELETE /api/categories.php?id=X     → borrar (admin). Falla si hay productos usándola.
//   POST   /api/categories.php?reorder=1 → reordenar (admin). Body: {ids: [3,1,2,4]}

function sa_category_to_api(array $row): array {
    return [
        'id'        => (int)$row['id'],
        'slug'      => $row['slug'],
        'name'      => $row['name'],
        'active'    => (bool)$row['active'],
        'sortOrder' => (int)$row['sort_order'],
    ];
}

// Convierte "Lencería Fina" → "lenceria-fina"
function sa_slugify(string $text): string {
    $text = trim($text);
    if ($text === '') return '';
    // Normalizar unicode y quitar acentos
    if (function_exists('iconv')) {
        $tr = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($tr !== false && $tr !== '') $text = $tr;
    }
    $text = strtolower($text);
    // Solo letras, números, espacios y guiones
    $text = preg_replace('/[^a-z0-9\s-]/', '', $text);
    // Espacios y guiones múltiples → un guion
    $text = preg_replace('/[\s-]+/', '-', $text);
    return trim($text, '-');
}

function sa_validate_category_name(string $name): string {
    $name = trim($name);
    if ($name === '')              sa_fail('El nombre es obligatorio', 400);
    if (mb_strlen($name) > 50)     sa_fail('El nombre es muy largo (máx 50 caracteres)', 400);
    return $name;
}

function sa_validate_category_slug(string $slug): string {
    $slug = strtolower(trim($slug));
    if ($slug === '')                            sa_fail('El slug es obligatorio', 400);
    if (!preg_match('/^[a-z0-9-]+$/', $slug))    sa_fail('Slug inválido (solo minúsculas, números y guiones)', 400);
    if (mb_strlen($slug) > 40)                   sa_fail('El slug es muy largo (máx 40 caracteres)', 400);
    return $slug;
}

$method    = $_SERVER['REQUEST_METHOD'];
$id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$pdo       = sa_db();

// ============ GET ============
if ($method === 'GET') {
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row)                               sa_fail('Categoría no encontrada', 404);
        if (!$row['active'] && !sa_current_user()) sa_fail('Categoría no encontrada', 404);
        sa_json(['category' => sa_category_to_api($row)]);
    }

    $all = !empty($_GET['all']);
    if ($all) sa_require_auth();

    $where = $all ? '' : 'WHERE active = 1';
    $rows = $pdo->query("SELECT * FROM categories $where ORDER BY sort_order ASC, id ASC")->fetchAll();
    $out = array_map('sa_category_to_api', $rows);
    sa_json(['categories' => $out]);
}

// ============ POST (crear o reordenar) ============
if ($method === 'POST' && !empty($_GET['reorder'])) {
    sa_require_auth();
    $in  = sa_read_json_body();
    $ids = isset($in['ids']) && is_array($in['ids']) ? $in['ids'] : null;
    if (!$ids) sa_fail('Falta la lista de ids', 400);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE categories SET sort_order = :ord, updated_at = datetime('now') WHERE id = :id");
        foreach ($ids as $i => $rawId) {
            $cid = (int)$rawId;
            if ($cid <= 0) continue;
            $stmt->execute([':ord' => $i, ':id' => $cid]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        sa_fail('No se pudo reordenar: ' . $e->getMessage(), 500);
    }
    sa_json(['ok' => true, 'count' => count($ids)]);
}

if ($method === 'POST') {
    sa_require_auth();
    $in     = sa_read_json_body();
    $name   = sa_validate_category_name((string)($in['name'] ?? ''));
    $slug   = isset($in['slug']) && trim((string)$in['slug']) !== ''
                ? sa_validate_category_slug((string)$in['slug'])
                : sa_slugify($name);
    if ($slug === '') sa_fail('No se pudo generar un slug válido del nombre', 400);
    $active = isset($in['active']) ? (int)!!$in['active'] : 1;

    // Verificar que el slug no exista
    $exists = $pdo->prepare("SELECT id FROM categories WHERE slug = :s");
    $exists->execute([':s' => $slug]);
    if ($exists->fetchColumn()) sa_fail('Ya existe una categoría con ese slug', 409);

    // Insertar al final del orden actual
    $maxOrder = (int)($pdo->query("SELECT MAX(sort_order) FROM categories")->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("
        INSERT INTO categories (slug, name, active, sort_order)
        VALUES (:slug, :name, :active, :ord)
    ");
    $stmt->execute([
        ':slug'   => $slug,
        ':name'   => $name,
        ':active' => $active,
        ':ord'    => $maxOrder + 1,
    ]);
    $newId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute([':id' => $newId]);
    sa_json(['category' => sa_category_to_api($stmt->fetch())], 201);
}

// ============ PATCH (editar una) ============
if ($method === 'PATCH') {
    sa_require_auth();
    if ($id <= 0) sa_fail('Falta el id', 400);

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $current = $stmt->fetch();
    if (!$current) sa_fail('Categoría no encontrada', 404);

    $in = sa_read_json_body();

    $sets = [];
    $params = [':id' => $id];

    if (array_key_exists('name', $in)) {
        $name = sa_validate_category_name((string)$in['name']);
        $sets[] = 'name = :name';
        $params[':name'] = $name;
    }

    if (array_key_exists('slug', $in)) {
        $slug = sa_validate_category_slug((string)$in['slug']);
        if ($slug !== $current['slug']) {
            // Verificar unicidad del nuevo slug
            $exists = $pdo->prepare("SELECT id FROM categories WHERE slug = :s AND id != :id");
            $exists->execute([':s' => $slug, ':id' => $id]);
            if ($exists->fetchColumn()) sa_fail('Ya existe otra categoría con ese slug', 409);

            // Al cambiar el slug, actualizar los productos que usaban el slug viejo
            $upd = $pdo->prepare("UPDATE products SET cat = :new, updated_at = datetime('now') WHERE cat = :old");
            $upd->execute([':new' => $slug, ':old' => $current['slug']]);

            $sets[] = 'slug = :slug';
            $params[':slug'] = $slug;
        }
    }

    if (array_key_exists('active', $in)) {
        $sets[] = 'active = :active';
        $params[':active'] = (int)!!$in['active'];
    }

    if (!$sets) sa_fail('No hay campos para actualizar', 400);

    $sets[] = "updated_at = datetime('now')";
    $sql = "UPDATE categories SET " . implode(', ', $sets) . " WHERE id = :id";
    $pdo->prepare($sql)->execute($params);

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute([':id' => $id]);
    sa_json(['category' => sa_category_to_api($stmt->fetch())]);
}

// ============ DELETE ============
if ($method === 'DELETE') {
    sa_require_auth();
    if ($id <= 0) sa_fail('Falta el id', 400);

    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $cat = $stmt->fetch();
    if (!$cat) sa_fail('Categoría no encontrada', 404);

    // Validar que no haya productos usándola. Si los hay, exigir ?force=1 para confirmar.
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE cat = :s");
    $cnt->execute([':s' => $cat['slug']]);
    $usedBy = (int)$cnt->fetchColumn();

    if ($usedBy > 0 && empty($_GET['force'])) {
        sa_fail(
            "No se puede borrar: hay $usedBy producto(s) en esta categoría. Movelos primero o confirmá el borrado.",
            409,
            ['usedBy' => $usedBy]
        );
    }

    $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute([':id' => $id]);
    sa_json(['ok' => true, 'id' => $id, 'usedBy' => $usedBy]);
}

sa_fail('Método no permitido', 405);
