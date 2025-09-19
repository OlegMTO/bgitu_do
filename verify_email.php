<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

if (!empty($token)) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Проверяем токен и его срок действия
        $query = "SELECT u.id, u.email, u.email_verified, evt.expires_at 
                  FROM email_verification_tokens evt 
                  JOIN users u ON evt.user_id = u.id 
                  WHERE evt.token = :token";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tokenData) {
            if ($tokenData['email_verified']) {
                $success = 'Ваш email уже подтвержден.';
            } elseif (strtotime($tokenData['expires_at']) < time()) {
                $error = 'Срок действия ссылки для подтверждения истек.';
            } else {
                // Подтверждаем email
                $query = "UPDATE users SET email_verified = TRUE, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $tokenData['id']);
                $stmt->execute();
                
                // Удаляем использованный токен
                $query = "DELETE FROM email_verification_tokens WHERE token = :token";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':token', $token);
                $stmt->execute();
                
                $success = 'Ваш email успешно подтвержден! Теперь вы можете войти в свой аккаунт.';
                logSecurityEvent('email_verified', $tokenData['id']);
                
                // Если пользователь авторизован, обновляем сессию
                if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $tokenData['id']) {
                    $_SESSION['email_verified'] = true;
                }
            }
        } else {
            $error = 'Недействительная ссылка для подтверждения email.';
        }
    } catch (PDOException $exception) {
        $error = 'Ошибка базы данных: ' . $exception->getMessage();
    }
} else {
    $error = 'Неверная ссылка для подтверждения email.';
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение email - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8">
    <div class="bg-white shadow-md rounded-lg overflow-hidden w-full max-w-md">
        <div class="bg-green-600 py-4 px-6">
            <h1 class="text-white text-xl font-bold flex items-center">
                <i data-feather="mail" class="mr-2"></i> Подтверждение email
            </h1>
        </div>
        
        <div class="p-6 text-center">
            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                <span class="block sm:inline"><?php echo $success; ?></span>
            </div>
            <?php endif; ?>
            
            <div class="my-6">
                <?php if ($error): ?>
                <i data-feather="x-circle" class="h-16 w-16 text-red-500 mx-auto"></i>
                <?php else: ?>
                <i data-feather="check-circle" class="h-16 w-16 text-green-500 mx-auto"></i>
                <?php endif; ?>
            </div>
            
            <div class="mt-6">
                <?php if (isset($_SESSION['user_id'])): ?>
                <a href="profile.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    Перейти в профиль
                </a>
                <?php else: ?>
                <a href="login.php" class="inline-block bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    Войти в аккаунт
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>