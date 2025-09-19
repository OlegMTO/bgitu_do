<?php
include_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

if ($db) {
    try {
        // Добавление тестовых курсов
        $courses = [
            [
                'title' => 'Основы маркетинга',
                'description' => 'Современные методы продвижения товаров и услуг в цифровой среде.',
                'category' => 'marketing',
                'image_url' => 'http://static.photos/marketing/640x360/1',
                'duration_hours' => 40,
                'price' => 15000.00
            ],
            [
                'title' => 'Инженерное проектирование',
                'description' => 'Проектирование и разработка инженерных решений для различных отраслей.',
                'category' => 'engineering',
                'image_url' => 'http://static.photos/engineering/640x360/1',
                'duration_hours' => 60,
                'price' => 20000.00
            ],
            // Добавьте другие курсы по аналогии
        ];

        foreach ($courses as $course) {
            $query = "INSERT INTO courses (title, description, category, image_url, duration_hours, price) 
                      VALUES (:title, :description, :category, :image_url, :duration_hours, :price)";
            $stmt = $db->prepare($query);
            $stmt->execute($course);
        }

        echo "Тестовые данные успешно добавлены!";
        
    } catch(PDOException $exception) {
        echo "Ошибка при добавлении данных: " . $exception->getMessage();
    }
} else {
    echo "Не удалось подключиться к базе данных";
}
?>