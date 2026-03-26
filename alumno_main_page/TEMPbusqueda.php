

<!-- ya funciona la busqueda, tengo que ver como crear el div oculto
 con la informacion extra para que se muestre junto al form para enviar la
 solicitud, cuando cliques en un div -->

<?php
session_start();
if(!isset($_SESSION["UID"])){

	header("Location: ../login/login.html");
	exit();
}

// 1. Tell PHP to report ALL types of errors (notices, warnings, fatal)
error_reporting(E_ALL);

// 2. Force errors to be printed to the browser screen
ini_set('display_errors', 1);

// 3. Specifically show errors that occur during PHP's startup sequence
ini_set('display_startup_errors', 1);
$running = true;
/*
echo "Request Method: " . $_SERVER['REQUEST_METHOD'] . "<br>";
echo "Content Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'Not set') . "<br>";
echo "Content Length: " . ($_SERVER['CONTENT_LENGTH'] ?? '0') . "<br>";
echo var_dump($_POST);
*/

$mapa_materias = [];
$mapa_cursos = [];

$materias = $_POST['materias'] ?? [];
$materias = array_map('intval', $materias);


if(isset($_POST['curso']) && !empty($_POST['curso'])){

        $curso = $_POST['curso'];
}
else{

        $curso = NULL;
}

if(file_exists("../weblogs/log_busqueda.txt")){

	$logfile = fopen("../weblogs/log_busqueda.txt", "a") or die("log file openning error");
}
else{
	$logfile = fopen("../weblogs/log_busqueda.txt", "w") or die("log file openning error");
}

$servername = "localhost";
$username = "root";
$password = "1221";
$db = "webprofes";

try{

	$conn = new mysqli($servername, $username, $password, $db);
	fwrite($logfile, date(DATE_RFC822) . " New databse connection as: " . $username . "\n");
}
catch(mysqli_sql_exception $ex){

	 
        $running = false;
	fwrite($logfile, date(DATE_RFC822) . " Database connection error: " . $ex->getMessage() . "\n");
}	
	
if($running){

        $sql = "SELECT id, nombre FROM materias";
        $result = $conn->query($sql);

        if($result->num_rows > 0){

                while($row = $result->fetch_assoc()){

                        $mapa_materias[$row["id"]] = $row["nombre"];
                }
        }
        else{
                
                $running = false;
	        fwrite($logfile, date(DATE_RFC822) . " No se obtuvieron materias de la base de datos \n");
        }

        $sql = "SELECT id, nombre FROM cursos";
        $result = $conn->query($sql);

        if($result->num_rows > 0){

                while($row = $result->fetch_assoc()){

                        $mapa_cursos[$row["id"]] = $row["nombre"];
                }
        }
        else{
                
                $running = false;
	        fwrite($logfile, date(DATE_RFC822) . " No se obtuvieron materias de la base de datos \n");
        }

}

if($running){

        $profesores = [];

        $param_materias = "(" . implode(",", $materias) . ")";

        if(!empty($materias) and !empty($curso)){

	        $sql = "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path FROM profesores_materias pm INNER JOIN profesores p ON pm.id_profesor = p.id WHERE pm.id_materia IN $param_materias AND pm.id_curso >= $curso GROUP BY id_profesor HAVING COUNT(DISTINCT pm.id_materia) = " . count($materias);
        }
        else if(!empty($materias) and empty($curso)){

	        $sql = "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path FROM profesores_materias pm INNER JOIN profesores p ON pm.id_profesor = p.id WHERE pm.id_materia IN $param_materias GROUP BY id_profesor HAVING COUNT(DISTINCT pm.id_materia) = " . count($materias);
        }
        else if(empty($materia) and $curso != NULL){

	        $sql = "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path FROM profesores_materias pm INNER JOIN profesores p ON pm.id_profesor = p.id WHERE pm.id_curso >= $curso GROUP BY id_profesor";
        }
        else{

	        $sql = "SELECT pm.id_profesor, p.nombre, p.email, p.telefono, p.pfp_path FROM profesores_materias pm INNER JOIN profesores p ON pm.id_profesor = p.id";
        }

	$result = $conn->query($sql);

	if($result->num_rows > 0){

		while($row = $result->fetch_assoc()){

			$profesores[$row["id_profesor"]] = array($row["nombre"], $row["email"], $row["telefono"], $row["pfp_path"]);
		}
	}
	else{
		$profesores[0] = "NULL";
                $running = false;
		fwrite($logfile, date(DATE_RFC822) . " la consulta a profesores devolvió 0 resultados \n");
	}

        foreach($profesores as $profesor => $values){

                $sql = "SELECT id_materia, id_curso FROM profesores_materias WHERE id_profesor = $profesor";

                $result = $conn->query($sql);

                while($row = $result->fetch_assoc()){

                        $profesores[$profesor][4][] = $row["id_materia"];
                        $profesores[$profesor][5][] = $row["id_curso"];
                }
        }
        /*
        foreach($profesores as $profesor => $values){
                
                echo "profesor con id " . $profesor . ": <br>";
                echo "Nombre: " . $values[0] . "<br>";
                echo "Email: " . $values[1] . "<br>";
                echo "Telefono: " . $values[2] . "<br>";
                echo "pfp path: " . $values[3] . "<br>";
                echo "Materias: ";
                foreach($values[4] as $materia){

                        echo $materia . " ";
                }
                echo "<br>";
                echo "Curso mas alto: " . max($values[5]) . "<br>";

        }
        */
        //array profesores guarda los resultados
}

fclose($logfile);

?>

<!DOCTYPE html>
<html>
	<head>
		<title>Busqueda profesores</title>
		<meta charset="UTF-8">
		<link rel="stylesheet" href="busqueda.css">
	</head>
	<body>
                <h2>Resultados de tu búsqueda</h2>
                <ul>

                        <?php
                        if($running){

                                foreach($profesores as $profesor){


                                        echo "<li>";
                                        echo "<div class=\"card-container\">";
                                        
                                                echo "<img src=\"" . $profesor[3] . "\">";
                                                echo "<h4>" . $profesor[0] . "</h4>";

                                                echo "<div class=\"materias_profesor\">";
                                                foreach($profesor[4] as $p_materia){

                                                        echo "<p>" . $mapa_materias[$p_materia] . "</p>";
                                                }
                                                echo "</div>";
                                                
                                                echo "<div class=\"hidden-content\">";

                                                        echo "<img src=\"" . $profesor[3] . "\">";
                                                        echo "<h4>" . $profesor[0] . "</h4>";

                                                        echo "<form class=\"solicitud\" method=\"post\" action=\"crear_solicitud.php\">";
                                                        echo "<p>Email: " . $profesor[1] . "</p>";
                                                        echo "<p>Telefono: " . $profesor[2] . "</p>";
                                                        echo "<p>Nivel máximo: " . max($profesor[5]) . "</p>";
                                                        echo "<input type=\"hidden\" name=\"id_profesor\" value=\"" . $profesor . "\">";
                                                        echo "<input type=\"text\" name=\"texto\" value=\"Hola " . $profesor[0] . "! Tengo interés en recibir clases sobre las materias que impartes. Gracias!\">";
                                                        echo "<input type=\"submit\" value=\"Enviar mensaje\">";
                                                        echo "</form>";
                                                        echo "<button class=\"close-button\">X</button>";

                                                echo "</div>";


                                        echo "</div>";
                                        echo "</li>";

                                }
                        }
                        else{

                                echo "<div class=\"error\">";
                                echo "<h1>x_x</h1>";
                                echo "<h2>No hay resultados para tu búsqueda</h2>";
                                echo "</div>";
                        }
                        

                        ?>

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


