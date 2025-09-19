<?php
function handleFileUpload($file, $allowedTypes = []) {
    $uploadDir = 'uploads/';
    
    // Создаем директорию, если ее нет
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Проверяем ошибки загрузки
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Ошибка загрузки файла: ' . $file['error']];
    }
    
    // Проверяем тип файла
    $fileType = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!empty($allowedTypes) && !in_array($fileType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Недопустимый тип файла. Разрешены: ' . implode(', ', $allowedTypes)];
    }
    
    // Генерируем уникальное имя файла
    $uniqueName = uniqid() . '_' . time() . '.' . $fileType;
    $targetPath = $uploadDir . $uniqueName;
    
    // Перемещаем файл
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return [
            'success' => true,
            'original_name' => $file['name'],
            'stored_name' => $uniqueName,
            'file_path' => $targetPath,
            'file_size' => $file['size'],
            'file_type' => $fileType
        ];
    } else {
        return ['success' => false, 'error' => 'Не удалось сохранить файл'];
    }
}

function saveFileToDatabase($db, $fileInfo) {
    try {
        $query = "INSERT INTO uploaded_files (original_name, stored_name, file_path, file_size, file_type) 
                  VALUES (:original_name, :stored_name, :file_path, :file_size, :file_type)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':original_name', $fileInfo['original_name']);
        $stmt->bindParam(':stored_name', $fileInfo['stored_name']);
        $stmt->bindParam(':file_path', $fileInfo['file_path']);
        $stmt->bindParam(':file_size', $fileInfo['file_size']);
        $stmt->bindParam(':file_type', $fileInfo['file_type']);
        
        if ($stmt->execute()) {
            return $db->lastInsertId();
        }
    } catch (PDOException $e) {
        error_log("Ошибка сохранения файла в БД: " . $e->getMessage());
    }
    return false;
}

function getFileExtensionIcon($extension) {
    $icons = [
        'pdf' => 'file-text',
        'doc' => 'file-text',
        'docx' => 'file-text',
        'txt' => 'file-text',
        'ppt' => 'presentation',
        'pptx' => 'presentation',
        'mp4' => 'video',
        'mov' => 'video',
        'avi' => 'video',
        'zip' => 'archive',
        'rar' => 'archive',
    ];
    
    return $icons[strtolower($extension)] ?? 'file';
}
?>