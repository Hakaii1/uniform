<?php
$serverName = "Zeus\\SQLEXPRESS"; 
$database   = "local"; 
$username   = ""; 
$password   = ""; 

try {
    $conn = new PDO(
        "sqlsrv:Server=$serverName;Database=$database",
        $username, 
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>