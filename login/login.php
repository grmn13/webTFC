<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once "../connect.php";

$errors   = [];
$usertype = '';
$web_username = '';

// ── Only run on POST ──────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Collect & validate
    $usertype     = $_POST['usertype']  ?? '';
    $web_username = trim($_POST['username'] ?? '');
    $pwd          = $_POST['pwd'] ?? '';

    if ($usertype === '') {
        $errors[] = "Selecciona si eres profesor o alumno.";
    }
    if ($web_username === '') {
        $errors[] = "Introduce tu nombre de usuario.";
    }
    if ($pwd === '') {
        $errors[] = "Introduce tu contraseña.";
    }

    // ── DB lookup (only if basic fields are present) ──────────────────────────

    if (empty($errors)) {

        // Logging
        $log_path = "../weblogs/log_login.txt";
        $logfile  = fopen($log_path, file_exists($log_path) ? "a" : "w");

        try {
            $conn = new mysqli($servername, $username, $password, $db);
            $conn->set_charset("utf8mb4");
        } catch (mysqli_sql_exception $ex) {
            $errors[] = "Error de conexión. Inténtalo de nuevo.";
            fwrite($logfile, date(DATE_RFC822) . " DB connection error: " . $ex->getMessage() . "\n");
            fclose($logfile);
        }

        if (empty($errors)) {

            $table = ($usertype === 'prof') ? 'profesores' : 'alumnos';
            $stmt  = $conn->prepare("SELECT id, passwd FROM $table WHERE usuario = ?");
            $stmt->bind_param("s", $web_username);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $conn->close();

            if ($row && password_verify($pwd, $row['passwd'])) {

                $_SESSION["UID"]   = $row["id"];
                $_SESSION["UTYPE"] = $usertype;

                fwrite($logfile, date(DATE_RFC822) . " Successful login ($usertype) id: " . $row["id"] . "\n");
                fclose($logfile);

                header("Location: " . ($usertype === 'prof'
                    ? "../profesor_main_page/main_profe.php"
                    : "../alumno_main_page/main_alum.php"));
                exit();

            } else {

                $errors[] = "Usuario o contraseña incorrectos.";
                fwrite($logfile, date(DATE_RFC822) . " Failed login ($usertype) usuario: $web_username\n");
                fclose($logfile);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <title>Login WebProfes</title>
    <link rel="stylesheet" href="login.css">
    <meta charset="UTF-8">
</head>
<body>
    <form method="POST" action="login.php">

        <input type="radio" id="prof" name="usertype" value="prof"
               <?= ($usertype === 'prof') ? 'checked' : '' ?>>
        <label for="prof">Soy profesor</label>
        <input type="radio" id="alum" name="usertype" value="alum"
               <?= ($usertype === 'alum') ? 'checked' : '' ?>>
        <label for="alum">Soy alumno</label>

        <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success'): ?>
            <div class="login-success">
                ✅ ¡Cuenta creada correctamente! Ya puedes iniciar sesión.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <ul class="login-errors">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <label for="username">Usuario:</label>
        <input type="text" id="username" name="username"
               value="<?= htmlspecialchars($web_username) ?>"
               class="<?= (!empty($errors) && $web_username === '') ? 'input-error' : '' ?>">

        <label for="pwd">Contraseña:</label>
        <input type="password" id="pwd" name="pwd"
               class="<?= (!empty($errors) && empty($_POST['pwd'])) ? 'input-error' : '' ?>">

        <input type="submit" value="Entrar">

        <p class="register-hint">
            ¿No tienes cuenta?
            <button type="button" class="btn-secondary"
                    onclick="window.location.href='../register/register_html.php'">
                Registrarse
            </button>
        </p>

    </form>
</body>
</html>
