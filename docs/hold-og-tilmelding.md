# Hold og administrativ tilmelding — 0.2.0

Opgavebranch: `feature/session-enrollment-core`. Ændringen leveres til review gennem en pull request. `main` opdateres først efter review og beståede checks.

## Leverancen

| Oplysning | Master / regel |
| --- | --- |
| Kursusreference, titel, salgsbeskrivelse | Dolibarr-standardservice; historisk programversion fra 0.1.0 |
| Holdreference, titel, kapacitet, tidszone | `training_session`, knyttet til én publiceret programversion i aktiv entity |
| Undervisningsblokke | `training_session_slot`; UTC start/slut, holdet bevarer IANA-tidszone |
| Deltageridentitet | Standardkontakt `Contact`; `training_learner` indeholder kun link og profilmetadata |
| Pladsbooking | `training_enrollment`; højst én række pr. deltagerprofil og hold |
| Bookede pladser | Afledt af `confirmed`; afbud tæller ikke |
| Ændringshistorik | `training_audit`; samme transaktion som ændringen |

Der er ingen parallel kundestamme eller kopi af deltagerens navn, e-mail eller telefon. Kontaktens aktuelle identitet læses gennem standardobjektet. Bevis-/aftaleidentitet fryses først med de senere relevante dokumentflows.

## Regler

- Et hold oprettes som kladde på en publiceret programversion. Reference er unik pr. entity.
- Blokke kan ændres i kladde. Interne overlap og ugyldige datoer/offsets afvises. Åbning kræver at summen af reelle undervisningsminutter svarer til programversionen.
- Workflow: kladde → åben → lukket → åben. Kalenderdato, kapacitet og workflow er forskellige oplysninger. Afslutning/aflysning af hele hold er endnu ikke implementeret.
- Tilmelding kræver et åbent hold og en aktiv, tilgængelig standardkontakt. Eksisterende bekræftet booking kan genfindes ved gentaget request, også efter lukning.
- Afbud kræver begrundelse og frigiver en plads. Gentaget afbud ændrer ikke historik. Gentilmelding genbruger tilmeldings-ID og kræver ny kapacitetskontrol; tidligere afbudsårsag bevares i audit.
- Alle writes til kapacitet/tilmelding tager først `FOR UPDATE` på samme holdrække. Bookede pladser læses som aktuelle låsende rækker, så en ældre transaktionssnapshot ikke kan medføre overbooking.
- Kapacitet må ikke sænkes under eksisterende bookinger. Fejl i audit ruller ændringen tilbage.
- Kontaktadgang kræver standardrettigheden `societe/contact/lire`, tilladt kontakt-entity og adgang til eventuelt tilknyttet selskab. Brugere uden `societe/client/voir` kræver standardrelation til selskabet i `societe_commerciaux`. Begrænsede kontaktidentiteter vises ikke i deltagerlisten.

Ingen udgående beskeder eller eksterne kald udføres inde i disse transaktioner. Der er endnu ingen seat holds, pris-/momssnapshot, køber-/betalerrelation, faktura eller betaling. Status `confirmed` betyder en administrativt booket plads; UI kalder den derfor **Plads booket**. Den er ikke dokumentation for en kommerciel aftale eller afregning. Økonomileverancen og online checkout skal tilføje de nødvendige regler før eksternt salg.

## Brug og opgradering i testmiljø

1. Opdatér hele `htdocs/custom/training`. Kontrollér foreløbigt modul-ID 504850 og rettigheds-ID'er 504851–504857.
2. Deaktivér/genaktivér Training i testinstallationens modulopsætning. Data bevares. Det additive schema opretter fire nye tabeller; eksisterende katalogkolonner ændres ikke. Services og Third Parties er afhængigheder.
3. Tildel kursuslæsning, holdlæsning/-ændring og tilmeldingslæsning/-ændring efter roller. Deltagerlookup kræver desuden standardkontaktlæsning og relevante kunderettigheder.
4. Åbn en standardservice → fanen **Hold**. Opret et hold med en publiceret version.
5. Tilføj blokke på holdkortet. Den første UI bruger én linje pr. blok med `start;slut` og eksplicit tidszoneoffset. Fx `2026-10-20T09:00:00+02:00;2026-10-20T16:00:00+02:00`. Flere blokke med mellemrum modellerer pauser. Service-laget modtager strukturerede blokke; en kalendereditor kan erstatte indtastningen senere.
6. Åbn for tilmelding, søg eksisterende standardkontakter og book navngivne deltagere. Søgning begrænses til 50 kandidater; indsnævr søgningen efter navn ved større registre.
7. Afprøv fuldt hold, gentaget booking, afbud/gentilmelding, lukning og kapacitetsnedsættelse. Prøv roller med begrænset kontakt-/entity-adgang.

Der er ikke deployet eller installeret på eira-systems.eu. Ressourcebooking, lokaler, undervisere og konflikter med andre hold/Agenda indgår endnu ikke; et åbent hold er derfor ikke en garanti for ledigt lokale eller underviser. Fremmøde og økonomilinjerelationer følger som selvstændige opgaver.

## Teststrategi og grænser

CI tester PHP-syntaks, eksisterende katalogtests, tidszone/DST-validering og MySQL-regler. Den faktiske MySQL-test omfatter entydighed, UTC, workflow, idempotens, kontakt-/entity-afgrænsning og rollback ved auditfejl. Separate PHP-processer med hver sin databaseforbindelse konkurrerer om sidste plads i fem runder. Yderligere fem runder konkurrerer tilmelding og kapacitetsnedsættelse. Testdatabasen er isoleret og bruger `tst_`-prefix.

MySQL 8.0 i CI er en testversion. Brugerens MySQL-version er stadig ukendt. Tests bruger standardobjekt-testadaptere; Dolibarr-installation, UI, CSRF og konkrete standard-/MultiCompany-konfigurationer skal stadig kontrolleres i målmiljøet. Sessionens relation til korrekt program-entity valideres i servicelaget; FK til version sikrer eksistens, mens nye underobjekter har composite entity-FK.

Skemaoprettelsen er genkørbar, men `IF NOT EXISTS` ændrer ikke eksisterende kolonner. Ingen historiske enrollment-tabeller migreres, da de ikke eksisterede i 0.1.0. Deadlocks/timeout giver kontrolleret fejl og rollback; automatisk retry og belastningsmålinger er endnu ikke kvalificeret.

Kildekontrakter for kontakt og rettigheder er kontrolleret mod [Contact 24.0.2](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/contact/class/contact.class.php) og [modSociete 24.0.2](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/core/modules/modSociete.class.php).
