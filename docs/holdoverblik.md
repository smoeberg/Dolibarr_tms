# Hold og kapacitet – Training 0.7.0

Det interne overblik findes under Produkter/Services → Hold og kapacitet samt fra holdkortet. Det samler filtrerede totaler og en liste med samme grundlag. Der oprettes ingen nye data, rettigheder eller økonomital.

## Filtre og definitioner

| Oplysning | Kilde / regel |
| --- | --- |
| Holdudvalg | Aktuel Training-entity, publiceret kursusversion og autoriseret standardservice-entity |
| Workflow | draft/open/closed; standardfilteret er open |
| Tidsstatus | unscheduled hvis ingen blokke; upcoming før første start; ongoing fra første start til sidste slut; finished efter sidste slut |
| Fra/til | Første undervisningsstart i inklusive lokale kalenderdatoer, omsat til et halvåbent UTC-interval |
| Tidszone | Valgt IANA-zone; bruges til datoafgrænsning og viste tider; standard Europe/Copenhagen |
| Søgning | Holdreference, holdtitel eller versionens kursustitelsnapshot; SQL LIKE-mønster |
| Bekræftede pladser | Antal confirmed-tilmeldinger |
| Reserverede pladser | SUM(qty) på active-reservationer med expires_utc > databaseklokken |
| Ubrugt kapacitet | Holdkapacitet − bekræftede − reserverede; er ikke et løfte om åben tilmelding |
| Vægtet belægning | 100 × SUM(bekræftede + reserverede) / SUM(kapacitet); ingen gennemsnit af holdprocenter |

Et hold med to undervisningsdage tælles efter første startdato, ikke én gang pr. dag. Tidsstatus ongoing beskriver intervallet mellem første og sidste blok, også pauser og dage uden undervisning; den betyder ikke nødvendigvis, at der undervises lige nu. Workflow lukkes ikke automatisk, fordi sidste blok er afsluttet.

Kursets reference/titel vises fra holdets publicerede versionssnapshot, mens standardservicens aktuelle entity og type stadig afgør adgang. Ingen kopi af deltageridentitet vises i overblikket. Fakturering, betaling, pipeline, omsætning, unikke deltagere og ressourcetilgængelighed indgår endnu ikke som KPI'er.

## Konsistens og detailvisning

`TrainingReportFilter` er immutable og validerer workflow, tidsstatus, datoer, tidszone og søgning. De samme filtre serialiseres til links og side-navigation. Både totaler og holdrækker kommer fra `TrainingReportingService`.

En SQL-forespørgsel samler holdene med hver sin aggregering af blokke, tilmeldinger og reservationer. Dermed multiplicerer flere blokke eller reservationsmedlemmer ikke pladsforbruget. Databaseklokken `UTC_TIMESTAMP()` er konstant i forespørgslen og bruges både som opgørelsestid og udløbsgrænse. Totaler beregnes fra netop disse rækker før sideinddeling, så hver side viser totalen for hele filterudvalget. Native InnoDB-læsning giver én statement-snapshot; ingen kapacitetslåse holdes under visning.

KPI-links viser holdlisten med samme filtre. Fra hver række kan koordinatoren åbne holdkortet, hvor de bekræftede deltagere og pladsreservationer kan undersøges. Det er et aktuelt opslag, ikke en historisk rapport: nye bookinger og udløb mellem klik kan ændre tallene. Booking- og godkendelseshandlinger udfører altid egen aktuelle kapacitetskontrol under sessionlåsen.

Der vises 50 hold pr. side. Første version tillader højst 5.000 matchende hold i én opgørelse. Større udvalg afvises med krav om et smallere filter; de afkortes aldrig til misvisende totaler. Det er en bevidst hukommelsesgrænse for fælles statement-snapshot, ikke en målt performancegaranti. Negative fripladser eller ugyldig kapacitet udløser invariantfejl, så overbooking ikke skjules som nul.

## Adgang og installation

Adgang kræver intern bruger, standardservice-læseadgang, course/read, session/read og enrollment/read. Entity og autoriserede standardservice-entities anvendes direkte i SQL for både rækker og totaler. Egne-fremmøde-rettigheder alene giver ingen adgang. Kontakt-/økonomirettigheder kontrolleres fortsat på de relevante detailsider.

Upload `dist/module_training-0.7.0.zip` gennem Dolibarrs modulinstallation. Ved opgradering: backup, upload, deaktivér/genaktivér Training for at registrere det nye menupunkt. Ingen nye tabeller eller ændringer til standarddata. Rettigheds-ID'erne 504851–504868 bevares. Målserverens GUI-kontrol står fortsat i issue #3.

CI kontrollerer sommer-/vintertidsdage på 23/25 timer, tidszoneskift ved midnat og hele oversprungne lokale datoer, afvisning af ugyldige filtre, periodens første-start-definition, separate statusmodeller, adgang og entity-/standardservice-scope, multiplikationsfri summer, udløb, vægtet belægning, sammenhæng med holdkort, sideinddeling og afvisning af mere end 5.000 hold. Den officielle Dolibarr-installation kontrollerer menu, native rapport og HTTP-side med den leverede ZIP.
