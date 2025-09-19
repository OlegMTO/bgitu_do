<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$course_id = $_GET['course_id'] ?? 0;
$error = '';
$success = '';

// Загрузка информации о курсе
$course = [];
if ($course_id) {
    try {
        $query = "SELECT * FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $course_id);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        $error = 'Ошибка загрузки курса: ' . $exception->getMessage();
    }
}

// Если курс не найден
if (!$course) {
    $error = 'Курс не найден';
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $course) {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $order_index = $_POST['order_index'] ?? 0;
    
    // Валидация данных
    if (empty($title)) {
        $error = 'Пожалуйста, введите название модуля';
    } else {
        try {
            // Подготовка SQL запроса
            $query = "INSERT INTO course_modules (course_id, title, description, order_index) 
                      VALUES (:course_id, :title, :description, :order_index)";
            $stmt = $db->prepare($query);
            
            // Привязка параметров
            $stmt->bindParam(':course_id', $course_id);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':order_index', $order_index);
            
            // Выполнение запроса
            if ($stmt->execute()) {
                $module_id = $db->lastInsertId();
                $success = 'Модуль успешно добавлен!';
                
                // Очистка полей формы
                $_POST['title'] = '';
                $_POST['description'] = '';
                
                // Перенаправляем на страницу добавления материалов
                header("Location: admin_add_material.php?module_id=" . $module_id);
                exit;
            } else {
                $error = 'Ошибка при добавлении модуля';
            }
        } catch (PDOException $exception) {
            $error = 'Ошибка базы данных: ' . $exception->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавление модуля - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="flex-shrink-0 flex items-center">
                        <i data-feather="book-open" class="h-8 w-8 text-green-700"></i>
                        <span class="ml-2 text-xl font-bold text-gray-900">БГИТУ Админ</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <a href="admin_dashboard.php" class="text-gray-700 hover:text-green-700 mr-4">Главная</a>
                    <a href="admin_manage_courses.php" class="text-gray-700 hover:text-green-700 mr-4">Курсы</a>
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Добавление модуля к курсу</h2>
                <p class="text-gray-600">Курс: <?php echo htmlspecialchars($course['title'] ?? 'Неизвестный курс'); ?></p>
            </div>
            
            <?php if ($error): ?>
            <div class="mx-6 mt-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?php echo $error; ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="mx-6 mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                <span class="block sm:inline"><?php echo $success; ?></span>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="px-6 py-4">
                <div class="grid grid-cols-1 gap-6 mt-4">
                    <!-- Название модуля -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название модуля *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Описание модуля -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">Описание модуля</label>
                        <textarea name="description" id="description" rows="4" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Порядковый номер -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="order_index">Порядковый номер</label>
                        <input type="number" name="order_index" id="order_index" min="0" 
                               value="<?php echo htmlspecialchars($_POST['order_index'] ?? '0'); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                    </div>
                </div>
                
                <div class="flex justify-between mt-6">
                    <a href="admin_add_course.php" class="px-6 py-2 leading-5 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 focus:outline-none">
                        Назад к курсам
                    </a>
                    <button type="submit" class="px-6 py-2 leading-5 text-white bg-green-600 rounded-md hover:bg-green-500 focus:outline-none focus:bg-green-700">
                        Добавить модуль и перейти к материалам
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>