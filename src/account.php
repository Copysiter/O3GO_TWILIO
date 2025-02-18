<?php
session_start();
require_once 'config.php';
require_once 'functions.php';

// Проверка авторизации
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

try {
    $mysqli = getDB();
    
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        header('Location: index.php');
        exit;
    }

    $account_id = (int)$_GET['id'];
    
    // Получение информации об аккаунте
    $stmt = $mysqli->prepare("
        SELECT * FROM twilio_accounts 
        WHERE numeric_id = ?
    ");
    $stmt->bind_param('i', $account_id);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    
    if (!$account) {
        header('Location: index.php');
        exit;
    }

    // Инициализация Twilio менеджера для этого аккаунта
    class TwilioManager {
        private $account_sid;
        private $auth_token;
        private $baseUrl;
        private $proxy;

        public function __construct($account_sid, $auth_token, $proxy = null) {
            $this->account_sid = $account_sid;
            $this->auth_token = $auth_token;
            $this->baseUrl = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}";
            $this->proxy = $proxy;
        }

        private function makeRequest($url, $method = 'GET', $data = null) {
            $ch = curl_init($url);
            
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_USERPWD => "{$this->account_sid}:{$this->auth_token}",
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30
            ];

            if ($this->proxy) {
                $options[CURLOPT_PROXY] = $this->proxy;
                $options[CURLOPT_PROXYTYPE] = CURLPROXY_SOCKS5;
            }

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                if ($data) {
                    $options[CURLOPT_POSTFIELDS] = http_build_query($data);
                }
            } else if ($method === 'DELETE') {
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            }

            curl_setopt_array($ch, $options);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if (curl_errno($ch)) {
                throw new Exception(curl_error($ch));
            }
            
            curl_close($ch);
            
            if ($http_code >= 400) {
                throw new Exception("API Error: HTTP {$http_code}");
            }
            
            return json_decode($response, true);
        }


        public function getSMSMessages($limit = 50) {
            $response = $this->makeRequest("{$this->baseUrl}/Messages.json?PageSize={$limit}");
            return $response['messages'];
        }

        public function getCalls($limit = 50) {
            $response = $this->makeRequest("{$this->baseUrl}/Calls.json?PageSize={$limit}");
            return $response['calls'];
        }

        public function getBalance() {
            $response = $this->makeRequest("{$this->baseUrl}/Balance.json");
            return $response;
        }
        
        public function getOwnedNumbers() {
            $response = $this->makeRequest("{$this->baseUrl}/IncomingPhoneNumbers.json");
            return $response['incoming_phone_numbers'];
        }
        
        public function deleteNumber($sid) {
            return $this->makeRequest("{$this->baseUrl}/IncomingPhoneNumbers/{$sid}.json", 'DELETE');
        }
        
        // Добавлен новый метод для поиска доступных номеров
        public function getAvailableNumbers($countryCode = 'US', $type = 'Local') {
            $response = $this->makeRequest("{$this->baseUrl}/AvailablePhoneNumbers/$countryCode/$type.json");
            return $response['available_phone_numbers'];
        }
        
        public function purchaseNumber($phone_number) {
            return $this->makeRequest(
                "{$this->baseUrl}/IncomingPhoneNumbers.json", 
                'POST', 
                ['PhoneNumber' => $phone_number]
            );
        }
    }

    $twilioManager = new TwilioManager(
        $account['account_sid'], 
        $account['auth_token'],
        $account['proxy_address']
    );

    // Получение SMS, звонков и баланса
    $messages = $twilioManager->getSMSMessages();
    $calls = $twilioManager->getCalls();
    $balance = $twilioManager->getBalance();
    $numbers = $twilioManager->getOwnedNumbers();

    // Обработка действий
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($_POST['action'] === 'delete_number' && isset($_POST['sid'])) {
            try {
                $twilioManager->deleteNumber($_POST['sid']);
                $success = "Номер успешно удален";
                header("Location: account.php?id={$account_id}&success=" . urlencode($success));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
        // Добавлена обработка покупки номера
        else if ($_POST['action'] === 'purchase' && isset($_POST['phone_number'])) {
            try {
                $twilioManager->purchaseNumber($_POST['phone_number']);
                $success = "Номер успешно куплен!";
                header("Location: account.php?id={$account_id}&success=" . urlencode($success));
                exit;
            } catch (Exception $e) {
                $error = $e->getMessage();
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>


<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Details - <?php echo htmlspecialchars($account['account_sid']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .card-header {
            background-color: #6c757d;
            color: white;
        }
        .clickable-row {
            cursor: pointer;
        }
        .clickable-row:hover {
            background-color: rgba(0,0,0,0.05) !important;
        }
        .action-button {
            margin: 5px;
        }
    </style>
</head>


<body>
<div class="container mt-4">
    <!-- Навигация -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>
            <a href="index.php" class="text-decoration-none text-dark">
                <i class="fas fa-arrow-left"></i>
            </a>
            Account Details
        </h2>
        <div>
            <span class="me-3">
                <?php echo htmlspecialchars($_SESSION['admin_username']); ?>
            </span>
                               <a href="index.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Назад
            </a>
                    </a>
            <a href="logout.php" class="btn btn-outline-danger">
                <i class="fas fa-sign-out-alt"></i> Выйти
            </a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?php echo htmlspecialchars($_GET['success']); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Информация об аккаунте -->
    <div class="card dashboard-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-info-circle"></i> Account Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <p><strong>Account SID:</strong><br><?php echo htmlspecialchars($account['account_sid']); ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Status:</strong><br>
                        <span class="badge bg-<?php echo $account['status'] === 'active' ? 'success' : 'danger'; ?>">
                            <?php echo ucfirst($account['status']); ?>
                        </span>
                    </p>
                </div>
                <div class="col-md-4">
                    <p><strong>Balance:</strong><br>$<?php echo number_format($balance['balance'], 2); ?></p>
                </div>
            </div>
        </div>
    </div>


    <!-- Номера -->
    <div class="card dashboard-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-phone"></i> Numbers</h5>
        </div>
        <div class="card-body">
            <!-- Текущие номера -->
            <h6>Current Numbers</h6>
            <div class="table-responsive mb-4">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Phone Number</th>
                            <th>Type</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($numbers as $number): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($number['phone_number']); ?></td>
                                <td>
                                    <?php echo $number['capabilities']['voice'] ? 'Voice + SMS' : 'SMS Only'; ?>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_number">
                                        <input type="hidden" name="sid" value="<?php echo $number['sid']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm action-button"
                                                onclick="return confirm('Are you sure you want to delete this number?')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Добавление нового номера -->
            <h6>Add New Number</h6>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="search">
                <div class="col-md-4">
                    <select name="country" class="form-select">
                        <option value="US">United States</option>
                        <option value="CA">Canada</option>
                        <option value="GB">United Kingdom</option>
                        <option value="AU">Australia</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search Available Numbers
                    </button>
                </div>
            </form>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_POST['action'] === 'search') {
                try {
                    $numbers = $twilioManager->getAvailableNumbers($_POST['country']);
                    if (count($numbers) > 0): ?>
                        <div class="mt-4">
                            <h6>Available Numbers:</h6>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Number</th>
                                            <th>Type</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($numbers as $number): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($number['phone_number']); ?></td>
                                                <td><?php echo $number['capabilities']['voice'] ? 'Voice + SMS' : 'SMS Only'; ?></td>
                                                <td>
                                                    <form method="POST">
                                                        <input type="hidden" name="action" value="purchase">
                                                        <input type="hidden" name="phone_number" value="<?php echo $number['phone_number']; ?>">
                                                        <button type="submit" class="btn btn-success btn-sm action-button">
                                                            <i class="fas fa-shopping-cart"></i> Purchase
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mt-4">
                            No available numbers for selected country.
                        </div>
                    <?php endif;
                } catch (Exception $e) {
                    echo '<div class="alert alert-danger mt-4">Error searching for numbers: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
            ?>
        </div>
    </div>

    <!-- SMS -->
    <div class="card dashboard-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-sms"></i> Messages</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th width="40%">Message</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $message): ?>
                            <tr class="clickable-row" data-bs-toggle="modal" 
                                data-bs-target="#smsModal" 
                                data-sid="<?php echo $message['sid']; ?>">
                                <td><?php echo date('Y-m-d H:i', strtotime($message['date_created'])); ?></td>
                                <td><?php echo htmlspecialchars($message['from']); ?></td>
                                <td><?php echo htmlspecialchars($message['to']); ?></td>
                                <td class="message-preview">
                                    <?php echo htmlspecialchars($message['body']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?php echo $message['status'] === 'delivered' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($message['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Calls -->
    <div class="card dashboard-card">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-phone"></i> Calls</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Duration</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calls as $call): ?>
                            <tr class="clickable-row" data-bs-toggle="modal" 
                                data-bs-target="#callModal" 
                                data-sid="<?php echo $call['sid']; ?>">
                                <td><?php echo date('Y-m-d H:i', strtotime($call['date_created'])); ?></td>
                                <td><?php echo htmlspecialchars($call['from']); ?></td>
                                <td><?php echo htmlspecialchars($call['to']); ?></td>
                                <td><?php echo $call['duration']; ?> sec</td>
                                <td>
                                    <span class="badge bg-<?php echo $call['status'] === 'completed' ? 'success' : 'warning'; ?>">
                                        <?php echo ucfirst($call['status']); ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Модальное окно для SMS -->
<div class="modal fade" id="smsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Message Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-center" id="smsSpinner">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div class="sms-details" style="display: none;">
                    <dl class="row">
                        <dt class="col-sm-3">Send Date:</dt>
                        <dd class="col-sm-9" id="smsDate"></dd>
                        
                        <dt class="col-sm-3">From:</dt>
                        <dd class="col-sm-9" id="smsFrom"></dd>
                        
                        <dt class="col-sm-3">To:</dt>
                        <dd class="col-sm-9" id="smsTo"></dd>
                        
                        <dt class="col-sm-3">Status:</dt>
                        <dd class="col-sm-9" id="smsStatus"></dd>
                        
                        <dt class="col-sm-3">Message:</dt>
                        <dd class="col-sm-9" id="smsBody"></dd>
                        
                        <dt class="col-sm-3">Price:</dt>
                        <dd class="col-sm-9" id="smsPrice"></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Модальное окно для звонков -->
<div class="modal fade" id="callModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Call Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-center" id="callSpinner">
                    <div class="spinner-border" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
                <div class="call-details" style="display: none;">
                    <dl class="row">
                        <dt class="col-sm-3">Call Date:</dt>
                        <dd class="col-sm-9" id="callDate"></dd>
                        
                        <dt class="col-sm-3">From:</dt>
                        <dd class="col-sm-9" id="callFrom"></dd>
                        
                        <dt class="col-sm-3">To:</dt>
                        <dd class="col-sm-9" id="callTo"></dd>
                        
                        <dt class="col-sm-3">Duration:</dt>
                        <dd class="col-sm-9" id="callDuration"></dd>
                        
                        <dt class="col-sm-3">Status:</dt>
                        <dd class="col-sm-9" id="callStatus"></dd>
                        
                        <dt class="col-sm-3">Price:</dt>
                        <dd class="col-sm-9" id="callPrice"></dd>

                        <dt class="col-sm-3">Recording:</dt>
                        <dd class="col-sm-9" id="callRecording"></dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Обработка клика по SMS
    const smsModal = document.getElementById('smsModal');
    if (smsModal) {
        smsModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const sid = button.getAttribute('data-sid');
            
            const spinner = document.getElementById('smsSpinner');
            const details = smsModal.querySelector('.sms-details');
            
            spinner.style.display = 'block';
            details.style.display = 'none';
            
            fetch(`?action=get_sms_details&sid=${sid}&id=<?php echo $account_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('smsDate').textContent = new Date(data.date_created).toLocaleString();
                    document.getElementById('smsFrom').textContent = data.from;
                    document.getElementById('smsTo').textContent = data.to;
                    document.getElementById('smsStatus').textContent = data.status;
                    document.getElementById('smsBody').textContent = data.body;
                    document.getElementById('smsPrice').textContent = data.price ? data.price + ' ' + data.price_unit : 'Free';
                    
                    spinner.style.display = 'none';
                    details.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    smsModal.querySelector('.modal-body').innerHTML = 
                        '<div class="alert alert-danger">Error loading message details</div>';
                });
        });
    }

    // Обработка клика по звонку
    const callModal = document.getElementById('callModal');
    if (callModal) {
        callModal.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget;
            const sid = button.getAttribute('data-sid');
            
            const spinner = document.getElementById('callSpinner');
            const details = callModal.querySelector('.call-details');
            
            spinner.style.display = 'block';
            details.style.display = 'none';
            
            fetch(`?action=get_call_details&sid=${sid}&id=<?php echo $account_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('callDate').textContent = new Date(data.date_created).toLocaleString();
                    document.getElementById('callFrom').textContent = data.from;
                    document.getElementById('callTo').textContent = data.to;
                    document.getElementById('callDuration').textContent = data.duration + ' seconds';
                    document.getElementById('callStatus').textContent = data.status;
                    document.getElementById('callPrice').textContent = data.price ? data.price + ' ' + data.price_unit : 'Free';
                    
                    if (data.recordings && data.recordings.length > 0) {
                        document.getElementById('callRecording').innerHTML = `
                            <audio controls>
                                <source src="${data.recordings[0].url}" type="audio/mpeg">
                                Your browser does not support the audio element.
                            </audio>`;
                    } else {
                        document.getElementById('callRecording').textContent = 'Recording not available';
                    }
                    
                    spinner.style.display = 'none';
                    details.style.display = 'block';
                })
                .catch(error => {
                    console.error('Error:', error);
                    callModal.querySelector('.modal-body').innerHTML = 
                        '<div class="alert alert-danger">Error loading call details</div>';
                });
        });
    }

    // Очистка модальных окон при закрытии
    const modals = document.querySelectorAll('.modal');
    modals.forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function() {
            const spinner = modal.querySelector('.spinner-border').parentElement;
            const details = modal.querySelector('.modal-body > div:not(.d-flex)');
            spinner.style.display = 'block';
            details.style.display = 'none';
        });
    });
});
</script>
</body>
</html>