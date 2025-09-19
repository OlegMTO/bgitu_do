<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

// Если пользователь уже авторизован, перенаправляем в профиль
if (isset($_SESSION['user_id'])) {
    header('Location: profile.php');
    exit;
}

$error = '';
$success = '';
$token = $_GET['token'] ?? '';

// Проверяем валидность токена
$isValidToken = false;
$userEmail = '';

if (!empty($token)) {
    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Проверяем токен и его срок действия
        $query = "SELECT u.email, prt.user_id 
                  FROM password_reset_tokens prt 
                  JOIN users u ON prt.user_id = u.id 
                  WHERE prt.token = :token AND prt.expires_at > NOW()";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':token', $token);
        $stmt->execute();
        
        $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tokenData) {
            $isValidToken = true;
            $userEmail = $tokenData['email'];
            $userId = $tokenData['user_id'];
        } else {
            $error = 'Недействительная или просроченная ссылка для сброса пароля.';
        }
    } catch (PDOException $exception) {
        $error = 'Ошибка базы данных: ' . $exception->getMessage();
    }
} else {
    $error = 'Неверная ссылка для сброса пароля.';
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidToken) {
    // Проверка CSRF-токена
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Пожалуйста, попробуйте еще раз.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Валидация данных
        if (empty($password) || empty($confirmPassword)) {
            $error = 'Пожалуйста, заполните все поля.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Пароли не совпадают.';
        } elseif (!isPasswordStrong($password)) {
            $error = 'Пароль должен содержать минимум 8 символов, включая заглавные и строчные буквы, и цифры.';
        } else {
            try {
                // Хешируем новый пароль
                $passwordHash = hashPassword($password);
                
                // Обновляем пароль пользователя
                $query = "UPDATE users SET password_hash = :password_hash, updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':password_hash', $passwordHash);
                $stmt->bindParam(':id', $userId);
                
                if ($stmt->execute()) {
                    // Удаляем использованный токен
                    $query = "DELETE FROM password_reset_tokens WHERE token = :token";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':token', $token);
                    $stmt->execute();
                    
                    $success = 'Пароль успешно изменен. Теперь вы можете войти с новым паролем.';
                    logSecurityEvent('password_reset_success', $userId);
                    
                    // Перенаправляем на страницу входа через 3 секунды
                    header("refresh:3;url=login.php");
                } else {
                    $error = 'Ошибка при изменении пароля. Пожалуйста, попробуйте позже.';
                }
            } catch (PDOException $exception) {
                $error = 'Ошибка базы данных: ' . $exception->getMessage();
                logSecurityEvent('password_reset_error', $userId, ['error' => $exception->getMessage()]);
            }
        }
    }
}

// Генерируем CSRF-токен
$csrfToken = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Новый пароль - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8">
    <div class="bg-white shadow-md rounded-lg overflow-hidden w-full max-w-md">
        <div class="bg-green-600 py-4 px-6">
            <h1 class="text-white text-xl font-bold flex items-center">
                <i data-feather="key" class="mr-2"></i> Новый пароль
            </h1>
        </div>
        
        <div class="p-6">
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
            
            <?php if ($isValidToken): ?>
            <p class="text-gray-600 mb-4">Установите новый пароль для вашего аккаунта: <span class="font-medium"><?php echo htmlspecialchars($userEmail); ?></span></p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-1" for="password">Новый пароль</label>
                    <input type="password" id="password" name="password" 
                           class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    <p class="text-xs text-gray-500 mt-1">Пароль должен содержать минимум 8 символов, включая заглавные и строчные буквы, и цифры.</p>
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-1" for="confirm_password">Подтверждение пароля</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                
                <div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                        Установить новый пароль
                    </button>
                </div>
            </form>
            <?php else: ?>
            <div class="text-center py-4">
                <p class="text-gray-600"><?php echo $error; ?></p>
                <div class="mt-4">
                    <a href="forgot_password.php" class="text-green-600 hover:text-green-800 font-medium">Запросить новую ссылку</a>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="mt-4 text-center">
                <p class="text-gray-600 text-sm">
                    <a href="login.php" class="text-green-600 hover:text-green-800 font-medium">Вернуться к входу</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>