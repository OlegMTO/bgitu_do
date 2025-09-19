<?php
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($db) {
    echo "Подключение к PostgreSQL успешно установлено!<br>";
    
    // Попробуем выполнить простой запрос
    try {
        $query = "SELECT version()";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "Версия PostgreSQL: " . $result['version'];
    } catch(PDOException $exception) {
        echo "Ошибка выполнения запроса: " . $exception->getMessage();
    }
} else {
    echo "Не удалось подключиться к базе данных";
}
?>