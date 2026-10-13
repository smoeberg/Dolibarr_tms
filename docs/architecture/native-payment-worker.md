# Native payment finalization worker

## Production path

The Training module listens for Dolibarr's `BILL_PAYED` trigger. It does not
finalize an enrollment from a browser return URL and does not run the TMS
finalizer inside the invoice-payment transaction. For a correlated invoice
that Dolibarr marks fully paid, the trigger inserts an idempotent row into
`llx_training_native_payment_queue` (using the configured table prefix).

The worker at
`htdocs/custom/training/scripts/process_native_payments.php` consumes that
queue under a dedicated internal Dolibarr user and calls
`TrainingCheckoutService::finalizeNativeCheckout()`. Failed work remains
persisted for retry. A worker crash leaves a processing lease that becomes
eligible for recovery after 15 minutes. The queue is unique by entity and
checkout session, so repeated trigger events do not create duplicate work.

The trigger only queues an invoice whose persisted TMS correlation identifies
it as the checkout's native invoice, and whose Dolibarr invoice row has
`paye = 1`. Partial payments do not qualify. Native payment records remain
the payment authority; the queue is only a durable signal to re-read and
finalize that checkout.

## Required configuration

Configure the global Dolibarr constant:

- `TRAINING_NATIVE_PAYMENT_WORKFLOW_USER_ID`: row ID of a dedicated, active,
  internal Dolibarr user (not a third-party/external user).

Grant only the rights needed by the checkout finalizer. At minimum, verify
that this user has:

- Service read access (`service/lire`).
- Training course read, session read, checkout read/write and billing
  read/write rights.
- Contact read access and access to the relevant customer third parties.
- Invoice read access.
- The required product/contact/user entity scopes for the entities processed.

The finalizer fails closed if the workflow user lacks a required right or
cannot access the invoice/customer/contact. Do not use an administrator
account merely to avoid configuring the necessary permissions. Record the
workflow user ID and its granted rights in the deployment runbook.

## Scheduling

Run the worker from the trusted server's CLI cron environment, as the
operating-system account used for the Dolibarr installation. For example,
invoke this script every minute:

```sh
php /absolute/path/to/dolibarr/htdocs/custom/training/scripts/process_native_payments.php
```

The worker processes up to 50 items per invocation for the active Dolibarr
entity. In multi-entity installations, configure and verify a separate
invocation/context for each entity; do not assume one entity's cron context
will process another entity's queue.

Monitor stdout/stderr and the exit code. An exit code other than zero means
one or more items failed during that run. Items stop automatic retry after
five attempts and must be reviewed and recovered deliberately; do not delete
failed queue rows as a routine fix.

## Verification and rollout

1. Configure the workflow user ID and verify its rights in a non-production
   environment.
2. Install/reactivate the module so the trigger and queue table are enabled.
3. Run the native runtime integration test and verify that a fully paid
   correlated invoice creates one pending queue row, while a partial payment
   does not.
4. Run the worker as the configured user and verify that the queue row becomes
   completed and the checkout/enrollment reaches the expected terminal state.
5. Re-run the worker and confirm the completed checkout is idempotent.
6. Verify retry and crash recovery before enabling production traffic.

Do not enable production traffic until the native runtime test and the
end-to-end worker test have passed on the target Dolibarr version.
