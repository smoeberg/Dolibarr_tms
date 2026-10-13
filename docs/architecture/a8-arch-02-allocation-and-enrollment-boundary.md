# A8-ARCH-02 — Allocation & Enrollment Boundary

**Status:** RATIFICERET — beslutningen om SeatHold-konvertering og delbetaling er godkendt af ejer 13. okt. 2026 (se ratificeringslog i §8).
**Supplerer:** A8-ARCH-00 (Domain & Aggregate Boundaries), A8-ARCH-01 (Checkout & SeatHold State Machine), `native-payment-worker.md`.
**Autoritet:** Denne kontrakt er normativ. Der implementeres ikke `PENDING_ENROLLMENT`, ændres allokering eller recovery, før dette dokument er ratificeret og PR #25's draft-plan er sammenholdt hermed.

---

## 1. Formål og grænse

ARCH-02 fastlægger grænsen mellem **allokering** (bogføring af, hvilke betalinger dækker hvilke tilmeldinger) og **tilmelding** (deltagerens plads i kurset) — og den **asynkrone finaliseringssti**, der forbinder checkout, worker og enrollment.

Ikke-normativ sammenfatning af princippet: Dolibarr ejer penge (`llx_facture`, `llx_paiement`). TMS ejer undervisningsdomænet (session, seat, enrollment, allokeringssnapshots). TMS må *referere* til native dokumenter og *allokere* betalinger til tilmeldinger, men må aldrig opbygge en parallel hovedbog eller ændre native betalingsautoritet.

Denne kontrakt er skrevet *efter* PR #26 (late-payment reconciliation) og worker-trigger-infrastrukturen er landet i `main` (`69e9ce3`). Den koder den eksisterende adfærd som regler, hvor den er korrekt, og udpeger huler, hvor den ikke er.

## 2. Domænemodeller og aggregater

### 2.1 Allokeringsaggregatet (udvidelse af ARCH-00)

| Aggregat | Rodeentitet | Invarianter | Persistens |
|---|---|---|---|
| **PaymentAllocation** | `llx_training_payment_allocation`-række | Beløb > 0; samme valuta som paiemente; `(entity, fk_enrollment, fk_paiement)` unik; refererer kun til eksisterende `llx_paiement` | MySQL |
| **PaymentSnapshot** | `llx_training_payment_snapshot`-række | Ændringsfri efter skrivning; præcis én pr. allokering (unikt nøgle); frysner amount/currency/datep/ref ved oprettelsestidspunkt | MySQL |
| **EnrollmentBalance** | beregnet, *ikke* gemt | sum(allokeringer) + sum(kreditnoter) − enrollment-beløb ≥ −tolerance; aldrig negativ "skylder" uden årsag | afledt |

**Regel A2.1** — Allokeringsidentiteten er `(entity, fk_enrollment, fk_paiement)`. Den er **uafhængig** af `payment_observation_id`, checkout-session og outbox-kø-elementer. Et queue-element kan gentrigges, en observation kan re-læses, men allokeringen opstår højst én gang per (enrollment, paiemente)-par — håndhævet af den eksisterende unikke nøgle, ikke af applikationslogik.

**Regel A2.2** — Allokeringsbeløbet er altid et **snapshot-afledt** tal: beløbet, der er allokeret *til den pågældende enrollment* af det pågældende paiemente. Det er ikke paiementets samlede beløb (som kan dække flere enrollments) og ikke enrollmentens samlede dækkede beløb (som kan komme fra flere paiemente).

**Regel A2.3** — Kreditnoter indgår i saldo-beregningen via det eksisterende creditnote-allokeringslag (`llx_training_zcreditnote.sql`). ARCH-02 ændrer ikke kreditnote-modellen; `TrainingFinancialBalanceService` forbliver den eneste saldo-kilde.

### 2.2 Enrollment og PENDING_ENROLLMENT

**Regel A2.4 — PENDING_ENROLLMENT er en førsteklasses, persistent tilstand.**

- Det er en tilstand på **enrollment-aggregatet** (ikke på checkout_session, ikke på seat_hold), repræsenteret som `llx_training_enrollment.status = 'PENDING_ENROLLMENT'` (eller, hvis enums-området på `status` er mindre end 18 tegn i eksisterende installationer, tildeles en migrationssti på samme måde som ARCH-01's status-migration: opdag ved aktivering, `ALTER … MODIFY` idempotent, fail-closed ved manglende kolonne).
- En enrollment i `PENDING_ENROLLMENT`:
  - **Har en plads.** Pladsen er konverteret fra et `CONSUMED`-seat-hold i overensstemmelse med ARCH-01; kapacitetsregnskabet er deducted og forbliver deducted.
  - **Er ikke aktiv/tilgængelig for undervisning.** Den kan ikke tælles med i fremmøde, ikke generere certifikater, og fremstår som "Pending" i UI indtil aktivering.
  - **Er forespørgselbar**: findes via normal enrollment-liste med statusfilter; workeren og UI bruger samme kanoniske konstant.
- Overgange: `PENDING_ENROLLMENT → CONFIRMED` (fuld betaling bekræftet) · `PENDING_ENROLLMENT → CANCELLED` (tilbagebetaling/refusion/lukket session) · **ingen anden udgang**. `PENDING_ENROLLMENT` kan ikke genopstå fra `CONFIRMED`.

**Regel A2.5 — Skillelinjen mellem plads og betaling er fast. Konvertering sker kun ved fuld betaling.**

| Hændelse | Plads (seat) | Enrollment | Betaling |
|---|---|---|---|
| Checkout session oprettes | hold = active | ingen | — |
| Betaling godkendt (native `Paiement` registreret) | hold forbliver active | ingen | paiemente-objekt oprettet af Dolibarr |
| Faktura **delvist** betalt | hold forbliver active (kan udløbe naturligt) | **ingen** — ingen `PENDING_ENROLLMENT`, ingen allokering | beløbet ligger i Dolibarrs faktura; findes kun via reconciliation-stien |
| Faktura **fuldt** betalt (`paye = 1`) + gyldig reservation → worker | hold → CONSUMED | enrollment oprettes i `PENDING_ENROLLMENT` | allokering + snapshot skrives i samme transaktion |
| Fuld betaling + **ugyldig/udløbt** reservation | hold ændres ikke; genaktivering er forbudt | ingen | reconciliation-stien (fail closed) |

Normative konsekvenser:

- Der findes præcis **ét sted**, hvor et hold forbruges: fuld betaling + gyldig reservation, i worker-finaliseringsstien. Delbetaling forbruger aldrig et hold.
- `PENDING_ENROLLMENT` er en **kortlivet men persistent** mellemtilstand *inden i* den atomiske transaktion: hvis transaktionen ruller tilbage (fx audit-failure), forbliver ingen enrollment i `PENDING_ENROLLMENT` — holdet forbliver CONSUMED og pladsen er brugt; operatøren kan genkøre finaliseringen idempotent (A2.6).
- Enrollment oprettes **kun** via konvertering af et hold i worker-finaliseringsstien — aldrig direkte fra browser-retur, aldrig fra trigger-handleren, aldrig ved siden af et aktivt hold (ARCH-01's regel om `confirmReservation` er uændret).

## 3. Worker og recovery-kontrakt

### 3.1 Idempotens og guards

**Regel A2.6 — Finaliseringen er idempotent under korrelationsnøglen.**
`finalizeNativeCheckout()` skal være sikker at køre mere end én gang for samme checkout-session (worker-retry, lease-recovery, manuel genkørsel). Effekten af N kørsler == effekten af én:

1. Hvis checkout allerede er `paid`/`finalized` → returnér succes uden sideeffekter.
2. Hvis holdet er `CONSUMED` og enrollment-rækkerne allerede findes (`fk_enrollment` sat på participants) → verificer kun; ingen ny oprettelse, ingen ny audit ud over idempotens-loggen.
3. Hvis holdet er `RELEASED` eller `EXPIRED` → **fail closed**: log, sæt queue-elementet til fejl med kode `HOLD_NOT_ACTIVE`, og lad betalingen forblive i reconciliation-stien (PR #26's sti). Finaliseringen må aldrig "genoprette" et dødt hold.

**Regel A2.7 — Recovery-guards er eksplicitte, ikke implicitte.**

- Lease-recovery (15 min., eksisterende worker-dokument) må kun genoptage elementer, hvor checkout-status stadig er `payment_redirected` eller `reconciling` — aldrig `paid`/`finalized`.
- Workeren kører under den dedikerede workflow-bruger (se `native-payment-worker.md`); rettighedsfejl er **fail closed** og sætter elementet i fejltilstand med tydelig kode — aldrig retrier i det ubestemte.
- Fejltilstande på queue-elementer er: `HOLD_NOT_ACTIVE`, `RIGHTS_DENIED`, `NATIVE_OBJECT_MISSING`, `ALLOCATE_FAILED`, `RECONCILE_INCOMPLETE`. Ingen af dem retrier automatisk; de kræver operatørhandling eller en eksplisit "retry"-handling i admin-UI.

**Regel A2.8 — Trigger og worker er adskilte, og triggeren er tynd.**
BILL_PAYED-triggeren validerer kun (per `native-payment-worker.md`): `paye = 1`, TMS-korrelation på fakturaen, fuld betaling (ingen delbetaling). Alt forretningsarbejde sker i workeren. Triggeren må aldrig kalde `finalizeNativeCheckout` eller skrive enrollment direkte.

### 3.2 Allokeringsøjeblikket

**Regel A2.9 — Allokering sker i samme transaktion som enrollment-bekræftelsen.**
Når workeren konverterer `PENDING_ENROLLMENT → CONFIRMED`, skrives payment_allocation + payment_snapshot i samme `TrainingStore::transaction()` som statusændringen. Audit-failure ruller allokeringen tilbage (mønster fra PR #26's atomiske reconciliation-audit). Delallokering: hvis paiemente dækker flere enrollments, fordeles beløbet i de allokerede dele; hver del er en selvstændig allokering, hver med sit snapshot.

**Regel A2.10 — Delbetaling forbruger hverken plads eller enrollment.**
En faktura, der kun er delvist betalt, udløser ikke triggeren og fører ikke til holdkonvertering, enrollment-oprettelse eller allokering. Holdet forbliver `active` og kan udløbe naturligt (ARCH-01). Beløbet forbliver i Dolibarrs faktura og håndteres udelukkende gennem reconciliation-stien (PR #26), som forbliver fail-closed: ingen genaktivering af frigivne holds, og gentagne forsøg må ikke oprette enrollment eller allokering ved siden af.

**Regel A2.10b — En konverteret plads returneres ikke.**
Når et hold først er CONSUMED, returneres pladsen **ikke** til puljen på grund af efterfølgende betalingsproblemer; tilbagevinding sker kun via `CANCELLED`-stien (kapacitetsfrigivelse ved annullering følger ARCH-00's session-aggregat).

## 4. Grænseflader

### 4.1 `TrainingEnrollmentService`

| Metode | Kontrakt |
|---|---|
| `createPendingFromHold(int $sessionId, int $holdId, array $members, string $reason): int[]` | Opretter enrollment-rækker i `PENDING_ENROLLMENT` fra et CONSUMED-hold. Atomisk: alle members eller ingen. Kaster `TrainingInvalidTransition` hvis holdet ikke er CONSUMED. |
| `confirmPending(int $enrollmentId, int $paymentId, string $reason): void` | `PENDING_ENROLLMENT → CONFIRMED` + allokering + snapshot, atomisk (A2.9). Idempotent: genkald med samme paiemente → ingen ny allokering (unik nøgle fanger), returnerer uden fejl. |
| `cancelPending(int $enrollmentId, string $reason): void` | `PENDING_ENROLLMENT → CANCELLED`, frigiver pladsen i overensstemmelse med ARCH-00, audit. Idempotent: kald på allerede-CANCELLED er en no-op med succes. |

### 4.2 `TrainingPaymentService`

| Metode | Kontrakt |
|---|---|
| `allocateToEnrollment(...)` | Skriver allokering + snapshot atomisk; idempotent per A2.1; kaster `TrainingPaymentAlreadyAllocated` hvis parret findes med **andet** beløb (dette er et invariantbrud, ikke en retry-situation). |

### 4.3 UI-adgang

- `payment.php` og `order.php` bevarer de rettighedsdomæner, der blev rettet i PR #26-runden (`training.payment.*`, `training.order.*`); UI-kald går altid gennem service-lagets `allow()`-gate.
- "Pending Enrollment" vises i enrollment-liste og sessionsdetalje som egen status med handlingsmuligheder (bekræft, annullér). Ingen UI-sti opretter enrollment udenom workeren.

## 5. Migrations- og kompatibilitetskrav

**Regel A2.11** — Nye status-strenge (`PENDING_ENROLLMENT`) skal have en migrationssti i `modTraining.class.php` i samme mønster som checkout-status-migrationen (PR #26 `9e5ef57`): opdag ved aktivering via `information_schema.columns`, `ALTER TABLE … MODIFY` ved behov, idempotent, fail-closed ved manglende kolonne. Mock-hullet i `tests/run.php` (fanget 13. okt.) er allerede rettet med statusColumnType-mock + ALTER-dækning — samme dækning gælder her.

**Regel A2.12** — Ingen eksisterende enrollment-statusser omdøbes eller fjernes. Legacy-enrollment (oprettet før ARCH-02) forbliver gyldig; de konverteres til `PENDING_ENROLLMENT` kun ved eksplisit operatørhandling, ikke automatisk.

## 6. Testkontrakt (prove-then-lock)

Implementeringen låses først, når disse regressionstests findes og fejler på den aktuelle kode:

1. **Idempotent allokering:** samme paiemente allokeret to gange → anden kald er no-op; forskelligt beløb → `TrainingPaymentAlreadyAllocated`.
2. **Uafhængighed af observation:** to checkout-sessioner, der deler paiemente-observation → to uafhængige allokeringer; sletning af observation ændrer ikke allokering.
3. **PENDING_ENROLLMENT-forespørgselbarhed:** enrollment oprettet fra hold findes med status-filter; vises ikke som CONFIRMED i rapporter.
4. **Recovery-guard:** queue-element med checkout i `paid`-tilstand → recovery skipper; lease udløbet på `reconciling` → retry tilladt.
5. **Fejltilstande:** hver af de fem fejltilstande fra A2.7 kan sættes og aflæses; ingen af dem retrier automatisk.
6. **Atomisk allokering:** audit-failure under `confirmPending` ruller både status og allokering tilbage (udvider PR #26's audit-atomicity-test).
7. **Delbetaling:** faktura betalt 50/100 → **ingen** holdkonvertering, **ingen** enrollment, **ingen** allokering; hold forbliver `active`. Fuld betaling → atomisk kæde: CONSUMED + `PENDING_ENROLLMENT` + allokering i én transaktion.
8. **PENDING_ENROLLMENT-persistens:** tilstanden kan læses fra vedvarende lagring (ikke blot findes som enum eller i hukommelsen); audit-fejl giver den præcist definerede, atomiske rollback-adfærd, hvorefter en idempotent genkørsel fuldfører kæden.

## 7. Explicit out of scope

- Ingen ændring af payment-authority-modellen (Dolibarr ejer fortsat `llx_facture`/`llx_paiement`).
- Ingen ny Stripe/webhook-infrastruktur (afvist i ARCH-00).
- Ingen ændring af ARCH-01's state machine — ARCH-02 *forbruger* dens kontrakter, omskriver dem ikke.
- Ingen automatisk konvertering af legacy-enrollments.

## 8. Ratificeringslog

- **13. okt. 2026** — Ejer godkendte implementeringskontrakten: (1) delbetaling forbruger ikke SeatHold og opretter hverken enrollment eller allokering; (2) fuld betaling + gyldig reservation → holdkonvertering, persistent `PENDING_ENROLLMENT` og allokering i én atomisk transaktion; (3) `PENDING_ENROLLMENT` er en selvstændig, persistent, forespørgsbar status; (4) ugyldig/udløbet reservation håndteres kun via fail-closed reconciliation; (5) idempotens på tværs af worker-kørsler og observationer. A2.5, A2.10 og §6 er opdateret derefter af ejerens side (dokumentet ejes af TMS-ejeren, ikke af implementatoren). Implementationatoren har ret til at gennemgå kontrakten mod eksisterende kode, men ikke til at ændre den.

*Ratificeringsnote: Reglerne A2.1, A2.4, A2.6 og A2.9 er de bærende; hvis nogen af dem ændres under review, skal §6's testkontrakt justeres i samme commit. Implementationsskøn (kolonnenavne, metodesignaturer) er sekundært og må justeres i implementation-PR'en, så længe reglerne holder.*
