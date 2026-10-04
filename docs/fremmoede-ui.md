# Fremmøde-UI — 0.4.1

Opfølgning på [issue #4](https://github.com/smoeberg/Dolibarr_tms/issues/4). Leverancen forbedrer koordinatorflowet oven på fremmøde- og økonomikernen i 0.4.0. Den ændrer ikke rettigheder, kapacitet, økonomi eller databasetabeller.

## Arbejdsgang

Holdets undervisningsblok åbner et overskueligt fremmødeark med status, observeret ankomst/afgang, fremmødeminutter, forsinkelsesminutter og links til registrering og historik. En fuld-blok-attestering har sin egen tekst; tomme observerede tider bliver ikke præsenteret som faktiske ankomsttider.

**Registrer/ret** åbner én formular for den valgte deltager. Dato/tid-vælgerne arbejder i holdets navngivne tidszone, som står ved felterne. Browserens eller brugerens lokale tidszone bestemmer ikke undervisningstiden. Til stede med begge tider tomme attesterer fortsat hele blokken. Fravær/nulstilling kræver tomme tider. Rettelser kræver stadig den særlige rettelsesret og begrundelse.

Efter et vellykket servicekald og redirect vises en sessionsbaseret gemmebesked med den faktiske registrering, herunder `late`, hvis sen ankomst ændrede brugerens valgte `present`. Hvis en anden bruger når at ændre registreringen efter gem, vises det særskilt, og tabellen viser den aktuelle registrering. Et URL-flag er ikke bevis for gem.

Valideringsfejl bevarer formularværdier og den oprindelige revision. Ved revisionskonflikt lukkes redigeringen: genindlæs/vurder den nye registrering og åbn derefter en ny formular. UI opdaterer ikke automatisk den gamle formular til en ny revision for at gennemtvinge den tidligere ændring.

Historik vises som en tabel med tidspunkt i holdets tidszone, standardbruger-ID, før/efter-status, minutter, eventuelle observerede tider og rettelsesbegrundelse. Audit gemmes uændret; rå JSON vises ikke længere i fremmødesiden. Navne på andre standardbrugere hentes ikke med nye implicitte brugerrettigheder.

## Sommertid og API

`TrainingLocalTime` løser dato/tid-vælgerens lokale klokkeslæt til en konkret ISO-tid med offset. Den prøver de relevante IANA-offsets og validerer ved round-trip; PHP's automatiske justering af ikke-eksisterende lokaltid accepteres ikke.

- Ved fremrykning af klokken findes visse lokale klokkeslæt ikke. De afvises med en konkret besked.
- Ved tilbagesætning kan samme klokkeslæt forekomme to gange. Automatisk valg afvises; brugeren skal vælge den korrekte UTC-forekomst for hver tid.
- Feltet til forekomstvalg vises omkring overgangene, også når hele blokken ligger i en af de gentagne forekomster.
- Entydige tider kan løses automatisk. Et manuelt offset skal passe til den konkrete tid og tidszone.
- Tidszoner med negative eller kvarte/halve timeoffsets understøttes.

Konverteringen kaldes af `TrainingAttendanceRecord::normalize()` inde i fremmødeservicens normale transaktion. De eksisterende kontroller af blokgrænser, varighed, deltagerstatus, entity, kontakter, rettigheder og revision gælder fortsat.

API/servicekald med `arrival`/`departure` som eksplicitte ISO-offsettider er fortsat understøttet. Det nye input er `arrival_local`/`departure_local` (`YYYY-MM-DDTHH:MM`) og valgfri `arrival_offset`/`departure_offset`. Blandede, ikke-tomme ISO- og lokal-inputs afvises. UI indeholder ingen separat tids-/fremmøderegel.

## Installation og kontrol

Download [module_training-0.4.1.zip](../dist/module_training-0.4.1.zip) og upload via Dolibarrs standardinstallation af eksterne moduler. Den nye pakke indeholder hele modulet, også økonomikoblingen fra 0.4.0. Eksisterende data og rettigheder er uændrede; ingen SQL-migration er nødvendig for denne patch.

CI tester entydig tid, forårs-gap, begge efterårsforekomster, forkert offset, ugyldig dato, kvarte/negative offsets, lokal-input kontra eksisterende ISO-API samt normalisering/registrering/rettelse gennem MySQL-servicen. Alle eksisterende regressions-, samtidigheds-, ZIP- og descriptor-tests fortsætter.

Fuld målserverinstallation og browserbaseret test følger fortsat [issue #3](https://github.com/smoeberg/Dolibarr_tms/issues/3). Dato/tid-kontrollens udseende afhænger af browseren; browser- og mobilflow, tokenkontrol, valideringsfejl, konflikter og historik skal afprøves dér. Issue #4 leverer selve forbedringen; #3 er den fortsatte driftsverifikation.

Undervisertildeling er fortsat [issue #5](https://github.com/smoeberg/Dolibarr_tms/issues/5). Kun relevante koordinatorer skal have fremmødeskrive-/rettelsesrettigheder indtil hold-specifik adgang findes.
