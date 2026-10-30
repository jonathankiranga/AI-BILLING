<?php
header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) {
    session_name('BlockchainLIMS');
    session_start();
}
$isAdministrator = (isset($_SESSION['UserID']) && (int)$_SESSION['UserID'] === 1)
    || (isset($_SESSION['role']) && strtolower((string)$_SESSION['role']) === 'admin');
if (!$isAdministrator) {
    http_response_code(403);
    echo json_encode(array('success' => false, 'message' => 'Administrator access is required to initiate wallet payments.'));
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('success' => false, 'message' => 'POST requests only.'));
    exit;
}
require_once '../db_connection.php';

function aiMpesaResponse($status, $message)
{
    http_response_code($status);
    echo json_encode(array('success' => false, 'message' => $message));
    exit;
}
function aiMpesaEnv($name)
{
    $value = getenv($name);
    return $value === false ? '' : trim((string)$value);
}
function aiMpesaPhone($phone)
{
    $phone = preg_replace('/[^0-9]/', '', (string)$phone);
    if (strpos($phone, '0') === 0 && strlen($phone) === 10) $phone = '254' . substr($phone, 1);
    if (strpos($phone, '254') !== 0 && strlen($phone) === 9) $phone = '254' . $phone;
    return preg_match('/^254[17][0-9]{8}$/', $phone) ? $phone : '';
}

$customerId = isset($_POST['customer_id']) ? trim($_POST['customer_id']) : '';
$phone = aiMpesaPhone(isset($_POST['phone_number']) ? $_POST['phone_number'] : '');
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
if ($customerId === '' || $phone === '' || $amount < 1 || floor($amount) != $amount) {
    aiMpesaResponse(400, 'Select a customer, enter a valid Kenyan M-PESA phone number and a whole-number amount of at least KES 1.');
}

$consumerKey = aiMpesaEnv('MPESA_CONSUMER_KEY');
$consumerSecret = aiMpesaEnv('MPESA_CONSUMER_SECRET');
$shortcode = aiMpesaEnv('MPESA_SHORTCODE');
$passkey = aiMpesaEnv('MPESA_PASSKEY');
$callbackUrl = aiMpesaEnv('MPESA_CALLBACK_URL');
$callbackToken = aiMpesaEnv('MPESA_CALLBACK_TOKEN');
$environment = strtolower(aiMpesaEnv('MPESA_ENVIRONMENT'));
if (!in_array($environment, array('sandbox', 'production'), true)) $environment = 'sandbox';

if ($consumerKey === '' || $consumerSecret === '' || $shortcode === '' || $passkey === '' || $callbackUrl === '' || strlen($callbackToken) < 32) {
    aiMpesaResponse(503, 'M-PESA is not configured. Set MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE, MPESA_PASSKEY, MPESA_CALLBACK_URL and a random MPESA_CALLBACK_TOKEN of at least 32 characters on the server.');
}
if (!function_exists('curl_init')) aiMpesaResponse(500, 'PHP cURL is required for M-PESA STK Push.');

$baseUrl = $environment === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
$oauth = curl_init($baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
curl_setopt($oauth, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . base64_encode($consumerKey . ':' . $consumerSecret)));
curl_setopt($oauth, CURLOPT_RETURNTRANSFER, true);
curl_setopt($oauth, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($oauth, CURLOPT_TIMEOUT, 30);
$oauthBody = curl_exec($oauth);
$oauthStatus = (int)curl_getinfo($oauth, CURLINFO_HTTP_CODE);
$oauthError = curl_error($oauth);
curl_close($oauth);
$oauthData = json_decode((string)$oauthBody, true);
if ($oauthBody === false || $oauthStatus < 200 || $oauthStatus >= 300 || empty($oauthData['access_token'])) {
    error_log('M-PESA OAuth failed (' . $oauthStatus . '): ' . $oauthError . ' ' . (string)$oauthBody);
    aiMpesaResponse(502, 'Could not authenticate with Safaricom M-PESA. Check the Daraja credentials and environment.');
}

$timestamp = date('YmdHis');
$password = base64_encode($shortcode . $passkey . $timestamp);
$accountReference = 'AI' . strtoupper(bin2hex(random_bytes(4)));
$description = 'AI wallet top-up';
$callback = $callbackUrl . (strpos($callbackUrl, '?') === false ? '?' : '&') . 'token=' . rawurlencode($callbackToken);

$stmt = $conn->prepare("INSERT INTO mpesa_payments (customer_id, phone_number, amount, account_reference, payment_status) VALUES (?, ?, ?, ?, 'PENDING')");
if (!$stmt) aiMpesaResponse(500, 'Unable to create the M-PESA payment record. Run the AI billing SQL migration.');
$stmt->bind_param('ssds', $customerId, $phone, $amount, $accountReference);
if (!$stmt->execute()) {
    $stmt->close();
    aiMpesaResponse(500, 'Unable to create the M-PESA payment record.');
}
$paymentId = (int)$stmt->insert_id;
$stmt->close();

$payload = array(
    'BusinessShortCode' => $shortcode,
    'Password' => $password,
    'Timestamp' => $timestamp,
    'TransactionType' => 'CustomerPayBillOnline',
    'Amount' => (int)$amount,
    'PartyA' => $phone,
    'PartyB' => $shortcode,
    'PhoneNumber' => $phone,
    'CallBackURL' => $callback,
    'AccountReference' => $accountReference,
    'TransactionDesc' => $description
);
$stk = curl_init($baseUrl . '/mpesa/stkpush/v1/processrequest');
curl_setopt($stk, CURLOPT_POST, true);
curl_setopt($stk, CURLOPT_HTTPHEADER, array('Content-Type: application/json', 'Authorization: Bearer ' . $oauthData['access_token']));
curl_setopt($stk, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($stk, CURLOPT_RETURNTRANSFER, true);
curl_setopt($stk, CURLOPT_CONNECTTIMEOUT, 10);
curl_setopt($stk, CURLOPT_TIMEOUT, 45);
$stkBody = curl_exec($stk);
$stkStatus = (int)curl_getinfo($stk, CURLINFO_HTTP_CODE);
$stkError = curl_error($stk);
curl_close($stk);
$stkData = json_decode((string)$stkBody, true);

if ($stkBody === false || $stkStatus < 200 || $stkStatus >= 300 || empty($stkData['CheckoutRequestID'])) {
    $failure = isset($stkData['errorMessage']) ? $stkData['errorMessage'] : 'Safaricom did not accept the STK Push request.';
    $update = $conn->prepare("UPDATE mpesa_payments SET payment_status = 'FAILED', result_description = ? WHERE payment_id = ?");
    if ($update) { $update->bind_param('si', $failure, $paymentId); $update->execute(); $update->close(); }
    error_log('M-PESA STK Push failed (' . $stkStatus . '): ' . $stkError . ' ' . (string)$stkBody);
    aiMpesaResponse(502, $failure);
}

$merchantRequestId = isset($stkData['MerchantRequestID']) ? $stkData['MerchantRequestID'] : '';
$checkoutRequestId = $stkData['CheckoutRequestID'];
$update = $conn->prepare("UPDATE mpesa_payments SET merchant_request_id = ?, checkout_request_id = ?, result_description = ? WHERE payment_id = ?");
if (!$update) aiMpesaResponse(500, 'STK Push was sent, but the checkout reference could not be saved. Do not retry until the payment status is checked.');
$resultDescription = isset($stkData['CustomerMessage']) ? $stkData['CustomerMessage'] : 'STK Push sent; awaiting customer confirmation.';
$update->bind_param('sssi', $merchantRequestId, $checkoutRequestId, $resultDescription, $paymentId);
if (!$update->execute()) {
    $update->close();
    error_log('Could not save M-PESA checkout identifiers for payment ' . $paymentId);
    aiMpesaResponse(500, 'STK Push was sent, but the checkout reference could not be saved. Do not retry until the payment status is checked.');
}
$update->close();

echo json_encode(array(
    'success' => true,
    'message' => 'STK Push sent to ' . $phone . '. Ask the customer to enter their M-PESA PIN. The wallet is credited only after Safaricom confirms payment.',
    'payment_id' => $paymentId,
    'account_reference' => $accountReference
));
