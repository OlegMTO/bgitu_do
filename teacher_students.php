<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'config/database.php';
require_once 'config/security.php';

// --- Требуем роль teacher ---
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
    header('Location: login.php');
    exit;
}

$database   = new Database();
$db         = $database->getConnection();
$teacher_id = (int)$_SESSION['user_id'];
$teacher_name = $_SESSION['user_name'] ?? 'Преподаватель';

$flash_success = [];
$flash_error   = [];

function flash_ok($msg){ global $flash_success; $flash_success[] = $msg; }
function flash_bad($msg){ global $flash_error;   $flash_error[]   = $msg; }

// Утилита: проверка, что курс принадлежит преподавателю
function assert_owns_course(PDO $db, int $course_id, int $teacher_id): bool {
    $q = "SELECT COUNT(*) FROM courses WHERE id = :cid AND teacher_id = :tid";
    $st = $db->prepare($q);
    $st->execute([':cid'=>$course_id, ':tid'=>$teacher_id]);
    return (int)$st->fetchColumn() > 0;
}

// Утилита: аккуратный sanitize
function s($v){ return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }

// === Обработка действий ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        flash_bad('Ошибка безопасности. Обновите страницу и попробуйте снова.');
    } else {
        $action = $_POST['action'] ?? '';

        try {
            // --- Добавление студента на курс по email ---
            if ($action === 'enroll_student') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $email     = trim($_POST['student_email'] ?? '');

                if (!$course_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс не найден или доступ запрещён.');
                } elseif (!isValidEmail($email)) {
                    flash_bad('Введите корректный email студента.');
                } else {
                    // Найдём пользователя
                    $q = "SELECT id, role FROM users WHERE email = :email LIMIT 1";
                    $st = $db->prepare($q);
                    $st->execute([':email'=>$email]);
                    $u = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$u) {
                        flash_bad('Пользователь с таким email не найден.');
                    } else {
                        $student_id = (int)$u['id'];
                        // Проверим, не записан ли уже
                        $q = "SELECT COUNT(*) FROM enrollments WHERE course_id = :cid AND user_id = :uid";
                        $st = $db->prepare($q);
                        $st->execute([':cid'=>$course_id, ':uid'=>$student_id]);
                        if ((int)$st->fetchColumn() > 0) {
                            flash_bad('Студент уже записан на курс.');
                        } else {
                            $q = "INSERT INTO enrollments (course_id, user_id, created_at) VALUES (:cid, :uid, NOW())";
                            $st = $db->prepare($q);
                            $st->execute([':cid'=>$course_id, ':uid'=>$student_id]);
                            flash_ok('Студент добавлен в курс.');
                        }
                    }
                }
            }

            // --- Удаление студента из курса ---
            if ($action === 'remove_student') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $user_id   = (int)($_POST['user_id'] ?? 0);

                if (!$course_id || !$user_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс/студент не найден или доступ запрещён.');
                } else {
                    $q = "DELETE FROM enrollments WHERE course_id = :cid AND user_id = :uid";
                    $st = $db->prepare($q);
                    $st->execute([':cid'=>$course_id, ':uid'=>$user_id]);
                    flash_ok('Студент удалён из курса.');
                }
            }

            // --- Рассылка студентам ---
            if ($action === 'send_email_to_students') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $subject   = trim(sanitizeInput($_POST['email_subject'] ?? ''));
                $message   = trim(sanitizeInput($_POST['email_message'] ?? ''));

                if (!$course_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс не найден или доступ запрещён.');
                } elseif ($subject === '' || $message === '') {
                    flash_bad('Заполните тему и сообщение для рассылки.');
                } else {
                    // Получаем email всех студентов курса
                    $q = "SELECT u.email FROM enrollments e 
                           JOIN users u ON e.user_id = u.id 
                           WHERE e.course_id = :cid";
                    $st = $db->prepare($q);
                    $st->execute([':cid'=>$course_id]);
                    $emails = $st->fetchAll(PDO::FETCH_COLUMN);
                    
                    if (empty($emails)) {
                        flash_bad('На курсе нет студентов для рассылки.');
                    } else {
                        // В реальном приложении здесь был бы код отправки email
                        // Для примера просто сохраним информацию о рассылке
                        $q = "INSERT INTO email_notifications (course_id, subject, message, sent_at) 
                              VALUES (:cid, :subject, :message, NOW())";
                        $st = $db->prepare($q);
                        $st->execute([
                            ':cid' => $course_id,
                            ':subject' => $subject,
                            ':message' => $message
                        ]);
                        
                        flash_ok('Рассылка отправлена ' . count($emails) . ' студентам.');
                    }
                }
            }

            // --- Отправка сообщения конкретному студенту ---
            if ($action === 'message_student') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $student_id = (int)($_POST['student_id'] ?? 0);
                $message   = trim(sanitizeInput($_POST['personal_message'] ?? ''));
                
                if (!$course_id || !$student_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Ошибка доступа.');
                } elseif ($message === '') {
                    flash_bad('Введите сообщение для студента.');
                } else {
                    // Получаем email студента
                    $q = "SELECT email FROM users WHERE id = :sid";
                    $st = $db->prepare($q);
                    $st->execute([':sid'=>$student_id]);
                    $student_email = $st->fetchColumn();
                    
                    if ($student_email) {
                        // Здесь должен быть код отправки email
                        // Для примера просто сохраним в базе
                        $q = "INSERT INTO teacher_messages (teacher_id, student_id, course_id, message, sent_at) 
                              VALUES (:tid, :sid, :cid, :msg, NOW())";
                        $st = $db->prepare($q);
                        $st->execute([
                            ':tid' => $teacher_id,
                            ':sid' => $student_id,
                            ':cid' => $course_id,
                            ':msg' => $message
                        ]);
                        
                        flash_ok('Сообщение отправлено студенту.');
                    } else {
                        flash_bad('Студент не найден.');
                    }
                }
            }

        } catch (PDOException $e) {
            flash_bad('Ошибка БД: ' . $e->getMessage());
        }
    }
}

// === Данные для шапки/статистики ===
$stats = ['courses'=>0,'modules'=>0,'students'=>0,'assignments'=>0];
try {
    // Кол-во курсов
    $st = $db->prepare("SELECT COUNT(*) FROM courses WHERE teacher_id = :tid");
    $st->execute([':tid'=>$teacher_id]);
    $stats['courses'] = (int)$st->fetchColumn();

    // Кол-во модулей
    $st = $db->prepare("
        SELECT COUNT(*) 
        FROM course_modules 
        WHERE course_id IN (SELECT id FROM courses WHERE teacher_id = :tid)
    ");
    $st->execute([':tid'=>$teacher_id]);
    $stats['modules'] = (int)$st->fetchColumn();

    // Кол-во студентов (уникальных) на курсах преподавателя
    $st = $db->prepare("
        SELECT COUNT(DISTINCT e.user_id)
        FROM enrollments e
        JOIN courses c ON c.id = e.course_id
        WHERE c.teacher_id = :tid
    ");
    $st->execute([':tid'=>$teacher_id]);
    $stats['students'] = (int)$st->fetchColumn();

    // Кол-во заданий на проверку
    $st = $db->prepare("
        SELECT COUNT(*)
        FROM assignments a
        JOIN courses c ON c.id = a.course_id
        WHERE c.teacher_id = :tid AND a.grade IS NULL
    ");
    $st->execute([':tid'=>$teacher_id]);
    $stats['assignments'] = (int)$st->fetchColumn();

} catch (PDOException $e) {
    error_log("Stats error: " . $e->getMessage());
}

// === Списки для главной: последние курсы ===
$courses = [];
try {
    $st = $db->prepare("
        SELECT id, title, category, created_at 
        FROM courses 
        WHERE teacher_id = :tid 
        ORDER BY created_at DESC 
        LIMIT 8
    ");
    $st->execute([':tid'=>$teacher_id]);
    $courses = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Courses error: " . $e->getMessage());
}

// === Детальный режим по курсу (управление студентами) ===
$course_view = null;
$course_students = [];
$student_details = null;

if (isset($_GET['course_id'])) {
    $cid = (int)$_GET['course_id'];
    if ($cid && assert_owns_course($db, $cid, $teacher_id)) {
        // Инфо по курсу
        $st = $db->prepare("SELECT * FROM courses WHERE id = :cid LIMIT 1");
        $st->execute([':cid'=>$cid]);
        $course_view = $st->fetch(PDO::FETCH_ASSOC);

        // Студенты курса с дополнительной информацией
        $st = $db->prepare("
            SELECT 
                u.id, 
                u.first_name, 
                u.last_name, 
                u.email,
                u.group_name,
                e.progress,
                e.enrolled_at,
                (SELECT COUNT(*) FROM assignments a WHERE a.user_id = u.id AND a.course_id = :cid AND a.grade IS NOT NULL) as assignments_done,
                (SELECT COUNT(*) FROM assignments a WHERE a.user_id = u.id AND a.course_id = :cid) as assignments_total,
                (SELECT MAX(submitted_at) FROM assignments a WHERE a.user_id = u.id AND a.course_id = :cid) as last_activity
            FROM enrollments e
            JOIN users u ON u.id = e.user_id
            WHERE e.course_id = :cid
            ORDER BY u.last_name, u.first_name
        ");
        $st->execute([':cid'=>$cid]);
        $course_students = $st->fetchAll(PDO::FETCH_ASSOC);
        
        // Если запрошена детальная информация о студенте
        if (isset($_GET['student_id'])) {
            $student_id = (int)$_GET['student_id'];
            $st = $db->prepare("
                SELECT 
                    u.*,
                    e.progress,
                    e.enrolled_at,
                    e.completed,
                    e.completed_at,
                    e.grade as course_grade
                FROM enrollments e
                JOIN users u ON u.id = e.user_id
                WHERE e.course_id = :cid AND e.user_id = :sid
            ");
            $st->execute([':cid'=>$cid, ':sid'=>$student_id]);
            $student_details = $st->fetch(PDO::FETCH_ASSOC);
            
            if ($student_details) {
// Получаем задания студента
$st = $db->prepare("
    SELECT 
        a.*,
        NULL as module_title
    FROM assignments a
    WHERE a.course_id = :cid AND a.user_id = :sid
    ORDER BY a.submitted_at DESC
");
$st->execute([':cid'=>$cid, ':sid'=>$student_id]);
$student_assignments = $st->fetchAll(PDO::FETCH_ASSOC);
                // Получаем активность студента
                $st = $db->prepare("
                    (SELECT 'quiz' as type, score, submitted_at as date, title 
                     FROM quiz_attempts qa 
                     JOIN module_quizzes mq ON qa.quiz_id = mq.id 
                     WHERE qa.user_id = :sid AND EXISTS 
                       (SELECT 1 FROM course_modules cm WHERE cm.id = mq.module_id AND cm.course_id = :cid))
                    UNION
                    (SELECT 'material' as type, NULL as score, completed_at as date, title 
                     FROM material_progress mp 
                     JOIN module_materials mm ON mp.material_id = mm.id 
                     WHERE mp.user_id = :sid AND EXISTS 
                       (SELECT 1 FROM course_modules cm WHERE cm.id = mm.module_id AND cm.course_id = :cid))
                    ORDER BY date DESC
                    LIMIT 20
                ");
                $st->execute([':sid'=>$student_id, ':cid'=>$cid]);
                $student_activity = $st->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление студентами - Панель преподавателя - БГИТУ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        .progress-bar {
            height: 8px;
            border-radius: 4px;
            background-color: #e5e7eb;
        }
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            background-color: #10b981;
            transition: width 0.3s ease;
        }
        .activity-item {
            border-left: 3px solid #3b82f6;
            padding-left: 1rem;
            margin-bottom: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Навбар -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 flex justify-between h-16 items-center">
            <div class="flex items-center">
                <i data-feather="book-open" class="h-7 w-7 text-blue-700"></i>
                <span class="ml-2 text-xl font-bold">БГИТУ — Преподаватель</span>
            </div>
            <div class="flex items-center gap-4">
                <a href="index.php" class="hover:text-blue-700">На сайт</a>
                <a href="teacher_courses.php" class="hover:text-blue-700">Управление курсами</a>
                <a href="logout.php" class="hover:text-blue-700">Выйти</a>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 px-4">

        <!-- Флеш-сообщения -->
        <?php foreach ($flash_success as $m): ?>
            <div class="mb-3 rounded bg-green-100 text-green-800 px-4 py-2"><?=s($m)?></div>
        <?php endforeach; ?>
        <?php foreach ($flash_error as $m): ?>
            <div class="mb-3 rounded bg-red-100 text-red-800 px-4 py-2"><?=s($m)?></div>
        <?php endforeach; ?>

        <!-- Приветствие -->
        <div class="bg-blue-700 text-white rounded-lg p-6 mb-6 shadow">
            <h1 class="text-2xl font-bold">Добро пожаловать, <?=s($teacher_name)?>!</h1>
            <p class="text-blue-200">Управляйте студентами и рассылками.</p>
        </div>

        <!-- Статистика -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white p-4 rounded shadow flex items-center">
                <i data-feather="book" class="h-6 w-6 text-blue-600 mr-3"></i>
                <div>
                    <p class="text-sm text-gray-500">Курсы</p>
                    <p class="text-xl font-bold"><?=$stats['courses']?></p>
                </div>
            </div>
            <div class="bg-white p-4 rounded shadow flex items-center">
                <i data-feather="users" class="h-6 w-6 text-green-600 mr-3"></i>
                <div>
                    <p class="text-sm text-gray-500">Студенты</p>
                    <p class="text-xl font-bold"><?=$stats['students']?></p>
                </div>
            </div>
            <div class="bg-white p-4 rounded shadow flex items-center">
                <i data-feather="clipboard" class="h-6 w-6 text-yellow-600 mr-3"></i>
                <div>
                    <p class="text-sm text-gray-500">Задания</p>
                    <p class="text-xl font-bold"><?=$stats['assignments']?></p>
                </div>
            </div>
            <div class="bg-white p-4 rounded shadow flex items-center">
                <i data-feather="layers" class="h-6 w-6 text-purple-600 mr-3"></i>
                <div>
                    <p class="text-sm text-gray-500">Модули</p>
                    <p class="text-xl font-bold"><?=$stats['modules']?></p>
                </div>
            </div>
        </div>

        <?php if ($course_view): ?>
            <!-- Режим: Управление конкретным курсом -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Инфо о курсе -->
                <div class="lg:col-span-1">
                    <div class="bg-white p-6 rounded shadow">
                        <h2 class="text-xl font-semibold">Курс</h2>
                        <p class="text-lg font-medium mb-1"><?=s($course_view['title'])?></p>
                        <p class="text-sm text-gray-500 mb-2"><?=s($course_view['category'])?></p>
                        <p class="text-gray-700 whitespace-pre-wrap"><?=nl2br(s($course_view['description']))?></p>
                    </div>

                    <!-- Добавить студента -->
                    <div class="bg-white p-6 rounded shadow mt-6">
                        <h3 class="font-semibold mb-3">Добавить студента</h3>
                        <form method="post" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                            <input type="hidden" name="action" value="enroll_student">
                            <input type="hidden" name="course_id" value="<?=$course_view['id']?>">
                            <div>
                                <label class="block text-sm font-medium mb-1">Email студента</label>
                                <input type="email" name="student_email" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                                Добавить
                            </button>
                        </form>
                    </div>

                    <!-- Рассылка студентам -->
                    <div class="bg-white p-6 rounded shadow mt-6">
                        <h3 class="font-semibold mb-3">Рассылка студентам</h3>
                        <form method="post" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                            <input type="hidden" name="action" value="send_email_to_students">
                            <input type="hidden" name="course_id" value="<?=$course_view['id']?>">
                            <div>
                                <label class="block text-sm font-medium mb-1">Тема письма</label>
                                <input type="text" name="email_subject" class="w-full border rounded px-3 py-2" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Сообщение</label>
                                <textarea name="email_message" rows="4" class="w-full border rounded px-3 py-2" required></textarea>
                            </div>
                            <button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">
                                Отправить
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Студенты курса -->
                <div class="lg:col-span-2">
                    <?php if ($student_details): ?>
                        <!-- Детальная информация о студенте -->
                        <div class="bg-white p-6 rounded shadow mb-6">
                            <div class="flex justify-between items-start mb-6">
                                <div>
                                    <h3 class="text-lg font-semibold"><?=s($student_details['first_name'])?> <?=s($student_details['last_name'])?></h3>
                                    <p class="text-gray-600"><?=s($student_details['email'])?></p>
                                    <?php if ($student_details['group_name']): ?>
                                        <p class="text-gray-600">Группа: <?=s($student_details['group_name'])?></p>
                                    <?php endif; ?>
                                </div>
                                <a href="teacher_students.php?course_id=<?=$course_view['id']?>" class="text-blue-600 hover:text-blue-800">
                                    ← Назад к списку
                                </a>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                                <div class="bg-gray-50 p-4 rounded">
                                    <h4 class="font-medium mb-2">Общая информация</h4>
                                    <p>Зачислен: <?=date('d.m.Y', strtotime($student_details['enrolled_at']))?></p>
                                    <p>Прогресс: <?=s($student_details['progress'])?>%</p>
                                    <?php if ($student_details['completed']): ?>
                                        <p>Завершил курс: <?=date('d.m.Y', strtotime($student_details['completed_at']))?></p>
                                        <p>Оценка: <?=s($student_details['course_grade'])?></p>
                                    <?php endif; ?>
                                </div>

                                <div class="bg-gray-50 p-4 rounded">
                                    <h4 class="font-medium mb-2">Отправить сообщение</h4>
                                    <form method="post">
                                        <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                                        <input type="hidden" name="action" value="message_student">
                                        <input type="hidden" name="course_id" value="<?=$course_view['id']?>">
                                        <input type="hidden" name="student_id" value="<?=$student_details['id']?>">
                                        <div class="mb-2">
                                            <textarea name="personal_message" rows="3" class="w-full border rounded px-3 py-2" placeholder="Ваше сообщение..." required></textarea>
                                        </div>
                                        <button class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                            Отправить
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <h4 class="font-medium mb-3">Задания и работы</h4>
                            <div class="mb-6">
                                <?php if (!empty($student_assignments)): ?>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="text-left p-2 border">Задание</th>
                                                    <th class="text-left p-2 border">Модуль</th>
                                                    <th class="text-left p-2 border">Дата сдачи</th>
                                                    <th class="text-left p-2 border">Оценка</th>
                                                    <th class="text-left p-2 border">Файлы</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($student_assignments as $assignment): ?>
                                                    <tr class="border-b">
                                                        <td class="p-2"><?=s($assignment['title'])?></td>
                                                        <td class="p-2">—</td>
                                                        <td class="p-2"><?=date('d.m.Y H:i', strtotime($assignment['submitted_at']))?></td>
                                                        <td class="p-2">
                                                            <?php if ($assignment['grade'] !== null): ?>
                                                                <?=s($assignment['grade'])?>
                                                            <?php else: ?>
                                                                <span class="text-yellow-600">На проверке</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="p-2">
                                                            <?php if ($assignment['file_path']): ?>
                                                                <a href="<?=s($assignment['file_path'])?>" class="text-blue-600 hover:underline" target="_blank">
                                                                    <i data-feather="download" class="w-4 h-4 inline"></i> Скачать
                                                                </a>
                                                            <?php else: ?>
                                                                —
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500">Студент еще не сдавал задания.</p>
                                <?php endif; ?>
                            </div>

                            <h4 class="font-medium mb-3">Активность</h4>
                            <div>
                                <?php if (!empty($student_activity)): ?>
                                    <div class="space-y-2">
                                        <?php foreach ($student_activity as $activity): ?>
                                            <div class="activity-item">
                                                <p class="text-sm">
                                                    <?php if ($activity['type'] == 'quiz'): ?>
                                                        <span class="font-medium">Тест:</span> <?=s($activity['title'])?>
                                                        <span class="text-gray-600">(Оценка: <?=s($activity['score'])?>)</span>
                                                    <?php else: ?>
                                                        <span class="font-medium">Изучен материал:</span> <?=s($activity['title'])?>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="text-xs text-gray-500"><?=date('d.m.Y H:i', strtotime($activity['date']))?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-gray-500">Активность не обнаружена.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Список студентов -->
                        <div class="bg-white p-6 rounded shadow">
                            <h3 class="text-lg font-semibold mb-3 flex items-center">
                                <i data-feather="users" class="w-5 h-5 mr-2 text-green-600"></i> Студенты курса
                            </h3>
                            <?php if ($course_students): ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50">
                                        <tr>
                                            <th class="text-left p-2 border">ФИО</th>
                                            <th class="text-left p-2 border">Email</th>
                                            <th class="text-left p-2 border">Группа</th>
                                            <th class="text-left p-2 border">Прогресс</th>
                                            <th class="text-left p-2 border">Заданий</th>
                                            <th class="text-left p-2 border">Активность</th>
                                            <th class="p-2 border">Действия</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($course_students as $st): ?>
                                            <tr class="border-b">
                                                <td class="p-2">
                                                    <a href="teacher_students.php?course_id=<?=$course_view['id']?>&student_id=<?=$st['id']?>" class="text-blue-600 hover:underline">
                                                        <?=s($st['last_name'])?> <?=s($st['first_name'])?>
                                                    </a>
                                                </td>
                                                <td class="p-2"><?=s($st['email'])?></td>
                                                <td class="p-2"><?=s($st['group_name'] ?? '—')?></td>
                                                <td class="p-2">
                                                    <div class="flex items-center">
                                                        <span class="mr-2"><?=s($st['progress'])?>%</span>
                                                        <div class="progress-bar w-16">
                                                            <div class="progress-fill" style="width: <?=s($st['progress'])?>%"></div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="p-2"><?=s($st['assignments_done'])?>/<?=s($st['assignments_total'])?></td>
                                                <td class="p-2">
                                                    <?php if ($st['last_activity']): ?>
                                                        <?=date('d.m.Y', strtotime($st['last_activity']))?>
                                                    <?php else: ?>
                                                        —
                                                    <?php endif; ?>
                                                </td>
                                                <td class="p-2 text-center">
                                                    <div class="flex justify-center space-x-2">
                                                        <a href="teacher_students.php?course_id=<?=$course_view['id']?>&student_id=<?=$st['id']?>" class="text-blue-600 hover:text-blue-800" title="Подробнее">
                                                            <i data-feather="eye" class="w-4 h-4"></i>
                                                        </a>
                                                        <form method="post" onsubmit="return confirm('Удалить студента из курса?')" class="inline">
                                                            <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                                                            <input type="hidden" name="action" value="remove_student">
                                                            <input type="hidden" name="course_id" value="<?=$course_view['id']?>">
                                                            <input type="hidden" name="user_id" value="<?=$st['id']?>">
                                                            <button type="submit" class="text-red-600 hover:text-red-800" title="Удалить">
                                                                <i data-feather="trash-2" class="w-4 h-4"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-gray-500">Студентов пока нет.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-8">
                <a href="teacher_students.php" class="text-blue-600 hover:text-blue-800">&larr; Назад к списку курсов</a>
            </div>

        <?php else: ?>
            <!-- Главная страница без выбранного курса -->
            <div class="grid grid-cols-1 gap-6">
                <!-- Мои курсы -->
                <div>
                    <div class="bg-white p-6 rounded shadow">
                        <h2 class="text-lg font-semibold mb-3">Мои курсы</h2>
                        <?php if ($courses): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($courses as $c): ?>
                                    <div class="border rounded p-4 hover:shadow-md transition-shadow">
                                        <h3 class="font-medium text-lg"><?=s($c['title'])?></h3>
                                        <p class="text-sm text-gray-500 mb-2"><?=s($c['category'])?></p>
                                        <p class="text-xs text-gray-400">Создан: <?=date('d.m.Y', strtotime($c['created_at']))?></p>
                                        <div class="mt-3">
                                            <a href="teacher_students.php?course_id=<?=$c['id']?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                Управление студентами →
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i data-feather="book-open" class="w-12 h-12 text-gray-400 mx-auto"></i>
                                <p class="text-gray-500 mt-2">У вас пока нет созданных курсов</p>
                                <p class="text-sm text-gray-400">Создайте первый курс в разделе "Управление курсами"</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>