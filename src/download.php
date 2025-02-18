<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$accounts_text = '';
$error = null;
$stats = [
    'total_accounts' => 0,
    'active_accounts' => 0,
    'suspended_accounts' => 0
];

try {
    $mysqli = getDB();
    
    // Получаем статистику по аккаунтам
    $query = "
        SELECT 
            COUNT(*) as total_accounts,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active_accounts,
            COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended_accounts
        FROM twilio_accounts 
        WHERE status != 'deleted'
    ";
    
    $result = $mysqli->query($query);
    if ($result) {
        $stats = $result->fetch_assoc();
    }

    // Получаем данные аккаунтов
    $accounts_query = $mysqli->query("
        SELECT 
            account_sid,
            auth_token,
            proxy_address,
            status
        FROM twilio_accounts 
        WHERE status != 'deleted'
        ORDER BY numeric_id ASC
    ");

    if ($accounts_query) {
        while ($row = $accounts_query->fetch_assoc()) {
            if ($row['status'] === 'active') {
                $acc_data = array_filter([
                    $row['account_sid'],
                    $row['auth_token'],
                    $row['proxy_address'] ?? ''
                ], function($value) {
                    return $value !== null && $value !== '';
                });
                
                $accounts_text .= implode(";", $acc_data) . "\n";
            }
        }
    } else {
        error_log("MySQL Error: " . $mysqli->error);
        throw new Exception("Ошибка при получении данных аккаунтов: " . $mysqli->error);
    }

    // Если запрошено скачивание файла
    if (isset($_POST['download']) && !empty($accounts_text)) {
        $filename = 'twilio_accounts_' . date('Y-m-d_H-i-s') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Записываем BOM для UTF-8
        echo chr(0xEF) . chr(0xBB) . chr(0xBF);
        
        // Записываем заголовки
        echo "Account SID;Auth Token;Proxy Address\n";
        
        // Записываем данные
        echo $accounts_text;
        exit();
    }
    
} catch (Exception $e) {
    error_log("Download page error: " . $e->getMessage());
    $error = "Произошла ошибка при загрузке данных: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Выгрузка аккаунтов - Twilio Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-xl font-bold">Twilio Manager</span>
                </div>
                <div class="flex items-center">
                    <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600 mr-4">
                        Вернуться в админку
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <h2 class="text-2xl font-bold mb-6">Выгрузка аккаунтов</h2>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <div class="bg-blue-50 rounded-lg p-4 border border-blue-200">
                    <div class="text-blue-800 text-lg font-semibold">Всего аккаунтов</div>
                    <div class="text-3xl font-bold text-blue-900 mt-2">
                        <?php echo number_format($stats['total_accounts']); ?>
                    </div>
                </div>
                <div class="bg-green-50 rounded-lg p-4 border border-green-200">
                    <div class="text-green-800 text-lg font-semibold">Активные аккаунты</div>
                    <div class="text-3xl font-bold text-green-900 mt-2">
                        <?php echo number_format($stats['active_accounts']); ?>
                    </div>
                </div>
                <div class="bg-red-50 rounded-lg p-4 border border-red-200">
                    <div class="text-red-800 text-lg font-semibold">Приостановленные</div>
                    <div class="text-3xl font-bold text-red-900 mt-2">
                        <?php echo number_format($stats['suspended_accounts']); ?>
                    </div>
                </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold">Данные аккаунтов</h3>
                    <div class="space-x-2">
                        <button onclick="copyToClipboard()" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
                            Копировать
                        </button>
                        <form method="POST" class="inline">
                            <button type="submit" name="download" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                                Скачать CSV
                            </button>
                        </form>
                    </div>
                </div>
                <textarea id="accountsData" class="w-full h-96 p-4 font-mono text-sm border rounded bg-white" readonly><?php echo $accounts_text; ?></textarea>
            </div>
        </div>
    </div>

    <script>
    function copyToClipboard() {
        const textarea = document.getElementById('accountsData');
        textarea.select();
        document.execCommand('copy');
        
        // Показываем уведомление
        const button = event.target;
        const originalText = button.textContent;
        button.textContent = 'Скопировано!';
        button.classList.remove('bg-green-500', 'hover:bg-green-600');
        button.classList.add('bg-gray-500', 'hover:bg-gray-600');
        
        setTimeout(() => {
            button.textContent = originalText;
            button.classList.remove('bg-gray-500', 'hover:bg-gray-600');
            button.classList.add('bg-green-500', 'hover:bg-green-600');
        }, 2000);
    }
    </script>
</body>
</html>