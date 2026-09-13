# ORVELL PULSE — Production System Architecture & Technical Specification

## 1. Architectural Overview & Domain Invariants

ORVELL PULSE is a multi-company wholesale importer operating platform built with Laravel 12. The system enforces strict physical inventory traceability, transactional financial integrity, server-side decimal math, immutable invoices, multi-company tenancy, centralized audit logging, and omni-channel WhatsApp/API sales pipelines.

### Core Domain Principles
1. **Authoritative Physical Inventory Source of Truth**:
   - `Bale` and `InventoryTransaction` represent the sole authoritative physical inventory records.
   - `products.stock` (`StockManagement.stock`) is strictly a derived catalogue aggregate.
2. **Physical vs Commercial Orthogonality**:
   - Creating/finalizing a sale reserves physical bales (`status = 'reserved'`).
   - Paying an invoice settles the financial balance without releasing physical goods.
   - Physical release occurs **only** when a valid `#PKP-XXXX` pickup code is presented and validated at the warehouse (`status = 'released'`).
3. **Invoice Immutability**:
   - Finalized invoices are completely immutable.
   - Changes are handled via formal `InvoiceAmendmentRequest` workflows resulting in versioned invoices (`#INV-XXXX-A`) and physical bale re-allocation.
4. **Multi-Company Tenancy**:
   - All models, services, transactions, and queries are strictly scoped by `company_id`.
5. **Pessimistic Concurrency & Idempotency**:
   - Exclusive row locks (`lockForUpdate()`) and `DB::transaction()` prevent double-reservations, race conditions, overpayments, and duplicate releases.
6. **Centralized Immutable Audit Trail**:
   - All domain operations are logged to `audit_logs` via `AuditService`.

---

## 2. Inventory & Traceability Engine

```
[Container Arrival] ──▶ ContainerService::processContainerArrival()
                                 │
                                 ▼
                     Create Individual Physical Bales
                      • unique bale_code (BAL-XXXX)
                      • status = 'available'
                      • append to inventory_transactions (container_arrival)
                                 │
                                 ▼
[Sale Creation]     ──▶ SaleService::createSale()
                                 │
                                 ▼
                     InventoryService::reserveBales()
                      • status = 'reserved'
                      • lockForUpdate() exclusive locking
                      • append to inventory_transactions (sale_reservation)
                      • generate Order (#ORD-XXXX) & Pickup Code (#PKP-XXXX)
                                 │
                                 ▼
[Cashier Payment]   ──▶ PaymentService::recordCashPayment()
                      • invoice payment_status = 'paid'
                      • order payment_status = 'paid'
                      • bales remain 'reserved' (not released!)
                                 │
                                 ▼
[Warehouse Pickup]  ──▶ PickupService::releaseOrderBales()
                      • server validate #PKP-XXXX
                      • status = 'released'
                      • append to inventory_transactions (pickup_release)
                      • create Release record (#REL-XXXX)
                      • order pickup_status = 'completed'
```

---

## 3. End-of-Day (EOD) Reconciliation Engine

`EodReconciliationService` computes exact aggregations directly from immutable database records:

$$\text{Total Sales} = \sum \text{invoices.total\_amount (finalized)}$$
$$\text{Total Cash Collected} = \sum \text{payments.amount (cash verified)}$$
$$\text{Total Bank Deposits} = \sum \text{bank\_deposits.amount}$$
$$\text{Total Expenses} = \sum \text{expenses.amount}$$
$$\text{Net Cash in Drawer} = \text{Total Cash Collected} - \text{Total Bank Deposits} - \text{Total Expenses}$$

Physical stock counts are reconciled against the ledger and stored in `daily_reconciliations` and `daily_snapshots`.

---

## 4. API Endpoints Reference

### Authentication & OTP
- `POST /api/otp/send` — Request 6-digit email OTP (Native SMTP)
- `POST /api/otp/verify` — Verify OTP (Single-use, bcrypt hashed, 10m expiry)
- `POST /api/auth/client-signup` — New client registration with OTP
- `POST /api/auth/login` — Sanctum bearer token login
- `POST /api/auth/logout` — Revoke active token

### Inventory & Bales
- `POST /api/containers/create` — Register new shipping container
- `POST /api/containers/{id}/process-arrival` — Unpack container manifest into physical Bales
- `GET /api/bales/breakdown` — Category stock breakdown (available, reserved, released)

### Orders & Sales
- `POST /api/orders/create` — Create sale, reserve bales, finalize invoice, issue pickup code
- `GET /api/orders/list` — List company orders with status filters
- `GET /api/orders/show/{uuid}` — Get order details

### Payments & Cashier
- `POST /api/payment/cash` — Record cash payment at cashier counter (Cashier/Admin RBAC)
- `POST /api/payment/verify` — Server-side Paystack online payment verification
- `POST /api/webhooks/paystack` — HMAC-SHA512 Paystack webhook receiver

### Invoices & Amendments
- `POST /api/invoices/amendment-request` — Request formal invoice amendment
- `POST /api/invoices/amendment/{id}/approve` — Admin approves amendment & swaps bales
- `POST /api/invoices/amendment/{id}/reject` — Admin rejects amendment

### Pickup & Physical Release
- `POST /api/pickup/validate` — Validate pickup code and preview reserved goods
- `POST /api/pickup/release` — Concurrency-safe physical release of goods

### Expenses & Bank Deposits
- `POST /api/expenses/create` — Record operational warehouse expense
- `GET /api/expenses/summary` — Aggregate expense summary by category
- `POST /api/bank-deposits/create` — Cashier records bank deposit
- `GET /api/bank-deposits/summary` — Bank deposit period summary

### End-of-Day Reconciliation
- `POST /api/reconciliation/eod/generate` — Generate daily EOD financial & stock report
- `GET /api/reconciliation/eod/show` — Retrieve stored EOD reconciliation report

### WhatsApp & Chatterly Automation
- `POST /api/whatsapp/webhook` — Inbound test/direct webhook
- `POST /api/whatsapp/chatterly` — Real Chatterly gateway webhook with instance resolution and deduplication
