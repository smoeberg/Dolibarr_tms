# Installationskontrol: Dolibarr 24.0.2

## Automatiseret kontrol

CI opretter en disponibel Dolibarr-installation med den officielle kildekode fra tag `24.0.2`, PHP 8.4 og MySQL 8.0. Databasen deles med de eksisterende integrationstest, men standardinstallationen bruger `llx_`, mens de syntetiske test bruger `tst_`. Ingen produktionsinstallation kontaktes.

`tests/native_install.py` skriver en testkonfiguration, udpakker den leverede `dist/module_training-0.7.0.zip` under standardstien `htdocs/custom/` og kører Dolibarrs egne `install/step2.php` og `install/step5.php`. Disse opretter standardtabeller, referencedata, administrator og installationslås. Konfigurationsskrivning og ZIP-udpakning sker automatisk; installationsguidens formularer og modulets uploadformular betjenes ikke i denne test.

`tests/native_runtime.php` bruger den rigtige `master.inc.php`, `activateModule()` og `unActivateModule()`. Den kontrollerer:

- Modul og afhængigheder aktiveres, 18 rettighedsdefinitioner og modulets to menupunkter registreres.
- Standardtabeller og Training-tabeller findes gennem Dolibarrs egen SQL-loader.
- Standard `Product` opretter kursusservicen; standard `Contact` opretter deltageren. Modulets normale loaders genindlæser dem.
- Kursusversion publiceres, hold åbnes, navngiven deltager reserveres og bekræftes og fremmøde registreres. En intern standardbruger tildeles holdet og får holdet i mine hold.
- Deaktivering og genaktivering bevarer antal rækker i alle Training-tabeller og det registrerede fremmøde.

`tests/native_http.py` starter en lokal PHP-webserver, logger ind gennem Dolibarrs rigtige login med session-cookie og CSRF-token og kontrollerer katalog, holdoversigt, holdkort, fremmøde, undervisertildeling, fakturafordeling, pladsreservationer, holdoverblik og mine hold samt Training-fanen på standardservicekortet. Uden login skal fremmødesiden vise loginformularen og ikke deltageroplysninger. Det er HTTP-kontrol af gengivet indhold, ikke visuel browserkontrol eller en komplet formular-/rettighedstest.

Testene kræver `CI=true` og placering i `.ci/dolibarr`; de er beregnet til CI's disponible installation. Testadministrator og databaseadgang er kun testdata. De må ikke bruges på en rigtig server.

## Kontrol på målserveren – issue #3

CI erstatter ikke kontrol af den konkrete server, dens installerede moduler, filrettigheder, PHP-konfiguration, MySQL-version og eventuelle ID-konflikter. Før første driftsbrug:

1. Tag database- og filbackup, og registrér præcise Dolibarr-, PHP- og MySQL-versioner samt SQL mode og storage engine.
2. Kontrollér det foreløbige modul-ID `504850` og rettigheds-ID'er `504851–504868` for konflikter.
3. Upload ZIP via **Opsætning → Moduler/applikationer → Installer eksternt modul**. Kontrollér at den installeres i `htdocs/custom/training/`.
4. Aktivér Training, kontrollér Services, Tredjeparter og Fakturaer samt kursus-/holdfaner på en standardservice.
5. Giv en intern koordinator relevante Training- og standardrettigheder. Afprøv kursusversion, hold, navngiven tilmelding og fremmøde.
6. Afprøv en intern underviser med egne-fremmøde-rettigheder og en aktiv holdtildeling. Kontrollér at andre hold er afvist og at tilbagekaldelse fjerner adgangen.
7. Deaktivér/genaktivér Training, og kontrollér at data, historik og fremmøde er bevaret.
8. Hvis en tidligere version er installeret, afprøv opgradering i en kopi af installationen og kontrollér data og historik. CI-kontrollen ovenfor er en ren installation, ikke en opgraderingstest.
9. Registrér resultat, dato, tester og fejl i issue #3. Gem eventuelle skærmbilleder uden personoplysninger.

Issue #3 skal fortsat stå åbent, indtil målserverens kontrol er dokumenteret. Online checkout og betaling indgår endnu ikke i den leverede funktionalitet.
