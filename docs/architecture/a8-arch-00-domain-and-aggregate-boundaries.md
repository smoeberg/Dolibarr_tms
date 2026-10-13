# A8-ARCH-00 — Domæne- og aggregatgrænser

Status: Låst som arkitekturkontrakt (forud for A8-ARCH-01).
Dato: 2026-10-06

## 1. Domænemodel

```text
CourseProfile
    ↓
CourseVersion
    ↓
TrainingSession          = den konkrete kursusgennemførelse/hold,
    │                      kunden køber en plads på
    ├── Slot 1
    ├── Slot 2
    └── Slot n
```

- **TrainingSessionSlot** = en planlægningsbar **kursusblok** (ikke "dag").
  En blok kan være: heldagsblok, formiddag, eftermiddag, aften, eksamen,
  workshop, online session, virksomhedsbesøg osv.
- Slot ejer: start/slut, sekvens, blok-semantik, planlægningskrav.

## 2. Aggregatgrænser

### TrainingSession (ejer)
- kursusversion, konkret gennemførelse
- **kapacitet (på sessionsniveau, aldrig på slot-niveau)**
- enrollment-population
- kommerciel kontekst
- pladsreservationer, allokeringer

### TrainingSessionSlot (ejer)
- start/slut, sekvens, blok-semantik, planlægningskrav
- **præcis én lokation pr. slot i v1** (flere åbnes senere, hvis behovet opstår)
- 0..n underviser-tildelinger, 0..n ressource-tildelinger

### TrainerAssignment (ejer)
- trainer, **role** (hovedunderviser/gæsteunderviser/assistent), status
- Ingen `rate`/`planned_minutes` i v1 — det vedrører costing, ikke planlægning.

### ResourceAssignment (ejer)
- resource (reference til standardressource i Dolibarr, hvor den findes),
  quantity, status

## 3. Location adskilt fra resource

```text
Location:   København → Lokale 4      (et sted)
Resource:   Projektor, Laptop-kit, Lab-udstyr, Catering   (et middel)
Person:     Anna, Peter               (senere, via trainer assignment)
```

Model:

```text
TrainingSessionSlot
    ├── Location
    ├── TrainerAssignment [0..n]
    └── ResourceAssignment [0..n]
```

Ingen samlet `fk_location_resource`.

## 4. SeatHold-reservation

**SeatHold reserverer KUN en plads på TrainingSession.**
Der sker ingen reservation af lokale, underviser eller udstyr.

SeatHold / enrollment og slot-scheduling er **to forskellige livscyklusser**:

```text
Session
 ├── SeatHold → Allocation → Enrollment      (checkout-flow)
 └── Slots → Trainers/Locations/Resources    (scheduling-flow)
```

Checkout-state og scheduling-state er ikke den samme state machine.

## 5. Dolibarr-integrationer er projektioner

```text
TrainingSession       → Dolibarr Project
TrainingSessionSlot   → Dolibarr Project Task
TrainingSessionSlot   → Dolibarr Agenda Event
Slot-ressourcebehov   → Dolibarr Resource booking
```

- TrainingSessionSlot er de **kanoniske** kursusplanlægningsdata.
- Dolibarr Project/Agenda/Resource er integrationsmål — aldrig parallelle
  Training-masterdata, og aldrig felter (`fk_project_task` osv.) på selve slotten.
- Projektioner skal være **idempotente og regenererbare**: hver projektion har
  en stabil korrelationsnøgle (fx `slot.id + projection_type`), så et re-run
  ikke skaber dubletter. En slettet Agenda-event kan gendannes ud fra slotten.

## 6. Konfliktkontrol

Tre uafhængige konflikttyper pr. slot/tidsinterval:

1. **Trainer**: samme underviser + overlappende aktive opgaver = konflikt
2. **Resource**: samme ressource + overlappende aktive opgaver = konflikt
3. **Location**: samme lokation + overlappende inkompatible bookinger = konflikt

**Implementeringskrav:** Konfliktvalidering skal være transaktionssikker
(transaktion + `SELECT ... FOR UPDATE` på relevante rækker eller unikke
interval-nøgler) — ikke `SELECT → check → INSERT`, da dette skaber race conditions ved
samtidige requests. Concurrency-test er et eksplicit testpunkt.

**Tidszoner:** Slot-intervaller gemmes med eksplicit tidszone, så
overlap-beregningen er korrekt ved DST-skift.

## 7. Rækkefølge

```text
A8-ARCH-00  Domain & Aggregate Boundaries   (dette dokument)
A8-ARCH-01  Checkout + SeatHold state machine
A8-ARCH-02  Failure / recovery
A8-ARCH-03  Allocation → enrollment boundary
A8.6        Allocation
A8.7        Enrollment
...
```

Først domænegrænser, så state machines, og til sidst kode.
