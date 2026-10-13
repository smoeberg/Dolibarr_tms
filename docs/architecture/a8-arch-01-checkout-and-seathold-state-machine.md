# A8-ARCH-01 — Checkout & SeatHold State Machine

**Status:** Normativt arkitekturdokument
**Serie:** A8 Architecture
**Forudsætter:** A8-ARCH-00 — Domain & Aggregate Boundaries
**Efterfølges af:** A8-ARCH-02 — Allocation & Enrollment Boundary
**Implementering:** Må først begynde efter arkitekturen er ratificeret

---

## 1. Formål

Dette dokument definerer den normative state model for A8-checkout og den tilhørende `SeatHold`-livscyklus.

Formålet er at fastlægge:

* hvad en checkout repræsenterer
* hvad en `SeatHold` repræsenterer
* hvilke states der er kanoniske
* hvilke transitions der er tilladte
* hvilke guards der gælder
* hvilke transitions der er forbudte
* hvordan session capacity beregnes
* hvordan Dolibarr `Facture` og `Paiement` observeres
* hvordan expiry, cancellation og late payment håndteres
* hvordan idempotency og recovery fungerer
* hvor allocation begynder
* hvorfor enrollment ikke må være en bivirkning af checkout/payment observation

Dette dokument definerer ikke selve allocation/enrollment-implementeringen. Den boundary fastlægges i ARCH-02.

---

# 2. Grundmodel

A8 skelner eksplicit mellem tre forskellige livscyklusser:

1. **Checkout lifecycle**
2. **SeatHold lifecycle**
3. **Scheduling lifecycle**

De må ikke reduceres til én state machine.

```text
CourseVersion
     │
     ▼
TrainingSession
     │
     ├────────────── Checkout lifecycle
     │                  │
     │                  ├── SeatHold
     │                  ├── Commercial document
     │                  ├── Payment observation
     │                  └── Allocation
     │
     └────────────── Scheduling lifecycle
                        │
                        └── TrainingSessionSlot
                              ├── TrainerAssignment
                              ├── Location
                              └── ResourceAssignment
```

En `TrainingSession` er den kommercielle og pædagogiske helhed, som deltageren tilmelder sig.

En `TrainingSessionSlot` er den planlægningsmæssige enhed, hvor tid, lokation, undervisere og ressourcer tildeles.

En `SeatHold` reserverer en plads på sessionen. Den reserverer ikke en bestemt kursusblok, underviser, lokation eller ressource.

---

# 3. Checkout aggregate scope

Checkout er knyttet til én konkret `TrainingSession`.

En checkout må derfor ikke eksistere uden:

* `TrainingSession`
* participant population
* reserveret capacity via `SeatHold`
* reservation/correlation key
* commercial correlation når commercial creation er gennemført
* payment correlation når payment er observeret

Checkout må ikke eje:

* Dolibarr customer masterdata
* Dolibarr contact masterdata
* Dolibarr invoice truth
* Dolibarr payment truth
* trainer scheduling
* resource scheduling
* agenda truth
* project/task truth

Checkout refererer til disse objekter og orkestrerer deres relation.

---

# 4. Canonical checkout states

Den kanoniske checkout-state machine er:

```text
HELD
  │
  ▼
COMMERCIAL_CREATED
  │
  ▼
PAYMENT_REDIRECTED
  │
  ▼
PAIEMENT_PRESENT
  │
  ▼
ALLOCATED
  │
  ▼
ENROLLMENT_CONFIRMED
```

De enkelte states betyder:

### `HELD`

Checkout har en gyldig aktiv `SeatHold`.

Der er endnu ikke oprettet et bindende Dolibarr commercial document.

### `COMMERCIAL_CREATED`

Det nødvendige Dolibarr commercial document eksisterer og er korreleret til checkout.

I A8 er dette normalt en Dolibarr `Facture`.

Checkout må ikke gå videre til payment redirect uden denne correlation.

### `PAYMENT_REDIRECTED`

Den native Dolibarr payment flow kan anvendes.

State betyder ikke, at betaling er gennemført.

Den betyder alene, at checkout har et gyldigt commercial document og en gyldig payment redirect.

### `PAIEMENT_PRESENT`

Dolibarr `Paiement`/`PaiementFacture` viser, at den relevante økonomiske forpligtelse er betalt tilstrækkeligt efter A8's betalingsregel.

Dette er en observation af Dolibarrs betalingssandhed.

### `ALLOCATED`

SeatHold er atomisk konverteret til den endelige allocation.

Allocation må kun ske efter `PAIEMENT_PRESENT`.

### `ENROLLMENT_CONFIRMED`

De relevante deltagere er oprettet/aktiveret som Training enrollments efter den definerede allocation boundary.

Enrollment er ikke en payment-state og må ikke bruges som alternativ betalingssandhed.

---

# 5. SeatHold lifecycle

`SeatHold` har sin egen state machine:

```text
ACTIVE
  ├── allocation → CONSUMED
  │
  ├── expiry ────→ RELEASED
  │
  └── cancellation → RELEASED
```

## `ACTIVE`

Pladsen er midlertidigt reserveret til den konkrete checkout.

## `CONSUMED`

Holdet er atomisk konverteret til allocation.

Et consumed hold kan ikke genaktiveres.

## `RELEASED`

Holdet er ikke længere gyldigt.

Release kan ske ved:

* expiry
* eksplicit cancellation
* anden eksplicit recoveryregel, som senere defineres normativt

Et released hold kan ikke bruges til ny allocation.

En ny checkout skal oprette et nyt `SeatHold`.

---

# 6. Capacity invariant

Session capacity er ikke en state.

Den beregnes ud fra sessionens kapacitet og dens reservationer/allocationer.

Den normative invariant er:

```text
available_capacity
=
capacity
-
active_holds
-
allocated_seats
```

med de relevante seat quantities efter sessionens kapacitetsmodel.

`TrainingSessionSlot` reducerer ikke i sig selv sessionens capacity.

Et tre-dages kursus med fem blokke er stadig én session med én samlet participant capacity.

---

# 7. SeatHold og scheduling er separate

Et `SeatHold` betyder:

> Denne checkout har midlertidigt krav på en plads på denne `TrainingSession`.

Det betyder ikke:

* at en bestemt plads i et lokale er reserveret
* at en bestemt trainer er reserveret
* at en bestemt resource er reserveret
* at en bestemt `TrainingSessionSlot` er reserveret

Scheduling konflikter håndteres af slot-/assignment-modellen fra ARCH-00.

Dette er nødvendigt for at undgå, at commercial checkout bliver koblet direkte til operationel planlægning.

---

# 8. Tilladte checkout transitions

## 8.1 `HELD → COMMERCIAL_CREATED`

Tilladt når:

1. checkout eksisterer
2. checkout tilhører den aktuelle entity
3. checkout er i `HELD`
4. `SeatHold` findes
5. `SeatHold` er `ACTIVE`
6. `SeatHold` tilhører samme session
7. reservation/correlation key matcher
8. holdet ikke er udløbet
9. participant data er valid
10. billing customer er entydig
11. commercial document kan oprettes
12. correlation kan persisteres atomisk med checkout transition

Efter transition skal checkout indeholde en persisted commercial correlation.

Minimum:

```text
native_commercial_object_type
native_commercial_object_id
native_commercial_object_ref
```

---

## 8.2 `COMMERCIAL_CREATED → PAYMENT_REDIRECTED`

Tilladt når:

1. checkout er `COMMERCIAL_CREATED`
2. commercial type er kendt
3. commercial object ID er gyldigt
4. commercial reference er gyldig
5. commercial object tilhører korrekt entity
6. payment URL kan genereres fra det korrekte commercial object

Transitionen ændrer ikke betalingssandheden.

---

## 8.3 Idempotent `PAYMENT_REDIRECTED → PAYMENT_REDIRECTED`

Hvis checkout allerede er `PAYMENT_REDIRECTED`, skal samme payment preparation kunne kaldes igen.

Resultatet skal være:

* samme commercial correlation
* samme native payment destination
* ingen ny commercial document
* ingen ny payment record
* ingen allocation

Dette er en normal idempotent operation.

---

## 8.4 `PAYMENT_REDIRECTED → PAIEMENT_PRESENT`

Tilladt når:

1. checkout er `PAYMENT_REDIRECTED`
2. commercial correlation stadig er gyldig
3. invoice tilhører korrekt entity
4. invoice reference matcher
5. invoice currency matcher checkout
6. invoice amount matcher den frosne commercial obligation
7. relevante Dolibarr `PaiementFacture` records findes
8. den kumulative betalte amount opfylder den definerede betalingsregel

Ingen allocation må udføres som side effect af denne transition.

---

## 8.5 Idempotent `PAIEMENT_PRESENT → PAIEMENT_PRESENT`

Gentagen observation skal være sikker.

En allerede bekræftet betaling må ikke:

* oprette endnu en payment record
* oprette allocation
* oprette enrollment
* ændre SeatHold til en ny state

Observationen skal returnere den allerede persisted payment observation.

---

# 9. Payment sufficiency

`PAIEMENT_PRESENT` betyder ikke nødvendigvis:

> sidste betaling var lig med fakturabeløbet.

Det betyder:

> den samlede relevante betaling opfylder den betalingsregel, som checkout kræver for at fortsætte.

For eksempel:

```text
invoice obligation = 125

payment #1 = 100
payment #2 = 50

cumulative = 150
```

Resultatet er:

```text
PAIEMENT_PRESENT
overpaid = true
```

Overpayment er en observation/outcome.

Det er ikke en separat canonical checkout state.

Den økonomiske behandling af overpayment ejes fortsat af Dolibarr.

Training må ikke opfinde en alternativ payment ledger.

---

# 10. Pending og partial payment

Hvis:

```text
cumulative_payment < required_amount
```

forbliver checkout i:

```text
PAYMENT_REDIRECTED
```

Eksempel:

```text
required = 125
paid     = 100

state = PAYMENT_REDIRECTED
```

Payment observation må være idempotent.

Der må ikke ske:

* allocation
* enrollment
* SeatHold consumption

før betalingskravet er opfyldt.

---

# 11. SeatHold expiry

Et `SeatHold` har en eksplicit expiry.

Ved expiry:

```text
ACTIVE → RELEASED
```

Checkout må ikke samtidig ændres til en ny "available" state.

Det er vigtigt, at:

```text
session capacity becomes available
```

men:

```text
the original checkout does not become AVAILABLE
```

En ny kunde/checkout skal oprette et nyt hold.

Dette forhindrer, at et gammelt checkout object genanvendes som en ny reservation.

---

# 12. Checkout efter released hold

Hvis et checkout stadig eksisterer, men dets `SeatHold` er `RELEASED`, må checkout ikke gennemføre allocation.

Det gælder også, hvis Dolibarr senere viser betaling.

Eksempel:

```text
HELD
  ↓ expiry
SeatHold = RELEASED

payment arrives later

Paiement = PRESENT
```

Dette er ikke en normal:

```text
PAYMENT → ALLOCATED
```

transition.

Det er en recovery/reconciliation situation.

Systemet skal derfor:

1. observere betalingen
2. identificere den korrekte checkout via persisted correlation
3. konstatere at SeatHold ikke længere er aktivt
4. undlade allocation
5. sende sagen til den eksplicit definerede recovery procedure

Der må ikke automatisk skabes plads blot fordi betaling findes.

---

# 13. Late payment

Late payment må aldrig behandles som implicit reservation.

Normativ regel:

```text
Payment truth ≠ SeatHold validity
```

Begge skal være gyldige for allocation.

Allocation kræver derfor mindst:

```text
valid commercial correlation
+
sufficient payment
+
valid active SeatHold
+
valid session
```

Hvis holdet er released, er betalingsobservationen ikke tilstrækkelig.

---

# 14. Cancellation

Cancellation skal skelne mellem:

* checkout cancellation
* SeatHold release
* Dolibarr commercial cancellation
* payment/refund
* allocation cancellation
* enrollment cancellation

Disse er ikke automatisk samme transition.

Som minimum:

```text
active SeatHold + cancellation
        ↓
SeatHold RELEASED
```

Efter allocation må en SeatHold ikke blot sættes tilbage til `ACTIVE`.

Eventuel cancellation af allocation/enrollment er en separat domain operation og skal defineres i ARCH-02/efterfølgende lifecycle-regler.

---

# 15. Forbidden transitions

Følgende transitions er normative forbidden:

```text
HELD → PAYMENT_REDIRECTED
HELD → PAIEMENT_PRESENT
HELD → ALLOCATED
HELD → ENROLLMENT_CONFIRMED

COMMERCIAL_CREATED → PAIEMENT_PRESENT
COMMERCIAL_CREATED → ALLOCATED

PAYMENT_REDIRECTED → ALLOCATED
PAYMENT_REDIRECTED → ENROLLMENT_CONFIRMED

PAIEMENT_PRESENT → ENROLLMENT_CONFIRMED
```

Den sidste gruppe er vigtig:

`PAIEMENT_PRESENT` må ikke springe allocation over.

Payment og enrollment er ikke samme domain event.

---

# 16. Allocation boundary

Allocation begynder først ved:

```text
PAIEMENT_PRESENT
```

Allocation skal kontrollere mindst:

* korrekt entity
* korrekt checkout
* korrekt session
* korrekt commercial correlation
* tilstrækkelig payment
* aktivt SeatHold
* korrekt reservation key
* tilstrækkelig capacity
* idempotency/uniqueness

Allocation må atomisk konvertere:

```text
SeatHold ACTIVE
        ↓
SeatHold CONSUMED
```

og skabe den tilsvarende allocation.

Detaljerne for denne operation fastlægges i:

**A8-ARCH-02 — Allocation & Enrollment Boundary**

---

# 17. Enrollment boundary

Enrollment må ikke oprettes af:

* invoice creation
* payment redirect
* payment observation
* webhook observation

Enrollment opstår først efter en gyldig allocation.

Normativ sekvens:

```text
Payment sufficient
        ↓
Allocation committed
        ↓
Enrollment
```

Der må ikke eksistere en normal runtime-state hvor systemet implicit antager:

```text
payment = enrollment
```

---

# 18. Dolibarr Invoice og Paiement

A8 anvender Dolibarr som canonical financial system.

Training ejer relationen til det kommercielle flow.

Dolibarr ejer:

* `Facture`
* `Paiement`
* `PaiementFacture`
* økonomiske beløb
* payment records
* payment linkage
* financial truth

Training gemmer de correlation fields, der er nødvendige for deterministisk recovery.

Training må ikke rekonstruere checkout ved:

> find latest invoice for this customer

eller tilsvarende heuristik.

Recovery skal baseres på persisted correlation.

---

# 19. Persisted correlation

Checkout skal kunne identificeres entydigt efter process crash.

Mindst følgende relation skal være persisted:

```text
Training checkout
    ↓
Dolibarr commercial object
```

og efter payment observation:

```text
Training checkout
    ↓
Dolibarr Paiement
```

Correlation må ikke kun eksistere:

* i HTTP session
* i webhook payload
* i redirect URL
* i runtime memory
* i log output

Persisted state er den normative recovery source.

---

# 20. Idempotency

Alle state transitions skal være idempotente, hvor operationen kan gentages efter crash/retry.

Minimum:

### Commercial creation

Samme checkout må ikke skabe to økonomiske obligationsobjekter på grund af retry.

### Payment redirect

Gentagelse må ikke skabe ny invoice eller payment.

### Payment observation

Gentagelse må ikke skabe ny payment eller allocation.

### Allocation

Samme payment obligation må ikke allokere samme SeatHold mere end én gang.

### Enrollment

Samme allocation må ikke skabe duplicate enrollment.

De sidste to detaljer ratificeres yderligere i ARCH-02.

---

# 21. Transaction-safe conflict control

State transitionen skal være beskyttet mod concurrent execution.

Eksempel:

```text
Request A ─┐
           ├── same checkout
Request B ─┘
```

Systemet må ikke tillade:

```text
A: sees HELD
B: sees HELD
A: creates commercial
B: creates commercial
```

på en måde der producerer to obligationsobjekter.

Relevant state og reservation skal derfor låses eller på anden måde beskyttes gennem transaction-safe conflict control.

Samme princip gælder ved:

* SeatHold expiry
* allocation
* payment observation
* concurrent retry

---

# 22. Crash recovery

Recovery skal kunne starte ud fra persisted state.

Eksempel:

```text
create invoice
      ↓
process crashes
      ↓
database state = HELD
```

Hvis commercial object allerede blev oprettet, men correlation ikke blev persisted, er systemet i en uønsket recovery situation.

Derfor skal commercial creation designes sådan, at:

```text
commercial creation
+
Training correlation
+
state transition
```

så vidt muligt udføres med den stærkeste tilgængelige atomicitet.

Hvis external transaction boundaries ikke kan være atomiske, skal ARCH-02/implementeringsdesignet definere en eksplicit recovery procedure.

Silent inconsistency er ikke acceptabel.

---

# 23. Recovery entry points

Minimum recovery cases:

### R1 — crash before commercial correlation

```text
HELD
commercial may exist
correlation missing
```

Systemet må ikke blot oprette endnu en invoice.

Der skal være en deterministisk recovery-strategi.

### R2 — crash after payment but before observation

```text
PAYMENT_REDIRECTED
Paiement exists
```

Næste observation skal kunne finde payment via persisted invoice correlation.

### R3 — payment after SeatHold expiry

```text
SeatHold = RELEASED
Paiement = PRESENT
```

Ingen automatisk allocation.

### R4 — crash during allocation

Allocation skal kunne retryes uden duplicate allocation.

### R5 — crash between allocation and enrollment

Boundary skal være eksplicit defineret.

Mulige arkitekturer er:

* samme DB transaction
* explicit pending/recovery state
* outbox/saga ved separat persistence/runtime boundary

Dette besluttes i ARCH-02.

---

# 24. Audit requirements

Alle state transitions skal kunne auditeres med mindst:

* checkout/session identifier
* current entity
* previous state
* new state
* correlation identifier
* actor/system actor
* timestamp
* relevant external object ID
* relevant payment observation
* failure/recovery reason når relevant

Audit data må ikke være den eneste state source.

Audit beskriver state changes; persisted domain state styrer recovery.

---

# 25. Interaction with TrainingSessionSlot

Checkout er session-level.

Derfor:

```text
TrainingSession
 ├── SeatHold
 ├── Allocation
 └── Enrollment
```

mens:

```text
TrainingSession
 └── TrainingSessionSlot
      ├── TrainerAssignment
      ├── Location
      └── ResourceAssignment
```

Checkout behøver ikke kende hver slot for at reservere en deltagerplads.

Et participant seat er en session-level commercial/pedagogical capacity.

Slot-level planning aktiveres gennem scheduling.

Dette gør det muligt at have:

```text
Session: 3 dage

Slot 1 → lokation A, trainer X
Slot 2 → lokation B, trainer X + Y
Slot 3 → lokation B, trainer Y
```

uden at ændre checkout's seat semantics.

---

# 26. Canonical lifecycle diagram

```text
                 CHECKOUT
                    │
                    ▼
                  HELD
                    │
             create commercial
                    │
                    ▼
          COMMERCIAL_CREATED
                    │
             payment redirect
                    │
                    ▼
          PAYMENT_REDIRECTED
                    │
          observe Dolibarr
                    │
        ┌───────────┴───────────┐
        │                       │
   insufficient             sufficient
        │                       │
        │                       ▼
        │               PAIEMENT_PRESENT
        │                       │
        │                    allocate
        │                       │
        │                       ▼
        │                   ALLOCATED
        │                       │
        │                   enroll
        │                       │
        │                       ▼
        │              ENROLLMENT_CONFIRMED
        │
        └── remain PAYMENT_REDIRECTED


SEAT HOLD

       ACTIVE
        │  │
        │  └── expiry/cancel ──→ RELEASED
        │
        └── allocation ────────→ CONSUMED
```

---

# 27. State ownership matrix

| Concern                     | Owner                       |
| --------------------------- | --------------------------- |
| Checkout lifecycle          | Training                    |
| SeatHold lifecycle          | Training                    |
| Session capacity            | Training                    |
| Allocation                  | Training                    |
| Enrollment                  | Training                    |
| Course/session/slot         | Training                    |
| Trainer assignment          | Training                    |
| Resource assignment         | Training                    |
| Location relation           | Training/domain integration |
| Invoice                     | Dolibarr                    |
| Payment                     | Dolibarr                    |
| Payment-to-invoice relation | Dolibarr                    |
| Customer/third party        | Dolibarr                    |
| Contact                     | Dolibarr                    |
| Product/service             | Dolibarr                    |
| Agenda event                | Dolibarr                    |
| Project/task                | Dolibarr                    |

Training må ikke oprette parallel financial eller ERP master truth.

---

# 28. Normative transition table

| From                 | To                     | Trigger                     | Required guards                                           | Side effects                    |
| -------------------- | ---------------------- | --------------------------- | --------------------------------------------------------- | ------------------------------- |
| `HELD`               | `COMMERCIAL_CREATED`   | commercial creation         | active hold, valid participants, billing customer, entity | create/correlate invoice        |
| `COMMERCIAL_CREATED` | `PAYMENT_REDIRECTED`   | payment preparation         | valid invoice correlation                                 | return native payment URL       |
| `PAYMENT_REDIRECTED` | `PAYMENT_REDIRECTED`   | retry                       | valid persisted correlation                               | none                            |
| `PAYMENT_REDIRECTED` | `PAIEMENT_PRESENT`     | sufficient Dolibarr payment | invoice/payment validation                                | persist payment observation     |
| `PAIEMENT_PRESENT`   | `PAIEMENT_PRESENT`     | retry                       | persisted payment state                                   | none                            |
| `PAIEMENT_PRESENT`   | `ALLOCATED`            | allocation                  | active hold, sufficient payment, capacity, idempotency    | consume hold, create allocation |
| `ALLOCATED`          | `ENROLLMENT_CONFIRMED` | enrollment                  | valid allocation                                          | create/confirm enrollment       |

---

# 29. SeatHold transition table

| From       | To         | Trigger      | Guards                                 |
| ---------- | ---------- | ------------ | -------------------------------------- |
| `ACTIVE`   | `CONSUMED` | allocation   | valid payment + allocation transaction |
| `ACTIVE`   | `RELEASED` | expiry       | hold expired                           |
| `ACTIVE`   | `RELEASED` | cancellation | cancellation permitted                 |
| `CONSUMED` | `ACTIVE`   | —            | **forbidden**                          |
| `RELEASED` | `ACTIVE`   | —            | **forbidden**                          |
| `CONSUMED` | `RELEASED` | —            | **forbidden**                          |

---

# 30. Test obligations

Tests must prove state, not merely HTTP response or row count.

Minimum transition tests:

### Commercial

* `HELD → COMMERCIAL_CREATED`
* missing hold rejected
* expired hold rejected
* wrong reservation key rejected
* wrong entity rejected
* duplicate retry idempotent
* no duplicate invoice

### Payment redirect

* `COMMERCIAL_CREATED → PAYMENT_REDIRECTED`
* missing invoice correlation rejected
* wrong entity rejected
* wrong invoice reference rejected
* repeated call idempotent

### Payment observation

* no payment remains `PAYMENT_REDIRECTED`
* partial payment remains `PAYMENT_REDIRECTED`
* exact payment becomes `PAIEMENT_PRESENT`
* overpayment becomes `PAIEMENT_PRESENT` with overpaid observation
* repeated observation idempotent
* payment belonging to another invoice is ignored/rejected
* payment from another entity is ignored/rejected

### SeatHold

* active hold can be consumed
* expired hold becomes released
* cancelled hold becomes released
* released hold cannot allocate
* consumed hold cannot be reused
* capacity cannot be double-consumed

### Recovery

* crash/retry does not duplicate invoice
* crash/retry does not duplicate payment observation
* late payment after hold release does not allocate
* allocation retry does not duplicate allocation
* enrollment retry does not duplicate enrollment

### Concurrency

At least one test must demonstrate that concurrent transitions cannot produce:

```text
duplicate commercial obligation
```

or:

```text
double allocation
```

for the same checkout/hold.

---

# 31. Non-goals

ARCH-01 does not define:

* public checkout API
* UI design
* Stripe webhook compatibility
* legacy checkout removal
* detailed allocation schema
* detailed enrollment schema
* refund policy
* payment dispute policy
* waitlist policy
* trainer scheduling algorithms
* resource scheduling algorithms
* project/task synchronization details

Those concerns belong to later architecture or implementation work.

---

# 32. Architectural rules to carry forward

The following rules are normative for A8:

1. **A checkout is not a capacity state.**
2. **A SeatHold is not a scheduling reservation.**
3. **Session capacity is an invariant, not a checkout state.**
4. **A released SeatHold never returns to active.**
5. **A late payment does not automatically create allocation.**
6. **Dolibarr `Paiement` is payment truth.**
7. **Training owns allocation and enrollment truth.**
8. **Payment observation has no enrollment side effect.**
9. **Allocation requires both sufficient payment and a valid SeatHold.**
10. **Persisted correlation is required for recovery.**
11. **Retries must be idempotent.**
12. **Concurrent execution must be transaction-safe.**
13. **TrainingSession is the commercial/pedagogical unit.**
14. **TrainingSessionSlot is the scheduling unit.**
15. **Scheduling blocks do not redefine participant capacity.**
16. **No transition may rely on "latest invoice" or equivalent heuristic lookup.**
17. **No state is introduced solely to make implementation convenient without a domain meaning.**

---

# 33. Next architectural document

After ratification of ARCH-01, the next document is:

**A8-ARCH-02 — Allocation & Enrollment Boundary**

It must decide explicitly:

* allocation aggregate and uniqueness
* exact `SeatHold → Allocation` transaction
* capacity locking
* payment sufficiency revalidation
* allocation idempotency key
* enrollment transaction boundary
* same-transaction vs recovery-state vs outbox/saga
* crash between allocation and enrollment
* enrollment uniqueness
* recovery/reconciliation rules
* audit semantics
* tests for allocation/enrollment failure modes

No implementation should start before that boundary is explicitly decided.
