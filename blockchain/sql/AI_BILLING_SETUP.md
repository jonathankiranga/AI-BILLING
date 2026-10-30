# AI prepaid wallet billing — deployment notes

## 1. Install the database objects

Run `blockchain/sql/ai_prepaid_wallet_billing.sql` once against the same MySQL database used by LIMS.

It creates:

- `ai_wallets`: one prepaid KES balance per ERP customer account code.
- `ai_wallet_transactions`: immutable credit/debit/refund ledger; each reference is unique.
- `ai_service_pricing`: customer selling price and administrator-maintained provider token-cost estimates.
- `ai_usage_logs`: sample, customer charge, provider-cost estimate, token counts and gross margin.
- `mpesa_payments`: STK Push requests and callback reconciliation details.

The wallet key is `sample_header.CustomerID`. This is the ERP customer account code already saved on sample registration. The implementation does not read or join the obsolete local `debtors` table.

The migration adds **AI Billing & Wallets** beneath the existing Admin menu and grants it to role ID 1.

## 2. Configure AI service pricing

Open **Admin → AI Billing & Wallets**.

For **AI Interpretation of Analysis Results**, configure:

- Selling price (KES): what the customer pays for one successful AI interpretation.
- Provider input cost / 1M tokens (KES): your estimated OpenAI input-token cost converted to KES.
- Provider output cost / 1M tokens (KES): your estimated OpenAI output-token cost converted to KES.
- Active: enable only after entering a positive selling price and the cost estimates.

The migration deliberately seeds the service at KES 0 and inactive. This prevents accidental free or unpriced operations. An interpretation request is rejected until the service is priced and active.

The gross margin shown is **estimated** from the token rates configured by the administrator. It is not a substitute for reconciling OpenAI invoices, exchange-rate changes, taxes, or payment-provider fees.

## 3. Configure M-PESA Daraja on the PHP server

Set these environment variables in the PHP hosting environment (not in Git, SQL, or JavaScript):

- `MPESA_ENVIRONMENT`: `sandbox` for testing or `production` for live collections.
- `MPESA_CONSUMER_KEY`
- `MPESA_CONSUMER_SECRET`
- `MPESA_SHORTCODE`
- `MPESA_PASSKEY`
- `MPESA_CALLBACK_URL`: public HTTPS base URL for `blockchain/ajax/ai_wallet_mpesa_callback.php`.
- `MPESA_CALLBACK_TOKEN`: a randomly generated secret of at least 32 characters.

Example callback base URL (replace with the actual deployed host):

`https://your-domain.example/blockchain/ajax/ai_wallet_mpesa_callback.php`

The STK endpoint appends the callback token as a query parameter. Configure the exact public callback URL in Safaricom Daraja where required by your app/account. The callback must be reachable from Safaricom over HTTPS.

The administrator can select a customer, enter the customer's Kenyan M-PESA phone number and a whole KES amount, then send an STK Push. The wallet is **not** credited when the browser receives the STK initiation response. It is credited only after the server callback matches the saved checkout request, expected amount, phone (when supplied by Safaricom), and receipt number. Duplicate callbacks and duplicate receipt references do not credit twice.

## 4. How AI billing works

1. The AI interpretation endpoint loads the sample registration and its `CustomerID`.
2. It checks the active selling price and locks the customer's wallet row.
3. It rejects suspended wallets or insufficient balances.
4. It deducts the fixed selling price in a database transaction and writes a unique ledger reference.
5. It calls OpenAI.
6. If the provider call fails or no usable draft is returned, the endpoint refunds the reservation and records the refund in the wallet ledger.
7. On success, it records token usage, estimated provider cost and gross margin. The Quill editor receives the draft and the user is told the amount charged.
8. The draft is not automatically saved or approved.

## 5. Testing sequence

1. Use Daraja sandbox credentials and a publicly reachable HTTPS callback URL.
2. Run the SQL migration.
3. Configure a positive test selling price and provider token-cost estimates.
4. Use the admin manual-credit form to issue a clearly identified test credit to a known ERP customer account.
5. Generate an AI interpretation for a sample belonging to that same customer account.
6. Confirm the wallet balance decreased by exactly the configured selling price, and that one debit ledger row and one successful usage row exist.
7. Test insufficient funds: the AI call must be blocked before contacting OpenAI.
8. Test an OpenAI failure: the reservation must be refunded and the balance restored.
9. Send an M-PESA sandbox STK request; confirm no wallet credit occurs at initiation, then confirm the callback credits once.
10. Replay the same callback and verify that no second credit is created.

## 6. Important deployment controls

- Back up the database before applying the migration.
- Configure the OpenAI key and `AI_API_ENCRYPTION_KEY` using the existing AI API settings mechanism.
- Keep the billing administration page restricted to administrators.
- Do not use manual credits for live customer payments; they are for controlled testing and administrator adjustments only.
- Reconcile M-PESA receipts and OpenAI invoices against the transaction and usage ledgers.
- Do not deploy the M-PESA endpoints to production until Daraja credentials, callback reachability, and sandbox tests have been completed.
