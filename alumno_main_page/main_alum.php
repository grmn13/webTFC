<?php
session_start();


if(!isset($_SESSION["UID"])){

	header("Location: ../login/login.html");
	exit();
}

if(file_exists("../weblogs/log_main_alum.txt")){

	$logfile = fopen("../weblogs/log_main_alum.txt", "a");
}
else{
	$logfile = fopen("../weblogs/log_main_alum.txt", "w");
}


$servername = "localhost";
$username = "root";
$password = "1221";
$db = "webprofes";

$running = true;


try{

	$conn = new mysqli($servername, $username, $password, $db);
	fwrite($logfile, date(DATE_RFC822) . " New databse connection as: " . $username . "\n");
}
catch(mysqli_sql_exception $ex){

	$running = false;
	fwrite($logfile, date(DATE_RFC822) . " Database connection error: " . $ex->getMessage() . "\n");
}

if($running){

	$materias = [];
	$cursos = [];

	$sql = "SELECT id, nombre FROM materias";
	$result = $conn->query($sql);

	if($result->num_rows > 0){

		while($row = $result->fetch_assoc()){

			$materias[$row["id"]] = $row["nombre"];
		}
	}
	else{
		$materias[0] = "NULL";
		fwrite($logfile, date(DATE_RFC822) . " la consulta a materias devolvió 0 resultados \n");
	}

	$sql = "SELECT id, nombre FROM cursos";
	$result = $conn->query($sql);

	if($result->num_rows > 0){

		while($row = $result->fetch_assoc()){

			$cursos[$row["id"]] = $row["nombre"];
		}
	}
	else{
		$cursos[0] = "NULL";
		fwrite($logfile, date(DATE_RFC822) . " la consulta a cursos devolvió 0 resultados \n");
	}

}

fclose($logfile);

?>

<!DOCTYPE html>
<html>
	<head>
		<title>Alumno main page</title>
		<meta charset="UTF-8">
		<link rel="stylesheet" href="main_alum.css">
	</head>
	<body>

		<div>
		<form method="POST" action="busqueda.php">
		
		<h3>Asignaturas</h3><br>
		
		<?php

		foreach($materias as $mat_id => $nombre){
		
			echo "<input type=\"checkbox\" id=\"mat_$mat_id\" value=\"$mat_id\" name=\"materias[]\">";
			echo "<label for=\"mat_$mat_id\">$nombre</label>";
		}
		
		echo "<h3>Curso</h3><br>";
		foreach($cursos as $cur_id => $nombre){
		
			echo "<input type=\"radio\" id=\"cur_$cur_id\" value=\"$cur_id\" name=\"curso\">";
			echo "<label for=\"cur_$cur_id\">$nombre</label>";
		}
		
		?>

		<input type="submit" value="BUSCAR">
		</form>
		</div>
	
	</body>
</html>
