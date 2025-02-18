<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

try {
    $mysqli = getDB();
    
    // Получение обновленной статистики
    $result = $mysqli->query("
        SELECT 
            COUNT(*) as total_accounts,
            SUM(sms_count) as current_sms,
            SUM(numbers_count) as current_numbers,
            SUM(balance) as total_balance,
            (
                SELECT COUNT(DISTINCT phone_number) 
                FROM phone_numbers 
                WHERE status = 'active'
            ) as active_numbers,
            (
                SELECT COUNT(DISTINCT phone_number) 
                FROM phone_numbers
            ) as total_numbers_ever,
            (
                SELECT COUNT(*) 
                FROM sms_archive
            ) as total_sms_archive,
            (
                SELECT COUNT(*) 
                FROM sms_archive 
                WHERE DATE(date_created) = CURDATE()
            ) as today_sms
        FROM twilio_accounts
        WHERE status != 'deleted'
    ");
    
    $stats = $result->fetch_assoc();
    
    // Добавляем дополнительные вычисления
    $stats['total_sms'] = $stats['current_sms'] + $stats['total_sms_archive'];
    $stats['total_numbers'] = $stats['total_numbers_ever'];
    
    // Отправляем данные в формате JSON
    header('Content-Type: application/json');
    echo json_encode($stats);

} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => $e->getMessage()]);
}
?>