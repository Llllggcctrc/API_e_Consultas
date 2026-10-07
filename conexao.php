<?php
// Conexão com o PostgreSQL usando PDO (Singleton simples)

function conectar(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $host    = "192.168.10.98";
        $porta   = "5432";
        $banco   = "almoxarifado";
        $usuario = "postgres";
        $senha   = "hattorizxd";   

        $dsn = "pgsql:host=$host;port=$porta;dbname=$banco";

        $pdo = new PDO($dsn, $usuario, $senha, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}