<?php
/**
 * export-products.php
 *
 * Simple CLI exporter: reads DB_CONNECTION_STRING from env, runs a query and writes JSON to pjm-data/products.json
 *
 * Usage (in GitHub Actions): php ./export-products.php
 *
 * DB_CONNECTION_STRING format (example):
 *   mysql://dbuser:secret@db-host.example.com:3306/mydatabase
 *
 * Edit $query below to match your actual products table and columns.
 */

$dsnEnv = getenv('DB_CONNECTION_STRING');

if (!$dsnEnv) {
    fwrite(STDERR, "ERROR: DB_CONNECTION_STRING environment variable is not set.\n");
    exit(1);
}

// Parse a DSN like: mysql://user:pass@host:port/dbname
$parts = parse_url($dsnEnv);
if ($parts === false || !isset($parts['scheme']) || $parts['scheme'] !== 'mysql') {
    fwrite(STDERR, "ERROR: DB_CONNECTION_STRING has wrong format. Expected mysql://user:pass@host:port/dbname\n");
    exit(1);
}

$user = $parts['user'] ?? '';
$pass = $parts['pass'] ?? '';
$host = $parts['host'] ?? '127.0.0.1';
$port = $parts['port'] ?? 3306;
$dbname = ltrim($parts['path'] ?? '', '/');

if (!$dbname) {
    fwrite(STDERR, "ERROR: database name missing in DB_CONNECTION_STRING\n");
    exit(1);
}

// Configure DSN for PDO
$pdoDsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

try {
    $pdo = new PDO($pdoDsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Exception $ex) {
    fwrite(STDERR, "ERROR: could not connect to database: " . $ex->getMessage() . "\n");
    exit(1);
}

// ====== ADAPT THIS QUERY TO YOUR SCHEMA ======
// Example: select id, title, price from your products table
// If your product table is wp_posts + meta or WooCommerce, you will need to join wp_postmeta or use WooCommerce REST API instead.
$query = "SELECT id, title, price FROM products LIMIT 10000";
// ============================================

try {
    $stmt = $pdo->query($query);
    $rows = $stmt->fetchAll();
} catch (Exception $ex) {
    fwrite(STDERR, "ERROR: query failed: " . $ex->getMessage() . "\n");
    exit(1);
}

$outputDir = 'pjm-data';
if (!is_dir($outputDir)) {
    if (!mkdir($outputDir, 0775, true)) {
        fwrite(STDERR, "ERROR: failed to create output directory: {$outputDir}\n");
        exit(1);
    }
}

$outFile = $outputDir . DIRECTORY_SEPARATOR . 'products.json';
$json = json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    fwrite(STDERR, "ERROR: failed to encode JSON\n");
    exit(1);
}

if (file_put_contents($outFile, $json) === false) {
    fwrite(STDERR, "ERROR: failed to write file: {$outFile}\n");
    exit(1);
}

echo "Wrote " . count($rows) . " products to {$outFile}\n";
exit(0);
