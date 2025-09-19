<?php
session_start();
require_once 'config/database.php';

// Проверяем авторизацию и роль
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// === СТАТИСТИКА ===
$stats = [
    'courses'   => $db->query("SELECT COUNT(*) FROM courses")->fetchColumn(),
    'modules'   => $db->query("SELECT COUNT(*) FROM course_modules")->fetchColumn(),
    'materials' => $db->query("SELECT COUNT(*) FROM module_materials")->fetchColumn(),
    'files'     => $db->query("SELECT COUNT(*) FROM uploaded_files")->fetchColumn(),
    'users'     => $db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
];

// === ПОСЛЕДНИЕ КУРСЫ ===
$courses = $db->query("SELECT id, title, category FROM courses ORDER BY created_at DESC LIMIT 5")
              ->fetchAll(PDO::FETCH_ASSOC);

// === ПОИСК ПОЛЬЗОВАТЕЛЕЙ ===
$users = [];
$search = $_GET['search'] ?? '';
if ($search) {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, role, last_login 
        FROM users 
        WHERE first_name ILIKE :s OR last_name ILIKE :s OR email ILIKE :s OR CAST(id AS TEXT) ILIKE :s
        ORDER BY created_at DESC LIMIT 20
    ");
    $stmt->execute([':s' => "%$search%"]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Админ-панель БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Навигация -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex justify-between h-16 items-center">
            <div class="flex items-center">
                <i data-feather="settings" class="h-7 w-7 text-green-700"></i>
                <span class="ml-2 text-xl font-bold">Админ-панель</span>
            </div>
            <div>
                <a href="index.php" class="mr-4 text-gray-700 hover:text-green-700">На сайт</a>
                <a href="logout.php" class="text-gray-700 hover:text-red-600">Выйти</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 px-4">
        <!-- СТАТИСТИКА -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <?php
            $cards = [
                ['Курсы', $stats['courses'], 'book', 'green'],
                ['Модули', $stats['modules'], 'layers', 'blue'],
                ['Материалы', $stats['materials'], 'file-text', 'yellow'],
                ['Файлы', $stats['files'], 'hard-drive', 'red'],
                ['Пользователи', $stats['users'], 'users', 'purple']
            ];
            foreach ($cards as [$label, $value, $icon, $color]) {
                echo "
                <div class='bg-white shadow rounded-lg p-5 flex items-center'>
                    <div class='p-3 rounded-md bg-{$color}-500'>
                        <i data-feather='{$icon}' class='text-white h-6 w-6'></i>
                    </div>
                    <div class='ml-4'>
                        <p class='text-sm text-gray-500'>{$label}</p>
                        <p class='text-lg font-semibold'>{$value}</p>
                    </div>
                </div>";
            }
            ?>
        </div>

        <!-- БЫСТРЫЕ ДЕЙСТВИЯ -->
        <div class="bg-white shadow rounded-lg p-6 mb-8">
            <h2 class="text-xl font-semibold mb-4">Быстрые действия</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="admin_add_course.php" class="flex items-center p-4 bg-green-50 rounded-lg hover:bg-green-100">
                    <i data-feather="plus-circle" class="h-6 w-6 text-green-600 mr-3"></i>
                    <span>Добавить курс</span>
                </a>
                <a href="admin_add_module.php" class="flex items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100">
                    <i data-feather="layers" class="h-6 w-6 text-blue-600 mr-3"></i>
                    <span>Добавить модуль</span>
                </a>
                <a href="admin_add_material.php" class="flex items-center p-4 bg-yellow-50 rounded-lg hover:bg-yellow-100">
                    <i data-feather="file-plus" class="h-6 w-6 text-yellow-600 mr-3"></i>
                    <span>Добавить материал</span>
                </a>
                <a href="admin_upload_file.php" class="flex items-center p-4 bg-red-50 rounded-lg hover:bg-red-100">
                    <i data-feather="upload" class="h-6 w-6 text-red-600 mr-3"></i>
                    <span>Загрузить файл</span>
                </a>
            </div>
        </div>

<!-- УПРАВЛЕНИЕ ПОЛЬЗОВАТЕЛЯМИ -->
<div class="bg-white shadow rounded-lg p-6 mb-8">
    <h2 class="text-xl font-semibold mb-4">Управление пользователями</h2>
    <form method="get" class="mb-4 flex">
        <input type="text" name="search" value="<?=htmlspecialchars($search)?>" placeholder="Поиск по имени, email..." 
               class="flex-1 border px-3 py-2 rounded-l-md focus:outline-none">
        <button class="bg-green-600 text-white px-4 rounded-r-md">Поиск</button>
    </form>

    <?php
    // Обработка смены роли
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'], $_POST['role'])) {
        $uid = (int) $_POST['user_id'];
        $newRole = $_POST['role'];
        $allowedRoles = ['student', 'teacher', 'admin'];
        if (in_array($newRole, $allowedRoles)) {
            $stmt = $db->prepare("UPDATE users SET role = :role WHERE id = :id");
            $stmt->execute([':role' => $newRole, ':id' => $uid]);
            echo "<div class='mb-4 p-3 bg-green-100 text-green-700 rounded'>Роль пользователя обновлена</div>";
        }
    }
    ?>

    <?php if ($search && $users): ?>
        <table class="min-w-full border text-sm">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-3 py-2 border">ID</th>
                    <th class="px-3 py-2 border">Имя</th>
                    <th class="px-3 py-2 border">Email</th>
                    <th class="px-3 py-2 border">Роль</th>
                    <th class="px-3 py-2 border">Последний вход</th>
                    <th class="px-3 py-2 border">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-3 py-2 border"><?=htmlspecialchars($u['id'])?></td>
                    <td class="px-3 py-2 border"><?=htmlspecialchars($u['name'])?></td>
                    <td class="px-3 py-2 border"><?=htmlspecialchars($u['email'])?></td>
                    <td class="px-3 py-2 border">
                        <form method="post" class="flex items-center space-x-2">
                            <input type="hidden" name="user_id" value="<?=$u['id']?>">
                            <select name="role" class="border rounded px-2 py-1 text-sm">
                                <option value="student" <?=$u['role']==='student'?'selected':''?>>Студент</option>
                                <option value="teacher" <?=$u['role']==='teacher'?'selected':''?>>Преподаватель</option>
                                <option value="admin" <?=$u['role']==='admin'?'selected':''?>>Админ</option>
                            </select>
                            <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-xs hover:bg-blue-700">Сохранить</button>
                        </form>
                    </td>
                    <td class="px-3 py-2 border"><?=htmlspecialchars($u['last_login'])?></td>
                    <td class="px-3 py-2 border">
                        <a href="admin_delete_user.php?id=<?=$u['id']?>" 
                           onclick="return confirm('Удалить пользователя?')" 
                           class="text-red-600 hover:underline text-sm">Удалить</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php elseif ($search): ?>
        <p class="text-gray-500">Пользователи не найдены</p>
    <?php endif; ?>
</div>


        <!-- ПОСЛЕДНИЕ КУРСЫ -->
        <div class="bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-semibold mb-4">Последние курсы</h2>
            <?php if ($courses): ?>
                <ul class="divide-y">
                    <?php foreach ($courses as $c): ?>
                        <li class="py-3 flex justify-between items-center">
                            <div>
                                <p class="font-medium"><?=htmlspecialchars($c['title'])?></p>
                                <p class="text-sm text-gray-500"><?=htmlspecialchars($c['category'])?></p>
                            </div>
                            <div>
                                <a href="admin_edit_course.php?id=<?=$c['id']?>" class="text-blue-600 mr-3">Редактировать</a>
                                <a href="admin_manage_course.php?id=<?=$c['id']?>" class="text-green-600">Управление</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-gray-500">Курсы не найдены.</p>
            <?php endif; ?>
        </div>
    </div>
    <script>feather.replace();</script>
</body>
</html>