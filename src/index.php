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
    
    // Получение общей статистики
    $result = $mysqli->query("
        SELECT 
            COUNT(*) as total_accounts,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
            SUM(sms_count) as total_sms,
            SUM(numbers_count) as total_numbers,
            SUM(balance) as total_balance
        FROM twilio_accounts
        WHERE status != 'deleted'
    ");
    $stats = $result->fetch_assoc();

    // Получение статистики по дням
    $daily_stats = [];
    $result = $mysqli->query("
        SELECT date, active_accounts, total_sms, total_calls
        FROM daily_stats
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY date ASC
    ");
    while ($row = $result->fetch_assoc()) {
        $daily_stats[] = $row;
    }

    // Параметры пагинации
    $per_page = 50;
    $current_page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    
    // Поиск
    $search_query = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
    $where_clause = "WHERE status != 'deleted'";
    $search_params = [];
    
    if (!empty($search_query)) {
        $where_clause .= " AND (account_sid LIKE ? OR friendly_name LIKE ?)";
        $search_params = ["%{$search_query}%", "%{$search_query}%"];
    }

    // Получение общего количества аккаунтов для пагинации
    if (empty($search_params)) {
        $count_result = $mysqli->query("SELECT COUNT(*) as count FROM twilio_accounts {$where_clause}");
    } else {
        $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM twilio_accounts {$where_clause}");
        $stmt->bind_param(str_repeat('s', count($search_params)), ...$search_params);
        $stmt->execute();
        $count_result = $stmt->get_result();
    }
    
    $total_accounts = $count_result->fetch_assoc()['count'];
    $total_pages = ceil($total_accounts / $per_page);
    $offset = ($current_page - 1) * $per_page;

    // Получение списка аккаунтов
    $query = "
        SELECT 
            ta.*,
            COALESCE(pn.number_count, 0) as number_count,
            COALESCE(pn.total_sms, 0) as total_sms
        FROM twilio_accounts ta
        LEFT JOIN (
            SELECT 
                account_id,
                COUNT(*) as number_count,
                SUM(sms_count) as total_sms
            FROM phone_numbers
            GROUP BY account_id
        ) pn ON ta.numeric_id = pn.account_id
        {$where_clause}
        ORDER BY ta.created_at DESC
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100">
    <!-- Header -->
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
                    <a href="logout.php" class="bg-red-500 text-white px-4 py-2 rounded hover:bg-red-600">
                        Выход
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto px-4 py-6">
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего аккаунтов</div>
                <div class="text-2xl font-bold"><?php echo number_format($stats['total_accounts']); ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Активные аккаунты</div>
                <div class="text-2xl font-bold"><?php echo number_format($stats['active_accounts']); ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего SMS</div>
                <div class="text-2xl font-bold"><?php echo number_format($stats['total_sms']); ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Всего номеров</div>
                <div class="text-2xl font-bold"><?php echo number_format($stats['total_numbers']); ?></div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <div class="text-gray-500 text-sm">Общий баланс</div>
                <div class="text-2xl font-bold">$<?php echo number_format($stats['total_balance'], 2); ?></div>
            </div>
        </div>

        <!-- Charts -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-4">
                <h3 class="text-lg font-semibold mb-4">Активность аккаунтов (30 дней)</h3>
                <div class="w-full h-96"> <!-- Фиксированная высота 24rem (384px) -->
                    <canvas id="accountsChart"></canvas>
                </div>
            </div>
            <div class="bg-white rounded-lg shadow p-4">
                <h3 class="text-lg font-semibold mb-4">SMS активность (30 дней)</h3>
                <div class="w-full h-96"> <!-- Фиксированная высота 24rem (384px) -->
                    <canvas id="smsChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Search -->
        <div class="bg-white rounded-lg shadow p-4 mb-6">
            <form method="GET" class="flex gap-4">
                <div class="flex-1">
                    <input type="text" name="search" 
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           placeholder="Поиск по SID или имени..."
                           class="w-full px-4 py-2 border rounded-lg">
                </div>
                <button type="submit" class="bg-blue-500 text-white px-6 py-2 rounded-lg hover:bg-blue-600">
                    Поиск
                </button>
                <?php if (!empty($search_query)): ?>
                    <a href="?" class="bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600">
                        Сброс
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Accounts Table -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold">
                    Аккаунты (<?php echo number_format($total_accounts); ?>)
                </h2>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Account SID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Номера</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">SMS</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Баланс</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Статус</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Последняя проверка</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php while ($account = $accounts_result->fetch_assoc()): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <a href="account.php?id=<?php echo $account['numeric_id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900">
                                        <?php echo $account['numeric_id']; ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4"><?php echo htmlspecialchars($account['account_sid']); ?></td>
                                <td class="px-6 py-4"><?php echo number_format($account['number_count']); ?></td>
                                <td class="px-6 py-4"><?php echo number_format($account['total_sms']); ?></td>
                                <td class="px-6 py-4">$<?php echo number_format($account['balance'], 2); ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                          <?php echo $account['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo ucfirst($account['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <?php echo $account['last_check'] ? date('d.m.Y H:i', strtotime($account['last_check'])) : 'Никогда'; ?>
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
                            <a href="?page=<?php echo $i; ?><?php echo $search_query ? '&search=' . urlencode($search_query) : ''; ?>"
                               class="px-3 py-1 rounded <?php echo $i === $current_page ? 'bg-blue-500 text-white' : 'bg-gray-200 hover:bg-gray-300'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Chart initialization
        const accountsCtx = document.getElementById('accountsChart').getContext('2d');
        new Chart(accountsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($daily_stats, 'date')); ?>,
                datasets: [{
                    label: 'Активные аккаунты',
                    data: <?php echo json_encode(array_column($daily_stats, 'active_accounts')); ?>,
                    borderColor: 'rgb(59, 130, 246)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        const smsCtx = document.getElementById('smsChart').getContext('2d');
        new Chart(smsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($daily_stats, 'date')); ?>,
                datasets: [{
                    label: 'SMS',
                    data: <?php echo json_encode(array_column($daily_stats, 'total_sms')); ?>,
                    backgroundColor: 'rgba(59, 130, 246, 0.5)',
                    borderColor: 'rgb(59, 130, 246)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Автоматическое обновление статистики каждые 5 минут
        setInterval(function() {
            fetch('update_stats.php')
                .then(response => response.json())
                .then(data => {
                    // Обновляем статистику на странице
                    document.querySelectorAll('[data-stat]').forEach(element => {
                        const stat = element.getAttribute('data-stat');
                        if (data[stat] !== undefined) {
                            element.textContent = new Intl.NumberFormat().format(data[stat]);
                        }
                    });
                })
                .catch(error => console.error('Error updating stats:', error));
        }, 300000); // 5 минут

        // Функция для форматирования чисел
        function formatNumber(number) {
            return new Intl.NumberFormat().format(number);
        }
    </script>
</body>
</html>