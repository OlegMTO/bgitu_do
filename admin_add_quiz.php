<?php
session_start();

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: admin_login.php');
    exit;
}

include_once 'config/database.php';

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
        $query = "SELECT cm.*, c.title as course_title, c.id as course_id
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
    $description = $_POST['description'] ?? '';
    $question_text = $_POST['question_text'] ?? '';
    $question_type = $_POST['question_type'] ?? '';
    $options = [];
    $correct_answer = '';
    
    // Валидация данных
    if (empty($title) || empty($question_text) || empty($question_type)) {
        $error = 'Пожалуйста, заполните все обязательные поля';
    } else {
        try {
            // Обработка вариантов ответов для multiple_choice
            if ($question_type === 'multiple_choice') {
                $options = [];
                $correct_answer = $_POST['correct_answer'] ?? '';
                
                for ($i = 1; $i <= 4; $i++) {
                    $option = $_POST['option_' . $i] ?? '';
                    if (!empty($option)) {
                        $options[] = $option;
                    }
                }
                
                if (count($options) < 2) {
                    $error = 'Добавьте хотя бы два варианта ответа';
                } elseif (empty($correct_answer)) {
                    $error = 'Выберите правильный вариант ответа';
                }
            }
            
            if (!$error) {
                // Подготовка SQL запроса
                $query = "INSERT INTO module_quizzes (module_id, title, description, question_text, question_type, options, correct_answer) 
                          VALUES (:module_id, :title, :description, :question_text, :question_type, :options, :correct_answer)";
                $stmt = $db->prepare($query);
                
                // Привязка параметров
                $stmt->bindParam(':module_id', $module_id);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':question_text', $question_text);
                $stmt->bindParam(':question_type', $question_type);
                $stmt->bindParam(':options', json_encode($options));
                $stmt->bindParam(':correct_answer', $correct_answer);
                
                // Выполнение запроса
                if ($stmt->execute()) {
                    $success = 'Тест успешно добавлен!';
                    
                    // Очистка полей формы
                    $_POST = [];
                } else {
                    $error = 'Ошибка при добавлении теста';
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
    <title>Добавление теста - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        function toggleQuestionType() {
            const questionType = document.getElementById('question_type').value;
            const multipleChoiceFields = document.getElementById('multiple_choice_fields');
            const fileUploadFields = document.getElementById('file_upload_fields');
            
            if (questionType === 'multiple_choice') {
                multipleChoiceFields.classList.remove('hidden');
                fileUploadFields.classList.add('hidden');
            } else if (questionType === 'file_upload') {
                multipleChoiceFields.classList.add('hidden');
                fileUploadFields.classList.remove('hidden');
            } else {
                multipleChoiceFields.classList.add('hidden');
                fileUploadFields.classList.add('hidden');
            }
        }
    </script>
</head>
<body class="bg-gray-100 min-h-screen" onload="toggleQuestionType()">
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
                <h2 class="text-2xl font-bold text-gray-800">Добавление теста к модулю</h2>
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
            
            <form method="POST" class="px-6 py-4">
                <div class="grid grid-cols-1 gap-6 mt-4">
                    <!-- Название теста -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название теста *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Описание теста -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">Описание теста</label>
                        <textarea name="description" id="description" rows="3" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Текст вопроса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="question_text">Текст вопроса *</label>
                        <textarea name="question_text" id="question_text" rows="3" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required><?php echo htmlspecialchars($_POST['question_text'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Тип вопроса -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="question_type">Тип вопроса *</label>
                        <select name="question_type" id="question_type" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required onchange="toggleQuestionType()">
                            <option value="">Выберите тип</option>
                            <option value="multiple_choice" <?php echo (isset($_POST['question_type']) && $_POST['question_type'] === 'multiple_choice') ? 'selected' : ''; ?>>Множественный выбор</option>
                            <option value="file_upload" <?php echo (isset($_POST['question_type']) && $_POST['question_type'] === 'file_upload') ? 'selected' : ''; ?>>Загрузка файла</option>
                        </select>
                    </div>
                    
                    <!-- Поля для множественного выбора -->
                    <div id="multiple_choice_fields" class="hidden">
                        <div class="grid grid-cols-1 gap-4">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div>
                                <label class="block text-gray-700 text-sm font-bold mb-2" for="option_<?php echo $i; ?>">Вариант ответа <?php echo $i; ?></label>
                                <input type="text" name="option_<?php echo $i; ?>" id="option_<?php echo $i; ?>" 
                                       value="<?php echo htmlspecialchars($_POST['option_' . $i] ?? ''); ?>" 
                                       class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                            <?php endfor; ?>
                        </div>
                        
                        <div class="mt-4">
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="correct_answer">Правильный ответ *</label>
                            <select name="correct_answer" id="correct_answer" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                                <option value="">Выберите правильный ответ</option>
                                <?php for ($i = 1; $i <= 4; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo (isset($_POST['correct_answer']) && $_POST['correct_answer'] == $i) ? 'selected' : ''; ?>>Вариант <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Поля для загрузки файла -->
                    <div id="file_upload_fields" class="hidden">
                        <div class="bg-blue-50 p-4 rounded">
                            <p class="text-blue-800">Для вопросов с загрузкой файла студенты смогут загружать файлы в ответ. Оценивание производится вручную преподавателем.</p>
                        </div>
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
                            Добавить тест
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Список тестов модуля -->
        <?php if ($module_id): ?>
        <div class="bg-white shadow rounded-lg p-6 mt-8">
            <h2 class="text-xl font-semibold mb-4">Тесты модуля</h2>
            <?php
            try {
                $query = "SELECT * FROM module_quizzes WHERE module_id = :module_id ORDER BY created_at DESC";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':module_id', $module_id);
                $stmt->execute();
                $quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($quizzes) > 0) {
                    echo '<ul class="divide-y divide-gray-200">';
                    foreach ($quizzes as $quiz) {
                        echo '<li class="py-4">';
                        echo '<div class="flex items-center justify-between">';
                        echo '<div>';
                        echo '<h3 class="text-lg font-medium">' . htmlspecialchars($quiz['title']) . '</h3>';
                        echo '<p class="text-sm text-gray-500">Тип: ' . ($quiz['question_type'] === 'multiple_choice' ? 'Множественный выбор' : 'Загрузка файла') . '</p>';
                        echo '<p class="text-sm text-gray-500">Вопрос: ' . htmlspecialchars(substr($quiz['question_text'], 0, 100)) . '...</p>';
                        echo '</div>';
                        echo '<div>';
                        echo '<a href="admin_edit_quiz.php?id=' . $quiz['id'] . '" class="text-blue-600 hover:text-blue-800 mr-3">Редактировать</a>';
                        echo '<a href="admin_delete_quiz.php?id=' . $quiz['id'] . '" class="text-red-600 hover:text-red-800" onclick="return confirm(\'Вы уверены?\')">Удалить</a>';
                        echo '</div>';
                        echo '</div>';
                        echo '</li>';
                    }
                    echo '</ul>';
                } else {
                    echo '<p class="text-gray-500">Тесты не добавлены.</p>';
                }
            } catch (PDOException $e) {
                echo '<p class="text-red-500">Ошибка загрузки тестов: ' . $e->getMessage() . '</p>';
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