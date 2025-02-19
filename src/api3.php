<?php
ob_start();
session_start();
require_once 'config.php';
require_once 'functions.php';

// Включаем отображение ошибок
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Устанавливаем заголовки
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');

    $mysqli = getDB();
    
    // Получаем API ключ из базы данных
    $API_KEY = getApiKey($mysqli);
    if (!$API_KEY) {
        throw new Exception('API key not configured');
    }

    // Проверка API ключа
    if (!isset($_GET['key']) || $_GET['key'] !== $API_KEY) {
        throw new Exception('Invalid API key');
    }

    // Вспомогательные функции
    function cleanPhoneNumber($phone_number) {
        return str_replace(['+', '-', '(', ')', ' '], '', $phone_number);
    }

    // Обновление статистики SMS для номера
    function updatePhoneNumberSmsStats($mysqli, $account_id, $phone_number, $sms_count) {
        $clean_number = cleanPhoneNumber($phone_number);
        $stmt = $mysqli->prepare("
            UPDATE phone_numbers 
            SET sms_count = ?,
                total_sms_count = total_sms_count + ?,
                daily_sms_count = ?,
                last_sms_update = NOW(),
                last_sms_date = CURDATE()
            WHERE account_id = ? 
            AND phone_number = ?
        ");
        $stmt->bind_param("iiiss", $sms_count, $sms_count, $sms_count, $account_id, $clean_number);
        return $stmt->execute();
    }

    // Обновление общей статистики SMS для аккаунта
    function updateAccountSmsStats($mysqli, $account_id) {
        $stmt = $mysqli->prepare("
            UPDATE twilio_accounts 
            SET sms_count = (
                SELECT COALESCE(SUM(sms_count), 0)
                FROM phone_numbers 
                WHERE account_id = ? 
                AND status = 'active'
            )
            WHERE numeric_id = ?
        ");
        $stmt->bind_param("ii", $account_id, $account_id);
        return $stmt->execute();
    }
    // CURL запросы
    function executeCurlRequest($url, $account_sid, $auth_token, $proxy, $method = 'GET', $data = null) {
        error_log("CURL Request: {$method} {$url}");
        
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ];
        
        if ($data && $method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = $data;
        }
        
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        error_log("CURL Response Code: {$http_code}");
        
        if ($error = curl_error($ch)) {
            error_log("CURL Error: " . $error);
            $result = ['status' => 'error', 'error' => "CURL Error: {$error}"];
        } else if ($http_code >= 200 && $http_code < 300) {
            $decoded = json_decode($response, true);
            $result = ['status' => 'success', 'data' => $decoded];
            error_log("CURL Success Response: " . substr(json_encode($decoded), 0, 500));
        } else {
            $decoded = json_decode($response, true);
            error_log("CURL Error Response: " . $response);
            $result = [
                'status' => 'error', 
                'error' => "HTTP Error: {$http_code}",
                'response' => $decoded
            ];
        }
        
        curl_close($ch);
        return $result;
    }

    // Функции для SMS
    function getTwilioMessages($account_sid, $auth_token, $proxy, $phone_number = null) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json?PageSize=1000";
        if ($phone_number) {
            $url .= "&To={$phone_number}";
        }
        return executeCurlRequest($url, $account_sid, $auth_token, $proxy);
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

    // Функция поиска доступных номеров
    function searchAvailableNumbers($account_sid, $auth_token, $proxy, $country, $prefix = '') {
        error_log("Searching for available numbers in {$country} with prefix: {$prefix}");
        
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/AvailablePhoneNumbers/{$country}/Local.json";
        
        if (!empty($prefix)) {
            $url .= "?Contains=" . urlencode($prefix);
        }
        
        $result = executeCurlRequest($url, $account_sid, $auth_token, $proxy);
        
        if ($result['status'] !== 'success' || empty($result['data']['available_phone_numbers'])) {
            // Если был указан префикс, пробуем поиск без него
            if (!empty($prefix)) {
                error_log("No numbers found with prefix, trying without prefix");
                return searchAvailableNumbers($account_sid, $auth_token, $proxy, $country);
            }
            error_log("No numbers found at all");
            return $result;
        }

        error_log("Found " . count($result['data']['available_phone_numbers']) . " numbers");
        return $result;
    }

    // Функция покупки номера
    function buyNumber($account_sid, $auth_token, $proxy, $phone_number) {
        error_log("Attempting to buy number: " . $phone_number);
        
        $data = http_build_query(['PhoneNumber' => $phone_number]);
        
        $result = executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json",
            $account_sid,
            $auth_token,
            $proxy,
            'POST',
            $data
        );
        
        error_log("Buy number result: " . json_encode($result));
        return $result;
    }

    // Получение SID номера
    function getTwilioNumberSid($account_sid, $auth_token, $proxy, $phone_number) {
        $clean_number = cleanPhoneNumber($phone_number);
        $formatted_number = '+' . $clean_number;
        
        error_log("Searching for SID of number: " . $formatted_number);
        
        // Сначала пробуем найти по точному номеру
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json?PhoneNumber=" . urlencode($formatted_number);
        
        $result = executeCurlRequest($url, $account_sid, $auth_token, $proxy);
        
        if ($result['status'] === 'success' && isset($result['data']['incoming_phone_numbers'])) {
            foreach ($result['data']['incoming_phone_numbers'] as $number) {
                if (cleanPhoneNumber($number['phone_number']) === $clean_number) {
                    error_log("Found SID for number: " . $number['sid']);
                    return $number['sid'];
                }
            }
        }
        
        // Если не нашли по точному совпадению, получаем все номера
        error_log("No exact match found, searching in all numbers");
        $result = executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json",
            $account_sid,
            $auth_token,
            $proxy
        );
        
        if ($result['status'] === 'success' && isset($result['data']['incoming_phone_numbers'])) {
            foreach ($result['data']['incoming_phone_numbers'] as $number) {
                if (cleanPhoneNumber($number['phone_number']) === $clean_number) {
                    error_log("Found SID in full list: " . $number['sid']);
                    return $number['sid'];
                }
            }
        }
        
        error_log("Failed to find SID for number");
        return null;
    }

    // Удаление номера
    function deleteTwilioNumber($account_sid, $auth_token, $proxy, $phone_number) {
        error_log("Starting delete process for number: " . $phone_number);
        
        $number_sid = getTwilioNumberSid($account_sid, $auth_token, $proxy, $phone_number);
        
        if (!$number_sid) {
            error_log("No SID found for number: {$phone_number}");
            return ['status' => 'error', 'error' => 'Number SID not found'];
        }

        error_log("Found SID, attempting deletion");
        
        $result = executeCurlRequest(
            "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers/{$number_sid}.json",
            $account_sid,
            $auth_token,
            $proxy,
            'DELETE'
        );
        
        error_log("Deletion result: " . json_encode($result));
        return $result;
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
    // Обработка команд
    $command = $_GET['command'] ?? '';
    $response = ['success' => true];

// Получаем account_id из параметров запроса
$account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

// Получаем информацию об аккаунте
if ($account_id > 0) {
    $stmt = $mysqli->prepare("SELECT * FROM twilio_accounts WHERE numeric_id = ? AND status = 'active'");
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    
    if (!$account) {
        throw new Exception('Account not found or inactive');
    }
}

    switch ($command) {
        case 'get_accounts':
            $result = $mysqli->query("
                SELECT numeric_id, account_sid, status, numbers_count, sms_count, balance, last_check 
                FROM twilio_accounts 
                WHERE status = 'active'
                ORDER BY numeric_id DESC
            ");
            $response['accounts'] = $result->fetch_all(MYSQLI_ASSOC);
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

        case 'get_numbers':
            if (!isset($_GET['country'])) {
                throw new Exception('Country code is required');
            }
            
            $country = $_GET['country'];
            $prefix = $_GET['prefix'] ?? '';
            
            if (!in_array($country, ['US', 'CA', 'GB'])) {
                throw new Exception('Invalid country code');
            }
            
            error_log("Searching for numbers in {$country} with prefix: {$prefix}");
            
            // Поиск доступных номеров
            $available_numbers = searchAvailableNumbers(
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address'],
                $country,
                $prefix
            );

            if ($available_numbers['status'] !== 'success' ||
                empty($available_numbers['data']['available_phone_numbers'])) {
                $response['result'] = [
                    'status' => 'error',
                    'error' => 'No available numbers found'
                ];
                break;
            }

            // Берем первый доступный номер
            $phone_number = $available_numbers['data']['available_phone_numbers'][0]['phone_number'];

            error_log("Found number to buy: " . $phone_number);

            // Покупаем номер
            $result = buyNumber(
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address'],
                $phone_number
            );

            if ($result['status'] === 'success') {
                $clean_number = cleanPhoneNumber($phone_number);

                // Начинаем транзакцию
                $mysqli->begin_transaction();

                try {
                    // Добавляем номер в базу
                    $stmt = $mysqli->prepare("
                        INSERT INTO phone_numbers (
                            account_id,
                            phone_number,
                            status,
                            date_added
                        ) VALUES (?, ?, 'active', NOW())
                        ON DUPLICATE KEY UPDATE
                            status = 'active',
                            date_deleted = NULL
                    ");
                    $stmt->bind_param("is", $account_id, $clean_number);
                    $stmt->execute();

                    // Увеличиваем счетчик номеров
                    $mysqli->query("
                        UPDATE twilio_accounts
                        SET numbers_count = numbers_count + 1
                        WHERE numeric_id = {$account_id}
                    ");

                    $mysqli->commit();

                    $result['phone_number'] = $clean_number;
                    error_log("Successfully bought and saved number: " . $clean_number);
                } catch (Exception $e) {
                    $mysqli->rollback();
                    error_log("Error saving bought number: " . $e->getMessage());
                    throw $e;
                }
            } else {
                error_log("Failed to buy number: " . json_encode($result));
            }
            
            $response['result'] = $result;
            break;

        case 'show_numbers':
            if (!isset($_GET['account_id'])) {
                throw new Exception('Account ID is required');
            }
            $account_id = (int)$_GET['account_id'];

            // Получаем информацию об аккаунте с номерами
            $stmt = $mysqli->prepare("
                SELECT t.*, p.phone_number, p.status as phone_status, p.sms_count
                FROM twilio_accounts t
                LEFT JOIN phone_numbers p ON t.numeric_id = p.account_id
                WHERE t.numeric_id = ? AND t.status = 'active'
            ");
            $stmt->bind_param('i', $account_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $account = $result->fetch_assoc();

            if (!$account) {
                throw new Exception('Account not found');
            }

            if (empty($account['proxy_address'])) {
                throw new Exception('No proxy configured for this account');
            }

            // Получаем актуальные номера от Twilio
            $numbers_result = executeCurlRequest(
                "https://api.twilio.com/2010-04-01/Accounts/{$account['account_sid']}/IncomingPhoneNumbers.json",
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address']
            );

            // Получаем SMS для подсчета
            $messages_result = getTwilioMessages($account['account_sid'], $account['auth_token'], $account['proxy_address']);

            if ($numbers_result['status'] === 'success' && isset($numbers_result['data']['incoming_phone_numbers'])) {
                $active_numbers = [];
                $messages = $messages_result['status'] === 'success' ? $messages_result['data']['messages'] : [];

                foreach ($numbers_result['data']['incoming_phone_numbers'] as $number) {
                    $clean_number = cleanPhoneNumber($number['phone_number']);
                    $sms_count = countMessagesForNumber($messages, $clean_number);

                    $active_numbers[] = [
                        'phone_number' => $clean_number,
                        'status' => 'active',
                        'sms_count' => $sms_count
                    ];

                    // Обновляем статистику в базе
                    updatePhoneNumberSmsStats($mysqli, $account_id, $clean_number, $sms_count);
                }

                // Помечаем удаленные номера
                if (!empty($active_numbers)) {
                    $active_numbers_list = array_map(function($n) { return $n['phone_number']; }, $active_numbers);
                    $numbers_str = "'" . implode("','", array_map([$mysqli, 'real_escape_string'], $active_numbers_list)) . "'";
                    $mysqli->query("
                        UPDATE phone_numbers
                        SET status = 'deleted'
                        WHERE account_id = {$account_id}
                        AND phone_number NOT IN ({$numbers_str})
                        AND status = 'active'
                    ");
                }

                // Обновляем статистику аккаунта
                updateAccountSmsStats($mysqli, $account_id);

                $response['numbers'] = $active_numbers;
            } else {
                $response['numbers'] = [];
            }
            break;

    case 'get_sms':
           $messages = getTwilioMessages($account['account_sid'], $account['auth_token'], $account['proxy_address'], $_GET['phone_number'] ?? null);
           $response['messages'] = $messages;

           if ($messages['status'] === 'success' && isset($messages['data']['messages'])) {
               $sms_count = count($messages['data']['messages']);
               $stmt = $mysqli->prepare("UPDATE twilio_accounts SET sms_count = ? WHERE numeric_id = ?");
               $stmt->bind_param("ii", $sms_count, $account_id);
               $stmt->execute();
           }
           break;

        case 'delete_sms':
            error_log("Starting SMS deletion for account: " . $account_id);
            
            $mysqli->begin_transaction();
            
            try {
                // Сохраняем текущую статистику
                $stmt = $mysqli->prepare("
                    SELECT phone_number, sms_count, total_sms_count 
                    FROM phone_numbers 
                    WHERE account_id = ? AND status = 'active'
                ");
                $stmt->bind_param('i', $account_id);
                $stmt->execute();
                $numbers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                
                // Удаляем сообщения в Twilio
                $result = deleteTwilioMessages($account['account_sid'], $account['auth_token'], $account['proxy_address']);
                $response['deletion_result'] = $result;
                
                if ($result['status'] === 'success') {
                    // Обновляем статистику в базе
                    foreach ($numbers as $number) {
                        $stmt = $mysqli->prepare("
                            INSERT INTO phone_numbers_history (
                                account_id,
                                phone_number,
                                total_sms,
                                date_added,
                                date_deleted
                            ) VALUES (?, ?, ?, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                                total_sms = total_sms + ?,
                                date_deleted = NOW()
                        ");
                        $stmt->bind_param('isii', 
                            $account_id, 
                            $number['phone_number'], 
                            $number['sms_count'],
                            $number['sms_count']
                        );
                        $stmt->execute();
                    }
                    
                    // Обнуляем счетчики SMS
                    $mysqli->query("
                        UPDATE phone_numbers 
                        SET sms_count = 0,
                            daily_sms_count = 0
                        WHERE account_id = {$account_id}
                    ");
                    
                    $mysqli->query("
                        UPDATE twilio_accounts 
                        SET sms_count = 0 
                        WHERE numeric_id = {$account_id}
                    ");
                    
                    $mysqli->commit();
                    error_log("Successfully deleted SMS for account: " . $account_id);
                } else {
                    $mysqli->rollback();
                    error_log("Failed to delete SMS for account: " . $account_id);
                }
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log("Error during SMS deletion: " . $e->getMessage());
                throw $e;
            }
            break;

        case 'get_balance':
            error_log("Getting balance for account: " . $account_id);
            
            $balance = getTwilioBalance($account['account_sid'], $account['auth_token'], $account['proxy_address']);
            
            if ($balance['status'] === 'success') {
                $stmt = $mysqli->prepare("
                    UPDATE twilio_accounts 
                    SET balance = ?, 
                        last_check = NOW() 
                    WHERE numeric_id = ?
                ");
                $balanceAmount = $balance['data']['balance'];
                $stmt->bind_param("di", $balanceAmount, $account_id);
                $stmt->execute();
                
                error_log("Successfully updated balance for account: " . $account_id);
            } else {
                error_log("Failed to get balance for account: " . $account_id);
            }
            
            $response['balance'] = $balance;
            break;

        case 'delete_number':
            if (!isset($_GET['phone_number'])) {
                throw new Exception('Phone number is required');
            }
            
            $phone_number = cleanPhoneNumber($_GET['phone_number']);
            error_log("Starting delete_number command for account: {$account_id}, number: {$phone_number}");
            
            // Получаем информацию о номере
            $stmt = $mysqli->prepare("
                SELECT phone_number, status, total_sms_count, daily_sms_count, date_added 
                FROM phone_numbers 
                WHERE account_id = ? AND phone_number = ?
            ");
            $stmt->bind_param('is', $account_id, $phone_number);
            $stmt->execute();
            $number_info = $stmt->get_result()->fetch_assoc();
            
            if (!$number_info) {
                error_log("Number not found in database: {$phone_number}");
//                 throw new Exception('Number not found in database');
            }
            
            if ($number_info['status'] !== 'active') {
                error_log("Number is already deleted or inactive: {$phone_number}");
                throw new Exception('Number is already deleted or inactive');
            }
            
            // Начинаем транзакцию
            $mysqli->begin_transaction();
            
            try {
                // Сохраняем в историю до удаления
                $stmt = $mysqli->prepare("
                    INSERT INTO phone_numbers_history (
                        account_id, 
                        phone_number, 
                        total_sms,
                        date_added,
                        date_deleted
                    ) VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        total_sms = VALUES(total_sms),
                        date_deleted = NOW()
                ");
                $stmt->bind_param("isis", 
                    $account_id, 
                    $phone_number, 
                    $number_info['total_sms_count'],
                    $number_info['date_added']
                );
                $stmt->execute();
                
                error_log("Saved number history for: {$phone_number}");
                
                // Удаляем номер в Twilio
                $result = deleteTwilioNumber(
                    $account['account_sid'],
                    $account['auth_token'],
                    $account['proxy_address'],
                    $phone_number
                );
                
                error_log("Twilio deletion result: " . json_encode($result));
                
                if ($result['status'] === 'success') {
                    // Обновляем статус номера в базе
                    $stmt = $mysqli->prepare("
                        UPDATE phone_numbers 
                        SET status = 'deleted',
                            date_deleted = NOW()
                        WHERE account_id = ? 
                        AND phone_number = ?
                    ");
                    $stmt->bind_param("is", $account_id, $phone_number);
                    $stmt->execute();
                    
                    // Уменьшаем счетчик номеров в аккаунте
                    $mysqli->query("
                        UPDATE twilio_accounts 
                        SET numbers_count = GREATEST(numbers_count - 1, 0)
                        WHERE numeric_id = {$account_id}
                    ");
                    
                    // Обновляем общую статистику SMS
                    updateAccountSmsStats($mysqli, $account_id);
                    
                    $mysqli->commit();
                    
                    $response['deletion_result'] = [
                        'status' => 'success',
                        'message' => 'Number deleted successfully',
                        'history_saved' => true
                    ];
                    
                    error_log("Successfully deleted number: {$phone_number}");
                } else {
                    $mysqli->rollback();
                    error_log("Failed to delete number in Twilio: {$phone_number}");
                    $response['deletion_result'] = $result;
                }
            } catch (Exception $e) {
                $mysqli->rollback();
                error_log("Error during number deletion: " . $e->getMessage());
                throw $e;
            }
            break;

        default:
            throw new Exception('Unknown command');
    }

    // Логируем действие
    logAdminAction(
        $mysqli, 
        0, 
        "api_{$command}", 
        "Account ID: " . ($account_id ?? 'none')
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
?>