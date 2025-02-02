<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions.php';

// Включаем отображение ошибок
ini_set('display_errors', 0);

try {
    // Устанавливаем заголовки
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    // API ключ
    define('API_KEY', '43ert43534tergreggdfgf');

    // Проверка API ключа
    function checkApiKey($key) {
        return $key === API_KEY;
    }

    // CURL запросы
    function executeCurlRequest($url, $account_sid, $auth_token, $proxy, $method = 'GET') {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);

        if ($error = curl_error($ch)) {
            $result = ['status' => 'error', 'error' => "CURL Error: $error"];
        } else {
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($http_code >= 200 && $http_code < 300) {
                $result = ['status' => 'success', 'data' => json_decode($response, true)];
            } else {
                $result = ['status' => 'error', 'error' => "HTTP Error: $http_code"];
            }
        }
        curl_close($ch);
        return $result;
    }
	// Функции для SMS
    function getTwilioMessages($account_sid, $auth_token, $proxy) {
        return executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json",
            $account_sid,
            $auth_token,
            $proxy
        );
    }

    function deleteTwilioMessages($account_sid, $auth_token, $proxy) {
        $messages = getTwilioMessages($account_sid, $auth_token, $proxy);
        if ($messages['status'] !== 'success') {
            return $messages;
        }

        $deleted = 0;
        $failed = 0;
        foreach ($messages['data']['messages'] ?? [] as $message) {
            $result = executeCurlRequest(
                "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages/{$message['sid']}.json",
                $account_sid,
                $auth_token,
                $proxy,
                'DELETE'
            );
            $result['status'] === 'success' ? $deleted++ : $failed++;
        }

        return ['status' => 'success', 'deleted' => $deleted, 'failed' => $failed];
    }

    // Получение баланса
    function getTwilioBalance($account_sid, $auth_token, $proxy) {
        return executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Balance.json",
            $account_sid,
            $auth_token,
            $proxy
        );
    }

    // Получение SID номера по номеру телефона
    function getTwilioNumberSid($account_sid, $auth_token, $proxy, $phone_number) {
        $result = executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json",
            $account_sid,
            $auth_token,
            $proxy
        );
        
        if ($result['status'] === 'success' && isset($result['data']['incoming_phone_numbers'])) {
            foreach ($result['data']['incoming_phone_numbers'] as $number) {
                if ($number['phone_number'] === '+' . $phone_number) {
                    return $number['sid'];
                }
            }
        }
        return null;
    }

    // Функция удаления номера
    function deleteTwilioNumber($account_sid, $auth_token, $proxy, $phone_number) {
        // Сначала получаем SID номера
        $number_sid = getTwilioNumberSid($account_sid, $auth_token, $proxy, $phone_number);
        
        if (!$number_sid) {
            return ['status' => 'error', 'error' => 'Number SID not found'];
        }

        return executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers/{$number_sid}.json",
            $account_sid,
            $auth_token,
            $proxy,
            'DELETE'
        );
    }

    // Проверка входных параметров
    if (!isset($_GET['key']) || !checkApiKey($_GET['key'])) {
        throw new Exception('Invalid API key');
    }

    if (!isset($_GET['account_id'])) {
        throw new Exception('Account ID is required');
    }
	$mysqli = getDB();
    $account_id = (int)$_GET['account_id'];
    
    $stmt = $mysqli->prepare("
        SELECT * FROM twilio_accounts 
        WHERE numeric_id = ? AND status != 'deleted'
    ");
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();

    if (!$account) {
        throw new Exception('Account not found');
    }

    if (empty($account['proxy_address'])) {
        throw new Exception('No proxy configured for this account');
    }

    $command = $_GET['command'] ?? '';
    $response = ['success' => true];

    switch ($command) {
        case 'get_accounts':
            $result = $mysqli->query("
                SELECT numeric_id, account_sid, status, numbers_count, sms_count, balance, last_check 
                FROM twilio_accounts 
                WHERE status != 'deleted'
                ORDER BY numeric_id DESC
            ");
            $response['accounts'] = $result->fetch_all(MYSQLI_ASSOC);
            break;

        case 'get_balance':
            $balance = getTwilioBalance($account['account_sid'], $account['auth_token'], $account['proxy_address']);
            if ($balance['status'] === 'success') {
                $stmt = $mysqli->prepare("UPDATE twilio_accounts SET balance = ?, last_check = NOW() WHERE numeric_id = ?");
                $stmt->bind_param("di", $balance['data']['balance'], $account_id);
                $stmt->execute();
            }
            $response['balance'] = $balance;
            break;

        case 'get_numbers':
            $country = $_GET['country'] ?? 'US';
            if (!in_array($country, ['US', 'CA', 'GB'])) {
                throw new Exception('Invalid country code');
            }
            
            $result = acquireNewNumber(
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address'],
                $country
            );

            if ($result['status'] === 'success') {
                // Добавляем номер в базу
                $stmt = $mysqli->prepare("
                    INSERT INTO phone_numbers (
                        account_id, phone_number, status
                    ) VALUES (?, ?, 'active')
                ");
                $stmt->bind_param("is", $account_id, $result['phone_number']);
                $stmt->execute();

                // Обновляем количество номеров в аккаунте
                $mysqli->query("
                    UPDATE twilio_accounts 
                    SET numbers_count = numbers_count + 1 
                    WHERE numeric_id = {$account_id}
                ");
            }
            
            $response['result'] = $result;
            break;
			case 'get_status':
            $response['account'] = [
                'id' => $account['numeric_id'],
                'account_sid' => $account['account_sid'],
                'status' => $account['status'],
                'numbers_count' => $account['numbers_count'],
                'sms_count' => $account['sms_count'],
                'balance' => $account['balance'],
                'proxy' => $account['proxy_address'],
                'last_check' => $account['last_check'],
            ];
            break;

        case 'show_numbers':
            $stmt = $mysqli->prepare("
                SELECT phone_number, status, sms_count
                FROM phone_numbers 
                WHERE account_id = ? AND status = 'active'
            ");
            $stmt->bind_param('i', $account_id);
            $stmt->execute();
            $numbers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            
            if (!empty($numbers)) {
                $response['numbers'] = $numbers;
            } else {
                $response['numbers'] = [];
            }
            break;

        case 'get_sms':
            $messages = getTwilioMessages($account['account_sid'], $account['auth_token'], $account['proxy_address']);
            $response['messages'] = $messages;
            
            if ($messages['status'] === 'success' && isset($messages['data']['messages'])) {
                $sms_count = count($messages['data']['messages']);
                $stmt = $mysqli->prepare("UPDATE twilio_accounts SET sms_count = ? WHERE numeric_id = ?");
                $stmt->bind_param("ii", $sms_count, $account_id);
                $stmt->execute();
            }
            break;

        case 'delete_sms':
            $result = deleteTwilioMessages($account['account_sid'], $account['auth_token'], $account['proxy_address']);
            $response['deletion_result'] = $result;
            
            if ($result['status'] === 'success') {
                $stmt = $mysqli->prepare("UPDATE twilio_accounts SET sms_count = 0 WHERE numeric_id = ?");
                $stmt->bind_param("i", $account_id);
                $stmt->execute();
            }
            break;

        case 'delete_number':
            if (!isset($_GET['phone_number'])) {
                throw new Exception('Phone number is required');
            }
            
            $phone_number = $_GET['phone_number'];
            
            // Удаляем номер из Twilio
            $result = deleteTwilioNumber(
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address'],
                $phone_number
            );
            
            if ($result['status'] === 'success') {
                // Обновляем статус в базе данных
                $stmt = $mysqli->prepare("
                    UPDATE phone_numbers 
                    SET status = 'deleted' 
                    WHERE account_id = ? AND phone_number = ?
                ");
                $stmt->bind_param("is", $account_id, $phone_number);
                $stmt->execute();
                
                // Уменьшаем счетчик номеров в аккаунте
                $mysqli->query("
                    UPDATE twilio_accounts 
                    SET numbers_count = numbers_count - 1 
                    WHERE numeric_id = {$account_id}
                ");
            }
            
            $response['deletion_result'] = $result;
            break;

        default:
            throw new Exception('Unknown command');
    }

    // Логируем действие
    logAdminAction(
        $mysqli, 
        0, // системное действие
        "api_{$command}", 
        "Account ID: {$account_id}"
    );

    // Возвращаем результат
    echo json_encode($response);

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'error_details' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

// Очищаем буфер
ob_end_flush();