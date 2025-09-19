<?php
session_start();
require_once 'config/database.php';
require_once 'config/security.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$database = new Database();
$db = $database->getConnection();

$user = [];
$enrollments = [];
$recommended_courses = [];
$error = '';
$success = '';
$showProfileModal = false;

// Проверяем и обновляем структуру таблиц при необходимости
try {
    // Добавляем отсутствующие колонки в users
    $db->exec("
        DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='city') THEN
                ALTER TABLE users ADD COLUMN city VARCHAR(100);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='street') THEN
                ALTER TABLE users ADD COLUMN street VARCHAR(100);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='house') THEN
                ALTER TABLE users ADD COLUMN house VARCHAR(20);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='apartment') THEN
                ALTER TABLE users ADD COLUMN apartment VARCHAR(20);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='is_private_house') THEN
                ALTER TABLE users ADD COLUMN is_private_house BOOLEAN DEFAULT FALSE;
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='education_level') THEN
                ALTER TABLE users ADD COLUMN education_level VARCHAR(50);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='diploma_number') THEN
                ALTER TABLE users ADD COLUMN diploma_number VARCHAR(100);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='passport_scan') THEN
                ALTER TABLE users ADD COLUMN passport_scan VARCHAR(255);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='users' AND column_name='diploma_scan') THEN
                ALTER TABLE users ADD COLUMN diploma_scan VARCHAR(255);
            END IF;
        END $$;
    ");
    
    // Добавляем отсутствующие колонки в enrollments
    $db->exec("
        DO $$ 
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='enrollments' AND column_name='grade') THEN
                ALTER TABLE enrollments ADD COLUMN grade VARCHAR(20);
            END IF;
            IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='enrollments' AND column_name='completed_at') THEN
                ALTER TABLE enrollments ADD COLUMN completed_at TIMESTAMP;
            END IF;
        END $$;
    ");
    
    // Создаем таблицу academic_records если ее нет
    $db->exec("
        CREATE TABLE IF NOT EXISTS academic_records (
            id SERIAL PRIMARY KEY,
            user_id INTEGER REFERENCES users(id),
            course_id INTEGER REFERENCES courses(id),
            grade VARCHAR(20),
            exam_date DATE,
            teacher VARCHAR(100),
            credits INTEGER,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

} catch (PDOException $e) {
    // Пропускаем ошибки, связанные с изменением структуры
    error_log("Ошибка изменения структуры БД: " . $e->getMessage());
}

try {
    $query = "SELECT id, email, first_name, last_name, avatar, phone, birth_date, passport, snils, inn, 
                     address, education_level, diploma_number, passport_scan, diploma_scan,
                     city, street, house, apartment, is_private_house, created_at 
              FROM users WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        session_destroy();
        header('Location: login.php');
        exit;
    }

    // Расшифровка данных
    if (!empty($user['phone'])) $user['phone'] = decryptData($user['phone']);
    if (!empty($user['passport'])) $user['passport'] = decryptData($user['passport']);
    if (!empty($user['snils'])) $user['snils'] = decryptData($user['snils']);
    if (!empty($user['inn'])) $user['inn'] = decryptData($user['inn']);
    if (!empty($user['address'])) $user['address'] = decryptData($user['address']);

    // Проверяем, заполнены ли все обязательные поля
    $requiredFields = ['first_name', 'last_name', 'email', 'phone', 'passport', 'snils', 'inn', 'education_level', 'diploma_number'];
    $missingFields = [];
    
    foreach ($requiredFields as $field) {
        if (empty($user[$field])) {
            $missingFields[] = $field;
        }
    }
    
    // Если есть незаполненные поля, показываем модальное окно
    if (!empty($missingFields) && !isset($_SESSION['profile_modal_shown'])) {
        $showProfileModal = true;
        $_SESSION['profile_modal_shown'] = true;
    }

    $query = "SELECT c.id, c.title, c.category, c.image_url, e.enrolled_at, e.progress, e.completed, e.grade, e.completed_at
              FROM enrollments e
              JOIN courses c ON e.course_id = c.id
              WHERE e.user_id = :user_id
              ORDER BY e.enrolled_at DESC";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Получаем рекомендуемые курсы
    $query = "SELECT id, title, category, image_url, description 
              FROM courses 
              WHERE id NOT IN (SELECT course_id FROM enrollments WHERE user_id = :user_id)
              ORDER BY created_at DESC 
              LIMIT 3";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $recommended_courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $exception) {
    $error = 'Ошибка загрузки данных: ' . $exception->getMessage();
}

// Обновление профиля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $firstName = sanitizeInput(substr($_POST['first_name'] ?? '', 0, 50));
    $lastName = sanitizeInput(substr($_POST['last_name'] ?? '', 0, 50));
    $email = sanitizeInput(substr($_POST['email'] ?? '', 0, 100));
    $phone = sanitizeInput($_POST['phone'] ?? '');
    $birthDate = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $passport = sanitizeInput($_POST['passport'] ?? '');
    $snils = sanitizeInput($_POST['snils'] ?? '');
    $inn = sanitizeInput($_POST['inn'] ?? '');
    $educationLevel = sanitizeInput($_POST['education_level'] ?? '');
    $diplomaNumber = sanitizeInput(substr($_POST['diploma_number'] ?? '', 0, 100));
    $city = sanitizeInput(substr($_POST['city'] ?? '', 0, 100));
    $street = sanitizeInput(substr($_POST['street'] ?? '', 0, 100));
    $house = sanitizeInput(substr($_POST['house'] ?? '', 0, 20));
    $apartment = sanitizeInput(substr($_POST['apartment'] ?? '', 0, 20));
    $isPrivateHouse = isset($_POST['is_private_house']) ? true : false;

    // Валидация email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный формат email';
    }
    // Валидация телефона
    elseif (!preg_match('/^\+7\s\(\d{3}\)\s\d{3}-\d{2}-\d{2}$/', $phone)) {
        $error = 'Телефон должен быть в формате: +7 (XXX) XXX-XX-XX';
    }
    // Валидация паспорта
    elseif (!preg_match('/^\d{2}\s\d{2}\s\d{6}$/', $passport)) {
        $error = 'Паспорт должен быть в формате: Серия 00 00 Номер 000000';
    }
    // Валидация СНИЛС
    elseif (!preg_match('/^\d{3}-\d{3}-\d{3}\s\d{2}$/', $snils)) {
        $error = 'СНИЛС должен быть в формате: 000-000-000 00';
    }
    // Валидация ИНН
    elseif (!preg_match('/^\d{12}$/', $inn)) {
        $error = 'ИНН должен состоять из 12 цифр';
    }
    // Валидация уровня образования
    elseif (empty($educationLevel)) {
        $error = 'Укажите уровень образования';
    }
    // Валидация номера диплома
    elseif (empty($diplomaNumber)) {
        $error = 'Укажите номер диплома';
    } else {
        // Аватар
        $avatarPath = $user['avatar'];
        if (!empty($_FILES['avatar']['name'])) {
            $uploadDir = 'uploads/avatars/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = uniqid() . '_' . basename($_FILES['avatar']['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                if ($user['avatar'] && $user['avatar'] !== 'uploads/avatars/default.png') {
                    @unlink($user['avatar']);
                }
                $avatarPath = $targetPath;
            }
        }
        
        // Скан паспорта
        $passportScanPath = $user['passport_scan'];
        if (!empty($_FILES['passport_scan']['name'])) {
            $uploadDir = 'uploads/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = uniqid() . '_passport_' . basename($_FILES['passport_scan']['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['passport_scan']['tmp_name'], $targetPath)) {
                if ($user['passport_scan']) {
                    @unlink($user['passport_scan']);
                }
                $passportScanPath = $targetPath;
            }
        }
        
        // Скан диплома
        $diplomaScanPath = $user['diploma_scan'];
        if (!empty($_FILES['diploma_scan']['name'])) {
            $uploadDir = 'uploads/documents/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $fileName = uniqid() . '_diploma_' . basename($_FILES['diploma_scan']['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['diploma_scan']['tmp_name'], $targetPath)) {
                if ($user['diploma_scan']) {
                    @unlink($user['diploma_scan']);
                }
                $diplomaScanPath = $targetPath;
            }
        }

        try {
            // Проверка, что email не занят другим пользователем
            $emailCheckQuery = "SELECT id FROM users WHERE email = :email AND id != :id";
            $emailCheckStmt = $db->prepare($emailCheckQuery);
            $emailCheckStmt->bindParam(':email', $email);
            $emailCheckStmt->bindParam(':id', $_SESSION['user_id']);
            $emailCheckStmt->execute();
            
            if ($emailCheckStmt->fetch()) {
                $error = 'Этот email уже занят другим пользователем';
            } else {
                // Шифруем чувствительные данные перед сохранением
                $encryptedPhone = encryptData($phone);
                $encryptedPassport = encryptData($passport);
                $encryptedSnils = encryptData($snils);
                $encryptedInn = encryptData($inn);
                
                $query = "UPDATE users SET first_name = :first_name, last_name = :last_name, 
                          email = :email, phone = :phone, birth_date = :birth_date,
                          passport = :passport, snils = :snils, inn = :inn, avatar = :avatar,
                          education_level = :education_level, diploma_number = :diploma_number,
                          passport_scan = :passport_scan, diploma_scan = :diploma_scan,
                          city = :city, street = :street, house = :house, apartment = :apartment, is_private_house = :is_private_house
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':first_name', $firstName);
                $stmt->bindParam(':last_name', $lastName);
                $stmt->bindParam(':email', $email);
                $stmt->bindParam(':phone', $encryptedPhone);
                $stmt->bindParam(':birth_date', $birthDate);
                $stmt->bindParam(':passport', $encryptedPassport);
                $stmt->bindParam(':snils', $encryptedSnils);
                $stmt->bindParam(':inn', $encryptedInn);
                $stmt->bindParam(':avatar', $avatarPath);
                $stmt->bindParam(':education_level', $educationLevel);
                $stmt->bindParam(':diploma_number', $diplomaNumber);
                $stmt->bindParam(':passport_scan', $passportScanPath);
                $stmt->bindParam(':diploma_scan', $diplomaScanPath);
                $stmt->bindParam(':city', $city);
                $stmt->bindParam(':street', $street);
                $stmt->bindParam(':house', $house);
                $stmt->bindParam(':apartment', $apartment);
                $stmt->bindParam(':is_private_house', $isPrivateHouse, PDO::PARAM_BOOL);
                $stmt->bindParam(':id', $_SESSION['user_id']);

                if ($stmt->execute()) {
                    $success = 'Профиль успешно обновлен.';
                    $_SESSION['user_name'] = $firstName . ' ' . $lastName;
                    // Обновляем данные пользователя для отображения
                    $user['email'] = $email;
                    $user['phone'] = $phone;
                    $user['passport'] = $passport;
                    $user['snils'] = $snils;
                    $user['inn'] = $inn;
                    $user['birth_date'] = $birthDate;
                    $user['education_level'] = $educationLevel;
                    $user['diploma_number'] = $diplomaNumber;
                    $user['passport_scan'] = $passportScanPath;
                    $user['diploma_scan'] = $diplomaScanPath;
                    $user['city'] = $city;
                    $user['street'] = $street;
                    $user['house'] = $house;
                    $user['apartment'] = $apartment;
                    $user['is_private_house'] = $isPrivateHouse;
                    
                    // Сбрасываем флаг показа модального окна
                    unset($_SESSION['profile_modal_shown']);
                } else {
                    $error = 'Ошибка при обновлении профиля.';
                }
            }
        } catch (PDOException $exception) {
            $error = 'Ошибка базы данных: ' . $exception->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет слушателя ДПО</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        // Функция для форматирования ввода телефона
        function formatPhone(input) {
            let value = input.value.replace(/\D/g, '');
            if (value.startsWith('7')) {
                value = '7' + value.substring(1);
            } else if (value.startsWith('8')) {
                value = '7' + value.substring(1);
            } else if (!value.startsWith('7')) {
                value = '7' + value;
            }
            
            let formattedValue = '+7';
            if (value.length > 1) {
                formattedValue += ' (' + value.substring(1, 4);
            }
            if (value.length > 4) {
                formattedValue += ') ' + value.substring(4, 7);
            }
            if (value.length > 7) {
                formattedValue += '-' + value.substring(7, 9);
            }
            if (value.length > 9) {
                formattedValue += '-' + value.substring(9, 11);
            }
            
            input.value = formattedValue;
        }

        // Функция для форматирования ввода паспорта
        function formatPassport(input) {
            let value = input.value.replace(/\D/g, '');
            let formattedValue = '';
            
            if (value.length > 0) {
                formattedValue = value.substring(0, 2);
            }
            if (value.length > 2) {
                formattedValue += ' ' + value.substring(2, 4);
            }
            if (value.length > 4) {
                formattedValue += ' ' + value.substring(4, 10);
            }
            
            input.value = formattedValue;
        }

        // Функция для форматирования ввода СНИЛС
        function formatSnils(input) {
            let value = input.value.replace(/\D/g, '');
            let formattedValue = '';
            
            if (value.length > 0) {
                formattedValue = value.substring(0, 3);
            }
            if (value.length > 3) {
                formattedValue += '-' + value.substring(3, 6);
            }
            if (value.length > 6) {
                formattedValue += '-' + value.substring(6, 9);
            }
            if (value.length > 9) {
                formattedValue += ' ' + value.substring(9, 11);
            }
            
            input.value = formattedValue;
        }

        // Функция для ограничения ввода только цифр в ИНН
        function formatInn(input) {
            input.value = input.value.replace(/\D/g, '');
            if (input.value.length > 12) {
                input.value = input.value.substring(0, 12);
            }
        }

        // Функция для переключения между вкладками
        function openTab(evt, tabName) {
            var i, tabcontent, tablinks;
            tabcontent = document.getElementsByClassName("tabcontent");
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].classList.add("hidden");
            }
            tablinks = document.getElementsByClassName("tablinks");
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].classList.remove("border-blue-600", "text-blue-700", "border-b-2", "font-semibold");
                tablinks[i].classList.add("text-gray-600", "border-transparent");
            }
            document.getElementById(tabName).classList.remove("hidden");
            evt.currentTarget.classList.remove("text-gray-600", "border-transparent");
            evt.currentTarget.classList.add("border-blue-600", "text-blue-700", "border-b-2", "font-semibold");
            
            // Сохраняем выбранную вкладку в sessionStorage
            sessionStorage.setItem('lastTab', tabName);
        }

        // Функция для переключения отображения поля квартиры
        function toggleApartmentField() {
            const isPrivateHouse = document.getElementById('is_private_house').checked;
            const apartmentField = document.getElementById('apartment_field');
            
            if (isPrivateHouse) {
                apartmentField.classList.add('hidden');
                document.getElementById('apartment').value = '';
            } else {
                apartmentField.classList.remove('hidden');
            }
        }

        // Функция для предпросмотра загружаемых файлов
        function previewFile(input, previewId) {
            const preview = document.getElementById(previewId);
            const file = input.files[0];
            
            if (file) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        preview.innerHTML = `<img src="${e.target.result}" class="w-full h-40 object-contain border rounded">`;
                    } else {
                        preview.innerHTML = `
                            <div class="bg-gray-100 p-4 rounded border text-center">
                                <i class="fas fa-file text-3xl text-gray-500 mb-2"></i>
                                <p class="text-sm font-medium">${file.name}</p>
                                <p class="text-xs text-gray-500">${(file.size / 1024).toFixed(2)} KB</p>
                            </div>
                        `;
                    }
                }
                
                reader.readAsDataURL(file);
            }
        }

        // По умолчанию открываем последнюю активную вкладку или первую
        document.addEventListener("DOMContentLoaded", function() {
            const lastTab = sessionStorage.getItem('lastTab') || 'Profile';
            document.querySelectorAll('.tablinks').forEach(tab => {
                if (tab.getAttribute('onclick').includes(lastTab)) {
                    tab.click();
                }
            });
            
            // Инициализируем поле квартиры
            toggleApartmentField();
            
            // Показываем модальное окно, если нужно
            <?php if ($showProfileModal): ?>
                document.getElementById('profileModal').classList.remove('hidden');
            <?php endif; ?>
        });
    </script>
    <style>
        .profile-card {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            color: white;
            overflow: hidden;
        }
        .course-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .course-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .avatar-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
        }
        .avatar-upload:hover::after {
            content: 'Сменить фото';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.7);
            color: white;
            text-align: center;
            padding: 5px;
            border-radius: 0 0 8px 8px;
        }
        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        .file-upload-btn {
            border: 1px solid #ccc;
            display: inline-block;
            padding: 8px 12px;
            cursor: pointer;
            background: #f8f9fa;
            border-radius: 4px;
            transition: background 0.3s;
        }
        .file-upload-btn:hover {
            background: #e9ecef;
        }
        .file-upload input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
<!-- Модальное окно для напоминания о заполнении профиля -->
<div id="profileModal" class="fixed inset-0 bg-gray-800 bg-opacity-75 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-lg p-6 max-w-md w-full mx-4">
        <div class="flex items-center justify-center w-16 h-16 rounded-full bg-blue-100 mx-auto mb-4">
            <i class="fas fa-exclamation-circle text-blue-600 text-2xl"></i>
        </div>
        <h3 class="text-xl font-bold text-gray-900 text-center mb-2">Заполните ваш профиль</h3>
        <p class="text-gray-700 text-center mb-6">Для полноценного использования всех функций личного кабинета необходимо заполнить все обязательные поля профиля.</p>
        <div class="flex justify-center">
            <button onclick="document.getElementById('profileModal').classList.add('hidden'); document.getElementById('EditProfileTab').click();" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg transition duration-200">
                Заполнить сейчас
            </button>
        </div>
    </div>
</div>

<nav class="bg-white shadow-lg">
    <div class="max-w-7xl mx-auto px-4 flex justify-between h-16 items-center">
        <div class="flex items-center">
            <span class="ml-2 text-xl font-bold text-blue-700">БГИТУ - ДПО</span>
        </div>
        <div class="flex items-center space-x-6">
            <a href="index.php" class="text-gray-700 hover:text-blue-600 transition duration-150"><i class="fas fa-home mr-1"></i> Главная</a>
            <a href="courses.php" class="text-gray-700 hover:text-blue-600 transition duration-150"><i class="fas fa-book mr-1"></i> Курсы</a>
            <a href="logout.php" class="text-gray-700 hover:text-blue-600 transition duration-150"><i class="fas fa-sign-out-alt mr-1"></i> Выйти</a>
        </div>
    </div>
</nav>

<div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
    <?php if ($error): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded shadow-sm">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-3"></i>
                <p><?php echo $error; ?></p>
            </div>
        </div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded shadow-sm">
            <div class="flex items-center">
                <i class="fas fa-check-circle mr-3"></i>
                <p><?php echo $success; ?></p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Приветствие -->
    <div class="bg-gradient-to-r from-blue-600 to-blue-800 rounded-xl p-6 mb-8 text-white shadow-lg">
        <h1 class="text-2xl md:text-3xl font-bold mb-2">Добро пожаловать, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
        <p class="text-blue-100">Личный кабинет слушателя программ дополнительного профессионального образования</p>
    </div>

    <!-- Навигация по вкладкам -->
    <div class="border-b border-gray-200 mb-8">
        <div class="flex flex-wrap space-x-0 md:space-x-8">
            <button id="ProfileTab" class="tablinks py-4 px-1 border-b-2 font-medium text-sm border-blue-600 text-blue-700 font-semibold" onclick="openTab(event, 'Profile')">
                <i class="fas fa-user mr-2"></i>Профиль
            </button>
            <button class="tablinks py-4 px-1 border-b-2 font-medium text-sm text-gray-600 border-transparent" onclick="openTab(event, 'Courses')">
                <i class="fas fa-graduation-cap mr-2"></i>Мои курсы
            </button>
            <button id="EditProfileTab" class="tablinks py-4 px-1 border-b-2 font-medium text-sm text-gray-600 border-transparent" onclick="openTab(event, 'EditProfile')">
                <i class="fas fa-user-edit mr-2"></i>Редактировать профиль
            </button>
        </div>
    </div>

    <!-- Вкладка профиля -->
    <div id="Profile" class="tabcontent">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Карточка профиля -->
            <div class="lg:col-span-2">
                <div class="profile-card p-6 md:p-8">
                    <div class="flex flex-col md:flex-row items-center md:items-start">
                        <div class="mb-6 md:mb-0 md:mr-6 flex-shrink-0">
                            <img src="<?php echo $user['avatar'] ?: 'uploads/avatars/default.png'; ?>" class="w-32 h-32 rounded-full border-4 border-white shadow-md object-cover">
                        </div>
                        <div class="text-center md:text-left">
                            <h2 class="text-2xl font-bold mb-1">БГИТУ - ДПО</h2>
                            <h3 class="text-xl font-semibold mb-4">Личная карточка слушателя</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <p class="text-sm opacity-80">ФИО</p>
                                    <p class="text-lg font-semibold"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm opacity-80">Уровень образования</p>
                                    <p class="text-lg font-semibold"><?php echo htmlspecialchars($user['education_level'] ?? 'не указан'); ?></p>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <p class="text-sm opacity-80">Номер диплома</p>
                                    <p class="text-lg font-semibold"><?php echo htmlspecialchars($user['diploma_number'] ?? 'не указан'); ?></p>
                                </div>
                                <div>
                                    <p class="text-sm opacity-80">Email</p>
                                    <p class="text-lg font-semibold"><?php echo htmlspecialchars($user['email']); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Документы -->
                <div class="bg-white rounded-xl shadow-md p-6 mt-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-file-alt mr-3 text-blue-600"></i> Документы
                    </h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h4 class="font-semibold text-gray-700 mb-2">Скан паспорта (все страницы)</h4>
                            <?php if (!empty($user['passport_scan'])): ?>
                                <div class="border rounded-lg p-4 bg-gray-50">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium">Загруженный файл</span>
                                        <a href="<?php echo $user['passport_scan']; ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm">
                                            <i class="fas fa-download mr-1"></i> Скачать
                                        </a>
                                    </div>
                                    <?php if (pathinfo($user['passport_scan'], PATHINFO_EXTENSION) === 'pdf'): ?>
                                        <div class="bg-red-100 p-4 rounded text-center">
                                            <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                            <p class="text-sm font-medium">PDF документ</p>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?php echo $user['passport_scan']; ?>" alt="Скан паспорта" class="w-full h-40 object-contain border rounded">
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                    <i class="fas fa-file-upload text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-gray-500">Файл не загружен</p>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div>
                            <h4 class="font-semibold text-gray-700 mb-2">Скан диплома об образовании</h4>
                            <?php if (!empty($user['diploma_scan'])): ?>
                                <div class="border rounded-lg p-4 bg-gray-50">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm font-medium">Загруженный файл</span>
                                        <a href="<?php echo $user['diploma_scan']; ?>" target="_blank" class="text-blue-600 hover:text-blue-800 text-sm">
                                            <i class="fas fa-download mr-1"></i> Скачать
                                        </a>
                                    </div>
                                    <?php if (pathinfo($user['diploma_scan'], PATHINFO_EXTENSION) === 'pdf'): ?>
                                        <div class="bg-red-100 p-4 rounded text-center">
                                            <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                            <p class="text-sm font-medium">PDF документ</p>
                                        </div>
                                    <?php else: ?>
                                        <img src="<?php echo $user['diploma_scan']; ?>" alt="Скан диплома" class="w-full h-40 object-contain border rounded">
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center">
                                    <i class="fas fa-file-upload text-3xl text-gray-400 mb-3"></i>
                                    <p class="text-gray-500">Файл не загружен</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ближайшие события -->
            <div>
                <div class="bg-white rounded-xl shadow-md p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-calendar-alt mr-2 text-blue-600"></i> Ближайшие события
                    </h3>
                    <div class="space-y-4">
                        <div class="flex items-start">
                            <div class="bg-blue-100 text-blue-800 rounded-lg p-2 mr-3 flex-shrink-0">
                                <i class="fas fa-book text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium">Экзамен по веб-разработке</p>
                                <p class="text-sm text-gray-600">25 декабря, 10:00</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <div class="bg-green-100 text-green-800 rounded-lg p-2 mr-3 flex-shrink-0">
                                <i class="fas fa-tasks text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium">Сдача проекта</p>
                                <p class="text-sm text-gray-600">20 декабря, 23:59</p>
                            </div>
                        </div>
                        <div class="flex items-start">
                            <div class="bg-purple-100 text-purple-800 rounded-lg p-2 mr-3 flex-shrink-0">
                                <i class="fas fa-users text-sm"></i>
                            </div>
                            <div>
                                <p class="font-medium">Собрание группы</p>
                                <p class="text-sm text-gray-600">15 декабря, 14:30</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Быстрый доступ -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                        <i class="fas fa-rocket mr-2 text-blue-600"></i> Быстрый доступ
                    </h3>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="courses.php" class="bg-blue-50 hover:bg-blue-100 text-blue-700 rounded-lg p-3 text-center transition duration-150">
                            <i class="fas fa-book block text-xl mb-1"></i>
                            <span class="text-sm">Курсы</span>
                        </a>
                        <a href="#" class="bg-green-50 hover:bg-green-100 text-green-700 rounded-lg p-3 text-center transition duration-150">
                            <i class="fas fa-tasks block text-xl mb-1"></i>
                            <span class="text-sm">Задания</span>
                        </a>
                        <a href="#" class="bg-purple-50 hover:bg-purple-100 text-purple-700 rounded-lg p-3 text-center transition duration-150">
                            <i class="fas fa-calendar block text-xl mb-1"></i>
                            <span class="text-sm">Расписание</span>
                        </a>
                        <a href="#" class="bg-yellow-50 hover:bg-yellow-100 text-yellow-700 rounded-lg p-3 text-center transition duration-150">
                            <i class="fas fa-chart-bar block text-xl mb-1"></i>
                            <span class="text-sm">Оценки</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Рекомендуемые курсы -->
        <div class="mt-12">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                <i class="fas fa-lightbulb mr-3 text-yellow-500"></i> Рекомендуемые курсы
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php if (!empty($recommended_courses)): ?>
                    <?php foreach ($recommended_courses as $course): ?>
                        <div class="course-card bg-white rounded-xl shadow-md overflow-hidden">
                            <div class="h-40 bg-gray-200 overflow-hidden">
                                <img src="<?php echo htmlspecialchars($course['image_url'] ?: 'https://via.placeholder.com/300x160'); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>" class="w-full h-full object-cover">
                            </div>
                            <div class="p-5">
                                <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full mb-2"><?php echo htmlspecialchars($course['category']); ?></span>
                                <h3 class="font-semibold text-lg mb-2"><?php echo htmlspecialchars($course['title']); ?></h3>
                                <p class="text-gray-600 text-sm mb-4"><?php echo mb_strimwidth(htmlspecialchars($course['description'] ?? 'Описание отсутствует'), 0, 100, '...'); ?></p>
                                <a href="course.php?id=<?php echo $course['id']; ?>" class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 rounded-lg transition duration-200">
                                    Подробнее
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-span-3 text-center py-8">
                        <i class="fas fa-book-open text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">На данный момент нет доступных курсов для рекомендаций.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Вкладка моих курсов -->
    <div id="Courses" class="tabcontent hidden">
        <div class="bg-white rounded-xl shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                <i class="fas fa-graduation-cap mr-3 text-blue-600"></i> Мои курсы
            </h2>
            
            <div class="mb-8">
                <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-play-circle mr-2 text-green-600"></i> Активные курсы
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (!empty($enrollments)): ?>
                        <?php foreach ($enrollments as $course): if (!$course['completed']): ?>
                            <div class="course-card bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                <div class="h-40 bg-gray-200 overflow-hidden">
                                    <img src="<?php echo htmlspecialchars($course['image_url'] ?: 'https://via.placeholder.com/300x160'); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>" class="w-full h-full object-cover">
                                </div>
                                <div class="p-5">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($course['category']); ?></span>
                                        <span class="text-xs text-gray-500">Зачислен: <?php echo date('d.m.Y', strtotime($course['enrolled_at'])); ?></span>
                                    </div>
                                    <h3 class="font-semibold text-lg mb-2"><?php echo htmlspecialchars($course['title']); ?></h3>
                                    
                                    <div class="w-full bg-gray-200 rounded-full h-2.5 mb-4">
                                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $course['progress']; ?>%"></div>
                                    </div>
                                    <div class="flex justify-between items-center mb-4">
                                        <span class="text-sm text-gray-600">Прогресс: <?php echo $course['progress']; ?>%</span>
                                        <span class="text-sm font-medium text-blue-600"><?php echo $course['progress']; ?>%</span>
                                    </div>
                                    
                                    <a href="learning.php?course_id=<?php echo $course['id']; ?>" class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 rounded-lg transition duration-200">
				    Продолжить обучение
					</a>
                                </div>
                            </div>
                        <?php endif; endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-3 text-center py-8">
                            <i class="fas fa-book-open text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">У вас нет активных курсов.</p>
                            <a href="courses.php" class="inline-block mt-4 bg-blue-600 hover:bg-blue-700 text-white py-2 px-6 rounded-lg transition duration-200">
                                Найти курсы
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-gray-700 mb-4 flex items-center">
                    <i class="fas fa-check-circle mr-2 text-green-600"></i> Завершенные курсы
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php if (!empty($enrollments)): ?>
                        <?php foreach ($enrollments as $course): if ($course['completed']): ?>
                            <div class="course-card bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                <div class="h-40 bg-gray-200 overflow-hidden">
                                    <img src="<?php echo htmlspecialchars($course['image_url'] ?: 'https://via.placeholder.com/300x160'); ?>" alt="<?php echo htmlspecialchars($course['title']); ?>" class="w-full h-full object-cover">
                                </div>
                                <div class="p-5">
                                    <div class="flex justify-between items-center mb-2">
                                        <span class="inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded-full"><?php echo htmlspecialchars($course['category']); ?></span>
                                        <span class="text-xs text-gray-500">Завершен: <?php echo date('d.m.Y', strtotime($course['completed_at'])); ?></span>
                                    </div>
                                    <h3 class="font-semibold text-lg mb-2"><?php echo htmlspecialchars($course['title']); ?></h3>
                                    
                                    <div class="flex justify-between items-center mb-4">
                                        <span class="text-sm text-gray-600">Оценка:</span>
                                        <span class="text-lg font-semibold text-blue-600"><?php echo $course['grade'] ?? 'не указана'; ?></span>
                                    </div>
                                    
                                    <a href="course.php?id=<?php echo $course['id']; ?>" class="block w-full bg-gray-100 hover:bg-gray-200 text-gray-800 text-center py-2 rounded-lg transition duration-200">
                                        Посмотреть курс
                                    </a>
                                </div>
                            </div>
                        <?php endif; endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-3 text-center py-8">
                            <i class="fas fa-trophy text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">У вас нет завершенных курсов.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Вкладка редактирования профиля -->
    <div id="EditProfile" class="tabcontent hidden">
        <div class="bg-white rounded-xl shadow-md p-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                <i class="fas fa-user-edit mr-3 text-blue-600"></i> Редактирование профиля
            </h2>
            
            <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <input type="hidden" name="update_profile" value="1">

                <div class="md:col-span-2 text-center">
                    <div class="avatar-upload inline-block relative">
                        <img src="<?php echo $user['avatar'] ?: 'uploads/avatars/default.png'; ?>" class="w-32 h-32 rounded-full mx-auto object-cover mb-2 border-4 border-white shadow-lg">
                        <input type="file" name="avatar" accept="image/*" class="mt-2 absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                    </div>
                    <p class="text-sm text-gray-600 mt-2">Нажмите на фото для загрузки нового</p>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Личная информация</h3>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Имя *</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($user['first_name']); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required maxlength="50">
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Фамилия *</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($user['last_name']); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required maxlength="50">
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">E-mail *</label>
                        <input type="email" name="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Телефон *</label>
                        <input type="tel" name="phone" 
                               pattern="^\+7\s\(\d{3}\)\s\d{3}-\d{2}-\d{2}$"
                               placeholder="+7 (***) ***-**-**"
                               value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                               oninput="formatPhone(this)"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Дата рождения</label>
                        <input type="date" name="birth_date" value="<?php echo htmlspecialchars($user['birth_date'] ?? ''); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Документы</h3>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Паспорт *</label>
                        <input type="text" name="passport"
                               pattern="^\d{2}\s\d{2}\s\d{6}$"
                               placeholder="Серия 00 00 Номер 000000"
                               value="<?php echo htmlspecialchars($user['passport'] ?? ''); ?>"
                               oninput="formatPassport(this)"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">СНИЛС *</label>
                        <input type="text" name="snils"
                               pattern="^\d{3}-\d{3}-\d{3}\s\d{2}$"
                               placeholder="000-000-000 00"
                               value="<?php echo htmlspecialchars($user['snils'] ?? ''); ?>"
                               oninput="formatSnils(this)"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">ИНН *</label>
                        <input type="text" name="inn"
                               pattern="^\d{12}$"
                               placeholder="12 цифр"
                               value="<?php echo htmlspecialchars($user['inn'] ?? ''); ?>"
                               oninput="formatInn(this)"
                               maxlength="12"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Уровень образования *</label>
                        <select name="education_level" class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                            <option value="">Выберите уровень образования</option>
                            <option value="Высшее" <?php echo ($user['education_level'] == 'Высшее') ? 'selected' : ''; ?>>Высшее</option>
                            <option value="Среднее профессиональное" <?php echo ($user['education_level'] == 'Среднее профессиональное') ? 'selected' : ''; ?>>Среднее профессиональное</option>
                            <option value="Среднее общее" <?php echo ($user['education_level'] == 'Среднее общее') ? 'selected' : ''; ?>>Среднее общее</option>
                            <option value="Основное общее" <?php echo ($user['education_level'] == 'Основное общее') ? 'selected' : ''; ?>>Основное общее</option>
                            <option value="Начальное общее" <?php echo ($user['education_level'] == 'Начальное общее') ? 'selected' : ''; ?>>Начальное общее</option>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Номер диплома *</label>
                        <input type="text" name="diploma_number"
                               value="<?php echo htmlspecialchars($user['diploma_number'] ?? ''); ?>"
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required maxlength="100">
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Загрузка документов</h3>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Скан паспорта (все страницы)</label>
                        <div class="file-upload w-full">
                            <div class="file-upload-btn w-full border border-gray-300 rounded-lg py-2 px-4 text-left">
                                <i class="fas fa-upload mr-2"></i>
                                <span><?php echo !empty($user['passport_scan']) ? 'Заменить файл' : 'Выберите файл'; ?></span>
                                <input type="file" name="passport_scan" accept=".jpg,.jpeg,.png,.pdf" onchange="previewFile(this, 'passportPreview')">
                            </div>
                        </div>
                        <div id="passportPreview" class="mt-2">
                            <?php if (!empty($user['passport_scan'])): ?>
                                <?php if (pathinfo($user['passport_scan'], PATHINFO_EXTENSION) === 'pdf'): ?>
                                    <div class="bg-red-100 p-4 rounded border text-center">
                                        <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                        <p class="text-sm font-medium">Загружен PDF документ</p>
                                        <a href="<?php echo $user['passport_scan']; ?>" target="_blank" class="text-blue-600 text-sm">Просмотреть</a>
                                    </div>
                                <?php else: ?>
                                    <img src="<?php echo $user['passport_scan']; ?>" class="w-full h-40 object-contain border rounded">
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Скан диплома об образовании</label>
                        <div class="file-upload w-full">
                            <div class="file-upload-btn w-full border border-gray-300 rounded-lg py-2 px-4 text-left">
                                <i class="fas fa-upload mr-2"></i>
                                <span><?php echo !empty($user['diploma_scan']) ? 'Заменить файл' : 'Выберите файл'; ?></span>
                                <input type="file" name="diploma_scan" accept=".jpg,.jpeg,.png,.pdf" onchange="previewFile(this, 'diplomaPreview')">
                            </div>
                        </div>
                        <div id="diplomaPreview" class="mt-2">
                            <?php if (!empty($user['diploma_scan'])): ?>
                                <?php if (pathinfo($user['diploma_scan'], PATHINFO_EXTENSION) === 'pdf'): ?>
                                    <div class="bg-red-100 p-4 rounded border text-center">
                                        <i class="fas fa-file-pdf text-3xl text-red-600 mb-2"></i>
                                        <p class="text-sm font-medium">Загружен PDF документ</p>
                                        <a href="<?php echo $user['diploma_scan']; ?>" target="_blank" class="text-blue-600 text-sm">Просмотреть</a>
                                    </div>
                                <?php else: ?>
                                    <img src="<?php echo $user['diploma_scan']; ?>" class="w-full h-40 object-contain border rounded">
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">Адрес проживания</h3>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Город *</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($user['city'] ?? ''); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Улица *</label>
                        <input type="text" name="street" value="<?php echo htmlspecialchars($user['street'] ?? ''); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Дом *</label>
                        <input type="text" name="house" value="<?php echo htmlspecialchars($user['house'] ?? ''); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" required>
                    </div>

                    <div class="mb-4 flex items-center">
                        <input type="checkbox" name="is_private_house" id="is_private_house" value="1" 
                               <?php echo !empty($user['is_private_house']) ? 'checked' : ''; ?> onchange="toggleApartmentField()"
                               class="mr-2 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                        <label for="is_private_house" class="text-gray-700 text-sm">Частный дом</label>
                    </div>

                    <div class="mb-4" id="apartment_field">
                        <label class="block text-gray-700 text-sm font-bold mb-1">Квартира</label>
                        <input type="text" name="apartment" value="<?php echo htmlspecialchars($user['apartment'] ?? ''); ?>" 
                               class="w-full border border-gray-300 rounded-lg py-2 px-4 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>

                <div class="md:col-span-2 border-t pt-6 mt-4">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-6 rounded-lg transition duration-200 flex items-center">
                        <i class="fas fa-save mr-2"></i> Сохранить изменения
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<footer class="bg-gray-800 text-white py-8 mt-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div>
                <h3 class="text-lg font-semibold mb-4">БГИТУ - ДПО</h3>
                <p class="text-gray-400">Дополнительное профессиональное образование для специалистов. Повышение квалификации с 1930 года.</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold mb-4">Контакты</h3>
                <p class="text-gray-400"><i class="fas fa-map-marker-alt mr-2"></i> г. Брянск, пр-т Станке Димитрова, 3</p>
                <p class="text-gray-400"><i class="fas fa-phone mr-2"></i> +7 (4832) 12-34-56</p>
                <p class="text-gray-400"><i class="fas fa-envelope mr-2"></i> dpo@bgitu.ru</p>
            </div>
            <div>
                <h3 class="text-lg font-semibold mb-4">Полезные ссылки</h3>
                <ul class="space-y-2">
                    <li><a href="#" class="text-gray-400 hover:text-white transition duration-150">Расписание</a></li>
                    <li><a href="#" class="text-gray-400 hover:text-white transition duration-150">Электронная библиотека</a></li>
                    <li><a href="#" class="text-gray-400 hover:text-white transition duration-150">Контакты преподавателей</a></li>
                </ul>
            </div>
        </div>
        <div class="border-t border-gray-700 mt-8 pt-6 text-center text-gray-400">
            <p>© 2023 БГИТУ - ДПО. Все права защищены.</p>
        </div>
    </div>
</footer>
</body>
</html>