<?php
require_once 'config/database.php';
require_once 'config/security.php';

session_start();

// Проверяем авторизацию и роль
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'teacher') {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$teacher_id = (int)$_SESSION['user_id'];

if (!isset($_GET['quiz_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID теста']);
    exit;
}

$quiz_id = (int)$_GET['quiz_id'];

// Проверяем, что тест принадлежит преподавателю
$q = "SELECT COUNT(*) FROM module_quizzes mq
      JOIN course_modules cm ON mq.module_id = cm.id
      JOIN courses c ON cm.course_id = c.id
      WHERE mq.id = :qid AND c.teacher_id = :tid";
$st = $db->prepare($q);
$st->execute([':qid' => $quiz_id, ':tid' => $teacher_id]);

if ((int)$st->fetchColumn() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

// Получаем данные теста
$q = "SELECT * FROM module_quizzes WHERE id = :qid";
$st = $db->prepare($q);
$st->execute([':qid' => $quiz_id]);
$quiz = $st->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    http_response_code(404);
    echo json_encode(['error' => 'Тест не найден']);
    exit;
}

// Декодируем options из JSON
$quiz['options'] = json_decode($quiz['options'], true);

echo json_encode($quiz, JSON_UNESCAPED_UNICODE);
?>