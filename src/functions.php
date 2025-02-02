<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

// PART 1 START - Core functions and database operations

function checkBalance($account_sid, $auth_token, $proxy = null) {
    try {
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Balance.json");
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            return [
                'status' => 'success',
                'balance' => floatval($data['balance']),
                'currency' => $data['currency']
            ];
        }
        
        return ['status' => 'error', 'balance' => 0];
    } catch (Exception $e) {
        error_log("Balance check error: " . $e->getMessage());
        return ['status' => 'error', 'balance' => 0];
    }
}

// Database connection function
function getDB() {
    try {
        $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($mysqli->connect_error) {
            throw new Exception('Database connection error');
        }
        $mysqli->set_charset("utf8mb4");
        return $mysqli;
    } catch (Exception $e) {
        print $e;
        print '<br />';
        error_log($e->getMessage());
        throw new Exception('System error');
    }
}

// Get system statistics with real-time updates
function getStats($mysqli) {
    $stats = [
        'total_accounts' => 0,
        'active_accounts' => 0,
        'total_numbers' => 0,
        'total_sms' => 0,
        'daily_stats' => [],
        'numbers_stats' => []
    ];
    
    try {
        // Total accounts with real counts
        $result = $mysqli->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(numbers_count) as total_numbers,
                SUM(sms_count) as total_sms
            FROM twilio_accounts
        ");
        
        if ($row = $result->fetch_assoc()) {
            $stats['total_accounts'] = (int)$row['total'];
            $stats['active_accounts'] = (int)$row['active'];
            $stats['total_numbers'] = (int)$row['total_numbers'];
            $stats['total_sms'] = (int)$row['total_sms'];
        }
        
        // Daily statistics (last 30 days)
        $result = $mysqli->query("
            SELECT date, active_accounts, total_sms, total_calls
            FROM daily_stats 
            WHERE date > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            ORDER BY date ASC
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats['daily_stats'][] = $row;
            }
        }

        // Numbers statistics by area code
        $result = $mysqli->query("
            SELECT pn.area_code, COUNT(*) as count
            FROM phone_numbers pn
            WHERE pn.status = 'active'
            GROUP BY pn.area_code
            ORDER BY count DESC
            LIMIT 10
        ");
        
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $stats['numbers_stats'][] = $row;
            }
        }
    } catch (Exception $e) {
        error_log("Error in getStats: " . $e->getMessage());
    }
    
    return $stats;
}
// PART 2 START - Account management functions

// Batch add Twilio accounts with semicolon separator
function batchAddTwilioAccounts($mysqli, $accounts_data) {
    try {
        $mysqli->begin_transaction();
        
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => []
        ];
        
        foreach ($accounts_data as $line => $data) {
            $parts = explode(';', trim($data));
            if (count($parts) >= 2) {
                // Логируем данные для отладки
                error_log("Processing account: " . json_encode([
                    'sid' => trim($parts[0]),
                    'proxy' => isset($parts[2]) ? trim($parts[2]) : null
                ]));
                
                // Проверяем формат прокси
                $proxy = isset($parts[2]) ? trim($parts[2]) : null;
                if ($proxy && !preg_match('/^socks5:\/\/[^:]+:[^:]+@[^:]+:\d+$/', $proxy)) {
                    $results['failed']++;
                    $results['errors'][] = "Line {$line}: Invalid proxy format. Expected: socks5://user:pass@host:port";
                    continue;
                }

                // Проверяем SID
                if (!preg_match('/^AC[a-zA-Z0-9]{32}$/', trim($parts[0]))) {
                    $results['failed']++;
                    $results['errors'][] = "Line {$line}: Invalid Account SID format";
                    continue;
                }

                // Проверяем Auth Token
                if (strlen(trim($parts[1])) < 32) {
                    $results['failed']++;
                    $results['errors'][] = "Line {$line}: Auth Token too short";
                    continue;
                }

                try {
                    $result = addTwilioAccount(
                        $mysqli, 
                        trim($parts[0]), 
                        trim($parts[1]), 
                        $proxy
                    );
                    
                    if ($result['status'] === 'success') {
                        $results['success']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = "Line {$line}: " . ($result['error'] ?? 'Unknown error');
                        error_log("Failed to add account: " . json_encode($result));
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = "Line {$line}: " . $e->getMessage();
                    error_log("Exception adding account: " . $e->getMessage());
                }
                
                // Добавляем задержку между запросами к API
                usleep(500000); // 500ms задержка
            } else {
                $results['failed']++;
                $results['errors'][] = "Line {$line}: Invalid format. Expected: AccountSID;AuthToken;Proxy";
            }
        }
        
        if ($results['success'] > 0) {
            $mysqli->commit();
        } else {
            $mysqli->rollback();
        }

        // Добавляем ошибки в сообщение об успехе
        if (!empty($results['errors'])) {
            error_log("Batch add errors: " . json_encode($results['errors']));
        }
        
        return $results;
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Batch add error: " . $e->getMessage());
        return [
            'success' => 0,
            'failed' => count($accounts_data),
            'errors' => ["Batch operation failed: " . $e->getMessage()]
        ];
    }
}

// Add new Twilio account with numeric_id
// В functions.php, функция addTwilioAccount:
function addTwilioAccount($mysqli, $account_sid, $auth_token, $proxy = null) {
    try {
        // Check if account already exists
        $stmt = $mysqli->prepare("SELECT numeric_id FROM twilio_accounts WHERE account_sid = ?");
        $stmt->bind_param("s", $account_sid);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return ['status' => 'error', 'error' => 'Account already exists'];
        }
        
        // Check account with Twilio API
        $check = checkTwilioAccount($account_sid, $auth_token, $proxy);
        if ($check['status'] !== 'active') {
            return $check;
        }

        // Begin transaction
        $mysqli->begin_transaction();
        
        try {
            // Add account to database
            $stmt = $mysqli->prepare("
                INSERT INTO twilio_accounts (
                    account_sid, 
                    auth_token, 
                    friendly_name, 
                    proxy_address, 
                    balance,
                    status
                ) VALUES (?, ?, ?, ?, ?, 'active')
            ");
            
            $stmt->bind_param("ssssd", 
                $account_sid, 
                $auth_token, 
                $check['friendly_name'],
                $proxy,
                $check['balance']
            );
            
            if (!$stmt->execute()) {
                throw new Exception('Failed to insert account');
            }

            $numeric_id = $mysqli->insert_id;
            
            $mysqli->commit();
            
            return [
                'status' => 'success',
                'numeric_id' => $numeric_id,
                'balance' => $check['balance'],
                'friendly_name' => $check['friendly_name']
            ];
        } catch (Exception $e) {
            $mysqli->rollback();
            throw $e;
        }
    } catch (Exception $e) {
        error_log("Error adding account: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Update account information with real-time stats
function updateAccountInfo($mysqli, $account_id) {
    try {
        $stmt = $mysqli->prepare("
            SELECT account_sid, auth_token, proxy_address 
            FROM twilio_accounts 
            WHERE numeric_id = ?
        ");
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        
        if (!$account) {
            return false;
        }
        
        // Check account status and balance
        $check = checkTwilioAccount(
            $account['account_sid'], 
            $account['auth_token'], 
            $account['proxy_address']
        );
        
        // Update numbers and SMS count
        $numbers_result = $mysqli->query("
            SELECT 
                COUNT(*) as numbers_count,
                SUM(sms_count) as total_sms
            FROM phone_numbers 
            WHERE account_id = {$account_id}
        ");
        $numbers_data = $numbers_result->fetch_assoc();
        
        if ($check['status'] === 'active') {
            $stmt = $mysqli->prepare("
                UPDATE twilio_accounts 
                SET status = 'active', 
                    friendly_name = ?, 
                    balance = ?, 
                    numbers_count = ?,
                    sms_count = ?,
                    last_check = NOW() 
                WHERE numeric_id = ?
            ");
            $stmt->bind_param("sdiii", 
                $check['friendly_name'],
                $check['balance'],
                $numbers_data['numbers_count'],
                $numbers_data['total_sms'],
                $account_id
            );
        } else {
            $stmt = $mysqli->prepare("
                UPDATE twilio_accounts 
                SET status = 'suspended', 
                    numbers_count = ?,
                    sms_count = ?,
                    last_check = NOW() 
                WHERE numeric_id = ?
            ");
            $stmt->bind_param("iii", 
                $numbers_data['numbers_count'],
                $numbers_data['total_sms'],
                $account_id
            );
        }
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error updating account info: " . $e->getMessage());
        return false;
    }
}
// PART 3 START - Batch operations functions

// Create batch task
function createBatchTask($mysqli, $task_type, $params = []) {
    try {
        $stmt = $mysqli->prepare("
            INSERT INTO batch_tasks (task_type, params)
            VALUES (?, ?)
        ");
        
        $params_json = json_encode($params);
        $stmt->bind_param("ss", $task_type, $params_json);
        
        if ($stmt->execute()) {
            return $mysqli->insert_id;
        }
        return false;
    } catch (Exception $e) {
        error_log("Error creating batch task: " . $e->getMessage());
        return false;
    }
}

// Update all accounts
function updateAllAccounts($mysqli) {
    try {
        $task_id = createBatchTask($mysqli, 'update_all_accounts');
        if (!$task_id) {
            throw new Exception('Failed to create batch task');
        }
        
        $stmt = $mysqli->prepare("
            SELECT id FROM twilio_accounts 
            WHERE status != 'deleted'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $updated = 0;
        $failed = 0;
        
        while ($account = $result->fetch_assoc()) {
            if (updateAccountInfo($mysqli, $account['id'])) {
                $updated++;
            } else {
                $failed++;
            }
        }
        
        // Update task status
        $stmt = $mysqli->prepare("
            UPDATE batch_tasks 
            SET status = 'completed',
                result = ?
            WHERE id = ?
        ");
        
        $result = json_encode([
            'updated' => $updated,
            'failed' => $failed
        ]);
        
        $stmt->bind_param("si", $result, $task_id);
        $stmt->execute();
        
        return [
            'status' => 'success',
            'updated' => $updated,
            'failed' => $failed
        ];
    } catch (Exception $e) {
        error_log("Error updating all accounts: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Get phone numbers for all accounts
function getNumbersForAllAccounts($mysqli, $country = 'US') {
    try {
        $task_id = createBatchTask($mysqli, 'get_numbers', ['country' => $country]);
        if (!$task_id) {
            throw new Exception('Failed to create batch task');
        }
        
        $stmt = $mysqli->prepare("
            SELECT id, account_sid, auth_token, proxy_address 
            FROM twilio_accounts 
            WHERE status = 'active'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $success = 0;
        $failed = 0;
        $numbers = [];
        
        while ($account = $result->fetch_assoc()) {
            $numbers_result = getTwilioNumbers(
                $account['account_sid'],
                $account['auth_token'],
                $account['proxy_address'],
                $country
            );
            
            if ($numbers_result['status'] === 'success') {
                $success++;
                $numbers = array_merge($numbers, $numbers_result['numbers']);
            } else {
                $failed++;
            }
        }
        
        // Update task status
        $stmt = $mysqli->prepare("
            UPDATE batch_tasks 
            SET status = 'completed',
                result = ?
            WHERE id = ?
        ");
        
        $result = json_encode([
            'success' => $success,
            'failed' => $failed,
            'numbers' => $numbers
        ]);
        
        $stmt->bind_param("si", $result, $task_id);
        $stmt->execute();
        
        return [
            'status' => 'success',
            'success' => $success,
            'failed' => $failed,
            'numbers' => $numbers
        ];
    } catch (Exception $e) {
        error_log("Error getting numbers for all accounts: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Remove numbers from all accounts
function removeNumbersFromAllAccounts($mysqli) {
    try {
        $task_id = createBatchTask($mysqli, 'remove_numbers');
        if (!$task_id) {
            throw new Exception('Failed to create batch task');
        }
        
        $stmt = $mysqli->prepare("
            SELECT ta.id, ta.account_sid, ta.auth_token, ta.proxy_address, pn.phone_number
            FROM twilio_accounts ta
            JOIN phone_numbers pn ON ta.id = pn.account_id
            WHERE ta.status = 'active'
        ");
        $stmt->execute();
        $result = $stmt->get_result();
        
        $removed = 0;
        $failed = 0;
        
        while ($row = $result->fetch_assoc()) {
            $remove_result = removeTwilioNumber(
                $row['account_sid'],
                $row['auth_token'],
                $row['phone_number'],
                $row['proxy_address']
            );
            
            if ($remove_result['status'] === 'success') {
                // Remove from local database
                $mysqli->query("
                    DELETE FROM phone_numbers 
                    WHERE account_id = {$row['id']} 
                      AND phone_number = '{$row['phone_number']}'
                ");
                $removed++;
            } else {
                $failed++;
            }
        }
        
        // Update accounts statistics
        $mysqli->query("
            UPDATE twilio_accounts ta
            SET numbers_count = 0,
                sms_count = 0
            WHERE id IN (
                SELECT account_id 
                FROM phone_numbers
            )
        ");
        
        // Update task status
        $stmt = $mysqli->prepare("
            UPDATE batch_tasks 
            SET status = 'completed',
                result = ?
            WHERE id = ?
        ");
        
        $result = json_encode([
            'removed' => $removed,
            'failed' => $failed
        ]);
        
        $stmt->bind_param("si", $result, $task_id);
        $stmt->execute();
        
        return [
            'status' => 'success',
            'removed' => $removed,
            'failed' => $failed
        ];
    } catch (Exception $e) {
        error_log("Error removing numbers from all accounts: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Get Twilio numbers
function getTwilioNumbers($account_sid, $auth_token, $proxy) {
    try {
        error_log("Getting numbers for account $account_sid using proxy: $proxy");
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json");
        
        // Настройки CURL
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ];
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        
        // Проверяем ошибки CURL
        if ($error = curl_error($ch)) {
            error_log("CURL Error: " . $error);
            return ['status' => 'error', 'error' => "CURL Error: " . $error];
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Логируем ответ
        error_log("Twilio Response Code: $http_code");
        error_log("Twilio Response: " . $response);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            $numbers = [];
            
            if (isset($data['incoming_phone_numbers']) && is_array($data['incoming_phone_numbers'])) {
                foreach ($data['incoming_phone_numbers'] as $number) {
                    if (isset($number['phone_number'])) {
                        $numbers[] = $number['phone_number'];
                    }
                }
                error_log("Found numbers: " . json_encode($numbers));
                return ['status' => 'success', 'numbers' => $numbers];
            }
        }
        
        return ['status' => 'error', 'error' => "HTTP {$http_code}"];
    } catch (Exception $e) {
        error_log("Exception in getTwilioNumbers: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Remove Twilio number
function removeTwilioNumber($account_sid, $auth_token, $phone_number, $proxy = null) {
    try {
        // First get the SID for this number
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json?PhoneNumber={$phone_number}");
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (!empty($data['incoming_phone_numbers'])) {
                $number_sid = $data['incoming_phone_numbers'][0]['sid'];
                
                // Now delete the number
                $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers/{$number_sid}.json");
                
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                
                if ($proxy) {
                    curl_setopt($ch, CURLOPT_PROXY, $proxy);
                    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
                }
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                
                if ($http_code === 204) {
                    return ['status' => 'success'];
                }
            }
        }
        
        return ['status' => 'error', 'error' => "HTTP {$http_code}"];
    } catch (Exception $e) {
        error_log("Error removing Twilio number: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}
// Не хватает этих важных функций:

// Check Twilio account status
function checkTwilioAccount($account_sid, $auth_token, $proxy = null) {
    try {
        error_log("Checking account: $account_sid with proxy: $proxy");
        
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}");
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERPWD, "{$account_sid}:{$auth_token}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        }
        
        $response = curl_exec($ch);
        
        if ($error = curl_error($ch)) {
            error_log("CURL Error: " . $error);
            return ['status' => 'error', 'error' => "CURL Error: " . $error];
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Twilio API Response: HTTP $http_code - " . substr($response, 0, 200));
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            return [
                'status' => 'active',
                'friendly_name' => $data['friendly_name'] ?? '',
                'balance' => $data['balance'] ?? 0,
                'numbers' => []
            ];
        }
        
        return ['status' => 'error', 'error' => "HTTP {$http_code}"];
    } catch (Exception $e) {
        error_log("Check account error: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Update daily statistics
function updateDailyStats($mysqli) {
    try {
        $date = date('Y-m-d');
        
        // Get current stats
        $result = $mysqli->query("
            SELECT 
                COUNT(*) as total_accounts,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_accounts,
                SUM(numbers_count) as total_numbers,
                SUM(sms_count) as total_sms
            FROM twilio_accounts
            WHERE status != 'deleted'
        ");
        
        $stats = $result->fetch_assoc();
        
        // Обновляем или добавляем статистику за текущий день
        $stmt = $mysqli->prepare("
            INSERT INTO daily_stats (date, active_accounts, total_sms, total_calls)
            VALUES (?, ?, ?, 0)
            ON DUPLICATE KEY UPDATE
                active_accounts = VALUES(active_accounts),
                total_sms = VALUES(total_sms)
        ");
        
        $stmt->bind_param('sii', 
            $date, 
            $stats['active_accounts'],
            $stats['total_sms']
        );
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error updating daily stats: " . $e->getMessage());
        return false;
    }
}

// Get pagination data
function getPagination($total, $per_page, $current_page) {
    $total_pages = max(1, ceil($total / $per_page));
    $current_page = max(1, min($current_page, $total_pages));
    
    return [
        'total' => $total,
        'per_page' => $per_page,
        'current_page' => $current_page,
        'total_pages' => $total_pages,
        'offset' => ($current_page - 1) * $per_page
    ];
}

// Sanitize input
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Format number for display
function formatNumber($number) {
    return number_format($number, 0, '.', ',');
}

// Log admin action
function logAdminAction($mysqli, $admin_id, $action, $details = '') {
    try {
        $stmt = $mysqli->prepare("
            INSERT INTO admin_logs (admin_id, action, details, ip_address) 
            VALUES (?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt->bind_param("isss", $admin_id, $action, $details, $ip);
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error logging admin action: " . $e->getMessage());
        return false;
    }
}
//НОВЫЙ
// Получение нового номера для аккаунта
function acquireNewNumber($account_sid, $auth_token, $proxy, $country = 'US') {
    try {
        error_log("Acquiring new number for account $account_sid in country $country using proxy: $proxy");
        
        // Сначала ищем доступные номера
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/AvailablePhoneNumbers/{$country}/Local.json");
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5
        ];
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        
        if ($error = curl_error($ch)) {
            error_log("CURL Error searching numbers: " . $error);
            return ['status' => 'error', 'error' => "CURL Error: " . $error];
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Available numbers response code: $http_code");
        error_log("Available numbers response: " . $response);
        
        if ($http_code !== 200) {
            return ['status' => 'error', 'error' => "Failed to search numbers: HTTP $http_code"];
        }
        
        $data = json_decode($response, true);
        if (empty($data['available_phone_numbers'])) {
            return ['status' => 'error', 'error' => "No numbers available"];
        }
        
        // Берем первый доступный номер
        $phoneNumber = $data['available_phone_numbers'][0]['phone_number'];
        
        // Теперь покупаем этот номер
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json");
        
        $postFields = http_build_query([
            'PhoneNumber' => $phoneNumber
        ]);
        
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_PROXY => $proxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields
        ];
        curl_setopt_array($ch, $options);
        
        $response = curl_exec($ch);
        
        if ($error = curl_error($ch)) {
            error_log("CURL Error purchasing number: " . $error);
            return ['status' => 'error', 'error' => "CURL Error: " . $error];
        }
        
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        error_log("Purchase number response code: $http_code");
        error_log("Purchase number response: " . $response);
        
        if ($http_code === 201) {
            $data = json_decode($response, true);
            return [
                'status' => 'success',
                'phone_number' => $data['phone_number'],
                'sid' => $data['sid']
            ];
        }
        
        return ['status' => 'error', 'error' => "Failed to purchase number: HTTP $http_code"];
        
    } catch (Exception $e) {
        error_log("Exception in acquireNewNumber: " . $e->getMessage());
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}
// Check login attempts
function checkLoginAttempts() {
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt'] = time();
    }

    // Сброс попыток после таймаута
    if ((time() - $_SESSION['first_attempt']) > LOGIN_TIMEOUT) {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['first_attempt'] = time();
    }

    // Проверка количества попыток
    if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $waitTime = ceil((LOGIN_TIMEOUT - (time() - $_SESSION['first_attempt'])) / 60);
        throw new Exception("Too many login attempts. Try again in {$waitTime} minutes.");
    }
}
function regenerateSession() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $old_session_id = session_id();
        session_regenerate_id(true);
        error_log("Session regenerated: {$old_session_id} -> " . session_id());
    }
}

function initializeSession() {
    if (!isset($_SESSION['initialized'])) {
        $_SESSION['initialized'] = true;
        $_SESSION['created_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function validateCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        error_log('CSRF token not found in session');
        return false;
    }
    if (!$token) {
        error_log('CSRF token not provided in request');
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}
function getTotalSMSCount($mysqli, $account_id = null) {
    if ($account_id) {
        $query = "SELECT total_sms_count FROM account_total_sms WHERE account_id = {$account_id}";
    } else {
        $query = "SELECT SUM(total_sms_count) as total FROM account_total_sms";
    }
    
    $result = $mysqli->query($query);
    $row = $result->fetch_assoc();
    
    return $account_id ? ($row['total_sms_count'] ?? 0) : ($row['total'] ?? 0);
}