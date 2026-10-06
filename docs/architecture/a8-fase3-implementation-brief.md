# A8 Fase 3 — Implementation Brief: Native Dolibarr Commercial + Stripe Flow

**Branch:** `boundary/fase3-checkout-payment`  
**Based on:** A8 Fase 3b Stripe 50300 capability audit  
**Status:** Implementation brief — no production implementation in this commit  
**Decision:** Native Stripe 50300 + native Dolibarr commercial/payment objects are authoritative.

---

## 1. Objective

Replace the current TMS-owned Stripe payment path with a thin checkout orchestration flow.

TMS remains responsible for:

- session selection
- seat holds
- participant/enrollment orchestration
- TMS price snapshot
- checkout correlation
- payment allocation to enrollment
- enrollment confirmation

Dolibarr remains responsible for:

- commercial document
- invoice/order state
- Stripe provider integration
- payment verification
- native `llx_paiement`
- paid status
- accounting/bank consequences

No replacement TMS payment ledger is allowed.

---

## 2. Current flow to remove

The current implementation in `TrainingCheckoutService` performs:

```
seat hold
  -> checkout_session
  -> Stripe PaymentIntent
  -> checkout_payment
  -> Stripe webhook
  -> TMS confirmation
  -> TMS reconciliation
```

The current `checkout.php` also:

- loads `TrainingStripeAdapter`
- reads `TRAINING_STRIPE_CONFIG`
- loads Stripe.js
- calls `stripe.confirmCardPayment()`
- redirects to TMS success/failure pages.

This is the shadow payment architecture and must be rewired.

---

## 3. Target flow

```
TMS checkout
  |
  | validate session + participants
  | create seat hold
  | freeze price
  v
TMS checkout_session
  |
  | create native commercial document
  v
Dolibarr Order / Invoice
  |
  | getOnlinePaymentUrl()
  v
Dolibarr public/payment/newpayment.php
  |
  | native Stripe 50300
  v
Stripe
  |
  | native verification / paymentok
  v
Dolibarr Paiement
  |
  | locate native payment
  v
TMS payment_allocation
  |
  v
TMS enrollment confirmation
```

There is no TMS call to Stripe.

---

## 4. Commercial document decision

### Preferred Fase 3 path: Invoice-first

Use a native Dolibarr customer invoice as the payment object when the checkout represents an immediately payable training purchase.

Reason:

- Stripe payment is directly against an invoice.
- native `Paiement` points naturally to the invoice.
- TMS already has `TrainingInvoiceAdapter` for read/reference.
- payment allocation can reference the native `Paiement`.
- invoice/payment/accounting remain one Dolibarr chain.

### Order path

Do not introduce Order-first merely because public Stripe also supports orders.

Order-first is valid only if the existing business process requires a commercial order before invoicing.

If an Order is used, the native Dolibarr payment path can create the invoice from the order and then create the native payment. This is more coupled and must be covered separately.

**Implementation rule:** Do not support both paths in the first implementation unless an existing TMS business requirement proves both are needed.

---

## 5. New adapter boundary

The existing `TrainingInvoiceAdapter` is read-only. It must not be turned into an accounting service.

Create a narrowly scoped native-commercial writer adapter, e.g.:

`TrainingCommercialCheckoutAdapter`

Responsibilities:

1. validate entity/customer access
2. create native Dolibarr Invoice using Dolibarr PHP classes/API
3. add the correct native Product line
4. apply the frozen checkout price only where allowed by the native commercial model
5. validate the invoice
6. return:
   - entity
   - invoice ID
   - invoice ref
   - total TTC
   - currency
   - payment URL
7. locate a native `Paiement` for that invoice
8. expose the payment as a read-only native object to TMS

It must **not**:

- call Stripe
- store Stripe credentials
- write `llx_paiement` directly with SQL
- calculate accounting state
- maintain payment status
- replace Dolibarr `Facture` or `Paiement`.

All native writes must go through Dolibarr classes/API.

---

## 6. Checkout session target state

`llx_training_checkout_session` remains as orchestration/correlation state.

Target semantic fields:

- entity
- TMS session
- seat hold
- checkout reference
- status
- price snapshot/currency
- native commercial object type
- native commercial object ID/ref
- expiry
- participant correlation

The following are legacy payment-engine fields and must be removed from active semantics:

- `stripe_payment_intent_id`
- `stripe_customer_id`

Do not drop them immediately if historical rows exist.

Migration strategy:

1. stop writing them
2. stop reading them
3. preserve existing rows
4. add explicit compatibility/migration note
5. remove columns only in the later cleanup phase after historical-data policy is agreed.

---

## 7. Checkout participant target state

`llx_training_checkout_participant` remains temporary correlation data.

It must not become a second enrollment master.

Target:

```
checkout participant
       |
       +--> native contact
       |
       +--> TMS enrollment
```

On successful checkout, the TMS enrollment ID should be stored.

No parallel person/contact master is allowed.

---

## 8. checkout_payment

### Decision: DEPRECATE

Do not create new rows.

Do not use it to determine:

- paid
- payment amount
- payment status
- payment provider truth.

During migration:

- retain schema
- retain historical rows
- remove active callers
- remove active reads
- document it as legacy
- later remove in cleanup phase after migration policy.

No replacement `training_payment` table may be introduced.

---

## 9. webhook_event

### Decision: NARROW/DEPRECATE

The current table exists for TMS-owned Stripe webhooks.

After rewiring:

- TMS must not accept Stripe payment webhooks as the payment authority.
- TMS must not update enrollment from `payment_intent.succeeded`.
- TMS must not create `checkout_payment` from Stripe events.

If the table has no non-payment TMS purpose after rewiring, stop writing it and mark it legacy.

---

## 10. reconciliation

### Decision: DEPRECATE as payment ledger

The current reconciliation table stores:

- provider
- provider ref
- amount
- currency
- checkout payment
- enrollment
- status

That is too close to a second payment ledger.

After rewiring:

- no Stripe reconciliation job in TMS
- no provider amount as TMS truth
- no provider status as TMS truth.

If operational diagnostics are later required, they must reference native Dolibarr payment identity and never become accounting truth.

---

## 11. TrainingCheckoutService target responsibilities

Keep:

### Seat management

- `createSeatHold()`
- `releaseSeatHold()`

### Checkout orchestration

- validate participants
- validate seat hold
- calculate/obtain checkout price
- create checkout correlation
- create native commercial document
- generate native Dolibarr payment URL
- return redirect information

### Completion

A native-payment completion operation must:

1. load checkout session in correct entity
2. locate native commercial object
3. locate native `Paiement`
4. verify payment belongs to the expected commercial object/entity
5. verify payment is eligible for the checkout amount
6. create idempotent `payment_allocation`
7. confirm enrollment
8. mark checkout session completed
9. release/consume seat hold according to existing enrollment semantics
10. enqueue notifications only after durable success.

It must not call Stripe.

---

## 12. Critical completion rule

Do not use browser return alone as proof of payment.

The success path must be:

```
return from Dolibarr
       |
       v
load native Paiement
       |
       +-- not found -> pending / retryable
       |
       +-- found -> validate native payment
                       |
                       v
                 allocate + confirm
```

This avoids:

```
browser says success
       !=
money exists
```

A return without a native `Paiement` must never confirm enrollment.

---

## 13. Idempotency

TMS allocation must be idempotent.

Required uniqueness semantics:

```
(entity, fk_paiement, fk_enrollment)
```

Before creating an allocation:

1. lock/load native payment
2. verify entity
3. verify enrollment entity/session
4. check existing allocation
5. if already allocated, return existing allocation
6. otherwise create allocation + snapshot atomically.

Repeated return to checkout success must therefore be harmless.

---

## 14. Amount validation

The authoritative payment amount is native Dolibarr `Paiement`.

TMS checkout price remains a historical/contractual snapshot.

Completion must compare:

- checkout expected amount
- native commercial object total
- native payment amount
- allocation amount.

No Stripe amount may be trusted as the final TMS payment amount.

For multiple participants:

```
native payment total
      >=
sum(TMS allocations)
```

The allocation service remains responsible for preventing over-allocation.

---

## 15. Entity isolation

Every checkout operation must preserve:

`checkout entity = commercial entity = payment entity = enrollment entity`

The native public payment URL must explicitly preserve the entity parameter where required:

`e=<entity>`

Tests must include identical Order/Invoice references in two entities.

A payment from entity A must never be allocatable to an enrollment in entity B.

---

## 16. checkout.php rewrite

Remove:

- `TrainingStripeAdapter`
- `TRAINING_STRIPE_CONFIG`
- Stripe publishable key handling
- Stripe.js
- Stripe Elements
- `stripe.confirmCardPayment()`
- TMS Stripe success/failure as payment authority.

Replace payment page with:

1. checkout summary
2. native Dolibarr payment URL
3. redirect to native payment page.

After return, use a TMS completion endpoint/page that verifies native `Paiement`.

The TMS page may display "payment pending" when the native payment has not yet been recorded.

---

## 17. Contact/customer boundary

The current `checkout.php` contains `findOrCreateContact()`.

It currently attempts to search globally across active entities and returns `0` if no contact is found.

This is not acceptable as-is for the new flow.

Before implementation:

- customer/third-party must be resolved in the correct entity
- contact must be resolved in the correct entity
- no cross-entity contact lookup
- creation must use native Dolibarr classes if creation is required
- TMS must not create a parallel customer/contact master.

This is a required Fase 3 implementation fix because the native commercial document requires a valid Dolibarr commercial party.

---

## 18. Price ownership

TMS continues to own the training price snapshot.

However, the native commercial document is the commercial representation.

Implementation must explicitly decide whether the invoice line:

- uses the native Product's current price, or
- receives the TMS frozen checkout price.

For checkout correctness, the preferred rule is:

> TMS freezes the price at checkout creation; the native invoice line records that agreed transaction price.

The native Product remains the catalog/product master.

No duplicate TMS Product master may be created.

---

## 19. Enrollment semantics

Do not change:

- capacity calculation
- seat hold locking
- enrollment status model
- attendance
- learner identity
- session/slot ownership.

Payment completion only changes the point at which an enrollment is confirmed.

The existing enrollment service remains the domain owner.

---

## 20. Tests required before push

### Native commercial

1. Create invoice for correct entity.
2. Invoice contains correct native Product.
3. Invoice amount equals checkout price snapshot.
4. Invoice validation succeeds.
5. Native payment URL contains correct ref and entity.

### Payment

6. Native Stripe payment creates `llx_paiement`.
7. TMS can locate the native payment.
8. `ext_payment_id` is reference-only.
9. No TMS payment ledger row is created.
10. Repeated completion does not duplicate allocation.

### Failure

11. Cancelled payment does not confirm enrollment.
12. Failed payment does not confirm enrollment.
13. Return without native payment leaves checkout pending.
14. Expired seat hold cannot complete checkout.

### Allocation

15. Native payment allocates correctly to one enrollment.
16. One payment can be allocated to multiple enrollments where supported.
17. Allocation cannot exceed payment amount.
18. Allocation cannot exceed enrollment remaining balance.
19. Existing admin-created native payment can still be allocated independently of checkout.

### Entity

20. Same invoice/order ref in two entities cannot cross-allocate.
21. Checkout URL carries the correct entity.
22. Native payment lookup is entity constrained.

### Architecture

23. No TMS source code calls Stripe API.
24. No TMS Stripe credentials are read.
25. No SQL write to `llx_paiement`.
26. No new payment ledger.
27. Legacy checkout payment tables have no active writers.

---

## 21. Release artifact

Because this phase changes PHP module source under:

`htdocs/custom/training`

the deterministic release artifact must be rebuilt:

`dist/module_training-0.8.0.zip`

before commit/push.

The ZIP must match the repository source exactly.

---

## 22. Implementation order

### Step 1 — native commercial adapter

Implement only native Invoice creation/read/payment URL capability.

No checkout rewrite yet.

### Step 2 — tests for native invoice/payment correlation

Prove entity, amount, product and URL behavior.

### Step 3 — checkout session schema compatibility

Stop writing Stripe fields without deleting historical columns.

Add native commercial correlation fields if required.

### Step 4 — rewrite checkout UI

Replace Stripe Elements with native Dolibarr payment redirect.

### Step 5 — native payment completion

Locate native `Paiement`, allocate, confirm enrollment.

### Step 6 — remove active Stripe paths

Remove active use of:

- `TrainingStripeAdapter`
- Stripe webhook processing
- checkout_payment creation
- reconciliation writes.

### Step 7 — legacy cleanup

Only after all callers are gone:

- document deprecated tables
- preserve historical data
- defer physical DROP to cleanup/migration phase.

### Step 8 — artifact + full CI

Run local tests, deterministic ZIP validation, then push.

CI is verification, not debugging.

---

## 23. Explicit non-goals

This Fase 3 implementation must not:

- modify native_link
- modify ActionComm
- modify Project mapping
- modify trainer architecture
- modify course/session/enrollment core semantics
- add a new accounting engine
- add a new payment ledger
- implement Stripe webhooks in TMS
- add Stripe credentials to TMS
- bump module version
- physically drop legacy checkout tables
- refactor unrelated services.

---

## 24. Definition of Done

Fase 3 is complete only when:

- [ ] native Dolibarr commercial object is created correctly
- [ ] native Stripe 50300 handles payment
- [ ] native `llx_paiement` is the payment truth
- [ ] TMS only allocates native payments
- [ ] enrollment confirmation requires native payment
- [ ] repeated completion is idempotent
- [ ] failed/cancelled/expired checkout does not confirm
- [ ] multi-entity isolation is proven
- [ ] no TMS Stripe API calls remain
- [ ] no TMS shadow payment ledger is active
- [ ] legacy tables remain migration-safe
- [ ] existing payment/order/billing functionality remains green
- [ ] deterministic distribution ZIP rebuilt
- [ ] full local test suite passes
- [ ] CI passes.

**Gate:** No merge before every item above is evidenced by tests or explicit repository inspection.
