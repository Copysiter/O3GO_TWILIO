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
    
    // Определяем режим отображения (активные/архивные)
    $show_archived = isset($_GET['show_archived']);
    $table = $show_archived ? 'twilio_accounts_archive' : 'twilio_accounts';
    
    // Получение общей статистики с учетом архива
    $result = $mysqli->query("
        SELECT 
            COALESCE(COUNT(*), 0) as total_accounts,
            COALESCE(SUM(numbers_count), 0) as current_numbers,
            COALESCE(SUM(balance), 0) as total_balance,
            (
                SELECT COALESCE(COUNT(DISTINCT phone_number), 0)
                FROM phone_numbers 
                WHERE status = 'active'
            ) as active_numbers,
            (
                SELECT COALESCE(COUNT(DISTINCT phone_number), 0)
                FROM phone_numbers
            ) as total_numbers_ever,
            (
                SELECT COALESCE(SUM(total_sms_count), 0)
                FROM phone_numbers
                WHERE status = 'active'
            ) as total_sms,
            (
                SELECT COALESCE(SUM(daily_sms_count), 0)
                FROM phone_numbers
                WHERE status = 'active'
                AND last_sms_date = CURDATE()
            ) as today_sms,
            (
                SELECT COALESCE(COUNT(*), 0)
                FROM " . ($show_archived ? 'twilio_accounts_archive' : 'twilio_accounts') . "
                WHERE status " . ($show_archived ? "!= 'deleted'" : "= 'active'") . "
            ) as filtered_accounts
        FROM " . ($show_archived ? 'twilio_accounts_archive' : 'twilio_accounts') . "
        WHERE " . ($show_archived ? "1=1" : "status = 'active'")
    );
    $stats = $result->fetch_assoc();

    // Безопасное преобразование значений
    $stats['total_sms'] = (int)$stats['total_sms'];
    $stats['today_sms'] = (int)$stats['today_sms'];
    $stats['total_numbers'] = (int)$stats['total_numbers_ever'];
    $stats['total_balance'] = (float)$stats['total_balance'];
	// Параметры пагинации
    $per_page = 50;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    
    // Поиск
    $search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $where_clause = $show_archived ? "WHERE 1=1" : "WHERE status = 'active'";
    $search_params = [];
    
    if (!empty($search_query)) {
        $where_clause .= " AND (account_sid LIKE ? OR friendly_name LIKE ?)";
        $search_params = ["%{$search_query}%", "%{$search_query}%"];
    }

    // Получение общего количества аккаунтов для пагинации
    if (empty($search_params)) {
        $count_result = $mysqli->query("SELECT COUNT(*) as count FROM {$table} {$where_clause}");
    } else {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM {$table} {$where_clause}");
        $stmt->bind_param(str_repeat('s', count($search_params)), ...$search_params);
        $stmt->execute();
        $count_result = $stmt->get_result();
    }
    
    $total_accounts = $count_result->fetch_assoc()['count'];
    $total_pages = ceil($total_accounts / $per_page);
    $offset = ($current_page - 1) * $per_page;

    // Получение списка аккаунтов с дополнительной статистикой
    $query = "
        SELECT 
            ta.*,
            COALESCE(pn.active_numbers, 0) as active_numbers,
            COALESCE(pn.total_numbers, 0) as total_numbers,
            COALESCE(pn.total_sms, 0) as total_sms,
            COALESCE(pn.today_sms, 0) as today_sms
        FROM {$table} ta
        LEFT JOIN (
            SELECT 
                account_id,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_numbers,
                COUNT(*) as total_numbers,
                SUM(CASE WHEN status = 'active' THEN total_sms_count ELSE 0 END) as total_sms,
                SUM(CASE 
                    WHEN status = 'active' 
                    AND last_sms_date = CURDATE() 
                    THEN daily_sms_count 
                    ELSE 0 
                END) as today_sms
            FROM phone_numbers
            GROUP BY account_id
        ) pn ON ta.numeric_id = pn.account_id
        {$where_clause}
        ORDER BY ta." . ($show_archived ? 'archived_at' : 'created_at') . " DESC
        LIMIT ? OFFSET ?
    ";

    if (!empty($search_params)) {
        $stmt = $mysqli->prepare($query);
        $params = array_merge($search_params, [$per_page, $offset]);
        $stmt->bind_param(str_repeat('s', count($search_params)) . 'ii', ...$params);
    } else {
        $stmt = $mysqli->prepare($query);
        $stmt->bind_param('ii', $per_page, $offset);
    }
    
    $stmt->execute();
    $accounts_result = $stmt->get_result();

    // Обновление суточной статистики
    updateDailyStats($mysqli);
    
} catch (Exception $e) {
    $error = $e->getMessage();
    error_log("Admin panel error: " . $error);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Twilio Manager - Admin Panel</title>
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
                    <span class="text-gray-600 mr-4">
                        <?php echo htmlspecialchars($_SESSION['admin_username']); ?> |
                        Последний вход: <?php echo date('d.m.Y H:i', strtotime($_SESSION['admin_last_login'])); ?>
                    </span>
                    <a href="change_passwords.php" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600 mr-4">
                        Сменить пароли
                    </a>
                    <a href="upload.php" class="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600 mr-4">
                        Загрузить акки
                    </a>
                    <a href="download.php" class="bg-purple-500 text-white px-4 py-2 rounded hover:bg-purple-600 mr-4">
                        Выгрузить акки
                    </a>
                    <a href="updater.php" class="bg-yellow-500 text-white px-4 py-2 rounded hover:bg-yellow-600 mr-4">
                        Обновить
                    </a>
                    <a href="api_docs.php" class="bg-indigo-500 text-white px-4 py-2 rounded hover:bg-indigo-600 mr-4">
                        API
                    </a>
                    <a href="logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                        Выход
                    </a>
                </div>
            </div>
        </div>
    </nav>
	<div class="max-w-7xl mx-auto px-4 py-6">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего аккаунтов</div>
                <div class="text-2xl font-bold" data-stat="total_accounts">
                    <?php echo number_format($stats['total_accounts']); ?>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Активные номера</div>
                <div class="text-2xl font-bold" data-stat="active_numbers">
                    <?php echo number_format($stats['active_numbers']); ?>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего номеров</div>
                <div class="text-2xl font-bold" data-stat="total_numbers">
                    <?php echo number_format($stats['total_numbers']); ?>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего SMS</div>
                <div class="text-2xl font-bold" data-stat="total_sms">
                    <?php echo number_format($stats['total_sms']); ?>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">SMS сегодня</div>
                <div class="text-2xl font-bold" data-stat="today_sms">
                    <?php echo number_format($stats['today_sms']); ?>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Общий баланс</div>
                <div class="text-2xl font-bold" data-stat="total_balance">
                    $<?php echo number_format($stats['total_balance'], 2); ?>
                </div>
            </div>
        </div>

        <!-- Search and Mode Selection -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <div class="flex justify-between items-center">
                <form method="GET" class="flex-1 mr-4">
                    <?php if ($show_archived): ?>
                        <input type="hidden" name="show_archived" value="1">
                    <?php endif; ?>
                    <div class="flex gap-4">
                        <input type="text" name="search" 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               placeholder="Поиск по SID или имени..."
                               class="flex-1 px-4 py-2 border rounded-lg">
                        <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600">
                            Поиск
                        </button>
                        <?php if (!empty($search_query)): ?>
                            <a href="?<?php echo $show_archived ? 'show_archived=1' : ''; ?>" 
                               class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600">
                                Сброс
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <div class="flex gap-2">
                    <a href="?" class="px-4 py-2 rounded <?php echo !$show_archived ? 'bg-blue-500 text-white hover:bg-blue-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                        Активные
                    </a>
                    <a href="?show_archived=1" class="px-4 py-2 rounded <?php echo $show_archived ? 'bg-blue-500 text-white hover:bg-blue-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?>">
                        Архивные
                    </a>
                </div>
            </div>
        </div>

        <!-- Accounts Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold">
                    <?php echo $show_archived ? 'Архивные аккаунты' : 'Активные аккаунты'; ?> 
                    (<?php echo number_format($total_accounts); ?>)
                </h2>
            </div>
			<div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Активные номера</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Всего номеров</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SMS всего</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SMS сегодня</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Баланс</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Последняя проверка</th>
                            <?php if ($show_archived): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Причина архивации</th>
                            <?php endif; ?>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while ($account = $accounts_result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50" data-account-id="<?php echo $account['numeric_id']; ?>">
                                <td class="px-6 py-4">
                                    <a href="account.php?id=<?php echo $account['numeric_id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900">
                                        <?php echo $account['numeric_id']; ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 active-numbers"><?php echo number_format($account['active_numbers']); ?></td>
                                <td class="px-6 py-4 total-numbers"><?php echo number_format($account['total_numbers']); ?></td>
                                <td class="px-6 py-4 total-sms">
                                    <?php echo number_format($account['total_sms']); ?>
                                </td>
                                <td class="px-6 py-4 today-sms"><?php echo number_format($account['today_sms']); ?></td>
                                <td class="px-6 py-4 balance">$<?php echo number_format($account['balance'], 2); ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                          <?php 
                                          if ($account['status'] === 'active') {
                                              echo 'bg-green-100 text-green-800';
                                          } elseif ($account['status'] === 'suspended') {
                                              echo 'bg-yellow-100 text-yellow-800';
                                          } else {
                                              echo 'bg-red-100 text-red-800';
                                          }
                                          ?>">
                                        <?php echo ucfirst($account['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 last-check">
                                    <?php echo $account['last_check'] ? date('d.m.Y H:i', strtotime($account['last_check'])) : 'Никогда'; ?>
                                </td>
                                <?php if ($show_archived): ?>
                                    <td class="px-6 py-4 text-sm text-gray-500">
                                        <?php echo htmlspecialchars($account['archive_reason'] ?? ''); ?>
                                    </td>
                                <?php endif; ?>
                                <td class="px-6 py-4 text-sm text-right space-x-2">
                                    <?php if (!$show_archived): ?>
                                        <button onclick="checkAccount(<?php echo $account['numeric_id']; ?>)" 
                                                class="inline-flex items-center px-3 py-1 bg-blue-100 text-blue-800 rounded-full hover:bg-blue-200">
                                            Проверить
                                        </button>
                                        <button onclick="getNumbers(<?php echo $account['numeric_id']; ?>)"
                                                class="inline-flex items-center px-3 py-1 bg-green-100 text-green-800 rounded-full hover:bg-green-200">
                                            Номера
                                        </button>
                                        <button onclick="getSMS(<?php echo $account['numeric_id']; ?>)"
                                                class="inline-flex items-center px-3 py-1 bg-purple-100 text-purple-800 rounded-full hover:bg-purple-200">
                                            SMS
                                        </button>
                                    <?php else: ?>
                                        <button onclick="restoreAccount(<?php echo $account['numeric_id']; ?>)"
                                                class="inline-flex items-center px-3 py-1 bg-green-100 text-green-800 rounded-full hover:bg-green-200">
                                            Восстановить
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
			<!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                    <div class="flex justify-center gap-2">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?><?php echo $show_archived ? '&show_archived=1' : ''; ?>"
                               class="px-3 py-1 rounded <?php echo $i === $current_page ? 'bg-blue-500 text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Window -->
    <div id="modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4">
            <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modal-title">Информация</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-500">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
            <div class="px-6 py-4" id="modal-content">
                <!-- Dynamic content will be inserted here -->
            </div>
            <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
                <button onclick="closeModal()" class="px-4 py-2 bg-gray-500 text-white rounded hover:bg-gray-600">
                    Закрыть
                </button>
            </div>
        </div>
    </div>
	<script>
        // Функции для работы с модальным окном
        function showModal(title, content) {
            document.getElementById('modal-title').textContent = title;
            document.getElementById('modal-content').innerHTML = content;
            document.getElementById('modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('modal').classList.add('hidden');
        }

        // Функция обновления статистики конкретного аккаунта
        function updateAccountStats(accountId, data) {
            const row = document.querySelector(`tr[data-account-id="${accountId}"]`);
            if (row) {
                if (data.total_sms !== undefined) row.querySelector('.total-sms').textContent = new Intl.NumberFormat().format(data.total_sms);
                if (data.today_sms !== undefined) row.querySelector('.today-sms').textContent = new Intl.NumberFormat().format(data.today_sms);
                if (data.active_numbers !== undefined) row.querySelector('.active-numbers').textContent = new Intl.NumberFormat().format(data.active_numbers);
                if (data.balance !== undefined) row.querySelector('.balance').textContent = '$' + new Intl.NumberFormat().format(data.balance);
                if (data.last_check) row.querySelector('.last-check').textContent = new Date(data.last_check).toLocaleString();
            }
        }

        // Функция для проверки аккаунта
        async function checkAccount(accountId) {
            try {
                const response = await fetch(`api3.php?command=get_status&account_id=${accountId}&key=wserbytwrdfkyukiuykuykyukyu777`);
                const data = await response.json();
                
                if (data.success) {
                    updateAccountStats(accountId, data);
                    let content = `
                        <div class="space-y-2">
                            <p><strong>Статус:</strong> ${data.status || 'Неизвестно'}</p>
                            <p><strong>Баланс:</strong> $${new Intl.NumberFormat().format(data.balance || 0)}</p>
                            <p><strong>Номеров:</strong> ${new Intl.NumberFormat().format(data.numbers_count || 0)}</p>
                            <p><strong>SMS всего:</strong> ${new Intl.NumberFormat().format(data.total_sms || 0)}</p>
                            <p><strong>SMS сегодня:</strong> ${new Intl.NumberFormat().format(data.today_sms || 0)}</p>
                            <p><strong>Последняя проверка:</strong> ${new Date().toLocaleString()}</p>
                        </div>
                    `;
                    showModal('Информация об аккаунте', content);
                } else {
                    showModal('Ошибка', `<p class="text-red-600">${data.error || 'Ошибка получения данных'}</p>`);
                }
            } catch (error) {
                console.error('Error:', error);
                showModal('Ошибка', `<p class="text-red-600">Произошла ошибка при получении данных</p>`);
            }
        }

        // Функция для получения номеров
        async function getNumbers(accountId) {
            try {
                const response = await fetch(`api3.php?command=show_numbers&account_id=${accountId}&key=wserbytwrdfkyukiuykuykyukyu777`);
                const data = await response.json();
                
                if (data.success) {
                    let content = '<div class="space-y-2">';
                    if (data.numbers && data.numbers.length > 0) {
                        content += '<table class="min-w-full divide-y divide-gray-200">';
                        content += '<thead><tr><th class="px-4 py-2">Номер</th><th class="px-4 py-2">Статус</th><th class="px-4 py-2">SMS</th></tr></thead>';
                        content += '<tbody>';
                        data.numbers.forEach(number => {
                            content += `
                                <tr>
                                    <td class="px-4 py-2">${number.phone_number}</td>
                                    <td class="px-4 py-2">${number.status}</td>
                                    <td class="px-4 py-2">${new Intl.NumberFormat().format(number.sms_count || 0)}</td>
                                </tr>
                            `;
                        });
                        content += '</tbody></table>';
                    } else {
                        content += '<p>Нет активных номеров</p>';
                    }
                    content += '</div>';
                    showModal('Номера аккаунта', content);
                } else {
                    showModal('Ошибка', `<p class="text-red-600">${data.error || 'Нет номеров'}</p>`);
                }
            } catch (error) {
                console.error('Error:', error);
                showModal('Ошибка', `<p class="text-red-600">Произошла ошибка при получении данных</p>`);
            }
        }

        // Функция для получения SMS
        async function getSMS(accountId) {
            try {
                const response = await fetch(`api3.php?command=get_sms&account_id=${accountId}&key=wserbytwrdfkyukiuykuykyukyu777`);
                const data = await response.json();
                
                if (data.success) {
                    let content = '<div class="space-y-2">';
                    if (data.messages && data.messages.length > 0) {
                        content += '<table class="min-w-full divide-y divide-gray-200">';
                        content += '<thead><tr><th class="px-4 py-2">Дата</th><th class="px-4 py-2">Отправитель</th><th class="px-4 py-2">Получатель</th><th class="px-4 py-2">Статус</th></tr></thead>';
                        content += '<tbody>';
                        data.messages.forEach(message => {
                            content += `
                                <tr>
                                    <td class="px-4 py-2">${new Date(message.date_sent || message.date_created).toLocaleString()}</td>
                                    <td class="px-4 py-2">${message.from}</td>
                                    <td class="px-4 py-2">${message.to}</td>
                                    <td class="px-4 py-2">${message.status}</td>
                                </tr>
                            `;
                        });
                        content += '</tbody></table>';
                    } else {
                        content += '<p>Нет SMS сообщений</p>';
                    }
                    content += '</div>';
                    showModal('SMS аккаунта', content);
                } else {
                    showModal('Ошибка', `<p class="text-red-600">${data.error || 'Нет сообщений'}</p>`);
                }
            } catch (error) {
                console.error('Error:', error);
                showModal('Ошибка', `<p class="text-red-600">Произошла ошибка при получении данных</p>`);
            }
        }

        // Функция для восстановления аккаунта из архива
        async function restoreAccount(accountId) {
            if (!confirm('Вы уверены, что хотите восстановить этот аккаунт?')) return;
            
            try {
                const response = await fetch('restore_account.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        account_id: accountId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    window.location.reload();
                } else {
                    showModal('Ошибка', `<p class="text-red-600">${data.error}</p>`);
                }
            } catch (error) {
                showModal('Ошибка', `<p class="text-red-600">Произошла ошибка при восстановлении аккаунта</p>`);
            }
        }

        // Автоматическое обновление статистики каждые 5 минут
        setInterval(async function() {
            try {
                const response = await fetch('update_stats.php');
                const data = await response.json();
                
                // Обновляем общую статистику
                document.querySelectorAll('[data-stat]').forEach(element => {
                    const stat = element.getAttribute('data-stat');
                    if (data[stat] !== undefined) {
                        if (stat.includes('balance')) {
                            element.textContent = '$' + new Intl.NumberFormat().format(data[stat]);
                        } else {
                            element.textContent = new Intl.NumberFormat().format(data[stat]);
                        }
                    }
                });

                // Обновляем статистику каждого аккаунта в таблице
                if (data.accounts) {
                    data.accounts.forEach(account => {
                        updateAccountStats(account.numeric_id, account);
                    });
                }
            } catch (error) {
                console.error('Error updating stats:', error);
            }
        }, 300000); // 5 минут
    </script>
</body>
</html>