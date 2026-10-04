# Kommercielle roller pr. tilmelding — 0.8.0

Denne leverance registrerer **køber, forventet betaler og arbejdsgiver** særskilt pr. eksisterende tilmelding. Deltageren er stadig Dolibarrs standardkontakt via learner-profilen. Alle tre øvrige roller linker til standardtredjeparter i Dolibarr (`societe`), herunder privatkunder. Samme tredjepart kan have flere roller; hver rolle kan være uregistreret. Kontaktens tilknyttede virksomhed udfylder ikke automatisk nogen rolle.

## Datakilder og manglende registreringer

| Oplysning | Master/kilde | Registrering i Training |
|---|---|---|
| Deltagerens navn og kontaktoplysninger | Standard Contact / `socpeople` | Eksisterende learner- og enrollment-link |
| Kunde-/virksomhedsnavn, adresse, kontaktoplysninger, professionelle ID’er, momsnummer, kunde-/prospektstatus | Standard Societe / `societe` | Ingen kopier eller nye ERP-stamdata |
| Hvem køber denne tilmelding? | Eksplicit valg af standardtredjepart | Nullable `fk_buyer` |
| Hvem forventes at betale? | Eksplicit valg af standardtredjepart | Nullable `fk_payer`; ingen dokumentation for betaling |
| Hvem er arbejdsgiver ved tilmelding? | Eksplicit valg af standardtredjepart | Nullable `fk_employer`; ingen løbende synkronisering med kontaktkort |
| Hvem ændrede rollerne og hvorfor? | Training audit + standardbruger-ID | Revision, actor, dato, before/after-ID’er og begrundelse |
| Accepteret pris, moms, valuta, vilkår, acceptdato og identitetssnapshot | **Endnu ikke implementeret** | Kræver separat aftale-/acceptleverance |
| Ordre og ordrelinjeforbindelse | **Endnu ikke implementeret** | Skal bruge Dolibarrs standardordre i næste økonomiflow |
| Finansieringsandele, fakturamodtager og faktiske betalinger | Dolibarr økonomi + fremtidige eksplicitte forbindelser | Udledes ikke af forventet betaler |

En privat kunde oprettes som standardtredjepart i Dolibarr. Training opretter ikke en parallel person-/kundedatabase. Eksisterende ekstra felter på standardobjekterne forbliver i Dolibarr; dette skærmbillede viser kun navn og aktivstatus samt link til standardkortet.

## Service, rettigheder og status

`TrainingCommercialService` er eneste writer til `training_enrollment_commercial`. UI kalder samme service. Ny tabel oprettes additivt ved modulaktivering; ingen eksisterende kolonner ændres. En entydig entity/enrollment-relation giver én aktuel registrering pr. tilmelding. Foreign keys peger på standardtredjeparter; entity/hold/deltager kontrolleres i service-laget.

Adgang kræver intern bruger, standard-service læseadgang, course/read, session/read, enrollment/read, commercial/read, standardkontakt læseadgang og standardtredjepart læseadgang. Dolibarrs delingsscope fra `getEntity('societe')` og kundebegrænsning til tilknyttet sælger gælder både valg og visning. Alle nuværende roller skal være tilgængelige før læsning eller rettelse; man kan ikke omgå adgangskontrol ved at rydde en skjult rolle. Historik kræver også adgang til tidligere linkede tredjeparter. Undervisernes ownattendance-rettigheder giver ingen adgang til kommercielle roller.

Nye rettigheder **504869–504871**, efter eksisterende ID’er uden omnummerering:

- `commercial/read`: se roller og tilgængelig historik.
- `commercial/write`: første registrering.
- `commercial/correct`: ændre eller rydde en eksisterende registrering, sammen med write.

| Handling | Krav/resultat |
|---|---|
| Åbn uden registrering | Alle roller vises som “ikke registreret”, revision 0 |
| Første gem | Bekræftet tilmelding, write, begrundelse; revision 1 + audit |
| Ændr/ryd roller | Bekræftet tilmelding, write + correct, præcis revision, begrundelse |
| Gentag samme roller med aktuel revision | Ingen ny revision eller audit; nøgleorden er uden betydning |
| Gem med gammel revision | Afvises; UI viser aktuel registrering til ny gennemgang |
| Tildel ny rolle | Aktiv, tilgængelig standardtredjepart; også hvis den allerede har en anden rolle |
| Bevar senere inaktiv tredjepart i samme rolle | Tilladt og markeret i visningen; øvrige roller kan rettes |
| Afmeld | Roller og historik bevares; registrering kan læses, men ikke ændres |
| Bekræft samme tilmelding igen | Eksisterende enrollment-ID og roller bevares; koordinator skal gennemgå dem igen |

Der er ingen “accepteret aftale”-status i denne leverance. Gemning bekræfter hverken pris, betalingsforpligtelse eller kundens accept. Alle roller kan være tomme; det gør manglende registrering synlig. Senere ordre-/checkoutflow skal kræve de relevante obligatoriske oplysninger og fryse aftalen, før der skabes økonomiske dokumenter.

## Transaktioner og historik

Writer låser hold → tilmelding → aktuel rolleregistrering → valgte standardtredjeparter i sorteret ID-orden. Holdlåsen er samme mutex som tilmelding/afmelding. Revision kontrolleres efter låsning. Rolleskift og audit committes samlet; auditfejl ruller også første indsættelse tilbage. En ændret tredjeparts navn vises straks fra standardtabellen. Historikken bevarer relationernes ID’er, ikke et accepteret identitets-/adressesnapshot.

Holdets bookingstatus er ikke en økonomisk aftalestatus: eksisterende bekræftede tilmeldinger kan vedligeholdes, også efter holdet er lukket. Historiske rettelser er således eksplicitte og kræver correct; de omskriver ingen faktura eller allokering.

## UI og verifikation

Holdkortets deltagerliste linker til **Kommercielle roller**. Søg standardtredjeparter (maks. 50 aktive og tilgængelige resultater), vælg hver rolle særskilt og angiv begrundelse. Eksisterende valgte tredjeparter bevares i formularen selv om de falder uden for søgeresultatet. Rollevalg vedrører én tilmelding, ikke hele gruppen i en reservation.

CI tester MySQL med ikke-standard prefix, gentaget schemaaktivering, forskellige/samme/tomme roller, native entity-/sælgeradgang, separate rettigheder, inaktive tredjeparter, forkert hold, auditrollback, afmelding/genbekræftelse og samtidige første registreringer/rettelser på uafhængige forbindelser. Officiel Dolibarr 24.0.2-installation tester standard Societe-oprettelse, rollelæsning, bevaret tabel ved deaktivering/genaktivering og HTTP-formular med native login, CSRF og revisionskonflikt.

ZIP: `dist/module_training-0.8.0.zip`. GUI-upload/upgrade på målserveren er fortsat ikke verificeret (#3). Foretag backup, installér pakken og deaktivér/genaktivér modulet for additivt schema og nye rettigheder; tildel disse eksplicit til relevante koordinatorer. Ingen undervisere får kommerciel adgang automatisk.

## Verificerede standardkilder

Felter og rettigheder er kontrolleret mod [Dolibarr 24.0.2 `llx_societe.sql`](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/install/mysql/tables/llx_societe.sql) og [modSociete](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/core/modules/modSociete.class.php). CI læser/skriver standardstamdata gennem den officielle installation; modulservicen læser standardtabellens identitet, navn og aktive status og ændrer ikke standardtredjeparten.
