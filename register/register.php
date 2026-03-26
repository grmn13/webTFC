<?php
session_start();
// 1. Error Reporting for development
error_reporting(E_ALL);
ini_set('display_errors', 1);

include "../connect.php";

$running = true;
$error_msg = "";

// Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Basic Validation: Ensure all fields are present
    $required_fields = ["usertype", "username", "pwd", "email", "nombre", "apellidos", "telefono"];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty(trim($_POST[$field]))) {
            $error_msg = "Todos los campos son obligatorios.";
            $running = false;
            break;
        }
    }

    if ($running) {
        // Sanitize inputs
        $usertype  = $_POST["usertype"]; // 'prof' or 'alum'
        $user_name = trim($_POST["username"]);
        $email     = trim($_POST["email"]);
        $nombre    = trim($_POST["nombre"]);
        $apellidos = trim($_POST["apellidos"]);
        $telefono  = trim($_POST["telefono"]);
        
        // Hash the password for security
        $hashed_pwd = password_hash($_POST["pwd"], PASSWORD_DEFAULT);

        try {
            $conn = new mysqli($servername, $username, $password, $db);
            
            // Choose the table based on user type
            $table = ($usertype === "prof") ? "profesores" : "alumnos";

            // Prepare Statement to prevent SQL Injection
            // Note: I'm leaving out fields like 'pfp_path' or 'curso' for now as they aren't in your HTML
            $stmt = $conn->prepare("INSERT INTO $table (usuario, passwd, nombre, apellidos, email, telefono) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssss", $user_name, $hashed_pwd, $nombre, $apellidos, $email, $telefono);

            if ($stmt->execute()) {
                // Success! Redirect to login
                header("Location: ../login/login.php?registered=success");
                exit();
            } else {
                $error_msg = "Error: El usuario o email ya existen.";
                $running = false;
            }
            $stmt->close();
            $conn->close();

        } catch (mysqli_sql_exception $ex) {
            $error_msg = "Error de base de datos: " . $ex->getMessage();
            $running = false;
        }
    }
}

// If something went wrong, reload the form and show the error
if (!$running || $_SERVER["REQUEST_METHOD"] != "POST") {
    include "register_html.php";
}
else{

        header("Location: ../login/login.html");
        exit();
}
?>
