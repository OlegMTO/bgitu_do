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
    $questions = [];
    $passing_score = $_POST['passing_score'] ?? 60;
    $time_limit_minutes = $_POST['time_limit_minutes'] ?? 60;
    $max_attempts = $_POST['max_attempts'] ?? 3;
    
    // Валидация данных
    if (empty($title)) {
        $error = 'Пожалуйста, введите название экзамена';
    } else {
        try {
            // Обработка вопросов
            $question_count = $_POST['question_count'] ?? 0;
            for ($i = 1; $i <= $question_count; $i++) {
                if (!empty($_POST['question_text_' . $i])) {
                    $question = [
                        'text' => $_POST['question_text_' . $i],
                        'type' => $_POST['question_type_' . $i],
                        'options' => [],
                        'correct_answer' => $_POST['correct_answer_' . $i] ?? ''
                    ];
                    
                    if ($question['type'] === 'multiple_choice') {
                        for ($j = 1; $j <= 4; $j++) {
                            $option = $_POST['option_' . $i . '_' . $j] ?? '';
                            if (!empty($option)) {
                                $question['options'][] = $option;
                            }
                        }
                    }
                    
                    $questions[] = $question;
                }
            }
            
            if (count($questions) === 0) {
                $error = 'Добавьте хотя бы один вопрос';
            }
            
            if (!$error) {
                // Подготовка SQL запроса
                $query = "INSERT INTO course_exams (course_id, title, description, questions, passing_score, time_limit_minutes, max_attempts) 
                          VALUES (:course_id, :title, :description, :questions, :passing_score, :time_limit_minutes, :max_attempts)";
                $stmt = $db->prepare($query);
                
                // Привязка параметров
                $stmt->bindParam(':course_id', $course_id);
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':questions', json_encode($questions, JSON_UNESCAPED_UNICODE));
                $stmt->bindParam(':passing_score', $passing_score);
                $stmt->bindParam(':time_limit_minutes', $time_limit_minutes);
                $stmt->bindParam(':max_attempts', $max_attempts);
                
                // Выполнение запроса
                if ($stmt->execute()) {
                    $success = 'Экзамен успешно добавлен!';
                    
                    // Очистка полей формы
                    $_POST = [];
                } else {
                    $error = 'Ошибка при добавлении экзамена';
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
    <title>Добавление экзамена - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <script>
        let questionCount = <?php echo $_POST['question_count'] ?? 0; ?>;
        
        function addQuestion() {
            questionCount++;
            const questionsContainer = document.getElementById('questions_container');
            
            const questionDiv = document.createElement('div');
            questionDiv.className = 'border rounded p-4 mb-4';
            questionDiv.innerHTML = `
                <div class="flex justify-between items-center mb-2">
                    <h3 class="text-lg font-semibold">Вопрос #${questionCount}</h3>
                    <button type="button" class="text-red-600 hover:text-red-800" onclick="this.parentElement.parentElement.remove()">
                        Удалить
                    </button>
                </div>
                <input type="hidden" name="question_count" value="${questionCount}">
                
                <div class="mb-3">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Текст вопроса *</label>
                    <textarea name="question_text_${questionCount}" rows="2" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="block text-gray-700 text-sm font-bold mb-2">Тип вопроса *</label>
                    <select name="question_type_${questionCount}" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" onchange="toggleQuestionType(${questionCount})" required>
                        <option value="">Выберите тип</option>
                        <option value="multiple_choice">Множественный выбор</option>
                        <option value="file_upload">Загрузка файла</option>
                    </select>
                </div>
                
                <div id="options_${questionCount}" class="hidden">
                    <div class="grid grid-cols-1 gap-3 mb-3">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Варианты ответов *</label>
                        ${[1, 2, 3, 4].map(i => `
                            <div>
                                <input type="text" name="option_${questionCount}_${i}" placeholder="Вариант ${i}" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            </div>
                        `).join('')}
                    </div>
                    
                    <div class="mb-3">
                        <label class="block text-gray-700 text-sm font-bold mb-2">Правильный ответ *</label>
                        <select name="correct_answer_${questionCount}" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                            <option value="">Выберите правильный ответ</option>
                            ${[1, 2, 3, 4].map(i => `
                                <option value="${i}">Вариант ${i}</option>
                            `).join('')}
                        </select>
                    </div>
                </div>
                
                <div id="file_upload_${questionCount}" class="hidden bg-blue-50 p-3 rounded">
                    <p class="text-blue-800 text-sm">Для вопросов с загрузкой файла студенты смогут загружать файлы в ответ. Оценивание производится вручную преподавателем.</p>
                </div>
            `;
            
            questionsContainer.appendChild(questionDiv);
        }
        
        function toggleQuestionType(questionNum) {
            const questionType = document.querySelector(`select[name="question_type_${questionNum}"]`).value;
            const optionsDiv = document.getElementById(`options_${questionNum}`);
            const fileUploadDiv = document.getElementById(`file_upload_${questionNum}`);
            
            if (questionType === 'multiple_choice') {
                optionsDiv.classList.remove('hidden');
                fileUploadDiv.classList.add('hidden');
            } else if (questionType === 'file_upload') {
                optionsDiv.classList.add('hidden');
                fileUploadDiv.classList.remove('hidden');
            } else {
                optionsDiv.classList.add('hidden');
                fileUploadDiv.classList.add('hidden');
            }
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
                <h2 class="text-2xl font-bold text-gray-800">Добавление итогового экзамена</h2>
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
                    <!-- Название экзамена -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="title">Название экзамена *</label>
                        <input type="text" name="title" id="title" value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>" 
                               class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    
                    <!-- Описание экзамена -->
                    <div>
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="description">Описание экзамена</label>
                        <textarea name="description" id="description" rows="3" 
                                  class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Параметры экзамена -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="passing_score">Проходной балл (%)</label>
                            <input type="number" name="passing_score" id="passing_score" min="0" max="100" 
                                   value="<?php echo htmlspecialchars($_POST['passing_score'] ?? '60'); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="time_limit_minutes">Лимит времени (мин.)</label>
                            <input type="number" name="time_limit_minutes" id="time_limit_minutes" min="1" 
                                   value="<?php echo htmlspecialchars($_POST['time_limit_minutes'] ?? '60'); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                        
                        <div>
                            <label class="block text-gray-700 text-sm font-bold mb-2" for="max_attempts">Макс. попыток</label>
                            <input type="number" name="max_attempts" id="max_attempts" min="1" 
                                   value="<?php echo htmlspecialchars($_POST['max_attempts'] ?? '3'); ?>" 
                                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                        </div>
                    </div>
                    
                    <!-- Вопросы экзамена -->
                    <div>
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-semibold">Вопросы экзамена</h3>
                            <button type="button" onclick="addQuestion()" class="px-3 py-1 bg-blue-600 text-white rounded text-sm">
                                Добавить вопрос
                            </button>
                        </div>
                        
                        <div id="questions_container">
                            <!-- Вопросы будут добавляться здесь через JavaScript -->
                        </div>
                        
                        <?php
                        // Восстановление вопросов из предыдущей отправки формы
                        if (isset($_POST['question_count']) && $_POST['question_count'] > 0) {
                            echo '<script>questionCount = 0;</script>';
                            for ($i = 1; $i <= $_POST['question_count']; $i++) {
                                if (!empty($_POST['question_text_' . $i])) {
                                    echo '<script>addQuestion();</script>';
                                }
                            }
                        }
                        ?>
                    </div>
                </div>
                
                <div class="flex justify-between mt-6">
                    <a href="admin_edit_course.php?id=<?php echo $course_id; ?>" class="px-6 py-2 leading-5 text-gray-700 bg-gray-200 rounded-md hover:bg-gray-300 focus:outline-none">
                        Назад к курсу
                    </a>
                    <button type="submit" class="px-6 py-2 leading-5 text-white bg-green-600 rounded-md hover:bg-green-500 focus:outline-none focus:bg-green-700">
                        Добавить экзамен
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        feather.replace();
        
        // Добавляем первый вопрос при загрузке, если нет сохраненных вопросов
        <?php if (!isset($_POST['question_count']) || $_POST['question_count'] == 0): ?>
        document.addEventListener('DOMContentLoaded', function() {
            addQuestion();
        });
        <?php endif; ?>
    </script>
</body>
</html>