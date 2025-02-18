<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();

require_once 'config.php';
require_once 'functions.php';

// Функция для проверки прокси
function checkProxy($proxy) {
    try {
        $ch = curl_init('http://ip-api.com/json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $http_code === 200;
    } catch (Exception $e) {
        return false;
    }
}

// Функция для проверки баланса и статуса аккаунта
function checkAccount($account_sid, $auth_token, $proxy = null) {
    try {
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Balance.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
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
        
        return ['status' => 'error', 'error' => "HTTP Error: {$http_code}", 'balance' => 0];
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage(), 'balance' => 0];
    }
}

// Новая функция для получения существующих номеров аккаунта
function getExistingNumbers($account_sid, $auth_token, $proxy = null) {
    try {
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30
        ]);
        
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
                'numbers' => $data['incoming_phone_numbers']
            ];
        }
        
        return ['status' => 'error', 'error' => "HTTP Error: {$http_code}"];
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}
// Новая функция для покупки номера в Канаде
function buyCanadianNumber($account_sid, $auth_token, $proxy = null) {
    try {
        $ch = curl_init("https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/IncomingPhoneNumbers.json");
        
        $data = [
            'CountryCode' => 'CA',
            'SmsEnabled' => 'true'
        ];
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$account_sid}:{$auth_token}",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ]);
        
        if ($proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 201) {
            $data = json_decode($response, true);
            return [
                'status' => 'success',
                'number' => $data['phone_number'],
                'friendly_name' => $data['friendly_name']
            ];
        }
        
        return ['status' => 'error', 'error' => "HTTP Error: {$http_code}"];
    } catch (Exception $e) {
        return ['status' => 'error', 'error' => $e->getMessage()];
    }
}

// Обработчик AJAX запросов
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    switch ($_GET['action']) {
        case 'init':
            if (!isset($_FILES['accountsFile'])) {
                echo json_encode(['error' => 'No file uploaded']);
                exit;
            }
            
            if ($_FILES['accountsFile']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'Upload error: ' . $_FILES['accountsFile']['error']]);
                exit;
            }
            
            $temp_dir = __DIR__ . '/temp';
            if (!is_dir($temp_dir)) {
                mkdir($temp_dir, 0755, true);
            }
            
            $temp_file = $temp_dir . '/' . session_id() . '.txt';
            move_uploaded_file($_FILES['accountsFile']['tmp_name'], $temp_file);
            
            $lines = file($temp_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $total_lines = count($lines);
            
            $_SESSION['upload_file'] = $temp_file;
            $_SESSION['total_lines'] = $total_lines;
            $_SESSION['current_line'] = 0;
            $_SESSION['success'] = 0;
            $_SESSION['failed'] = 0;
            $_SESSION['errors'] = [];
            
            echo json_encode([
                'status' => 'success',
                'total_lines' => $total_lines
            ]);
            break;
			case 'process':
            if (!isset($_SESSION['upload_file']) || !file_exists($_SESSION['upload_file'])) {
                echo json_encode(['error' => 'No file to process']);
                exit;
            }
            
            $lines = file($_SESSION['upload_file'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($_SESSION['current_line'] >= count($lines)) {
                echo json_encode([
                    'status' => 'completed',
                    'success' => $_SESSION['success'],
                    'failed' => $_SESSION['failed'],
                    'errors' => $_SESSION['errors']
                ]);
                unlink($_SESSION['upload_file']);
                session_destroy();
                exit;
            }
            
            $line = $lines[$_SESSION['current_line']];
            $parts = explode(';', trim($line));
            
            try {
                $mysqli = getDB();
                
                if (count($parts) < 2) {
                    throw new Exception("Invalid format");
                }
                
                $account_sid = trim($parts[0]);
                $auth_token = trim($parts[1]);
                $proxy = isset($parts[2]) ? trim($parts[2]) : null;
                
                // Проверка существования аккаунта
                $stmt = $mysqli->prepare("SELECT COUNT(*) as count FROM twilio_accounts WHERE account_sid = ?");
                $stmt->bind_param("s", $account_sid);
                $stmt->execute();
                if ($stmt->get_result()->fetch_assoc()['count'] > 0) {
                    throw new Exception("Account already exists");
                }
                
                // Проверка прокси
                if ($proxy && !checkProxy($proxy)) {
                    throw new Exception("Proxy test failed");
                }
                
                // Проверка аккаунта и баланса
                $account_check = checkAccount($account_sid, $auth_token, $proxy);
                if ($account_check['status'] !== 'success') {
                    throw new Exception("Invalid account: " . ($account_check['error'] ?? 'Unknown error'));
                }
                
                // Проверка существующих номеров
                $numbers_check = getExistingNumbers($account_sid, $auth_token, $proxy);
                $existing_numbers = [];
                $has_canadian_number = false;
                
                if ($numbers_check['status'] === 'success') {
                    foreach ($numbers_check['numbers'] as $number) {
                        $existing_numbers[] = [
                            'number' => $number['phone_number'],
                            'friendly_name' => $number['friendly_name']
                        ];
                        if (strpos($number['phone_number'], '+1') === 0) {
                            $has_canadian_number = true;
                        }
                    }
                }
                
                // Если нет канадского номера, пытаемся купить
                if (!$has_canadian_number) {
                    $buy_result = buyCanadianNumber($account_sid, $auth_token, $proxy);
                    if ($buy_result['status'] === 'success') {
                        $existing_numbers[] = [
                            'number' => $buy_result['number'],
                            'friendly_name' => $buy_result['friendly_name']
                        ];
                    }
                }
                
                // Начинаем транзакцию
                $mysqli->begin_transaction();
                
                try {
                    // Добавляем аккаунт
                    $stmt = $mysqli->prepare("
                        INSERT INTO twilio_accounts (
                            account_sid, auth_token, proxy_address, 
                            balance, status, numbers_count, sms_count
                        ) VALUES (?, ?, ?, ?, 'active', ?, 0)
                    ");
                    
                    $numbers_count = count($existing_numbers);
                    $stmt->bind_param("sssdi",
                        $account_sid,
                        $auth_token,
                        $proxy,
                        $account_check['balance'],
                        $numbers_count
                    );
                    
                    $stmt->execute();
                    $account_id = $mysqli->insert_id;
                    
                    // Добавляем номера
                    if (!empty($existing_numbers)) {
                        $stmt = $mysqli->prepare("
                            INSERT INTO phone_numbers (
                                account_id, phone_number, friendly_name, 
                                area_code, status
                            ) VALUES (?, ?, ?, ?, 'active')
                        ");
                        
                        foreach ($existing_numbers as $number) {
                            $area_code = substr($number['number'], 2, 3);
                            $stmt->bind_param("isss",
                                $account_id,
                                $number['number'],
                                $number['friendly_name'],
                                $area_code
                            );
                            $stmt->execute();
                        }
                    }
                    
                    $mysqli->commit();
                    $_SESSION['success']++;
                    
                    $result = [
                        'status' => 'success',
                        'message' => "Account added successfully. Balance: $" . 
                                   number_format($account_check['balance'], 2) . 
                                   ", Numbers: " . $numbers_count
                    ];
                    
                } catch (Exception $e) {
                    $mysqli->rollback();
                    throw $e;
                }
                
            } catch (Exception $e) {
                $_SESSION['failed']++;
                $_SESSION['errors'][] = "Line " . ($_SESSION['current_line'] + 1) . ": " . $e->getMessage();
                $result = [
                    'status' => 'error',
                    'message' => $e->getMessage()
                ];
            }
            
            $_SESSION['current_line']++;
            
            echo json_encode([
                'status' => 'processing',
                'current_line' => $_SESSION['current_line'],
                'total_lines' => $_SESSION['total_lines'],
                'result' => $result
            ]);
            break;
    }
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Import Twilio Accounts</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2/dist/tailwind.min.css" rel="stylesheet">
    <style>
        .log-container {
            max-height: 600px;
            overflow-y: auto;
        }
    </style>
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto bg-white rounded-lg shadow p-6">
        <h1 class="text-2xl font-bold mb-6">Import Twilio Accounts</h1>

        <div id="upload-form">
            <form id="accountsForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Select accounts file (txt format)</label>
                    <input type="file" name="accountsFile" accept=".txt" required
                           class="mt-1 block w-full px-3 py-2 border rounded-md shadow-sm">
                    <p class="mt-2 text-sm text-gray-500">Format: AccountSID;AuthToken;Proxy</p>
                </div>
                <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    Upload and Process
                </button>
            </form>
        </div>

        <div id="progress" class="hidden">
            <div class="mb-4">
                <div class="text-lg font-medium">Processing accounts...</div>
                <div class="mt-2 h-6 relative w-full rounded-full overflow-hidden bg-gray-200">
                    <div id="progress-bar" class="w-0 h-full bg-blue-500 transition-all duration-200"></div>
                </div>
                <div id="progress-text" class="mt-1 text-sm text-gray-600"></div>
            </div>
            
            <div class="log-container space-y-2" id="log"></div>
            
            <div id="summary" class="hidden mt-4">
                <div class="p-4 bg-gray-100 rounded">
                    <div class="font-bold">Processing completed:</div>
                    <div id="summary-text"></div>
                </div>
            </div>
        </div>

        <div class="mt-6">
            <a href="index.php" class="inline-block bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                Back to Admin Panel
            </a>
        </div>
    </div>

    <script>
        document.getElementById('accountsForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            const uploadForm = document.getElementById('upload-form');
            const progress = document.getElementById('progress');
            const progressBar = document.getElementById('progress-bar');
            const progressText = document.getElementById('progress-text');
            const log = document.getElementById('log');
            const summary = document.getElementById('summary');
            const summaryText = document.getElementById('summary-text');
            
            // Инициализация
            try {
                const initResponse = await fetch('?action=init', {
                    method: 'POST',
                    body: formData
                });
                const initData = await initResponse.json();
                
                if (initData.error) {
                    throw new Error(initData.error);
                }
                
                uploadForm.classList.add('hidden');
                progress.classList.remove('hidden');
                
                // Обработка строк
                let currentLine = 0;
                const totalLines = initData.total_lines;
                
                progressText.textContent = `Processing account 1 of ${totalLines}`;
                
                while (true) {
                    const processResponse = await fetch('?action=process');
                    const processData = await processResponse.json();
                    
                    if (processData.error) {
                        throw new Error(processData.error);
                    }
                    
                    // Обновляем прогресс
                    currentLine = processData.current_line;
                    const percent = (currentLine / totalLines * 100).toFixed(1);
                    progressBar.style.width = percent + '%';
                    progressText.textContent = `Processing account ${currentLine} of ${totalLines} (${percent}%)`;
                    
                    // Добавляем результат в лог
                    const logEntry = document.createElement('div');
                    logEntry.className = 'border-l-4 p-2 ' + 
                        (processData.result.status === 'success' ? 'border-green-500' : 'border-red-500');
                    logEntry.textContent = `Line ${currentLine}: ${processData.result.message}`;
                    log.appendChild(logEntry);
                    log.scrollTop = log.scrollHeight;
                    
                    // Если обработка завершена
                    if (processData.status === 'completed') {
                        progressText.textContent = 'Processing completed';
                        summary.classList.remove('hidden');
                        summaryText.innerHTML = `
                            Successfully added: ${processData.success}<br>
                            Failed: ${processData.failed}<br>
                            ${processData.errors.length > 0 ? '<br>Errors:<br>' + processData.errors.join('<br>') : ''}
                        `;
                        break;
                    }
                    
                    // Небольшая задержка между запросами
                    await new Promise(resolve => setTimeout(resolve, 500));
                }
                
            } catch (error) {
                alert('Error: ' + error.message);
                console.error(error);
            }
        });
    </script>
</body>
</html>