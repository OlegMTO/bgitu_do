<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

// Если пользователь уже авторизован — перенаправляем по его роли
if (isset($_SESSION['user_id']) && isset($_SESSION['user_role'])) {
    switch ($_SESSION['user_role']) {
        case 'admin':
            header('Location: admin_dashboard.php');
            break;
        case 'teacher':
            header('Location: teacher_dashboard.php');
            break;
        default:
            header('Location: profile.php');
            break;
    }
    exit;
}

$error = '';
$email = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Ошибка безопасности. Попробуйте еще раз.';
    } else {
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']);

        if (empty($email) || empty($password)) {
            $error = 'Заполните все поля.';
        } elseif (!isValidEmail($email)) {
            $error = 'Введите корректный email.';
        } else {
            $database = new Database();
            $db = $database->getConnection();

            try {
                // Берем и роль тоже
                $query = "SELECT id, email, password_hash, first_name, last_name, role 
                          FROM users WHERE email = :email LIMIT 1";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && verifyPassword($password, $user['password_hash'])) {
                    // Сохраняем данные в сессию
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                    $_SESSION['user_role'] = $user['role'];

                    // Логиним
                    logSecurityEvent('login_success', $user['id']);

                    // Обновляем last_login
                    $q = "UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id";
                    $st = $db->prepare($q);
                    $st->bindParam(':id', $user['id']);
                    $st->execute();

                    // Запомнить меня
                    if ($remember) {
                        $token = generateToken();
                        $expires = time() + 60 * 60 * 24 * 30;
                        setcookie('remember_token', $token, $expires, '/', '', true, true);

                        try {
                            $q = "INSERT INTO auth_tokens (user_id, token, expires_at) 
                                  VALUES (:user_id, :token, :expires_at)";
                            $st = $db->prepare($q);
                            $st->bindParam(':user_id', $user['id']);
                            $st->bindParam(':token', $token);
                            $st->bindParam(':expires_at', date('Y-m-d H:i:s', $expires));
                            $st->execute();
                        } catch (PDOException $e) {
                            error_log("Ошибка сохранения токена: " . $e->getMessage());
                        }
                    }

                    // Перенаправляем по роли
                    switch ($user['role']) {
                        case 'admin':
                            header('Location: admin_dashboard.php');
                            break;
                        case 'teacher':
                            header('Location: teacher_dashboard.php');
                            break;
                        default:
                            header('Location: profile.php');
                            break;
                    }
                    exit;
                } else {
                    $error = 'Неверный email или пароль.';
                    logSecurityEvent('login_failed', null, ['email' => $email]);
                }
            } catch (PDOException $e) {
                $error = 'Ошибка базы данных.';
                error_log("DB Error: " . $e->getMessage());
            }
        }
    }
}

// CSRF-токен
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему - БГИТУ | Инновационные технологии</title>
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
        
        @keyframes pulse-glow {
            0% {
                box-shadow: 0 0 5px rgba(0, 198, 255, 0.5);
            }
            100% {
                box-shadow: 0 0 20px rgba(0, 198, 255, 0.8), 
                            0 0 30px rgba(0, 114, 255, 0.5);
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
            perspective: 1000px;
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
        
        .cyber-globe::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: transparent;
            border: 1px solid rgba(0, 198, 255, 0.2);
            transform: rotateX(80deg);
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
    <div class="cyber-container cyber-border w-full max-w-md">
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
            <p class="text-neo-cyan text-sm mb-4 relative z-10 font-exo">Инновации • Технологии • Будущее</p>
            
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
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="email">
                        <i class="fas fa-user-astronaut mr-2"></i>Логин
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-at text-neo-cyan"></i>
                        </div>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($email); ?>"
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none"
                               placeholder="user@bgitu.ru" required>
                    </div>
                </div>
                
                <div class="holographic-card p-4">
                    <label class="block text-neo-cyan text-sm font-bold mb-2 font-exo" for="password">
                        <i class="fas fa-key mr-2"></i>Пароль
                    </label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-lock text-neo-cyan"></i>
                        </div>
                        <input type="password" id="password" name="password"
                               class="input-neo py-3 px-4 pl-10 rounded-lg w-full focus:outline-none"
                               placeholder="Пароль" required>
                        <button type="button" class="absolute inset-y-0 right-0 pr-3 flex items-center" onclick="togglePassword()">
                            <i class="fas fa-eye text-neo-cyan"></i>
                        </button>
                    </div>
                </div>
                
                <div class="flex items-center">
                    <input id="remember" name="remember" value="1" type="checkbox" class="cyber-checkbox">
                    <label for="remember" class="ml-2 block text-sm text-gray-300 font-exo">
                        Запомнить мою сессию
                    </label>
                </div>
                
                <div>
                    <button type="submit" class="btn-neo w-full text-white font-bold py-3 px-4 rounded-lg flex items-center justify-center font-exo">
                        <i class="fas fa-sign-in-alt mr-2"></i>
                        <span>ПОДКЛЮЧИТЬСЯ К СИСТЕМЕ</span>
                    </button>
                </div>
            </form>
            
            <div class="mt-8 text-center">
                <p class="text-sm text-gray-400 font-exo">
                    Нет учетной записи?
                    <a href="register.php" class="text-neo-cyan hover:text-neo-blue font-medium ml-1 transition-colors">
                        <i class="fas fa-user-plus mr-1"></i> Активировать доступ
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
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.querySelector('#password + button i');
            
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