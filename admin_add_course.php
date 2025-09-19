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

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            // Обработка загрузки изображения
            $image_url = '';
            if (!empty($_FILES['image']['name'])) {
                $imageUpload = handleFileUpload($_FILES['image'], ['jpg', 'jpeg', 'png', 'gif']);
                if ($imageUpload['success']) {
                    $image_url = $imageUpload['file_path'];
                    saveFileToDatabase($db, $imageUpload);
                } else {
                    $error = $imageUpload['error'];
                }
            }
            
            if (!$error) {
                // Подготовка SQL запроса
                $query = "INSERT INTO courses (title, description, category, image_url, duration_hours, price) 
                          VALUES (:title, :description, :category, :image_url, :duration_hours, :price)";
                $stmt = $db->prepare($query);
                
                // Привязка параметров
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':category', $category);
                $stmt->bindParam(':image_url', $image_url);
                $stmt->bindParam(':duration_hours', $duration_hours);
                $stmt->bindParam(':price', $price);
                
                // Выполнение запроса
                if ($stmt->execute()) {
                    $course_id = $db->lastInsertId();
                    $success = 'Курс успешно добавлен!';
                    
                    // Перенаправляем на страницу управления модулями курса
                    header("Location: admin_add_module.php?course_id=" . $course_id);
                    exit;
                } else {
                    $error = 'Ошибка при добавлении курса';
                }
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
    <title>Добавление курса - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        function previewImage(event) {
            const reader = new FileReader();
            reader.onload = function() {
                const output = document.getElementById('imagePreview');
                output.src = reader.result;
                output.classList.remove('hidden');
            }
            reader.readAsDataURL(event.target.files[0]);
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
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Добавление нового курса</h2>
                <p class="text-gray-600">Заполните информацию о курсе</p>
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
            
            <form method="POST" enctype="multipart/form-data" class="px-6 py-4">
                <div class="grid grid-cols-1 gap-6 mt-4">
                    <!-- Название курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название курса *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Описание курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">Описание курса *</label>
                        <textarea name="description" id="description" rows="4" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Категория -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="category">Категория *</label>
                        <select name="category" id="category" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                            <option value="">Выберите категорию</option>
                            <option value="marketing" <?php echo (isset($_POST['category']) && $_POST['category'] === 'marketing') ? 'selected' : ''; ?>>Маркетинг</option>
                            <option value="engineering" <?php echo (isset($_POST['category']) && $_POST['category'] === 'engineering') ? 'selected' : ''; ?>>Инженерия</option>
                            <option value="science" <?php echo (isset($_POST['category']) && $_POST['category'] === 'science') ? 'selected' : ''; ?>>Лабораторное дело</option>
                            <option value="it" <?php echo (isset($_POST['category']) && $_POST['category'] === 'it') ? 'selected' : ''; ?>>IT технологии</option>
                        </select>
                    </div>
                    
                    <!-- Изображение курса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="image">Изображение курса</label>
                        <input type="file" name="image" id="image" accept="image/*" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" onchange="previewImage(event)">
                        <div class="mt-2">
                            <img id="imagePreview" class="hidden max-w-xs rounded shadow-md">
                        </div>
                    </div>
                    
                    <!-- Продолжительность и цена -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="duration_hours">Продолжительность (часов)</label>
                            <input type="number" name="duration_hours" id="duration_hours" min="0" 
                                   value="<?php echo htmlspecialchars($_POST['duration_hours'] ?? ''); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="price">Цена (руб.)</label>
                            <input type="number" name="price" id="price" min="0" step="0.01" 
                                   value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                    </div>
                </div>
                
                <div class="flex justify-end mt-6">
                    <button type="submit" class="px-6 py-2 leading-5 text-white bg-green-600 rounded-md hover:bg-green-500 focus:outline-none focus:bg-green-700">
                        Добавить курс и перейти к модулям
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