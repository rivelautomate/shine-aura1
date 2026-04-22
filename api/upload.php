<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// POST /api/upload.php — subir imagen de producto (auth).
// Acepta multipart/form-data con campo "image".
// Devuelve {url: "/uploads/products/xxx.jpg"}.

sa_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') sa_fail('Método no permitido', 405);
if (empty($_FILES['image'])) sa_fail('No se envió ningún archivo', 400);

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    $msg = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande',
        UPLOAD_ERR_PARTIAL   => 'Subida incompleta',
        UPLOAD_ERR_NO_FILE   => 'No se envió archivo',
        default              => 'Error al subir',
    };
    sa_fail($msg, 400);
}

$maxBytes = 5 * 1024 * 1024; // 5 MB
if ($file['size'] > $maxBytes) sa_fail('La imagen debe pesar menos de 5 MB', 400);

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
if (!isset($allowed[$mime])) sa_fail('Formato no permitido. Usá JPG, PNG, WebP o GIF.', 400);

// Validar que sea una imagen real, no un archivo renombrado.
$dim = @getimagesize($file['tmp_name']);
if (!$dim) sa_fail('El archivo no es una imagen válida', 400);

$ext = $allowed[$mime];
$basename = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
$destPath = SA_UPLOADS_DIR . '/' . $basename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    sa_fail('No se pudo guardar la imagen en el servidor', 500);
}
@chmod($destPath, 0644);

$url = SA_UPLOADS_URL_PREFIX . '/' . $basename;
sa_json(['url' => $url, 'filename' => $basename]);
