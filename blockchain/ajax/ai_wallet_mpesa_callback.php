<?php
header('Content-Type: application/json; charset=utf-8');
require_once '../db_connection.php';

function aiMpesaCallbackLog($message)
{
    error_log('AI M-PESA callback: ' . $message);
}
$expectedToken = getenv('MPESA_CALLBACK_TOKEN');
$providedToken = isset($_GET['token']) ? (string)$_GET['token'] : '';
if ($expectedToken === false || strlen($expectedToken) < 32 || !hash_equals((string)$expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(array('ResultCode' => 1, 'ResultDesc' => 'Forbidden'));
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
$callback = isset($data['Body']['stkCallback']) ? $data['Body']['stkCallback'] : null;
if (!is_array($callback) || empty($callback['CheckoutRequestID']) || !isset($callback['ResultCode'])) {
    http_response_code(400);
    echo json_encode(array('ResultCode' => 1, 'ResultDesc' => 'Invalid callback payload'));
    exit;
}

$checkoutId = (string)$callback['CheckoutRequestID'];
$resultCode = (int)$callback['ResultCode'];
$resultDescription = isset($callback['ResultDesc']) ? (string)$callback['ResultDesc'] : '';
$metadata = array();
if (isset($callback['CallbackMetadata']['Item']) && is_array($callback['CallbackMetadata']['Item'])) {
    foreach ($callback['CallbackMetadata']['Item'] as $item) {
        if (isset($item['Name'])) $metadata[$item['Name']] = isset($item['Value']) ? $item['Value'] : null;
    }
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("SELECT payment_id, customer_id, phone_number, amount, payment_status FROM mpesa_payments WHERE checkout_request_id = ? LIMIT 1 FOR UPDATE");
    if (!$stmt) throw new Exception('Could not prepare payment lookup.');
    $stmt->bind_param('s', $checkoutId);
    if (!$stmt->execute()) throw new Exception('Could not read payment record.');
    $payment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$payment) throw new Exception('Unknown CheckoutRequestID: ' . $checkoutId);

    if ($payment['payment_status'] === 'PAID') {
        $conn->commit();
        echo json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Payment already processed'));
        exit;
    }

    if ($resultCode !== 0) {
        $update = $conn->prepare("UPDATE mpesa_payments SET payment_status = 'FAILED', result_code = ?, result_description = ?, callback_payload = ? WHERE payment_id = ?");
        $update->bind_param('issi', $resultCode, $resultDescription, $raw, $payment['payment_id']);
        if (!$update->execute()) throw new Exception('Could not record failed payment callback.');
        $update->close();
        $conn->commit();
        echo json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Failure callback recorded'));
        exit;
    }

    $paidAmount = isset($metadata['Amount']) ? (float)$metadata['Amount'] : 0;
    $receipt = isset($metadata['MpesaReceiptNumber']) ? trim((string)$metadata['MpesaReceiptNumber']) : '';
    $paidPhone = isset($metadata['PhoneNumber']) ? (string)$metadata['PhoneNumber'] : '';
    $expectedPhone = preg_replace('/[^0-9]/', '', (string)$payment['phone_number']);
    if (strpos($expectedPhone, '0') === 0 && strlen($expectedPhone) === 10) $expectedPhone = '254' . substr($expectedPhone, 1);
    $callbackPhone = preg_replace('/[^0-9]/', '', $paidPhone);
    if ($receipt === '' || $paidAmount <= 0 || round($paidAmount, 2) !== round((float)$payment['amount'], 2)
        || $callbackPhone === '' || $callbackPhone !== $expectedPhone) {
        $mismatch = 'Successful callback did not match the expected amount, phone number or receipt number.';
        $update = $conn->prepare("UPDATE mpesa_payments SET payment_status = 'FAILED', result_code = ?, result_description = ?, callback_payload = ? WHERE payment_id = ?");
        $update->bind_param('issi', $resultCode, $mismatch, $raw, $payment['payment_id']);
        if (!$update->execute()) throw new Exception('Could not record payment mismatch.');
        $update->close();
        $conn->commit();
        aiMpesaCallbackLog($mismatch . ' Checkout=' . $checkoutId);
        echo json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Payment mismatch recorded for manual reconciliation'));
        exit;
    }

    $customerId = $payment['customer_id'];
    $walletCreate = $conn->prepare("INSERT IGNORE INTO ai_wallets (customer_id, balance, currency) VALUES (?, 0.00, 'KES')");
    $walletCreate->bind_param('s', $customerId);
    if (!$walletCreate->execute()) throw new Exception('Could not create wallet for confirmed payment.');
    $walletCreate->close();

    $wallet = $conn->prepare("SELECT balance FROM ai_wallets WHERE customer_id = ? FOR UPDATE");
    $wallet->bind_param('s', $customerId);
    if (!$wallet->execute()) throw new Exception('Could not lock wallet for confirmed payment.');
    $walletRow = $wallet->get_result()->fetch_assoc();
    $wallet->close();
    if (!$walletRow) throw new Exception('Wallet missing after initialization.');

    $reference = 'MPESA-' . $receipt;
    $check = $conn->prepare("SELECT transaction_id FROM ai_wallet_transactions WHERE reference = ? LIMIT 1");
    $check->bind_param('s', $reference);
    if (!$check->execute()) throw new Exception('Could not check M-PESA credit reference.');
    $alreadyCredited = $check->get_result()->fetch_assoc();
    $check->close();

    if (!$alreadyCredited) {
        $amount = (float)$payment['amount'];
        $newBalance = round((float)$walletRow['balance'] + $amount, 2);
        $updateWallet = $conn->prepare("UPDATE ai_wallets SET balance = ? WHERE customer_id = ?");
        $updateWallet->bind_param('ds', $newBalance, $customerId);
        if (!$updateWallet->execute()) throw new Exception('Could not credit customer wallet.');
        $updateWallet->close();

        $type = 'CREDIT';
        $description = 'M-PESA top-up; receipt ' . $receipt . ($paidPhone !== '' ? '; phone ' . $paidPhone : '');
        $ledger = $conn->prepare("INSERT INTO ai_wallet_transactions (customer_id, transaction_type, amount, balance_after, reference, description, created_by) VALUES (?, ?, ?, ?, ?, ?, 'M-PESA callback')");
        $ledger->bind_param('ssddss', $customerId, $type, $amount, $newBalance, $reference, $description);
        if (!$ledger->execute()) throw new Exception('Could not record M-PESA wallet credit.');
        $ledger->close();
    }

    $status = 'PAID';
    $updatePayment = $conn->prepare("UPDATE mpesa_payments SET payment_status = ?, result_code = ?, result_description = ?, mpesa_receipt_number = ?, callback_payload = ?, wallet_credit_reference = ? WHERE payment_id = ?");
    $updatePayment->bind_param('sissssi', $status, $resultCode, $resultDescription, $receipt, $raw, $reference, $payment['payment_id']);
    if (!$updatePayment->execute()) throw new Exception('Could not finalize M-PESA payment record.');
    $updatePayment->close();

    $conn->commit();
    echo json_encode(array('ResultCode' => 0, 'ResultDesc' => 'Payment confirmed and wallet credited'));
} catch (Exception $e) {
    $conn->rollback();
    aiMpesaCallbackLog($e->getMessage());
    http_response_code(500);
    echo json_encode(array('ResultCode' => 1, 'ResultDesc' => 'Callback processing failed; payment requires reconciliation'));
}
