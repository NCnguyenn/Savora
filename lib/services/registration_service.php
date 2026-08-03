<?php
declare(strict_types=1);

require_once __DIR__ . '/../repositories/registration_repository.php';

function registration_result(bool $ok, int $status, string $message, array $data = [], array $errors = []): array
{
    $result = ['ok' => $ok, 'status' => $status, 'message' => $message];
    if ($data !== []) $result['data'] = $data;
    if ($errors !== []) $result['errors'] = $errors;
    return $result;
}

function registration_normalize_identifier(string $value): string
{
    return mb_strtolower(trim($value));
}

function registration_text(mixed $value, int $maximum, string $field, bool $required = true): string
{
    $text = trim((string) $value);
    if (($required && $text === '') || mb_strlen($text) > $maximum) {
        throw new InvalidArgumentException($field . ' is invalid.');
    }
    return $text;
}

function registration_customer_payload(array $input): array
{
    $username = registration_normalize_identifier((string) ($input['username'] ?? ''));
    $email = registration_normalize_identifier((string) ($input['email'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $confirmation = (string) ($input['passwordConfirmation'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{3,50}$/', $username)) {
        throw new InvalidArgumentException('Username must contain 3-50 letters, numbers, underscores, or hyphens.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if (strlen($password) < 10 || $password !== $confirmation) {
        throw new InvalidArgumentException('Passwords must match and contain at least 10 characters.');
    }
    if (!filter_var($input['acceptedTerms'] ?? false, FILTER_VALIDATE_BOOL)) {
        throw new InvalidArgumentException('Accept the Terms of Service and Privacy Policy.');
    }
    return [
        'fullName' => registration_text($input['fullName'] ?? '', 120, 'Full name'),
        'username' => $username,
        'email' => $email,
        'phone' => registration_text($input['phone'] ?? '', 40, 'Phone number'),
        'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
        'address' => registration_text($input['deliveryAddress'] ?? '', 500, 'Delivery address'),
        'notes' => registration_text($input['defaultDeliveryNotes'] ?? '', 500, 'Default delivery notes', false),
    ];
}

function registration_register_customer(mysqli $conn, array $input): array
{
    try {
        $data = registration_customer_payload($input);
    } catch (InvalidArgumentException $exception) {
        return registration_result(false, 422, $exception->getMessage());
    }

    $conn->begin_transaction();
    try {
        $userId = registration_repository_create_user($conn, [
            'username' => $data['username'],
            'passwordHash' => $data['passwordHash'],
            'role' => 'customer',
            'fullName' => $data['fullName'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'status' => 'active',
        ]);
        registration_repository_claim($conn, 'username', $data['username'], 'user', $userId);
        registration_repository_claim($conn, 'email', $data['email'], 'user', $userId);
        registration_repository_create_customer_profile($conn, $userId, $data);
        $conn->commit();
        return registration_result(true, 201, 'Your Customer account is ready. Sign in to continue.', [
            'userId' => $userId,
            'role' => 'customer',
            'next' => 'index.php',
        ]);
    } catch (mysqli_sql_exception $exception) {
        $conn->rollback();
        if ((int) $exception->getCode() === 1062) {
            return registration_result(false, 409, 'Username or email is already in use.');
        }
        return registration_result(false, 500, 'Customer registration could not be completed.');
    } catch (Throwable) {
        $conn->rollback();
        return registration_result(false, 500, 'Customer registration could not be completed.');
    }
}
