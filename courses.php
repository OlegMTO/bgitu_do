<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Получаем параметры фильтрации
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$sort = $_GET['sort'] ?? 'newest';

// Формируем SQL запрос с учетом фильтров
$query = "SELECT * FROM courses WHERE 1=1";
$params = [];

if (!empty($category)) {
    $query .= " AND category = :category";
    $params[':category'] = $category;
}

if (!empty($search)) {
    $query .= " AND (title ILIKE :search OR description ILIKE :search)";
    $params[':search'] = "%$search%";
}

// Добавляем сортировку
switch ($sort) {
    case 'price_asc':
        $query .= " ORDER BY price ASC";
        break;
    case 'price_desc':
        $query .= " ORDER BY price DESC";
        break;
    case 'popular':
        $query = "SELECT c.*, COUNT(e.id) as enrollment_count 
                  FROM courses c 
                  LEFT JOIN enrollments e ON c.id = e.course_id 
                  WHERE 1=1" . 
                  (!empty($category) ? " AND c.category = :category" : "") .
                  (!empty($search) ? " AND (c.title ILIKE :search OR c.description ILIKE :search)" : "") .
                  " GROUP BY c.id 
                  ORDER BY enrollment_count DESC";
        break;
    case 'newest':
    default:
        $query .= " ORDER BY created_at DESC";
        break;
}

// Выполняем запрос
$courses = [];
try {
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $courses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Ошибка загрузки курсов: " . $exception->getMessage());
}

// Функция для получения иконки по категории
function getCategoryIcon($category) {
    $icons = [
        'marketing' => 'trending-up',
        'engineering' => 'cpu',
        'science' => 'droplet',
        'it' => 'code'
    ];
    return $icons[$category] ?? 'book';
}

// Функция для получения названия категории
function getCategoryName($category) {
    $categories = [
        'marketing' => 'Маркетинг',
        'engineering' => 'Инженерия',
        'science' => 'Лабораторное дело',
        'it' => 'IT технологии'
    ];
    return $categories[$category] ?? $category;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Все курсы - БГИТУ</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
</head>
<body class="bg-gray-100 min-h-screen">
    <!-- Навигация -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <div class="flex-shrink-0 flex items-center">
                        <i data-feather="book-open" class="h-8 w-8 text-green-700"></i>
                        <span class="ml-2 text-xl font-bold text-gray-900">БГИТУ</span>
                    </div>
                </div>
                <div class="flex items-center">
                    <a href="index.php" class="text-gray-700 hover:text-green-700 mr-4">Главная</a>
                    <a href="courses.php" class="text-gray-700 hover:text-green-700 mr-4">Курсы</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="profile.php" class="text-gray-700 hover:text-green-700 mr-4">Профиль</a>
                        <a href="logout.php" class="text-gray-700 hover:text-green-700">Выйти</a>
                    <?php else: ?>
                        <a href="login.php" class="text-gray-700 hover:text-green-700 mr-4">Войти</a>
                        <a href="register.php" class="text-gray-700 hover:text-green-700">Регистрация</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <div class="bg-white shadow rounded-lg overflow-hidden mb-8">
            <div class="px-6 py-4 border-b border-gray-200">
                <h1 class="text-2xl font-bold text-gray-800">Все курсы</h1>
                <p class="text-gray-600">Выберите подходящий курс для обучения</p>
            </div>
            
            <div class="px-6 py-4 bg-gray-50">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Категория</label>
                        <select name="category" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-green-500 focus:border-green-500">
                            <option value="">Все категории</option>
                            <option value="marketing" <?php echo $category == 'marketing' ? 'selected' : ''; ?>>Маркетинг</option>
                            <option value="engineering" <?php echo $category == 'engineering' ? 'selected' : ''; ?>>Инженерия</option>
                            <option value="science" <?php echo $category == 'science' ? 'selected' : ''; ?>>Лабораторное дело</option>
                            <option value="it" <?php echo $category == 'it' ? 'selected' : ''; ?>>IT технологии</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Поиск</label>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                               class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-green-500 focus:border-green-500" 
                               placeholder="Название или описание">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Сортировка</label>
                        <select name="sort" class="w-full border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-green-500 focus:border-green-500">
                            <option value="newest" <?php echo $sort == 'newest' ? 'selected' : ''; ?>>Сначала новые</option>
                            <option value="price_asc" <?php echo $sort == 'price_asc' ? 'selected' : ''; ?>>Цена (по возрастанию)</option>
                            <option value="price_desc" <?php echo $sort == 'price_desc' ? 'selected' : ''; ?>>Цена (по убыванию)</option>
                            <option value="popular" <?php echo $sort == 'popular' ? 'selected' : ''; ?>>Популярные</option>
                        </select>
                    </div>
                    
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 transition duration-300">
                            Применить
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (count($courses) > 0): ?>
                <?php foreach ($courses as $course): ?>
                <div class="course-card bg-white rounded-lg shadow overflow-hidden transition duration-300 hover:shadow-lg">
                    <img class="w-full h-48 object-cover" src="<?php echo htmlspecialchars($course['image_url']); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>">
                    <div class="p-6">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-green-100 p-2 rounded-md">
                                <i data-feather="<?php echo getCategoryIcon($course['category']); ?>" class="h-6 w-6 text-green-600"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($course['title']); ?></h3>
                                <p class="text-sm text-gray-500"><?php echo getCategoryName($course['category']); ?></p>
                            </div>
                        </div>
                        <p class="mt-3 text-base text-gray-500">
                            <?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>...
                        </p>
                        <div class="mt-4 flex items-center justify-between">
                            <span class="text-green-600 font-bold"><?php echo number_format($course['price'], 0, ',', ' '); ?> руб.</span>
                            <span class="text-gray-500 text-sm"><?php echo $course['duration_hours']; ?> часов</span>
                        </div>
                        <div class="mt-6">
                            <a href="course.php?id=<?php echo $course['id']; ?>" class="block w-full text-center bg-green-600 text-white py-2 px-4 rounded-md hover:bg-green-700 transition duration-300">
                                Подробнее
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-span-full text-center py-12">
                    <i data-feather="search" class="h-12 w-12 text-gray-400 mx-auto"></i>
                    <h3 class="mt-4 text-lg font-medium text-gray-900">Курсы не найдены</h3>
                    <p class="mt-1 text-gray-500">Попробуйте изменить параметры поиска или фильтрации.</p>
                    <div class="mt-6">
                        <a href="courses.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                            Сбросить фильтры
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>