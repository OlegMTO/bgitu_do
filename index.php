<?php
session_start();
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

// Получаем популярные курсы
$popularCourses = [];
try {
    $query = "SELECT c.*, COUNT(e.id) as enrollment_count 
              FROM courses c 
              LEFT JOIN enrollments e ON c.id = e.course_id 
              GROUP BY c.id 
              ORDER BY enrollment_count DESC 
              LIMIT 6";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $popularCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Ошибка загрузки популярных курсов: " . $exception->getMessage());
}

// Получаем новые курсы
$newCourses = [];
try {
    $query = "SELECT * FROM courses ORDER BY created_at DESC LIMIT 6";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $newCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log("Ошибка загрузки новых курсов: " . $exception->getMessage());
}

// Функция для получения иконки по категории
function getCategoryIcon($category) {
    $icons = [
        'construction' => 'hard-hat',
        'transport' => 'truck',
        'forestry' => 'tree',
        'design' => 'ruler',
        'it' => 'code',
        'engineering' => 'cog'
    ];
    return $icons[$category] ?? 'book';
}

// Функция для получения названия категории
function getCategoryName($category) {
    $categories = [
        'construction' => 'Строительство',
        'transport' => 'Транспорт',
        'forestry' => 'Лесное хозяйство',
        'design' => 'Дизайн',
        'it' => 'IT технологии',
        'engineering' => 'Инженерия'
    ];
    return $categories[$category] ?? $category;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>БГИТУ | Инженерно-технологическое образование</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Exo 2', sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
            line-height: 1.6;
        }
        
        .header-gradient {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        }
        
        .nav-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .card-hover {
            transition: all 0.3s ease;
            border-left: 4px solid transparent;
        }
        
        .card-hover:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(30, 64, 175, 0.15);
            border-left-color: #1e40af;
        }
        
        .category-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(30, 64, 175, 0.3);
        }
        
        .stats-card {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            border-radius: 16px;
            overflow: hidden;
            position: relative;
        }
        
        .stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='100' height='100' viewBox='0 0 100 100' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M11 18c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm48 25c3.866 0 7-3.134 7-7s-3.134-7-7-7-7 3.134-7 7 3.134 7 7 7zm-43-7c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm63 31c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM34 90c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zm56-76c1.657 0 3-1.343 3-3s-1.343-3-3-3-3 1.343-3 3 1.343 3 3 3zM12 86c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm28-65c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm23-11c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-6 60c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm29 22c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zM32 63c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm57-13c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm-9-21c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM60 91c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM35 41c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2zM12 60c1.105 0 2-.895 2-2s-.895-2-2-2-2 .895-2 2 .895 2 2 2z' fill='%23ffffff' fill-opacity='0.1' fill-rule='evenodd'/%3E%3C/svg%3E");
            opacity: 0.2;
        }
        
        .direction-card {
            transition: all 0.3s ease;
            overflow: hidden;
            position: relative;
        }
        
        .direction-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to bottom, transparent 0%, rgba(0, 0, 0, 0.7) 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .direction-card:hover::before {
            opacity: 1;
        }
        
        .direction-card:hover .direction-content {
            transform: translateY(0);
            opacity: 1;
        }
        
        .direction-content {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            padding: 1.5rem;
            transform: translateY(20px);
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .section-title {
            position: relative;
            display: inline-block;
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            width: 50px;
            height: 3px;
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
        }
        
        .nav-link {
            position: relative;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -5px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .course-image {
            transition: all 0.5s ease;
        }
        
        .course-card:hover .course-image {
            transform: scale(1.05);
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Navigation -->
    <nav class="nav-container fixed w-full z-50 py-4">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center">
                <div class="flex items-center">
                    <div class="flex-shrink-0 flex items-center">
                        <i class="fas fa-university text-2xl text-blue-800 mr-2"></i>
                        <span class="text-xl font-bold text-blue-800">БГИТУ</span>
                    </div>
                </div>
                <div class="hidden md:flex md:items-center md:space-x-8">
                    <a href="index.php" class="nav-link text-blue-800 font-medium">Главная</a>
                    <a href="courses.php" class="nav-link text-gray-600 hover:text-blue-800 font-medium">Курсы</a>
                    <a href="#" class="nav-link text-gray-600 hover:text-blue-800 font-medium">О нас</a>
                    <a href="#" class="nav-link text-gray-600 hover:text-blue-800 font-medium">Контакты</a>
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <a href="profile.php" class="nav-link text-gray-600 hover:text-blue-800 font-medium">Личный кабинет</a>
                        <a href="logout.php" class="btn-primary px-4 py-2 text-white rounded-md text-sm font-medium">Выйти</a>
                    <?php else: ?>
                        <a href="login.php" class="nav-link text-gray-600 hover:text-blue-800 font-medium">Войти</a>
                        <a href="register.php" class="btn-primary px-4 py-2 text-white rounded-md text-sm font-medium">Регистрация</a>
                    <?php endif; ?>
                </div>
                <div class="md:hidden">
                    <button type="button" class="text-blue-800 focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <div class="header-gradient pt-32 pb-20 text-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="lg:grid lg:grid-cols-2 lg:gap-8 items-center">
                <div class="mt-12 lg:mt-0">
                    <h1 class="text-4xl font-bold tracking-tight sm:text-5xl lg:text-6xl">
                        БГИТУ - образование для реальной жизни
                    </h1>
                    <p class="mt-3 text-xl text-blue-100 sm:mt-5 sm:text-lg sm:max-w-xl sm:mx-auto md:mt-5 md:text-xl lg:mx-0">
                        Получите практические знания в строительстве, транспорте, лесном хозяйстве и дизайне от ведущих специалистов отрасли.
                    </p>
                    <div class="mt-8 sm:flex sm:justify-center lg:justify-start">
                        <div class="rounded-md shadow">
                            <a href="courses.php" class="w-full flex items-center justify-center px-8 py-3 border border-transparent text-base font-medium rounded-md text-blue-800 bg-white hover:bg-gray-50 md:py-4 md:text-lg md:px-10">
                                <i class="fas fa-graduation-cap mr-2"></i> Смотреть курсы
                            </a>
                        </div>
                        <?php if (!isset($_SESSION['user_id'])): ?>
                        <div class="mt-3 rounded-md shadow sm:mt-0 sm:ml-3">
                            <a href="register.php" class="w-full flex items-center justify-center px-8 py-3 border border-transparent text-base font-medium rounded-md text-white bg-blue-700 hover:bg-blue-800 md:py-4 md:text-lg md:px-10">
                                <i class="fas fa-user-plus mr-2"></i> Начать обучение
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="mt-12 relative">
                    <img class="w-full rounded-lg shadow-xl" src="https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" alt="Студенты БГИТУ">
                </div>
            </div>
        </div>
    </div>

    <!-- Directions Section -->
    <div class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-base text-blue-600 font-semibold tracking-wide uppercase">Направления подготовки</h2>
                <p class="mt-2 text-3xl leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl section-title">
                    Ключевые специализации
                </p>
                <p class="mt-4 max-w-2xl text-xl text-gray-500 mx-auto">
                    Мы готовим специалистов для ключевых отраслей экономики с акцентом на практические навыки
                </p>
            </div>

            <div class="mt-12 grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-4">
                <div class="direction-card bg-white rounded-lg shadow overflow-hidden">
                    <img class="w-full h-48 object-cover" src="https://images.unsplash.com/photo-1503387762-592deb58ef4e?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" alt="Строительство">
                    <div class="direction-content">
                        <h3 class="text-xl font-bold text-white">Строительство</h3>
                        <p class="mt-2 text-blue-100">Подготовка инженеров-строителей и архитекторов</p>
                        <a href="#" class="mt-3 inline-flex items-center text-white font-medium">
                            Подробнее <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
                
                <div class="direction-card bg-white rounded-lg shadow overflow-hidden">
                    <img class="w-full h-48 object-cover" src="https://images.unsplash.com/photo-1535498730771-e357b4d4cfc9?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" alt="Транспорт">
                    <div class="direction-content">
                        <h3 class="text-xl font-bold text-white">Транспорт</h3>
                        <p class="mt-2 text-blue-100">Логистика, управление и инженерия транспортных систем</p>
                        <a href="#" class="mt-3 inline-flex items-center text-white font-medium">
                            Подробнее <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
                
                <div class="direction-card bg-white rounded-lg shadow overflow-hidden">
                    <img class="w-full h-48 object-cover" src="https://images.unsplash.com/photo-1448375240586-882707db888b?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" alt="Лесное хозяйство">
                    <div class="direction-content">
                        <h3 class="text-xl font-bold text-white">Лесное хозяйство</h3>
                        <p class="mt-2 text-blue-100">Экология, лесопользование и природообустройство</p>
                        <a href="#" class="mt-3 inline-flex items-center text-white font-medium">
                            Подробнее <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
                
                <div class="direction-card bg-white rounded-lg shadow overflow-hidden">
                    <img class="w-full h-48 object-cover" src="https://images.unsplash.com/photo-1561070791-2526d30994b5?ixlib=rb-1.2.1&auto=format&fit=crop&w=1000&q=80" alt="Дизайн">
                    <div class="direction-content">
                        <h3 class="text-xl font-bold text-white">Дизайн</h3>
                        <p class="mt-2 text-blue-100">Графический, промышленный и environmental дизайн</p>
                        <a href="#" class="mt-3 inline-flex items-center text-white font-medium">
                            Подробнее <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Features -->
    <div class="py-16 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-base text-blue-600 font-semibold tracking-wide uppercase">Преимущества</h2>
                <p class="mt-2 text-3xl leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl section-title">
                    Почему выбирают нас
                </p>
            </div>

            <div class="mt-16">
                <div class="grid grid-cols-1 gap-10 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="pt-6">
                        <div class="card-hover flow-root bg-white rounded-lg px-6 pb-8 h-full shadow-md">
                            <div class="-mt-6">
                                <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-100 text-blue-800 mx-auto">
                                    <i class="fas fa-hard-hat text-xl"></i>
                                </div>
                                <h3 class="mt-8 text-lg font-medium text-gray-900 text-center">Практико-ориентированное обучение</h3>
                                <p class="mt-5 text-base text-gray-500 text-center">
                                    Современные лаборатории и оборудование для получения реальных навыков
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="pt-6">
                        <div class="card-hover flow-root bg-white rounded-lg px-6 pb-8 h-full shadow-md">
                            <div class="-mt-6">
                                <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-100 text-blue-800 mx-auto">
                                    <i class="fas fa-handshake text-xl"></i>
                                </div>
                                <h3 class="mt-8 text-lg font-medium text-gray-900 text-center">Связь с индустрией</h3>
                                <p class="mt-5 text-base text-gray-500 text-center">
                                    Партнерства с ведущими компаниями и гарантированные стажировки
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="pt-6">
                        <div class="card-hover flow-root bg-white rounded-lg px-6 pb-8 h-full shadow-md">
                            <div class="-mt-6">
                                <div class="flex items-center justify-center h-12 w-12 rounded-md bg-blue-100 text-blue-800 mx-auto">
                                    <i class="fas fa-chalkboard-teacher text-xl"></i>
                                </div>
                                <h3 class="mt-8 text-lg font-medium text-gray-900 text-center">Опытные преподаватели</h3>
                                <p class="mt-5 text-base text-gray-500 text-center">
                                    Преподаватели-практики с опытом работы в реальных проектах
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Popular Courses -->
    <div class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center">
                <h2 class="text-base text-blue-600 font-semibold tracking-wide uppercase">Популярные курсы</h2>
                <p class="mt-2 text-3xl leading-8 font-extrabold tracking-tight text-gray-900 sm:text-4xl section-title">
                    Самые востребованные программы
                </p>
            </div>

            <div class="mt-16 grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3">
                <?php foreach ($popularCourses as $course): ?>
                <div class="course-card bg-white rounded-lg shadow-md overflow-hidden transition duration-300">
                    <div class="overflow-hidden">
                        <img class="w-full h-48 object-cover course-image" src="<?php echo htmlspecialchars($course['image_url']); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>">
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <span class="category-badge bg-blue-100 text-blue-800">
                                <?php echo getCategoryName($course['category']); ?>
                            </span>
                            <span class="text-sm text-gray-500"><?php echo $course['duration_hours']; ?> часов</span>
                        </div>
                        <h3 class="mt-4 text-xl font-semibold text-gray-900"><?php echo htmlspecialchars($course['title']); ?></h3>
                        <p class="mt-3 text-base text-gray-500">
                            <?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>...
                        </p>
                        <div class="mt-4 flex items-center justify-between">
                            <span class="text-blue-600 font-bold"><?php echo number_format($course['price'], 0, ',', ' '); ?> руб.</span>
                            <span class="text-sm text-gray-500"><?php echo $course['enrollment_count']; ?> учащихся</span>
                        </div>
                        <div class="mt-6">
                            <a href="course.php?id=<?php echo $course['id']; ?>" class="block w-full text-center btn-primary text-white py-2 px-4 rounded-md">
                                Подробнее
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="mt-10 text-center">
                <a href="courses.php" class="btn-primary inline-flex items-center px-6 py-3 text-base font-medium rounded-md text-white">
                    Все курсы <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="py-16 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="stats-card py-12 px-6 text-white">
                <div class="max-w-4xl mx-auto text-center">
                    <h2 class="text-3xl font-extrabold sm:text-4xl">
                        БГИТУ в цифрах
                    </h2>
                </div>
                <div class="mt-10 text-center grid grid-cols-1 sm:grid-cols-3 gap-8">
                    <div class="mb-10 sm:mb-0">
                        <div class="text-4xl font-extrabold sm:text-5xl">
                            95
                        </div>
                        <div class="mt-2 text-base font-medium text-blue-100">
                            Лет на рынке образования
                        </div>
                    </div>
                    <div class="mb-10 sm:mb-0">
                        <div class="text-4xl font-extrabold sm:text-5xl">
                            50+
                        </div>
                        <div class="mt-2 text-base font-medium text-blue-100">
                            Образовательных программ
                        </div>
                    </div>
                    <div class="mb-10 sm:mb-0">
                        <div class="text-4xl font-extrabold sm:text-5xl">
                            10000+
                        </div>
                        <div class="mt-2 text-base font-medium text-blue-100">
                            Выпускников
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA -->
    <div class="bg-white py-16">
        <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:py-16 lg:px-8 lg:flex lg:items-center lg:justify-between">
            <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl">
                <span class="block">Готовы начать обучение?</span>
                <span class="block text-blue-600">Запишитесь на курс прямо сейчас.</span>
            </h2>
            <div class="mt-8 flex lg:mt-0 lg:flex-shrink-0">
                <div class="inline-flex rounded-md shadow">
                    <a href="courses.php" class="btn-primary inline-flex items-center justify-center px-5 py-3 text-base font-medium rounded-md text-white">
                        Выбрать курс
                    </a>
                </div>
                <?php if (!isset($_SESSION['user_id'])): ?>
                <div class="ml-3 inline-flex rounded-md shadow">
                    <a href="register.php" class="inline-flex items-center justify-center px-5 py-3 border border-transparent text-base font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200">
                        Зарегистрироваться
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white">
        <div class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div>
                    <h3 class="text-sm font-semibold text-blue-300 tracking-wider uppercase">
                        О нас
                    </h3>
                    <ul class="mt-4 space-y-4">
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                История
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Преподаватели
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Лицензии
                            </a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-blue-300 tracking-wider uppercase">
                        Обучение
                    </h3>
                    <ul class="mt-4 space-y-4">
                        <li>
                            <a href="courses.php" class="text-base text-gray-300 hover:text-white">
                                Курсы
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Вебинары
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Сертификация
                            </a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-blue-300 tracking-wider uppercase">
                        Поддержка
                    </h3>
                    <ul class="mt-4 space-y-4">
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                FAQ
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Техподдержка
                            </a>
                        </li>
                        <li>
                            <a href="#" class="text-base text-gray-300 hover:text-white">
                                Контакты
                            </a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-blue-300 tracking-wider uppercase">
                        Контакты
                    </h3>
                    <ul class="mt-4 space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-map-marker-alt text-blue-300 mr-2 mt-1"></i>
                            <span class="text-base text-gray-300">г. Брянск, ул. Примерная, 123</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-phone text-blue-300 mr-2 mt-1"></i>
                            <span class="text-base text-gray-300">+7 (4832) 12-34-56</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-envelope text-blue-300 mr-2 mt-1"></i>
                            <span class="text-base text-gray-300">dpo@bgitu.ru</span>
                        </li>
                    </ul>
                </div>
            </div>
            <div class="mt-8 border-t border-gray-700 pt-8 md:flex md:items-center md:justify-between">
                <div class="flex space-x-6 md:order-2">
                    <a href="#" class="text-gray-400 hover:text-blue-300">
                        <i class="fab fa-facebook-f text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-blue-300">
                        <i class="fab fa-instagram text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-blue-300">
                        <i class="fab fa-twitter text-xl"></i>
                    </a>
                    <a href="#" class="text-gray-400 hover:text-blue-300">
                        <i class="fab fa-youtube text-xl"></i>
                    </a>
                </div>
                <p class="mt-8 text-base text-gray-400 md:mt-0 md:order-1">
                    &copy; <?php echo date('Y'); ?> БГИТУ. Все права защищены.
                </p>
            </div>
        </div>
    </footer>

    <script>
        // Анимация счетчиков
        function animateCounters() {
            const counters = document.querySelectorAll('.stats-card .text-4xl');
            const speed = 200;
            
            counters.forEach(counter => {
                const target = +counter.innerText.replace('+', '');
                const count = +counter.innerText.replace('+', '');
                const increment = Math.ceil(target / speed);
                
                if (count < target) {
                    counter.innerText = count + increment;
                    setTimeout(animateCounters, 1);
                } else {
                    counter.innerText = target;
                }
            });
        }
        
        // Инициализация
        document.addEventListener('DOMContentLoaded', function() {
            // Запускаем анимацию счетчиков когда они появляются в viewport
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        animateCounters();
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.5 });
            
            const statsSection = document.querySelector('.stats-card');
            if (statsSection) {
                observer.observe(statsSection);
            }
        });
    </script>
</body>
</html>