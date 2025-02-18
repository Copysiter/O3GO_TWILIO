<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка если уже авторизован
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Проверка на количество попыток входа
        checkLoginAttempts();

        $username = trim($_POST['username']);
        $password = $_POST['password'];

        if (empty($username) || empty($password)) {
            throw new Exception('Все поля обязательны для заполнения');
        }

        $mysqli = getDB();
        
        $stmt = $mysqli->prepare("
            SELECT id, username, password, last_login 
            FROM admins 
            WHERE username = ?
        ");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($admin = $result->fetch_assoc()) {
            if (password_verify($password, $admin['password'])) {
                // Успешный вход
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_last_login'] = $admin['last_login'];
                
                // Обновляем время последнего входа
                $stmt = $mysqli->prepare("
                    UPDATE admins 
                    SET last_login = NOW() 
                    WHERE id = ?
                ");
                $stmt->bind_param("i", $admin['id']);
                $stmt->execute();

                // Логируем успешный вход
                logAdminAction($mysqli, $admin['id'], 'login', 'Successful login');
                
                // Сброс счетчика попыток входа
                unset($_SESSION['login_attempts']);
                
                // Перенаправление на главную
                header('Location: index.php');
                exit;
            }
        }

        // Увеличиваем счетчик неудачных попыток
        $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
        
        throw new Exception('Неверные учетные данные');
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        logAdminAction($mysqli ?? null, 0, 'login_failed', $error);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Login - Twilio Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="max-w-md w-full mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-6 space-y-6">
            <div class="text-center">
                <h1 class="text-2xl font-bold">Twilio Manager</h1>
                <p class="text-gray-600">Вход в систему</p>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Имя пользователя</label>
                    <input type="text" name="username" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700">Пароль</label>
                    <input type="password" name="password" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring focus:ring-blue-500">
                </div>

                <button type="submit" 
                        class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Войти
                </button>
            </form>
        </div>
    </div>
</body>
</html>