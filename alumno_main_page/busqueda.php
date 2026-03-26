<?php
session_start();
if (!isset($_SESSION["UID"])) {
    header("Location: ../login/login.html");
    exit();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once "../connect.php";

$running = true;

$mapa_materias = [];
$mapa_cursos   = [];

// ── Input sanitization ────────────────────────────────────────────────────────

$materias = $_POST['materias'] ?? [];
$materias = array_map('intval', $materias);

// Remove any zero/negative values that sneaked in
$materias = array_filter($materias, fn($m) => $m > 0);

$curso = (isset($_POST['curso']) && $_POST['curso'] !== '') ? (int) $_POST['curso'] : null;

// ── Logging ───────────────────────────────────────────────────────────────────

$log_path = "../weblogs/log_busqueda.txt";
$logfile  = fopen($log_path, file_exists($log_path) ? "a" : "w")
            or die("log file opening error");

// ── Database connection ───────────────────────────────────────────────────────

try {
    $conn = new mysqli($servername, $username, $password, $db);
    $conn->set_charset("utf8mb4");
    fwrite($logfile, date(DATE_RFC822) . " New database connection as: " . $username . "\n");
} catch (mysqli_sql_exception $ex) {
    $running = false;
    fwrite($logfile, date(DATE_RFC822) . " Database connection error: " . $ex->getMessage() . "\n");
}

// ── Load lookup maps ──────────────────────────────────────────────────────────

if ($running) {

    $result = $conn->query("SELECT id, nombre FROM materias");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $mapa_materias[$row["id"]] = $row["nombre"];
        }
    } else {
        $running = false;
        fwrite($logfile, date(DATE_RFC822) . " No se obtuvieron materias de la base de datos\n");
    }

    $result = $conn->query("SELECT id, nombre FROM cursos");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $mapa_cursos[$row["id"]] = $row["nombre"];
        }
    } else {
        $running = false;
        fwrite($logfile, date(DATE_RFC822) . " No se obtuvieron cursos de la base de datos\n");
    }
}

// ── Build professor query ─────────────────────────────────────────────────────

if ($running) {

    $profesores = [];

    /*
     * Four cases:
     *   1. materias + curso   → filter by both
     *   2. materias only      → filter by subject
     *   3. curso only         → filter by level
     *   4. neither            → return everyone
     *
     * Bug fixed: was checking `empty($materia)` (typo, undefined var).
     * Now correctly checks `empty($materias)`.
     */

    if (!empty($materias) && $curso !== null) {

        // Parameterised IN clause built with placeholders
        $placeholders = implode(',', array_fill(0, count($materias), '?'));
        $types        = str_repeat('i', count($materias)) . 'i'; // materias + curso
        $params       = array_merge($materias, [$curso]);

        $stmt = $conn->prepare(
            "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path
             FROM profesores_materias pm
             INNER JOIN profesores p ON pm.id_profesor = p.id
             WHERE pm.id_materia IN ($placeholders)
               AND pm.id_curso >= ?
             GROUP BY pm.id_profesor
             HAVING COUNT(DISTINCT pm.id_materia) = " . count($materias)
        );
        $stmt->bind_param($types, ...$params);

    } elseif (!empty($materias) && $curso === null) {

        $placeholders = implode(',', array_fill(0, count($materias), '?'));
        $types        = str_repeat('i', count($materias));

        $stmt = $conn->prepare(
            "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path
             FROM profesores_materias pm
             INNER JOIN profesores p ON pm.id_profesor = p.id
             WHERE pm.id_materia IN ($placeholders)
             GROUP BY pm.id_profesor
             HAVING COUNT(DISTINCT pm.id_materia) = " . count($materias)
        );
        $stmt->bind_param($types, ...$materias);

    } elseif (empty($materias) && $curso !== null) {  // Bug fixed: was $materia

        $stmt = $conn->prepare(
            "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path
             FROM profesores_materias pm
             INNER JOIN profesores p ON pm.id_profesor = p.id
             WHERE pm.id_curso >= ?
             GROUP BY pm.id_profesor"
        );
        $stmt->bind_param('i', $curso);

    } else {

        $stmt = $conn->prepare(
            "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path
             FROM profesores_materias pm
             INNER JOIN profesores p ON pm.id_profesor = p.id
             GROUP BY pm.id_profesor"
        );
    }

    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $profesores[$row["id_profesor"]] = [
                $row["nombre"],   // [0]
                $row["email"],    // [1]
                $row["telefono"], // [2]
                $row["pfp_path"], // [3]
                [],               // [4] materias
                [],               // [5] cursos
            ];
        }
    } else {
        $running = false;
        fwrite($logfile, date(DATE_RFC822) . " La consulta a profesores devolvió 0 resultados\n");
    }

    $stmt->close();

    // ── Fetch each professor's subjects & levels ──────────────────────────────

    if ($running) {

        $stmt = $conn->prepare(
            "SELECT id_materia, id_curso FROM profesores_materias WHERE id_profesor = ?"
        );

        foreach ($profesores as $id_prof => &$values) {

            $stmt->bind_param('i', $id_prof);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $values[4][] = $row["id_materia"];
                $values[5][] = $row["id_curso"];
            }
        }
        unset($values); // break the reference after the loop

        $stmt->close();
    }
}

fclose($logfile);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <title>Búsqueda de profesores</title>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="busqueda.css">
</head>
<body>
    <h2>Resultados de tu búsqueda</h2>
    <ul>
        <?php if ($running): ?>

            <?php foreach ($profesores as $id_prof => $values): ?>
                <li>
                    <div class="card-container">

                        <img src="<?= htmlspecialchars($values[3]) ?>" alt="Foto de <?= htmlspecialchars($values[0]) ?>">
                        <h4><?= htmlspecialchars($values[0]) ?></h4>

                        <div class="materias_profesor">
                            <?php foreach ($values[4] as $id_materia): ?>
                                <p><?= htmlspecialchars($mapa_materias[$id_materia] ?? '') ?></p>
                            <?php endforeach; ?>
                        </div>

                        <div class="hidden-content">

                            <img src="<?= htmlspecialchars($values[3]) ?>" alt="Foto de <?= htmlspecialchars($values[0]) ?>">
                            <h4><?= htmlspecialchars($values[0]) ?></h4>

                            <form class="solicitud" method="post" action="crear_solicitud.php">

                                <p>Email: <?= htmlspecialchars($values[1]) ?></p>
                                <p>Teléfono: <?= htmlspecialchars($values[2]) ?></p>
                                <p>Nivel máximo: <?= htmlspecialchars($mapa_cursos[max($values[5])] ?? max($values[5])) ?></p>

                                <!-- Bug fixed: was outputting the array, now correctly outputs the professor ID -->
                                <input type="hidden" name="id_profesor" value="<?= $id_prof ?>">

                                <textarea name="texto">Hola <?= htmlspecialchars($values[0]) ?>! Tengo interés en recibir clases sobre las materias que impartes. Gracias!</textarea>

                                <input type="submit" value="Enviar mensaje">

                            </form>

                            <button class="close-button">X</button>

                        </div>

                    </div>
                </li>
            <?php endforeach; ?>

        <?php else: ?>

            <div class="error">
                <h1>x_x</h1>
                <h2>No hay resultados para tu búsqueda</h2>
            </div>

        <?php endif; ?>
    </ul>

    <div class="modal-backdrop"></div>

    <script>
        const backdrop = document.querySelector('.modal-backdrop');

        document.querySelectorAll('.card-container').forEach(card => {
            card.addEventListener('click', () => {
                const popup = card.querySelector('.hidden-content');
                popup.classList.add('active');
                backdrop.classList.add('active');
            });
        });

        document.querySelectorAll('.close-button').forEach(btn => {
            btn.addEventListener('click', e => {
                e.stopPropagation();
                closeModal();
            });
        });

        backdrop.addEventListener('click', closeModal);

        function closeModal() {
            document.querySelectorAll('.hidden-content.active')
                .forEach(p => p.classList.remove('active'));
            backdrop.classList.remove('active');
        }
    </script>

</body>
</html>
