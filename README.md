# Dolibarr TMS

Et nyt kursusadministrationsmodul til Dolibarr med online tilmelding gennem Dolibarr Website.

**Status:** Arkitektur og standard-/gapkortlægning, version 2.5. Der er endnu ingen modulimplementation i repoet. Runtime-prototyper og installationstest er ikke udført.

**Målversion:** Dolibarr 24.0.2, valgt af brugeren. PHP 8.4.26 og MySQL er oplyst via brugerens installationscheck. MySQL-version, testmiljø og betalingsudbyder er endnu ikke afklaret. Kompatibilitetstest mod målversionen udestår.

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
| Fase 1A | Intern kerne: kursusversioner, hold, blokke, tilmeldinger, økonomirelationer og basis-fremmøde | Planlagt |
| Fase 1B | Online tilmelding: ét hold pr. checkout, navngivne deltagere, én betalingsudbyder, godkendt B2B-faktura, outbox og afstemning | Planlagt |
| Fase 2 | Udvidelser som certifikater, avanceret bedømmelse, ventelisteautomatik og portal | Udskudt |

## Næste udviklingstrin

1. Etablér et testmiljø med Dolibarr 24.0.2, PHP 8.4.26 og MySQL; verificér MySQL-version, storage engine, isolation, SQL mode og nødvendige PHP-extensions.
2. Vælg og afprøv én betalingsudbyder og én betalingsvariant.
3. Verificér standardobjekter, adgangskontrol, Website-interface og jobdrift på målversionen.
4. Afprøv kapacitet og pladsreservation under samtidighed samt betaling efter reservationsudløb.
5. Omsæt den logiske datamodel til versionsbundne migrationsfiler og et modul med det beskrevne servicelag.

Estimater og driftsmål i arkitekturen er foreløbige. Dokumentationen er et udviklingsgrundlag; den er ikke dokumentation for et allerede fungerende modul.
