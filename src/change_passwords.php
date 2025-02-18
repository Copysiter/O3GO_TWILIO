<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $mysqli = getDB();
    $error = '';
    $success = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $admin_password = $_POST['admin_password'] ?? '';
        $api_password = $_POST['api_password'] ?? '';
        
        if (!empty($admin_password)) {
            // Обновление пароля админа
            $hashed_password = password_hash($admin_password, PASSWORD_DEFAULT);
            $stmt = $mysqli->prepare("UPDATE admins SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_password, $_SESSION['admin_id']);
            $stmt->execute();
            $success .= "Пароль администратора успешно обновлен.\n";
        }
        
        if (!empty($api_password)) {
            // Обновление API ключа
            $stmt = $mysqli->prepare("UPDATE settings SET value = ? WHERE name = 'api_key'");
            $stmt->bind_param("s", $api_password);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $success .= "API ключ успешно обновлен.";
            } else {
                // Если записи нет, создаем новую
                $stmt = $mysqli->prepare("INSERT INTO settings (name, value) VALUES ('api_key', ?)");
                $stmt->bind_param("s", $api_password);
                $stmt->execute();
                $success .= "API ключ успешно создан.";
            }
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Смена паролей - Twilio Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center">
        <div class="max-w-md w-full bg-white rounded-lg shadow-lg p-8">
            <h2 class="text-2xl font-bold mb-8 text-center">Смена паролей</h2>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo nl2br(htmlspecialchars($success)); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Новый пароль администратора
                    </label>
                    <input type="password" name="admin_password" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">
                        Новый API ключ
                    </label>
                    <input type="text" name="api_password" 
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                
                <div class="flex justify-between">
                    <button type="submit" 
                            class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                        Сохранить
                    </button>
                    
                    <a href="index.php" 
                       class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                        Назад
                    </a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>