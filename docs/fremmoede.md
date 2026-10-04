# Basis-fremmøde og installérbar modulpakke — 0.3.0

## Leverance og standarddata

Fremmøde registreres pr. tilmelding og undervisningsblok. Deltagerens navn kommer fra Dolibarrs standardkontakt via learner-profilen; kursus og hold genbruger den eksisterende service, programversion og session. Modulet opretter ikke parallelle person- eller produktkartoteker.

| Data | Kilde / ejer |
| --- | --- |
| Kursusreference, salgsbeskrivelse, pris og moms | Dolibarr standardservice (`Product`, type 1) |
| Deltageridentitet og kundetilknytning | Dolibarr `Contact` og `Societe` |
| Registrerende / rettende bruger | Dolibarr `User` |
| Program, varighed og historisk kursustitel | Training programversion |
| Undervisningstid og tidszone | Training session og session_slot |
| Deltager på holdet | Training enrollment med standardkontaktlink |
| Fremmødestatus, faktiske tider, minutter, revision | Ny `training_attendance` |
| Ændringshistorik og begrundelse | Eksisterende `training_audit` |

## Regler

- `not_registered` betyder ukendt. Det må ikke tælles som fravær eller nul fremmøde.
- `present` uden tider er en eksplicit attestering af hele blokken. Ingen observeret ankomst/afgang opfindes.
- Ved delvist fremmøde angives begge faktiske tider med dato og UTC-offset. De skal ligge inden for blokken og passe med holdets IANA-tidszone. Sen ankomst giver `late` og beregnede forsinkelsesminutter.
- `absent` og `excused` har nul fremmødeminutter og ingen observerede tider. MVP måler én sammenhængende tilstedeværelsesperiode; pauser og flere delintervaller er ikke modelleret.
- Ny registrering kræver bekræftet tilmelding og et åbent eller lukket hold. Afmelding sletter aldrig tidligere fremmøde. Historiske registreringer kan rettes efter afmelding.
- En rettelse kræver både skriveret og særskilt rettelsesret plus begrundelse. Nulstilling bevarer historik og returnerer minutter til ukendt.
- Formularens revision sammenlignes under holdets fælles transaktionslås. En forældet formular afvises med konflikt; brugeren skal genindlæse og vurdere den nye registrering. En uændret registrering med aktuel revision er en no-op.
- Dataændring og audit er i samme transaktion. Fejl i audit ruller ændringen tilbage.
- Kontaktadgang og aktiv entity gælder for ark, historik og ændringer. Utilgængelige kontakters fremmøde vises ikke.

`TrainingAttendanceService` er eneste skrivevej. UI har ingen selvstændig forretningslogik. Fremmøde findes via link på hver blok i holdkortet. Se [UI-opdateringen 0.4.1](fremmoede-ui.md) for den nye dato/tid-vælger og læsbare historik. Historik viser før/efter, begrundelse, tidspunkt og standardbruger-ID.

## Rettigheder

Interne brugere skal have standardrettighed til at læse services og kontakter samt Training kursuslæsning og holdlæsning. Derudover:

| Ret | Formål |
| --- | --- |
| attendance/read | Læs ark og historik for tilgængelige kontakter |
| attendance/write | Første registrering |
| attendance/correct | Rettelser og nulstilling, sammen med write |

Tilmeldings- og holdskriverettigheder giver ikke automatisk fremmøderettigheder. Rettigheder er entity-afgrænsede, men der er endnu ingen undervisertildeling til bestemte hold; giv derfor adgang til koordinatorer, ikke en bred undervisergruppe. Certifikater, bedømmelser og fremmøde-KPI'er er ikke implementeret her.

## Direkte installation i Dolibarr

Download [module_training-0.3.0.zip](../dist/module_training-0.3.0.zip) ved at åbne filen på GitHub og vælge **Download raw file**. Brug denne ZIP, ikke GitHubs generelle “Download ZIP” af hele repository'et.

1. Brug først en testinstallation med Dolibarr 24.0.2, PHP 8.4 og MySQL/InnoDB. Tag backup ved opgradering.
2. Som administrator: **Opsætning → Moduler/Applikationer → Installer/deploy eksternt modul**; upload ZIP-filen.
3. Dolibarr placerer `training/` i `htdocs/custom/training`. Aktivér Services og Tredjeparter samt Training.
4. Ved opgradering: deaktivér/genaktivér Training, så manglende tabeller og nye rettigheder bliver registreret. Deaktivering beholder data.
5. Tildel rettigheder. Opret/åbn en standardservice og brug fanerne Kursus og Hold. Åbn et hold og en undervisningsblok for fremmøde.

Pakken har Dolibarrs eksterne modulstruktur: `training/core/modules/modTraining.class.php`, `sql/`, `langs/`, `class/`, `lib/` og sider. Installation sker via standarddescriptorens `init()`/`_load_tables()`; ingen kernefiler ændres og ingen ekstra Composer- eller npm-installation er nødvendig.

Modulnummer 504850 og rettighedsnumre 504851–504860 er foreløbige private numre. Kontroller lokalt modulnummerkonflikt før installation; aktivering afviser rettighedskonflikter. Ekstern offentlig distribution kræver senere afklaring af officielt modulnummer. Serveren skal tillade eksterne moduler og skrivning i standardmappen `custom`; alternative eksterne modulrødder understøttes ikke af bootstrap endnu.

## Verifikation

CI på PHP 8.4 og MySQL 8.0 kontrollerer PHP-syntaks, enhedsregler, MySQL-skema med anden tabelpræfiks, genaktivering, rollback, rettigheder, entity, afmelding/historik og samtidige fremmøderegistreringer fra separate databaseforbindelser. Eksisterende kursus-, booking- og kapacitetstests fortsætter.

Pakketesten sammenligner hver ZIP-fil med kilden, kontrollerer sikre stier og deterministisk genbygning. En PHP-test udpakker den distribuerede ZIP og indlæser descriptoren med **Dolibarr 24.0.2's rigtige `DolibarrModules`-klasse**. Dette er format-/descriptorverifikation; det er ikke en komplet GUI-installationstest på kundens server. Upload, aktivering og hele UI-forløbet på det konkrete målmiljø skal verificeres før produktionsbrug.

Genbyg: `python3 tools/build_module.py`. Kontroller: `python3 tests/package.py`. Ved ændringer i modulkilden skal ZIP-filen genbygges og committes i samme PR; CI afviser en forældet pakke.

Officielt grundlag: [Dolibarr 24.0.2 ekstern modulinstallation](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/admin/modules.php) og [ModuleBuilder-skabelon](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/modulebuilder/template/README.md).
