-- AI prepaid wallet billing foundation.
-- Run once against the LIMS database before enabling billable AI operations.
-- Customer ownership uses sample_header.CustomerID (ERP debtor/item code); no local debtors join.

CREATE TABLE IF NOT EXISTS ai_wallets (
    customer_id VARCHAR(20) NOT NULL,
    balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    status ENUM('active','suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (customer_id),
    KEY idx_ai_wallet_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_wallet_transactions (
    transaction_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id VARCHAR(20) NOT NULL,
    transaction_type ENUM('CREDIT','DEBIT','REFUND','ADJUSTMENT') NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reference VARCHAR(100) NOT NULL,
    description VARCHAR(500) DEFAULT NULL,
    created_by VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (transaction_id),
    UNIQUE KEY uq_ai_wallet_reference (reference),
    KEY idx_ai_wallet_customer_date (customer_id, created_at),
    CONSTRAINT fk_ai_wallet_transaction_customer
      FOREIGN KEY (customer_id) REFERENCES ai_wallets(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_service_pricing (
    service_code VARCHAR(64) NOT NULL,
    service_name VARCHAR(150) NOT NULL,
    selling_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    provider_input_cost_per_million DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    provider_output_cost_per_million DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    active TINYINT(1) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (service_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_usage_logs (
    usage_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id VARCHAR(20) NOT NULL,
    sample_id VARCHAR(50) DEFAULT NULL,
    header_id INT DEFAULT NULL,
    service_code VARCHAR(64) NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'openai',
    model VARCHAR(100) NOT NULL,
    input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    customer_charge DECIMAL(14,2) NOT NULL,
    provider_cost_kes DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    gross_margin_kes DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    reference VARCHAR(100) NOT NULL,
    status ENUM('SUCCESS','FAILED') NOT NULL DEFAULT 'SUCCESS',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (usage_id),
    UNIQUE KEY uq_ai_usage_reference (reference),
    KEY idx_ai_usage_customer_date (customer_id, created_at),
    KEY idx_ai_usage_service_date (service_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS mpesa_payments (
    payment_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id VARCHAR(20) NOT NULL,
    phone_number VARCHAR(20) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    account_reference VARCHAR(64) NOT NULL,
    checkout_request_id VARCHAR(100) DEFAULT NULL,
    merchant_request_id VARCHAR(100) DEFAULT NULL,
    mpesa_receipt_number VARCHAR(40) DEFAULT NULL,
    result_code INT DEFAULT NULL,
    result_description VARCHAR(500) DEFAULT NULL,
    payment_status ENUM('PENDING','PAID','FAILED','CANCELLED') NOT NULL DEFAULT 'PENDING',
    callback_payload LONGTEXT DEFAULT NULL,
    wallet_credit_reference VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_mpesa_checkout (checkout_request_id),
    UNIQUE KEY uq_mpesa_receipt (mpesa_receipt_number),
    KEY idx_mpesa_customer_date (customer_id, created_at),
    KEY idx_mpesa_status (payment_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ai_service_pricing (service_code, service_name, selling_price, currency, active)
VALUES ('RESULTS_INTERPRETATION', 'AI Interpretation of Analysis Results', 0.00, 'KES', 0)
ON DUPLICATE KEY UPDATE service_name = VALUES(service_name);

-- Add the billing administration page beneath the existing Admin menu.
INSERT INTO menu_items (title, icon, url, parent_id, security_id)
SELECT 'AI Billing & Wallets', 'fas fa-wallet', 'ai_billing.php', admin_menu.id, 0
FROM menu_items AS admin_menu
WHERE admin_menu.title = 'Admin' AND admin_menu.parent_id IS NULL
  AND NOT EXISTS (SELECT 1 FROM menu_items WHERE url = 'ai_billing.php')
ORDER BY admin_menu.id LIMIT 1;

INSERT IGNORE INTO role_security (role_id, security_id)
SELECT 1, id FROM menu_items WHERE url = 'ai_billing.php' LIMIT 1;
