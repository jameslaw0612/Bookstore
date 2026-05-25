<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/encryption.php';
require_once __DIR__ . '/../backend/auth-middleware.php';
require_once __DIR__ . '/../backend/book-image-storage.php';

function apiResponse(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function apiRequestMethod(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function apiReadJsonBody(): array
{
    return readEncryptedJsonRequestBody();
}

function apiPathSegments(): array
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/api', PHP_URL_PATH) ?: '/api';
    $trimmed = trim($path, '/');
    $segments = $trimmed === '' ? [] : explode('/', $trimmed);

    if (!empty($segments) && $segments[0] === 'api') {
        array_shift($segments);
    }

    return array_values($segments);
}

function apiRequireFields(array $data, array $requiredFields): void
{
    foreach ($requiredFields as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === '' || $data[$field] === null) {
            apiResponse(400, ['success' => false, 'message' => "Missing required field: {$field}"]);
        }
    }
}

function apiGetBearerToken(): string
{
    $header = authGetAuthorizationHeaderValue();
    return authExtractBearerToken($header);
}

function apiRequireUser(): array
{
    return authenticate();
}

function apiRequireAdmin(): array
{
    return authenticateAdmin();
}

function apiMaskPhone(?string $phone): string
{
    if (!$phone) {
        return '';
    }

    $tail = substr($phone, -4);
    return str_repeat('*', max(strlen($phone) - 4, 0)) . $tail;
}

function apiDecryptStoredValue(?string $encrypted, ?string $iv, ?string $tag): string
{
    if (!$encrypted || !$iv || !$tag) {
        return '';
    }

    try {
        return EncryptionUtil::decryptFromStorage($encrypted, $iv, $tag);
    } catch (Exception $exception) {
        return '';
    }
}

function apiDecryptPhone(array $row): string
{
    return apiDecryptStoredValue(
        $row['phone_encrypted'] ?? null,
        $row['phone_iv'] ?? null,
        $row['phone_tag'] ?? null
    );
}

function apiDecryptEmail(array $row): string
{
    return decryptEmailFromRow($row);
}

function apiNormalizeEmail(string $email): string
{
    return EncryptionUtil::normalizeEmail($email);
}

function apiHashEmailForLookup(string $email): string
{
    return EncryptionUtil::hashEmailForLookup($email);
}

function apiNormalizePhilippinePhone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';

    if (preg_match('/^09\d{9}$/', $digits) === 1) {
        return '+63' . substr($digits, 1);
    }

    if (preg_match('/^639\d{9}$/', $digits) === 1) {
        return '+' . $digits;
    }

    if (preg_match('/^\+639\d{9}$/', trim($phone)) === 1) {
        return trim($phone);
    }

    apiResponse(400, ['success' => false, 'message' => 'Phone number must be a valid 11-digit mobile number.']);
}

function apiEmptyAddress(): array
{
    return [
        'address_id' => 0,
        'country' => '',
        'state_province' => '',
        'city_town' => '',
        'barangay' => '',
        'apartment_unit' => '',
        'street' => '',
        'house_number' => '',
    ];
}

function apiNormalizeAddressFields(array $data): array
{
    return [
        'address_id' => isset($data['address_id']) ? intval($data['address_id']) : 0,
        'country' => trim((string) ($data['country'] ?? '')),
        'state_province' => trim((string) ($data['state_province'] ?? '')),
        'city_town' => trim((string) ($data['city_town'] ?? '')),
        'barangay' => trim((string) ($data['barangay'] ?? '')),
        'apartment_unit' => trim((string) ($data['apartment_unit'] ?? '')),
        'street' => trim((string) ($data['street'] ?? '')),
        'house_number' => trim((string) ($data['house_number'] ?? '')),
    ];
}

function apiNormalizeAddressList(mixed $addresses): array
{
    if (!is_array($addresses)) {
        return [];
    }

    $normalized = [];
    foreach ($addresses as $address) {
        if (!is_array($address)) {
            continue;
        }

        $entry = apiNormalizeAddressFields($address);
        if ($entry['address_id'] > 0 || apiHasAnyAddressFields($entry)) {
            $normalized[] = $entry;
        }
    }

    return array_slice($normalized, 0, 2);
}

function apiHasAnyAddressFields(array $address): bool
{
    foreach (['country', 'state_province', 'city_town', 'barangay', 'apartment_unit', 'street', 'house_number'] as $field) {
        if (($address[$field] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

function apiFormatAddressSummary(array $address): string
{
    $parts = array_filter([
        trim((string) ($address['house_number'] ?? '')),
        trim((string) ($address['street'] ?? '')),
        trim((string) ($address['apartment_unit'] ?? '')),
        trim((string) ($address['barangay'] ?? '')),
        trim((string) ($address['city_town'] ?? '')),
        trim((string) ($address['state_province'] ?? '')),
        trim((string) ($address['country'] ?? '')),
    ], static fn(string $value): bool => $value !== '');

    return implode(', ', $parts);
}

function apiHasOrderDeliveryTable(PDO $conn): bool
{
    try {
        $stmt = dbPrepare($conn, "
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'order_delivery_tbl'
        ");
        $stmt->execute();
        return intval($stmt->fetchColumn()) > 0;
    } catch (Throwable $exception) {
        return false;
    }
}

function apiEnsureOrderDeliveryTable(PDO $conn): bool
{
    if (apiHasOrderDeliveryTable($conn)) {
        return true;
    }

    try {
        dbPrepare($conn, '
            CREATE TABLE IF NOT EXISTS order_delivery_tbl (
                order_id INT PRIMARY KEY,
                contact_phone_encrypted TEXT NULL,
                contact_phone_iv VARCHAR(255) NULL,
                contact_phone_tag VARCHAR(255) NULL,
                address_snapshot_fld TEXT NULL,
                CONSTRAINT fk_order_delivery_order
                    FOREIGN KEY (order_id) REFERENCES orders_tbl(order_id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB
        ')->execute();
    } catch (Throwable $exception) {
        return false;
    }

    return apiHasOrderDeliveryTable($conn);
}

function apiUpsertOrderDeliverySnapshot(PDO $conn, int $orderId, string $phone, array $address): void
{
    if (!apiEnsureOrderDeliveryTable($conn)) {
        return;
    }

    $encryptedPhone = EncryptionUtil::encryptForStorage($phone);
    dbPrepare($conn, '
        INSERT INTO order_delivery_tbl (
            order_id, contact_phone_encrypted, contact_phone_iv, contact_phone_tag, address_snapshot_fld
        )
        VALUES (:order_id, :phone_encrypted, :phone_iv, :phone_tag, :address_snapshot)
        ON DUPLICATE KEY UPDATE
            contact_phone_encrypted = VALUES(contact_phone_encrypted),
            contact_phone_iv = VALUES(contact_phone_iv),
            contact_phone_tag = VALUES(contact_phone_tag),
            address_snapshot_fld = VALUES(address_snapshot_fld)
    ')->execute([
        ':order_id' => $orderId,
        ':phone_encrypted' => $encryptedPhone['encrypted'],
        ':phone_iv' => $encryptedPhone['iv'],
        ':phone_tag' => $encryptedPhone['tag'],
        ':address_snapshot' => apiFormatAddressSummary($address),
    ]);
}

function apiDeleteOrderDeliverySnapshot(PDO $conn, int $orderId): void
{
    if (!apiHasOrderDeliveryTable($conn)) {
        return;
    }

    dbPrepare($conn, 'DELETE FROM order_delivery_tbl WHERE order_id = :order_id')
        ->execute([':order_id' => $orderId]);
}

function apiFetchAddressesByAccountId(PDO $conn, int $accountId): array
{
    $stmt = dbPrepare($conn, '
        SELECT address_id, country_fld, state_province_fld, city_town_fld,
               barangay_fld, apartment_unit_fld, streetnum_fld, housenum_fld
        FROM user_address_tbl
        WHERE account_id = :account_id
        ORDER BY address_id ASC
        LIMIT 2
    ');
    $stmt->execute([':account_id' => $accountId]);

    return array_map(static function (array $row): array {
        return apiNormalizeAddressFields([
            'address_id' => $row['address_id'] ?? 0,
            'country' => $row['country_fld'] ?? '',
            'state_province' => $row['state_province_fld'] ?? '',
            'city_town' => $row['city_town_fld'] ?? '',
            'barangay' => $row['barangay_fld'] ?? '',
            'apartment_unit' => $row['apartment_unit_fld'] ?? '',
            'street' => $row['streetnum_fld'] ?? '',
            'house_number' => $row['housenum_fld'] ?? '',
        ]);
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function apiFindAddressById(PDO $conn, int $accountId, int $addressId): ?array
{
    foreach (apiFetchAddressesByAccountId($conn, $accountId) as $address) {
        if (intval($address['address_id'] ?? 0) === $addressId) {
            return $address;
        }
    }

    return null;
}

function apiFetchUserById(PDO $conn, int $accountId): ?array
{
    $stmt = dbPrepare($conn, "
        SELECT ua.account_id, ua.name_id,
               ua.email, ua.email_encrypted, ua.email_iv, ua.email_tag, ua.email_hash,
               ua.password_hash, ua.role,
               ua.phone_encrypted, ua.phone_iv, ua.phone_tag,
               un.fname_fld, un.lname_fld
        FROM user_account_tbl ua
        JOIN user_name_tbl un ON un.name_id = ua.name_id
        WHERE ua.account_id = :account_id
    ");
    $stmt->execute([':account_id' => $accountId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user['addresses'] = apiFetchAddressesByAccountId($conn, $accountId);
    }

    return $user ?: null;
}

function apiFormatUser(array $user, bool $includeSensitivePhone = false): array
{
    $email = apiDecryptEmail($user);
    $phone = apiDecryptPhone($user);
    $addresses = apiNormalizeAddressList($user['addresses'] ?? []);
    $primaryAddress = $addresses[0] ?? apiEmptyAddress();

    return [
        'account_id' => intval($user['account_id']),
        'fname' => $user['fname_fld'],
        'lname' => $user['lname_fld'],
        'email' => $email,
        'role' => $user['role'],
        'phone' => $includeSensitivePhone ? $phone : apiMaskPhone($phone),
        'address' => $primaryAddress,
        'addresses' => $addresses,
    ];
}

function apiFetchBookById(PDO $conn, int $bookId): ?array
{
    $stmt = dbPrepare($conn, "
        SELECT book_id, title_fld AS title, author_fld AS author, description_fld AS description,
               isbn_fld AS isbn, price_fld AS price, stock_qty_fld AS stock_quantity, book_cover_image,
               original_cover_image, image_scale, image_offset_x, image_offset_y
        FROM books_tbl
        WHERE book_id = :book_id
    ");
    $stmt->execute([':book_id' => $bookId]);
    $book = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$book) {
        return null;
    }

    $book['book_id'] = intval($book['book_id']);
    $book['price'] = floatval($book['price']);
    $book['stock_quantity'] = intval($book['stock_quantity']);

    $imageState = resolveBookImageStateFromRow($book);
    $book['book_cover_original_image'] = $imageState['book_cover_original_image'];
    $book['image_scale'] = $imageState['image_scale'];
    $book['image_offset_x'] = $imageState['image_offset_x'];
    $book['image_offset_y'] = $imageState['image_offset_y'];
    unset($book['original_cover_image']);

    $catStmt = dbPrepare($conn, "
        SELECT c.category_id, c.category_name_fld AS category_name
        FROM book_categories_tbl bc
        JOIN categories_tbl c ON c.category_id = bc.category_id
        WHERE bc.book_id = :book_id
        ORDER BY c.category_name_fld ASC
    ");
    $catStmt->execute([':book_id' => $bookId]);
    $book['categories'] = array_map(
        static fn(array $category): array => [
            'category_id' => intval($category['category_id']),
            'category_name' => $category['category_name'],
        ],
        $catStmt->fetchAll(PDO::FETCH_ASSOC)
    );

    return $book;
}

function apiFetchOrderById(PDO $conn, int $orderId): ?array
{
    $stmt = dbPrepare($conn, "
        SELECT o.order_id, o.account_id, o.total_amount_fld, o.order_status_fld,
               o.payment_encrypted, o.payment_iv, o.payment_tag,
               o.order_created_fld, o.order_updated_fld,
               ua.email, ua.email_encrypted, ua.email_iv, ua.email_tag, ua.email_hash,
               ua.phone_encrypted, ua.phone_iv, ua.phone_tag,
               un.fname_fld, un.lname_fld
        FROM orders_tbl o
        JOIN user_account_tbl ua ON ua.account_id = o.account_id
        JOIN user_name_tbl un ON un.name_id = ua.name_id
        WHERE o.order_id = :order_id
    ");
    $stmt->execute([':order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        return null;
    }

    $itemsStmt = dbPrepare($conn, "
        SELECT oi.order_item_id, oi.book_id, oi.quantity_fld, oi.price_at_purchase_fld,
               b.title_fld AS title, b.author_fld AS author,
               b.book_cover_image, b.stock_qty_fld AS stock_quantity
        FROM order_items_tbl oi
        JOIN books_tbl b ON b.book_id = oi.book_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.order_item_id ASC
    ");
    $itemsStmt->execute([':order_id' => $orderId]);

    $deliverySnapshot = [
        'phone' => '',
        'address' => '',
    ];

    if (apiHasOrderDeliveryTable($conn)) {
        $deliveryStmt = dbPrepare($conn, '
            SELECT contact_phone_encrypted, contact_phone_iv, contact_phone_tag, address_snapshot_fld
            FROM order_delivery_tbl
            WHERE order_id = :order_id
        ');
        $deliveryStmt->execute([':order_id' => $orderId]);
        $delivery = $deliveryStmt->fetch(PDO::FETCH_ASSOC);

        if ($delivery) {
            $deliverySnapshot = [
                'phone' => apiDecryptStoredValue(
                    $delivery['contact_phone_encrypted'] ?? null,
                    $delivery['contact_phone_iv'] ?? null,
                    $delivery['contact_phone_tag'] ?? null
                ),
                'address' => trim((string) ($delivery['address_snapshot_fld'] ?? '')),
            ];
        }
    }

    $fallbackAddress = '';
    if ($deliverySnapshot['address'] === '') {
        $addresses = apiFetchAddressesByAccountId($conn, intval($order['account_id']));
        if (!empty($addresses)) {
            $fallbackAddress = apiFormatAddressSummary($addresses[0]);
        }
    }

    return [
        'order_id' => intval($order['order_id']),
        'account_id' => intval($order['account_id']),
        'total_amount' => floatval($order['total_amount_fld']),
        'payment_method' => apiDecryptStoredValue(
            $order['payment_encrypted'] ?? null,
            $order['payment_iv'] ?? null,
            $order['payment_tag'] ?? null
        ),
        'status' => $order['order_status_fld'],
        'created_at' => $order['order_created_fld'],
        'updated_at' => $order['order_updated_fld'],
        'user' => [
            'account_id' => intval($order['account_id']),
            'fname' => $order['fname_fld'],
            'lname' => $order['lname_fld'],
            'email' => apiDecryptEmail($order),
            'phone' => $deliverySnapshot['phone'] !== ''
                ? $deliverySnapshot['phone']
                : apiDecryptStoredValue(
                    $order['phone_encrypted'] ?? null,
                    $order['phone_iv'] ?? null,
                    $order['phone_tag'] ?? null
                ),
        ],
        'delivery' => [
            'address' => $deliverySnapshot['address'] !== '' ? $deliverySnapshot['address'] : $fallbackAddress,
        ],
        'items' => array_map(
            static fn(array $item): array => [
                'order_item_id' => intval($item['order_item_id']),
                'book_id' => intval($item['book_id']),
                'title' => $item['title'],
                'author' => $item['author'],
                'quantity' => intval($item['quantity_fld']),
                'price_at_purchase' => floatval($item['price_at_purchase_fld']),
                'book_cover_image' => $item['book_cover_image'] ?: null,
                'stock_quantity' => intval($item['stock_quantity']),
            ],
            $itemsStmt->fetchAll(PDO::FETCH_ASSOC)
        ),
    ];
}

function apiFetchCartOrderId(PDO $conn, int $accountId): ?int
{
    $stmt = dbPrepare($conn, "
        SELECT order_id
        FROM orders_tbl
        WHERE account_id = :account_id AND order_status_fld = 'cart'
        ORDER BY order_updated_fld DESC, order_id DESC
        LIMIT 1
    ");
    $stmt->execute([':account_id' => $accountId]);
    $orderId = $stmt->fetchColumn();

    return $orderId !== false ? intval($orderId) : null;
}

function apiFetchCurrentCart(PDO $conn, int $accountId): ?array
{
    $cartOrderId = apiFetchCartOrderId($conn, $accountId);
    if ($cartOrderId === null) {
        return null;
    }

    return apiFetchOrderById($conn, $cartOrderId);
}
