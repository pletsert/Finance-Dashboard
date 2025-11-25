<?php
$root = dirname(__DIR__, 2);
$dotenv = $root . '/.env';
$env = is_file($dotenv) ? parse_ini_file($dotenv, false, INI_SCANNER_RAW) : [];
$dbDriver = $env['DB_DRIVER'] ?? 'sqlite';
if ($dbDriver === 'mysql') {
  $host = $env['DB_HOST'] ?? '127.0.0.1';
  $name = $env['DB_NAME'] ?? 'finance';
  $user = $env['DB_USER'] ?? 'root';
  $pass = $env['DB_PASS'] ?? '';
  $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";
  $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
} else {
  $dataDir = $root . '/data';
  if (!is_dir($dataDir)) mkdir($dataDir, 0775, true);
  $dsn = "sqlite:" . $dataDir . "/finance.sqlite";
  $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $schema = $root . '/database/schema.sql';
  if (is_file($schema)) {
    $sql = file_get_contents($schema);
    $pdo->exec($sql);
  }
}
?>