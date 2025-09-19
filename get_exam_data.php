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

if (!isset($_GET['exam_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Не указан ID экзамена']);
    exit;
}

$exam_id = (int)$_GET['exam_id'];

// Проверяем, что экзамен принадлежит преподавателю
$q = "SELECT COUNT(*) FROM course_exams ce
      JOIN courses c ON ce.course_id = c.id
      WHERE ce.id = :eid AND c.teacher_id = :tid";
$st = $db->prepare($q);
$st->execute([':eid' => $exam_id, ':tid' => $teacher_id]);

if ((int)$st->fetchColumn() === 0) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещен']);
    exit;
}

// Получаем данные экзамена
$q = "SELECT * FROM course_exams WHERE id = :eid";
$st = $db->prepare($q);
$st->execute([':eid' => $exam_id]);
$exam = $st->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    http_response_code(404);
    echo json_encode(['error' => 'Экзамен не найден']);
    exit;
}

// Декодируем вопросы из JSON
$exam['questions'] = json_decode($exam['questions'], true);

echo json_encode($exam, JSON_UNESCAPED_UNICODE);
?>