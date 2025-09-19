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
$email = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Пожалуйста, попробуйте еще раз.';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        
        // Валидация данных
        if (empty($email)) {
            $error = 'Пожалуйста, введите email адрес.';
        } elseif (!isValidEmail($email)) {
            $error = 'Пожалуйста, введите корректный email адрес.';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            try {
                // Проверяем, существует ли пользователь с таким email
                $query = "SELECT id, first_name, email FROM users WHERE email = :email AND is_active = TRUE";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Генерируем токен для сброса пароля
                    $token = generateToken();
                    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    
                    // Удаляем старые токены
                    $query = "DELETE FROM password_reset_tokens WHERE user_id = :user_id";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $user['id']);
                    $stmt->execute();
                    
                    // Сохраняем новый токен
                    $query = "INSERT INTO password_reset_tokens (user_id, token, expires_at) 
                              VALUES (:user_id, :token, :expires_at)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':user_id', $user['id']);
                    $stmt->bindParam(':token', $token);
                    $stmt->bindParam(':expires_at', $expiresAt);
                    $stmt->execute();
                    
                    // Отправляем email с ссылкой для сброса пароля
                    $resetLink = "https://" . $_SERVER['HTTP_HOST'] . "/reset_password.php?token=" . $token;
                    
                    // В реальном приложении здесь должен быть код отправки email
                    // mail($email, "Восстановление пароля", "Для сброса пароля перейдите по ссылке: $resetLink");
                    
                    $success = 'На ваш email отправлена инструкция для восстановления пароля.';
                    logSecurityEvent('password_reset_requested', $user['id']);
                } else {
                    // Для безопасности не сообщаем, что пользователь не найден
                    $success = 'Если email зарегистрирован в системе, на него будет отправлена инструкция для восстановления пароля.';
                }
            } catch (PDOException $exception) {
                $error = 'Ошибка базы данных: ' . $exception->getMessage();
                logSecurityEvent('password_reset_error', null, ['error' => $exception->getMessage()]);
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
    <title>Восстановление пароля - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8">
    <div class="bg-white shadow-md rounded-lg overflow-hidden w-full max-w-md">
        <div class="bg-green-600 py-4 px-6">
            <h1 class="text-white text-xl font-bold flex items-center">
                <i data-feather="lock" class="mr-2"></i> Восстановление пароля
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
            
            <p class="text-gray-600 mb-4">Введите email, указанный при регистрации, и мы вышлем вам инструкцию для восстановления пароля.</p>
            
            <form method="POST" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-1" for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" 
                           class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                
                <div>
                    <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                        Отправить инструкцию
                    </button>
                </div>
            </form>
            
            <div class="mt-4 text-center">
                <p class="text-gray-600 text-sm">Вспомнили пароль? 
                    <a href="login.php" class="text-green-600 hover:text-green-800 font-medium">Войти</a>
                </p>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>