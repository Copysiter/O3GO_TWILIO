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

// Если нажата кнопка стоп
if (isset($_POST['stop'])) {
    unset($_SESSION['update_in_progress']);
    // Оставляем лог для отображения
}

// Если нажата кнопка старт
if (isset($_POST['start'])) {
    $_SESSION['update_in_progress'] = date('Y-m-d H:i:s');
    $_SESSION['processed_accounts'] = 0;
    $_SESSION['log_messages'] = [];
}

// Получаем статистику
$stats = $mysqli->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
        COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended
    FROM twilio_accounts 
    WHERE status != 'deleted'
")->fetch_assoc();

$processed_accounts = isset($_SESSION['processed_accounts']) ? $_SESSION['processed_accounts'] : 0;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Обновление аккаунтов - Twilio Manager</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .log-message { 
            padding: 4px 8px; 
            border-radius: 4px;
            margin: 2px 0;
        }
        .log-message.error { 
            background-color: #FEE2E2; 
            color: #DC2626; 
        }
        .log-message.warning { 
            background-color: #FEF3C7; 
            color: #D97706; 
        }
        .log-message.success { 
            background-color: #D1FAE5; 
            color: #059669; 
        }
        .log-message .time {
            color: #6B7280;
            font-size: 0.8em;
            margin-right: 8px;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="max-w-7xl mx-auto px-4 py-6">
        <div class="bg-white rounded-lg shadow-lg p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-bold">Обновление аккаунтов</h2>
                <a href="index.php" class="bg-gray-500 text-white px-4 py-2 rounded hover:bg-gray-600">
                    Вернуться в админку
                </a>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-blue-50 rounded-lg p-4">
                    <div class="text-blue-800">Всего аккаунтов</div>
                    <div class="text-3xl font-bold text-blue-900" id="total-accounts">
                        <?php echo $stats['total']; ?>
                    </div>
                </div>
                <div class="bg-green-50 rounded-lg p-4">
                    <div class="text-green-800">Активные</div>
                    <div class="text-3xl font-bold text-green-900" id="active-accounts">
                        <?php echo $stats['active']; ?>
                    </div>
                </div>
                <div class="bg-yellow-50 rounded-lg p-4">
                    <div class="text-yellow-800">Приостановленные</div>
                    <div class="text-3xl font-bold text-yellow-900" id="suspended-accounts">
                        <?php echo $stats['suspended']; ?>
                    </div>
                </div>
            </div>

            <!-- Progress Section -->
            <div class="mb-6">
                <div class="flex justify-between items-center mb-2">
                    <div class="text-lg" id="progress-text">
                        Прогресс: <?php echo $processed_accounts; ?> из <?php echo $stats['total']; ?> аккаунтов
                    </div>
                    <div>
                        <?php if (isset($_SESSION['update_in_progress'])): ?>
                            <form method="POST" class="inline">
                                <button type="submit" name="stop" class="bg-red-500 text-white px-6 py-2 rounded hover:bg-red-600">
                                    Остановить обновление
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="POST" class="inline">
                                <button type="submit" name="start" class="bg-blue-500 text-white px-6 py-2 rounded hover:bg-blue-600">
                                    Начать обновление
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Progress Bar -->
                <div class="relative pt-1">
                    <div class="overflow-hidden h-2 mb-4 text-xs flex rounded bg-blue-100">
                        <div class="shadow-none flex flex-col text-center whitespace-nowrap text-white justify-center bg-blue-500 transition-all duration-500" 
                             style="width: <?php echo ($stats['total'] > 0 ? ($processed_accounts / $stats['total'] * 100) : 0); ?>%"
                             id="progress-bar">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Log Window -->
            <div id="log" class="bg-gray-50 p-4 rounded-lg h-96 overflow-y-auto font-mono text-sm">
                <?php
                if (isset($_SESSION['log_messages'])) {
                    foreach ($_SESSION['log_messages'] as $message) {
                        echo '<div class="log-message ' . ($message['type'] ?? 'info') . '">';
                        echo '<span class="time">[' . ($message['time'] ?? '') . ']</span>';
                        echo htmlspecialchars($message['text']);
                        echo '</div>';
                    }
                }
                ?>
            </div>
        </div>
    </div>

    <script>
    let updateInProgress = <?php echo isset($_SESSION['update_in_progress']) ? 'true' : 'false'; ?>;
    let totalAccounts = <?php echo $stats['total']; ?>;

    function addLogMessage(message, type = 'info', time = null) {
        const log = document.getElementById('log');
        const div = document.createElement('div');
        div.className = 'log-message ' + type;
        
        // Добавляем время
        const timeSpan = document.createElement('span');
        timeSpan.className = 'time';
        timeSpan.textContent = '[' + (time || new Date().toLocaleTimeString()) + ']';
        div.appendChild(timeSpan);
        
        // Добавляем текст сообщения
        div.appendChild(document.createTextNode(message));
        
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function updateProgress(processed, total) {
        const percentage = (processed / total * 100).toFixed(1);
        document.getElementById('progress-bar').style.width = percentage + '%';
        document.getElementById('progress-text').textContent = 
            `Прогресс: ${processed} из ${total} аккаунтов`;
    }

    function updateStats() {
        if (!updateInProgress) return;
        
        fetch('get_progress.php')
            .then(response => response.json())
            .then(data => {
                document.getElementById('active-accounts').textContent = data.active;
                document.getElementById('suspended-accounts').textContent = data.suspended;
                document.getElementById('total-accounts').textContent = data.total;
                updateProgress(data.processed, data.total);
            })
            .catch(error => console.error('Error updating stats:', error));
    }

    function processAccounts() {
        if (!updateInProgress) return;

        fetch('process.php')
            .then(response => response.json())
            .then(data => {
                if (data.messages) {
                    data.messages.forEach(message => {
                        addLogMessage(message.text, message.type, message.time);
                    });
                }

                if (data.status === 'processing') {
                    setTimeout(processAccounts, 1000);
                    updateStats();
                } else if (data.status === 'completed') {
                    updateInProgress = false;
                    addLogMessage('Обновление завершено', 'success');
                    setTimeout(() => window.location.reload(), 2000);
                }
            })
            .catch(error => {
                addLogMessage('Ошибка: ' + error.message, 'error');
                setTimeout(processAccounts, 5000);
            });
    }

    // Start processing if update is in progress
    if (updateInProgress) {
        processAccounts();
        // Обновляем статистику каждые 5 секунд
        setInterval(updateStats, 5000);
    }
    </script>
</body>
</html>