<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Получаем ID курса
$course_id = $_GET['id'] ?? 0;

// Загрузка данных курса
$course = [];
$modules = [];
$isEnrolled = false;

if ($course_id) {
    try {
        // Загружаем информацию о курсе
        $query = "SELECT * FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $course_id);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($course) {
            // Загружаем модули курса
            $query = "SELECT * FROM course_modules WHERE course_id = :course_id ORDER BY order_index";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id);
            $stmt->execute();
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Проверяем, записан ли пользователь на курс
            if (isset($_SESSION['user_id'])) {
                $query = "SELECT id FROM enrollments WHERE user_id = :user_id AND course_id = :course_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->bindParam(':course_id', $course_id);
                $stmt->execute();
                $isEnrolled = $stmt->fetch() !== false;
            }
        }
    } catch (PDOException $exception) {
        error_log("Ошибка загрузки курса: " . $exception->getMessage());
    }
}

// Если курс не найден
if (!$course) {
    header('Location: courses.php');
    exit;
}

// Обработка записи на курс
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enroll']) && isset($_SESSION['user_id'])) {
    try {
        $query = "INSERT INTO enrollments (user_id, course_id) VALUES (:user_id, :course_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->execute();
        
        $isEnrolled = true;
        header("Location: course.php?id=$course_id&enrolled=1");
        exit;
    } catch (PDOException $exception) {
        error_log("Ошибка записи на курс: " . $exception->getMessage());
    }
}

// Функция для получения названия категории
function getCategoryName($category) {
    $categories = [
        'marketing' => 'Маркетинг',
        'engineering' => 'Инженерия',
        'science' => 'Лабораторное дело',
        'it' => 'IT технологии'
    ];
    return $categories[$category] ?? $category;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($course['title']); ?> - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Навигация -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="flex-shrink-0 flex items-center">
                        <i data-feather="book-open" class="h-8 w-8 text-green-700"></i>
                        <span class="ml-2 text-xl font-bold text-gray-900">БГИТУ</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">Главная</a>
                    <a href="courses.php" class="text-gray-700 hover:text-green-700 mr-4">Курсы</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="profile.php" class="text-gray-700 hover:text-green-700 mr-4">Профиль</a>
                        <a href="logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                    <?php else: ?>
                        <a href="login.php" class="text-gray-700 hover:text-green-700 mr-4">Войти</a>
                        <a href="register.php" class="text-gray-700 hover:text-green-700">Регистрация</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if (isset($_GET['enrolled']) && $_GET['enrolled'] == 1): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline">Вы успешно записаны на курс!</span>
        </div>
        <?php endif; ?>
        
        <div class="bg-white shadow rounded-lg overflow-hidden mb-8">
            <div class="relative">
                <img class="w-full h-64 object-cover" src="<?php echo htmlspecialchars($course['image_url']); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>">
                <div class="absolute inset-0 bg-black bg-opacity-40 flex items-center justify-center">
                    <h1 class="text-4xl font-bold text-white text-center"><?php echo htmlspecialchars($course['title']); ?></h1>
                </div>
            </div>
            
            <div class="px-6 py-4">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center">
                    <div>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                            <?php echo getCategoryName($course['category']); ?>
                        </span>
                        <p class="mt-2 text-gray-600"><?php echo $course['duration_hours']; ?> часов обучения</p>
                    </div>
                    
                    <div class="mt-4 md:mt-0">
                        <span class="text-3xl font-bold text-green-600"><?php echo number_format($course['price'], 0, ',', ' '); ?> руб.</span>
                    </div>
                </div>
                
                <div class="mt-6">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($isEnrolled): ?>
                            <a href="learning.php?course_id=<?php echo $course_id; ?>" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
                                Перейти к обучению
                            </a>
                        <?php else: ?>
                            <form method="POST">
                                <button type="submit" name="enroll" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700">
                                    Записаться на курс
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="space-y-3">
                            <p class="text-gray-600">Для записи на курс необходимо войти в систему</p>
                            <div class="space-x-4">
                                <a href="login.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                    Войти
                                </a>
                                <a href="register.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Зарегистрироваться
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-2">
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-800">О курсе</h2>
                    </div>
                    <div class="px-6 py-4">
                        <p class="text-gray-700"><?php echo nl2br(htmlspecialchars($course['description'])); ?></p>
                    </div>
                </div>
                
                <?php if (count($modules) > 0): ?>
                <div class="bg-white shadow rounded-lg overflow-hidden mt-8">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-800">Программа курса</h2>
                        <p class="text-gray-600"><?php echo count($modules); ?> модулей</p>
                    </div>
                    <div class="px-6 py-4">
                        <div class="space-y-4">
                            <?php foreach ($modules as $index => $module): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-green-100 flex items-center justify-center">
                                        <span class="text-green-800 font-bold"><?php echo $index + 1; ?></span>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($module['title']); ?></h3>
                                        <?php if (!empty($module['description'])): ?>
                                        <p class="mt-1 text-gray-600"><?php echo htmlspecialchars($module['description']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div>
                <div class="bg-white shadow rounded-lg overflow-hidden sticky top-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-xl font-bold text-gray-800">Детали курса</h2>
                    </div>
                    <div class="px-6 py-4">
                        <div class="space-y-4">
                            <div class="flex items-center">
                                <i data-feather="clock" class="h-5 w-5 text-gray-500"></i>
                                <span class="ml-3 text-gray-700">Продолжительность: <strong><?php echo $course['duration_hours']; ?> часов</strong></span>
                            </div>
                            
                            <div class="flex items-center">
                                <i data-feather="book" class="h-5 w-5 text-gray-500"></i>
                                <span class="ml-3 text-gray-700">Модулей: <strong><?php echo count($modules); ?></strong></span>
                            </div>
                            
                            <div class="flex items-center">
                                <i data-feather="bar-chart" class="h-5 w-5 text-gray-500"></i>
                                <span class="ml-3 text-gray-700">Уровень: <strong>Для начинающих</strong></span>
                            </div>
                            
                            <div class="flex items-center">
                                <i data-feather="award" class="h-5 w-5 text-gray-500"></i>
                                <span class="ml-3 text-gray-700">Сертификат: <strong>Да</strong></span>
                            </div>
                        </div>
                        
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <div class="flex items-center justify-between">
                                <span class="text-2xl font-bold text-green-600"><?php echo number_format($course['price'], 0, ',', ' '); ?> руб.</span>
                                <?php if (isset($_SESSION['user_id']) && !$isEnrolled): ?>
                                <form method="POST">
                                    <button type="submit" name="enroll" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                                        Записаться
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                            
                            <?php if (!isset($_SESSION['user_id'])): ?>
                            <div class="mt-4">
                                <p class="text-sm text-gray-600">Для записи на курс необходимо войти в систему</p>
                                <div class="mt-2 space-y-2">
                                    <a href="login.php" class="block text-center bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 transition duration-300">
                                        Войти
                                    </a>
                                    <a href="register.php" class="block text-center border border-green-600 text-green-600 py-2 px-4 rounded-md hover:bg-green-50 transition duration-300">
                                        Зарегистрироваться
                                    </a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>