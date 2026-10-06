# A8 Fase 3b — Dolibarr Stripe 50300 capability audit (24.0.2)

**Dato:** 2026-10-06  
**Scope:** Faktisk Dolibarr 24.0.2-kilde  
**Repository:** Dolibarr/dolibarr  
**TMS branch:** `boundary/fase3-checkout-payment`  
**Status:** Audit only — ingen produktionskode ændret

## Executive conclusion

Dolibarrs officielle Stripe-modul 50300 **dækker den centrale Stripe-betalingsmotor**, og TMS bør derfor **ikke eje sin egen Stripe PaymentIntent-motor**.

Men modulet dækker ikke hele den ønskede TMS-checkout-integration uden forbehold.

**Beslutning: NATIVE STRIPE DÆKKER DELVIST — men tilstrækkeligt til at DEPRECATE TMS som Stripe-provider-motor.**

Det officielle modul ejer:

- Stripe API credentials
- PaymentIntent/Checkout integration
- SCA/payment confirmation
- verifikation af Stripe-betaling
- oprettelse af native `llx_paiement`
- betaling på native Order/Invoice
- ekstern Stripe reference i `ext_payment_id`
- payment method i Dolibarr
- bank/accounting efterfølgende Dolibarr-flow

TMS skal derfor ikke eje:

- Stripe PaymentIntent
- Stripe Customer
- Stripe webhook ledger
- Stripe payment status
- Stripe reconciliation mod Stripe
- Stripe API keys

TMS skal fortsat eje:

- checkout orchestration
- seat hold
- checkout correlation
- enrollment
- price snapshot
- allocation af en eksisterende Dolibarr `Paiement` til enrollment

### Vigtig begrænsning

Dolibarr 24.0.2's public Stripe-flow er primært **return-URL/session-baseret**, ikke et separat server-side Stripe webhook-flow i `htdocs/stripe/`.

Det betyder, at TMS kan bruge Dolibarr som betalingsmotor, men vi skal ikke påstå, at et vellykket Stripe-køb automatisk bliver observeret af TMS, hvis kunden aldrig returnerer til Dolibarrs `paymentok.php`.

---

# S1 — Eksakt payment URL

## Resultat: PASS

Dolibarrs `getOnlinePaymentUrl()` findes i:

`htdocs/core/lib/payments.lib.php`

For Order:

`/public/payment/newpayment.php?source=order&ref=<order-ref>`

For Invoice:

`/public/payment/newpayment.php?source=invoice&ref=<invoice-ref>`

Hvis `PAYMENT_SECURITY_TOKEN` er aktiveret, tilføjes:

`securekey=<hash>`

Kilden bygger URL'en på Dolibarr object type + reference.

TMS kan derfor redirecte kunden til Dolibarrs officielle betalingsside i stedet for at oprette en Stripe PaymentIntent.

### Entity-vigtigt

`newpayment.php` og `paymentok.php` understøtter eksplicit entity via parameteren `e`.

Men `getOnlinePaymentUrl()` i 24.0.2 tilføjer ikke automatisk `e=<entity>`.

**Konsekvens:** TMS må ved multi-entity checkout sikre, at den genererede URL eksplicit indeholder korrekt entity.

Det er et TMS-integrationskrav, ikke en grund til at eje Stripe selv.

---

# S2 — Hvornår oprettes Paiement?

## Resultat: PASS med vigtig begrænsning

For Stripe validerer Dolibarr i `paymentok.php` den eksterne betaling mod Stripe API.

Derefter oprettes native:

`new Paiement($db)`

med bl.a.:

- `amounts`
- `paiementid`
- `ext_payment_id`
- `ext_payment_site`

og der kaldes:

`$paiement->create($user, 1)`

For Order sker desuden:

1. Dolibarr loader Order
2. opretter Invoice via `Facture::createFromOrder()`
3. validerer Invoice
4. opretter `Paiement`
5. lukker/klassificerer relevante native objekter

For Invoice oprettes `Paiement` direkte mod Invoice.

### Ingen separat native Stripe webhook fundet

Der findes ikke et separat Stripe webhook-endpoint under `htdocs/stripe/` i 24.0.2-kilden, som TMS kan bruge som universal server-side payment callback.

Den dokumenterede native success-path går gennem `public/payment/paymentok.php`.

**Konsekvens:** "Stripe succeeded" og "Dolibarr Paiement created" er koblet til Dolibarrs payment-return-flow.

---

# S3 — Paiement correlation fields

## Resultat: PASS

Dolibarr `Paiement` har:

- `ext_payment_id`
- `ext_payment_site`

Klassen dokumenterer direkte:

> `ext_payment_id`: external payment identifier; Stripe bruger bl.a. `pi_...`
>
> `ext_payment_site`: `StripeLive`, `StripeTest`, osv.

Ved Stripe-flowet sættes:

`$paiement->ext_payment_id = $TRANSACTIONID`

eller den længere transaction reference, hvis relevant.

TMS behøver derfor ikke en parallel tabel for at bevare Stripe-ID.

TMS skal kun læse native payment-reference ved behov.

---

# S4 — Korrelation til TMS checkout/enrollment

## Resultat: PASS — via native commercial object; ikke via TMS Stripe ID

Dolibarrs public payment-flow bærer allerede:

- source
- ref
- fulltag
- entity

Stripe PaymentIntent metadata indeholder bl.a.:

- `dol_version`
- `dol_entity`
- `dol_type`
- `dol_id`
- tredjeparts-ID hvor relevant

Ved Order er `ORD=<order id>` en del af standard `FULLTAG`.

Det betyder, at den robuste TMS-korrelation bør være:

`TMS checkout -> Dolibarr Order/Invoice -> Dolibarr Paiement -> TMS allocation`

ikke:

`TMS checkout -> Stripe PaymentIntent ID -> TMS payment ledger`

### Anbefalet identitet

TMS skal kende den native commercial object ID/reference, som checkout blev oprettet på.

Når Dolibarr `Paiement` findes, kan TMS allokere dette payment til enrollment.

Stripe ID er kun provider-reference.

---

# S5 — Success / cancel / expiry

## Resultat: DELVIST PASS

Dolibarr understøtter:

- success URL
- cancel/error URL
- PaymentIntent statuskontrol
- Stripe SCA/PaymentIntent
- Stripe Checkout-mode i konfigurationen

`newpayment.php` bygger success/cancel URLs til:

- `/public/payment/paymentok.php`
- `/public/payment/paymentko.php`

### Vigtig begrænsning

I den almindelige PaymentIntent-model verificeres betalingen, når kunden kommer tilbage til `paymentok.php`.

Der er ikke et separat TMS-uafhængigt webhook-flow, som garanterer, at TMS får besked hvis browser-sessionen afbrydes efter Stripe har accepteret betalingen.

### STRIPE_USE_NEW_CHECKOUT

24.0.2 indeholder også kode for `STRIPE_USE_NEW_CHECKOUT`, hvor der oprettes en Stripe Checkout Session.

Den kode bør **ikke vælges som vores første integrationsvej** uden separat runtime-test. Kildens success-flow er ikke lige så klart koblet til den native `Paiement`-registrering som den almindelige PaymentIntent-path.

**Fase 3 bør derfor baseres på Dolibarrs dokumenterede/native payment URL + standard PaymentIntent-path, ikke på en ny TMS-specifik Stripe Checkout implementation.**

---

# S6 — Duplicate webhook / dobbeltbetaling

## Resultat: PASS for native return-flow; ingen TMS webhook-ledger nødvendig

Dolibarr har allerede beskyttelse i Stripe PaymentIntent-flowet mod at genbruge et allerede anvendt PaymentIntent til at registrere betaling på et andet object.

Koden kontrollerer eksisterende `llx_paiement.ext_payment_id`.

Hvis PaymentIntent allerede er brugt, afvises genbrug.

Derudover bruger `paymentok.php` session-baserede kontrolværdier og rydder payment session state efter behandlingen.

### TMS-krav

TMS må ikke lave:

`checkout_payment.status = paid`

som økonomisk sandhed.

TMS skal i stedet gøre allocation idempotent på:

`Dolibarr Paiement ID + Enrollment ID`

Dermed kan samme native payment ikke skabe to TMS allocations.

---

# S7 — Partial payment / refund / credit note

## Resultat: DELVIST PASS

### Invoice

Dolibarrs public invoice payment-flow understøtter betaling af et beløb mod en eksisterende invoice, og invoice-flowet beregner restbeløb.

Det passer godt med TMS' eksisterende payment allocation-model.

### Order

Order-flowet forventer som udgangspunkt ordrebeløbet.

Koden indeholder eksplicit kontrol af, at payment amount svarer til Order total, og markerer afvigelse som mulig hack/fejl.

**Anbefaling:** TMS checkout skal ikke bruge Order som en generisk partial-payment ledger.

### Refund / credit note

Refund/credit-note skal fortsat håndteres af Dolibarrs native Invoice/Credit Note/payment-flow.

TMS kan allokere resultatet til enrollment, men må ikke bygge sin egen refund/credit ledger.

Dette er i overensstemmelse med A8.

---

# S8 — Module activation/configuration/fail-soft

## Resultat: PASS

Stripe-modulet er:

- module ID `50300`
- core
- enabled via `MAIN_MODULE_STRIPE`
- konfigureret gennem `stripe/admin/stripe.php`

Public payment-flow kontrollerer:

`isModEnabled('stripe')`

Hvis Stripe ikke er aktivt, bliver betalingsmetoden ikke tilbudt.

Stripe-konfigurationen bruger:

- `STRIPE_TEST_SECRET_KEY`
- `STRIPE_TEST_PUBLISHABLE_KEY`
- `STRIPE_LIVE_SECRET_KEY`
- `STRIPE_LIVE_PUBLISHABLE_KEY`
- `STRIPE_LIVE`

Manglende publishable key resulterer i setup-fejl på betalingsformularen.

### TMS skal derfor ikke kopiere credentials

TMS skal kun kontrollere/eksponere, at native Stripe payment capability er tilgængelig.

---

# S9 — Hooks / triggers

## Resultat: PASS — native trigger findes

Public payment flow initialiserer hook-manager med:

`newpayment`

Der findes bl.a. hooks omkring:

- payment validation
- bank account selection
- payment validation checks

Endnu vigtigere: efter en succesfuld online payment kalder Dolibarr:

`$object->call_trigger('PAYMENTONLINE_PAYMENT_OK', $user)`

Det giver en platform-native integrationsmulighed.

### Arkitekturanbefaling

TMS bør ikke implementere en parallel Stripe webhook.

Hvis TMS har behov for event-driven integration, skal den integreres omkring:

**Dolibarr Paiement / native payment trigger**

og ikke omkring Stripe API events.

Dette holder provider-ansvaret i Dolibarr.

---

# S10 — Multi-entity / entity isolation

## Resultat: DELVIST PASS

Dolibarrs public payment endpoints understøtter entity:

`e=<entity>`

Både `newpayment.php` og `paymentok.php` definerer `DOLENTITY` før `main.inc.php`.

Dolibarrs `Paiement` fetch bruger desuden entity-filtering.

### Men

`getOnlinePaymentUrl()` genererer ikke automatisk `e=<entity>`.

Da Dolibarr eksplicit kommenterer, at to entities kan have samme reference, er dette vigtigt.

**TMS skal derfor selv bevare entity i checkout correlation og sikre entity-parameteren i den offentlige payment URL.**

Dette skal dækkes af en konkret multi-entity integrationstest.

---

# S1–S10 matrix

| ID | Verifikation | Resultat | TMS-konsekvens |
|---|---|---|---|
| S1 | URL-format/order/invoice | PASS | Brug native payment URL |
| S2 | Paiement creation | PASS / limitation | Dolibarr opretter native payment via paymentok |
| S3 | ext_payment_id/site | PASS | Ingen TMS Stripe-ID ledger |
| S4 | Checkout correlation | PASS | Knyt via Order/Invoice/Paiement |
| S5 | success/cancel/expiry | PARTIAL | Return-flow er stærk; ingen universal webhook |
| S6 | duplicate payment | PASS | Brug native Paiement + idempotent allocation |
| S7 | partial/refund/credit | PARTIAL | Dolibarr ejer økonomien |
| S8 | activation/config | PASS | TMS bruger native capability |
| S9 | hooks/triggers | PASS | `PAYMENTONLINE_PAYMENT_OK` er integrationspunkt |
| S10 | multi-entity | PARTIAL | TMS skal eksplicit sende `e` |

---

# Final architectural decision

## 1. TMS egen Stripe-motor

**DEPRECATE**

`TrainingStripeAdapter` skal ikke længere være betalingsmotor.

Den må ikke:

- oprette PaymentIntent
- validere Stripe betaling
- eje Stripe credentials
- eje Stripe customer
- reconciliere Stripe-beløb
- bestemme "paid"

Den kan senere fjernes efter migration og caller-audit.

## 2. checkout_payment

**DEPRECATE**

Den nuværende `llx_training_checkout_payment` er en shadow payment ledger.

Den skal ikke erstattes med en ny ledger.

## 3. reconciliation

**DEPRECATE/NARROW**

Den må ikke være økonomisk ledger.

Hvis der er behov for operationel checkout-status, skal den kun beskrive orchestration/correlation — ikke være authoritative payment state.

## 4. webhook_event

**NARROW**

TMS skal ikke bruge Stripe webhook-events som sin økonomiske sandhed.

Hvis tabellen kun eksisterer for den gamle TMS Stripe-motor, skal den senere udfases.

## 5. payment_allocation

**KEEP**

Dette er fortsat den korrekte TMS-struktur:

`Dolibarr Paiement -> TMS payment_allocation -> Enrollment`

## 6. payment_snapshot

**KEEP**

Snapshot er historisk TMS-information om allocation/pris.

Det er ikke en betalingsledger.

---

# Target flow efter audit

```
TMS Session / Enrollment
        |
        | seat hold
        v
TMS Checkout orchestration
        |
        | create native commercial document
        v
Dolibarr Order / Invoice
        |
        | getOnlinePaymentUrl()
        v
Dolibarr public/payment/newpayment.php
        |
        | Stripe via module 50300
        v
Stripe
        |
        | native verification
        v
Dolibarr paymentok.php
        |
        | Paiement::create()
        v
Dolibarr Paiement
        |
        | native payment reference
        v
TMS payment allocation
        |
        v
TMS Enrollment confirmation
```

**Ingen:**

`TMS -> Stripe PaymentIntent`

**Ingen:**

`TMS -> checkout_payment -> paid`

**Ingen:**

`TMS -> Stripe reconciliation -> paid`

---

# Fase 3 næste implementation boundary

Fase 3 skal derfor ikke længere beskrives som "Stripe integration".

Den skal beskrives som:

**Checkout → native Dolibarr commercial/payment integration.**

Minimal TMS-adapter:

`TrainingCheckoutService`

skal orkestrere:

1. validate session/participants
2. create/locate native Order or Invoice
3. persist checkout correlation
4. generate native Dolibarr payment URL
5. redirect customer
6. on return, locate native Dolibarr Paiement
7. create idempotent TMS payment allocation
8. confirm enrollment
9. release/close seat hold

Den skal **ikke** kalde Stripe.

---

# Required tests before implementation

1. Native invoice payment URL resolves correctly.
2. Native order payment URL resolves correctly.
3. Entity `e` is preserved.
4. Correct Order/Invoice is loaded in target entity.
5. Stripe success results in native `llx_paiement`.
6. `ext_payment_id` is available.
7. Repeated return does not create duplicate TMS allocation.
8. Failed/cancelled payment does not confirm enrollment.
9. Successful payment confirms enrollment only after native Paiement exists.
10. Partial invoice payment produces correct TMS allocation.
11. Existing admin/native payment allocation remains independent of public checkout.
12. Capacity/seat hold semantics remain unchanged.
13. No Stripe API call exists in TMS after rewire.
14. Existing checkout tables can be left in place during migration without remaining callers.
15. Module source changes require deterministic release ZIP rebuild.

---

# Audit verdict

**Dolibarr Stripe 50300 is sufficient to remove the architectural justification for a TMS-owned Stripe payment engine.**

The native module is therefore the correct platform boundary.

It is **not sufficient to claim that TMS receives an asynchronous payment event independently of the browser return path**. That limitation must be explicitly handled in Fase 3 design.

**Decision:**

> **DEPRECATE TrainingStripeAdapter as payment engine.**
>
> **REWIRE TrainingCheckoutService to native Dolibarr commercial + payment flow.**
>
> **DEPRECATE checkout_payment as shadow ledger.**
>
> **Keep payment_allocation and payment_snapshot.**
>
> **No new payment ledger.**
>
> **No TMS Stripe API calls.**

No production code should be changed until the implementation brief is updated from this audit.
