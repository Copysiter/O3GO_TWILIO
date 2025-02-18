<?php
require_once '../config.php';
require_once '../functions.php';

// Проверка авторизации
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $mysqli = getDB();
    
    // Получаем актуальную статистику
    $query = "
        SELECT 
            COUNT(*) as total_accounts,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
            SUM(numbers_count) as total_numbers,
            SUM(sms_count) as total_sms
        FROM twilio_accounts 
        WHERE status != 'deleted'
    ";
    
    $result = $mysqli->query($query);
    $stats = $result->fetch_assoc();
    
    // Получаем статистику за последние 30 дней
    $daily_stats_query = "
        SELECT date, active_accounts, total_sms, total_calls
        FROM daily_stats 
        WHERE date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY date ASC
    ";
    
    $daily_stats_result = $mysqli->query($daily_stats_query);
    $daily_stats = [];
    
    while ($row = $daily_stats_result->fetch_assoc()) {
        $daily_stats[] = [
            'date' => $row['date'],
            'active_accounts' => (int)$row['active_accounts'],
            'total_sms' => (int)$row['total_sms'],
            'total_calls' => (int)$row['total_calls']
        ];
    }
    
    // Формируем ответ
    $response = [
        'status' => 'success',
        'total_accounts' => (int)$stats['total_accounts'],
        'active_accounts' => (int)$stats['active_accounts'],
        'total_numbers' => (int)$stats['total_numbers'],
        'total_sms' => (int)$stats['total_sms'],
        'daily_stats' => $daily_stats
    ];
    
    // Обновляем дневную статистику
    updateDailyStats($mysqli);
    
    // Отправляем ответ
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}
?>