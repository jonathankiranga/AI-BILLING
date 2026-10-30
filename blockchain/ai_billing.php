<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('BlockchainLIMS');
    session_start();
}
$isAdministrator = (isset($_SESSION['UserID']) && (int)$_SESSION['UserID'] === 1)
    || (isset($_SESSION['role']) && strtolower((string)$_SESSION['role']) === 'admin');
if (!$isAdministrator) {
    http_response_code(403);
    exit('Administrator access is required to manage AI billing.');
}
require_once 'db_connection.php';

function aiBillingPageEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
    if ($action === 'save_price') {
        $serviceCode = isset($_POST['service_code']) ? trim($_POST['service_code']) : '';
        $serviceName = isset($_POST['service_name']) ? trim($_POST['service_name']) : '';
        $price = isset($_POST['selling_price']) ? (float)$_POST['selling_price'] : -1;
        $inputRate = isset($_POST['provider_input_cost_per_million']) ? (float)$_POST['provider_input_cost_per_million'] : 0;
        $outputRate = isset($_POST['provider_output_cost_per_million']) ? (float)$_POST['provider_output_cost_per_million'] : 0;
        $active = isset($_POST['active']) ? 1 : 0;
        if ($serviceCode === '' || $serviceName === '' || $price < 0 || $inputRate < 0 || $outputRate < 0) {
            $error = 'Enter a valid service name and a non-negative KES price.';
        } else {
            $stmt = $conn->prepare("INSERT INTO ai_service_pricing (service_code, service_name, selling_price, provider_input_cost_per_million, provider_output_cost_per_million, currency, active) VALUES (?, ?, ?, ?, ?, 'KES', ?) ON DUPLICATE KEY UPDATE service_name = VALUES(service_name), selling_price = VALUES(selling_price), provider_input_cost_per_million = VALUES(provider_input_cost_per_million), provider_output_cost_per_million = VALUES(provider_output_cost_per_million), active = VALUES(active), currency = 'KES'");
            if ($stmt) {
                $stmt->bind_param('ssdddi', $serviceCode, $serviceName, $price, $inputRate, $outputRate, $active);
                if ($stmt->execute()) $message = 'AI service pricing saved.';
                else $error = 'Could not save pricing: ' . $stmt->error;
                $stmt->close();
            } else $error = 'Could not prepare the pricing update.';
        }
    } elseif ($action === 'credit_wallet') {
        $customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
        $amount = isset($_POST['amount']) ? round((float)$_POST['amount'], 2) : 0;
        $description = isset($_POST['description']) ? trim($_POST['description']) : 'Administrator wallet top-up';
        $createdBy = isset($_SESSION['username']) ? (string)$_SESSION['username'] : 'Administrator';
        if ($customerId === '' || $amount <= 0) {
            $error = 'Select a customer and enter a top-up amount greater than zero.';
        } else {
            $conn->begin_transaction();
            try {
                $insert = $conn->prepare("INSERT IGNORE INTO ai_wallets (customer_id, balance, currency) VALUES (?, 0.00, 'KES')");
                if (!$insert) throw new Exception('Unable to initialize wallet.');
                $insert->bind_param('s', $customerId);
                if (!$insert->execute()) throw new Exception('Unable to initialize wallet.');
                $insert->close();

                $lock = $conn->prepare("SELECT balance, status FROM ai_wallets WHERE customer_id = ? FOR UPDATE");
                $lock->bind_param('s', $customerId);
                if (!$lock->execute()) throw new Exception('Unable to lock wallet.');
                $wallet = $lock->get_result()->fetch_assoc();
                $lock->close();
                if (!$wallet || $wallet['status'] !== 'active') throw new Exception('Wallet not found or suspended.');

                $newBalance = round((float)$wallet['balance'] + $amount, 2);
                $update = $conn->prepare("UPDATE ai_wallets SET balance = ? WHERE customer_id = ?");
                $update->bind_param('ds', $newBalance, $customerId);
                if (!$update->execute()) throw new Exception('Unable to update wallet balance.');
                $update->close();

                $type = 'CREDIT';
                $reference = 'ADMIN-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
                $ledger = $conn->prepare("INSERT INTO ai_wallet_transactions (customer_id, transaction_type, amount, balance_after, reference, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ledger->bind_param('ssddsss', $customerId, $type, $amount, $newBalance, $reference, $description, $createdBy);
                if (!$ledger->execute()) throw new Exception('Unable to record wallet credit.');
                $ledger->close();
                $conn->commit();
                $message = 'Wallet credited by KES ' . number_format($amount, 2) . '. New balance: KES ' . number_format($newBalance, 2) . '.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = $e->getMessage();
            }
        }
    }
}

$pricing = array();
$result = $conn->query("SELECT service_code, service_name, selling_price, provider_input_cost_per_million, provider_output_cost_per_million, currency, active, updated_at FROM ai_service_pricing ORDER BY service_name");
if ($result) while ($row = $result->fetch_assoc()) $pricing[] = $row;

$customers = array();
$result = $conn->query("SELECT CustomerID, MAX(CustomerName) AS CustomerName, MAX(`Date`) AS LastSample FROM sample_header WHERE CustomerID <> '' GROUP BY CustomerID ORDER BY CustomerName LIMIT 300");
if ($result) while ($row = $result->fetch_assoc()) $customers[] = $row;

$wallets = array();
$result = $conn->query("SELECT w.customer_id, w.balance, w.currency, w.status, w.updated_at, MAX(sh.CustomerName) AS CustomerName FROM ai_wallets w LEFT JOIN sample_header sh ON sh.CustomerID = w.customer_id GROUP BY w.customer_id, w.balance, w.currency, w.status, w.updated_at ORDER BY w.updated_at DESC LIMIT 100");
if ($result) while ($row = $result->fetch_assoc()) $wallets[] = $row;

$payments = array();
$result = $conn->query("SELECT payment_id, customer_id, phone_number, amount, mpesa_receipt_number, payment_status, result_description, created_at FROM mpesa_payments ORDER BY payment_id DESC LIMIT 40");
if ($result) while ($row = $result->fetch_assoc()) $payments[] = $row;

$usage = array();
$result = $conn->query("SELECT u.customer_id, u.sample_id, u.service_code, u.model, u.input_tokens, u.output_tokens, u.provider_cost_kes, u.gross_margin_kes, u.customer_charge, u.currency, u.created_at, MAX(sh.CustomerName) AS CustomerName FROM ai_usage_logs u LEFT JOIN sample_header sh ON sh.CustomerID = u.customer_id GROUP BY u.usage_id, u.customer_id, u.sample_id, u.service_code, u.model, u.input_tokens, u.output_tokens, u.provider_cost_kes, u.gross_margin_kes, u.customer_charge, u.currency, u.created_at ORDER BY u.usage_id DESC LIMIT 50");
if ($result) while ($row = $result->fetch_assoc()) $usage[] = $row;

$totalBalance = 0;
$totalSales = 0;
$totalProviderCost = 0;
$totalMargin = 0;
$result = $conn->query("SELECT COALESCE(SUM(balance),0) AS total_balance FROM ai_wallets");
if ($result) $totalBalance = (float)$result->fetch_assoc()['total_balance'];
$result = $conn->query("SELECT COALESCE(SUM(customer_charge),0) AS total_sales, COALESCE(SUM(provider_cost_kes),0) AS total_provider_cost, COALESCE(SUM(gross_margin_kes),0) AS total_margin FROM ai_usage_logs WHERE status = 'SUCCESS'");
if ($result) $totals = $result->fetch_assoc();
if ($totals) { $totalSales = (float)$totals['total_sales']; $totalProviderCost = (float)$totals['total_provider_cost']; $totalMargin = (float)$totals['total_margin']; }
?>
<div class="container-fluid py-3" style="font-family:Arial,sans-serif;color:#243447;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div><h3 class="mb-1"><i class="fas fa-wallet"></i> AI Billing & Wallets</h3><div class="text-muted small">Prepaid AI credits, customer balances, service pricing and usage revenue</div></div>
        <span class="badge bg-dark p-2">Currency: KES</span>
    </div>
    <?php if ($message !== ''): ?><div class="alert alert-success"><?php echo aiBillingPageEscape($message); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo aiBillingPageEscape($error); ?></div><?php endif; ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Customer wallet funds outstanding</div><div class="fs-3 fw-bold">KES <?php echo number_format($totalBalance,2); ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">AI service charges recorded</div><div class="fs-3 fw-bold">KES <?php echo number_format($totalSales,2); ?></div></div></div></div>
        <div class="col-md-4"><div class="card shadow-sm"><div class="card-body"><div class="text-muted small">Gross margin (estimated)</div><div class="fs-3 fw-bold">KES <?php echo number_format($totalMargin,2); ?></div><div class="small text-muted">Provider cost: KES <?php echo number_format($totalProviderCost,2); ?></div></div></div></div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header fw-bold">AI service pricing</div><div class="card-body">
                <?php if (!$pricing): ?><div class="alert alert-warning">Run blockchain/sql/ai_prepaid_wallet_billing.sql to create the billing tables and default service.</div><?php endif; ?>
                <?php foreach ($pricing as $service): ?>
                <form method="post" class="border rounded p-3 mb-3">
                    <input type="hidden" name="action" value="save_price">
                    <input type="hidden" name="service_code" value="<?php echo aiBillingPageEscape($service['service_code']); ?>">
                    <div class="fw-semibold"><?php echo aiBillingPageEscape($service['service_name']); ?></div>
                    <div class="small text-muted mb-2"><?php echo aiBillingPageEscape($service['service_code']); ?></div>
                    <input type="hidden" name="service_name" value="<?php echo aiBillingPageEscape($service['service_name']); ?>">
                    <div class="row g-2">
                        <div class="col-md-4"><label class="form-label small">Selling price (KES)</label><input type="number" min="0" step="0.01" name="selling_price" class="form-control" value="<?php echo aiBillingPageEscape($service['selling_price']); ?>" required></div>
                        <div class="col-md-4"><label class="form-label small">Provider input cost / 1M tokens (KES)</label><input type="number" min="0" step="0.0001" name="provider_input_cost_per_million" class="form-control" value="<?php echo aiBillingPageEscape($service['provider_input_cost_per_million']); ?>" required></div>
                        <div class="col-md-4"><label class="form-label small">Provider output cost / 1M tokens (KES)</label><input type="number" min="0" step="0.0001" name="provider_output_cost_per_million" class="form-control" value="<?php echo aiBillingPageEscape($service['provider_output_cost_per_million']); ?>" required></div>
                        <div class="col-12 d-flex justify-content-between align-items-center mt-2"><div class="form-check"><input class="form-check-input" type="checkbox" name="active" id="active_<?php echo aiBillingPageEscape($service['service_code']); ?>" <?php echo ((int)$service['active'] === 1 ? 'checked' : ''); ?>><label class="form-check-label" for="active_<?php echo aiBillingPageEscape($service['service_code']); ?>">Active</label></div><button class="btn btn-primary">Save pricing</button></div>
                    </div>
                </form>
                <?php endforeach; ?>
                <div class="small text-muted">An AI operation is blocked until a positive price is saved and activated. Enter provider cost estimates in KES per million tokens. Gross margin is an estimate, not an audited provider invoice reconciliation.</div>
            </div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header fw-bold">Issue prepaid wallet credits</div><div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="credit_wallet">
                    <div class="mb-3"><label class="form-label">Customer account (ERP CustomerID)</label><select class="form-select" name="customer_id" required><option value="">Select customer</option><?php foreach ($customers as $customer): ?><option value="<?php echo aiBillingPageEscape($customer['CustomerID']); ?>"><?php echo aiBillingPageEscape($customer['CustomerName'] . ' — ' . $customer['CustomerID']); ?></option><?php endforeach; ?></select></div>
                    <div class="mb-3"><label class="form-label">Credit amount (KES)</label><input class="form-control" type="number" name="amount" min="1" step="0.01" required></div>
                    <div class="mb-3"><label class="form-label">Reference / description</label><input class="form-control" type="text" name="description" maxlength="500" value="Administrator wallet top-up"></div>
                    <button class="btn btn-success"><i class="fas fa-plus-circle"></i> Credit wallet</button>
                </form>
                <div class="small text-muted mt-3">This is an administrator-issued test/manual credit. It is not an M-PESA confirmation. Customer wallets are keyed to Sample_Header.CustomerID, which matches the ERP customer account code.</div>
                <hr class="my-4">
                <h6 class="fw-bold">Request M-PESA STK Push</h6>
                <form id="aiMpesaStkForm">
                    <div class="mb-2"><label class="form-label">Customer account</label><select class="form-select" name="customer_id" required><option value="">Select customer</option><?php foreach ($customers as $customer): ?><option value="<?php echo aiBillingPageEscape($customer['CustomerID']); ?>"><?php echo aiBillingPageEscape($customer['CustomerName'] . ' — ' . $customer['CustomerID']); ?></option><?php endforeach; ?></select></div>
                    <div class="row g-2"><div class="col"><label class="form-label">M-PESA phone</label><input class="form-control" name="phone_number" placeholder="07XXXXXXXX or 2547XXXXXXXX" required></div><div class="col"><label class="form-label">Amount (KES)</label><input class="form-control" type="number" name="amount" min="1" step="1" required></div></div>
                    <button type="submit" class="btn btn-success mt-3"><i class="fas fa-mobile-alt"></i> Send STK Push</button>
                </form>
                <div id="aiMpesaMessage" class="small mt-2" aria-live="polite"></div>
            </div></div>
        </div>
    </div>

    <div class="card mb-4"><div class="card-header fw-bold">Customer wallet balances</div><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Customer</th><th>ERP Account</th><th>Balance</th><th>Status</th><th>Updated</th></tr></thead><tbody>
    <?php if (!$wallets): ?><tr><td colspan="5" class="text-center text-muted">No wallet accounts yet.</td></tr><?php endif; ?>
    <?php foreach ($wallets as $wallet): ?><tr><td><?php echo aiBillingPageEscape($wallet['CustomerName'] ?: 'Customer account'); ?></td><td><?php echo aiBillingPageEscape($wallet['customer_id']); ?></td><td class="fw-bold">KES <?php echo number_format((float)$wallet['balance'],2); ?></td><td><?php echo aiBillingPageEscape($wallet['status']); ?></td><td><?php echo aiBillingPageEscape($wallet['updated_at']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>

    <div class="card"><div class="card-header fw-bold">Recent AI usage and charges</div><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Customer</th><th>Sample</th><th>Service</th><th>Model</th><th>Tokens</th><th>Provider cost</th><th>Gross margin</th><th>Customer charge</th></tr></thead><tbody>
    <?php if (!$usage): ?><tr><td colspan="9" class="text-center text-muted">No billable AI usage recorded yet.</td></tr><?php endif; ?>
    <?php foreach ($usage as $item): ?><tr><td><?php echo aiBillingPageEscape($item['created_at']); ?></td><td><?php echo aiBillingPageEscape($item['CustomerName'] ?: $item['customer_id']); ?></td><td><?php echo aiBillingPageEscape($item['sample_id']); ?></td><td><?php echo aiBillingPageEscape($item['service_code']); ?></td><td><?php echo aiBillingPageEscape($item['model']); ?></td><td><?php echo (int)$item['input_tokens'] + (int)$item['output_tokens']; ?></td><td>KES <?php echo number_format((float)$item['provider_cost_kes'],4); ?></td><td>KES <?php echo number_format((float)$item['gross_margin_kes'],4); ?></td><td class="fw-bold">KES <?php echo number_format((float)$item['customer_charge'],2); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <div class="card mt-4"><div class="card-header fw-bold">Recent M-PESA wallet top-ups</div><div class="table-responsive"><table class="table table-sm table-striped mb-0"><thead><tr><th>Date</th><th>Customer account</th><th>Phone</th><th>Amount</th><th>Receipt</th><th>Status</th><th>Details</th></tr></thead><tbody>
    <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted">No M-PESA requests recorded.</td></tr><?php endif; ?>
    <?php foreach ($payments as $payment): ?><tr><td><?php echo aiBillingPageEscape($payment['created_at']); ?></td><td><?php echo aiBillingPageEscape($payment['customer_id']); ?></td><td><?php echo aiBillingPageEscape($payment['phone_number']); ?></td><td>KES <?php echo number_format((float)$payment['amount'],2); ?></td><td><?php echo aiBillingPageEscape($payment['mpesa_receipt_number']); ?></td><td><?php echo aiBillingPageEscape($payment['payment_status']); ?></td><td><?php echo aiBillingPageEscape($payment['result_description']); ?></td></tr><?php endforeach; ?>
    </tbody></table></div></div>
    <div class="alert alert-info mt-3 mb-0"><strong>M-PESA server setup:</strong> Configure Daraja environment variables on the PHP server. The callback must be a publicly reachable HTTPS URL pointing to <code>ajax/ai_wallet_mpesa_callback.php</code>. Wallet credit is posted only by the callback after receipt, amount and checkout reference checks; the browser response never credits the wallet.</div>
</div>
<script>
(function(){
    var form = document.getElementById('aiMpesaStkForm');
    if (!form || form.getAttribute('data-bound') === '1') return;
    form.setAttribute('data-bound','1');
    form.addEventListener('submit', function(event){
        event.preventDefault();
        var message = document.getElementById('aiMpesaMessage');
        var button = form.querySelector('button[type="submit"]');
        var original = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';
        message.className = 'small mt-2 text-muted';
        message.textContent = 'Contacting Safaricom...';
        fetch('ajax/ai_wallet_stk_push.php', { method:'POST', body:new FormData(form), cache:'no-store' })
            .then(function(response){ return response.json().then(function(data){ if(!response.ok || !data.success) throw new Error(data.message || 'STK Push failed.'); return data; }); })
            .then(function(data){ message.className = 'small mt-2 text-success'; message.textContent = data.message; })
            .catch(function(error){ message.className = 'small mt-2 text-danger'; message.textContent = error.message; })
            .finally(function(){ button.disabled = false; button.innerHTML = original; });
    });
})();
</script>
