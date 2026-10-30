<?php
/**
 * AI prepaid wallet operations.
 * Wallet balances are locked and updated transactionally; the ledger is authoritative.
 */

function aiBillingCreateReference($prefix)
{
    return $prefix . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(6));
}

function aiBillingReserve($conn, $customerId, $serviceCode, $sampleId, $headerId, &$reservation, &$message)
{
    $reservation = null;
    $message = '';
    $customerId = trim((string)$customerId);
    if ($customerId === '') {
        $message = 'This sample has no customer account code. AI billing cannot be applied.';
        return false;
    }

    try {
        $conn->begin_transaction();

        $priceStmt = $conn->prepare("SELECT selling_price, currency FROM ai_service_pricing WHERE service_code = ? AND active = 1 LIMIT 1 FOR UPDATE");
        if (!$priceStmt) {
            throw new Exception('AI pricing is not configured. Ask an administrator to configure AI Billing.');
        }
        $priceStmt->bind_param('s', $serviceCode);
        if (!$priceStmt->execute()) {
            throw new Exception('Unable to read the AI service price.');
        }
        $price = $priceStmt->get_result()->fetch_assoc();
        $priceStmt->close();

        if (!$price || (float)$price['selling_price'] <= 0) {
            throw new Exception('This AI service is not yet enabled for billing. Ask an administrator to set and activate its KES selling price.');
        }
        if ($price['currency'] !== 'KES') {
            throw new Exception('The AI wallet currently supports KES pricing only.');
        }

        $walletInsert = $conn->prepare("INSERT IGNORE INTO ai_wallets (customer_id, balance, currency) VALUES (?, 0.00, 'KES')");
        if (!$walletInsert) throw new Exception('Unable to initialize the customer AI wallet.');
        $walletInsert->bind_param('s', $customerId);
        if (!$walletInsert->execute()) throw new Exception('Unable to initialize the customer AI wallet.');
        $walletInsert->close();

        $walletStmt = $conn->prepare("SELECT balance, status FROM ai_wallets WHERE customer_id = ? FOR UPDATE");
        if (!$walletStmt) throw new Exception('Unable to lock the customer AI wallet.');
        $walletStmt->bind_param('s', $customerId);
        if (!$walletStmt->execute()) throw new Exception('Unable to read the customer AI wallet.');
        $wallet = $walletStmt->get_result()->fetch_assoc();
        $walletStmt->close();

        if (!$wallet || $wallet['status'] !== 'active') {
            throw new Exception('The customer AI wallet is suspended. Contact the administrator.');
        }

        $charge = round((float)$price['selling_price'], 2);
        $balance = round((float)$wallet['balance'], 2);
        if ($balance < $charge) {
            throw new Exception('Insufficient AI wallet funds. Required: KES ' . number_format($charge, 2) . '; available: KES ' . number_format($balance, 2) . '. Please top up the wallet.');
        }

        $newBalance = round($balance - $charge, 2);
        $update = $conn->prepare("UPDATE ai_wallets SET balance = ? WHERE customer_id = ?");
        if (!$update) throw new Exception('Unable to reserve AI wallet funds.');
        $update->bind_param('ds', $newBalance, $customerId);
        if (!$update->execute()) throw new Exception('Unable to reserve AI wallet funds.');
        $update->close();

        $reference = aiBillingCreateReference('AI');
        $description = 'Reserved for ' . $serviceCode . ' / Sample ' . $sampleId;
        $type = 'DEBIT';
        $ledger = $conn->prepare("INSERT INTO ai_wallet_transactions (customer_id, transaction_type, amount, balance_after, reference, description, created_by) VALUES (?, ?, ?, ?, ?, ?, 'AI Service')");
        if (!$ledger) throw new Exception('Unable to record the AI wallet transaction.');
        $ledger->bind_param('ssddss', $customerId, $type, $charge, $newBalance, $reference, $description);
        if (!$ledger->execute()) throw new Exception('Unable to record the AI wallet transaction.');
        $ledger->close();

        $conn->commit();
        $reservation = array(
            'customer_id' => $customerId,
            'service_code' => $serviceCode,
            'sample_id' => $sampleId,
            'header_id' => (int)$headerId,
            'amount' => $charge,
            'currency' => 'KES',
            'reference' => $reference
        );
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        $message = $e->getMessage();
        return false;
    }
}

function aiBillingRefund($conn, $reservation, $reason)
{
    if (!$reservation || empty($reservation['reference'])) return false;
    $conn->begin_transaction();
    try {
        $originalReference = $reservation['reference'];
        $check = $conn->prepare("SELECT transaction_id FROM ai_wallet_transactions WHERE reference = ? LIMIT 1");
        $check->bind_param('s', $originalReference);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$exists) { $conn->rollback(); return false; }

        $refundReference = 'RF-' . $originalReference;
        $check = $conn->prepare("SELECT transaction_id FROM ai_wallet_transactions WHERE reference = ? LIMIT 1");
        $check->bind_param('s', $refundReference);
        $check->execute();
        $alreadyRefunded = $check->get_result()->fetch_assoc();
        $check->close();
        if ($alreadyRefunded) { $conn->commit(); return true; }

        $customerId = $reservation['customer_id'];
        $wallet = $conn->prepare("SELECT balance FROM ai_wallets WHERE customer_id = ? FOR UPDATE");
        $wallet->bind_param('s', $customerId);
        $wallet->execute();
        $row = $wallet->get_result()->fetch_assoc();
        $wallet->close();
        if (!$row) throw new Exception('Wallet not found during refund.');

        $amount = (float)$reservation['amount'];
        $newBalance = round((float)$row['balance'] + $amount, 2);
        $update = $conn->prepare("UPDATE ai_wallets SET balance = ? WHERE customer_id = ?");
        $update->bind_param('ds', $newBalance, $customerId);
        if (!$update->execute()) throw new Exception('Could not restore wallet balance.');
        $update->close();

        $type = 'REFUND';
        $description = 'AI charge refunded: ' . $reason;
        $ledger = $conn->prepare("INSERT INTO ai_wallet_transactions (customer_id, transaction_type, amount, balance_after, reference, description, created_by) VALUES (?, ?, ?, ?, ?, ?, 'AI Service')");
        $ledger->bind_param('ssddss', $customerId, $type, $amount, $newBalance, $refundReference, $description);
        if (!$ledger->execute()) throw new Exception('Could not record wallet refund.');
        $ledger->close();
        $conn->commit();
        return true;
    } catch (Exception $e) {
        $conn->rollback();
        error_log('AI wallet refund failed: ' . $e->getMessage());
        return false;
    }
}

function aiBillingRecordUsage($conn, $reservation, $model, $inputTokens, $outputTokens)
{
    $rates = $conn->prepare("SELECT provider_input_cost_per_million, provider_output_cost_per_million FROM ai_service_pricing WHERE service_code = ? LIMIT 1");
    if (!$rates) return false;
    $serviceCode = $reservation['service_code'];
    $rates->bind_param('s', $serviceCode);
    if (!$rates->execute()) { $rates->close(); return false; }
    $rate = $rates->get_result()->fetch_assoc();
    $rates->close();
    if (!$rate) return false;

    $customerId = $reservation['customer_id'];
    $sampleId = $reservation['sample_id'];
    $headerId = (int)$reservation['header_id'];
    $inputTokens = max(0, (int)$inputTokens);
    $outputTokens = max(0, (int)$outputTokens);
    $totalTokens = $inputTokens + $outputTokens;
    $charge = (float)$reservation['amount'];
    // Rates are administrator-maintained KES estimates per one million provider tokens.
    $providerCost = (($inputTokens * (float)$rate['provider_input_cost_per_million'])
        + ($outputTokens * (float)$rate['provider_output_cost_per_million'])) / 1000000;
    $margin = $charge - $providerCost;
    $reference = $reservation['reference'];

    $stmt = $conn->prepare("INSERT INTO ai_usage_logs (customer_id, sample_id, header_id, service_code, provider, model, input_tokens, output_tokens, total_tokens, customer_charge, provider_cost_kes, gross_margin_kes, currency, reference, status) VALUES (?, ?, ?, ?, 'openai', ?, ?, ?, ?, ?, ?, ?, 'KES', ?, 'SUCCESS')");
    if (!$stmt) return false;
    $stmt->bind_param('ssissiiiddds', $customerId, $sampleId, $headerId, $serviceCode, $model, $inputTokens, $outputTokens, $totalTokens, $charge, $providerCost, $margin, $reference);
    $ok = $stmt->execute();
    if (!$ok) error_log('AI usage logging failed: ' . $stmt->error);
    $stmt->close();
    return $ok;
}
