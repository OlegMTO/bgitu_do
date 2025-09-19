<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Загрузка списка курсов
$courses = [];
try {
    $query = "SELECT * FROM courses ORDER BY created_at DESC";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    $error = 'Ошибка загрузки курсов: ' . $exception->getMessage();
}

// Обработка удаления курса
if (isset($_GET['delete_id'])) {
    $delete_id = $_GET['delete_id'];
    try {
        // Начинаем транзакцию для безопасного удаления
        $db->beginTransaction();
        
        // Удаляем материалы модулей
        $query = "DELETE FROM module_materials WHERE module_id IN (SELECT id FROM course_modules WHERE course_id = :course_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $delete_id);
        $stmt->execute();
        
        // Удаляем модули
        $query = "DELETE FROM course_modules WHERE course_id = :course_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $delete_id);
        $stmt->execute();
        
        // Удаляем курс
        $query = "DELETE FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $delete_id);
        $stmt->execute();
        
        $db->commit();
        $_SESSION['success'] = 'Курс успешно удален';
        header('Location: admin_manage_courses.php');
        exit;
    } catch (PDOException $exception) {
        $db->rollBack();
        $error = 'Ошибка удаления курса: ' . $exception->getMessage();
    }
}

// Проверка сообщений об успехе
$success = '';
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление курсами - БГИТУ</title>
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
                    <a href="admin_add_course.php" class="text-gray-700 hover:text-green-700 mr-4">Добавить курс</a>
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?php echo $error; ?></span>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
            <span class="block sm:inline"><?php echo $success; ?></span>
        </div>
        <?php endif; ?>
        
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Управление курсами</h2>
                <p class="text-gray-600">Всего курсов: <?php echo count($courses); ?></p>
            </div>
            
            <div class="px-6 py-4">
                <?php if (count($courses) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Категория</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Цена</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата создания</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($courses as $course): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <?php if (!empty($course['image_url'])): ?>
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <img class="h-10 w-10 rounded-full object-cover" src="<?php echo $course['image_url']; ?>" alt="<?php echo htmlspecialchars($course['title']); ?>">
                                        </div>
                                        <?php endif; ?>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($course['title']); ?></div>
                                            <div class="text-sm text-gray-500"><?php echo $course['duration_hours']; ?> часов</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php 
                                    $categories = [
                                        'marketing' => 'Маркетинг',
                                        'engineering' => 'Инженерия',
                                        'science' => 'Лабораторное дело',
                                        'it' => 'IT технологии'
                                    ];
                                    echo $categories[$course['category']] ?? $course['category'];
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo number_format($course['price'], 2, '.', ' '); ?> руб.
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d.m.Y', strtotime($course['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="admin_edit_course.php?id=<?php echo $course['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">Редактировать</a>
                                    <a href="admin_manage_course.php?id=<?php echo $course['id']; ?>" class="text-green-600 hover:text-green-900 mr-3">Модули</a>
                                    <a href="admin_manage_courses.php?delete_id=<?php echo $course['id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Вы уверены, что хотите удалить этот курс? Все модули и материалы также будут удалены.')">Удалить</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <i data-feather="book" class="h-12 w-12 text-gray-400 mx-auto"></i>
                    <h3 class="mt-4 text-lg font-medium text-gray-900">Курсы не найдены</h3>
                    <p class="mt-1 text-gray-500">Начните с добавления первого курса.</p>
                    <div class="mt-6">
                        <a href="admin_add_course.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            <i data-feather="plus" class="h-4 w-4 mr-2"></i> Добавить курс
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>