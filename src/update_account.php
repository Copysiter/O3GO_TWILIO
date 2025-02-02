<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit('Unauthorized');
}

// Проверка ID аккаунта
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.1 400 Bad Request');
    exit('Invalid account ID');
}

try {
    $account_id = (int)$_GET['id'];
    $mysqli = getDB();
    
    // Обновляем информацию об аккаунте
    $updated = updateAccountInfo($mysqli, $account_id);
    
    // Возвращаем результат
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $updated ? 'success' : 'error',
        'message' => $updated ? 'Account updated successfully' : 'Failed to update account'
    ]);
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}