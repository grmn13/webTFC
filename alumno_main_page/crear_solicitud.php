<?php
session_start();

if (!isset($_SESSION["UID"])) {
    header("Location: ../login/login.html");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../busqueda/busqueda.php");
    exit();
}

require_once "../connect.php";

// ── Input collection ──────────────────────────────────────────────────────────

$id_alumno   = (int) $_SESSION["UID"];
$id_profesor = isset($_POST['id_profesor']) ? (int) $_POST['id_profesor'] : 0;
$mensaje     = trim($_POST['texto'] ?? '');

// ── Helper to render a page and exit ─────────────────────────────────────────

function render_page(string $icon, string $heading, string $body_html, string $btn_label = '← Volver', string $back_url = ''): void {
    $href = $back_url !== '' ? htmlspecialchars($back_url) : 'javascript:history.back()';
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($heading) ?></title>
        <link rel="stylesheet" href="crear_solicitud.css">
    </head>
    <body>
        <div class="card">
            <div class="icon"><?= $icon ?></div>
            <h2><?= htmlspecialchars($heading) ?></h2>
            <?= $body_html ?>
            <a class="btn-back" href="<?= $href ?>"><?= htmlspecialchars($btn_label) ?></a>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// ── Validation ────────────────────────────────────────────────────────────────

$errors = [];

if ($id_profesor <= 0) {
    $errors[] = "El profesor seleccionado no es válido.";
}

if ($mensaje === '') {
    $errors[] = "El mensaje no puede estar vacío.";
} elseif (mb_strlen($mensaje) > 1000) {
    $errors[] = "El mensaje no puede superar los 1000 caracteres.";
}

if (!empty($errors)) {

    $items = implode('', array_map(
        fn($e) => '<li>' . htmlspecialchars($e) . '</li>',
        $errors
    ));

    render_page(
        '⚠️',
        'No se pudo enviar la solicitud',
        '<ul class="error-list">' . $items . '</ul>'
    );
}

// ── Database connection ───────────────────────────────────────────────────────

$conn = new mysqli($servername, $username, $password, $db);

if ($conn->connect_error) {
    render_page('❌', 'Error de conexión', '<p>No se pudo conectar con la base de datos. Inténtalo más tarde.</p>');
}

$conn->set_charset("utf8mb4");

// ── Verify the professor exists ───────────────────────────────────────────────

$stmt = $conn->prepare("SELECT id FROM profesores WHERE id = ?");
$stmt->bind_param("i", $id_profesor);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    $stmt->close();
    $conn->close();
    render_page('❌', 'Profesor no encontrado', '<p>El profesor seleccionado no existe en el sistema.</p>');
}
$stmt->close();

// ── Check for duplicate request ───────────────────────────────────────────────

$stmt = $conn->prepare(
    "SELECT id_solicitud FROM solicitudes
     WHERE id_alumno = ? AND id_profesor = ?
     LIMIT 1"
);
$stmt->bind_param("ii", $id_alumno, $id_profesor);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    $conn->close();
    render_page(
        '📋',
        'Solicitud ya enviada',
        '<div class="notice">Ya tienes una solicitud activa con este profesor. Espera su respuesta antes de enviar otra.</div>'
    );
}
$stmt->close();

// ── Insert the request ────────────────────────────────────────────────────────

$fecha = date('Y-m-d');

$stmt = $conn->prepare(
    "INSERT INTO solicitudes (id_alumno, id_profesor, fecha, mensaje)
     VALUES (?, ?, ?, ?)"
);
$stmt->bind_param("iiss", $id_alumno, $id_profesor, $fecha, $mensaje);

if ($stmt->execute()) {

    $stmt->close();
    $conn->close();
    render_page(
        '✅',
        'Solicitud enviada',
        '<div class="success-msg">Tu mensaje ha sido enviado correctamente. El profesor lo recibirá en breve.</div>',
        '← Volver al inicio',
        '../alumno_main_page/main_alum.php'
    );

} else {

    $error = htmlspecialchars($stmt->error);
    $stmt->close();
    $conn->close();
    render_page(
        '❌',
        'Error al enviar la solicitud',
        '<p>' . $error . '</p>'
    );
}
?>
