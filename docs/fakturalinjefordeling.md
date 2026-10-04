# Fakturalinjefordeling — Training 0.4.0

Denne leverance knytter eksisterende, validerede standardfakturalinjer til navngivne tilmeldinger. Den første økonomikobling er administrativ og eksplicit; den opretter ikke fakturaer, beregner ikke nye salgspriser og fordeler ikke betalinger.

## Standarddata og manglende data

| Information | Kilde / ejerskab |
| --- | --- |
| Kursusservice og katalogpris | Dolibarr `Product`, type 1; programversionens service-link |
| Faktura, reference, status og fakturamodtager | Dolibarr `facture` / `Facture` |
| Antal, enhedspris, rabat, moms, lokalafgifter, HT/TTC | Dolibarr `facturedet`, felter verificeret mod 24.0.2 |
| Kundeadgang og virksomhed | Standard `Societe`, entity og `societe_commerciaux` |
| Deltageridentitet | Standard `Contact` via learner/enrollment |
| Relation mellem hold og standardfakturalinje | Ny `training_billing_line` |
| Deltagerens andel af linjen | Ny `training_billing_allocation` |
| Fordeling, rettelser og tilbagekaldelse | `TrainingBillingService` og eksisterende transaktionel audit |
| Faktisk betaling og afregning | Dolibarrs standardøkonomi; ikke fordelt på deltagere i 0.4.0 |

Fakturamodtageren er en standardvirksomhed/personkunde. Modulet antager ikke, at denne er deltagerens arbejdsgiver, køber i en tidligere ordre eller den faktiske betaler. Arbejdsgiver ved tilmelding, køber/finansieringsaftale, accepteret pris før fakturering og eksplicit betalingsallokering mangler stadig.

## Arbejdsgang

1. Opret og validér den almindelige faktura gennem Dolibarrs standardarbejdsgang. Linjen skal være knyttet til samme standardservice som holdet.
2. Åbn holdkortets **Fakturalinjefordeling**. Indtast standardfaktura-ID og linje-ID; originalfakturaen kan åbnes via link.
3. Angiv vægt for de bekræftede tilmeldinger: `1,1,1` deler hele linjen ligeligt; `2,1` giver forholdet 2:1; `0` udelader deltageren.
4. Gem. Linjens eksisterende HT og TTC fordeles; de gemte andele vises. Et aktuelt, identisk kald er en no-op.
5. Ret med særskilt rettelsesret og begrundelse. Hele fordelingen erstattes atomisk, med før/efter-historik.
6. Ved fejlagtig association kan fordelingen tilbagekaldes med begrundelse. Andele/snapshot/audit bevares, men er ikke aktiv fakturering. Eksplicit genaktivering på samme hold kræver rettelsesret og ny begrundelse og kan indlæse et ændret fakturagrundlag.

Der bruges ingen direkte SQL-opdateringer til standardfakturaer eller fakturalinjer. Adapteren læser versionens standardfelter; eventuel senere dokumentoprettelse skal bruge Dolibarrs objektmetoder. Afmelding giver hverken automatisk kreditnota, refusion eller sletning af fordelingen.

## Regler og præcision

- Én standardlinje har én globalt entydig mapping, også på tværs af module-entities. Én linje fordeles på ét hold; en samlefaktura kan indeholde flere linjer for forskellige hold.
- En tilmelding kan være på flere fakturalinjer/fakturaer. Det er en relation til faktisk fakturering, ikke en garanti mod genfakturering af samme aftale; aftalegrundlag og idempotent fakturaoprettelse kommer senere.
- Hele linjens beløb fordeles. Delvis association af en linje og fordelinger mellem forskellige hold på samme linje understøttes ikke i MVP. En tilbagekaldt mapping kan ikke flyttes til et andet hold i denne version.
- Vægte er positive heltal 1–10000; højst 1000 deltagere. Beregningen sorterer på tilmeldings-ID og fordeler kumulative andele med afrunding til otte decimaler. Den sidste residual medfølger deterministisk; HT og TTC summerer præcist til standardlinjens kanoniske decimalværdier.
- PHP-regningen bruger heltal og ingen binære floats. Standard-Dolibarrs DOUBLE-felter læses som DECIMAL(24,8); 0.4.0 understøtter ikke-negative beløb op til ti heltalscifre. Præcisionen er til afstemning; bogføringens valutaafrunding bliver hos Dolibarr.
- Almindelige fakturaer type 0, valideret/afsluttet status 1 eller 2, standardservice og basisvaluta er understøttet. Kladder, annullerede fakturaer, kreditnotaer, erstatningsfakturaer, depositum, situationer og fremmedvaluta er udskudt.
- Headerstatus “afsluttet” betyder ikke nødvendigvis fuldt betalt. UI viser derfor ikke deltagerbetaling eller kontant indbetalt omsætning.
- Snapshot omfatter linjens identitet, produkt, antal, priser, rabat, afgifter, HT/TTC og fakturareference/kunde/valuta. Katalogprisændringer påvirker ikke eksisterende allokeringer.
- Ændret standardgrundlag eller genåbnet/annulleret faktura bliver **ikke afstemt**. Ingen automatisk genberegning. Tilbagekaldelse og eksplicit genaktivering giver sporbar manuel behandling, når dokumentet igen er understøttet.
- En tilbagekaldt mapping beholder relationer til standardtabellerne. Foreign keys blokerer fysisk sletning af tilknyttede økonomidokumenter/linjer; historikken slettes ikke gennem Training. Brug Dolibarrs korrekte økonomiske annullering/kreditering.
- Booking-/afmeldingsændringer og fordeling bruger samme holdlås. Fordeling låser standardfaktura → linje → hold → mapping. Revisionskontrol afviser forældede formularer. Auditfejl ruller hele ændringen tilbage.

Ingen samlet nettoomsætnings-KPI leveres endnu: uden kreditnota-/afregningsmodellen ville den være ufuldstændig. En senere rapport skal filtrere aktiv mapping, aktuelt gyldigt standardgrundlag og korrekt periode samt følge de videre regler for kreditnotaer.

## Adgang og installation

Kræver Dolibarr-standardrettigheder til services, kontakter og fakturaer, Training kursus-/holdlæsning og `billing/read`. Fordeling kræver `billing/write`; rettelse, tilbagekaldelse og genaktivering kræver også `billing/correct`. Brugere skal kunne tilgå både de berørte deltagere og standardkunden, inklusive kommerciel tildeling. Fakturaen skal tilhøre aktiv entity; delt økonomiadgang på tværs af entities er bevidst ikke implementeret.

Training 0.4.0 tilføjer standardmodulet Fakturaer (`modFacture`) som afhængighed og rettigheder 504861–504863. Alle rettighedsnumre er stadig private/provisoriske. Upload [module_training-0.4.0.zip](../dist/module_training-0.4.0.zip) via Dolibarrs eksterne modulinstallation. Deaktivér/genaktivér ved opgradering, så den nye additive SQL-fil og rettigheder indlæses. Tag backup og brug testmiljø først. Moduldata bevares ved deaktivering.

`llx_training_zbilling.sql` indlæses efter eksisterende kursus-/holdtabeller af standardloaderen. Tabeller har InnoDB, normal tabelpræfiks-substitution, foreign keys og global linje-unikhed. Ingen kerneændringer eller nye Composer-afhængigheder.

## Test og resterende arbejde

CI anvender PHP 8.4, MySQL 8.0 og de **faktiske standarddefinitioner for facture/facturedet fra Dolibarr 24.0.2**. Tests dækker tredjedele/afrunding/store beløb, korrekt service/faktura/linje, status/type/valuta, kundeadgang, entity, rettelser, rollback, historik efter afmelding, kildeafvigelser, tilbagekaldelse/genaktivering og kapløb mellem nye fordelinger og rettelser på samme linje og på tværs af hold. Eksisterende kursus-/booking-/fremmødetests samt ZIP- og officiel descriptor-test fortsætter.

Fuld installation/aktivering og UI-test på målserveren følger [issue #3](https://github.com/smoeberg/Dolibarr_tms/issues/3) og er ikke udført her. UI bruger foreløbig dokument-ID'er og vægte; en standarddokumentvælger kan forbedre arbejdsgangen senere.

Næste økonomitrin er aftalepris/ordrelinje og separate køber-/finansieringsrelationer, derefter kreditnotaer og eksplicit afregningsallokering. Online checkout, reservationer og betaling kræver disse efterfølgende leverancer.

Officielle kilder: [facture-skema](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/install/mysql/tables/llx_facture.sql), [facturedet-skema](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/install/mysql/tables/llx_facturedet.sql) og [standard fakturakort/adgang](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/compta/facture/card.php).
