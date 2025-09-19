<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

include_once 'config/database.php';
include_once 'config/file_functions.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';
$course = null;

// Получение ID курса для редактирования
$course_id = $_GET['id'] ?? 0;

// Загрузка данных курса
if ($course_id) {
    try {
        $query = "SELECT * FROM courses WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $course_id);
        $stmt->execute();
        $course = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        $error = 'Ошибка загрузки данных курса: ' . $exception->getMessage();
    }
}

// Если курс не найден
if (!$course) {
    $error = 'Курс не найден';
    $course_id = 0;
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $course) {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $category = $_POST['category'] ?? '';
    $duration_hours = $_POST['duration_hours'] ?? 0;
    $price = $_POST['price'] ?? 0;
    
    // Валидация данных
    if (empty($title) || empty($description) || empty($category)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } else {
        try {
            // Обработка загрузки нового изображения (если предоставлено)
            $image_url = $course['image_url'];
            if (!empty($_FILES['image']['name'])) {
                $imageUpload = handleFileUpload($_FILES['image'], ['jpg', 'jpeg', 'png', 'gif']);
                if ($imageUpload['success']) {
                    // Удаляем старое изображение, если оно есть
                    if (!empty($image_url) && file_exists($image_url)) {
                        unlink($image_url);
                    }
                    $image_url = $imageUpload['file_path'];
                    saveFileToDatabase($db, $imageUpload);
                } else {
                    $error = $imageUpload['error'];
                }
            }
            
            // Обработка удаления текущего изображения
            if (isset($_POST['remove_image']) && $_POST['remove_image'] == '1') {
                if (!empty($image_url) && file_exists($image_url)) {
                    unlink($image_url);
                }
                $image_url = '';
            }
            
            if (!$error) {
                // Подготовка SQL запроса
                $query = "UPDATE courses SET title = :title, description = :description, category = :category, 
                          image_url = :image_url, duration_hours = :duration_hours, price = :price,
                          updated_at = CURRENT_TIMESTAMP WHERE id = :id";
                $stmt = $db->prepare($query);
                
                // Привязка параметров
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':category', $category);
                $stmt->bindParam(':image_url', $image_url);
                $stmt->bindParam(':duration_hours', $duration_hours);
                $stmt->bindParam(':price', $price);
                $stmt->bindParam(':id', $course_id);
                
                // Выполнение запроса
                if ($stmt->execute()) {
                    $success = 'Курс успешно обновлен!';
                    // Обновляем данные курса для отображения
                    $course['title'] = $title;
                    $course['description'] = $description;
                    $course['category'] = $category;
                    $course['image_url'] = $image_url;
                    $course['duration_hours'] = $duration_hours;
                    $course['price'] = $price;
                } else {
                    $error = 'Ошибка при обновлении курса';
                }
            }
        } catch (PDOException $exception) {
            $error = 'Ошибка базы данных: ' . $exception->getMessage();
        }
    }
}

// Загрузка модулей курса
$modules = [];
if ($course_id) {
    try {
        $query = "SELECT * FROM course_modules WHERE course_id = :course_id ORDER BY order_index, title";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':course_id', $course_id);
        $stmt->execute();
        $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        $error = 'Ошибка загрузки модулей: ' . $exception->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактирование курса - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        function previewImage(event) {
            const reader = new FileReader();
            reader.onload = function() {
                const output = document.getElementById('imagePreview');
                output.src = reader.result;
                output.classList.remove('hidden');
                // Скрываем текущее изображение, если загружается новое
                const currentImage = document.getElementById('currentImage');
                if (currentImage) {
                    currentImage.classList.add('hidden');
                }
                // Показываем кнопку удаления
                document.getElementById('removeImageContainer').classList.remove('hidden');
            }
            reader.readAsDataURL(event.target.files[0]);
        }
        
        function removeCurrentImage() {
            document.getElementById('removeImage').value = '1';
            document.getElementById('currentImage').classList.add('hidden');
            document.getElementById('imagePreview').classList.add('hidden');
            document.getElementById('removeImageContainer').classList.add('hidden');
            document.getElementById('image').value = '';
        }
    </script>
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
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-6xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
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
        
        <?php if ($course): ?>
        <div class="bg-white shadow rounded-lg overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Редактирование курса</h2>
                <p class="text-gray-600">ID: <?php echo $course['id']; ?></p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="px-6 py-4">
                <input type="hidden" id="removeImage" name="remove_image" value="0">
                
                <div class="grid grid-cols-1 gap-6 mt-4">
                    <!-- Название курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название курса *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($course['title']); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Описание курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">Описание курса *</label>
                        <textarea name="description" id="description" rows="4" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required><?php echo htmlspecialchars($course['description']); ?></textarea>
                    </div>
                    
                    <!-- Категория -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="category">Категория *</label>
                        <select name="category" id="category" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                            <option value="marketing" <?php echo $course['category'] === 'marketing' ? 'selected' : ''; ?>>Маркетинг</option>
                            <option value="engineering" <?php echo $course['category'] === 'engineering' ? 'selected' : ''; ?>>Инженерия</option>
                            <option value="science" <?php echo $course['category'] === 'science' ? 'selected' : ''; ?>>Лабораторное дело</option>
                            <option value="it" <?php echo $course['category'] === 'it' ? 'selected' : ''; ?>>IT технологии</option>
                        </select>
                    </div>
                    
                    <!-- Изображение курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="image">Изображение курса</label>
                        
                        <?php if (!empty($course['image_url'])): ?>
                        <div class="mb-4">
                            <p class="text-sm text-gray-600 mb-2">Текущее изображение:</p>
                            <img id="currentImage" src="<?php echo $course['image_url']; ?>" class="max-w-xs rounded shadow-md">
                        </div>
                        <?php endif; ?>
                        
                        <input type="file" name="image" id="image" accept="image/*" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" onchange="previewImage(event)">
                        <div class="mt-2">
                            <img id="imagePreview" class="hidden max-w-xs rounded shadow-md">
                        </div>
                        
                        <?php if (!empty($course['image_url'])): ?>
                        <div id="removeImageContainer" class="mt-2">
                            <button type="button" onclick="removeCurrentImage()" class="text-red-600 text-sm hover:text-red-800 flex items-center">
                                <i data-feather="trash-2" class="h-4 w-4 mr-1"></i> Удалить текущее изображение
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Продолжительность и цена -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="duration_hours">Продолжительность (часов)</label>
                            <input type="number" name="duration_hours" id="duration_hours" min="0" 
                                   value="<?php echo htmlspecialchars($course['duration_hours']); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="price">Цена (руб.)</label>
                            <input type="number" name="price" id="price" min="0" step="0.01" 
                                   value="<?php echo htmlspecialchars($course['price']); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button type="submit" class="px-6 py-2 leading-5 text-white bg-green-600 rounded-md hover:bg-green-500 focus:outline-none focus:bg-green-700">
                        Сохранить изменения
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Управление модулями курса -->
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h2 class="text-2xl font-bold text-gray-800">Модули курса</h2>
                <a href="admin_add_module.php?course_id=<?php echo $course_id; ?>" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 flex items-center">
                    <i data-feather="plus" class="h-4 w-4 mr-1"></i> Добавить модуль
                </a>
            </div>
            
            <div class="px-6 py-4">
                <?php if (count($modules) > 0): ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Название</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Порядок</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Дата создания</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($modules as $module): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($module['title']); ?></div>
                                    <?php if (!empty($module['description'])): ?>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars(substr($module['description'], 0, 100)); ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $module['order_index']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d.m.Y H:i', strtotime($module['created_at'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="admin_edit_module.php?id=<?php echo $module['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">Редактировать</a>
                                    <a href="admin_manage_module.php?id=<?php echo $module['id']; ?>" class="text-green-600 hover:text-green-900 mr-3">Материалы</a>
                                    <a href="admin_delete_module.php?id=<?php echo $module['id']; ?>" class="text-red-600 hover:text-red-900" onclick="return confirm('Вы уверены, что хотите удалить этот модуль? Все материалы модуля также будут удалены.')">Удалить</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-8">
                    <i data-feather="folder" class="h-12 w-12 text-gray-400 mx-auto"></i>
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
        </div>
        <?php else: ?>
        <div class="bg-white shadow rounded-lg overflow-hidden p-6 text-center">
            <i data-feather="alert-circle" class="h-12 w-12 text-red-500 mx-auto"></i>
            <h3 class="mt-4 text-lg font-medium text-gray-900">Курс не найден</h3>
            <p class="mt-1 text-gray-500">Запрошенный курс не существует или был удален.</p>
            <div class="mt-6">
                <a href="admin_manage_courses.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700">
                    Вернуться к списку курсов
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        feather.replace();
        <?php if (empty($course['image_url'])): ?>
        // Скрываем контейнер удаления изображения, если изображения нет
        document.getElementById('removeImageContainer').classList.add('hidden');
        <?php endif; ?>
    </script>
</body>
</html>