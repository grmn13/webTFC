<?php
session_start();

if (!isset($_SESSION["UID"])) {
    header("Location: ../login/login.php");
    exit();
}

require_once "../connect.php";

$id_profesor = (int) $_SESSION["UID"];
$running     = true;
$flash       = null; // success / error message after redirect

// ── Handle "mark as completed" POST ──────────────────────────────────────────
// Uses PRG (Post-Redirect-Get) so refreshing the page never re-submits

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['completar'])) {

    $id_solicitud = (int) $_POST['id_solicitud'];

    $conn = new mysqli($servername, $username, $password, $db);
    $conn->set_charset("utf8mb4");

    if (!$conn->connect_error) {

        // Only delete if the solicitud actually belongs to this teacher
        $stmt = $conn->prepare(
            "DELETE FROM solicitudes
             WHERE id_solicitud = ? AND id_profesor = ?"
        );
        $stmt->bind_param("ii", $id_solicitud, $id_profesor);
        $stmt->execute();

        $deleted = $stmt->affected_rows;
        $stmt->close();
        $conn->close();

        $_SESSION['flash'] = $deleted > 0
            ? ['type' => 'success', 'msg' => 'Solicitud marcada como completada.']
            : ['type' => 'error',   'msg' => 'No se pudo completar la solicitud.'];
    }

    header("Location: main_profe.php");
    exit();
}

// ── Consume flash message set by the POST redirect ────────────────────────────

if (isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

// ── Database connection (GET path) ────────────────────────────────────────────

$conn = new mysqli($servername, $username, $password, $db);

if ($conn->connect_error) {
    $running = false;
}

$conn->set_charset("utf8mb4");

// ── Fetch teacher data ────────────────────────────────────────────────────────

$profesor = null;

if ($running) {

    $stmt = $conn->prepare(
        "SELECT nombre, apellidos, email, pfp_path FROM profesores WHERE id = ?"
    );
    $stmt->bind_param("i", $id_profesor);
    $stmt->execute();
    $result = $stmt->get_result();
    $profesor = $result->fetch_assoc();
    $stmt->close();

    if (!$profesor) {
        $running = false;
    }
}

// ── Fetch solicitudes ─────────────────────────────────────────────────────────

$solicitudes = [];

if ($running) {

    $stmt = $conn->prepare(
        "SELECT s.id_solicitud, s.fecha, s.mensaje,
                a.nombre        AS nombre_alumno,
                a.apellidos     AS apellidos_alumno,
                a.email         AS email_alumno,
                a.telefono      AS telefono_alumno,
                m.nombre        AS nombre_materia,
                c.nombre        AS nombre_curso
         FROM solicitudes s
         INNER JOIN alumnos  a ON s.id_alumno  = a.id
         LEFT  JOIN materias m ON s.id_materia = m.id
         LEFT  JOIN cursos   c ON s.id_curso   = c.id
         WHERE s.id_profesor = ?
         ORDER BY s.fecha DESC"
    );
    $stmt->bind_param("i", $id_profesor);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $solicitudes[] = $row;
    }

    $stmt->close();
    $conn->close();
}

$num_solicitudes = count($solicitudes);
$nombre_display  = $profesor ? htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellidos']) : 'Profesor';
$pfp             = $profesor && $profesor['pfp_path'] ? htmlspecialchars($profesor['pfp_path']) : '../assets/default_pfp.png';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel del profesor</title>
    <link rel="stylesheet" href="main_profe.css">
</head>
<body>

    <!-- ── Top navbar ─────────────────────────────────────────────────────── -->
    <header class="navbar">
        <div class="navbar-brand">
            <span class="brand-dot"></span>
            <span class="brand-name">WebProfes</span>
        </div>
        <div class="navbar-user">
            <img class="navbar-pfp" src="<?= $pfp ?>" alt="Foto de perfil">
            <span class="navbar-nombre"><?= $nombre_display ?></span>
            <a class="btn-editar" href="editar_perfil.php">✏️ Editar perfil</a>
        </div>
    </header>

    <!-- ── Page body ──────────────────────────────────────────────────────── -->
    <main class="contenido">

        <!-- Flash message -->
        <?php if ($flash): ?>
            <div class="flash flash-<?= $flash['type'] ?>">
                <?= htmlspecialchars($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <!-- Stats strip -->
        <div class="stats-strip">
            <div class="stat-card">
                <span class="stat-number"><?= $num_solicitudes ?></span>
                <span class="stat-label">solicitud<?= $num_solicitudes !== 1 ? 'es' : '' ?> pendiente<?= $num_solicitudes !== 1 ? 's' : '' ?></span>
            </div>
        </div>

        <!-- Section heading -->
        <h2 class="section-title">Tus solicitudes</h2>

        <?php if (!$running): ?>

            <div class="empty-state">
                <span class="empty-icon">⚠️</span>
                <p>No se pudo conectar con la base de datos.</p>
            </div>

        <?php elseif (empty($solicitudes)): ?>

            <div class="empty-state">
                <span class="empty-icon">📭</span>
                <p>No tienes solicitudes pendientes de momento.</p>
            </div>

        <?php else: ?>

            <div class="solicitudes-grid">
                <?php foreach ($solicitudes as $s): ?>

                    <article class="solicitud-card">

                        <div class="card-header">
                            <div class="alumno-avatar">
                                <?= mb_strtoupper(mb_substr($s['nombre_alumno'], 0, 1)) ?>
                            </div>
                            <div class="alumno-info">
                                <span class="alumno-nombre">
                                    <?= htmlspecialchars($s['nombre_alumno'] . ' ' . $s['apellidos_alumno']) ?>
                                </span>
                                <span class="solicitud-fecha">
                                    <?= htmlspecialchars(date('d/m/Y', strtotime($s['fecha']))) ?>
                                </span>
                            </div>
                        </div>

                        <div class="card-tags">
                            <?php if ($s['nombre_materia']): ?>
                                <span class="tag tag-materia">📚 <?= htmlspecialchars($s['nombre_materia']) ?></span>
                            <?php endif; ?>
                            <?php if ($s['nombre_curso']): ?>
                                <span class="tag tag-curso">🎓 <?= htmlspecialchars($s['nombre_curso']) ?></span>
                            <?php endif; ?>
                        </div>

                        <p class="card-mensaje"><?= nl2br(htmlspecialchars($s['mensaje'])) ?></p>

                        <div class="card-contacto">
                            <a href="mailto:<?= htmlspecialchars($s['email_alumno']) ?>" class="contacto-link">
                                ✉️ <?= htmlspecialchars($s['email_alumno']) ?>
                            </a>
                            <?php if ($s['telefono_alumno']): ?>
                                <a href="tel:<?= htmlspecialchars($s['telefono_alumno']) ?>" class="contacto-link">
                                    📞 <?= htmlspecialchars($s['telefono_alumno']) ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <form method="post" action="main_profe.php"
                              onsubmit="return confirm('¿Marcar esta solicitud como completada? Se eliminará de la lista.')">
                            <input type="hidden" name="id_solicitud" value="<?= (int) $s['id_solicitud'] ?>">
                            <button class="btn-completar" type="submit" name="completar">
                                ✔ Marcar como completada
                            </button>
                        </form>

                    </article>

                <?php endforeach; ?>
            </div>

        <?php endif; ?>

    </main>

</body>
</html>
