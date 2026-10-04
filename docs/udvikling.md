# Første kodeleverance — 0.1.0

Mål: Dolibarr 24.0.2, PHP 8.4.26 og MySQL. MySQL-serverversionen er endnu ikke oplyst.

## Implementeret

- Eksternt modul `training` og tre særskilte rettigheder: læs, opret programkladde, publicér.
- Kursusfane på standardservices. Produkter og eksterne brugere kan ikke bruge siden.
- Kursusprofil med entydigt link til standardservice pr. aktiv entity. Autoriseret deling af standardservice respekteres; moduldata forbliver i aktiv entity.
- Ordnet program med mål, forudsætninger, målgruppe, undervisningsform og undervisningsminutter. UI opretter én programdel; service-laget understøtter flere.
- Ny kladde som ny programversion. Denne leverance har endnu ingen redigering, arkivering eller sletning.
- Publicering med historisk snapshot af standardservicens reference, titel og salgsbeskrivelse. Aktuel titel, pris og moms vedligeholdes fortsat på standardservicen.
- Alle writes går gennem `TrainingCatalogService`. Version og audit skrives i samme lokale transaktion. Gentagen publicering erstatter ikke snapshot.
- InnoDB-schema med unique keys, FK til standardservice og composite entity-FK mellem profil og version.
- PHP-tests og en GitHub Actions-workflow på PHP 8.4 med syntetisk MySQL 8.0. Testdatabasen er separat og må aldrig pege på en live installation.

Der er ingen checkout, hold, tilmelding, fremmøde, kapacitetsmotor eller fakturering endnu. Version 0.1.0 er starten på Fase 1A, ikke en færdig driftsleverance.

## Installation i et testmiljø

1. Tag backup. Kontrollér Dolibarr 24.0.2, PHP 8.4.26, MySQL-serverversion, databaseprefix og InnoDB på standardtabellen `product`.
2. Kontrollér at det foreløbige modul-ID **504850** og rettigheds-ID'er **504851–504853** er ledige. Modulet afviser observerede rettighedskollisioner, men modul-ID'et er endnu ikke officielt reserveret.
3. Kopiér repoets `htdocs/custom/training` til installationens `htdocs/custom/training`. Bootstrap forudsætter denne placering; andre external-root-placeringer er endnu ikke understøttet.
4. Aktivér Dolibarrs Services-modul og derefter Training under Opsætning → Moduler. SQL-loaderen erstatter `llx_` med installationens databaseprefix. Aktivér ikke modulet ved manuelt at sætte en konstant.
5. Tildel kursusrettigheder og standardrettigheden til at læse services. Publicering har sin egen rettighed. Modulet ændrer ikke standardservicens data.
6. Åbn en service og fanen Kursus. Opret en kladde med mål, målgruppe og programdel; publicér.
7. Ændr standardservicens titel, og kontrollér at den publicerede versions snapshot er bevaret. Opret en ny kladde for et ændret program.
8. Prøv med læsebruger, bruger uden kursusrettighed, ekstern bruger og en anden entity. Bekræft afvisning af fremmede versioner og ingen adgang via et manipuleret ID.
9. Kontrollér manglende/forkert CSRF-token, escaping af tekst samt audit og rollback. Deaktivér/genaktivér og kontrollér at data er bevaret.

Ingen af disse installationsprøver er udført på eira-systems.eu. Installation og aktivering dér er ikke en del af denne kodeændring.

## Tests

```sh
find htdocs tests -name '*.php' -print0 | xargs -0 -n 1 php -l
php tests/run.php
```

MySQL-testen kræver mysqli, en isoleret database ved navn `training_test` og miljøvariablerne `TRAINING_TEST_MYSQL_HOST` / `TRAINING_TEST_MYSQL_PASSWORD`. Den bruger og nulstiller kun `tst_*`-tabeller i denne database. CI bruger en dedikeret, kortlivet container.

Unit/service-tests dækker varighed, fejlagtige værdier, adgang, entity, snapshot, idempotent publicering og auditrollback. MySQL-tests dækker faktisk DDL, gentagen schemaoprettelse, prefix, constraints og lokale transaktioner. De anvender en testadapter og en syntetisk service; de erstatter **ikke** test af Dolibarrs DoliDB/Product, tab-rendering, CSRF eller modulaktivering.

PHP-runtime er ikke installeret i agentens arbejdsrum. PHP-kørsel udføres via repoets CI; seneste workflowresultat skal kontrolleres før installation. MySQL 8.0 i CI er en testmatrix, ikke en antagelse om brugerens databaseversion. Fremtidige schemaændringer kræver en versionsstyret opgraderingsmigration; initialschemaets `IF NOT EXISTS` opgraderer ikke eksisterende kolonner.

## Verificerede kildekontrakter

Kontrolleret mod tag `24.0.2` ved udviklingen:

- [Moduldescriptor-template](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/modulebuilder/template/core/modules/modMyModule.class.php): tabs, rettigheder, dependencies, aktivering/deaktivering.
- [DolibarrModules](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/core/modules/DolibarrModules.class.php): SQL-loader, prefix og installation.
- [Product card](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/product/card.php): Product::TYPE_SERVICE, service/lire og restrictedArea.
- [Functions](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/core/lib/functions.lib.php): tabs bruger `$objectoffield` i condition.
- [Main bootstrap](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/main.inc.php): CSRFCHECK_WITH_TOKEN og sessions-token.

Kildekontrol er udført; runtimekompatibilitet afventer installationstest.

## Næste lodrette leverance

Hold → undervisningsblokke → learner-link til standardkontakt → tilmelding → kapacitetslås og samtidighedsprøve. Derefter økonomilinjerelationer og basisfremmøde. Online tilmelding gennem Website er Fase 1B og bruger det samme servicelag.
