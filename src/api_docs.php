<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$mysqli = getDB();
$API_KEY = getApiKey($mysqli);
?>
<!DOCTYPE html>
<html>
<head>
    <title>API Documentation - Twilio Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <span class="text-xl font-bold">Twilio Manager API</span>
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
            <!-- API Key Section -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-4">API Ключ</h2>
                <div class="bg-gray-100 p-4 rounded font-mono select-all">
                    <?php echo htmlspecialchars($API_KEY); ?>
                </div>
                <p class="mt-2 text-sm text-gray-600">
                    Добавляйте этот ключ к каждому запросу в параметре key
                </p>
            </div>

            <!-- Base URL Section -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-4">Базовый URL</h2>
                <div class="bg-gray-100 p-4 rounded font-mono">
                    https://<?php echo $_SERVER['HTTP_HOST']; ?>/api.php
                </div>
            </div>

            <!-- Methods Section -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-4">Методы API</h2>

                <!-- Get Accounts -->
                <div class="border rounded-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-2">Получение списка активных аккаунтов</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=get_accounts&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Возвращает список всех активных аккаунтов с их основными параметрами.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "accounts": [
        {
            "numeric_id": 1,
            "account_sid": "AC...",
            "status": "active",
            "numbers_count": 5,
            "sms_count": 100,
            "balance": 50.00
        },
        ...
    ]
}</pre>
                    </div>
                </div>

                <!-- Get Account Status -->
                <div class="border rounded-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-2">Получение статуса аккаунта</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=get_status&account_id={ID}&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Возвращает детальную информацию о конкретном аккаунте.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "account": {
        "id": 1,
        "account_sid": "AC...",
        "status": "active",
        "numbers_count": 5,
        "sms_count": 100,
        "balance": 50.00,
        "last_check": "2025-02-12 15:30:00"
    }
}</pre>
                    </div>
                </div>

                <!-- Get Numbers -->
                <div class="border rounded-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-2">Получение номеров аккаунта</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=show_numbers&account_id={ID}&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Возвращает список всех активных номеров аккаунта.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "numbers": [
        {
            "phone_number": "+1234567890",
            "status": "active",
            "sms_count": 50
        },
        ...
    ]
}</pre>
                    </div>
                </div>

                <!-- Get SMS -->
                <div class="border rounded-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-2">Получение SMS аккаунта</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=get_sms&account_id={ID}&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Возвращает список SMS сообщений аккаунта.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "messages": {
        "status": "success",
        "data": {
            "messages": [
                {
                    "date_created": "2025-02-12T15:30:00Z",
                    "direction": "inbound",
                    "status": "received"
                },
                ...
            ]
        }
    }
}</pre>
                    </div>
                </div>

                <!-- Delete SMS -->
                <div class="border rounded-lg p-6 mb-6">
                    <h3 class="text-xl font-semibold mb-2">Удаление SMS аккаунта</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=delete_sms&account_id={ID}&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Удаляет все SMS сообщения аккаунта.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "deletion_result": {
        "status": "success",
        "deleted": 50,
        "failed": 0
    }
}</pre>
                    </div>
                </div>

                <!-- Get Balance -->
                <div class="border rounded-lg p-6">
                    <h3 class="text-xl font-semibold mb-2">Получение баланса аккаунта</h3>
                    <div class="bg-gray-100 p-4 rounded font-mono mb-4">
                        GET /api.php?command=get_balance&account_id={ID}&key={API_KEY}
                    </div>
                    <p class="text-gray-600 mb-4">
                        Возвращает текущий баланс аккаунта.
                    </p>
                    <div class="bg-gray-100 p-4 rounded">
                        <pre class="text-sm">Response: {
    "success": true,
    "balance": {
        "status": "success",
        "data": {
            "balance": "50.00",
            "currency": "usd"
        }
    }
}</pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Add copy functionality to code blocks
        document.querySelectorAll('pre').forEach(block => {
            block.style.cursor = 'pointer';
            block.onclick = function() {
                const text = this.textContent;
                navigator.clipboard.writeText(text).then(() => {
                    // Visual feedback
                    this.style.backgroundColor = '#e2e8f0';
                    setTimeout(() => {
                        this.style.backgroundColor = '';
                    }, 200);
                });
            };
        });
    </script>
</body>
</html>