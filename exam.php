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

// Получаем информацию о курсе и экзамене
$course_id = $_GET['course_id'] ?? 0;
$course = [];
$exam = null;
$user = [];

if ($course_id) {
    try {
        // Данные курса
        $stmt = $db->prepare("SELECT * FROM courses WHERE id = :id");
        $stmt->execute([':id' => $course_id]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        // Данные экзамена
        $stmt = $db->prepare("SELECT * FROM course_exams WHERE course_id = :course_id");
        $stmt->execute([':course_id' => $course_id]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Данные пользователя
        $stmt = $db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    } catch (PDOException $e) {
        error_log("Ошибка загрузки данных: " . $e->getMessage());
    }
}

if (!$course || !$exam) {
    header('Location: courses.php');
    exit;
}

// Проверяем, записан ли пользователь на курс
$isEnrolled = false;
try {
    $stmt = $db->prepare("SELECT id FROM enrollments WHERE user_id = :user_id AND course_id = :course_id");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':course_id' => $course_id
    ]);
    $isEnrolled = $stmt->fetch() !== false;
} catch (PDOException $e) {
    error_log("Ошибка проверки записи на курс: " . $e->getMessage());
}

if (!$isEnrolled) {
    header('Location: courses.php');
    exit;
}

// Проверяем количество попыток
$attemptsCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) as attempts FROM exam_attempts WHERE user_id = :user_id AND exam_id = :exam_id");
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':exam_id' => $exam['id']
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $attemptsCount = $result['attempts'] ?? 0;
} catch (PDOException $e) {
    error_log("Ошибка проверки попыток: " . $e->getMessage());
}

// Если попытки исчерпаны
if ($attemptsCount >= $exam['max_attempts']) {
    $maxAttemptsReached = true;
} else {
    $maxAttemptsReached = false;
}

// Обработка отправки экзамена
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam']) && !$maxAttemptsReached) {
    // Здесь будет обработка ответов на вопросы экзамена
    
    // Сохраняем попытку
    try {
        $stmt = $db->prepare("INSERT INTO exam_attempts (user_id, exam_id, score, total_questions, passed, attempted_at) 
                              VALUES (:user_id, :exam_id, :score, :total_questions, :passed, CURRENT_TIMESTAMP)");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':exam_id' => $exam['id'],
            ':score' => 0, // Здесь будет реальный счет
            ':total_questions' => 0, // Здесь будет количество вопросов
            ':passed' => false // Здесь будет результат
        ]);
        
        // Получаем ID последней попытки
        $attempt_id = $db->lastInsertId();
        
        // Сохраняем фото с веб-камеры, если оно было отправлено
        if (!empty($_POST['webcam_photo'])) {
            $photo_data = $_POST['webcam_photo'];
            // Убираем префикс data:image/png;base64,
            $photo_data = str_replace('data:image/png;base64,', '', $photo_data);
            $photo_data = str_replace(' ', '+', $photo_data);
            $photo_binary = base64_decode($photo_data);
            
            // Генерируем имя файла
            $filename = 'exam_photo_' . $attempt_id . '_' . time() . '.png';
            $filepath = 'uploads/exam_photos/' . $filename;
            
            // Сохраняем файл
            file_put_contents($filepath, $photo_binary);
            
            // Сохраняем путь к файлу в базе данных
            $stmt = $db->prepare("UPDATE exam_attempts SET verification_photo = :photo_path WHERE id = :attempt_id");
            $stmt->execute([
                ':photo_path' => $filepath,
                ':attempt_id' => $attempt_id
            ]);
        }
        
        // Перенаправляем на страницу с результатами
        header("Location: exam_results.php?attempt_id=$attempt_id");
        exit;
        
    } catch (PDOException $e) {
        error_log("Ошибка сохранения попытки экзамена: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Экзамен: <?php echo htmlspecialchars($course['title']); ?> - БГИТУ</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/feather-icons/dist/feather.min.js"></script>
    <style>
        .webcam-container {
            position: relative;
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
        }
        #webcam-video, #webcam-photo {
            width: 100%;
            border-radius: 8px;
            border: 2px solid #e5e7eb;
        }
        .webcam-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.7);
            pointer-events: none;
        }
        .passport-frame {
            width: 80%;
            height: 60%;
            border: 2px dashed white;
            border-radius: 8px;
            margin-bottom: 10px;
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
                <a href="learning.php?course_id=<?php echo $course_id; ?>" class="mr-4">Вернуться к курсу</a>
                <a href="logout.php">Выйти</a>
            </div>
        </div>
    </nav>

    <div class="max-w-4xl mx-auto py-6 px-4">
        <div class="bg-white shadow rounded-lg p-6">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-2xl font-bold">Экзамен: <?php echo htmlspecialchars($course['title']); ?></h1>
                <div class="flex items-center">
                    <i data-feather="clock" class="h-5 w-5 mr-1"></i>
                    <span id="timer" class="font-medium">00:00:00</span>
                </div>
            </div>

            <?php if ($maxAttemptsReached): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <div class="flex items-center">
                        <i data-feather="alert-circle" class="h-6 w-6 mr-2"></i>
                        <h2 class="font-bold">Превышено количество попыток</h2>
                    </div>
                    <p class="mt-2">Вы исчерпали все доступные попытки сдачи этого экзамена (максимум: <?php echo $exam['max_attempts']; ?>).</p>
                    <p class="mt-1">Обратитесь к администратору для получения дополнительных попыток.</p>
                </div>
            <?php else: ?>
                <div class="mb-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div class="bg-blue-50 p-4 rounded-lg">
                            <h3 class="font-medium text-blue-800 mb-2">Информация об экзамене</h3>
                            <p class="text-sm"><span class="font-medium">Время:</span> <?php echo $exam['time_limit_minutes']; ?> минут</p>
                            <p class="text-sm"><span class="font-medium">Проходной балл:</span> <?php echo $exam['passing_score']; ?>%</p>
                            <p class="text-sm"><span class="font-medium">Попытка:</span> <?php echo $attemptsCount + 1; ?> из <?php echo $exam['max_attempts']; ?></p>
                        </div>
                        <div class="bg-green-50 p-4 rounded-lg">
                            <h3 class="font-medium text-green-800 mb-2">Инструкция</h3>
                            <p class="text-sm">1. Подготовьте свой паспорт для верификации</p>
                            <p class="text-sm">2. Сделайте фото с паспортом с помощью веб-камеры</p>
                            <p class="text-sm">3. Ответьте на вопросы экзамена</p>
                        </div>
                    </div>

                    <?php if (!empty($exam['description'])): ?>
                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                            <h3 class="font-medium text-gray-800 mb-2">Описание экзамена</h3>
                            <p class="text-sm"><?php echo nl2br(htmlspecialchars($exam['description'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <form id="exam-form" method="POST">
                    <!-- Шаг 1: Верификация с помощью веб-камеры -->
                    <div class="mb-8">
                        <h2 class="text-xl font-bold mb-4 flex items-center">
                            <span class="bg-blue-600 text-white rounded-full w-8 h-8 flex items-center justify-center mr-2">1</span>
                            Верификация личности
                        </h2>
                        
                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                            <p class="text-sm text-gray-600 mb-4">
                                Для подтверждения личности необходимо сделать фотографию с вашим паспортом. 
                                Расположите паспорт так, чтобы были видны ваше фото и основные данные.
                            </p>
                            
                            <div class="webcam-container mb-4">
                                <video id="webcam-video" autoplay playsinline></video>
                                <canvas id="webcam-canvas" style="display: none;"></canvas>
                                <div class="webcam-overlay">
                                    <div class="passport-frame"></div>
                                    <p>Расположите паспорт здесь</p>
                                </div>
                            </div>
                            
                            <div id="webcam-controls" class="flex flex-col items-center">
                                <button type="button" id="start-camera" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded mb-2 flex items-center">
                                    <i data-feather="camera" class="h-4 w-4 mr-2"></i> Включить камеру
                                </button>
                                
                                <div id="capture-controls" style="display: none;">
                                    <button type="button" id="take-photo" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded mb-2 flex items-center">
                                        <i data-feather="aperture" class="h-4 w-4 mr-2"></i> Сделать фото
                                    </button>
                                </div>
                            </div>
                            
                            <div id="photo-preview" style="display: none;" class="mt-4">
                                <h3 class="font-medium mb-2">Предпросмотр фото</h3>
                                <img id="webcam-photo" src="" alt="Ваше фото">
                                <div class="mt-2 flex space-x-2">
                                    <button type="button" id="retake-photo" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded flex items-center">
                                        <i data-feather="refresh-cw" class="h-4 w-4 mr-2"></i> Переснять
                                    </button>
                                    <button type="button" id="confirm-photo" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded flex items-center">
                                        <i data-feather="check" class="h-4 w-4 mr-2"></i> Подтвердить фото
                                    </button>
                                </div>
                            </div>
                            
                            <input type="hidden" id="webcam-photo-data" name="webcam_photo">
                        </div>
                    </div>

                    <!-- Шаг 2: Вопросы экзамена -->
                    <div id="exam-questions" style="display: none;">
                        <h2 class="text-xl font-bold mb-4 flex items-center">
                            <span class="bg-blue-600 text-white rounded-full w-8 h-8 flex items-center justify-center mr-2">2</span>
                            Вопросы экзамена
                        </h2>
                        
                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                            <?php
                            // Загрузка вопросов экзамена
                            $questions = [];
                            if (!empty($exam['questions'])) {
                                $questions = json_decode($exam['questions'], true);
                            }
                            
                            if (empty($questions)): ?>
                                <p class="text-gray-600">Вопросы к экзамену еще не добавлены.</p>
                            <?php else: ?>
                                <?php foreach ($questions as $index => $question): ?>
                                    <div class="question-block mb-6 p-4 bg-white rounded-lg shadow-sm">
                                        <h3 class="font-medium mb-2">Вопрос <?php echo $index + 1; ?>:</h3>
                                        <p class="mb-3"><?php echo htmlspecialchars($question['question']); ?></p>
                                        
                                        <?php if ($question['type'] === 'multiple_choice'): ?>
                                            <div class="space-y-2">
                                                <?php foreach ($question['options'] as $optionIndex => $option): ?>
                                                    <label class="flex items-center">
                                                        <input type="radio" name="answers[<?php echo $index; ?>]" value="<?php echo $optionIndex; ?>" class="mr-2">
                                                        <?php echo htmlspecialchars($option); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php elseif ($question['type'] === 'text'): ?>
                                            <textarea name="answers[<?php echo $index; ?>]" class="w-full p-2 border rounded" rows="4" placeholder="Введите ваш ответ"></textarea>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <div class="mt-6">
                                    <button type="submit" name="submit_exam" class="bg-green-600 hover:bg-green-700 text-white px-6 py-3 rounded-lg font-medium flex items-center mx-auto">
                                        <i data-feather="check-circle" class="h-5 w-5 mr-2"></i> Завершить экзамен
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>


<script>
        feather.replace();
        
        // Таймер экзамена
        const examTimeLimit = <?php echo $exam['time_limit_minutes'] * 60; ?>; // в секундах
        let timeLeft = examTimeLimit;
        const timerElement = document.getElementById('timer');
        
        function updateTimer() {
            const hours = Math.floor(timeLeft / 3600);
            const minutes = Math.floor((timeLeft % 3600) / 60);
            const seconds = timeLeft % 60;
            
            timerElement.textContent = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                alert('Время экзамена истекло!');
                document.getElementById('exam-form').submit();
            }
            
            timeLeft--;
        }
        
        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();
        
        // Работа с веб-камерой
        const startCameraBtn = document.getElementById('start-camera');
        const takePhotoBtn = document.getElementById('take-photo');
        const retakePhotoBtn = document.getElementById('retake-photo');
        const confirmPhotoBtn = document.getElementById('confirm-photo');
        const videoElement = document.getElementById('webcam-video');
        const canvasElement = document.getElementById('webcam-canvas');
        const photoElement = document.getElementById('webcam-photo');
        const photoDataInput = document.getElementById('webcam-photo-data');
        const captureControls = document.getElementById('capture-controls');
        const photoPreview = document.getElementById('photo-preview');
        const examQuestions = document.getElementById('exam-questions');
        
        let stream = null;
        
        // Проверяем поддержку API медиа-устройств
        function hasGetUserMedia() {
            return !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
        }
        
        // Показываем сообщение об ошибке
        function showError(message) {
            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4';
            errorDiv.innerHTML = `
                <div class="flex items-center">
                    <i data-feather="alert-circle" class="h-6 w-6 mr-2"></i>
                    <strong class="font-bold">Ошибка: </strong>
                    <span class="block sm:inline ml-1">${message}</span>
                </div>
            `;
            
            const webcamContainer = document.querySelector('.bg-gray-50');
            webcamContainer.insertBefore(errorDiv, webcamContainer.firstChild);
            feather.replace();
        }
        
        // Проверяем поддержку камеры при загрузке страницы
        if (!hasGetUserMedia()) {
            showError('Ваш браузер не поддерживает доступ к камере. Пожалуйста, используйте современный браузер (Chrome, Firefox, Edge).');
            startCameraBtn.disabled = true;
        }
        
        startCameraBtn.addEventListener('click', async () => {
            try {
                // Запрашиваем доступ к камере
                stream = await navigator.mediaDevices.getUserMedia({ 
                    video: { 
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                        facingMode: 'user' // Используем фронтальную камеру
                    }, 
                    audio: false 
                });
                
                videoElement.srcObject = stream;
                captureControls.style.display = 'block';
                startCameraBtn.style.display = 'none';
                
            } catch (err) {
                console.error('Ошибка доступа к камере:', err);
                
                let errorMessage = 'Не удалось получить доступ к камере. ';
                
                if (err.name === 'NotAllowedError') {
                    errorMessage += 'Вы отказали в разрешении на использование камеры.';
                } else if (err.name === 'NotFoundError' || err.name === 'OverconstrainedError') {
                    errorMessage += 'Камера не найдена или не поддерживает требуемые параметры.';
                } else if (err.name === 'NotReadableError') {
                    errorMessage += 'Камера уже используется другим приложением.';
                } else if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                    errorMessage += 'Для доступа к камере требуется HTTPS соединение.';
                } else {
                    errorMessage += 'Проверьте разрешения и попробуйте снова.';
                }
                
                showError(errorMessage);
            }
        });
        
        takePhotoBtn.addEventListener('click', () => {
            const context = canvasElement.getContext('2d');
            canvasElement.width = videoElement.videoWidth;
            canvasElement.height = videoElement.videoHeight;
            
            // Рисуем текущий кадр видео на canvas
            context.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
            
            // Преобразуем в data URL
            const dataUrl = canvasElement.toDataURL('image/png');
            photoElement.src = dataUrl;
            photoDataInput.value = dataUrl;
            
            // Показываем превью
            photoPreview.style.display = 'block';
            captureControls.style.display = 'none';
            
            // Останавливаем видео
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
        
        retakePhotoBtn.addEventListener('click', () => {
            photoPreview.style.display = 'none';
            startCameraBtn.style.display = 'block';
            startCameraBtn.click();
        });
        
        confirmPhotoBtn.addEventListener('click', () => {
            // Показываем вопросы экзамена после подтверждения фото
            examQuestions.style.display = 'block';
            
            // Прокручиваем к вопросам
            examQuestions.scrollIntoView({ behavior: 'smooth' });
        });
        
        // Остановка камеры при закрытии страницы
        window.addEventListener('beforeunload', () => {
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
            }
        });
    </script>
</body>
</html>