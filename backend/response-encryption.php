<?php
require_once __DIR__ . '/config.php';

const RESPONSE_ENCRYPTION_ENVELOPE_FIELD = 'a';
const RESPONSE_ENCRYPTION_IV_AND_TAG_LENGTH = ENCRYPTION_IV_LENGTH + ENCRYPTION_TAG_LENGTH;

function responseEncryptionStart(): void
{
    if (PHP_SAPI === 'cli' || defined('RESPONSE_ENCRYPTION_ACTIVE')) {
        return;
    }

    define('RESPONSE_ENCRYPTION_ACTIVE', true);
    ob_start('responseEncryptionBufferCallback');
}

function responseEncryptionBufferCallback(string $buffer): string
{
    if ($buffer === '' || responseEncryptionShouldSkip($buffer)) {
        return $buffer;
    }

    $trimmedBuffer = trim($buffer);
    if ($trimmedBuffer === '' || !responseEncryptionLooksLikeJson($trimmedBuffer)) {
        return $buffer;
    }

    try {
        $encryptedPayload = responseEncryptPayload($trimmedBuffer);
        header_remove('Content-Length');
        header('Content-Type: application/json; charset=utf-8');

        return json_encode(
            [RESPONSE_ENCRYPTION_ENVELOPE_FIELD => $encryptedPayload],
            JSON_UNESCAPED_SLASHES
        );
    } catch (Throwable $exception) {
        error_log('Response encryption failed: ' . $exception->getMessage());
        return $buffer;
    }
}

function responseEncryptionShouldSkip(string $buffer): bool
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        return true;
    }

    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Type:') !== 0) {
            continue;
        }

        $contentType = strtolower(trim(substr($header, strlen('Content-Type:'))));
        if (
            str_starts_with($contentType, 'image/') ||
            str_contains($contentType, 'text/html') ||
            str_contains($contentType, 'application/octet-stream')
        ) {
            return true;
        }
    }

    return false;
}

function responseEncryptionLooksLikeJson(string $buffer): bool
{
    json_decode($buffer, true);
    return json_last_error() === JSON_ERROR_NONE;
}

function responseEncryptPayload(string $plaintext): string
{
    $iv = random_bytes(ENCRYPTION_IV_LENGTH);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $plaintext,
        ENCRYPTION_CIPHER,
        RESPONSE_ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        ENCRYPTION_TAG_LENGTH
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Transport encryption failed: ' . openssl_error_string());
    }

    return base64_encode($iv . $ciphertext . $tag);
}

function responseDecryptPayload(string $encryptedPayload): string
{
    $decodedPayload = base64_decode($encryptedPayload, true);
    if ($decodedPayload === false || strlen($decodedPayload) <= RESPONSE_ENCRYPTION_IV_AND_TAG_LENGTH) {
        throw new RuntimeException('Encrypted transport payload is incomplete.');
    }

    $iv = substr($decodedPayload, 0, ENCRYPTION_IV_LENGTH);
    $ciphertextWithTag = substr($decodedPayload, ENCRYPTION_IV_LENGTH);
    $ciphertextLength = strlen($ciphertextWithTag) - ENCRYPTION_TAG_LENGTH;
    if ($ciphertextLength <= 0) {
        throw new RuntimeException('Encrypted transport payload is missing ciphertext.');
    }

    $ciphertext = substr($ciphertextWithTag, 0, $ciphertextLength);
    $tag = substr($ciphertextWithTag, $ciphertextLength, ENCRYPTION_TAG_LENGTH);
    $plaintext = openssl_decrypt(
        $ciphertext,
        ENCRYPTION_CIPHER,
        RESPONSE_ENCRYPTION_KEY,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($plaintext === false) {
        throw new RuntimeException('Transport decryption failed: ' . openssl_error_string());
    }

    return $plaintext;
}

function readEncryptedJsonRequestBody(): array
{
    static $cachedBody = null;
    if ($cachedBody !== null) {
        return $cachedBody;
    }

    $rawBody = file_get_contents('php://input');
    if ($rawBody === false || trim($rawBody) === '') {
        $cachedBody = [];
        return $cachedBody;
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        $cachedBody = [];
        return $cachedBody;
    }

    if (
        isset($decoded[RESPONSE_ENCRYPTION_ENVELOPE_FIELD]) &&
        is_string($decoded[RESPONSE_ENCRYPTION_ENVELOPE_FIELD])
    ) {
        try {
            $decryptedJson = responseDecryptPayload($decoded[RESPONSE_ENCRYPTION_ENVELOPE_FIELD]);
            $decryptedData = json_decode($decryptedJson, true);
            $cachedBody = is_array($decryptedData) ? $decryptedData : [];
            return $cachedBody;
        } catch (Throwable $exception) {
            error_log('Request decryption failed: ' . $exception->getMessage());
            $cachedBody = [];
            return $cachedBody;
        }
    }

    $cachedBody = $decoded;
    return $cachedBody;
}
