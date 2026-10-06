# A8 Fase 3 — Checkout / Payment Boundary Inventory

Status: inventory only. No PHP/schema implementation changes in this branch.

Branch: `boundary/fase3-checkout-payment`
Base: `main` after PR #19.

## Scope

Audit the current checkout/payment implementation against the approved A8 boundary:

> TMS owns teaching-domain orchestration, enrollment and price snapshots. Dolibarr owns commercial documents, payments and accounting. TMS may reference and allocate native commercial/payment objects but must not become a parallel ERP/ledger.

Out of scope:
- trainer identity
- native_link / Project / ActionComm
- Course / Session / Enrollment core redesign
- module version bump

## Executive finding

The current checkout implementation is **not yet a thin orchestration layer**.

It currently contains a complete Stripe payment lifecycle and a TMS-side payment record/reconciliation path:

`seat hold -> checkout_session -> Stripe PaymentIntent -> checkout_payment -> enrollment confirmation -> reconciliation`

The most important boundary violation is that `TrainingCheckoutService::confirmPayment()` treats a successful Stripe PaymentIntent as sufficient to create TMS enrollments and writes `llx_training_checkout_payment` as the payment record. There is no Dolibarr `llx_paiement` write in this flow.

Therefore the current state is best classified as **C/D shadow payment flow**, not as a harmless orchestration state machine.

The later `TrainingPaymentService` is architecturally different: it reads native Dolibarr `Paiement` objects and creates TMS allocation/snapshot rows. That part is substantially aligned with A8.

## File inventory

| # | Path | Actual role | TMS reads | TMS writes | Dolibarr reads | Dolibarr writes | Classification | Risk |
|---|---|---|---|---|---|---|---|---|
| 1 | `class/trainingcheckoutservice.class.php` | Seat hold, checkout state, Stripe PaymentIntent, webhook, payment record, enrollment confirmation, reconciliation, outbox | Session, hold, participant, price, checkout rows | checkout session/participants/payment/reconciliation, enrollment, learner, outbox | Indirect contact/session access | **None for Order/Invoice/Payment in checkout flow** | **C/D — too much payment ownership** | High |
| 2 | `class/trainingstripeadapter.class.php` | Stripe provider adapter | Stripe provider data | Creates PaymentIntent/Customer and other provider objects | None | Stripe writes | **B/D provider adapter** | Medium |
| 3 | `class/trainingpaymentservice.class.php` | Allocation of native Dolibarr payments to enrollments | Enrollment, price snapshot, native payment through adapter, allocations | payment_allocation + payment_snapshot + audit | Reads native `llx_paiement` | None | **B — correct allocation/reference layer** | Low/Medium |
| 4 | `class/trainingorderservice.class.php` | Allocation of native order lines | Enrollment + TMS mapping | order_line + order_allocation + audit | Reads native `llx_commande` / lines | None | **B — correct reference/allocation layer** | Low/Medium |
| 5 | `class/trainingbillingservice.class.php` | Allocation of native invoice lines | Enrollment + TMS mapping | billing_line + billing_allocation + audit | Reads native `llx_facture` / lines | None | **B — correct reference/allocation layer** | Low/Medium |
| 6 | `class/trainingoutboxservice.class.php` | Async notification queue | Enrollment/payment references | outbox + audit | Contact/email information | External email delivery | **B — orchestration/async** | Low |
| 7 | `checkout.php` | Public/internal checkout UI | Sessions, prices, capacity, session state | PHP session + delegates checkout writes | Product/session information | No native commercial document | **C/D — frontend of current shadow payment flow** | High |
| 8 | `webhook.php` | Stripe webhook endpoint | Stripe payload/config | Delegates to checkout webhook processing | None | No native Dolibarr payment | **B/D provider-event ingress** | High |
| 9 | `sql/llx_training_zcheckout.sql` | Checkout session/participant/payment/webhook/outbox/reconciliation schema | — | Defines parallel payment state | References TMS/native IDs | — | **C/D mixed; payment tables need narrowing** | High |
| 10 | `sql/llx_training_zpayment.sql` | Native payment allocation + immutable snapshot | — | Allocation/snapshot | References `llx_paiement` | — | **B — keep/narrow** | Low |
| 11 | `tests/payment_mysql.php` | Native payment allocation verification | — | Test data | Native payment fixtures | Test writes only | **B** | Low |
| 12 | `tests/billing_mysql.php`, `tests/order_mysql.php` | Native commercial allocation tests | — | Test mappings | Native invoice/order fixtures | Test writes only | **B** | Low |
| 13 | `docs/fakturalinjefordeling.md` | Documents invoice allocation boundary | — | — | Native invoice references | — | **B/documentation** | Low |

No dedicated checkout MySQL test file was found in `tests/`; checkout behavior is therefore a specific testing gap to inventory before implementation changes.

## Checkout state machine — actual main

Current flow:

```
session selection
  -> participant form
  -> TMS seat hold
  -> TMS checkout_session
  -> Stripe PaymentIntent
  -> Stripe webhook
  -> TMS checkout_payment
  -> TMS enrollment confirmation
  -> TMS checkout_session = confirmed
  -> TMS reconciliation = pending/completed/failed
  -> TMS outbox confirmation
```

### State ownership

| Step | TMS writes | Dolibarr writes | Current master for “paid?” | Current master for “enrolled?” |
|---|---|---|---|---|
| Session selection | none | none | none | none |
| Participant/hold | seat hold | none | none | TMS reservation |
| Checkout creation | checkout_session + participants | none | Stripe PaymentIntent pending | TMS hold |
| Stripe success | checkout_payment | none | **Stripe/TMS checkout_payment** | not yet |
| Confirmation | enrollment + participant status + checkout_session | none | **TMS checkout_payment / Stripe event** | **TMS enrollment** |
| Reconciliation | reconciliation status | none | Stripe provider lookup | TMS enrollment |

This is the central architectural issue: **Dolibarr is currently not the master for the payment created by the public checkout flow.**

## Shadow-ledger checklist

| Symptom | Result | Evidence |
|---|---|---|
| TMS stores payment amount as ongoing truth | **YES** | `checkout_payment.amount_ht/amount_ttc` and `createPaymentRecord()` |
| TMS reconciles provider state without Dolibarr payment | **YES** | `runReconciliation()` calls Stripe directly |
| TMS updates paid state without `llx_paiement` | **YES** | successful Stripe webhook creates TMS payment record and enrollments |
| Duplicate order/invoice state in TMS | **No direct order/invoice master found** | order/billing tables are mappings to native documents |
| Reconciliation is its own ledger | **Effectively YES / C-D** | reconciliation has provider ref, amount, status and payment FK |
| Webhook is provider source of truth without Dolibarr payment write | **YES** | webhook success drives confirmation directly |

## Important distinction: native payment allocation is healthy

`TrainingPaymentService` does **not** have the same problem.

It starts from a standard Dolibarr payment:

```
llx_paiement
    |
    v
TrainingPaymentService
    |
    +--> payment_allocation
    +--> payment_snapshot
```

This is the desired A8 pattern:

- Dolibarr `Paiement` remains the payment master.
- TMS records allocation to enrollment.
- Snapshot freezes relevant historical source data.
- TMS calculates enrollment-level allocation/balance from the allocation relations.
- No TMS payment master is created.

`TrainingFinancialBalanceService` explicitly documents this boundary and combines native-payment allocations with credit-note allocations.

## Q1–Q6 decision gate

### Q1 — Is checkout_session orchestration or payment master?

**Current: C/D — mixed orchestration + payment state.**

It is legitimate orchestration state for seat hold/session/participant lifecycle, but it also stores payment amount, currency, Stripe PaymentIntent ID and payment status.

Target: **NARROW to orchestration/reference state.**

### Q2 — Is checkout_payment a shadow ledger?

**YES.**

It stores amount, currency, Stripe PaymentIntent/charge IDs and payment status, and is used as the basis for enrollment confirmation and reconciliation.

Target: **DEPRECATE/REWIRE**, unless reduced to a provider-event/reference record that is explicitly not payment truth.

### Q3 — Are Order/Invoice written through Dolibarr API by checkout?

**NO in the current checkout flow.**

The native Order/Invoice services are read-only adapters plus TMS allocation mappings. Checkout itself has no native Order/Invoice creation path.

This is a sequencing/ownership gap, not something to solve by creating TMS order/invoice masters.

Target strategy: **REWIRE**, after the exact desired commercial sequence is decided.

### Q4 — Is “paid” currently determined by Dolibarr or TMS?

**TMS/provider path.**

Stripe `payment_intent.succeeded` calls `confirmPayment()`, which creates `checkout_payment(payment_status=succeeded)` and then confirms enrollment.

Dolibarr `llx_paiement` is not involved.

Target: **Dolibarr must become economic/payment master.**

### Q5 — Can enrollment confirmation run without TMS owning money?

**Architecturally YES; current public checkout flow does not.**

The existing administrative `TrainingEnrollmentService::confirm()` already represents enrollment as a TMS domain operation. The checkout implementation unnecessarily couples that confirmation to its own Stripe payment record.

Target: separate:
- payment/provider orchestration
- commercial document/payment registration
- TMS enrollment confirmation

The exact sequence must be decided before code changes.

### Q6 — Does reconciliation have legitimate temporary value?

**As currently implemented: NO as a ledger.**

A provider reconciliation job can have temporary operational value, but the current table stores amount/provider/status and reconciles the TMS payment record directly against Stripe.

Target: **NARROW/DEPRECATE** into provider-event/idempotency/operational state. It must not become accounting truth.

## Object-level decisions

| Object | Decision | Reason |
|---|---|---|
| `checkout_session` | **NARROW** | Keep orchestration state, remove payment-master semantics |
| `checkout_participant` | **NARROW** | Keep temporary checkout correlation; do not duplicate enrollment/contact identity |
| `checkout_payment` | **DEPRECATE / REWIRE** | Current shadow payment record |
| `TrainingCheckoutService` | **REWIRE** | Split orchestration from payment ownership |
| `TrainingStripeAdapter` | **NARROW / KEEP** | Provider adapter; no accounting truth |
| `webhook_event` | **KEEP / NARROW** | Provider-event idempotency/audit record |
| `outbox` | **KEEP** | Async notification orchestration |
| `reconciliation` | **NARROW / DEPRECATE** | Current implementation is too close to shadow ledger |
| `payment_allocation` | **KEEP / NARROW** | Correct TMS allocation relation to native payment |
| `payment_snapshot` | **KEEP / NARROW** | Immutable historical snapshot only |
| `billing_* ` | **KEEP / reference** | Native invoice allocation |
| `order_* ` | **KEEP / reference** | Native order allocation |
| `creditnote_* ` | **KEEP / reference** | Native credit-note allocation |

## Additional finding: current checkout does not create Dolibarr commercial documents

This is important for the next implementation brief.

The target architecture cannot simply delete Stripe/TMS payment rows. There must be an explicit commercial sequence.

Current:

```
TMS price
  -> Stripe
  -> TMS payment
  -> TMS enrollment
```

Target direction:

```
TMS seat hold / checkout
        |
        v
Dolibarr commercial document(s)
        |
        v
payment provider orchestration
        |
        v
Dolibarr payment
        |
        v
TMS enrollment + allocation/reference
```

The exact Order → Invoice → Payment sequence must be confirmed against the actual Dolibarr installation/capabilities before implementation.

## Forbidden in implementation PR

- No new payment ledger.
- No TMS invoice/order/payment master.
- No change to native_link/Session/Slot/ActionComm/Project.
- No trainer changes.
- No module version bump.
- No removal of Stripe merely because it is currently over-coupled.
- No deletion of checkout tables until all callers and historical-data migration implications are mapped.
- No change to core capacity/enrollment semantics except where required to decouple payment from enrollment confirmation.

## Test-first requirements for implementation

Before changing production code:

1. Inspect existing MySQL test harness API; do not invent helpers.
2. Add tests for the selected target state machine.
3. Prove provider webhook idempotency.
4. Prove payment truth is not derived from a TMS payment ledger.
5. Prove native Dolibarr payment references remain entity-safe.
6. Prove enrollment/capacity behavior is unchanged.
7. Prove partial/native payment allocation remains independent of checkout.
8. Prove failed/cancelled/expired checkout does not create enrollment.
9. Prove successful commercial/payment completion can be correlated back to the TMS checkout without making TMS the payment master.
10. Update `dist/module_training-0.8.0.zip` if any PHP module source changes.

## Branch contract

This branch is currently **inventory/architecture only**.

No production implementation is approved yet.

Next gate:

```
inventory
  -> confirm Dolibarr commercial/payment sequence
  -> define target checkout state machine
  -> define migration/deprecation strategy
  -> test-first implementation brief
  -> implementation
  -> local state-flow validation
  -> package validation
  -> CI
```

No PR should be opened for implementation until the decision gate is accepted.
