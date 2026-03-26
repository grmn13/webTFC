<?php
session_start();

if (!isset($_SESSION["UID"])) {
    header("Location: ../login/login.html");
    exit();
}

require_once "../connect.php";

$id_profesor = (int) $_SESSION["UID"];
$errors      = [];
$success     = false;

// Directory where profile pictures are stored, relative to this file.
// Make sure this folder exists and is writable by your web server.
define('PFP_DIR',     __DIR__ . '/../uploads/pfp/');
define('PFP_WEB',     '../uploads/pfp/');       // web-accessible path stored in DB
define('PFP_MAX',     2 * 1024 * 1024);         // 2 MB
define('PFP_DEFAULT', '../assets/default_pfp.png');
define('PFP_ALLOWED', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// ── Handle POST ───────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & sanitize text fields
    $nombre    = trim($_POST['nombre']    ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    $telefono  = trim($_POST['telefono']  ?? '');

    $pwd_actual = $_POST['pwd_actual'] ?? '';
    $pwd_nueva  = $_POST['pwd_nueva']  ?? '';
    $pwd_repite = $_POST['pwd_repite'] ?? '';

    // ── Validate text fields ──────────────────────────────────────────────────

    if ($nombre === '') {
        $errors[] = "El nombre no puede estar vacío.";
    } elseif (mb_strlen($nombre) > 30) {
        $errors[] = "El nombre no puede superar 30 caracteres.";
    }

    if ($apellidos === '') {
        $errors[] = "Los apellidos no pueden estar vacíos.";
    } elseif (mb_strlen($apellidos) > 50) {
        $errors[] = "Los apellidos no pueden superar 50 caracteres.";
    }

    if ($email === '') {
        $errors[] = "El email no puede estar vacío.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "El formato del email no es válido.";
    } elseif (mb_strlen($email) > 50) {
        $errors[] = "El email no puede superar 50 caracteres.";
    }

    if ($telefono !== '' && !preg_match('/^[0-9]{9}$/', $telefono)) {
        $errors[] = "El teléfono debe tener exactamente 9 dígitos.";
    }

    // ── Collect submitted subjects ────────────────────────────────────────────
    // Form sends: materias[] = checked id_materia values
    //             curso_materia[{id_materia}] = selected id_curso
    // We build a clean map: [id_materia => id_curso]

    $materias_input = [];
    $curso_materia_raw = $_POST['curso_materia'] ?? [];

    foreach (($_POST['materias'] ?? []) as $raw_id_m) {
        $id_m = (int) $raw_id_m;
        $id_c = (int) ($curso_materia_raw[$id_m] ?? 0);

        if ($id_m <= 0) continue;

        if ($id_c <= 0) {
            $errors[] = "Selecciona un nivel para cada materia marcada.";
            break;
        }
        $materias_input[$id_m] = $id_c;
    }

    // ── Validate uploaded photo (optional) ───────────────────────────────────

    $new_pfp_path = null; // null = no new file uploaded, keep existing

    $file = $_FILES['pfp'] ?? null;

    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = "Error al subir el archivo (código " . (int)$file['error'] . ").";
        } elseif ($file['size'] > PFP_MAX) {
            $errors[] = "La imagen no puede superar 2 MB.";
        } else {
            // Use finfo to check the REAL mime type, not the browser-supplied one
            $finfo    = new finfo(FILEINFO_MIME_TYPE);
            $mimetype = $finfo->file($file['tmp_name']);

            if (!in_array($mimetype, PFP_ALLOWED, true)) {
                $errors[] = "Solo se aceptan imágenes JPG, PNG, GIF o WEBP.";
            } else {
                // Build a unique filename: {id_profesor}_{timestamp}.{ext}
                $ext          = match($mimetype) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                };
                $new_filename = $id_profesor . '_' . time() . '.' . $ext;
                $new_pfp_path = PFP_WEB . $new_filename;
                $dest         = PFP_DIR . $new_filename;
            }
        }
    }

    // ── Password change ───────────────────────────────────────────────────────

    $cambiar_pwd = $pwd_actual !== '';

    if ($cambiar_pwd) {
        if ($pwd_nueva === '') {
            $errors[] = "Escribe la nueva contraseña.";
        } elseif (mb_strlen($pwd_nueva) < 8) {
            $errors[] = "La nueva contraseña debe tener al menos 8 caracteres.";
        } elseif ($pwd_nueva !== $pwd_repite) {
            $errors[] = "Las contraseñas nuevas no coinciden.";
        }
    }

    // ── DB operations ─────────────────────────────────────────────────────────

    if (empty($errors)) {

        $conn = new mysqli($servername, $username, $password, $db);
        $conn->set_charset("utf8mb4");

        if ($conn->connect_error) {
            $errors[] = "Error de conexión con la base de datos.";
        } else {

            // Duplicate email check
            $stmt = $conn->prepare(
                "SELECT id FROM profesores WHERE email = ? AND id != ?"
            );
            $stmt->bind_param("si", $email, $id_profesor);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows > 0) {
                $errors[] = "Ese email ya está en uso por otra cuenta.";
            }
            $stmt->close();

            // Verify current password if a change was requested
            if (empty($errors) && $cambiar_pwd) {

                $stmt = $conn->prepare("SELECT passwd FROM profesores WHERE id = ?");
                $stmt->bind_param("i", $id_profesor);
                $stmt->execute();
                $row_pwd = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row_pwd || !password_verify($pwd_actual, $row_pwd['passwd'])) {
                    $errors[] = "La contraseña actual no es correcta.";
                }
            }

            if (empty($errors)) {

                // Fetch existing pfp_path so we can delete the old file after saving
                $stmt = $conn->prepare("SELECT pfp_path FROM profesores WHERE id = ?");
                $stmt->bind_param("i", $id_profesor);
                $stmt->execute();
                $old_row  = $stmt->get_result()->fetch_assoc();
                $old_path = $old_row['pfp_path'] ?? '';
                $stmt->close();

                // Decide which path goes into the DB
                // If a new file was uploaded use it, otherwise keep the existing one
                $pfp_to_save = $new_pfp_path ?? $old_path;

                // Build the UPDATE depending on whether the password changes
                if ($cambiar_pwd) {
                    $hash = password_hash($pwd_nueva, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        "UPDATE profesores
                         SET nombre=?, apellidos=?, email=?, telefono=?, pfp_path=?, passwd=?
                         WHERE id=?"
                    );
                    $stmt->bind_param("ssssssi",
                        $nombre, $apellidos, $email, $telefono, $pfp_to_save, $hash,
                        $id_profesor
                    );
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE profesores
                         SET nombre=?, apellidos=?, email=?, telefono=?, pfp_path=?
                         WHERE id=?"
                    );
                    $stmt->bind_param("sssssi",
                        $nombre, $apellidos, $email, $telefono, $pfp_to_save,
                        $id_profesor
                    );
                }

                if ($stmt->execute()) {

                    // ── Sync profesores_materias ──────────────────────────────
                    // Fetch what the teacher currently has
                    $cur_stmt = $conn->prepare(
                        "SELECT id_materia, id_curso FROM profesores_materias WHERE id_profesor = ?"
                    );
                    $cur_stmt->bind_param("i", $id_profesor);
                    $cur_stmt->execute();
                    $cur_res = $cur_stmt->get_result();
                    $current_materias = []; // [id_materia => id_curso]
                    while ($r = $cur_res->fetch_assoc()) {
                        $current_materias[(int)$r['id_materia']] = (int)$r['id_curso'];
                    }
                    $cur_stmt->close();

                    // Delete subjects the teacher no longer teaches.
                    // Note: solicitudes referencing (id_profesor, id_materia) will
                    // cascade-delete per the FK definition in the schema.
                    $del_stmt = $conn->prepare(
                        "DELETE FROM profesores_materias WHERE id_profesor = ? AND id_materia = ?"
                    );
                    foreach ($current_materias as $id_m => $id_c) {
                        if (!array_key_exists($id_m, $materias_input)) {
                            $del_stmt->bind_param("ii", $id_profesor, $id_m);
                            $del_stmt->execute();
                        }
                    }
                    $del_stmt->close();

                    // Insert new subjects / update curso if it changed
                    $upsert_stmt = $conn->prepare(
                        "INSERT INTO profesores_materias (id_profesor, id_materia, id_curso)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE id_curso = VALUES(id_curso)"
                    );
                    foreach ($materias_input as $id_m => $id_c) {
                        $upsert_stmt->bind_param("iii", $id_profesor, $id_m, $id_c);
                        $upsert_stmt->execute();
                    }
                    $upsert_stmt->close();

                    // DB saved — now move the uploaded file into place
                    if ($new_pfp_path !== null) {

                        // Create the uploads directory if it doesn't exist yet
                        if (!is_dir(PFP_DIR)) {
                            mkdir(PFP_DIR, 0755, true);
                        }

                        if (move_uploaded_file($file['tmp_name'], $dest)) {
                            // Delete the old file if it was one we stored
                            // (don't delete external URLs or the default asset)
                            if ($old_path !== '' && str_starts_with($old_path, PFP_WEB)) {
                                $old_abs = __DIR__ . '/' . ltrim($old_path, '.');
                                if (is_file($old_abs)) {
                                    unlink($old_abs);
                                }
                            }
                            $success = true;
                        } else {
                            // File move failed — roll back the pfp_path in the DB
                            $stmt2 = $conn->prepare(
                                "UPDATE profesores SET pfp_path=? WHERE id=?"
                            );
                            $stmt2->bind_param("si", $old_path, $id_profesor);
                            $stmt2->execute();
                            $stmt2->close();
                            $errors[] = "No se pudo guardar la imagen. El resto del perfil sí se actualizó.";
                        }

                    } else {
                        $success = true;
                    }

                } else {
                    $errors[] = "No se pudo guardar. Inténtalo de nuevo.";
                }

                $stmt->close();
            }

            $conn->close();
        }
    }
}

// ── Fetch current profile for the form ───────────────────────────────────────

$conn = new mysqli($servername, $username, $password, $db);
$conn->set_charset("utf8mb4");

$profesor = [
    'nombre'    => '',
    'apellidos' => '',
    'email'     => '',
    'telefono'  => '',
    'pfp_path'  => '',
];

// All available subjects and course levels (for the checkboxes/selects)
$all_materias = []; // [id => nombre]
$all_cursos   = []; // [id => nombre]

// Subjects this teacher currently teaches: [id_materia => id_curso]
$teacher_materias = [];

if (!$conn->connect_error) {

    $stmt = $conn->prepare(
        "SELECT nombre, apellidos, email, telefono, pfp_path FROM profesores WHERE id = ?"
    );
    $stmt->bind_param("i", $id_profesor);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $row['telefono'] = $row['telefono'] ?? '';
        $row['pfp_path'] = $row['pfp_path'] ?? '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
            // Repopulate with what the user typed on a failed submit
            $profesor['nombre']    = $_POST['nombre']    ?? $row['nombre'];
            $profesor['apellidos'] = $_POST['apellidos'] ?? $row['apellidos'];
            $profesor['email']     = $_POST['email']     ?? $row['email'];
            $profesor['telefono']  = $_POST['telefono']  ?? $row['telefono'];
            $profesor['pfp_path']  = $row['pfp_path']; // always from DB; file inputs can't be repopulated
        } else {
            $profesor = $row;
        }
    }

    // Fetch all materias
    $res = $conn->query("SELECT id, nombre FROM materias ORDER BY nombre");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $all_materias[(int)$r['id']] = $r['nombre'];
        }
    }

    // Fetch all cursos ordered by difficulty
    $res = $conn->query("SELECT id, nombre FROM cursos ORDER BY dificultad");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $all_cursos[(int)$r['id']] = $r['nombre'];
        }
    }

    // Fetch teacher's current subjects
    // On a failed POST repopulate from what they submitted so checkboxes stay ticked
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success && !empty($materias_input)) {
        $teacher_materias = $materias_input;
    } else {
        $stmt = $conn->prepare(
            "SELECT id_materia, id_curso FROM profesores_materias WHERE id_profesor = ?"
        );
        $stmt->bind_param("i", $id_profesor);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $teacher_materias[(int)$r['id_materia']] = (int)$r['id_curso'];
        }
        $stmt->close();
    }

    $conn->close();
}

$pfp_preview = ($profesor['pfp_path'] !== '')
    ? htmlspecialchars($profesor['pfp_path'])
    : PFP_DEFAULT;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar perfil</title>
    <link rel="stylesheet" href="editar_perfil.css">
</head>
<body>

    <header class="navbar">
        <div class="navbar-brand">
            <span class="brand-dot"></span>
            <span class="brand-name">WebProfes</span>
        </div>
        <a class="btn-volver" href="main_profe.php">← Volver al panel</a>
    </header>

    <main class="contenido">
        <div class="perfil-card">

            <!-- Avatar preview -->
            <div class="avatar-section">
                <div class="avatar-wrapper">
                    <img id="pfp-preview" class="avatar-img" src="<?= $pfp_preview ?>" alt="Foto de perfil">
                    <label for="pfp" class="avatar-overlay" title="Cambiar foto">
                        <span class="avatar-overlay-icon">📷</span>
                    </label>
                </div>
                <p class="avatar-hint">Haz clic en la foto para cambiarla</p>
            </div>

            <h2 class="form-title">Editar perfil</h2>

            <?php if ($success): ?>
                <div class="banner banner-success">✅ Perfil actualizado correctamente.</div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <ul class="error-list">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <!--
                enctype="multipart/form-data" is required for file uploads.
                The file input is hidden and triggered by clicking the avatar.
            -->
            <form method="post" action="editar_perfil.php" enctype="multipart/form-data">

                <!-- Hidden file input linked to the avatar overlay -->
                <input type="file" id="pfp" name="pfp"
                       accept="image/jpeg,image/png,image/gif,image/webp"
                       style="display:none">

                <!-- Selected-file indicator (shown by JS) -->
                <div id="pfp-chosen" class="pfp-chosen" style="display:none">
                    <span id="pfp-chosen-name"></span>
                    <button type="button" id="pfp-clear">✕ Quitar</button>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nombre">Nombre</label>
                        <input type="text" id="nombre" name="nombre"
                               maxlength="30" required
                               value="<?= htmlspecialchars($profesor['nombre']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="apellidos">Apellidos</label>
                        <input type="text" id="apellidos" name="apellidos"
                               maxlength="50" required
                               value="<?= htmlspecialchars($profesor['apellidos']) ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email"
                           maxlength="50" required
                           value="<?= htmlspecialchars($profesor['email']) ?>">
                </div>

                <div class="form-group">
                    <label for="telefono">Teléfono <span class="optional">(9 dígitos)</span></label>
                    <input type="tel" id="telefono" name="telefono"
                           maxlength="9" pattern="[0-9]{9}"
                           value="<?= htmlspecialchars($profesor['telefono']) ?>">
                </div>

                <div class="separator">
                    <span>Materias que impartes</span>
                </div>

                <?php if (empty($all_materias)): ?>
                    <p class="materias-empty">No hay materias disponibles en el sistema.</p>
                <?php else: ?>
                    <div class="materias-grid">
                        <?php foreach ($all_materias as $id_m => $nombre_m):
                            $checked    = array_key_exists($id_m, $teacher_materias);
                            $cur_curso  = $teacher_materias[$id_m] ?? 0;
                        ?>
                            <div class="materia-row" id="mrow-<?= $id_m ?>">

                                <label class="materia-check-label">
                                    <input type="checkbox"
                                           class="materia-checkbox"
                                           name="materias[]"
                                           value="<?= $id_m ?>"
                                           data-id="<?= $id_m ?>"
                                           <?= $checked ? 'checked' : '' ?>>
                                    <span class="materia-check-box"></span>
                                    <span class="materia-nombre"><?= htmlspecialchars($nombre_m) ?></span>
                                </label>

                                <div class="materia-curso-wrap <?= $checked ? 'visible' : '' ?>"
                                     id="curso-wrap-<?= $id_m ?>">
                                    <select name="curso_materia[<?= $id_m ?>]"
                                            class="materia-curso-select"
                                            id="curso-select-<?= $id_m ?>">
                                        <option value="0">— Nivel máximo —</option>
                                        <?php foreach ($all_cursos as $id_c => $nombre_c): ?>
                                            <option value="<?= $id_c ?>"
                                                <?= ($cur_curso === $id_c) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($nombre_c) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="separator">
                    <span>Cambiar contraseña <span class="optional">(opcional)</span></span>
                </div>

                <div class="form-group">
                    <label for="pwd_actual">Contraseña actual</label>
                    <input type="password" id="pwd_actual" name="pwd_actual" autocomplete="current-password">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="pwd_nueva">Nueva contraseña</label>
                        <input type="password" id="pwd_nueva" name="pwd_nueva"
                               autocomplete="new-password" minlength="8">
                    </div>
                    <div class="form-group">
                        <label for="pwd_repite">Repite la nueva</label>
                        <input type="password" id="pwd_repite" name="pwd_repite"
                               autocomplete="new-password">
                    </div>
                </div>

                <input type="submit" value="Guardar cambios">

            </form>
        </div>
    </main>

    <script>
        // ── Avatar upload preview ─────────────────────────────────────────────
        const fileInput   = document.getElementById('pfp');
        const preview     = document.getElementById('pfp-preview');
        const chosenBar   = document.getElementById('pfp-chosen');
        const chosenName  = document.getElementById('pfp-chosen-name');
        const clearBtn    = document.getElementById('pfp-clear');
        const defaultSrc  = '<?= PFP_DEFAULT ?>';

        fileInput.addEventListener('change', () => {
            const file = fileInput.files[0];
            if (!file) return;
            const reader = new FileReader();
            reader.onload = e => { preview.src = e.target.result; };
            reader.readAsDataURL(file);
            chosenName.textContent = file.name;
            chosenBar.style.display = 'flex';
        });

        clearBtn.addEventListener('click', () => {
            fileInput.value = '';
            preview.src = '<?= $pfp_preview ?>';
            chosenBar.style.display = 'none';
        });

        preview.addEventListener('error', () => { preview.src = defaultSrc; });

        // ── Subject checkbox → show/hide course selector ──────────────────────
        document.querySelectorAll('.materia-checkbox').forEach(cb => {
            cb.addEventListener('change', () => {
                const wrap = document.getElementById('curso-wrap-' + cb.dataset.id);
                if (cb.checked) {
                    wrap.classList.add('visible');
                } else {
                    wrap.classList.remove('visible');
                    // Reset select so the POST validation sees 0
                    document.getElementById('curso-select-' + cb.dataset.id).value = '0';
                }
            });
        });
    </script>

</body>
</html>
