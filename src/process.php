<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Включаем буферизацию вывода
ob_start();

// Отключаем вывод ошибок напрямую
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Функция для отправки JSON-ответа
function sendJsonResponse($data) {
    ob_clean();
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    echo json_encode($data);
    exit;
}

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    sendJsonResponse(['status' => 'error', 'message' => 'Unauthorized']);
}

// Функция для отправки сообщений в лог
function addMessage($messages, $text, $type = 'info') {
    $messages[] = [
        'text' => $text,
        'type' => $type,
        'time' => date('H:i:s')
    ];
    return $messages;
}

// Функция для парсинга строки прокси
function parseProxyString($proxy_string) {
    if (empty($proxy_string)) return null;

    if (preg_match('/^socks5:\/\/([^:]+):([^@]+)@([^:]+):(\d+)$/', $proxy_string, $matches)) {
        return [
            'type' => CURLPROXY_SOCKS5,
            'username' => $matches[1],
            'password' => $matches[2],
            'host' => $matches[3],
            'port' => $matches[4]
        ];
    }
    return null;
}

// Функция для выполнения запроса к Twilio API
function twilioRequest($url, $account_sid, $auth_token, $proxy_string = '') {
    $ch = curl_init();
    
    // Базовые настройки
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Authorization: Basic ' . base64_encode($account_sid . ':' . $auth_token)
        ]
    ]);
    
    // Настройка прокси
    if (!empty($proxy_string)) {
        $proxy = parseProxyString($proxy_string);
        if ($proxy) {
            curl_setopt_array($ch, [
                CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
                CURLOPT_PROXY => $proxy['host'],
                CURLOPT_PROXYPORT => $proxy['port'],
                CURLOPT_PROXYUSERPWD => $proxy['username'] . ':' . $proxy['password']
            ]);
            
            // Опционально: прокси тоже должен использовать SSL
            curl_setopt($ch, CURLOPT_PROXY_SSL_VERIFYPEER, false);
        }
    }
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception($error);
    }
    
    curl_close($ch);
    
    $data = json_decode($response, true);
    
    if ($http_code === 401) {
        throw new Exception("AUTH ERROR: Account is suspended", 401);
    }
    
    if ($http_code !== 200) {
        throw new Exception("API request failed with code $http_code: " . ($data['message'] ?? $response));
    }
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("JSON decode error: " . json_last_error_msg());
    }
    
    return $data;
}

$messages = [];
try {
    $mysqli = getDB();
    
    // Инициализация процесса обновления
    if (!isset($_SESSION['update_in_progress'])) {
        $_SESSION['update_in_progress'] = date('Y-m-d H:i:s');
        $_SESSION['processed_accounts'] = 0;
        $_SESSION['log_messages'] = [];
        $messages = addMessage($messages, "🔄 Начало процесса обновления");
    }
    
    // Получаем следующий аккаунт для обработки
    $query = "SELECT numeric_id, account_sid, auth_token, proxy_address, status 
              FROM twilio_accounts 
              WHERE status != 'deleted' 
              AND (last_check < ? OR last_check IS NULL)
              ORDER BY last_check ASC, numeric_id ASC
              LIMIT 1";
              
    $stmt = $mysqli->prepare($query);
    $stmt->bind_param('s', $_SESSION['update_in_progress']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($account = $result->fetch_assoc()) {
        $messages = addMessage($messages, "🔍 Проверка аккаунта {$account['account_sid']} (текущий статус: {$account['status']})");
        
        try {
            // Получаем базовую информацию
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$account['account_sid']}.json";
            $account_info = twilioRequest($url, $account['account_sid'], $account['auth_token'], $account['proxy_address']);
            $messages = addMessage($messages, "✓ Базовая информация получена");
            
            // Получаем баланс
            $url_balance = "https://api.twilio.com/2010-04-01/Accounts/{$account['account_sid']}/Balance.json";
            $balance_info = twilioRequest($url_balance, $account['account_sid'], $account['auth_token'], $account['proxy_address']);
            $balance = $balance_info['balance'] ?? 0;
            $messages = addMessage($messages, "💰 Баланс: $" . number_format($balance, 2), 'success');
            
            // Получаем номера
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$account['account_sid']}/IncomingPhoneNumbers.json";
            $numbers = twilioRequest($url, $account['account_sid'], $account['auth_token'], $account['proxy_address']);
            $numbers_count = count($numbers['incoming_phone_numbers'] ?? []);
            $messages = addMessage($messages, "📱 Активных номеров: $numbers_count");
            
            // Обновляем информацию в базе
            $mysqli->begin_transaction();
            
            $update_query = "UPDATE twilio_accounts SET 
                balance = ?,
                numbers_count = ?,
                status = 'active',
                last_check = NOW(),
                last_error = NULL
                WHERE numeric_id = ?";
            
            $stmt = $mysqli->prepare($update_query);
            $stmt->bind_param('dii', 
                $balance,
                $numbers_count,
                $account['numeric_id']
            );
            $stmt->execute();
            
            $mysqli->commit();
            $messages = addMessage($messages, "✅ Аккаунт активен", 'success');
            
        } catch (Exception $e) {
            if (isset($mysqli) && $mysqli->connect_errno === 0) {
                $mysqli->rollback();
            }
            
            $error_msg = $e->getMessage();
            $status = 'suspended';
            
            if (stripos($error_msg, 'authenticate') !== false || stripos($error_msg, 'auth error') !== false || stripos($error_msg, '401') !== false) {
                $messages = addMessage($messages, "⚠️ Аккаунт приостановлен: Ошибка авторизации", 'warning');
            } elseif (stripos($error_msg, 'curl') !== false || stripos($error_msg, 'timeout') !== false) {
                $messages = addMessage($messages, "⚠️ Аккаунт приостановлен: Ошибка соединения - " . $error_msg, 'warning');
            } else {
                $messages = addMessage($messages, "⚠️ Аккаунт приостановлен: " . $error_msg, 'warning');
            }
            
            // Обновляем статус аккаунта
            $update_query = "UPDATE twilio_accounts SET 
                status = ?,
                last_check = NOW(),
                last_error = ?,
                balance = 0,
                numbers_count = 0
                WHERE numeric_id = ?";
            
            $stmt = $mysqli->prepare($update_query);
            $error_msg = substr($error_msg, 0, 255);
            $stmt->bind_param('ssi', $status, $error_msg, $account['numeric_id']);
            $stmt->execute();
            
            $messages = addMessage($messages, "📝 Статус обновлен в базе данных", 'info');
        }
        
        // Увеличиваем счетчик и сохраняем сообщения
        $_SESSION['processed_accounts'] = ($_SESSION['processed_accounts'] ?? 0) + 1;
        $_SESSION['log_messages'] = array_merge($_SESSION['log_messages'] ?? [], $messages);
        
        sendJsonResponse([
            'status' => 'processing',
            'messages' => $messages,
            'progress' => [
                'processed' => $_SESSION['processed_accounts']
            ]
        ]);
        
    } else {
        // Все аккаунты обработаны
        $final_message = "✨ Обновление завершено";
        $_SESSION['log_messages'][] = ['text' => $final_message, 'type' => 'success', 'time' => date('H:i:s')];
        
        sendJsonResponse([
            'status' => 'completed',
            'messages' => [['text' => $final_message, 'type' => 'success', 'time' => date('H:i:s')]]
        ]);
    }
    
} catch (Exception $e) {
    $error_message = "❌ Критическая ошибка: " . $e->getMessage();
    $_SESSION['log_messages'][] = ['text' => $error_message, 'type' => 'error', 'time' => date('H:i:s')];
    
    sendJsonResponse([
        'status' => 'error',
        'messages' => [['text' => $error_message, 'type' => 'error', 'time' => date('H:i:s')]]
    ]);
}