<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

// Проверка аутентификации
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

// Инициализация переменных
$progress = 0;
$course_id = $_GET['course_id'] ?? 0;
$isEnrolled = false;
$course = [];
$modules = [];
$moduleQuizzes = [];
$courseExam = null;
$quizProgress = [];
$materialProgress = [];

if ($course_id) {
    try {
        // Проверяем, записан ли пользователь
        $query = "SELECT id, progress FROM enrollments WHERE user_id = :user_id AND course_id = :course_id";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':course_id' => $course_id
        ]);
        $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
        $isEnrolled = $enrollment !== false;
        $progress = $enrollment['progress'] ?? 0;

        if ($isEnrolled) {
            // Данные курса
            $stmt = $db->prepare("SELECT * FROM courses WHERE id = :id");
            $stmt->execute([':id' => $course_id]);
            $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

            // Модули
            $stmt = $db->prepare("SELECT DISTINCT cm.* FROM course_modules cm WHERE cm.course_id = :course_id ORDER BY cm.order_index");
            $stmt->execute([':course_id' => $course_id]);
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            // Материалы модулей
            $moduleIds = array_column($modules, 'id');
            if (!empty($moduleIds)) {
                $placeholders = implode(',', array_fill(0, count($moduleIds), '?'));
                
                // Материалы для всех модулей
                $stmt = $db->prepare("SELECT * FROM module_materials WHERE module_id IN ($placeholders) ORDER BY module_id, order_index");
                $stmt->execute($moduleIds);
                $allMaterials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                // Группируем материалы по module_id
                $materialsByModule = [];
                foreach ($allMaterials as $material) {
                    $materialsByModule[$material['module_id']][] = $material;
                }
                
                // Добавляем материалы к соответствующим модулям
                foreach ($modules as &$module) {
                    $moduleId = $module['id'];
                    $module['materials'] = $materialsByModule[$moduleId] ?? [];
                }
                unset($module); // разрываем ссылку
                
                // Тесты модулей
                $stmt = $db->prepare("SELECT * FROM module_quizzes WHERE module_id IN ($placeholders) ORDER BY module_id, created_at");
                $stmt->execute($moduleIds);
                $allQuizzes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                // Группируем тесты по module_id
                foreach ($allQuizzes as $quiz) {
                    $moduleQuizzes[$quiz['module_id']][] = $quiz;
                }
            }

            // Итоговый экзамен
            $stmt = $db->prepare("SELECT * FROM course_exams WHERE course_id = :course_id");
            $stmt->execute([':course_id' => $course_id]);
            $courseExam = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

            // Прогресс тестов пользователя
            $stmt = $db->prepare("
                SELECT quiz_id, MAX(score) as best_score 
                FROM quiz_attempts 
                WHERE user_id = :user_id 
                GROUP BY quiz_id
            ");
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $quizProgress[$row['quiz_id']] = [
                    'completed' => $row['best_score'] > 0,
                    'score' => $row['best_score']
                ];
            }

            // Прогресс материалов пользователя
            $stmt = $db->prepare("
                SELECT material_id 
                FROM material_progress 
                WHERE user_id = :user_id AND completed = true
            ");
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $materialProgress[$row['material_id']] = true;
            }
        }
    } catch (PDOException $e) {
        error_log("Ошибка загрузки курса: " . $e->getMessage());
        $_SESSION['error'] = "Ошибка загрузки курса. Пожалуйста, попробуйте позже.";
    }
}

if (!$isEnrolled || !$course) {
    header('Location: courses.php');
    exit;
}

// Текущий материал
$material_id = $_GET['material_id'] ?? 0;
$currentMaterial = null;

if ($material_id) {
    $stmt = $db->prepare("SELECT * FROM module_materials WHERE id = :id");
    $stmt->execute([':id' => $material_id]);
    $currentMaterial = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$currentMaterial && !empty($modules[0]['materials'])) {
    $currentMaterial = $modules[0]['materials'][0];
    $material_id = $currentMaterial['id'];
}

// Обработка прохождения теста
$quizError = null;
$quizSuccess = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_quiz'])) {
    $quiz_id = $_POST['quiz_id'] ?? 0;
    
    try {
        // Получаем данные теста
        $stmt = $db->prepare("SELECT * FROM module_quizzes WHERE id = :id");
        $stmt->execute([':id' => $quiz_id]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($quiz) {
            $score = 0;
            
            if ($quiz['question_type'] === 'multiple_choice') {
                $user_answer = $_POST['answer'] ?? '';
                $options = json_decode($quiz['options'], true);
                $correct_index = (int)$quiz['correct_answer'] - 1;
                
                if (isset($options[$correct_index]) && $user_answer == $options[$correct_index]) {
                    $score = 1;
                    $quizSuccess = "Правильный ответ! Тест пройден успешно.";
                } else {
                    $quizError = "Неверный ответ. Попробуйте еще раз.";
                }
            } elseif ($quiz['question_type'] === 'file_upload') {
                // Для загрузки файлов считаем, что пользователь получил баллы за отправку
                if (!empty($_FILES['answer_file']['name'])) {
                    $uploadDir = 'uploads/quiz_answers/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $fileName = time() . '_' . basename($_FILES['answer_file']['name']);
                    $targetPath = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['answer_file']['tmp_name'], $targetPath)) {
                        $score = 1; // Засчитываем как выполненное
                        $quizSuccess = "Файл успешно загружен! Ответ принят.";
                    } else {
                        $quizError = "Ошибка загрузки файла. Попробуйте еще раз.";
                    }
                } else {
                    $quizError = "Пожалуйста, выберите файл для загрузки.";
                }
            }
            
            // Сохраняем результат в quiz_attempts
            $stmt = $db->prepare("
                INSERT INTO quiz_attempts (user_id, quiz_id, score, total, submitted_at) 
                VALUES (:user_id, :quiz_id, :score, 1, NOW())
            ");
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':quiz_id' => $quiz_id,
                ':score' => $score
            ]);
            
            // Обновляем прогресс теста в сессии
            $quizProgress[$quiz_id] = [
                'completed' => $score > 0,
                'score' => $score
            ];
            
            // Обновляем общий прогресс курса
            if ($score > 0) {
                $stmt = $db->prepare("
                    UPDATE enrollments 
                    SET progress = (
                        SELECT COUNT(DISTINCT quiz_id) * 100 / (
                            SELECT COUNT(*) FROM module_quizzes mq 
                            JOIN course_modules cm ON mq.module_id = cm.id 
                            WHERE cm.course_id = :course_id
                        )
                        FROM quiz_attempts 
                        WHERE user_id = :user_id AND score > 0
                    )
                    WHERE user_id = :user_id AND course_id = :course_id
                ");
                $stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':course_id' => $course_id
                ]);
                
                // Обновляем переменную прогресса
                $stmt = $db->prepare("SELECT progress FROM enrollments WHERE user_id = :user_id AND course_id = :course_id");
                $stmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':course_id' => $course_id
                ]);
                $enrollment = $stmt->fetch(PDO::FETCH_ASSOC);
                $progress = $enrollment['progress'] ?? $progress;
            }
        }
    } catch (PDOException $e) {
        error_log("Ошибка прохождения теста: " . $e->getMessage());
        $quizError = "Ошибка при обработке теста. Пожалуйста, попробуйте еще раз.";
    }
}

// Помечаем материал как просмотренный
if ($currentMaterial && !isset($materialProgress[$currentMaterial['id']])) {
    try {
        $stmt = $db->prepare("
            INSERT INTO material_progress (user_id, material_id, completed, completed_at) 
            VALUES (:user_id, :material_id, true, NOW())
            ON CONFLICT (user_id, material_id) 
            DO UPDATE SET completed = true, completed_at = NOW()
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':material_id' => $currentMaterial['id']
        ]);
        
        $materialProgress[$currentMaterial['id']] = true;
    } catch (PDOException $e) {
        error_log("Ошибка сохранения прогресса: " . $e->getMessage());
    }
}

function getMaterialIcon($type) {
    $icons = [
        'video' => 'video',
        'presentation' => 'layers',
        'text' => 'file-text',
        'file' => 'file',
        'link' => 'link'
    ];
    return $icons[$type] ?? 'file';
}

// Функция для определения типа файла по расширению
function getFileType($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
    $documentExtensions = ['pdf', 'doc', 'docx', 'txt', 'rtf'];
    $presentationExtensions = ['ppt', 'pptx'];
    $spreadsheetExtensions = ['xls', 'xlsx', 'csv'];
    $videoExtensions = ['mp4', 'mov', 'avi', 'wmv', 'webm', 'mkv'];
    $audioExtensions = ['mp3', 'wav', 'ogg', 'm4a'];
    
    if (in_array($extension, $imageExtensions)) return 'image';
    if (in_array($extension, $documentExtensions)) return 'document';
    if (in_array($extension, $presentationExtensions)) return 'presentation';
    if (in_array($extension, $spreadsheetExtensions)) return 'spreadsheet';
    if (in_array($extension, $videoExtensions)) return 'video';
    if (in_array($extension, $audioExtensions)) return 'audio';
    
    return 'other';
}

// Функция для отображения файла
function displayFile($filePath, $fileType, $title = '') {
    switch ($fileType) {
        case 'image':
            return "<div class='text-center'>
                <img src='$filePath' class='max-w-full max-h-96 mx-auto rounded-lg shadow-md' alt='$title'>
                <div class='mt-4'>
                    <a href='$filePath' download class='inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                        <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать изображение
                    </a>
                </div>
            </div>";
        
        case 'document':
            if (pathinfo($filePath, PATHINFO_EXTENSION) === 'pdf') {
                return "<div class='w-full'>
                    <iframe src='$filePath' class='w-full h-96 border rounded-lg shadow-md' frameborder='0'></iframe>
                    <div class='mt-4'>
                        <a href='$filePath' download class='inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                            <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать PDF
                        </a>
                    </div>
                </div>";
            } else {
                return "<div class='text-center py-8 bg-gray-50 rounded-lg'>
                    <i data-feather='file-text' class='h-16 w-16 text-green-400 mx-auto'></i>
                    <p class='mt-4 text-lg font-medium'>$title</p>
                    <p class='text-gray-600 mt-2'>Этот формат файла можно только скачать для просмотра</p>
                    <a href='$filePath' download class='inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                        <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать документ
                    </a>
                </div>";
            }
        
        case 'presentation':
            return "<div class='text-center py-8 bg-gray-50 rounded-lg'>
                <i data-feather='layers' class='h-16 w-16 text-green-400 mx-auto'></i>
                <p class='mt-4 text-lg font-medium'>$title</p>
                <p class='text-gray-600 mt-2'>Этот формат файла можно только скачать для просмотра</p>
                <a href='$filePath' download class='inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                    <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать презентацию
                </a>
            </div>";
        
        case 'spreadsheet':
            return "<div class='text-center py-8 bg-gray-50 rounded-lg'>
                <i data-feather='grid' class='h-16 w-16 text-green-400 mx-auto'></i>
                <p class='mt-4 text-lg font-medium'>$title</p>
                <p class='text-gray-600 mt-2'>Этот формат файла можно только скачать для просмотра</p>
                <a href='$filePath' download class='inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                    <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать таблицу
                </a>
            </div>";
        
        case 'video':
            return "<div class='bg-black rounded-lg overflow-hidden shadow-md'>
                <video controls class='w-full' poster=''>
                    <source src='$filePath' type='video/mp4'>
                    Ваш браузер не поддерживает видео.
                </video>
                <div class='mt-4'>
                    <a href='$filePath' download class='inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                        <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать видео
                    </a>
                </div>
            </div>";
        
        case 'audio':
            return "<div class='text-center py-8 bg-gray-50 rounded-lg'>
                <i data-feather='music' class='h-16 w-16 text-green-400 mx-auto'></i>
                <p class='mt-4 text-lg font-medium'>$title</p>
                <audio controls class='w-full mt-4'>
                    <source src='$filePath' type='audio/mpeg'>
                    Ваш браузер не поддерживает аудио.
                </audio>
                <div class='mt-4'>
                    <a href='$filePath' download class='inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                        <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать аудио
                    </a>
                </div>
            </div>";
        
        case 'other':
        default:
            return "<div class='text-center py-8 bg-gray-50 rounded-lg'>
                <i data-feather='file' class='h-16 w-16 text-green-400 mx-auto'></i>
                <p class='mt-4 text-lg font-medium'>$title</p>
                <p class='text-gray-600 mt-2'>Этот формат файла можно только скачать</p>
                <a href='$filePath' download class='inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700'>
                    <i data-feather='download' class='h-4 w-4 mr-2'></i> Скачать файл
                </a>
            </div>";
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($course['title']) ? htmlspecialchars($course['title']) : 'Курс'; ?> - Обучение</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        .quiz-option {
            transition: all 0.2s;
        }
        .quiz-option:hover {
            background-color: #f3f4f6;
        }
        .correct-answer {
            background-color: #d1fae5;
            border-color: #10b981;
        }
        .wrong-answer {
            background-color: #fee2e2;
            border-color: #ef4444;
        }
        .completed-material {
            border-left: 4px solid #10b981;
        }
        .material-item:hover {
            background-color: #f9fafb;
        }
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                top: 0;
                left: -100%;
                width: 80%;
                height: 100vh;
                z-index: 50;
                transition: left 0.3s ease;
                overflow-y: auto;
            }
            .sidebar.open {
                left: 0;
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 40;
            }
            .overlay.open {
                display: block;
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Навигация -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 flex justify-between h-16 items-center">
            <div class="flex items-center">
                <i data-feather="book-open" class="h-6 w-6 text-green-700"></i>
                <span class="ml-2 font-bold">БГИТУ</span>
            </div>
            <div class="flex items-center">
                <button id="menu-toggle" class="lg:hidden p-2 rounded-md text-gray-600 hover:text-green-700">
                    <i data-feather="menu" class="h-6 w-6"></i>
                </button>
                <div class="hidden lg:flex">
                    <a href="index.php" class="mr-4 hover:text-green-700">Главная</a>
                    <a href="courses.php" class="mr-4 hover:text-green-700">Курсы</a>
                    <a href="profile.php" class="mr-4 hover:text-green-700">Профиль</a>
                    <a href="logout.php" class="hover:text-green-700">Выйти</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Мобильное меню -->
    <div id="overlay" class="overlay"></div>

    <div class="max-w-7xl mx-auto py-6 px-4 flex flex-col lg:flex-row gap-6">
        <!-- Боковое меню -->
        <aside id="sidebar" class="sidebar lg:w-1/4 bg-white shadow rounded-lg p-4 h-fit lg:sticky lg:top-6">
            <div class="flex justify-between items-center mb-4 lg:hidden">
                <h2 class="font-bold text-lg"><?php echo isset($course['title']) ? htmlspecialchars($course['title']) : 'Курс'; ?></h2>
                <button id="close-menu" class="p-2 rounded-md text-gray-600 hover:text-green-700">
                    <i data-feather="x" class="h-6 w-6"></i>
                </button>
            </div>
            
            <div class="hidden lg:flex items-center justify-between mb-4">
                <h2 class="font-bold text-lg"><?php echo isset($course['title']) ? htmlspecialchars($course['title']) : 'Курс'; ?></h2>
                <?php if (isset($course['image_url']) && $course['image_url']): ?>
                    <img src="<?php echo htmlspecialchars($course['image_url']); ?>" alt="Изображение курса" class="w-10 h-10 object-cover rounded">
                <?php endif; ?>
            </div>
            
            <div class="mb-4">
                <div class="flex justify-between text-sm mb-1">
                    <span>Прогресс курса</span>
                    <span><?php echo $progress; ?>%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2">
                    <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $progress; ?>%"></div>
                </div>
            </div>

            <div class="space-y-4">
                <?php foreach ($modules as $index => $module): ?>
                    <div>
                        <div class="flex items-center justify-between">
                            <h3 class="font-medium flex items-center">
                                <i data-feather="folder" class="h-4 w-4 mr-2"></i>
                                Модуль <?php echo $index + 1; ?>: <?php echo htmlspecialchars($module['title']); ?>
                            </h3>
                        </div>
                        
                        <?php if (isset($module['description']) && !empty($module['description'])): ?>
                            <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($module['description']); ?></p>
                        <?php endif; ?>
                        
                        <ul class="mt-2 space-y-2 ml-6">
                            <?php foreach ($module['materials'] as $material): 
                                $isCurrent = $material['id'] == $material_id;
                                $isCompleted = isset($materialProgress[$material['id']]);
                            ?>
                                <li class="material-item rounded p-1 <?php echo $isCompleted ? 'completed-material' : ''; ?>">
                                    <a href="learning.php?course_id=<?php echo $course_id; ?>&material_id=<?php echo $material['id']; ?>" 
                                       class="flex items-center <?php echo $isCurrent ? 'text-green-600 font-bold' : 'text-gray-600'; ?>">
                                        <i data-feather="<?php echo getMaterialIcon($material['type']); ?>" class="h-4 w-4 mr-2"></i>
                                        <?php echo htmlspecialchars($material['title']); ?>
                                        <?php if ($isCompleted): ?>
                                            <i data-feather="check-circle" class="h-4 w-4 text-green-500 ml-2"></i>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            
                            <?php if (isset($moduleQuizzes[$module['id']]) && !empty($moduleQuizzes[$module['id']])): ?>
                                <?php foreach ($moduleQuizzes[$module['id']] as $quiz): 
                                    $isQuizCompleted = isset($quizProgress[$quiz['id']]) && $quizProgress[$quiz['id']]['completed'];
                                ?>
                                    <li class="material-item rounded p-1">
                                        <div class="flex items-center text-gray-600">
                                            <i data-feather="help-circle" class="h-4 w-4 mr-2"></i>
                                            <span>Тест: <?php echo htmlspecialchars($quiz['title']); ?></span>
                                            <?php if ($isQuizCompleted): ?>
                                                <i data-feather="check-circle" class="h-4 w-4 text-green-500 ml-2"></i>
                                            <?php endif; ?>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endforeach; ?>
                
                <?php if ($courseExam): ?>
                    <div class="pt-4 border-t">
                        <h3 class="font-medium flex items-center">
                            <i data-feather="award" class="h-4 w-4 mr-2 text-red-500"></i>
                            Итоговый экзамен
                        </h3>
                        <p class="text-sm text-gray-600 mt-1">Проходной балл: <?php echo $courseExam['passing_score']; ?>%</p>
                        <p class="text-sm text-gray-600">Время: <?php echo $courseExam['time_limit_minutes']; ?> минут</p>
                        <p class="text-sm text-gray-600">Попытки: <?php echo $courseExam['max_attempts']; ?></p>
                        
                        <a href="exam.php?course_id=<?php echo $course_id; ?>" class="inline-block mt-2 bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-sm">
                            Начать экзамен
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- Контент -->
        <main class="lg:w-3/4 bg-white shadow rounded-lg">
            <?php if ($currentMaterial): ?>
                <div class="p-4 md:p-6 border-b flex justify-between items-center">
                    <h2 class="text-xl md:text-2xl font-bold"><?php echo htmlspecialchars($currentMaterial['title']); ?></h2>
                    <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                        <?php 
                        $typeLabels = [
                            'video' => 'Видео',
                            'presentation' => 'Презентация',
                            'text' => 'Текст',
                            'file' => 'Файл',
                            'link' => 'Ссылка'
                        ];
                        echo $typeLabels[$currentMaterial['type']] ?? 'Материал';
                        ?>
                    </span>
                </div>
                <div class="p-4 md:p-6">
                    <?php if ($currentMaterial['type'] === 'video'): ?>
                        <div class="bg-black rounded-lg overflow-hidden">
                            <video controls class="w-full" poster="<?php echo isset($course['image_url']) ? htmlspecialchars($course['image_url']) : ''; ?>">
                                <source src="<?php echo htmlspecialchars($currentMaterial['file_path']); ?>" type="video/mp4">
                                Ваш браузер не поддерживает видео.
                            </video>
                        </div>

                    <?php elseif ($currentMaterial['type'] === 'text'): ?>
                        <div class="prose max-w-none">
                            <?php echo nl2br(htmlspecialchars($currentMaterial['content'])); ?>
                        </div>

                    <?php elseif ($currentMaterial['type'] === 'file'): ?>
                        <div class="border rounded-lg p-4 bg-gray-50">
                            <div class="flex items-center mb-4">
                                <i data-feather="file" class="h-6 w-6 mr-2 text-green-600"></i>
                                <h3 class="text-lg font-medium">Файл: <?php echo htmlspecialchars($currentMaterial['title']); ?></h3>
                            </div>
                            <?php 
                            $fileType = getFileType($currentMaterial['file_path']);
                            echo displayFile($currentMaterial['file_path'], $fileType, $currentMaterial['title']); 
                            ?>
                        </div>

                    <?php elseif ($currentMaterial['type'] === 'link'): ?>
                        <div class="text-center py-8">
                            <i data-feather="link" class="h-16 w-16 text-green-400 mx-auto"></i>
                            <p class="mt-4 text-lg">Внешний ресурс</p>
                            <p class="text-gray-600 mt-2"><?php echo htmlspecialchars($currentMaterial['content']); ?></p>
                            <a href="<?php echo htmlspecialchars($currentMaterial['content']); ?>" target="_blank"
                               class="inline-flex items-center mt-4 px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700">
                                <i data-feather="external-link" class='h-4 w-4 mr-2'></i> Перейти к ресурсу
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Тесты модуля -->
                <?php 
                $module_id = null;
                foreach ($modules as $module) {
                    foreach ($module['materials'] as $material) {
                        if ($material['id'] == $currentMaterial['id']) {
                            $module_id = $module['id'];
                            break 2;
                        }
                    }
                }
                
                if ($module_id && isset($moduleQuizzes[$module_id]) && !empty($moduleQuizzes[$module_id])): 
                ?>
                    <div class="p-4 md:p-6 border-t">
                        <h3 class="text-xl font-bold mb-4">Тесты модуля</h3>
                        <div class="space-y-6">
                            <?php foreach ($moduleQuizzes[$module_id] as $quiz): 
                                $isQuizCompleted = isset($quizProgress[$quiz['id']]) && $quizProgress[$quiz['id']]['completed'];
                            ?>
                                <div class="border rounded-lg p-4 <?php echo $isQuizCompleted ? 'bg-green-50 border-green-200' : 'bg-gray-50'; ?>">
                                    <div class="flex justify-between items-center mb-4">
                                        <h4 class="text-lg font-medium"><?php echo htmlspecialchars($quiz['title']); ?></h4>
                                        <?php if ($isQuizCompleted): ?>
                                            <span class="bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full flex items-center">
                                                <i data-feather="check-circle" class="h-4 w-4 mr-1"></i> Пройдено
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (isset($quiz['description']) && !empty($quiz['description'])): ?>
                                        <p class="text-gray-600 mb-4"><?php echo htmlspecialchars($quiz['description']); ?></p>
                                    <?php endif; ?>
                                    
                                    <p class="font-medium mb-2"><?php echo htmlspecialchars($quiz['question_text']); ?></p>
                                    
                                    <?php if ($quiz['question_type'] === 'multiple_choice' && !$isQuizCompleted): 
                                        $options = json_decode($quiz['options'], true);
                                        $correct_index = (int)$quiz['correct_answer'] - 1;
                                        $correct_answer = isset($options[$correct_index]) ? $options[$correct_index] : '';
                                    ?>
                                        <form method="POST" class="space-y-2">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                            <?php foreach ($options as $index => $option): 
                                                $is_correct = ($option === $correct_answer);
                                                $option_class = '';
                                                if (isset($_POST['submit_quiz']) && $_POST['quiz_id'] == $quiz['id']) {
                                                    if ($_POST['answer'] === $option) {
                                                        $option_class = $is_correct ? 'correct-answer' : 'wrong-answer';
                                                    } elseif ($is_correct) {
                                                        $option_class = 'correct-answer';
                                                    }
                                                }
                                            ?>
                                                <label class="block quiz-option cursor-pointer p-3 border rounded-lg <?php echo $option_class; ?>">
                                                    <input type="radio" name="answer" value="<?php echo htmlspecialchars($option); ?>" class="mr-2" 
                                                           <?php if (isset($_POST['submit_quiz']) && $_POST['quiz_id'] == $quiz['id'] && $_POST['answer'] === $option) echo 'checked'; ?>
                                                           <?php if ($isQuizCompleted) echo 'disabled'; ?>>
                                                    <?php echo htmlspecialchars($option); ?>
                                                    <?php if ($option_class === 'correct-answer'): ?>
                                                        <i data-feather="check" class="h-4 w-4 text-green-600 ml-2 inline"></i>
                                                    <?php elseif ($option_class === 'wrong-answer'): ?>
                                                        <i data-feather="x" class="h-4 w-4 text-red-600 ml-2 inline"></i>
                                                    <?php endif; ?>
                                                </label>
                                            <?php endforeach; ?>
                                            
                                            <?php if (isset($quizError) && $_POST['quiz_id'] == $quiz['id']): ?>
                                                <div class="text-red-600 mt-2 flex items-center">
                                                    <i data-feather="alert-circle" class="h-4 w-4 mr-1"></i> 
                                                    <?php echo $quizError; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (isset($quizSuccess) && $_POST['quiz_id'] == $quiz['id']): ?>
                                                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mt-4 flex items-center">
                                                    <i data-feather="check-circle" class="h-4 w-4 mr-2"></i> 
                                                    <?php echo $quizSuccess; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (!$isQuizCompleted): ?>
                                                <button type="submit" name="submit_quiz" class="mt-4 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center">
                                                    <i data-feather="check-square" class="h-4 w-4 mr-2"></i> Проверить ответ
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    <?php elseif ($quiz['question_type'] === 'file_upload' && !$isQuizCompleted): ?>
                                        <form method="POST" enctype="multipart/form-data">
                                            <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                            <div class="mb-4">
                                                <label class="block text-sm font-medium mb-2">Загрузите ваш файл:</label>
                                                <input type="file" name="answer_file" required class="block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:bg-green-50 file:text-green-700 hover:file:bg-green-100">
                                            </div>
                                            
                                            <?php if (isset($quizError) && $_POST['quiz_id'] == $quiz['id']): ?>
                                                <div class="text-red-600 mt-2 flex items-center">
                                                    <i data-feather="alert-circle" class="h-4 w-4 mr-1"></i> 
                                                    <?php echo $quizError; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if (isset($quizSuccess) && $_POST['quiz_id'] == $quiz['id']): ?>
                                                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mt-4 flex items-center">
                                                    <i data-feather="check-circle" class="h-4 w-4 mr-2"></i> 
                                                    <?php echo $quizSuccess; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <button type="submit" name="submit_quiz" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center">
                                                <i data-feather="upload" class="h-4 w-4 mr-2"></i> Отправить файл
                                            </button>
                                        </form>
                                    <?php elseif ($isQuizCompleted): ?>
                                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded flex items-center">
                                            <i data-feather="check-circle" class="h-4 w-4 mr-2"></i> 
                                            Тест успешно пройден! 
                                            <?php if (isset($quizProgress[$quiz['id']])): ?>
                                                (Результат: <?php echo $quizProgress[$quiz['id']]['score']; ?> из 1)
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
            <?php else: ?>
                <div class="p-6 text-center">
                    <i data-feather="book-open" class="h-16 w-16 text-green-400 mx-auto"></i>
                    <h2 class="mt-4 text-xl font-medium">Добро пожаловать на курс!</h2>
                    <p class="mt-2 text-gray-600">Выберите материал слева, чтобы начать обучение.</p>
                    
                    <?php if (isset($course['description']) && $course['description']): ?>
                        <div class="mt-6 text-left bg-green-50 p-4 rounded-lg">
                            <h3 class="font-medium text-green-800">О курсе</h3>
                            <p class="mt-2 text-green-700"><?php echo nl2br(htmlspecialchars($course['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4 text-left">
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <i data-feather="layers" class="h-6 w-6 text-green-600"></i>
                            <h3 class="font-medium mt-2">Модули</h3>
                            <p class="text-sm text-gray-600 mt-1"><?php echo count($modules); ?> модулей</p>
                        </div>
                        
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <i data-feather="file-text" class="h-6 w-6 text-green-600"></i>
                            <h3 class="font-medium mt-2">Материалы</h3>
                            <?php
                            $materialCount = 0;
                            foreach ($modules as $module) {
                                $materialCount += count($module['materials']);
                            }
                            ?>
                            <p class="text-sm text-gray-600 mt-1"><?php echo $materialCount; ?> материалов</p>
                        </div>
                        
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <i data-feather="help-circle" class="h-6 w-6 text-green-600"></i>
                            <h3 class="font-medium mt-2">Тесты</h3>
                            <?php
                            $quizCount = 0;
                            foreach ($moduleQuizzes as $quizzes) {
                                $quizCount += count($quizzes);
                            }
                            ?>
                            <p class="text-sm text-gray-600 mt-1"><?php echo $quizCount; ?> тестов</p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <script>
        feather.replace();
        
        // Обработка мобильного меню
        document.addEventListener('DOMContentLoaded', function() {
            const menuToggle = document.getElementById('menu-toggle');
            const closeMenu = document.getElementById('close-menu');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            
            function openMenu() {
                sidebar.classList.add('open');
                overlay.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
            
            function closeMenuFunc() {
                sidebar.classList.remove('open');
                overlay.classList.remove('open');
                document.body.style.overflow = 'auto';
            }
            
            if (menuToggle) menuToggle.addEventListener('click', openMenu);
            if (closeMenu) closeMenu.addEventListener('click', closeMenuFunc);
            if (overlay) overlay.addEventListener('click', closeMenuFunc);
            
            // Обработка видео для лучшего UX
            const videos = document.querySelectorAll('video');
            videos.forEach(video => {
                video.addEventListener('play', function() {
                    // Пауза всех других видео на странице
                    videos.forEach(otherVideo => {
                        if (otherVideo !== video) {
                            otherVideo.pause();
                        }
                    });
                });
            });
            
            // Автоматическая прокрутка к результату теста
            <?php if (isset($_POST['submit_quiz'])): ?>
                const quizElement = document.querySelector('input[name="quiz_id"][value="<?php echo $_POST['quiz_id']; ?>"]')?.closest('.border');
                if (quizElement) {
                    setTimeout(() => {
                        quizElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }, 100);
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>