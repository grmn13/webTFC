<!DOCTYPE html>
<html>

	<head>
		<title>Login webprofes</title>
		<link rel="stylesheet" href="register.css">
		<meta charset="UTF-8">
	</head>
	<body>

		<form method="POST" action="register.php">

			<input type="radio" id="prof" name="usertype" value="prof">
			<label for="prof">SOY PROFESOR</label>
			<input type="radio" id="alum" name="usertype" value="alum">
			<label for="alum">SOY ALUMNO</label>

			<br>
                        <?php if (isset($error_msg) && !empty($error_msg)): ?>
                        <div style="background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; text-align: center; border: 1px solid #fecaca;">
                                <?php echo $error_msg; ?>
                        </div>
                        <?php endif; ?>
			<label for="username">Usuario:</label><br>
			<input type="text" id="username" name="username"><br>

			<label for="pwd">Contraseña:</label><br>
			<input type="password" id="pwd" name="pwd">

                        <label for="email">E-Mail:</label><br>
                        <input type="text" id="email" name="email"><br>

                        <label for="nombre">Nombre:</label>
                        <input type="text" id="nombre" name="nombre"><br>

                        <label for="apellidos">Apellidos:</label>
                        <input type="text" id="apellidos" name="apellidos"><br>

                        <label for="telefono">Telefono:</label>
                        <input type="text" id="telefono" name="telefono" max="999999999">

			<br>

			<input type="submit" value="REGISTER">
		</form>
	</body>
</html>

