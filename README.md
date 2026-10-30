# AI-BILLING

Prepaid AI wallet and usage-billing integration for the SmarterNow LIMS application.

## Contents

- `blockchain/ai_billing.php` — administrator billing dashboard, service pricing, wallet adjustments and M-PESA top-up initiation.
- `blockchain/functions/ai_wallet_billing.php` — wallet reservation, debit, refund, usage and margin accounting.
- `blockchain/ajax/ai_wallet_stk_push.php` — Safaricom Daraja STK Push initiation.
- `blockchain/ajax/ai_wallet_mpesa_callback.php` — authenticated, idempotent M-PESA callback reconciliation.
- `blockchain/ajax/generate_results_interpretation.php` — wallet-billed AI interpretation endpoint.
- `blockchain/reviewtestresults.php` — LIMS review screen integration for AI interpretation.
- `blockchain/sql/ai_prepaid_wallet_billing.sql` — database migration.
- `blockchain/sql/AI_BILLING_SETUP.md` — installation and configuration guide.

## Important integration note

This repository contains the AI-BILLING implementation extracted from the `AI-BILLING` branch of `jonathankiranga/LIMS`. It is an integration package, not a complete standalone LIMS installation. The PHP pages and AJAX endpoints rely on the host LIMS application's existing database connection, authentication/session bootstrap, configuration, AI API settings, and related sample/result tables.

Apply the SQL migration to a test database first. Configure service selling prices and provider-cost rates before enabling AI usage. Configure the Daraja credentials and callback URL before testing M-PESA. See `blockchain/sql/AI_BILLING_SETUP.md`.

The source LIMS repository and its `master` branch are not modified by this repository transfer.
