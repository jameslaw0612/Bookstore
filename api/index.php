<?php
require_once __DIR__ . '/bootstrap.php';

$method = apiRequestMethod();
$segments = apiPathSegments();

if ($method === 'OPTIONS') {
    apiResponse(200, ['success' => true]);
}

if (empty($segments)) {
    apiResponse(200, [
        'success' => true,
        'message' => 'Online bookstore API',
        'available_roots' => ['auth', 'users', 'books', 'orders', 'admin', 'reports'],
    ]);
}

switch ($segments[0]) {
    case 'auth':
        handleAuthRoutes($conn, $method, array_slice($segments, 1));
        break;
    case 'users':
        handleUserRoutes($conn, $method, array_slice($segments, 1));
        break;
    case 'books':
        handleBookRoutes($conn, $method, array_slice($segments, 1));
        break;
    case 'orders':
        handleOrderRoutes($conn, $method, array_slice($segments, 1));
        break;
    case 'admin':
        handleAdminRoutes($conn, $method, array_slice($segments, 1));
        break;
    case 'reports':
        handleReportRoutes($conn, $method, array_slice($segments, 1));
        break;
    default:
        apiResponse(404, ['success' => false, 'message' => 'Endpoint not found']);
}

function handleAuthRoutes(PDO $conn, string $method, array $segments): void
{
    $resource = $segments[0] ?? '';

    if ($resource === 'register' && $method === 'POST') {
        $data = apiReadJsonBody();
        apiRequireFields($data, ['fname', 'lname', 'email', 'phone', 'password']);
        $normalizedEmail = apiNormalizeEmail((string) $data['email']);
        $normalizedPhone = apiNormalizePhilippinePhone((string) $data['phone']);
        $emailHash = apiHashEmailForLookup($normalizedEmail);

        $checkStmt = dbPrepare($conn, 'SELECT account_id FROM user_account_tbl WHERE email_hash = :email_hash');
        $checkStmt->execute([':email_hash' => $emailHash]);
        if ($checkStmt->fetch()) {
            apiResponse(400, ['success' => false, 'message' => 'Email already registered']);
        }

        $nameStmt = dbPrepare($conn, 'INSERT INTO user_name_tbl (fname_fld, lname_fld) VALUES (:fname, :lname)');
        $nameStmt->execute([':fname' => trim($data['fname']), ':lname' => trim($data['lname'])]);
        $nameId = intval($conn->lastInsertId());

        $encryptedEmail = EncryptionUtil::encryptForStorage($normalizedEmail);
        $encryptedPhone = EncryptionUtil::encryptForStorage($normalizedPhone);
        $insertStmt = dbPrepare($conn, "
            INSERT INTO user_account_tbl (
                name_id, email, email_encrypted, email_iv, email_tag, email_hash,
                password_hash, phone_encrypted, phone_iv, phone_tag, role
            )
            VALUES (
                :name_id, NULL, :email_encrypted, :email_iv, :email_tag, :email_hash,
                :password_hash, :phone_encrypted, :phone_iv, :phone_tag, 'user'
            )
        ");
        $insertStmt->execute([
            ':name_id' => $nameId,
            ':email_encrypted' => $encryptedEmail['encrypted'],
            ':email_iv' => $encryptedEmail['iv'],
            ':email_tag' => $encryptedEmail['tag'],
            ':email_hash' => $emailHash,
            ':password_hash' => password_hash($data['password'], PASSWORD_BCRYPT),
            ':phone_encrypted' => $encryptedPhone['encrypted'],
            ':phone_iv' => $encryptedPhone['iv'],
            ':phone_tag' => $encryptedPhone['tag'],
        ]);

        $user = apiFetchUserById($conn, intval($conn->lastInsertId()));
        apiResponse(201, ['success' => true, 'message' => 'User registered successfully', 'user' => apiFormatUser($user, true)]);
    }

    if ($resource === 'login' && $method === 'POST') {
        $data = apiReadJsonBody();
        apiRequireFields($data, ['email', 'password']);
        $normalizedEmail = apiNormalizeEmail((string) $data['email']);
        $emailHash = apiHashEmailForLookup($normalizedEmail);

        $stmt = dbPrepare($conn, "
            SELECT ua.account_id, ua.name_id,
                   ua.email, ua.email_encrypted, ua.email_iv, ua.email_tag, ua.email_hash,
                   ua.password_hash, ua.role,
                   ua.phone_encrypted, ua.phone_iv, ua.phone_tag,
                   un.fname_fld, un.lname_fld
            FROM user_account_tbl ua
            JOIN user_name_tbl un ON un.name_id = ua.name_id
            WHERE ua.email_hash = :email_hash
        ");
        $stmt->execute([':email_hash' => $emailHash]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($data['password'], $user['password_hash'])) {
            apiResponse(401, ['success' => false, 'message' => 'Invalid email or password']);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

        dbPrepare($conn, 'DELETE FROM user_tokens_tbl WHERE account_id = :account_id')
            ->execute([':account_id' => $user['account_id']]);
        dbPrepare($conn, '
            INSERT INTO user_tokens_tbl (account_id, token, expires_at)
            VALUES (:account_id, :token, :expires_at)
        ')->execute([
            ':account_id' => $user['account_id'],
            ':token' => $token,
            ':expires_at' => $expiresAt,
        ]);

        apiResponse(200, [
            'success' => true,
            'message' => 'Login successful',
            'token' => $token,
            'user' => apiFormatUser($user, true),
        ]);
    }

    if ($resource === 'logout' && $method === 'POST') {
        $token = apiGetBearerToken();
        if ($token === '') {
            $body = apiReadJsonBody();
            $token = trim($body['token'] ?? '');
        }

        if ($token === '') {
            apiResponse(401, ['success' => false, 'message' => 'No token provided']);
        }

        dbPrepare($conn, 'DELETE FROM user_tokens_tbl WHERE token = :token')->execute([':token' => $token]);
        apiResponse(200, ['success' => true, 'message' => 'Logged out successfully']);
    }

    apiResponse(404, ['success' => false, 'message' => 'Auth endpoint not found']);
}

function handleUserRoutes(PDO $conn, string $method, array $segments): void
{
    $resource = $segments[0] ?? '';
    $currentUser = apiRequireUser();

    if ($resource === 'profile' && $method === 'GET') {
        $user = apiFetchUserById($conn, intval($currentUser['account_id']));
        apiResponse(200, ['success' => true, 'profile' => apiFormatUser($user, true)]);
    }

    if ($resource === 'profile' && $method === 'PUT') {
        $data = apiReadJsonBody();
        apiRequireFields($data, ['fname', 'lname', 'email', 'phone']);
        $normalizedEmail = apiNormalizeEmail((string) $data['email']);
        $normalizedPhone = apiNormalizePhilippinePhone((string) $data['phone']);
        $addresses = apiNormalizeAddressList($data['addresses'] ?? []);
        $emailHash = apiHashEmailForLookup($normalizedEmail);

        $user = apiFetchUserById($conn, intval($currentUser['account_id']));
        if (!$user) {
            apiResponse(404, ['success' => false, 'message' => 'User not found']);
        }

        $duplicateStmt = dbPrepare($conn, '
            SELECT account_id FROM user_account_tbl
            WHERE email_hash = :email_hash AND account_id != :account_id
        ');
        $duplicateStmt->execute([
            ':email_hash' => $emailHash,
            ':account_id' => $currentUser['account_id'],
        ]);
        if ($duplicateStmt->fetch()) {
            apiResponse(400, ['success' => false, 'message' => 'Email already registered to another account']);
        }

        foreach ($addresses as $address) {
            foreach (['country', 'state_province', 'city_town', 'street', 'house_number'] as $field) {
                if ($address[$field] === '') {
                    apiResponse(400, ['success' => false, 'message' => 'Please complete the required address fields before saving your profile.']);
                }
            }
        }

        $encryptedEmail = EncryptionUtil::encryptForStorage($normalizedEmail);
        $encryptedPhone = EncryptionUtil::encryptForStorage($normalizedPhone);
        try {
            $conn->beginTransaction();

            dbPrepare($conn, '
                UPDATE user_name_tbl SET fname_fld = :fname, lname_fld = :lname WHERE name_id = :name_id
            ')->execute([
                ':fname' => trim($data['fname']),
                ':lname' => trim($data['lname']),
                ':name_id' => $user['name_id'],
            ]);
            dbPrepare($conn, '
                UPDATE user_account_tbl
                SET email = NULL,
                    email_encrypted = :email_encrypted,
                    email_iv = :email_iv,
                    email_tag = :email_tag,
                    email_hash = :email_hash,
                    phone_encrypted = :phone_encrypted,
                    phone_iv = :phone_iv,
                    phone_tag = :phone_tag
                WHERE account_id = :account_id
            ')->execute([
                ':email_encrypted' => $encryptedEmail['encrypted'],
                ':email_iv' => $encryptedEmail['iv'],
                ':email_tag' => $encryptedEmail['tag'],
                ':email_hash' => $emailHash,
                ':phone_encrypted' => $encryptedPhone['encrypted'],
                ':phone_iv' => $encryptedPhone['iv'],
                ':phone_tag' => $encryptedPhone['tag'],
                ':account_id' => $currentUser['account_id'],
            ]);

            $existingAddressIds = array_map(
                static fn(array $address): int => intval($address['address_id'] ?? 0),
                apiFetchAddressesByAccountId($conn, intval($currentUser['account_id']))
            );
            $keptAddressIds = [];

            foreach ($addresses as $address) {
                $addressParams = [
                    ':account_id' => $currentUser['account_id'],
                    ':country' => $address['country'],
                    ':state_province' => $address['state_province'],
                    ':city_town' => $address['city_town'],
                    ':barangay' => $address['barangay'] !== '' ? $address['barangay'] : null,
                    ':apartment_unit' => $address['apartment_unit'] !== '' ? $address['apartment_unit'] : null,
                    ':street' => $address['street'],
                    ':house_number' => $address['house_number'],
                ];

                if ($address['address_id'] > 0 && in_array($address['address_id'], $existingAddressIds, true)) {
                    dbPrepare($conn, '
                        UPDATE user_address_tbl
                        SET country_fld = :country,
                            state_province_fld = :state_province,
                            city_town_fld = :city_town,
                            barangay_fld = :barangay,
                            apartment_unit_fld = :apartment_unit,
                            streetnum_fld = :street,
                            housenum_fld = :house_number
                        WHERE address_id = :address_id AND account_id = :account_id
                    ')->execute($addressParams + [':address_id' => $address['address_id']]);
                    $keptAddressIds[] = $address['address_id'];
                } else {
                    dbPrepare($conn, '
                        INSERT INTO user_address_tbl (
                            account_id, country_fld, state_province_fld, city_town_fld,
                            barangay_fld, apartment_unit_fld, streetnum_fld, housenum_fld
                        ) VALUES (
                            :account_id, :country, :state_province, :city_town,
                            :barangay, :apartment_unit, :street, :house_number
                        )
                    ')->execute($addressParams);
                    $keptAddressIds[] = intval($conn->lastInsertId());
                }
            }

            foreach ($existingAddressIds as $addressId) {
                if (!in_array($addressId, $keptAddressIds, true)) {
                    dbPrepare($conn, 'DELETE FROM user_address_tbl WHERE address_id = :address_id AND account_id = :account_id')
                        ->execute([
                            ':address_id' => $addressId,
                            ':account_id' => $currentUser['account_id'],
                        ]);
                }
            }

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            apiResponse(500, ['success' => false, 'message' => 'Failed to update profile']);
        }

        $updatedUser = apiFetchUserById($conn, intval($currentUser['account_id']));
        apiResponse(200, ['success' => true, 'message' => 'Profile updated successfully', 'profile' => apiFormatUser($updatedUser, true)]);
    }

    if ($resource === 'change-password' && $method === 'PUT') {
        $data = apiReadJsonBody();
        apiRequireFields($data, ['current_password', 'new_password']);

        $user = apiFetchUserById($conn, intval($currentUser['account_id']));
        if (!$user || !password_verify($data['current_password'], $user['password_hash'])) {
            apiResponse(400, ['success' => false, 'message' => 'Current password is incorrect']);
        }

        dbPrepare($conn, '
            UPDATE user_account_tbl SET password_hash = :password_hash WHERE account_id = :account_id
        ')->execute([
            ':password_hash' => password_hash($data['new_password'], PASSWORD_BCRYPT),
            ':account_id' => $currentUser['account_id'],
        ]);

        apiResponse(200, ['success' => true, 'message' => 'Password updated successfully']);
    }

    apiResponse(404, ['success' => false, 'message' => 'User endpoint not found']);
}

function handleBookRoutes(PDO $conn, string $method, array $segments): void
{
    if (empty($segments)) {
        if ($method === 'GET') {
            $stmt = dbPrepare($conn, "
                SELECT book_id
                FROM books_tbl
                ORDER BY book_id DESC
            ");
            $stmt->execute();
            $books = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $book = apiFetchBookById($conn, intval($row['book_id']));
                if ($book) {
                    $books[] = $book;
                }
            }

            apiResponse(200, ['success' => true, 'books' => $books, 'total' => count($books)]);
        }

        if ($method === 'POST') {
            apiRequireAdmin();
            $data = apiReadJsonBody();
            apiRequireFields($data, ['title', 'author', 'description', 'isbn', 'price', 'stock_quantity']);

            $duplicateStmt = dbPrepare($conn, 'SELECT book_id FROM books_tbl WHERE isbn_fld = :isbn');
            $duplicateStmt->execute([':isbn' => trim($data['isbn'])]);
            if ($duplicateStmt->fetch()) {
                apiResponse(400, ['success' => false, 'message' => 'ISBN already exists']);
            }

            dbPrepare($conn, '
                INSERT INTO books_tbl (title_fld, author_fld, description_fld, isbn_fld, price_fld, stock_qty_fld, book_cover_image)
                VALUES (:title, :author, :description, :isbn, :price, :stock_quantity, :book_cover_image)
            ')->execute([
                ':title' => trim($data['title']),
                ':author' => trim($data['author']),
                ':description' => trim($data['description']),
                ':isbn' => trim($data['isbn']),
                ':price' => floatval($data['price']),
                ':stock_quantity' => intval($data['stock_quantity']),
                ':book_cover_image' => trim($data['book_cover_image'] ?? '') ?: null,
            ]);

            $bookId = intval($conn->lastInsertId());
            foreach (($data['category_ids'] ?? []) as $categoryId) {
                dbPrepare($conn, '
                    INSERT INTO book_categories_tbl (book_id, category_id) VALUES (:book_id, :category_id)
                ')->execute([
                    ':book_id' => $bookId,
                    ':category_id' => intval($categoryId),
                ]);
            }

            $book = apiFetchBookById($conn, $bookId);
            apiResponse(201, ['success' => true, 'message' => 'Book created successfully', 'book' => $book]);
        }
    }

    $bookId = intval($segments[0] ?? 0);
    if ($bookId <= 0) {
        apiResponse(404, ['success' => false, 'message' => 'Book endpoint not found']);
    }

    if ($method === 'GET') {
        $book = apiFetchBookById($conn, $bookId);
        if (!$book) {
            apiResponse(404, ['success' => false, 'message' => 'Book not found']);
        }
        apiResponse(200, ['success' => true, 'book' => $book]);
    }

    if ($method === 'PUT') {
        apiRequireAdmin();
        $existingBook = apiFetchBookById($conn, $bookId);
        if (!$existingBook) {
            apiResponse(404, ['success' => false, 'message' => 'Book not found']);
        }

        $data = apiReadJsonBody();
        apiRequireFields($data, ['title', 'author', 'description', 'isbn', 'price', 'stock_quantity']);

        dbPrepare($conn, '
            UPDATE books_tbl
            SET title_fld = :title, author_fld = :author, description_fld = :description,
                isbn_fld = :isbn, price_fld = :price, stock_qty_fld = :stock_quantity,
                book_cover_image = :book_cover_image
            WHERE book_id = :book_id
        ')->execute([
            ':title' => trim($data['title']),
            ':author' => trim($data['author']),
            ':description' => trim($data['description']),
            ':isbn' => trim($data['isbn']),
            ':price' => floatval($data['price']),
            ':stock_quantity' => intval($data['stock_quantity']),
            ':book_cover_image' => trim($data['book_cover_image'] ?? ($existingBook['book_cover_image'] ?? '')) ?: null,
            ':book_id' => $bookId,
        ]);

        dbPrepare($conn, 'DELETE FROM book_categories_tbl WHERE book_id = :book_id')
            ->execute([':book_id' => $bookId]);
        foreach (($data['category_ids'] ?? []) as $categoryId) {
            dbPrepare($conn, '
                INSERT INTO book_categories_tbl (book_id, category_id) VALUES (:book_id, :category_id)
            ')->execute([
                ':book_id' => $bookId,
                ':category_id' => intval($categoryId),
            ]);
        }

        $book = apiFetchBookById($conn, $bookId);
        apiResponse(200, ['success' => true, 'message' => 'Book updated successfully', 'book' => $book]);
    }

    if ($method === 'DELETE') {
        apiRequireAdmin();
        $book = apiFetchBookById($conn, $bookId);
        if (!$book) {
            apiResponse(404, ['success' => false, 'message' => 'Book not found']);
        }

        dbPrepare($conn, 'DELETE FROM book_categories_tbl WHERE book_id = :book_id')
            ->execute([':book_id' => $bookId]);
        dbPrepare($conn, 'DELETE FROM books_tbl WHERE book_id = :book_id')
            ->execute([':book_id' => $bookId]);

        apiResponse(200, ['success' => true, 'message' => 'Book deleted successfully', 'deleted_book' => $book]);
    }

    apiResponse(405, ['success' => false, 'message' => 'Method not allowed']);
}

function handleOrderRoutes(PDO $conn, string $method, array $segments): void
{
    $currentUser = apiRequireUser();
    $accountId = intval($currentUser['account_id']);

    if (($segments[0] ?? '') === 'cart' && $method === 'GET') {
        $cart = apiFetchCurrentCart($conn, $accountId);
        apiResponse(200, ['success' => true, 'cart' => $cart]);
    }

    if (($segments[0] ?? '') === 'cart' && $method === 'PUT') {
        $data = apiReadJsonBody();
        $items = $data['items'] ?? [];

        if (!is_array($items)) {
            apiResponse(400, ['success' => false, 'message' => 'Cart items must be an array']);
        }

        $lineItems = [];
        $totalAmount = 0.0;
        foreach ($items as $item) {
            $bookId = intval($item['book_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            if ($bookId <= 0 || $quantity <= 0) {
                apiResponse(400, ['success' => false, 'message' => 'Each cart item must include a valid book_id and quantity']);
            }

            $book = apiFetchBookById($conn, $bookId);
            if (!$book) {
                apiResponse(404, ['success' => false, 'message' => "Book {$bookId} not found"]);
            }
            if ($book['stock_quantity'] < $quantity) {
                apiResponse(400, ['success' => false, 'message' => "Insufficient stock for book {$book['title']}"]);
            }

            $price = floatval($book['price']);
            $lineItems[] = ['book' => $book, 'quantity' => $quantity, 'price' => $price];
            $totalAmount += $price * $quantity;
        }

        try {
            $conn->beginTransaction();

            $existingCartOrderId = apiFetchCartOrderId($conn, $accountId);
            if (count($lineItems) === 0) {
                if ($existingCartOrderId !== null) {
                    dbPrepare($conn, 'DELETE FROM orders_tbl WHERE order_id = :order_id AND account_id = :account_id')
                        ->execute([
                            ':order_id' => $existingCartOrderId,
                            ':account_id' => $accountId,
                        ]);
                }

                $conn->commit();
                apiResponse(200, ['success' => true, 'message' => 'Cart updated successfully', 'cart' => null]);
            }

            if ($existingCartOrderId === null) {
                dbPrepare($conn, '
                    INSERT INTO orders_tbl (account_id, total_amount_fld, payment_encrypted, payment_iv, payment_tag, order_status_fld)
                    VALUES (:account_id, :total_amount, NULL, NULL, NULL, :status)
                ')->execute([
                    ':account_id' => $accountId,
                    ':total_amount' => $totalAmount,
                    ':status' => 'cart',
                ]);
                $cartOrderId = intval($conn->lastInsertId());
            } else {
                $cartOrderId = $existingCartOrderId;
                dbPrepare($conn, '
                    UPDATE orders_tbl
                    SET total_amount_fld = :total_amount,
                        payment_encrypted = NULL,
                        payment_iv = NULL,
                        payment_tag = NULL,
                        order_status_fld = :status
                    WHERE order_id = :order_id AND account_id = :account_id
                ')->execute([
                    ':total_amount' => $totalAmount,
                    ':status' => 'cart',
                    ':order_id' => $cartOrderId,
                    ':account_id' => $accountId,
                ]);
                dbPrepare($conn, 'DELETE FROM order_items_tbl WHERE order_id = :order_id')
                    ->execute([':order_id' => $cartOrderId]);
            }

            foreach ($lineItems as $lineItem) {
                dbPrepare($conn, '
                    INSERT INTO order_items_tbl (order_id, book_id, quantity_fld, price_at_purchase_fld)
                    VALUES (:order_id, :book_id, :quantity, :price)
                ')->execute([
                    ':order_id' => $cartOrderId,
                    ':book_id' => $lineItem['book']['book_id'],
                    ':quantity' => $lineItem['quantity'],
                    ':price' => $lineItem['price'],
                ]);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            apiResponse(500, ['success' => false, 'message' => 'Failed to update cart']);
        }

        $cart = apiFetchCurrentCart($conn, $accountId);
        apiResponse(200, ['success' => true, 'message' => 'Cart updated successfully', 'cart' => $cart]);
    }

    if (($segments[0] ?? '') === 'checkout' && $method === 'POST') {
        $data = apiReadJsonBody();
        $status = trim((string) ($data['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'paid'], true)) {
            apiResponse(400, ['success' => false, 'message' => 'Checkout status must be pending or paid']);
        }

        $cart = apiFetchCurrentCart($conn, $accountId);
        if (!$cart || empty($cart['items'])) {
            apiResponse(400, ['success' => false, 'message' => 'No cart items found for checkout']);
        }

        $addressId = intval($data['address_id'] ?? 0);
        if ($addressId <= 0) {
            apiResponse(400, ['success' => false, 'message' => 'Please select a delivery address before placing your order']);
        }

        $savedAddresses = apiFetchAddressesByAccountId($conn, $accountId);
        if (count($savedAddresses) === 0) {
            apiResponse(400, ['success' => false, 'message' => 'Please add a delivery address before placing your order']);
        }

        $selectedAddress = null;
        foreach ($savedAddresses as $address) {
            if (intval($address['address_id'] ?? 0) === $addressId) {
                $selectedAddress = $address;
                break;
            }
        }

        if (!$selectedAddress && count($savedAddresses) === 1) {
            $selectedAddress = $savedAddresses[0];
        }

        if (!$selectedAddress) {
            apiResponse(400, [
                'success' => false,
                'message' => 'Your saved delivery addresses changed. Please reopen checkout and choose your address again.',
            ]);
        }

        $orderingUser = apiFetchUserById($conn, $accountId);
        if (!$orderingUser) {
            apiResponse(404, ['success' => false, 'message' => 'User not found']);
        }
        $deliveryPhone = trim(apiDecryptPhone($orderingUser));
        if ($deliveryPhone === '') {
            apiResponse(400, ['success' => false, 'message' => 'Please add a contact number before placing your order']);
        }

        $allowedPaymentMethods = [
            'Cash on delivery',
            'Payments via Maya (Credit/Debit Card, G-Cash, Maya)',
            'Paynamics (G-Cash, BPI Online, 7-Eleven, Dragonpay, etc.)',
        ];
        $paymentMethod = trim((string) ($data['payment_method'] ?? ''));
        if ($paymentMethod === '' || !in_array($paymentMethod, $allowedPaymentMethods, true)) {
            apiResponse(400, ['success' => false, 'message' => 'Please select a valid payment method before placing your order']);
        }

        $selectedBookIds = array_values(array_unique(array_filter(
            array_map('intval', is_array($data['selected_book_ids'] ?? null) ? $data['selected_book_ids'] : []),
            static fn(int $bookId): bool => $bookId > 0
        )));

        $checkoutItems = count($selectedBookIds) > 0
            ? array_values(array_filter(
                $cart['items'],
                static fn(array $item): bool => in_array(intval($item['book_id']), $selectedBookIds, true)
            ))
            : $cart['items'];

        if (count($selectedBookIds) > 0 && count($checkoutItems) !== count($selectedBookIds)) {
            apiResponse(400, ['success' => false, 'message' => 'Some selected cart items could not be found']);
        }

        if (count($checkoutItems) === 0) {
            apiResponse(400, ['success' => false, 'message' => 'No cart items found for checkout']);
        }

        $remainingCartItems = count($selectedBookIds) > 0
            ? array_values(array_filter(
                $cart['items'],
                static fn(array $item): bool => !in_array(intval($item['book_id']), $selectedBookIds, true)
            ))
            : [];

        $paymentEncrypted = null;
        $paymentIv = null;
        $paymentTag = null;
        $encrypted = EncryptionUtil::encryptForStorage($paymentMethod);
        $paymentEncrypted = $encrypted['encrypted'];
        $paymentIv = $encrypted['iv'];
        $paymentTag = $encrypted['tag'];

        $validatedBooks = [];
        $recalculatedTotal = 0.0;
        foreach ($checkoutItems as $item) {
            $book = apiFetchBookById($conn, intval($item['book_id']));
            if (!$book) {
                apiResponse(404, ['success' => false, 'message' => "Book {$item['book_id']} not found"]);
            }
            if ($book['stock_quantity'] < intval($item['quantity'])) {
                apiResponse(400, ['success' => false, 'message' => "Insufficient stock for book {$book['title']}"]);
            }

            $validatedBooks[] = $book;
            $recalculatedTotal += floatval($item['price_at_purchase']) * intval($item['quantity']);
        }

        // Ensure the delivery snapshot table exists before opening a transaction.
        // MySQL DDL can implicitly commit, which would break the checkout flow if it runs mid-transaction.
        apiEnsureOrderDeliveryTable($conn);

        try {
            $conn->beginTransaction();

            foreach ($checkoutItems as $index => $item) {
                dbPrepare($conn, '
                    UPDATE books_tbl
                    SET stock_qty_fld = stock_qty_fld - :quantity
                    WHERE book_id = :book_id
                ')->execute([
                    ':quantity' => intval($item['quantity']),
                    ':book_id' => intval($validatedBooks[$index]['book_id']),
                ]);
            }

            if (count($remainingCartItems) === 0) {
                dbPrepare($conn, '
                    UPDATE orders_tbl
                    SET total_amount_fld = :total_amount,
                        payment_encrypted = :payment_encrypted,
                        payment_iv = :payment_iv,
                        payment_tag = :payment_tag,
                        order_status_fld = :status
                    WHERE order_id = :order_id AND account_id = :account_id
                ')->execute([
                    ':total_amount' => $recalculatedTotal,
                    ':payment_encrypted' => $paymentEncrypted,
                    ':payment_iv' => $paymentIv,
                    ':payment_tag' => $paymentTag,
                    ':status' => $status,
                    ':order_id' => intval($cart['order_id']),
                    ':account_id' => $accountId,
                ]);

                $checkedOutOrderId = intval($cart['order_id']);
                apiUpsertOrderDeliverySnapshot($conn, $checkedOutOrderId, $deliveryPhone, $selectedAddress);
            } else {
                dbPrepare($conn, '
                    INSERT INTO orders_tbl (account_id, total_amount_fld, payment_encrypted, payment_iv, payment_tag, order_status_fld)
                    VALUES (:account_id, :total_amount, :payment_encrypted, :payment_iv, :payment_tag, :status)
                ')->execute([
                    ':account_id' => $accountId,
                    ':total_amount' => $recalculatedTotal,
                    ':payment_encrypted' => $paymentEncrypted,
                    ':payment_iv' => $paymentIv,
                    ':payment_tag' => $paymentTag,
                    ':status' => $status,
                ]);
                $checkedOutOrderId = intval($conn->lastInsertId());
                apiUpsertOrderDeliverySnapshot($conn, $checkedOutOrderId, $deliveryPhone, $selectedAddress);

                foreach ($checkoutItems as $item) {
                    dbPrepare($conn, '
                        INSERT INTO order_items_tbl (order_id, book_id, quantity_fld, price_at_purchase_fld)
                        VALUES (:order_id, :book_id, :quantity, :price)
                    ')->execute([
                        ':order_id' => $checkedOutOrderId,
                        ':book_id' => intval($item['book_id']),
                        ':quantity' => intval($item['quantity']),
                        ':price' => floatval($item['price_at_purchase']),
                    ]);
                }

                $remainingTotal = array_reduce(
                    $remainingCartItems,
                    static fn(float $sum, array $item): float => $sum + (floatval($item['price_at_purchase']) * intval($item['quantity'])),
                    0.0
                );

                dbPrepare($conn, '
                    UPDATE orders_tbl
                    SET total_amount_fld = :total_amount,
                        payment_encrypted = NULL,
                        payment_iv = NULL,
                        payment_tag = NULL,
                        order_status_fld = :status
                    WHERE order_id = :order_id AND account_id = :account_id
                ')->execute([
                    ':total_amount' => $remainingTotal,
                    ':status' => 'cart',
                    ':order_id' => intval($cart['order_id']),
                    ':account_id' => $accountId,
                ]);
                apiDeleteOrderDeliverySnapshot($conn, intval($cart['order_id']));

                dbPrepare($conn, 'DELETE FROM order_items_tbl WHERE order_id = :order_id')
                    ->execute([':order_id' => intval($cart['order_id'])]);

                foreach ($remainingCartItems as $item) {
                    dbPrepare($conn, '
                        INSERT INTO order_items_tbl (order_id, book_id, quantity_fld, price_at_purchase_fld)
                        VALUES (:order_id, :book_id, :quantity, :price)
                    ')->execute([
                        ':order_id' => intval($cart['order_id']),
                        ':book_id' => intval($item['book_id']),
                        ':quantity' => intval($item['quantity']),
                        ':price' => floatval($item['price_at_purchase']),
                    ]);
                }
            }

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            apiResponse(500, ['success' => false, 'message' => 'Failed to checkout cart']);
        }

        $checkedOutOrder = apiFetchOrderById($conn, $checkedOutOrderId ?? intval($cart['order_id']));
        $remainingCart = apiFetchCurrentCart($conn, $accountId);
        apiResponse(200, [
            'success' => true,
            'message' => 'Order placed successfully',
            'order' => $checkedOutOrder,
            'cart' => $remainingCart,
        ]);
    }

    if (empty($segments) && $method === 'POST') {
        $data = apiReadJsonBody();
        apiRequireFields($data, ['items']);

        $status = trim((string) ($data['status'] ?? 'pending'));
        if ($status === 'cart') {
            apiResponse(400, ['success' => false, 'message' => 'Use the cart endpoint for cart operations']);
        }

        if (!is_array($data['items']) || count($data['items']) === 0) {
            apiResponse(400, ['success' => false, 'message' => 'Order items are required']);
        }

        $lineItems = [];
        $totalAmount = 0.0;
        foreach ($data['items'] as $item) {
            $bookId = intval($item['book_id'] ?? 0);
            $quantity = intval($item['quantity'] ?? 0);
            if ($bookId <= 0 || $quantity <= 0) {
                apiResponse(400, ['success' => false, 'message' => 'Each order item must include a valid book_id and quantity']);
            }

            $book = apiFetchBookById($conn, $bookId);
            if (!$book) {
                apiResponse(404, ['success' => false, 'message' => "Book {$bookId} not found"]);
            }
            if ($book['stock_quantity'] < $quantity) {
                apiResponse(400, ['success' => false, 'message' => "Insufficient stock for book {$book['title']}"]);
            }

            $price = floatval($book['price']);
            $lineItems[] = ['book' => $book, 'quantity' => $quantity, 'price' => $price];
            $totalAmount += $price * $quantity;
        }

        $paymentEncrypted = null;
        $paymentIv = null;
        $paymentTag = null;
        if (!empty($data['payment_method'])) {
            $encrypted = EncryptionUtil::encryptForStorage((string) $data['payment_method']);
            $paymentEncrypted = $encrypted['encrypted'];
            $paymentIv = $encrypted['iv'];
            $paymentTag = $encrypted['tag'];
        }

        dbPrepare($conn, '
            INSERT INTO orders_tbl (account_id, total_amount_fld, payment_encrypted, payment_iv, payment_tag, order_status_fld)
            VALUES (:account_id, :total_amount, :payment_encrypted, :payment_iv, :payment_tag, :status)
        ')->execute([
            ':account_id' => $accountId,
            ':total_amount' => $totalAmount,
            ':payment_encrypted' => $paymentEncrypted,
            ':payment_iv' => $paymentIv,
            ':payment_tag' => $paymentTag,
            ':status' => $status,
        ]);

        $orderId = intval($conn->lastInsertId());
        foreach ($lineItems as $lineItem) {
            dbPrepare($conn, '
                INSERT INTO order_items_tbl (order_id, book_id, quantity_fld, price_at_purchase_fld)
                VALUES (:order_id, :book_id, :quantity, :price)
            ')->execute([
                ':order_id' => $orderId,
                ':book_id' => $lineItem['book']['book_id'],
                ':quantity' => $lineItem['quantity'],
                ':price' => $lineItem['price'],
            ]);

            dbPrepare($conn, '
                UPDATE books_tbl
                SET stock_qty_fld = stock_qty_fld - :quantity
                WHERE book_id = :book_id
            ')->execute([
                ':quantity' => $lineItem['quantity'],
                ':book_id' => $lineItem['book']['book_id'],
            ]);
        }

        apiResponse(201, ['success' => true, 'message' => 'Order created successfully', 'order' => apiFetchOrderById($conn, $orderId)]);
    }

    if (($segments[0] ?? '') === 'user' && $method === 'GET') {
        $userId = intval($segments[1] ?? 0);
        if ($userId <= 0) {
            apiResponse(400, ['success' => false, 'message' => 'Invalid user id']);
        }
        if (intval($currentUser['account_id']) !== $userId && $currentUser['role'] !== 'admin') {
            apiResponse(403, ['success' => false, 'message' => 'Access denied']);
        }

        $stmt = dbPrepare($conn, "
            SELECT order_id
            FROM orders_tbl
            WHERE account_id = :account_id AND order_status_fld != 'cart'
            ORDER BY order_created_fld DESC
        ");
        $stmt->execute([':account_id' => $userId]);
        $orders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $order = apiFetchOrderById($conn, intval($row['order_id']));
            if ($order) {
                $orders[] = $order;
            }
        }

        apiResponse(200, ['success' => true, 'orders' => $orders, 'total' => count($orders)]);
    }

    $orderId = intval($segments[0] ?? 0);
    if ($orderId > 0 && ($segments[1] ?? '') === 'items' && intval($segments[2] ?? 0) > 0 && ($segments[3] ?? '') === 'cancel' && $method === 'POST') {
        $orderItemId = intval($segments[2]);
        $order = apiFetchOrderById($conn, $orderId);
        if (!$order) {
            apiResponse(404, ['success' => false, 'message' => 'Order not found']);
        }
        if (intval($order['account_id']) !== intval($currentUser['account_id']) && $currentUser['role'] !== 'admin') {
            apiResponse(403, ['success' => false, 'message' => 'Access denied']);
        }
        if (!in_array($order['status'], ['pending', 'paid'], true)) {
            apiResponse(400, ['success' => false, 'message' => 'Only pending or paid items can be cancelled']);
        }

        $matchedItem = null;
        foreach ($order['items'] as $item) {
            if (intval($item['order_item_id']) === $orderItemId) {
                $matchedItem = $item;
                break;
            }
        }

        if (!$matchedItem) {
            apiResponse(404, ['success' => false, 'message' => 'Order item not found']);
        }

        $shouldCancelWholeOrder = count($order['items']) === 1;

        try {
            $conn->beginTransaction();

            dbPrepare($conn, '
                UPDATE books_tbl
                SET stock_qty_fld = stock_qty_fld + :quantity
                WHERE book_id = :book_id
            ')->execute([
                ':quantity' => intval($matchedItem['quantity']),
                ':book_id' => intval($matchedItem['book_id']),
            ]);

            if ($shouldCancelWholeOrder) {
                dbPrepare($conn, '
                    UPDATE orders_tbl
                    SET order_status_fld = :status
                    WHERE order_id = :order_id AND account_id = :account_id
                ')->execute([
                    ':status' => 'cancelled',
                    ':order_id' => $orderId,
                    ':account_id' => intval($order['account_id']),
                ]);
            } else {
                dbPrepare($conn, '
                    DELETE FROM order_items_tbl
                    WHERE order_item_id = :order_item_id AND order_id = :order_id
                ')->execute([
                    ':order_item_id' => $orderItemId,
                    ':order_id' => $orderId,
                ]);

                $remainingStmt = dbPrepare($conn, '
                    SELECT COUNT(*) AS item_count, COALESCE(SUM(price_at_purchase_fld * quantity_fld), 0) AS total_amount
                    FROM order_items_tbl
                    WHERE order_id = :order_id
                ');
                $remainingStmt->execute([':order_id' => $orderId]);
                $remaining = $remainingStmt->fetch(PDO::FETCH_ASSOC) ?: ['item_count' => 0, 'total_amount' => 0];

                dbPrepare($conn, '
                    UPDATE orders_tbl
                    SET total_amount_fld = :total_amount
                    WHERE order_id = :order_id AND account_id = :account_id
                ')->execute([
                    ':total_amount' => floatval($remaining['total_amount']),
                    ':order_id' => $orderId,
                    ':account_id' => intval($order['account_id']),
                ]);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            apiResponse(500, ['success' => false, 'message' => 'Failed to cancel order item']);
        }

        apiResponse(200, [
            'success' => true,
            'message' => $order['status'] === 'paid'
                ? 'Order item cancelled and refunded successfully'
                : 'Order item cancelled successfully',
            'cancelled_item_id' => $orderItemId,
            'was_paid' => $order['status'] === 'paid',
        ]);
    }

    if ($orderId > 0 && ($segments[1] ?? '') === 'cancel' && $method === 'POST') {
        $order = apiFetchOrderById($conn, $orderId);
        if (!$order) {
            apiResponse(404, ['success' => false, 'message' => 'Order not found']);
        }
        if (intval($order['account_id']) !== intval($currentUser['account_id']) && $currentUser['role'] !== 'admin') {
            apiResponse(403, ['success' => false, 'message' => 'Access denied']);
        }
        if (!in_array($order['status'], ['pending', 'paid'], true)) {
            apiResponse(400, ['success' => false, 'message' => 'Only pending or paid orders can be cancelled']);
        }

        try {
            $conn->beginTransaction();

            foreach ($order['items'] as $item) {
                dbPrepare($conn, '
                    UPDATE books_tbl
                    SET stock_qty_fld = stock_qty_fld + :quantity
                    WHERE book_id = :book_id
                ')->execute([
                    ':quantity' => intval($item['quantity']),
                    ':book_id' => intval($item['book_id']),
                ]);
            }

            dbPrepare($conn, '
                UPDATE orders_tbl
                SET order_status_fld = :status
                WHERE order_id = :order_id AND account_id = :account_id
            ')->execute([
                ':status' => 'cancelled',
                ':order_id' => $orderId,
                ':account_id' => intval($order['account_id']),
            ]);

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            apiResponse(500, ['success' => false, 'message' => 'Failed to cancel order']);
        }

        $updatedOrder = apiFetchOrderById($conn, $orderId);
        apiResponse(200, [
            'success' => true,
            'message' => $order['status'] === 'paid'
                ? 'Order cancelled and refunded successfully'
                : 'Order cancelled successfully',
            'order' => $updatedOrder,
        ]);
    }

    if ($orderId > 0 && ($segments[1] ?? '') === 'complete' && $method === 'PUT') {
        $order = apiFetchOrderById($conn, $orderId);
        if (!$order) {
            apiResponse(404, ['success' => false, 'message' => 'Order not found']);
        }
        if (intval($order['account_id']) !== intval($currentUser['account_id']) && $currentUser['role'] !== 'admin') {
            apiResponse(403, ['success' => false, 'message' => 'Access denied']);
        }
        if ($order['status'] !== 'shipped') {
            apiResponse(400, ['success' => false, 'message' => 'Only shipped orders can be marked as completed']);
        }

        dbPrepare($conn, '
            UPDATE orders_tbl
            SET order_status_fld = :status
            WHERE order_id = :order_id AND account_id = :account_id
        ')->execute([
            ':status' => 'completed',
            ':order_id' => $orderId,
            ':account_id' => intval($order['account_id']),
        ]);

        $updatedOrder = apiFetchOrderById($conn, $orderId);
        apiResponse(200, [
            'success' => true,
            'message' => 'Order marked as completed successfully',
            'order' => $updatedOrder,
        ]);
    }

    if ($orderId > 0 && $method === 'GET') {
        $order = apiFetchOrderById($conn, $orderId);
        if (!$order) {
            apiResponse(404, ['success' => false, 'message' => 'Order not found']);
        }
        if (intval($order['account_id']) !== intval($currentUser['account_id']) && $currentUser['role'] !== 'admin') {
            apiResponse(403, ['success' => false, 'message' => 'Access denied']);
        }

        apiResponse(200, ['success' => true, 'order' => $order]);
    }

    apiResponse(404, ['success' => false, 'message' => 'Order endpoint not found']);
}

function handleAdminRoutes(PDO $conn, string $method, array $segments): void
{
    apiRequireAdmin();
    $resource = $segments[0] ?? '';

    if ($resource === 'users' && $method === 'GET') {
        $stmt = dbPrepare($conn, '
            SELECT ua.account_id, ua.name_id,
                   ua.email, ua.email_encrypted, ua.email_iv, ua.email_tag, ua.email_hash,
                   ua.password_hash, ua.role,
                   ua.phone_encrypted, ua.phone_iv, ua.phone_tag,
                   un.fname_fld, un.lname_fld
            FROM user_account_tbl ua
            JOIN user_name_tbl un ON un.name_id = ua.name_id
            ORDER BY ua.account_id ASC
        ');
        $stmt->execute();
        $users = array_map(static fn(array $user): array => apiFormatUser($user, false), $stmt->fetchAll(PDO::FETCH_ASSOC));
        apiResponse(200, ['success' => true, 'users' => $users, 'total' => count($users)]);
    }

    if ($resource === 'books' && $method === 'GET') {
        handleBookRoutes($conn, 'GET', []);
    }

    if ($resource === 'orders' && $method === 'GET') {
        $stmt = dbPrepare($conn, '
            SELECT order_id
            FROM orders_tbl
            WHERE order_status_fld != :cart_status
            ORDER BY order_created_fld DESC, order_id DESC
        ');
        $stmt->execute([':cart_status' => 'cart']);

        $orders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $orderId) {
            $order = apiFetchOrderById($conn, intval($orderId));
            if ($order !== null) {
                $orders[] = $order;
            }
        }

        apiResponse(200, ['success' => true, 'orders' => $orders, 'total' => count($orders)]);
    }

    if ($resource === 'orders' && intval($segments[1] ?? 0) > 0 && ($segments[2] ?? '') === 'status' && $method === 'PUT') {
        $orderId = intval($segments[1]);
        $data = apiReadJsonBody();
        $nextStatus = trim((string) ($data['status'] ?? ''));

        if (!in_array($nextStatus, ['paid', 'shipped'], true)) {
            apiResponse(400, ['success' => false, 'message' => 'Admin can only change orders to paid or shipped']);
        }

        $order = apiFetchOrderById($conn, $orderId);
        if ($order === null) {
            apiResponse(404, ['success' => false, 'message' => 'Order not found']);
        }

        $allowedTransitions = [
            'pending' => 'paid',
            'paid' => 'shipped',
        ];

        $currentStatus = (string) ($order['status'] ?? '');
        $allowedNextStatus = $allowedTransitions[$currentStatus] ?? null;

        if ($allowedNextStatus === null || $allowedNextStatus !== $nextStatus) {
            apiResponse(400, [
                'success' => false,
                'message' => 'Only pending orders can become paid, and only paid orders can become shipped',
            ]);
        }

        dbPrepare($conn, '
            UPDATE orders_tbl
            SET order_status_fld = :status
            WHERE order_id = :order_id
        ')->execute([
            ':status' => $nextStatus,
            ':order_id' => $orderId,
        ]);

        $updatedOrder = apiFetchOrderById($conn, $orderId);
        apiResponse(200, [
            'success' => true,
            'message' => $nextStatus === 'paid'
                ? 'Order marked as paid successfully'
                : 'Order marked as shipped successfully',
            'order' => $updatedOrder,
        ]);
    }

    apiResponse(404, ['success' => false, 'message' => 'Admin endpoint not found']);
}

function handleReportRoutes(PDO $conn, string $method, array $segments): void
{
    apiRequireAdmin();
    $resource = $segments[0] ?? '';

    if ($resource === 'sales' && $method === 'GET') {
        $summaryStmt = dbPrepare($conn, "
            SELECT COUNT(*) AS successful_order_count,
                   COALESCE(SUM(total_amount_fld), 0) AS total_sales,
                   COALESCE(AVG(total_amount_fld), 0) AS average_order_value,
                   COALESCE(MAX(total_amount_fld), 0) AS highest_order_value,
                   COALESCE(MIN(total_amount_fld), 0) AS lowest_order_value,
                   COUNT(DISTINCT account_id) AS paying_customers
            FROM orders_tbl
            WHERE order_status_fld IN ('paid', 'shipped', 'completed')
        ");
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'successful_order_count' => 0,
            'total_sales' => 0,
            'average_order_value' => 0,
            'highest_order_value' => 0,
            'lowest_order_value' => 0,
            'paying_customers' => 0,
        ];

        $statusStmt = dbPrepare($conn, "
            SELECT order_status_fld AS status,
                   COUNT(*) AS total_orders,
                   COALESCE(SUM(total_amount_fld), 0) AS total_amount
            FROM orders_tbl
            WHERE order_status_fld != 'cart'
            GROUP BY order_status_fld
            ORDER BY FIELD(order_status_fld, 'pending', 'paid', 'shipped', 'completed', 'cancelled')
        ");
        $statusStmt->execute();
        $statusBreakdown = array_map(
            static fn(array $row): array => [
                'status' => $row['status'],
                'total_orders' => intval($row['total_orders']),
                'total_amount' => floatval($row['total_amount']),
            ],
            $statusStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $dailyStmt = dbPrepare($conn, "
            SELECT DATE(order_created_fld) AS sales_date,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(total_amount_fld), 0) AS total_sales
            FROM orders_tbl
            WHERE order_status_fld IN ('paid', 'shipped', 'completed')
            GROUP BY DATE(order_created_fld)
            ORDER BY sales_date DESC
            LIMIT 7
        ");
        $dailyStmt->execute();
        $dailySales = array_reverse(array_map(
            static fn(array $row): array => [
                'date' => $row['sales_date'],
                'order_count' => intval($row['order_count']),
                'total_sales' => floatval($row['total_sales']),
            ],
            $dailyStmt->fetchAll(PDO::FETCH_ASSOC)
        ));

        $monthlyStmt = dbPrepare($conn, "
            SELECT DATE_FORMAT(order_created_fld, '%Y-%m') AS sales_month,
                   COUNT(*) AS order_count,
                   COALESCE(SUM(total_amount_fld), 0) AS total_sales
            FROM orders_tbl
            WHERE order_status_fld IN ('paid', 'shipped', 'completed')
            GROUP BY DATE_FORMAT(order_created_fld, '%Y-%m')
            ORDER BY sales_month DESC
            LIMIT 6
        ");
        $monthlyStmt->execute();
        $monthlySales = array_reverse(array_map(
            static fn(array $row): array => [
                'month' => $row['sales_month'],
                'order_count' => intval($row['order_count']),
                'total_sales' => floatval($row['total_sales']),
            ],
            $monthlyStmt->fetchAll(PDO::FETCH_ASSOC)
        ));

        $topBooksStmt = dbPrepare($conn, "
            SELECT b.book_id,
                   b.title_fld AS title,
                   b.author_fld AS author,
                   SUM(oi.quantity_fld) AS total_quantity_sold,
                   SUM(oi.quantity_fld * oi.price_at_purchase_fld) AS total_revenue
            FROM order_items_tbl oi
            JOIN orders_tbl o ON o.order_id = oi.order_id
            JOIN books_tbl b ON b.book_id = oi.book_id
            WHERE o.order_status_fld IN ('paid', 'shipped', 'completed')
            GROUP BY b.book_id, b.title_fld, b.author_fld
            ORDER BY total_revenue DESC, total_quantity_sold DESC
            LIMIT 5
        ");
        $topBooksStmt->execute();
        $topBooks = array_map(
            static fn(array $row): array => [
                'book_id' => intval($row['book_id']),
                'title' => $row['title'],
                'author' => $row['author'],
                'total_quantity_sold' => intval($row['total_quantity_sold']),
                'total_revenue' => floatval($row['total_revenue']),
            ],
            $topBooksStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        apiResponse(200, [
            'success' => true,
            'report' => [
                'summary' => [
                    'successful_order_count' => intval($summary['successful_order_count']),
                    'total_sales' => floatval($summary['total_sales']),
                    'average_order_value' => floatval($summary['average_order_value']),
                    'highest_order_value' => floatval($summary['highest_order_value']),
                    'lowest_order_value' => floatval($summary['lowest_order_value']),
                    'paying_customers' => intval($summary['paying_customers']),
                ],
                'status_breakdown' => $statusBreakdown,
                'daily_sales' => $dailySales,
                'monthly_sales' => $monthlySales,
                'top_books' => $topBooks,
            ],
        ]);
    }

    if ($resource === 'orders' && $method === 'GET') {
        $summaryStmt = dbPrepare($conn, "
            SELECT COUNT(*) AS total_orders,
                   SUM(CASE WHEN order_status_fld = 'pending' THEN 1 ELSE 0 END) AS pending_orders,
                   SUM(CASE WHEN order_status_fld = 'paid' THEN 1 ELSE 0 END) AS paid_orders,
                   SUM(CASE WHEN order_status_fld = 'shipped' THEN 1 ELSE 0 END) AS shipped_orders,
                   SUM(CASE WHEN order_status_fld = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
                   SUM(CASE WHEN order_status_fld = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders
            FROM orders_tbl
            WHERE order_status_fld != 'cart'
        ");
        $summaryStmt->execute();
        $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'total_orders' => 0,
            'pending_orders' => 0,
            'paid_orders' => 0,
            'shipped_orders' => 0,
            'completed_orders' => 0,
            'cancelled_orders' => 0,
        ];

        $averageItemsStmt = dbPrepare($conn, "
            SELECT COALESCE(AVG(item_count), 0) AS average_items_per_order
            FROM (
                SELECT COUNT(*) AS item_count
                FROM order_items_tbl oi
                JOIN orders_tbl o ON o.order_id = oi.order_id
                WHERE o.order_status_fld != 'cart'
                GROUP BY oi.order_id
            ) AS order_item_counts
        ");
        $averageItemsStmt->execute();
        $averageItems = $averageItemsStmt->fetch(PDO::FETCH_ASSOC) ?: ['average_items_per_order' => 0];

        $statusStmt = dbPrepare($conn, "
            SELECT order_status_fld AS status,
                   COUNT(*) AS total_orders,
                   COALESCE(SUM(total_amount_fld), 0) AS total_amount
            FROM orders_tbl
            WHERE order_status_fld != 'cart'
            GROUP BY order_status_fld
            ORDER BY FIELD(order_status_fld, 'pending', 'paid', 'shipped', 'completed', 'cancelled')
        ");
        $statusStmt->execute();
        $statusBreakdown = array_map(
            static fn(array $row): array => [
                'status' => $row['status'],
                'total_orders' => intval($row['total_orders']),
                'total_amount' => floatval($row['total_amount']),
            ],
            $statusStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $topCustomersStmt = dbPrepare($conn, "
            SELECT ua.account_id,
                   un.fname_fld AS fname,
                   un.lname_fld AS lname,
                   ua.email,
                   ua.email_encrypted,
                   ua.email_iv,
                   ua.email_tag,
                   ua.email_hash,
                   COUNT(o.order_id) AS total_orders,
                   COALESCE(SUM(o.total_amount_fld), 0) AS total_amount
            FROM orders_tbl o
            JOIN user_account_tbl ua ON ua.account_id = o.account_id
            JOIN user_name_tbl un ON un.name_id = ua.name_id
            WHERE o.order_status_fld != 'cart'
            GROUP BY ua.account_id, un.fname_fld, un.lname_fld, ua.email, ua.email_encrypted, ua.email_iv, ua.email_tag, ua.email_hash
            ORDER BY total_orders DESC, total_amount DESC
            LIMIT 5
        ");
        $topCustomersStmt->execute();
        $topCustomers = array_map(
            static fn(array $row): array => [
                'account_id' => intval($row['account_id']),
                'fname' => $row['fname'],
                'lname' => $row['lname'],
                'email' => apiDecryptEmail($row),
                'total_orders' => intval($row['total_orders']),
                'total_amount' => floatval($row['total_amount']),
            ],
            $topCustomersStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $topBooksStmt = dbPrepare($conn, "
            SELECT b.book_id,
                   b.title_fld AS title,
                   b.author_fld AS author,
                   SUM(oi.quantity_fld) AS total_quantity_ordered
            FROM order_items_tbl oi
            JOIN orders_tbl o ON o.order_id = oi.order_id
            JOIN books_tbl b ON b.book_id = oi.book_id
            WHERE o.order_status_fld NOT IN ('cancelled', 'cart')
            GROUP BY b.book_id, b.title_fld, b.author_fld
            ORDER BY total_quantity_ordered DESC, b.book_id ASC
            LIMIT 5
        ");
        $topBooksStmt->execute();
        $topBooksByQuantity = array_map(
            static fn(array $row): array => [
                'book_id' => intval($row['book_id']),
                'title' => $row['title'],
                'author' => $row['author'],
                'total_quantity_ordered' => intval($row['total_quantity_ordered']),
            ],
            $topBooksStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $recentOrdersStmt = dbPrepare($conn, "
            SELECT o.order_id,
                   o.account_id,
                   un.fname_fld AS fname,
                   un.lname_fld AS lname,
                   o.order_status_fld AS status,
                   o.total_amount_fld AS total_amount,
                   o.order_created_fld AS created_at
            FROM orders_tbl o
            JOIN user_account_tbl ua ON ua.account_id = o.account_id
            JOIN user_name_tbl un ON un.name_id = ua.name_id
            WHERE o.order_status_fld != 'cart'
            ORDER BY o.order_created_fld DESC, o.order_id DESC
            LIMIT 3
        ");
        $recentOrdersStmt->execute();
        $recentOrders = array_map(
            static fn(array $row): array => [
                'order_id' => intval($row['order_id']),
                'account_id' => intval($row['account_id']),
                'customer_name' => trim($row['fname'] . ' ' . $row['lname']),
                'status' => $row['status'],
                'total_amount' => floatval($row['total_amount']),
                'created_at' => $row['created_at'],
            ],
            $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC)
        );

        $totalOrders = max(intval($summary['total_orders']), 1);
        $cancelledOrders = intval($summary['cancelled_orders']);
        $completedOrders = intval($summary['completed_orders']);

        apiResponse(200, [
            'success' => true,
            'report' => [
                'summary' => [
                    'total_orders' => intval($summary['total_orders']),
                    'pending_orders' => intval($summary['pending_orders']),
                    'paid_orders' => intval($summary['paid_orders']),
                    'shipped_orders' => intval($summary['shipped_orders']),
                    'completed_orders' => intval($summary['completed_orders']),
                    'cancelled_orders' => intval($summary['cancelled_orders']),
                    'cancellation_rate' => round(($cancelledOrders / $totalOrders) * 100, 2),
                    'completion_rate' => round(($completedOrders / $totalOrders) * 100, 2),
                    'average_items_per_order' => round(floatval($averageItems['average_items_per_order'] ?? 0), 2),
                ],
                'status_breakdown' => $statusBreakdown,
                'top_customers' => $topCustomers,
                'top_books_by_quantity' => $topBooksByQuantity,
                'recent_orders' => $recentOrders,
            ],
        ]);
    }

    apiResponse(404, ['success' => false, 'message' => 'Report endpoint not found']);
}
