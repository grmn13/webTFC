<?php

$passwd = "hola123";

$hashed = password_hash($passwd, PASSWORD_DEFAULT);

echo $passwd;
echo "<br>";
echo $hashed;
?>
