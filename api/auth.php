<?php
require_once __DIR__ . '/db.php';

// Endpoint para login/logout/cambiar password y chequeo de sesión.
// Uso (desde admin.html):
//   POST /api/auth.php?action=login         {username, password} → devuelve cookie de sesión
//   POST /api/auth.php?action=logout        → cierra sesión
//   POST /api/auth.php?action=change-password {currentPassword, newPassword}
//   GET  /api/auth.php?action=me            → devuelve {user: {...}} si hay sesión, {error} si no

const SA_SESSION_COOKIE = 'sa_session';
const SA_SESSION_TTL_HOURS = 24 * 7; // 7 días

function sa_issue_session(PDO $pdo, int $userId): string {
    $token = bin2hex(random_bytes(32));
    $expires = gmdate('Y-m-d H:i:s', time() + SA_SESSION_TTL_HOURS * 3600);
    $stmt = $pdo->prepare("INSERT INTO admin_sessions (token, user_id, expires_at) VALUES (:t, :u, :e)");
    $stmt->execute([':t' => $token, ':u' => $userId, ':e' => $expires]);

    // HttpOnly + Secure (estamos siempre en HTTPS en prod) + SameSite Strict para CSRF.
    setcookie(SA_SESSION_COOKIE, $token, [
        'expires'  => time() + SA_SESSION_TTL_HOURS * 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    return $token;
}

function sa_clear_session_cookie(): void {
    setcookie(SA_SESSION_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function sa_current_user(): ?array {
    $token = $_COOKIE[SA_SESSION_COOKIE] ?? null;
    if (!$token) return null;
    $pdo = sa_db();
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, s.expires_at
        FROM admin_sessions s
        JOIN admin_users u ON u.id = s.user_id
        WHERE s.token = :t
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (strtotime($row['expires_at']) < time()) {
        $del = $pdo->prepare("DELETE FROM admin_sessions WHERE token = :t");
        $del->execute([':t' => $token]);
        return null;
    }
    return ['id' => (int)$row['id'], 'username' => $row['username']];
}

function sa_require_auth(): array {
    $u = sa_current_user();
    if (!$u) sa_fail('No autenticado', 401);
    return $u;
}

// Punto de entrada para este archivo (si se ejecuta directamente como endpoint).
if (basename($_SERVER['SCRIPT_NAME']) === 'auth.php') {
    $action = $_GET['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($action === 'me' && $method === 'GET') {
        $u = sa_current_user();
        if (!$u) sa_fail('No autenticado', 401);
        sa_json(['user' => $u]);
    }

    if ($action === 'login' && $method === 'POST') {
        $body = sa_read_json_body();
        $username = trim((string)($body['username'] ?? ''));
        $password = (string)($body['password'] ?? '');
        if ($username === '' || $password === '') sa_fail('Usuario y contraseña son obligatorios', 400);

        $pdo = sa_db();
        $ip = sa_client_ip();

        // Rate limit: si la IP tuvo >=5 fallos en los últimos 15 min, bloquearla por 30 min.
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip = :ip AND success = 0
              AND datetime(created_at) > datetime('now', '-15 minutes')
        ");
        $stmt->execute([':ip' => $ip]);
        $recentFails = (int)$stmt->fetchColumn();

        if ($recentFails >= 5) {
            // Verificar si la IP sigue bloqueada (último fallo dentro de los últimos 30 min)
            $stmt = $pdo->prepare("
                SELECT MAX(created_at) FROM login_attempts
                WHERE ip = :ip AND success = 0
                  AND datetime(created_at) > datetime('now', '-30 minutes')
            ");
            $stmt->execute([':ip' => $ip]);
            if ($stmt->fetchColumn()) {
                usleep(800000);
                sa_fail('Demasiados intentos fallidos. Esperá 30 minutos antes de reintentar.', 429);
            }
        }

        $stmt = $pdo->prepare("SELECT id, password_hash FROM admin_users WHERE username = :u");
        $stmt->execute([':u' => $username]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, $row['password_hash'])) {
            // Registrar el fallo
            $log = $pdo->prepare("INSERT INTO login_attempts (ip, username, success) VALUES (:ip, :u, 0)");
            $log->execute([':ip' => $ip, ':u' => substr($username, 0, 80)]);
            // Limpieza ocasional: borrar intentos viejos (>7 días)
            if (random_int(1, 20) === 1) {
                $pdo->exec("DELETE FROM login_attempts WHERE datetime(created_at) < datetime('now', '-7 days')");
            }
            // Pequeño delay para dificultar fuerza bruta sin ser molesto.
            usleep(400000);
            sa_fail('Usuario o contraseña incorrectos', 401);
        }

        // Login exitoso — registrar y emitir sesión.
        $log = $pdo->prepare("INSERT INTO login_attempts (ip, username, success) VALUES (:ip, :u, 1)");
        $log->execute([':ip' => $ip, ':u' => substr($username, 0, 80)]);
        sa_issue_session($pdo, (int)$row['id']);
        sa_json(['user' => ['id' => (int)$row['id'], 'username' => $username]]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $pdo = sa_db();
        $token = $_COOKIE[SA_SESSION_COOKIE] ?? null;
        if ($token) {
            $stmt = $pdo->prepare("DELETE FROM admin_sessions WHERE token = :t");
            $stmt->execute([':t' => $token]);
        }
        sa_clear_session_cookie();
        sa_json(['ok' => true]);
    }

    if ($action === 'change-password' && $method === 'POST') {
        $me = sa_require_auth();
        $body = sa_read_json_body();
        $current = (string)($body['currentPassword'] ?? '');
        $next    = (string)($body['newPassword'] ?? '');
        if (strlen($next) < 8) sa_fail('La contraseña nueva debe tener al menos 8 caracteres', 400);

        $pdo = sa_db();
        $stmt = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = :id");
        $stmt->execute([':id' => $me['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) sa_fail('La contraseña actual no coincide', 401);

        $newHash = password_hash($next, PASSWORD_DEFAULT);
        $upd = $pdo->prepare("UPDATE admin_users SET password_hash = :h, updated_at = datetime('now') WHERE id = :id");
        $upd->execute([':h' => $newHash, ':id' => $me['id']]);

        // Invalidar todas las demás sesiones por seguridad.
        $token = $_COOKIE[SA_SESSION_COOKIE] ?? null;
        if ($token) {
            $del = $pdo->prepare("DELETE FROM admin_sessions WHERE user_id = :u AND token != :t");
            $del->execute([':u' => $me['id'], ':t' => $token]);
        }
        sa_json(['ok' => true]);
    }

    sa_fail('Acción no reconocida', 404);
}
