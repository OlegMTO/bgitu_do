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

// Список допустимых категорий курсов
$allowed_categories = [
    'Программирование',
    'Дизайн',
    'Маркетинг',
    'Аналитика',
    'Управление',
    'Иностранные языки'
];

// Утилита: проверка, что курс принадлежит преподавателю
function assert_owns_course(PDO $db, int $course_id, int $teacher_id): bool {
    $q = "SELECT COUNT(*) FROM courses WHERE id = :cid AND teacher_id = :tid";
    $st = $db->prepare($q);
    $st->execute([':cid'=>$course_id, ':tid'=>$teacher_id]);
    return (int)$st->fetchColumn() > 0;
}

// Утилита: проверка, что модуль принадлежит курсу преподавателя
function assert_owns_module(PDO $db, int $module_id, int $teacher_id): bool {
    $q = "SELECT COUNT(*) FROM course_modules cm 
          JOIN courses c ON cm.course_id = c.id 
          WHERE cm.id = :mid AND c.teacher_id = :tid";
    $st = $db->prepare($q);
    $st->execute([':mid'=>$module_id, ':tid'=>$teacher_id]);
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
            // --- Создание курса ---
            if ($action === 'create_course') {
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $category = trim(sanitizeInput($_POST['category'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));

                if ($title === '' || mb_strlen($title) < 3) {
                    flash_bad('Название курса слишком короткое.');
                } elseif (!in_array($category, $allowed_categories)) {
                    flash_bad('Выберите допустимую категорию из списка.');
                } else {
                    // Обработка загрузки изображения
                    $image_url = null;
                    if (isset($_FILES['course_image']) && $_FILES['course_image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/courses/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['course_image']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['course_image']['tmp_name'], $target_path)) {
                            $image_url = $target_path;
                        } else {
                            flash_bad('Ошибка загрузки изображения.');
                        }
                    }
                    
                    $q = "INSERT INTO courses (teacher_id, title, category, description, image_url, created_at, updated_at)
                          VALUES (:tid, :title, :cat, :descr, :image_url, NOW(), NOW())";
                    $st = $db->prepare($q);
                    $st->execute([
                        ':tid'=>$teacher_id,
                        ':title'=>$title,
                        ':cat'=>$category,
                        ':descr'=>$description,
                        ':image_url'=>$image_url
                    ]);
                    flash_ok('Курс создан успешно.');
                }
            }

            // --- Обновление курса ---
            if ($action === 'update_course') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $category = trim(sanitizeInput($_POST['category'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));

                if (!$course_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс не найден или доступ запрещён.');
                } elseif ($title === '' || mb_strlen($title) < 3) {
                    flash_bad('Название курса слишком короткое.');
                } elseif (!in_array($category, $allowed_categories)) {
                    flash_bad('Выберите допустимую категорию из списка.');
                } else {
                    // Обработка загрузки изображения
                    $image_url = null;
                    if (isset($_FILES['course_image']) && $_FILES['course_image']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/courses/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['course_image']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['course_image']['tmp_name'], $target_path)) {
                            $image_url = $target_path;
                            
                            // Удаляем старое изображение, если оно есть
                            $st = $db->prepare("SELECT image_url FROM courses WHERE id = :cid");
                            $st->execute([':cid' => $course_id]);
                            $old_image = $st->fetchColumn();
                            if ($old_image && file_exists($old_image)) {
                                unlink($old_image);
                            }
                        } else {
                            flash_bad('Ошибка загрузки изображения.');
                        }
                    }
                    
                    if ($image_url) {
                        $q = "UPDATE courses SET title = :title, category = :cat, description = :descr, image_url = :image_url, updated_at = NOW() WHERE id = :cid";
                        $params = [
                            ':cid'=>$course_id,
                            ':title'=>$title,
                            ':cat'=>$category,
                            ':descr'=>$description,
                            ':image_url'=>$image_url
                        ];
                    } else {
                        $q = "UPDATE courses SET title = :title, category = :cat, description = :descr, updated_at = NOW() WHERE id = :cid";
                        $params = [
                            ':cid'=>$course_id,
                            ':title'=>$title,
                            ':cat'=>$category,
                            ':descr'=>$description
                        ];
                    }
                    
                    $st = $db->prepare($q);
                    $st->execute($params);
                    flash_ok('Курс обновлен успешно.');
                }
            }

            // --- Создание модуля ---
            if ($action === 'create_module') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));
                $order_index = (int)($_POST['order_index'] ?? 0);

                if (!$course_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс не найден или доступ запрещён.');
                } elseif ($title === '') {
                    flash_bad('Введите название модуля.');
                } else {
                    $q = "INSERT INTO course_modules (course_id, title, description, order_index, created_at) 
                          VALUES (:cid, :title, :description, :order_index, NOW())";
                    $st = $db->prepare($q);
                    $st->execute([
                        ':cid' => $course_id,
                        ':title' => $title,
                        ':description' => $description,
                        ':order_index' => $order_index
                    ]);
                    flash_ok('Модуль создан успешно.');
                }
            }

            // --- Обновление модуля ---
            if ($action === 'update_module') {
                $module_id = (int)($_POST['module_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));
                $order_index = (int)($_POST['order_index'] ?? 0);

                if (!$module_id || !assert_owns_module($db, $module_id, $teacher_id)) {
                    flash_bad('Модуль не найден или доступ запрещён.');
                } elseif ($title === '') {
                    flash_bad('Введите название модуля.');
                } else {
                    $q = "UPDATE course_modules SET title = :title, description = :description, order_index = :order_index WHERE id = :mid";
                    $st = $db->prepare($q);
                    $st->execute([
                        ':mid' => $module_id,
                        ':title' => $title,
                        ':description' => $description,
                        ':order_index' => $order_index
                    ]);
                    flash_ok('Модуль обновлен успешно.');
                }
            }

            // --- Создание материала модуля ---
            if ($action === 'create_material') {
                $module_id = (int)($_POST['module_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $type = trim(sanitizeInput($_POST['type'] ?? ''));
                $content = trim(sanitizeInput($_POST['content'] ?? ''));
                $order_index = (int)($_POST['order_index'] ?? 0);
                $file_path = null;

                if (!$module_id || !assert_owns_module($db, $module_id, $teacher_id)) {
                    flash_bad('Модуль не найден или доступ запрещён.');
                } elseif ($title === '' || $type === '') {
                    flash_bad('Заполните обязательные поля.');
                } else {
                    // Обработка загрузки файла
                    if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/materials/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['material_file']['tmp_name'], $target_path)) {
                            $file_path = $target_path;
                        } else {
                            flash_bad('Ошибка загрузки файла.');
                        }
                    }
                    
                    $q = "INSERT INTO module_materials (module_id, title, type, content, file_path, order_index, created_at) 
                          VALUES (:mid, :title, :type, :content, :file_path, :order_index, NOW())";
                    $st = $db->prepare($q);
                    $st->execute([
                        ':mid' => $module_id,
                        ':title' => $title,
                        ':type' => $type,
                        ':content' => $content,
                        ':file_path' => $file_path,
                        ':order_index' => $order_index
                    ]);
                    flash_ok('Материал добавлен успешно.');
                }
            }

            // --- Обновление материала модуля ---
            if ($action === 'update_material') {
                $material_id = (int)($_POST['material_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $type = trim(sanitizeInput($_POST['type'] ?? ''));
                $content = trim(sanitizeInput($_POST['content'] ?? ''));
                $order_index = (int)($_POST['order_index'] ?? 0);
                $file_path = null;

                // Проверяем, что материал принадлежит преподавателю
                $q = "SELECT COUNT(*) FROM module_materials mm
                      JOIN course_modules cm ON mm.module_id = cm.id
                      JOIN courses c ON cm.course_id = c.id
                      WHERE mm.id = :mid AND c.teacher_id = :tid";
                $st = $db->prepare($q);
                $st->execute([':mid' => $material_id, ':tid' => $teacher_id]);
                $owns_material = (int)$st->fetchColumn() > 0;

                if (!$owns_material) {
                    flash_bad('Материал не найден или доступ запрещён.');
                } elseif ($title === '' || $type === '') {
                    flash_bad('Заполните обязательные поля.');
                } else {
                    // Обработка загрузки файла
                    if (isset($_FILES['material_file']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = 'uploads/materials/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_extension = pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION);
                        $file_name = uniqid() . '.' . $file_extension;
                        $target_path = $upload_dir . $file_name;
                        
                        if (move_uploaded_file($_FILES['material_file']['tmp_name'], $target_path)) {
                            $file_path = $target_path;
                            
                            // Удаляем старый файл, если он есть
                            $st = $db->prepare("SELECT file_path FROM module_materials WHERE id = :mid");
                            $st->execute([':mid' => $material_id]);
                            $old_file = $st->fetchColumn();
                            if ($old_file && file_exists($old_file)) {
                                unlink($old_file);
                            }
                        } else {
                            flash_bad('Ошибка загрузки файла.');
                        }
                    }
                    
                    if ($file_path) {
                        $q = "UPDATE module_materials SET title = :title, type = :type, content = :content, file_path = :file_path, order_index = :order_index WHERE id = :mid";
                        $params = [
                            ':mid' => $material_id,
                            ':title' => $title,
                            ':type' => $type,
                            ':content' => $content,
                            ':file_path' => $file_path,
                            ':order_index' => $order_index
                        ];
                    } else {
                        $q = "UPDATE module_materials SET title = :title, type = :type, content = :content, order_index = :order_index WHERE id = :mid";
                        $params = [
                            ':mid' => $material_id,
                            ':title' => $title,
                            ':type' => $type,
                            ':content' => $content,
                            ':order_index' => $order_index
                        ];
                    }
                    
                    $st = $db->prepare($q);
                    $st->execute($params);
                    flash_ok('Материал обновлен успешно.');
                }
            }

            // --- Удаление материала ---
            if ($action === 'delete_material') {
                $material_id = (int)($_POST['material_id'] ?? 0);

                // Проверяем, что материал принадлежит преподавателю
                $q = "SELECT mm.file_path FROM module_materials mm
                      JOIN course_modules cm ON mm.module_id = cm.id
                      JOIN courses c ON cm.course_id = c.id
                      WHERE mm.id = :mid AND c.teacher_id = :tid";
                $st = $db->prepare($q);
                $st->execute([':mid' => $material_id, ':tid' => $teacher_id]);
                $material = $st->fetch(PDO::FETCH_ASSOC);

                if (!$material) {
                    flash_bad('Материал не найден или доступ запрещён.');
                } else {
                    // Удаляем файл, если он есть
                    if ($material['file_path'] && file_exists($material['file_path'])) {
                        unlink($material['file_path']);
                    }
                    
                    $q = "DELETE FROM module_materials WHERE id = :mid";
                    $st = $db->prepare($q);
                    $st->execute([':mid' => $material_id]);
                    flash_ok('Материал удален успешно.');
                }
            }

            // --- Создание теста модуля ---
            if ($action === 'create_module_quiz') {
                $module_id = (int)($_POST['module_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));
                $question_text = trim(sanitizeInput($_POST['question_text'] ?? ''));
                $question_type = trim(sanitizeInput($_POST['question_type'] ?? ''));
                $options = [];
                $correct_answer = '';

                if (!$module_id || !assert_owns_module($db, $module_id, $teacher_id)) {
                    flash_bad('Модуль не найден или доступ запрещён.');
                } elseif ($title === '' || $question_text === '' || $question_type === '') {
                    flash_bad('Заполните обязательные поля.');
                } else {
                    // Обработка вариантов ответов для multiple_choice
                    if ($question_type === 'multiple_choice') {
                        $correct_answer = (int)($_POST['correct_answer'] ?? 0);
                        
                        for ($i = 1; $i <= 4; $i++) {
                            $option = trim(sanitizeInput($_POST['option_' . $i] ?? ''));
                            if (!empty($option)) {
                                $options[] = $option;
                            }
                        }
                        
                        if (count($options) < 2) {
                            flash_bad('Добавьте хотя бы два варианта ответа');
                        } elseif ($correct_answer < 1 || $correct_answer > count($options)) {
                            flash_bad('Выберите правильный вариант ответа');
                        }
                    }
                    
                    if (empty($flash_error)) {
                        $q = "INSERT INTO module_quizzes (module_id, title, description, question_text, question_type, options, correct_answer, created_at) 
                              VALUES (:mid, :title, :description, :question_text, :question_type, :options, :correct_answer, NOW())";
                        $st = $db->prepare($q);
                        $st->execute([
                            ':mid' => $module_id,
                            ':title' => $title,
                            ':description' => $description,
                            ':question_text' => $question_text,
                            ':question_type' => $question_type,
                            ':options' => json_encode($options),
                            ':correct_answer' => $correct_answer
                        ]);
                        flash_ok('Тест модуля создан успешно.');
                    }
                }
            }

            // --- Создание итогового экзамена ---
            if ($action === 'create_final_exam') {
                $course_id = (int)($_POST['course_id'] ?? 0);
                $title = trim(sanitizeInput($_POST['title'] ?? ''));
                $description = trim(sanitizeInput($_POST['description'] ?? ''));
                $passing_score = (int)($_POST['passing_score'] ?? 60);
                $time_limit_minutes = (int)($_POST['time_limit_minutes'] ?? 60);
                $max_attempts = (int)($_POST['max_attempts'] ?? 3);
                $questions = [];

                if (!$course_id || !assert_owns_course($db, $course_id, $teacher_id)) {
                    flash_bad('Курс не найден или доступ запрещён.');
                } elseif ($title === '') {
                    flash_bad('Введите название экзамена.');
                } else {
                    // Обработка вопросов
                    $question_count = (int)($_POST['question_count'] ?? 0);
                    for ($i = 1; $i <= $question_count; $i++) {
                        if (!empty($_POST['question_text_' . $i])) {
                            $question = [
                                'text' => trim(sanitizeInput($_POST['question_text_' . $i])),
                                'type' => trim(sanitizeInput($_POST['question_type_' . $i])),
                                'options' => [],
                                'correct_answer' => trim(sanitizeInput($_POST['correct_answer_' . $i] ?? ''))
                            ];
                            
                            if ($question['type'] === 'multiple_choice') {
                                for ($j = 1; $j <= 4; $j++) {
                                    $option = trim(sanitizeInput($_POST['option_' . $i . '_' . $j] ?? ''));
                                    if (!empty($option)) {
                                        $question['options'][] = $option;
                                    }
                                }
                            }
                            
                            $questions[] = $question;
                        }
                    }
                    
                    if (count($questions) === 0) {
                        flash_bad('Добавьте хотя бы один вопрос');
                    }
                    
                    if (empty($flash_error)) {
                        $q = "INSERT INTO course_exams (course_id, title, description, questions, passing_score, time_limit_minutes, max_attempts, created_at) 
                              VALUES (:cid, :title, :description, :questions, :passing_score, :time_limit_minutes, :max_attempts, NOW())";
                        $st = $db->prepare($q);
                        $st->execute([
                            ':cid' => $course_id,
                            ':title' => $title,
                            ':description' => $description,
                            ':questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
                            ':passing_score' => $passing_score,
                            ':time_limit_minutes' => $time_limit_minutes,
                            ':max_attempts' => $max_attempts
                        ]);
                        flash_ok('Итоговый экзамен создан успешно.');
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
        SELECT id, title, category, created_at, image_url 
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

// === Детальный режим по курсу (управление модулями и тестами) ===
$course_view = null;
$course_modules = [];
$module_materials = [];
$module_quizzes = [];
$course_exam = null;

if (isset($_GET['course_id'])) {
    $cid = (int)$_GET['course_id'];
    if ($cid && assert_owns_course($db, $cid, $teacher_id)) {
        // Инфо по курсу
        $st = $db->prepare("SELECT * FROM courses WHERE id = :cid LIMIT 1");
        $st->execute([':cid'=>$cid]);
        $course_view = $st->fetch(PDO::FETCH_ASSOC);

        // Модули курса
        $st = $db->prepare("SELECT * FROM course_modules WHERE course_id = :cid ORDER BY order_index, title");
        $st->execute([':cid'=>$cid]);
        $course_modules = $st->fetchAll(PDO::FETCH_ASSOC);

        // Материалы модулей
        foreach ($course_modules as $module) {
            $st = $db->prepare("SELECT * FROM module_materials WHERE module_id = :mid ORDER BY order_index, title");
            $st->execute([':mid'=>$module['id']]);
            $module_materials[$module['id']] = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // Тесты модулей
        foreach ($course_modules as $module) {
            $st = $db->prepare("SELECT * FROM module_quizzes WHERE module_id = :mid ORDER BY created_at DESC");
            $st->execute([':mid'=>$module['id']]);
            $module_quizzes[$module['id']] = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        // Итоговый экзамен
        $st = $db->prepare("SELECT * FROM course_exams WHERE course_id = :cid LIMIT 1");
        $st->execute([':cid'=>$cid]);
        $course_exam = $st->fetch(PDO::FETCH_ASSOC);
    }
}

$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Управление курсами - Панель преподавателя - БГИТУ</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
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
                <a href="teacher_students.php" class="hover:text-blue-700">Управление студентами</a>
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
            <p class="text-blue-200">Управляйте курсами, модулями и тестами.</p>
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
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

                <!-- Инфо о курсе -->
                <div>
                    <div class="bg-white p-6 rounded shadow">
                        <div class="flex justify-between items-start mb-3">
                            <h2 class="text-xl font-semibold">Курс</h2>
                            <button onclick="openEditCourseModal(<?=htmlspecialchars(json_encode($course_view), ENT_QUOTES, 'UTF-8')?>)" class="text-blue-600 hover:text-blue-800 text-sm flex items-center">
                                <i data-feather="edit" class="w-4 h-4 mr-1"></i> Редактировать
                            </button>
                        </div>
                        
                        <?php if ($course_view['image_url']): ?>
                            <div class="mb-4">
                                <img src="<?=s($course_view['image_url'])?>" alt="Изображение курса" class="w-full h-48 object-cover rounded-lg">
                            </div>
                        <?php endif; ?>
                        
                        <p class="text-lg font-medium mb-1"><?=s($course_view['title'])?></p>
                        <p class="text-sm text-gray-500 mb-2"><?=s($course_view['category'])?></p>
                        <p class="text-gray-700 whitespace-pre-wrap"><?=nl2br(s($course_view['description']))?></p>
                    </div>
                </div>

                <!-- Модули курса -->
                <div class="space-y-6">
                    <div class="bg-white p-6 rounded shadow">
                        <h3 class="text-lg font-semibold mb-3 flex items-center">
                            <i data-feather="layers" class="w-5 h-5 mr-2 text-purple-600"></i> Модули курса
                        </h3>

                        <!-- Форма добавления модуля -->
                        <div class="mb-6 p-4 bg-gray-50 rounded-lg">
                            <h4 class="font-medium mb-3">Добавить новый модуль</h4>
                            <form method="post" class="space-y-3">
                                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                                <input type="hidden" name="action" value="create_module">
                                <input type="hidden" name="course_id" value="<?=$course_view['id']?>">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Название модуля</label>
                                        <input type="text" name="title" class="w-full border rounded px-3 py-2" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Порядковый номер</label>
                                        <input type="number" name="order_index" min="0" value="0" class="w-full border rounded px-3 py-2">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium mb-1">Описание модуля</label>
                                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2" placeholder="Краткое описание содержания модуля"></textarea>
                                </div>
                                
                                <button class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded">
                                    Добавить модуль
                                </button>
                            </form>
                        </div>

                        <?php if ($course_modules): ?>
                            <div class="space-y-4 mt-6">
                                <?php foreach ($course_modules as $module): ?>
                                    <div class="border rounded p-4">
                                        <div class="flex items-center justify-between mb-3">
                                            <div>
                                                <h4 class="font-medium"><?=s($module['title'])?></h4>
                                                <?php if (!empty($module['description'])): ?>
                                                    <p class="text-sm text-gray-600"><?=s($module['description'])?></p>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex gap-2">
                                                <button onclick="openEditModuleModal(<?=htmlspecialchars(json_encode($module), ENT_QUOTES, 'UTF-8')?>)" class="text-blue-600 hover:underline text-sm flex items-center">
                                                    <i data-feather="edit" class="w-4 h-4 mr-1"></i> Изменить
                                                </button>
                                                <button onclick="openAddMaterialModal(<?=$module['id']?>)" class="text-blue-600 hover:underline text-sm flex items-center">
                                                    <i data-feather="plus" class="w-4 h-4 mr-1"></i> Материал
                                                </button>
                                                <button onclick="openAddQuizModal(<?=$module['id']?>)" class="text-green-600 hover:underline text-sm flex items-center">
                                                    <i data-feather="plus" class="w-4 h-4 mr-1"></i> Тест
                                                </button>
                                            </div>
                                        </div>

                                        <!-- Материалы модуля -->
                                        <?php $materials = $module_materials[$module['id']] ?? []; ?>
                                        <?php if ($materials): ?>
                                            <div class="mt-3">
                                                <h5 class="text-sm font-medium mb-2 flex items-center">
                                                    <i data-feather="file-text" class="w-4 h-4 mr-1"></i> Материалы:
                                                </h5>
                                                <ul class="space-y-2">
                                                    <?php foreach ($materials as $material): ?>
                                                        <li class="text-sm text-gray-700 flex items-center justify-between">
                                                            <div class="flex items-center">
                                                                <?php 
                                                                    $icon = 'file';
                                                                    if ($material['type'] === 'video') $icon = 'film';
                                                                    if ($material['type'] === 'presentation') $icon = 'image';
                                                                    if ($material['type'] === 'text') $icon = 'file-text';
                                                                    if ($material['type'] === 'file') $icon = 'download';
                                                                    if ($material['type'] === 'link') $icon = 'link';
                                                                ?>
                                                                <i data-feather="<?=$icon?>" class="w-4 h-4 mr-2"></i>
                                                                <?=s($material['title'])?> (<?=s($material['type'])?>)
                                                                <?php if ($material['file_path']): ?>
                                                                    <a href="<?=s($material['file_path'])?>" target="_blank" class="text-blue-600 ml-2">
                                                                        <i data-feather="download" class="w-4 h-4"></i>
                                                                    </a>
                                                                <?php endif; ?>
                                                            </div>
                                                            <div class="flex gap-2">
                                                                <button onclick="openEditMaterialModal(<?=htmlspecialchars(json_encode($material), ENT_QUOTES, 'UTF-8')?>)" class="text-blue-600 hover:underline text-xs">
                                                                    <i data-feather="edit" class="w-3 h-3"></i>
                                                                </button>
                                                                <form method="post" class="inline">
                                                                    <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                                                                    <input type="hidden" name="action" value="delete_material">
                                                                    <input type="hidden" name="material_id" value="<?=$material['id']?>">
                                                                    <button type="submit" class="text-red-600 hover:underline text-xs" onclick="return confirm('Удалить этот материал?')">
                                                                        <i data-feather="trash" class="w-3 h-3"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <!-- Тесты модуля -->
                                        <?php $quizzes = $module_quizzes[$module['id']] ?? []; ?>
                                        <?php if ($quizzes): ?>
                                            <div class="mt-3">
                                                <h5 class="text-sm font-medium mb-2 flex items-center">
                                                    <i data-feather="check-square" class="w-4 h-4 mr-1"></i> Тесты:
                                                </h5>
                                                <ul class="space-y-2">
                                                    <?php foreach ($quizzes as $quiz): ?>
                                                        <li class="text-sm text-gray-700 flex items-center">
                                                            <i data-feather="help-circle" class="w-4 h-4 mr-2"></i>
                                                            <?=s($quiz['title'])?> (<?=s($quiz['question_type'])?>)
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (empty($materials) && empty($quizzes)): ?>
                                            <p class="text-sm text-gray-500 italic">В этом модуле пока нет материалов или тестов.</p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500">Модулей пока нет. Добавьте первый модуль для вашего курса.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Итоговый экзамен -->
                    <div class="bg-white p-6 rounded shadow">
                        <h3 class="text-lg font-semibold mb-3 flex items-center">
                            <i data-feather="award" class="w-5 h-5 mr-2 text-red-600"></i> Итоговый экзамен
                        </h3>
                        
                        <?php if ($course_exam): ?>
                            <div class="bg-green-50 p-4 rounded mb-3">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <p class="text-green-800 font-medium"><?=s($course_exam['title'])?></p>
                                        <p class="text-sm text-green-600">Проходной балл: <?=s($course_exam['passing_score'])?>%</p>
                                        <p class="text-sm text-green-600">Время на выполнение: <?=s($course_exam['time_limit_minutes'])?> минут</p>
                                        <p class="text-sm text-green-600">Максимум попыток: <?=s($course_exam['max_attempts'])?></p>
                                    </div>
                                    <span class="bg-green-200 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">Активен</span>
                                </div>
                                <?php if (!empty($course_exam['description'])): ?>
                                    <p class="text-sm text-green-700 mt-2"><?=s($course_exam['description'])?></p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="bg-blue-50 p-4 rounded mb-3">
                                <p class="text-blue-800">Итоговый экзамен еще не создан для этого курса.</p>
                                <p class="text-sm text-blue-600 mt-1">Создайте экзамен, чтобы оценить знания студентов по всему курсу.</p>
                            </div>
                            
                            <button onclick="openCreateExamModal()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded flex items-center">
                                <i data-feather="plus" class="w-4 h-4 mr-2"></i> Создать итоговый экзамен
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mt-8">
                <a href="teacher_courses.php" class="text-blue-600 hover:text-blue-800">&larr; Назад к списку курсов</a>
            </div>

        <?php else: ?>
            <!-- Главная страница без выбранного курса -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Создать курс -->
                <div class="lg:col-span-1">
                    <div class="bg-white p-6 rounded shadow">
                        <h2 class="text-lg font-semibold mb-3">Создать новый курс</h2>
                        <form method="post" enctype="multipart/form-data" class="space-y-3">
                            <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                            <input type="hidden" name="action" value="create_course">
                            <div>
                                <label class="block text-sm font-medium mb-1">Название курса</label>
                                <input type="text" name="title" class="w-full border rounded px-3 py-2" placeholder="Введите название курса" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Категория</label>
                                <select name="category" class="w-full border rounded px-3 py-2" required>
                                    <option value="">Выберите категорию</option>
                                    <?php foreach ($allowed_categories as $cat): ?>
                                        <option value="<?=s($cat)?>"><?=s($cat)?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Изображение курса</label>
                                <input type="file" name="course_image" accept="image/*" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Описание курса</label>
                                <textarea name="description" rows="4" class="w-full border rounded px-3 py-2" placeholder="Опишите содержание курса, цели обучения и требования"></textarea>
                            </div>
                            <button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded w-full">
                                Создать курс
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Мои курсы -->
                <div class="lg:col-span-2">
                    <div class="bg-white p-6 rounded shadow">
                        <h2 class="text-lg font-semibold mb-3">Мои курсы</h2>
                        <?php if ($courses): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($courses as $c): ?>
                                    <div class="border rounded p-4 hover:shadow-md transition-shadow">
                                        <?php if ($c['image_url']): ?>
                                            <img src="<?=s($c['image_url'])?>" alt="Изображение курса" class="w-full h-32 object-cover rounded-lg mb-3">
                                        <?php endif; ?>
                                        <h3 class="font-medium text-lg"><?=s($c['title'])?></h3>
                                        <p class="text-sm text-gray-500 mb-2"><?=s($c['category'])?></p>
                                        <p class="text-xs text-gray-400">Создан: <?=date('d.m.Y', strtotime($c['created_at']))?></p>
                                        <div class="mt-3">
                                            <a href="teacher_courses.php?course_id=<?=$c['id']?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                Управлять курсом →
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8">
                                <i data-feather="book-open" class="w-12 h-12 text-gray-400 mx-auto"></i>
                                <p class="text-gray-500 mt-2">У вас пока нет созданных курсов</p>
                                <p class="text-sm text-gray-400">Создайте первый курс, чтобы начать работу</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- Модальное окно редактирования курса -->
    <div id="editCourseModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
            <h3 class="text-lg font-semibold mb-3">Редактировать курс</h3>
            <form method="post" enctype="multipart/form-data" id="editCourseForm">
                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                <input type="hidden" name="action" value="update_course">
                <input type="hidden" name="course_id" id="edit_course_id">
                
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Название курса</label>
                    <input type="text" name="title" id="edit_course_title" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Категория</label>
                    <select name="category" id="edit_course_category" class="w-full border rounded px-3 py-2" required>
                        <option value="">Выберите категорию</option>
                        <?php foreach ($allowed_categories as $cat): ?>
                            <option value="<?=s($cat)?>"><?=s($cat)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Изображение курса</label>
                    <input type="file" name="course_image" accept="image/*" class="w-full border rounded px-3 py-2">
                    <p class="text-xs text-gray-500 mt-1">Оставьте пустым, чтобы сохранить текущее изображение</p>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Описание курса</label>
                    <textarea name="description" id="edit_course_description" class="w-full border rounded px-3 py-2" rows="4"></textarea>
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeEditCourseModal()" class="px-4 py-2 rounded border">Отмена</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно редактирования модуля -->
    <div id="editModuleModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
            <h3 class="text-lg font-semibold mb-3">Редактировать модуль</h3>
            <form method="post" id="editModuleForm">
                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                <input type="hidden" name="action" value="update_module">
                <input type="hidden" name="module_id" id="edit_module_id">
                
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Название модуля</label>
                    <input type="text" name="title" id="edit_module_title" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Описание модуля</label>
                    <textarea name="description" id="edit_module_description" class="w-full border rounded px-3 py-2" rows="3"></textarea>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Порядковый номер</label>
                    <input type="number" name="order_index" id="edit_module_order_index" min="0" class="w-full border rounded px-3 py-2">
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeEditModuleModal()" class="px-4 py-2 rounded border">Отмена</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно добавления/редактирования материала -->
    <div id="materialModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
            <h3 class="text-lg font-semibold mb-3" id="materialModalTitle">Добавить материал</h3>
            <form method="post" enctype="multipart/form-data" id="materialForm">
                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                <input type="hidden" name="action" id="material_action" value="create_material">
                <input type="hidden" name="module_id" id="material_module_id">
                <input type="hidden" name="material_id" id="edit_material_id">
                
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Название материала</label>
                    <input type="text" name="title" id="material_title" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Тип материала</label>
                    <select name="type" id="material_type" class="w-full border rounded px-3 py-2" required onchange="toggleMaterialInput()">
                        <option value="text">Текстовая лекция</option>
                        <option value="video">Видеоурок</option>
                        <option value="presentation">Презентация</option>
                        <option value="file">Файл для скачивания</option>
                        <option value="link">Внешняя ссылка</option>
                    </select>
                </div>
                
                <div class="mb-3" id="material_text_input">
                    <label class="block text-sm font-medium mb-1">Содержание</label>
                    <textarea name="content" id="material_content" class="w-full border rounded px-3 py-2" rows="4" placeholder="Введите текст материала"></textarea>
                </div>
                
                <div class="mb-3 hidden" id="material_file_input">
                    <label class="block text-sm font-medium mb-1">Файл</label>
                    <input type="file" name="material_file" class="w-full border rounded px-3 py-2">
                    <p class="text-xs text-gray-500 mt-1" id="material_file_note">Загрузите файл (PDF, PPT, DOC, видео и т.д.)</p>
                </div>
                
                <div class="mb-3 hidden" id="material_link_input">
                    <label class="block text-sm font-medium mb-1">Ссылка</label>
                    <input type="url" name="content" id="material_link" class="w-full border rounded px-3 py-2" placeholder="https://...">
                </div>
                
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Порядковый номер</label>
                    <input type="number" name="order_index" id="material_order_index" min="0" value="0" class="w-full border rounded px-3 py-2">
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeMaterialModal()" class="px-4 py-2 rounded border">Отмена</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно добавления теста модуля -->
    <div id="addQuizModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-md">
            <h3 class="text-lg font-semibold mb-3">Добавить тест модуля</h3>
            <form method="post" id="addQuizForm">
                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                <input type="hidden" name="action" value="create_module_quiz">
                <input type="hidden" name="module_id" id="quiz_module_id">
                
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Название теста</label>
                    <input type="text" name="title" class="w-full border rounded px-3 py-2" required>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Описание</label>
                    <textarea name="description" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Текст вопроса</label>
                    <textarea name="question_text" class="w-full border rounded px-3 py-2" rows="2" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Тип вопроса</label>
                    <select name="question_type" class="w-full border rounded px-3 py-2" required onchange="toggleQuizType()">
                        <option value="multiple_choice">Множественный выбор</option>
                        <option value="file_upload">Загрузка файла</option>
                    </select>
                </div>
                
                <div id="multiple_choice_options">
                    <div class="grid grid-cols-1 gap-2 mb-3">
                        <label class="block text-sm font-medium mb-1">Варианты ответов</label>
                        <?php for ($i = 1; $i <= 4; $i++): ?>
                            <input type="text" name="option_<?=$i?>" placeholder="Вариант <?=$i?>" class="w-full border rounded px-3 py-2">
                        <?php endfor; ?>
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Правильный ответ</label>
                        <select name="correct_answer" class="w-full border rounded px-3 py-2">
                            <option value="1">Вариант 1</option>
                            <option value="2">Вариант 2</option>
                            <option value="3">Вариант 3</option>
                            <option value="4">Вариант 4</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeAddQuizModal()" class="px-4 py-2 rounded border">Отмена</button>
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded">Добавить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно создания итогового экзамена -->
    <div id="createExamModal" class="fixed inset-0 bg-gray-800 bg-opacity-50 hidden flex items-center justify-center z-50">
        <div class="bg-white p-6 rounded shadow-lg w-full max-w-2xl max-h-screen overflow-y-auto">
            <h3 class="text-lg font-semibold mb-3">Создать итоговый экзамен</h3>
            <form method="post" id="createExamForm">
                <input type="hidden" name="csrf_token" value="<?=$csrfToken?>">
                <input type="hidden" name="action" value="create_final_exam">
                <input type="hidden" name="course_id" value="<?=$course_view['id'] ?? ''?>">
                <input type="hidden" name="question_count" id="exam_question_count" value="0">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Название экзамена</label>
                        <input type="text" name="title" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Проходной балл (%)</label>
                        <input type="number" name="passing_score" min="0" max="100" value="60" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">Лимит времени (мин.)</label>
                        <input type="number" name="time_limit_minutes" min="1" value="60" class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">Макс. попыток</label>
                        <input type="number" name="max_attempts" min="1" value="3" class="w-full border rounded px-3 py-2">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">Описание экзамена</label>
                    <textarea name="description" class="w-full border rounded px-3 py-2" rows="2"></textarea>
                </div>
                
                <div class="mb-4">
                    <h4 class="font-medium mb-2">Вопросы экзамена</h4>
                    <div id="exam_questions_container" class="space-y-4">
                        <!-- Вопросы будут добавляться здесь -->
                    </div>
                    
                    <button type="button" onclick="addExamQuestion()" class="mt-3 bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-2 rounded flex items-center">
                        <i data-feather="plus" class="w-4 h-4 mr-1"></i> Добавить вопрос
                    </button>
                </div>
                
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeCreateExamModal()" class="px-4 py-2 rounded border">Отмена</button>
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded">Создать экзамен</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        feather.replace();
        
        function openEditCourseModal(course) {
            document.getElementById('edit_course_id').value = course.id;
            document.getElementById('edit_course_title').value = course.title;
            document.getElementById('edit_course_category').value = course.category;
            document.getElementById('edit_course_description').value = course.description;
            document.getElementById('editCourseModal').classList.remove('hidden');
        }
        
        function closeEditCourseModal() {
            document.getElementById('editCourseModal').classList.add('hidden');
        }
        
        function openEditModuleModal(module) {
            document.getElementById('edit_module_id').value = module.id;
            document.getElementById('edit_module_title').value = module.title;
            document.getElementById('edit_module_description').value = module.description;
            document.getElementById('edit_module_order_index').value = module.order_index;
            document.getElementById('editModuleModal').classList.remove('hidden');
        }
        
        function closeEditModuleModal() {
            document.getElementById('editModuleModal').classList.add('hidden');
        }
        
        function openAddMaterialModal(moduleId) {
            document.getElementById('material_action').value = 'create_material';
            document.getElementById('materialModalTitle').textContent = 'Добавить материал';
            document.getElementById('material_module_id').value = moduleId;
            document.getElementById('edit_material_id').value = '';
            document.getElementById('material_title').value = '';
            document.getElementById('material_type').value = 'text';
            document.getElementById('material_content').value = '';
            document.getElementById('material_link').value = '';
            document.getElementById('material_order_index').value = 0;
            
            toggleMaterialInput();
            document.getElementById('materialModal').classList.remove('hidden');
        }
        
        function openEditMaterialModal(material) {
            document.getElementById('material_action').value = 'update_material';
            document.getElementById('materialModalTitle').textContent = 'Редактировать материал';
            document.getElementById('edit_material_id').value = material.id;
            document.getElementById('material_title').value = material.title;
            document.getElementById('material_type').value = material.type;
            document.getElementById('material_content').value = material.content;
            document.getElementById('material_link').value = material.type === 'link' ? material.content : '';
            document.getElementById('material_order_index').value = material.order_index;
            
            toggleMaterialInput();
            document.getElementById('materialModal').classList.remove('hidden');
        }
        
        function closeMaterialModal() {
            document.getElementById('materialModal').classList.add('hidden');
        }
        
        function toggleMaterialInput() {
            const type = document.getElementById('material_type').value;
            
            // Скрываем все поля ввода
            document.getElementById('material_text_input').classList.add('hidden');
            document.getElementById('material_file_input').classList.add('hidden');
            document.getElementById('material_link_input').classList.add('hidden');
            
            // Показываем нужное поле ввода
            if (type === 'text') {
                document.getElementById('material_text_input').classList.remove('hidden');
            } else if (['video', 'presentation', 'file'].includes(type)) {
                document.getElementById('material_file_input').classList.remove('hidden');
                
                // Устанавливаем подсказку в зависимости от типа
                let note = 'Загрузите файл';
                if (type === 'video') note = 'Загрузите видеофайл (MP4, AVI, MOV и т.д.)';
                if (type === 'presentation') note = 'Загрузите презентацию (PPT, PPTX, PDF)';
                if (type === 'file') note = 'Загрузите файл (PDF, DOC, ZIP и т.д.)';
                
                document.getElementById('material_file_note').textContent = note;
            } else if (type === 'link') {
                document.getElementById('material_link_input').classList.remove('hidden');
            }
        }
        
        function openAddQuizModal(moduleId) {
            document.getElementById('quiz_module_id').value = moduleId;
            document.getElementById('addQuizModal').classList.remove('hidden');
        }
        
        function closeAddQuizModal() {
            document.getElementById('addQuizModal').classList.add('hidden');
        }
        
        function openCreateExamModal() {
            document.getElementById('createExamModal').classList.remove('hidden');
        }
        
        function closeCreateExamModal() {
            document.getElementById('createExamModal').classList.add('hidden');
        }
        
        function toggleQuizType() {
            const type = document.querySelector('#addQuizForm select[name="question_type"]').value;
            const optionsDiv = document.getElementById('multiple_choice_options');
            
            if (type === 'multiple_choice') {
                optionsDiv.style.display = 'block';
            } else {
                optionsDiv.style.display = 'none';
            }
        }
        
        let examQuestionCount = 0;
        function addExamQuestion() {
            examQuestionCount++;
            document.getElementById('exam_question_count').value = examQuestionCount;
            const container = document.getElementById('exam_questions_container');
            
            const questionDiv = document.createElement('div');
            questionDiv.className = 'border rounded p-4 bg-gray-50';
            questionDiv.innerHTML = `
                <div class="flex justify-between items-center mb-3">
                    <h5 class="font-medium">Вопрос #${examQuestionCount}</h5>
                    <button type="button" class="text-red-600 text-sm" onclick="this.parentElement.parentElement.remove()">
                        <i data-feather="x" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Текст вопроса</label>
                    <textarea name="question_text_${examQuestionCount}" class="w-full border rounded px-3 py-2" rows="2" required></textarea>
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium mb-1">Тип вопроса</label>
                    <select name="question_type_${examQuestionCount}" class="w-full border rounded px-3 py-2" onchange="toggleExamQuestionType(${examQuestionCount})" required>
                        <option value="multiple_choice">Множественный выбор</option>
                        <option value="file_upload">Загрузка файла</option>
                    </select>
                </div>
                <div id="exam_question_${examQuestionCount}_options">
                    <div class="grid grid-cols-1 gap-2 mb-3">
                        <label class="block text-sm font-medium mb-1">Варианты ответов</label>
                        ${[1, 2, 3, 4].map(i => `
                            <input type="text" name="option_${examQuestionCount}_${i}" placeholder="Вариант ${i}" class="w-full border rounded px-3 py-2">
                        `).join('')}
                    </div>
                    <div class="mb-3">
                        <label class="block text-sm font-medium mb-1">Правильный ответ</label>
                        <select name="correct_answer_${examQuestionCount}" class="w-full border rounded px-3 py-2">
                            <option value="1">Вариант 1</option>
                            <option value="2">Вариант 2</option>
                            <option value="3">Вариант 3</option>
                            <option value="4">Вариант 4</option>
                        </select>
                    </div>
                </div>
            `;
            
            container.appendChild(questionDiv);
            feather.replace();
        }
        
        function toggleExamQuestionType(questionNum) {
            const type = document.querySelector(`select[name="question_type_${questionNum}"]`).value;
            const optionsDiv = document.getElementById(`exam_question_${questionNum}_options`);
            
            if (type === 'multiple_choice') {
                optionsDiv.style.display = 'block';
            } else {
                optionsDiv.style.display = 'none';
            }
        }
        
        // Закрытие модальных окон при клике вне их
        document.getElementById('editCourseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditCourseModal();
            }
        });
        
        document.getElementById('editModuleModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModuleModal();
            }
        });
        
        document.getElementById('materialModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeMaterialModal();
            }
        });
        
        document.getElementById('addQuizModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddQuizModal();
            }
        });
        
        document.getElementById('createExamModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCreateExamModal();
            }
        });
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            // Добавляем первый вопрос при открытии модального окна экзамена
            const examModal = document.getElementById('createExamModal');
            if (examModal) {
                examModal.addEventListener('shown', function() {
                    if (examQuestionCount === 0) {
                        addExamQuestion();
                    }
                });
            }
        });
    </script>
</body>
</html>