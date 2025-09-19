<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$course_id = $_GET['id'] ?? 0;
$course = null;
$modules = [];

// Загрузка информации о курсе
if ($course_id) {
    try {
        $query = "SELECT * FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $course_id);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($course) {
            // Загрузка модулей курса
            $query = "SELECT * FROM course_modules WHERE course_id = :course_id ORDER BY order_index, title";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':course_id', $course_id);
            $stmt->execute();
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $exception) {
        $error = 'Ошибка загрузки данных: ' . $exception->getMessage();
    }
}

// Если курс не найден
if (!$course) {
    header('Location: admin_manage_courses.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление модулями - БГИТУ</title>
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
                    <a href="admin_manage_courses.php" class="text-gray-700 hover:text-green-700 mr-4">Все курсы</a>
                    <a href="admin_edit_course.php?id=<?php echo $course_id; ?>" class="text-gray-700 hover:text-green-700 mr-4">Редактировать курс</a>
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Управление модулями курса</h2>
                <p class="text-gray-600">Курс: <?php echo htmlspecialchars($course['title']); ?></p>
            </div>
            
            <div class="px-6 py-4 flex justify-between items-center">
                <p class="text-gray-600">Всего модулей: <?php echo count($modules); ?></p>
                <a href="admin_add_module.php?course_id=<?php echo $course_id; ?>" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center">
                    <i data-feather="plus" class="h-4 w-4 mr-1"></i> Добавить модуль
                </a>
            </div>
        </div>
        
        <?php if (count($modules) > 0): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($modules as $module): ?>
            <div class="bg-white shadow rounded-lg overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($module['title']); ?></h3>
                    <?php if (!empty($module['description'])): ?>
                    <p class="text-gray-600 text-sm mt-1"><?php echo htmlspecialchars(substr($module['description'], 0, 100)); ?>...</p>
                    <?php endif; ?>
                </div>
                
                <div class="px-6 py-4">
                    <div class="flex justify-between items-center mb-4">
                        <span class="text-sm text-gray-500">Порядок: <?php echo $module['order_index']; ?></span>
                        <span class="text-sm text-gray-500"><?php echo date('d.m.Y', strtotime($module['created_at'])); ?></span>
                    </div>
                    
                    <div class="flex justify-between">
                        <a href="admin_edit_module.php?id=<?php echo $module['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm">Редактировать</a>
                        <a href="admin_manage_module.php?id=<?php echo $module['id']; ?>" class="text-green-600 hover:text-green-800 text-sm">Материалы</a>
                        <a href="admin_delete_module.php?id=<?php echo $module['id']; ?>" class="text-red-600 hover:text-red-800 text-sm" onclick="return confirm('Вы уверены, что хотите удалить этот модуль? Все материалы модуля также будут удалены.')">Удалить</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden p-6 text-center">
            <i data-feather="layers" class="h-12 w-12 text-gray-400 mx-auto"></i>
            <h3 class="mt-4 text-lg font-medium text-gray-900">Модули не найдены</h3>
            <p class="mt-1 text-gray-500">Добавьте модули для организации материалов курса.</p>
            <div class="mt-6">
                <a href="admin_add_module.php?course_id=<?php echo $course_id; ?>" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                    <i data-feather="plus" class="h-4 w-4 mr-2"></i> Добавить модуль
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>