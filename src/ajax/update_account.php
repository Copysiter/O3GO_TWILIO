<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new Exception('Invalid security token');
    }

    if (!isset($_POST['account_id'])) {
        throw new Exception('Account ID is required');
    }

    $mysqli = getDB();
    $account_id = (int)$_POST['account_id'];

    // Обновляем информацию об аккаунте
    if (!updateAccountInfo($mysqli, $account_id)) {
        throw new Exception('Failed to update account');
    }

    // Получаем обновленные данные
    $stmt = $mysqli->prepare("
        SELECT 
            numeric_id,
            status,
            friendly_name,
            numbers_count,
            sms_count,
            balance,
            last_check
        FROM twilio_accounts 
        WHERE numeric_id = ?
    ");

    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();

    if (!$account) {
        throw new Exception('Account not found');
    }

    echo json_encode([
        'status' => 'success',
        'account_status' => $account['status'],
        'numbers_count' => (int)$account['numbers_count'],
        'sms_count' => (int)$account['sms_count'],
        'balance' => (float)$account['balance'],
        'last_check' => date('d.m.Y H:i', strtotime($account['last_check'])),
        'friendly_name' => $account['friendly_name']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}