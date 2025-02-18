<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    die(json_encode(['error' => 'Unauthorized']));
}

try {
    $mysqli = getDB();

    // Получаем актуальную статистику
    $stats = $mysqli->query("
        SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status = 'active' THEN 1 END) as active,
            COUNT(CASE WHEN status = 'suspended' THEN 1 END) as suspended
        FROM twilio_accounts 
        WHERE status != 'deleted'
    ")->fetch_assoc();

    // Отправляем JSON с данными
    header('Content-Type: application/json');
    echo json_encode([
        'processed' => $_SESSION['processed_accounts'] ?? 0,
        'total' => $stats['total'],
        'active' => $stats['active'],
        'suspended' => $stats['suspended']
    ]);

} catch (Exception $e) {
    error_log("Error in get_progress.php: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
}