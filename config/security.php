<?php
// Генерация безопасного токена
function generateToken($length = 32) {
    return bin2hex(random_bytes($length));
}

// Хеширование пароля
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// Проверка пароля
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// Проверка сложности пароля
function isPasswordStrong($password) {
    // Минимум 8 символов,至少一个大写字母，一个小写字母，一个数字
    return strlen($password) >= 8 && 
           preg_match('/[A-Z]/', $password) && 
           preg_match('/[a-z]/', $password) && 
           preg_match('/[0-9]/', $password);
}

// Санитизация ввода
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Генерация CSRF-токена
function generateCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Проверка CSRF-токена
function verifyCsrfToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Проверка email
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Логирование безопасности
function logSecurityEvent($event, $userId = null, $details = []) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'user_id' => $userId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => json_encode($details)
    ];
    
    // Запись в файл лога
    $logMessage = implode(' | ', $logData) . PHP_EOL;
    file_put_contents('logs/security.log', $logMessage, FILE_APPEND | LOCK_EX);
}

define('ENCRYPTION_KEY', 'my_super_secret_key_32chars'); // 32 символа
define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encryptData($data) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptData($data) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
}

?>