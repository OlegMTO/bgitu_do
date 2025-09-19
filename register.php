<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

// Если пользователь уже авторизован, перенаправляем в личный кабинет
if (isset($_SESSION['user_id'])) {
    header('Location: profile.php');
    exit;
}

$error = '';
$success = '';
$email = '';
$firstName = '';
$lastName = '';
$phone = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка CSRF-токена
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Пожалуйста, попробуйте еще раз.';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $phone = sanitizeInput($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Валидация данных
        if (empty($email) || empty($firstName) || empty($lastName) || empty($password)) {
            $error = 'Пожалуйста, заполните все обязательные поля.';
        } elseif (!isValidEmail($email)) {
            $error = 'Пожалуйста, введите корректный email адрес.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Пароли не совпадают.';
        } elseif (!isPasswordStrong($password)) {
            $error = 'Пароль должен содержать минимум 8 символов, включая заглавные и строчные буквы, и цифры.';
        } else {
            $database = new Database();
            $db = $database->getConnection();
            
            try {
                // Проверяем, существует ли уже пользователь с таким email
                $query = "SELECT id FROM users WHERE email = :email";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                
                if ($stmt->fetch()) {
                    $error = 'Пользователь с таким email уже зарегистрирован.';
                } else {
                    // Хешируем пароль
                    $passwordHash = hashPassword($password);
                    
                    // Создаем пользователя
                    $query = "INSERT INTO users (email, password_hash, first_name, last_name, phone) 
                              VALUES (:email, :password_hash, :first_name, :last_name, :phone)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':password_hash', $passwordHash);
                    $stmt->bindParam(':first_name', $firstName);
                    $stmt->bindParam(':last_name', $lastName);
                    $stmt->bindParam(':phone', $phone);
                    
                    if ($stmt->execute()) {
                        $userId = $db->lastInsertId();
                        
                        // Генерируем токен для подтверждения email
                        $token = generateToken();
                        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
                        
                        $query = "INSERT INTO email_verification_tokens (user_id, token, expires_at) 
                                  VALUES (:user_id, :token, :expires_at)";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':user_id', $userId);
                        $stmt->bindParam(':token', $token);
                        $stmt->bindParam(':expires_at', $expiresAt);
                        $stmt->execute();
                        
                        // Отправляем email с подтверждением (заглушка)
                        // В реальном приложении здесь должен быть код отправки email
                        
                        $success = 'Регистрация успешна! На ваш email отправлено письмо с подтверждением.';
                        logSecurityEvent('user_registered', $userId, ['email' => $email]);
                        
                        // Очищаем форму
                        $email = $firstName = $lastName = $phone = '';
                    } else {
                        $error = 'Ошибка при создании пользователя. Пожалуйста, попробуйте позже.';
                    }
                }
            } catch (PDOException $exception) {
                $error = 'Ошибка базы данных: ' . $exception->getMessage();
                logSecurityEvent('registration_error', null, ['error' => $exception->getMessage()]);
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
    <title>Регистрация - БГИТУ | Инновационные технологии</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;700&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'neo-blue': '#00c6ff',
                        'neo-purple': '#0072ff',
                        'neo-cyan': '#00f2fe',
                        'neo-dark': '#0a1930',
                        'neo-light': '#1a2c4f',
                    },
                    animation: {
                        'pulse-glow': 'pulse-glow 2s ease-in-out infinite alternate',
                        'float': 'float 6s ease-in-out infinite',
                        'spin-slow': 'spin 20s linear infinite',
                    },
                    fontFamily: {
                        'orbitron': ['Orbitron', 'sans-serif'],
                        'exo': ['Exo 2', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Exo 2', sans-serif;
            background: linear-gradient(to bottom, #0a1930 0%, #0d1b36 40%, #122543 100%);
            color: #fff;
            min-height: 100vh;
            overflow-x: hidden;
        }
        
        .cyber-container {
            position: relative;
            z-index: 20;
            background: rgba(10, 25, 48, 0.7);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(0, 198, 255, 0.3);
            box-shadow: 0 0 40px rgba(0, 198, 255, 0.2),
                        inset 0 0 20px rgba(0, 198, 255, 0.1);
            border-radius: 16px;
            overflow: hidden;
        }
        
        .cyber-border {
            position: relative;
        }
        
        .cyber-border::before {
            content: '';
            position: absolute;
            top: -2px;
            left: -2px;
            right: -2px;
            bottom: -2px;
            background: linear-gradient(45deg, #00c6ff, #0072ff, #00c6ff);
            z-index: -1;
            border-radius: 18px;
            animation: border-glow 3s ease-in-out infinite alternate;
        }
        
        @keyframes border-glow {
            0% {
                opacity: 0.4;
                filter: blur(5px);
            }
            100% {
                opacity: 1;
                filter: blur(8px);
            }
        }
        
        .holographic-card {
            background: rgba(26, 44, 79, 0.6);
            border: 1px solid rgba(0, 198, 255, 0.2);
            border-radius: 12px;
            backdrop-filter: blur(5px);
            transition: all 0.3s ease;
        }
        
        .holographic-card:hover {
            background: rgba(26, 44, 79, 0.8);
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 198, 255, 0.3);
        }
        
        .input-neo {
            background: rgba(10, 25, 48, 0.7);
            border: 2px solid rgba(0, 198, 255, 0.3);
            border-radius: 8px;
            color: white;
            transition: all 0.3s ease;
        }
        
        .input-neo:focus {
            outline: none;
            border-color: #00c6ff;
            box-shadow: 0 0 15px rgba(0, 198, 255, 0.5);
        }
        
        .btn-neo {
            background: linear-gradient(90deg, #0072ff 0%, #00c6ff 100%);
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .btn-neo::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            transition: all 0.5s ease;
        }
        
        .btn-neo:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(0, 198, 255, 0.4);
        }
        
        .btn-neo:hover::before {
            left: 100%;
        }
        
        .cyber-grid {
            background-image: 
                linear-gradient(rgba(0, 198, 255, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 198, 255, 0.1) 1px, transparent 1px);
            background-size: 30px 30px;
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }
        
        .floating-particles {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
        }
        
        .particle {
            position: absolute;
            background: rgba(0, 198, 255, 0.5);
            border-radius: 50%;
            filter: blur(2px);
        }
        
        .cyber-globe {
            width: 300px;
            height: 300px;
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle at 30% 30%, rgba(0, 198, 255, 0.3), rgba(0, 114, 255, 0.1));
            box-shadow: inset 0 0 50px rgba(0, 198, 255, 0.2);
            transform-style: preserve-3d;
            animation: spin-slow 20s linear infinite;
        }
        
        .hologram-line {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 5px;
            background: linear-gradient(90deg, transparent, #00c6ff, transparent);
            filter: blur(1px);
            animation: scanline 3s linear infinite;
        }
        
        @keyframes scanline {
            0% {
                transform: translateY(-200px);
            }
            100% {
                transform: translateY(calc(100vh + 200px));
            }
        }
        
        .cyber-title {
            font-family: 'Orbitron', sans-serif;
            text-shadow: 0 0 10px rgba(0, 198, 255, 0.7);
            letter-spacing: 2px;
            background: linear-gradient(90deg, #00c6ff, #0072ff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .binary-rain {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -2;
            overflow: hidden;
        }
        
        .binary-digit {
            position: absolute;
            color: rgba(0, 198, 255, 0.3);
            font-family: 'Courier New', monospace;
            font-size: 18px;
            animation: fall linear infinite;
        }
        
        @keyframes fall {
            to {
                transform: translateY(100vh);
            }
        }
        
        .tech-element {
            position: absolute;
            border: 1px solid rgba(0, 198, 255, 0.3);
            box-shadow: 0 0 15px rgba(0, 198, 255, 0.2);
            border-radius: 5px;
            transform-style: preserve-3d;
            animation: float 6s ease-in-out infinite;
        }
        
        .cyber-checkbox {
            appearance: none;
            -webkit-appearance: none;
            width: 20px;
            height: 20px;
            background: rgba(10, 25, 48, 0.7);
            border: 2px solid rgba(0, 198, 255, 0.3);
            border-radius: 5px;
            cursor: pointer;
            position: relative;
            transition: all 0.3s ease;
        }
        
        .cyber-checkbox:checked {
            background: rgba(0, 198, 255, 0.3);
            border-color: #00c6ff;
        }
        
        .cyber-checkbox:checked::after {
            content: '✓';
            position: absolute;
            color: #00c6ff;
            font-size: 14px;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-shadow: 0 0 5px rgba(0, 198, 255, 0.7);
        }
        
        .error-pulse {
            animation: error-pulse 0.5s ease-in-out;
        }
        
        @keyframes error-pulse {
            0% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            50% { transform: translateX(5px); }
            75% { transform: translateX(-5px); }
            100% { transform: translateX(0); }
        }
        
        .password-strength {
            height: 5px;
            transition: all 0.3s ease;
            border-radius: 4px;
        }
        
        .circuit-pattern {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(0, 198, 255, 0.13) 0%, transparent 19%),
                radial-gradient(circle at 85% 30%, rgba(0, 114, 255, 0.1) 0%, transparent 26%),
                radial-gradient(circle at 50% 70%, rgba(0, 242, 254, 0.08) 0%, transparent 20%);
            background-size: 100% 100%;
            z-index: -1;
            opacity: 0.5;
        }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <!-- Бинарный дождь -->
    <div class="binary-rain" id="binaryRain"></div>
    
    <!-- Анимированный сетчатый фон -->
    <div class="cyber-grid"></div>
    
    <!-- Circuit Pattern Background -->
    <div class="circuit-pattern"></div>
    
    <!-- Плавающие частицы -->
    <div class="floating-particles" id="particles"></div>
    
    <!-- Глобус -->
    <div class="cyber-globe" style="top: 10%; right: 5%;"></div>
    
    <!-- Холографическая линия -->
    <div class="hologram-line"></div>
    
    <!-- Технологические элементы -->
    <div class="tech-element w-10 h-10" style="top: 20%; left: 10%; animation-delay: 0s;"></div>
    <div class="tech-element w-6 h-6" style="top: 60%; left: 15%; animation-delay: 2s;"></div>
    <div class="tech-element w-8 h-8" style="top: 40%; right: 10%; animation-delay: 4s;"></div>
    <div class="tech-element w-12 h-12" style="bottom: 20%; right: 15%; animation-delay: 1s;"></div>
    <div class="tech-element w-7 h-7" style="bottom: 30%; left: 20%; animation-delay: 3s;"></div>
    
    <!-- Основной контейнер -->
    <div class="cyber-container cyber-border w-full max-w-2xl">
        <!-- Заголовок -->
        <div class="text-center py-8 px-6 relative overflow-hidden">
            <div class="absolute inset-0 opacity-10">
                <div class="absolute top-0 left-0 w-full h-full" style="
                    background-image: url('data:image/svg+xml;utf8,<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 100 100\"><circle cx=\"50\" cy=\"50\" r=\"40\" fill=\"none\" stroke=\"%230066ff\" stroke-width=\"0.5\"/><line x1=\"50\" y1=\"10\" x2=\"50\" y2=\"50\" stroke=\"%230066ff\" stroke-width=\"0.5\"/><line x1=\"50\" y1=\"50\" x2=\"80\" y2=\"65\" stroke=\"%230066ff\" stroke-width=\"0.5\"/></svg>');
                    background-size: 80px;
                    background-repeat: repeat;
                "></div>
            </div>
            
            <h1 class="cyber-title text-4xl font-bold mb-2 relative z-10">
                <i class="fas fa-brain mr-2"></i>БГИТУ
            </h1>
            <p class="text-neo-cyan text-sm mb-4 relative z-10 font-exo">Регистрация • Инновации • Технологии</p>
            
            <div class="relative">
                <div class="h-1 w-20 bg-gradient-to-r from-neo-purple to-neo-cyan mx-auto rounded-full"></div>
                <div class="h-1 w-20 bg-gradient-to-r from-neo-cyan to-neo-purple mx-auto mt-1 rounded-full"></div>
            </div>
        </div>
        
        <!-- Форма -->
        <div class="px-8 pb-10">
            <?php if ($error): ?>
                <div id="error-message" class="holographic-card p-4 mb-6 error-pulse flex items-center">
                    <i class="fas fa-exclamation-triangle text-red-400 mr-3"></i>
                    <span class="text-red-200"><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="holographic-card p-4 mb-6 flex items-center bg-green-900 bg-opacity-50">
                    <i class="fas fa-check-circle text-green-400 mr-3"></i>
                    <span class="text-green-200"><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="holographic-card p-4">
                        <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="first_name">
                            <i class="fas fa-signature mr-2"></i>Имя *
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-user text-neo-cyan"></i>
                            </div>
                            <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($firstName); ?>" 
                                   class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none" required>
                        </div>
                    </div>
                    
                    <div class="holographic-card p-4">
                        <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="last_name">
                            <i class="fas fa-users mr-2"></i>Фамилия *
                        </label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-user-circle text-neo-cyan"></i>
                            </div>
                            <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($lastName); ?>" 
                                   class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none" required>
                        </div>
                    </div>
                </div>
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="email">
                        <i class="fas fa-at mr-2"></i>Email *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-envelope text-neo-cyan"></i>
                        </div>
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" 
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none" required>
                    </div>
                </div>
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="phone">
                        <i class="fas fa-phone mr-2"></i>Телефон
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-mobile-alt text-neo-cyan"></i>
                        </div>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>" 
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none">
                    </div>
                </div>
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="password">
                        <i class="fas fa-key mr-2"></i>Пароль *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-neo-cyan"></i>
                        </div>
                        <input type="password" id="password" name="password" 
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none" 
                               required oninput="updatePasswordStrength()">
                        <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center" onclick="togglePassword('password')">
                            <i class="fas fa-eye text-neo-cyan"></i>
                        </button>
                    </div>
                    
                    <div class="mt-4">
                        <div class="bg-gray-800 rounded-full overflow-hidden">
                            <div id="password-strength-bar" class="password-strength bg-red-500 rounded-full" style="width: 0%"></div>
                        </div>
                        <p class="text-xs mt-2 font-exo">Сложность: <span id="password-strength-text" class="text-red-500">Не указан</span></p>
                    </div>
                </div>
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="confirm_password">
                        <i class="fas fa-key mr-2"></i>Подтверждение пароля *
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-neo-cyan"></i>
                        </div>
                        <input type="password" id="confirm_password" name="confirm_password" 
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none" required>
                        <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center" onclick="togglePassword('confirm_password')">
                            <i class="fas fa-eye text-neo-cyan"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex items-center">
                    <input id="agree_terms" name="agree_terms" value="1" type="checkbox" class="cyber-checkbox" required>
                    <label for="agree_terms" class="ml-2 block text-sm text-gray-300 font-exo">
                        Я согласен с <a href="#" class="text-neo-cyan hover:text-neo-blue">условиями использования</a> и 
                        <a href="#" class="text-neo-cyan hover:text-neo-blue">политикой конфиденциальности</a>
                    </label>
                </div>
                
                <div>
                    <button type="submit" class="btn-neo w-full text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center font-exo">
                        <i class="fas fa-user-plus mr-2"></i>
                        <span>СОЗДАТЬ АККАУНТ</span>
                    </button>
                </div>
            </form>
            
            <div class="mt-8 text-center">
                <p class="text-sm text-gray-400 font-exo">
                    Уже есть учетная запись?
                    <a href="login.php" class="text-neo-cyan hover:text-neo-blue font-medium ml-1 transition-colors">
                        <i class="fas fa-sign-in-alt mr-1"></i> Войти в систему
                    </a>
                </p>
            </div>
        </div>
        
        <!-- Футер -->
        <div class="py-4 px-8 text-center border-t border-neo-light">
            <p class="text-xs text-gray-400 font-exo">
                &copy; <?php echo date('Y'); ?> Брянский государственный инженерно-технологический университет
            </p>
        </div>
    </div>

    <script>
        // Создание бинарного дождя
        function createBinaryRain() {
            const container = document.getElementById('binaryRain');
            const digits = '0101010101010101010101010101010101010101010101010101010101010101';
            const fragment = document.createDocumentFragment();
            
            for (let i = 0; i < 50; i++) {
                const digit = document.createElement('div');
                digit.className = 'binary-digit';
                digit.textContent = digits.charAt(Math.floor(Math.random() * digits.length));
                digit.style.left = `${Math.random() * 100}%`;
                digit.style.animationDuration = `${Math.random() * 5 + 5}s`;
                digit.style.animationDelay = `${Math.random() * 5}s`;
                digit.style.opacity = Math.random() * 0.5 + 0.1;
                fragment.appendChild(digit);
            }
            
            container.appendChild(fragment);
        }
        
        // Создание частиц
        function createParticles() {
            const container = document.getElementById('particles');
            const fragment = document.createDocumentFragment();
            
            for (let i = 0; i < 30; i++) {
                const particle = document.createElement('div');
                particle.className = 'particle';
                particle.style.width = `${Math.random() * 5 + 2}px`;
                particle.style.height = particle.style.width;
                particle.style.left = `${Math.random() * 100}%`;
                particle.style.top = `${Math.random() * 100}%`;
                particle.style.animation = `float ${Math.random() * 10 + 5}s ease-in-out infinite`;
                particle.style.animationDelay = `${Math.random() * 5}s`;
                fragment.appendChild(particle);
            }
            
            container.appendChild(fragment);
        }
        
        // Переключение видимости пароля
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const eyeIcon = passwordInput.nextElementSibling.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                eyeIcon.classList.remove('fa-eye');
                eyeIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                eyeIcon.classList.remove('fa-eye-slash');
                eyeIcon.classList.add('fa-eye');
            }
        }
        
        // Проверка сложности пароля
        function checkPasswordStrength(password) {
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[!@#$%^&*(),.?":{}|<>]+/)) strength++;
            
            return strength;
        }
        
        function updatePasswordStrength() {
            const password = document.getElementById('password').value;
            const strength = checkPasswordStrength(password);
            const strengthBar = document.getElementById('password-strength-bar');
            const strengthText = document.getElementById('password-strength-text');
            
            let color, width, text;
            
            switch(strength) {
                case 0:
                case 1:
                    color = 'bg-red-500';
                    width = '25%';
                    text = 'Слабый';
                    break;
                case 2:
                    color = 'bg-orange-500';
                    width = '50%';
                    text = 'Средний';
                    break;
                case 3:
                    color = 'bg-yellow-500';
                    width = '75%';
                    text = 'Хороший';
                    break;
                case 4:
                case 5:
                    color = 'bg-green-500';
                    width = '100%';
                    text = 'Отличный';
                    break;
            }
            
            strengthBar.className = `password-strength ${color}`;
            strengthBar.style.width = width;
            strengthText.textContent = text;
            strengthText.className = `text-xs ${color.replace('bg-', 'text-')}`;
        }
        
        // 3D эффект при движении мыши
        function initTiltEffect() {
            const container = document.querySelector('.cyber-container');
            
            container.addEventListener('mousemove', (e) => {
                const x = e.clientX;
                const y = e.clientY;
                const rect = container.getBoundingClientRect();
                const centerX = rect.left + rect.width / 2;
                const centerY = rect.top + rect.height / 2;
                
                const angleY = (x - centerX) / 20;
                const angleX = (centerY - y) / 20;
                
                container.style.transform = `perspective(1000px) rotateX(${angleX}deg) rotateY(${angleY}deg)`;
            });
            
            container.addEventListener('mouseleave', () => {
                container.style.transform = 'perspective(1000px) rotateX(0) rotateY(0)';
            });
        }
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            createBinaryRain();
            createParticles();
            initTiltEffect();
            
            // Анимация появления формы
            const form = document.querySelector('.cyber-container');
            form.style.opacity = 0;
            form.style.transform = 'perspective(1000px) rotateX(10deg) translateY(50px)';
            form.style.transition = 'opacity 1s ease, transform 1s ease';
            
            setTimeout(() => {
                form.style.opacity = 1;
                form.style.transform = 'perspective(1000px) rotateX(0) translateY(0)';
            }, 300);
            
            // Анимация ошибки
            const errorMessage = document.getElementById('error-message');
            if (errorMessage) {
                setInterval(() => {
                    errorMessage.classList.toggle('error-pulse');
                }, 4000);
            }
        });
    </script>
</body>
</html>