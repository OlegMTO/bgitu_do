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

$module_id = $_GET['module_id'] ?? 0;
$error = '';
$success = '';

// Загрузка информации о модуле и курсе
$module = [];
$course = [];
if ($module_id) {
    try {
        $query = "SELECT cm.*, c.title as course_title 
                  FROM course_modules cm 
                  JOIN courses c ON cm.course_id = c.id 
                  WHERE cm.id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $module_id);
        $stmt->execute();
        $module = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($module) {
            $course_query = "SELECT * FROM courses WHERE id = :course_id";
            $course_stmt = $db->prepare($course_query);
            $course_stmt->bindParam(':course_id', $module['course_id']);
            $course_stmt->execute();
            $course = $course_stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $exception) {
        $error = 'Ошибка загрузки модуля: ' . $exception->getMessage();
    }
}

// Если модуль не найден
if (!$module) {
    $error = 'Модуль не найден';
}

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $module) {
    $title = $_POST['title'] ?? '';
    $type = $_POST['type'] ?? '';
    $content = $_POST['content'] ?? '';
    $order_index = $_POST['order_index'] ?? 0;
    
    // Валидация данных
    if (empty($title) || empty($type)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } else {
        try {
            $file_path = '';
            $file_size = 0;
            
            // Обработка загрузки файла, если тип требует этого
            if (in_array($type, ['video', 'presentation', 'file']) && !empty($_FILES['material_file']['name'])) {
                $allowedTypes = [];
                switch ($type) {
                    case 'video':
                        $allowedTypes = ['mp4', 'mov', 'avi', 'wmv'];
                        break;
                    case 'presentation':
                        $allowedTypes = ['ppt', 'pptx', 'pdf'];
                        break;
                    case 'file':
                        $allowedTypes = ['pdf', 'doc', 'docx', 'txt', 'zip', 'rar'];
                        break;
                }
                
                $fileUpload = handleFileUpload($_FILES['material_file'], $allowedTypes);
                if ($fileUpload['success']) {
                    $file_path = $fileUpload['file_path'];
                    $file_size = $fileUpload['file_size'];
                    saveFileToDatabase($db, $fileUpload);
                } else {
                    $error = $fileUpload['error'];
                }
            }
            
            if (!$error) {
                // Подготовка SQL запроса
                $query = "INSERT INTO module_materials (module_id, title, type, content, file_path, file_size, order_index) 
                          VALUES (:module_id, :title, :type, :content, :file_path, :file_size, :order_index)";
                $stmt = $db->prepare($query);
                
                // Привязка параметров
                $stmt->bindParam(':module_id', $module_id);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':type', $type);
                $stmt->bindParam(':content', $content);
                $stmt->bindParam(':file_path', $file_path);
                $stmt->bindParam(':file_size', $file_size);
                $stmt->bindParam(':order_index', $order_index);
                
                // Выполнение запроса
                if ($stmt->execute()) {
                    $success = 'Материал успешно добавлен!';
                    
                    // Очистка полей формы
                    $_POST['title'] = '';
                    $_POST['content'] = '';
                } else {
                    $error = 'Ошибка при добавлении материала';
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
    <title>Добавление материала - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        function toggleMaterialFields() {
            const type = document.getElementById('type').value;
            const fileField = document.getElementById('file-field');
            const contentField = document.getElementById('content-field');
            
            if (type === 'video' || type === 'presentation' || type === 'file') {
                fileField.classList.remove('hidden');
                contentField.classList.add('hidden');
            } else if (type === 'text' || type === 'summary') {
                fileField.classList.add('hidden');
                contentField.classList.remove('hidden');
            } else {
                fileField.classList.add('hidden');
                contentField.classList.add('hidden');
            }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen" onload="toggleMaterialFields()">
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
                    <a href="admin_manage_modules.php" class="text-gray-700 hover:text-green-700 mr-4">Модули</a>
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">На сайт</a>
                    <a href="admin_logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-2xl font-bold text-gray-800">Добавление материала к модулю</h2>
                <p class="text-gray-600">Курс: <?php echo htmlspecialchars($course['title'] ?? 'Неизвестный курс'); ?></p>
                <p class="text-gray-600">Модуль: <?php echo htmlspecialchars($module['title'] ?? 'Неизвестный модуль'); ?></p>
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
                    <!-- Название материала -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название материала *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Тип материала -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="type">Тип материала *</label>
                        <select name="type" id="type" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required onchange="toggleMaterialFields()">
                            <option value="">Выберите тип</option>
                            <option value="video" <?php echo (isset($_POST['type']) && $_POST['type'] === 'video') ? 'selected' : ''; ?>>Видео</option>
                            <option value="presentation" <?php echo (isset($_POST['type']) && $_POST['type'] === 'presentation') ? 'selected' : ''; ?>>Презентация</option>
                            <option value="text" <?php echo (isset($_POST['type']) && $_POST['type'] === 'text') ? 'selected' : ''; ?>>Текст</option>
                            <option value="summary" <?php echo (isset($_POST['type']) && $_POST['type'] === 'summary') ? 'selected' : ''; ?>>Конспект</option>
                            <option value="file" <?php echo (isset($_POST['type']) && $_POST['type'] === 'file') ? 'selected' : ''; ?>>Файл</option>
                        </select>
                    </div>
                    
                    <!-- Поле для файла -->
                    <div id="file-field" class="hidden">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="material_file">Файл *</label>
                        <input type="file" name="material_file" id="material_file" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        <p class="text-xs text-gray-500 mt-1">
                            Для видео: MP4, MOV, AVI, WMV<br>
                            Для презентаций: PPT, PPTX, PDF<br>
                            Для файлов: PDF, DOC, DOCX, TXT, ZIP, RAR
                        </p>
                    </div>
                    
                    <!-- Поле для текстового содержания -->
                    <div id="content-field" class="hidden">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="content">Содержание *</label>
                        <textarea name="content" id="content" rows="6" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"><?php echo htmlspecialchars($_POST['content'] ?? ''); ?></textarea>
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
                    <a href="admin_add_module.php?course_id=<?php echo $module['course_id']; ?>" class="px-6 py-2 leading-5 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 focus:outline-none">
                        Назад к модулям
                    </a>
                    <div>
                        <button type="submit" name="add_another" value="1" class="px-6 py-2 leading-5 text-gray-700 bg-blue-200 rounded-md hover:bg-blue-300 focus:outline-none mr-2">
                            Добавить и создать еще
                        </button>
                        <button type="submit" class="px-6 py-2 leading-5 text-white bg-green-600 rounded-md hover:bg-green-500 focus:outline-none focus:bg-green-700">
                            Добавить материал
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Список материалов модуля -->
        <?php if ($module_id): ?>
        <div class="bg-white shadow rounded-lg p-6 mt-8">
            <h2 class="text-xl font-semibold mb-4">Материалы модуля</h2>
            <?php
            try {
                $query = "SELECT * FROM module_materials WHERE module_id = :module_id ORDER BY order_index, title";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':module_id', $module_id);
                $stmt->execute();
                $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($materials) > 0) {
                    echo '<ul class="divide-y divide-gray-200">';
                    foreach ($materials as $material) {
                        echo '<li class="py-4">';
                        echo '<div class="flex items-center justify-between">';
                        echo '<div>';
                        echo '<h3 class="text-lg font-medium">' . htmlspecialchars($material['title']) . '</h3>';
                        echo '<p class="text-sm text-gray-500">Тип: ' . $material['type'] . '</p>';
                        if ($material['file_path']) {
                            echo '<p class="text-sm text-gray-500">Файл: ' . basename($material['file_path']) . '</p>';
                        }
                        echo '</div>';
                        echo '<div>';
                        echo '<a href="admin_edit_material.php?id=' . $material['id'] . '" class="text-blue-600 hover:text-blue-800 mr-3">Редактировать</a>';
                        echo '<a href="admin_delete_material.php?id=' . $material['id'] . '" class="text-red-600 hover:text-red-800" onclick="return confirm(\'Вы уверены?\')">Удалить</a>';
                        echo '</div>';
                        echo '</div>';
                        echo '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p class="text-gray-500">Материалы не добавлены.</p>';
                }
            } catch (PDOException $e) {
                echo '<p class="text-red-500">Ошибка загрузки материалов: ' . $e->getMessage() . '</p>';
            }
            ?>
        </div>
        <?php endif; ?>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>