# Dolibarr TMS

Et nyt kursusadministrationsmodul til Dolibarr med online tilmelding gennem Dolibarr Website.

**Status:** Intern kerne 0.3.0: kursusprofiler, programversioner, hold, undervisningsblokke, administrativ tilmelding, kapacitetsstyring og basis-fremmøde. Online checkout er planlagt. Arkitektur og standard-/gapkortlægning er dokumenteret i version 2.5.

**Målmiljø:** Dolibarr 24.0.2, PHP 8.4.26 og MySQL. CI bruger PHP 8.4 og MySQL 8.0; den konkrete MySQL-serverversion og fuld installation/UI på målserveren mangler fortsat at blive verificeret.

## Download og installation

Download **[module_training-0.3.0.zip](dist/module_training-0.3.0.zip)** (vælg “Download raw file” på GitHub). Upload pakken direkte i Dolibarr under **Opsætning → Moduler/Applikationer → Installer eksternt modul**, og aktivér Training. Pakken indeholder kun det installerbare modul; ingen kerneændringer eller ekstra afhængighedsinstallation er nødvendig.

Se [installationsvejledning, fremmøderegler og testgrænser](docs/fremmoede.md). Modulkoden ligger i [`htdocs/custom/training`](htdocs/custom/training). Tidligere leverancer er beskrevet i [kursusgrundlaget](docs/udvikling.md) og [hold/tilmelding](docs/hold-og-tilmelding.md). Brug en testinstallation før produktion.

## Dokumentation

[Læs arkitekturen og Fase 0-resultatet](docs/arkitektur.md).

Dokumentet beskriver:

| Område | Afsnit |
| --- | --- |
| Ansvarsfordeling og genbrug af standarddata | 1–4 |
| Dashboard, datakilder og manglende registreringer | 5–7 |
| Forretningsregler og acceptkriterier | 8–10 |
| Online tilmelding gennem Dolibarr Website | 11 |
| Ressourcer, statusovergange og MVP-afgrænsning | 12 |
| Prototypeplan og udført Fase 0-kortlægning | 13–14 |
| Kilder, risici, beslutninger og data dictionary | 15–18 |
| Performance, persondata og foreløbigt estimat | 19–21 |
| Teststrategi, deployment, drift og kommunikation | 22–24 |
| Brugeropstart, eksempel, versionshistorik og ordliste | 25–27 |

Dokumentet henviser til screenshots og reviewtekster fra designarbejdet. Disse bilag er ikke lagt i repoet.

## Grundprincipper

- **Et kursus er en standardservice i Dolibarr.** Standardservice ejer produktreference, beskrivelse, aktuel pris og momsopsætning.
- Dolibarr ejer kunder, kontakter, brugere, tilbud, ordrer, fakturaer og betalinger.
- Training-modulet tilføjer kursusversioner, hold, undervisningsblokke, tilmeldinger, kapacitetsstyring, fremmøde og relationer til økonomiske linjer.
- Website er præsentationslag. Modulets services håndhæver tilmelding, reservationer og regler.
- EnrollmentService, SchedulingService og BillingService samler forretningslogikken for både administration og online tilmelding.
- Entity-afgrænsning, rettigheder, audit og historiske snapshots indgår fra første leverance.

## Leveranceforløb

| Fase | Indhold | Status |
| --- | --- | --- |
| Fase 0 | Standard-/gapkortlægning og målarkitektur | Dokumenteret; miljøvalg og runtime-prototyper udestår |
| Fase 1A | Intern kerne: kursusversioner, hold, blokke, tilmeldinger, økonomirelationer og basis-fremmøde | Kursusgrundlag, hold/tilmelding og basis-fremmøde implementeret; økonomirelationer mangler |
| Fase 1B | Online tilmelding: ét hold pr. checkout, navngivne deltagere, én betalingsudbyder, godkendt B2B-faktura, outbox og afstemning | Planlagt |
| Fase 2 | Udvidelser som certifikater, avanceret bedømmelse, ventelisteautomatik og portal | Udskudt |

## Næste udviklingstrin

1. Etablér et testmiljø med Dolibarr 24.0.2, PHP 8.4.26 og MySQL; verificér MySQL-version, storage engine, isolation, SQL mode og nødvendige PHP-extensions.
2. Vælg og afprøv én betalingsudbyder og én betalingsvariant.
3. Verificér standardobjekter, adgangskontrol, Website-interface og jobdrift på målversionen.
4. Afprøv kapacitet og pladsreservation under samtidighed samt betaling efter reservationsudløb.
5. Implementér linjeallokering til standardøkonomien og derefter online checkout med reservation, outbox og afstemning.

Estimater og driftsmål i arkitekturen er foreløbige. Dokumentationen er et udviklingsgrundlag; runtime-test på det konkrete målmiljø udestår.


Udviklingsarbejdsgangen er beskrevet i [CONTRIBUTING.md](CONTRIBUTING.md): en branch pr. opgave og pull request før merge til main.
