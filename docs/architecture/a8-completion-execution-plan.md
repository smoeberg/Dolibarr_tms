# A8 completion plan — test first, then lock

**Status:** Proposal for review; not a ratified architecture decision.  
**Baseline:** `main` after PR #24 merge, expected merge commit `7ac74a47b19e3f90ca4e0397a0434df164916826`. Re-check the branch head before implementation.  
**Operating principle:** CI verifies a finished branch; CI must not finish the branch. Do not start broad refactoring.

## Purpose

Close the remaining TMS implementation gaps by proving current behaviour against the A8 contracts, resolving the small number of behaviour-defining decisions before writing tests, and keeping CI evidence separate from target-environment evidence.

Time-box the initial baseline to **one short pass**: inspect the current branch, A8 documents, relevant CI failures/history, and open issues #3–#5. Do not attempt to create a new exhaustive specification. Add newly discovered requirements to the test/release checklist as they emerge.

## Gate 0 — ratify the behaviour-defining A8 rules before end-to-end implementation tests

The following rules must be explicitly accepted or changed before Gate 1. This is a decision list, not a claim that the rules have already been ratified.

1. **Payment is not allocation identity.** Allocation identity is derived from stable checkout/SeatHold/commercial correlation identity. A payment observation is evidence for settlement, not a component of the allocation's identity. Duplicate or later payment observations must not create a second allocation.
2. **Pending Enrollment is persisted.** It is a durable state/record with explicit transitions and recovery, not an in-memory or merely conceptual step between allocation and confirmed enrollment.
3. **SeatHold conversion is atomic and terminal states stay terminal.** The SeatHold-to-Allocation/Pending Enrollment transition must be transaction-safe. `CONSUMED` and `RELEASED` cannot be reactivated; session capacity is a separate concern from the individual checkout aggregate.
4. **Queue identity is unique.** A checkout/entity/native invoice correlation may have at most one logical queue item. Trigger delivery, reconciliation, retries and multiple workers must converge on the same item.
5. **Capacity is protected under concurrency.** With one remaining place, two simultaneous `reserve()` calls must not both succeed. This is a normative claim and an explicit race test, not a generic “concurrency tested” checkbox.
6. **Worker authority is least-privilege.** The configured `TRAINING_NATIVE_PAYMENT_WORKFLOW_USER_ID` must identify an active internal Dolibarr user with only the rights required by the finalizer. Missing, external, inactive or insufficiently privileged users fail closed. This authorization check belongs to the payment-chain contract and CI/native-runtime tests.
7. **Payment settlement and late-payment rule — previously established contract, verify against current ARCH text.** A partial payment below the frozen checkout obligation leaves the checkout in `PAYMENT_REDIRECTED`; it does not trigger allocation, enrollment confirmation, or SeatHold consumption. Sufficient cumulative native payment is required. If the SeatHold is released/expires before sufficient payment is observed, the checkout must persist/query `RECONCILIATION_REQUIRED`; it must never auto-allocate or reactivate the released hold. Payment sufficiency and SeatHold validity are independent facts. The explicit recovery outcome (for example refund, manual reconciliation, new reservation, or controlled reallocation) remains an ARCH-02 decision and must be ratified before implementation. Overpayment must not increase the frozen enrollment price or create extra allocation; the allocation is capped at the frozen obligation, with excess handled only through Dolibarr's native accounting/credit process. Do not reopen the settled partial-payment/late-payment rule as a new decision unless the current ARCH documents contradict it.
8. **Deactivation/reactivation preserves data but revalidates authority.** Module deactivation must not delete checkout, queue, allocation, enrollment or audit records. On reactivation, queued work remains recoverable and the worker must re-check configured user status/rights; do not assume permissions from a previous activation remain valid.

Record each unresolved decision and any exception in the relevant ARCH document before calling the corresponding test contract locked. For rules already agreed in prior A8 work, verify the current ARCH document and implementation against that agreement; do not relabel an existing decision as undecided.

## Gate 1 — CI/in-process and native-runtime proof

This gate tests application/database behaviour in the disposable CI/native Dolibarr environment. It does **not** claim to verify real cron operation on the target server.

### Payment and checkout scenarios

- Native invoice and payment are authoritative; no TMS-owned Stripe payment ledger is used.
- Partial payment: invoice remains unpaid; no final enrollment; no false `BILL_PAYED` assumption.
- Full payment after one or more partial payments: exactly one queue item and exactly one finalization.
- Overpayment: enrollment amount/allocation stays capped at frozen obligation; no duplicate allocation.
- Duplicate trigger, reconciliation after missed trigger, duplicate worker run and retry after failure are idempotent.
- Paid invoice with no trigger-created queue row is recovered by reconciliation.
- Partial payment leaves checkout `PAYMENT_REDIRECTED`; no allocation, enrollment confirmation or SeatHold consumption before sufficient cumulative native payment.
- If the hold is released before sufficient payment, persist `RECONCILIATION_REQUIRED`; assert that late payment cannot auto-allocate or reactivate the hold. Test the separately ratified recovery path.
- Two simultaneous reservations competing for the last place yield exactly one successful reservation.
- Two workers attempting the same queue row do not both own/process it; expired leases recover, live leases are not stolen, and retry exhaustion becomes visible.
- Queue uniqueness is enforced by a database constraint/atomic operation, not only by a pre-check.
- Missing/inactive/external workflow user and missing required permissions fail closed; a correctly configured least-privilege user can process a valid item.
- Deactivation/reactivation preserves durable rows; reactivation and the worker's next run handle pending/expired-lease items without duplicate enrollment.

### Evidence required

- Run the existing PHP unit/integration suite and native Dolibarr runtime tests.
- Run SQL/module activation tests on the supported DB engine and SQL mode.
- Run HTTP/permission/CSRF/entity tests.
- Verify deterministic archive equality independently; CI must not write or commit the archive.
- Link exact CI run and commit SHA in the PR. Any failing test is fixed in the branch before CI is treated as a gate.

## Gate 2 — target-environment driven worker proof

This is a separate release gate and must not be conflated with Gate 1. Execute against a disposable copy/staging installation matching the target Dolibarr/PHP/database versions.

- Configure the dedicated internal workflow user and prove its effective rights through the actual CLI bootstrap.
- Install the real cron/scheduler entry, run it as the intended OS user, and verify environment/configuration loading and exit codes.
- Exercise a real pending queue item through the scheduled worker, including logs and the resulting invoice/checkout/enrollment state.
- Run two real worker processes concurrently against the same queue and observe claim/lease behaviour.
- Kill a worker during processing; verify lease expiry, recovery and idempotent replay.
- Verify retry exhaustion, visible failed state, operator diagnosis and documented deliberate recovery.
- Test module deactivate/reactivate while queue work exists; prove records survive, authority is revalidated, and the next scheduled run recovers safely.
- Record DB engine/version, SQL mode, storage engine, PHP version, Dolibarr version, cron command/user, workflow user rights and evidence. No production enablement based only on CI.

## Gate 3 — settle and document the Stripe boundary

**Proposed decision:** Remove/freeze any TMS-owned Stripe provider, PaymentIntent, webhook-ledger or Stripe reconciliation code. Stripe, if enabled, is only a payment method owned by Dolibarr's official Stripe module; TMS consumes native Dolibarr invoice/payment evidence and never treats a Stripe API response as settlement authority.

The existing Stripe capability matrix documents an important limitation: the native public payment success path depends on the Dolibarr return flow, so a successful provider payment that never returns may not promptly produce a native `Paiement` visible to TMS. Do not describe this as universal webhook coverage. Before enabling native Stripe for TMS checkout, explicitly choose and test a supported recovery path for that return-flow gap. Until then, native non-Stripe Dolibarr payment is the supported baseline; Stripe remains disabled for TMS checkout or explicitly marked experimental. Update the capability matrix and architecture brief to match the ratified decision; do not leave an ambiguous “backup” provider.

## Gate 4 — close A8 docs against implementation

Review ARCH-00 through ARCH-03 after Gate 0 decisions and Gate 1 evidence. Resolve discrepancies in these areas: SeatHold terminal states and expiry; allocation identity; persisted Pending Enrollment; capacity locking; payment observation vs. payment authority; queue uniqueness; worker retry/recovery; and the payment-after-expiry policy. A document is not accepted merely because it exists or has been merged: normative rules must match code and tests.

## Gate 5 — remaining workflows and release validation

Keep unrelated UI/security follow-ups in narrow PRs:

- Issue #4: attendance time picker in the session's IANA time zone, saved/computed status, readable audit history, explicit revision-conflict handling.
- Issue #5: instructor-to-session assignment and service-layer enforcement for reads/writes/history, entity scoping and revoked access.
- Issue #3: target Dolibarr 24.0.2 GUI installation, activation, actual prior-version upgrade, deactivate/reactivate, data preservation, rights and HTTP/CSRF/entity checks.

Run target-environment installation/upgrade checks separately from CI. Keep attendance UI work and instructor assignment out of the payment-authority PR.

## Definition of done

- [ ] Gate 0 decisions are recorded in the relevant A8 documents, including explicit partial-payment, overpayment and hold-expiry behaviour.
- [ ] Last-seat concurrency test proves exactly one reservation wins.
- [ ] Checkout/invoice/payment/queue/allocation/Pending Enrollment/final enrollment are proven end-to-end in native runtime tests.
- [ ] Queue uniqueness, duplicate delivery, multiple workers, lease recovery, retry exhaustion and idempotent replay are tested.
- [ ] Workflow user is least-privilege and missing rights fail closed.
- [ ] Deactivation/reactivation preserves data and revalidates worker authority.
- [ ] Stripe ownership and the return-flow limitation have an explicit accepted decision.
- [ ] ARCH-00–ARCH-03 are consistent with implementation and tests.
- [ ] Issues #3–#5 are either completed with evidence or explicitly accepted as release blockers.
- [ ] Deterministic ZIP matches the build; CI is verification-only.
- [ ] Target-environment cron, install, upgrade and recovery evidence is recorded before production enablement.

## Execution discipline

Use a separate branch/PR per coherent change. Do not merge without explicit instruction. No broad refactor. Do not call a phase complete from code presence alone: attach test names/results, exact commit SHA, and target-environment evidence where applicable.
