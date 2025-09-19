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

// Получаем ID попытки из параметра
$attempt_id = $_GET['attempt_id'] ?? 0;

if (!$attempt_id) {
    header('Location: courses.php');
    exit;
}

// Получаем информацию о попытке
$attempt = [];
$exam = [];
$course = [];
$user = [];

try {
    // Данные о попытке
    $stmt = $db->prepare("
        SELECT ea.*, ce.title as exam_title, ce.passing_score, ce.course_id, 
               c.title as course_title, u.first_name, u.last_name
        FROM exam_attempts ea
        JOIN course_exams ce ON ea.exam_id = ce.id
        JOIN courses c ON ce.course_id = c.id
        JOIN users u ON ea.user_id = u.id
        WHERE ea.id = :attempt_id AND ea.user_id = :user_id
    ");
    $stmt->execute([
        ':attempt_id' => $attempt_id,
        ':user_id' => $_SESSION['user_id']
    ]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    if (!$attempt) {
        header('Location: courses.php');
        exit;
    }
    
    // Данные экзамена
    $stmt = $db->prepare("SELECT * FROM course_exams WHERE id = :exam_id");
    $stmt->execute([':exam_id' => $attempt['exam_id']]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    // Данные курса
    $stmt = $db->prepare("SELECT * FROM courses WHERE id = :course_id");
    $stmt->execute([':course_id' => $attempt['course_id']]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
    // Данные пользователя
    $stmt = $db->prepare("SELECT * FROM users WHERE id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    
} catch (PDOException $e) {
    error_log("Ошибка загрузки данных: " . $e->getMessage());
    header('Location: courses.php');
    exit;
}

// Рассчитываем процент правильных ответов
$percentage = $attempt['total_questions'] > 0 
    ? round(($attempt['score'] / $attempt['total_questions']) * 100) 
    : 0;

// Проверяем, сдан ли экзамен
$is_passed = $percentage >= $attempt['passing_score'];

// Если экзамен сдан, обновляем запись о enrollment
if ($is_passed && !$attempt['passed']) {
    try {
        // Обновляем попытку
        $stmt = $db->prepare("UPDATE exam_attempts SET passed = TRUE WHERE id = :attempt_id");
        $stmt->execute([':attempt_id' => $attempt_id]);
        
        // Обновляем запись о курсе (отмечаем как завершенный)
        $stmt = $db->prepare("
            UPDATE enrollments 
            SET completed = TRUE, completed_at = CURRENT_TIMESTAMP, progress = 100 
            WHERE user_id = :user_id AND course_id = :course_id
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':course_id' => $attempt['course_id']
        ]);
        
        // Обновляем локальную переменную
        $attempt['passed'] = true;
        
    } catch (PDOException $e) {
        error_log("Ошибка обновления данных: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Результаты экзамена - <?php echo htmlspecialchars($course['title']); ?> - БГИТУ</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        .result-card {
            transition: all 0.3s ease;
        }
        .result-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .progress-ring {
            transition: stroke-dashoffset 0.5s ease;
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
            <div>
                <a href="learning.php?course_id=<?php echo $attempt['course_id']; ?>" class="mr-4">Вернуться к курсу</a>
                <a href="courses.php" class="mr-4">Все курсы</a>
                <a href="logout.php">Выйти</a>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto py-6 px-4">
        <div class="bg-white shadow rounded-lg overflow-hidden result-card">
            <!-- Заголовок -->
            <div class="bg-gradient-to-r from-blue-500 to-blue-700 text-white p-6">
                <h1 class="text-2xl font-bold mb-2">Результаты экзамена</h1>
                <p class="text-blue-100">Курс: <?php echo htmlspecialchars($course['title']); ?></p>
                <p class="text-blue-100">Экзамен: <?php echo htmlspecialchars($attempt['exam_title']); ?></p>
                <p class="text-blue-100">Дата: <?php echo date('d.m.Y H:i', strtotime($attempt['attempted_at'])); ?></p>
            </div>

            <div class="p-6">
                <!-- Статус экзамена -->
                <div class="flex flex-col items-center mb-8">
                    <div class="relative">
                        <!-- Круговой индикатор прогресса -->
                        <svg class="w-32 h-32 transform -rotate-90" viewBox="0 0 36 36">
                            <path class="text-gray-200 stroke-current"
                                d="M18 2.0845
                                    a 15.9155 15.9155 0 0 1 0 31.831
                                    a 15.9155 15.9155 0 0 1 0 -31.831"
                                fill="none"
                                stroke-width="2"
                                stroke-dasharray="100, 100"
                            />
                            <path class="progress-ring <?php echo $is_passed ? 'text-green-500' : 'text-red-500'; ?> stroke-current"
                                d="M18 2.0845
                                    a 15.9155 15.9155 0 0 1 0 31.831
                                    a 15.9155 15.9155 0 0 1 0 -31.831"
                                fill="none"
                                stroke-width="2"
                                stroke-dasharray="<?php echo $percentage; ?>, 100"
                            />
                        </svg>
                        <div class="absolute inset-0 flex items-center justify-center">
                            <span class="text-2xl font-bold"><?php echo $percentage; ?>%</span>
                        </div>
                    </div>
                    
                    <div class="mt-4 text-center">
                        <h2 class="text-xl font-bold <?php echo $is_passed ? 'text-green-600' : 'text-red-600'; ?>">
                            <?php echo $is_passed ? 'Экзамен сдан!' : 'Экзамен не сдан'; ?>
                        </h2>
                        <p class="text-gray-600 mt-1">
                            Проходной балл: <?php echo $attempt['passing_score']; ?>%
                        </p>
                    </div>
                </div>

                <!-- Детали результатов -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="font-medium text-gray-800 mb-3 flex items-center">
                            <i data-feather="bar-chart-2" class="h-5 w-5 mr-2"></i>Результаты
                        </h3>
                        <div class="space-y-2">
                            <p><span class="font-medium">Правильные ответы:</span> <?php echo $attempt['score']; ?> из <?php echo $attempt['total_questions']; ?></p>
                            <p><span class="font-medium">Набрано баллов:</span> <?php echo $percentage; ?>%</p>
                            <p><span class="font-medium">Проходной балл:</span> <?php echo $attempt['passing_score']; ?>%</p>
                            <p><span class="font-medium">Статус:</span> 
                                <span class="<?php echo $is_passed ? 'text-green-600 font-medium' : 'text-red-600'; ?>">
                                    <?php echo $is_passed ? 'Сдан' : 'Не сдан'; ?>
                                </span>
                            </p>
                        </div>
                    </div>

                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="font-medium text-gray-800 mb-3 flex items-center">
                            <i data-feather="clock" class="h-5 w-5 mr-2"></i>Время
                        </h3>
                        <div class="space-y-2">
                            <p><span class="font-medium">Начало:</span> <?php echo date('d.m.Y H:i:s', strtotime($attempt['attempted_at'])); ?></p>
                            <?php if (!empty($attempt['finished_at'])): ?>
                                <p><span class="font-medium">Завершение:</span> <?php echo date('d.m.Y H:i:s', strtotime($attempt['finished_at'])); ?></p>
                                <?php
                                $start = new DateTime($attempt['attempted_at']);
                                $end = new DateTime($attempt['finished_at']);
                                $duration = $start->diff($end);
                                ?>
                                <p><span class="font-medium">Затраченное время:</span> 
                                    <?php echo $duration->h . ' ч. ' . $duration->i . ' мин. ' . $duration->s . ' сек.'; ?>
                                </p>
                            <?php else: ?>
                                <p><span class="font-medium">Завершение:</span> Не завершено</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Фото верификации -->
                <?php if (!empty($attempt['verification_photo'])): ?>
                <div class="mb-8">
                    <h3 class="text-lg font-medium mb-3 flex items-center">
                        <i data-feather="camera" class="h-5 w-5 mr-2"></i>Фото верификации
                    </h3>
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <img src="<?php echo htmlspecialchars($attempt['verification_photo']); ?>" alt="Фото верификации" class="max-w-full h-auto rounded-lg mx-auto max-h-64">
                        <p class="text-sm text-gray-600 mt-2 text-center">Фото с паспортом, сделанное во время экзамена</p>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Действия -->
                <div class="flex flex-col sm:flex-row gap-4 justify-center mt-8">
                    <?php if ($is_passed): ?>
                        <a href="learning.php?course_id=<?php echo $attempt['course_id']; ?>" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium flex items-center justify-center">
                            <i data-feather="check-circle" class="h-5 w-5 mr-2"></i> Перейти к курсу
                        </a>
                    <?php else: ?>
                        <a href="exam.php?course_id=<?php echo $attempt['course_id']; ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium flex items-center justify-center">
                            <i data-feather="refresh-cw" class="h-5 w-5 mr-2"></i> Попробовать снова
                        </a>
                    <?php endif; ?>
                    
                    <a href="courses.php" class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium flex items-center justify-center">
                        <i data-feather="book-open" class="h-5 w-5 mr-2"></i> Все курсы
                    </a>
                </div>

                <!-- Сертификат (если экзамен сдан) -->
                <?php if ($is_passed): ?>
                <div class="mt-8 text-center">
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 inline-flex items-center">
                        <i data-feather="award" class="h-6 w-6 text-yellow-600 mr-2"></i>
                        <span class="text-yellow-800">Поздравляем! Вы успешно сдали экзамен и можете получить сертификат.</span>
                    </div>
                    <div class="mt-4">
                        <a href="generate_certificate.php?attempt_id=<?php echo $attempt_id; ?>" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                            <i data-feather="download" class="h-4 w-4 mr-1"></i> Скачать сертификат
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Информация о следующем экзамене (если есть) -->
        <?php if ($is_passed): ?>
        <div class="mt-6 bg-white shadow rounded-lg p-6">
            <h2 class="text-xl font-bold mb-4">Что дальше?</h2>
            <p class="text-gray-600 mb-4">Вы успешно завершили этот курс. Вот что вы можете сделать дальше:</p>
            <ul class="list-disc list-inside space-y-2 text-gray-600">
                <li>Изучить другие курсы в нашем каталоге</li>
                <li>Поделиться своим достижением в социальных сетях</li>
                <li>Добавить сертификат в свое портфолио</li>
                <li>Оставить отзыв о курсе</li>
            </ul>
            <div class="mt-4">
                <a href="courses.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium inline-flex items-center">
                    <i data-feather="book-open" class="h-4 w-4 mr-2"></i> Найти другие курсы
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        feather.replace();
        
        // Анимация прогресс-бара
        document.addEventListener('DOMContentLoaded', function() {
            const progressRing = document.querySelector('.progress-ring');
            if (progressRing) {
                // Задержка для запуска анимации после загрузки страницы
                setTimeout(() => {
                    progressRing.style.transition = 'stroke-dashoffset 1s ease-in-out';
                }, 100);
            }
        });
    </script>
</body>
</html>