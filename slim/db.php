<?php
function getDB(): PDO
{
    $host = 'db';
    $dbname = 'seminariophp';
    $user = 'seminariophp';
    $pass = 'seminariophp';

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    // las fechas (TIMESTAMP) se leen y se guardan siempre en UTC, sin depender de la zona del servidor
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
