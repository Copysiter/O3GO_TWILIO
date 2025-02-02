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
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="fas fa-phone"></i> Numbers</h5>
            <form method="POST">
                <input type="hidden" name="action" value="add_number">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-plus"></i> Add Canadian Number
                </button>
            </form>
        </div>
        <div class="card-body">
            <div class="table-responsive">
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
                                        <button type="submit" class="btn btn-danger btn-sm"
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
                            <th>Message</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $message): ?>
                            <tr>
                                <td><?php echo date('Y-m-d H:i', strtotime($message['date_created'])); ?></td>
                                <td><?php echo htmlspecialchars($message['from']); ?></td>
                                <td><?php echo htmlspecialchars($message['to']); ?></td>
                                <td><?php echo htmlspecialchars($message['body']); ?></td>
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
                            <tr>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>