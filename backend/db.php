<?php

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 3600');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/response-encryption.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/encryption.php';

responseEncryptionStart();

function dbTableExists(PDO $conn, string $tableName): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([':table_name' => $tableName]);

    return intval($stmt->fetchColumn()) > 0;
}

function dbColumnExists(PDO $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':column_name' => $columnName,
    ]);

    return intval($stmt->fetchColumn()) > 0;
}

function dbIndexExists(PDO $conn, string $tableName, string $indexName): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        ':table_name' => $tableName,
        ':index_name' => $indexName,
    ]);

    return intval($stmt->fetchColumn()) > 0;
}

function dbEnsureEncryptedEmailSchema(PDO $conn): void
{
    if (!dbTableExists($conn, 'user_account_tbl')) {
        return;
    }

    $tableName = 'user_account_tbl';

    if (!dbColumnExists($conn, $tableName, 'email_encrypted')) {
        $conn->exec("ALTER TABLE user_account_tbl ADD COLUMN email_encrypted TEXT NULL AFTER name_id");
    }
    if (!dbColumnExists($conn, $tableName, 'email_iv')) {
        $conn->exec("ALTER TABLE user_account_tbl ADD COLUMN email_iv VARCHAR(255) NULL AFTER email_encrypted");
    }
    if (!dbColumnExists($conn, $tableName, 'email_tag')) {
        $conn->exec("ALTER TABLE user_account_tbl ADD COLUMN email_tag VARCHAR(255) NULL AFTER email_iv");
    }
    if (!dbColumnExists($conn, $tableName, 'email_hash')) {
        $conn->exec("ALTER TABLE user_account_tbl ADD COLUMN email_hash CHAR(64) NULL AFTER email_tag");
    }
    if (dbColumnExists($conn, $tableName, 'email')) {
        $conn->exec("ALTER TABLE user_account_tbl MODIFY COLUMN email VARCHAR(191) NULL");
    }
    if (!dbIndexExists($conn, $tableName, 'uq_user_account_email_hash')) {
        $conn->exec("ALTER TABLE user_account_tbl ADD UNIQUE KEY uq_user_account_email_hash (email_hash)");
    }

    $rows = $conn->query("
        SELECT account_id, email, email_encrypted, email_iv, email_tag, email_hash
        FROM user_account_tbl
        ORDER BY account_id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare("
        UPDATE user_account_tbl
        SET email = :legacy_email,
            email_encrypted = :email_encrypted,
            email_iv = :email_iv,
            email_tag = :email_tag,
            email_hash = :email_hash
        WHERE account_id = :account_id
    ");

    foreach ($rows as $row) {
        $legacyEmail = trim((string) ($row['email'] ?? ''));
        $hasEncryptedEmail =
            !empty($row['email_encrypted']) &&
            !empty($row['email_iv']) &&
            !empty($row['email_tag']);

        $normalizedEmail = '';
        if ($legacyEmail !== '') {
            $normalizedEmail = EncryptionUtil::normalizeEmail($legacyEmail);
        } elseif ($hasEncryptedEmail) {
            try {
                $normalizedEmail = EncryptionUtil::normalizeEmail(decryptEmailFromRow($row));
            } catch (Throwable $exception) {
                $normalizedEmail = '';
            }
        }

        $needsEncryptedEmail = !$hasEncryptedEmail && $normalizedEmail !== '';
        $needsEmailHash = trim((string) ($row['email_hash'] ?? '')) === '' && $normalizedEmail !== '';
        $needsLegacyEmailCleanup = $legacyEmail !== '';

        if (!$needsEncryptedEmail && !$needsEmailHash && !$needsLegacyEmailCleanup) {
            continue;
        }

        $encryptedEmail = $row['email_encrypted'] ?? null;
        $emailIv = $row['email_iv'] ?? null;
        $emailTag = $row['email_tag'] ?? null;
        if ($needsEncryptedEmail) {
            $encryptedEmailData = EncryptionUtil::encryptForStorage($normalizedEmail);
            $encryptedEmail = $encryptedEmailData['encrypted'];
            $emailIv = $encryptedEmailData['iv'];
            $emailTag = $encryptedEmailData['tag'];
        }

        $emailHash = trim((string) ($row['email_hash'] ?? ''));
        if ($needsEmailHash) {
            $emailHash = EncryptionUtil::hashEmailForLookup($normalizedEmail);
        }

        $updateStmt->execute([
            ':legacy_email' => null,
            ':email_encrypted' => $encryptedEmail,
            ':email_iv' => $emailIv,
            ':email_tag' => $emailTag,
            ':email_hash' => $emailHash !== '' ? $emailHash : null,
            ':account_id' => $row['account_id'],
        ]);
    }
}

function dbPrepare(PDO $conn, string $sql): PDOStatement
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new PDOException('Failed to prepare SQL statement.');
    }

    return $stmt;
}

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $conn = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    dbEnsureEncryptedEmailSchema($conn);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'MySQL or MariaDB connection failed. This project requires a configured MySQL/MariaDB database.',
        'error' => $exception->getMessage(),
    ]);
    exit();
}
