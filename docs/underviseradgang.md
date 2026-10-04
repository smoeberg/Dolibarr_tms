# Undervisertildeling og hold-specifik fremmødeadgang — 0.5.0

Implementering af [issue #5](https://github.com/smoeberg/Dolibarr_tms/issues/5). En underviser er en profil knyttet til en eksisterende intern **Dolibarr User**. Navn, login, aktivering, kontakt- og kundeadgang og rettigheder forbliver standarddata. Modulet opretter ingen nye brugerkonti eller parallelle personnavne.

## Data og adfærd

| Data | Ejer |
| --- | --- |
| Brugerkonto, login, navn, aktiv/inaktiv, intern/ekstern | Dolibarr `user` |
| Hvilke standardbrugere koordinatoren må se | Standard bruger-læseret og `getEntity('user')` |
| Underviserprofil og standardbrugerlink | `training_trainer` med unik `(entity, fk_user)` |
| Tildeling til hold, aktiv/fjernet, revision | `training_trainer_assignment` |
| Før/efter, begrundelse, koordinator og tidspunkt | Eksisterende `training_audit` |
| Fremmøde og historik | Eksisterende fremmødeservice og standardkontaktlinks |

En koordinator åbner **Undervisere og holdadgang** fra holdkortet, finder en aktiv intern standardbruger og tildeler med begrundelse. En tildeling kan fjernes/genaktiveres med begrundelse; den samme række og profil genbruges. Revision beskytter mod forældede formularer. Identiske aktuelle kald ændrer ikke audit.

Underviseren bruger **Mine undervisningshold** i produkt/service-menuen eller `/custom/training/myattendance.php`. Siden viser kun vedkommendes aktive tildelinger i aktiv entity og links til holdets undervisningsblokke. Hold-/produktadgang og kontaktadgang genkontrolleres i servicelaget; en menulinje eller et skjult link er ikke adgangskontrollen.

## Rolleopsætning

| Rolle | Training-rettigheder | Standardrettigheder |
| --- | --- | --- |
| Underviser, læsning | course/read + ownattendance/read | service/lire + societe/contact/lire og relevant kundeadgang |
| Underviser, registrering | Ovenstående + ownattendance/write | Som ovenfor |
| Underviser, rettelser | Ovenstående + ownattendance/correct | Som ovenfor |
| Koordinator, fremmøde på alle tilgængelige hold | course/read + session/read + attendance/read/write/correct | Standard service-/kontakt-/kundeadgang |
| Koordinator, tildeling | course/read + session/read + trainer/read/write | service/lire + user/user/lire; bruger-entity skal være tilgængelig |

**Giv ikke underviserrollen de globale `attendance/*`-rettigheder eller generel hold-/booking-/økonomisk adgang.** De eksisterende globale fremmøderettigheder er fortsat koordinatorrettigheder og kræver ingen tildeling. En underviser skal have både ownattendance-rettighed og aktiv tildeling; ingen af delene alene er nok. Rollen giver ikke adgang til standardkontakter/kunder, som Dolibarr ellers afviser.

Standard `user` entity-scope kommer fra `getEntity('user')`, inklusive global entity 0 i UI-konteksten. API/servicekontekster bør levere den eksplicit tilladte user-scope til `TrainingAccess`; uden den er scope begrænset til aktiv entity. Kontakters og services' egne scopes er uændrede.

## Fjernelse, historik og samtidighed

- Fjernet tildeling fjerner både læsning, registrering, rettelser og historik for egen-hold-rollen. Det gælder også direkte servicekald og gamle links/formularer.
- Lukkede historiske hold er fortsat tilgængelige ved aktiv tildeling. Der er ikke automatisk udløb ved holdets slutdato. Koordinatoren skal fjerne tildelingen, når adgangen skal stoppe.
- Afmelding og fjernelse af underviseren sletter aldrig fremmøde eller audit. Koordinatorens adgang til historikken fortsætter.
- En deaktiveret standardbruger, ekstern brugerkonto, deaktiveret trainer-profil eller user-entity uden for scope får ikke egen-hold-adgang. UI leverer endnu ikke separat profil-deaktivering; standardbrugerens aktivering og holdtildeling er de administrative greb i denne MVP.
- Før en fremmødeændring genkontrolleres tildelingen **inden for holdets transaktionslås**, med aktuelle locking reads af assignment/profil/standardbruger. Fjernelse bruger samme holdlås. Et fremmødekald kan derfor ikke committe efter en allerede committet fjernelse.
- Tildeling/profilændring og audit er transaktionelle. Auditfejl ruller også en ny profil tilbage.
- Profiler har foreign key til standardbrugeren. Fysisk sletning af en tilknyttet bruger er blokeret; deaktivér brugeren og fjern tildelinger for at bevare historikken.

Service-laget håndhæver adgang til ark, historik, handlinger og egne-hold-listen. UI spørger servicen om læse-/skrive-/rettelsesadgang og tilbyder kun tilladte handlinger. Eksisterende token-, revisions-, tidszone- og kontaktkontroller fortsætter.

## Installation og grænser

Upload [module_training-0.5.0.zip](../dist/module_training-0.5.0.zip) via Dolibarrs standardinstallation. Tag backup og deaktivér/genaktivér Training ved opgradering, så additive tabeller og fem nye rettigheder registreres. Eksisterende rettigheds-ID'er ændres ikke; nye private ID'er er 504864–504868. Menupunktet bruger Dolibarrs standard modul-menu-definition. Ingen kernefiler eller standardbrugerdata ændres.

Denne leverance modellerer adgang og tildeling til hele hold, ikke ressourcebooking, undervisertimer, løn, konfliktkontrol eller adgang til udvalgte blokke. De øvrige modulefunktioner fra 0.4.1 indgår i pakken.

## Verifikation

CI bruger PHP 8.4/MySQL 8.0 og den rigtige `user`-tabeldefinition fra Dolibarr 24.0.2. Tests dækker direkte servicekald, egen-hold-rolle uden globale rettigheder, ikke-tildelte hold, andre brugere/entities, inaktive/eksterne konti, standardkontaktgrænser, særskilt rettelsesret, fjernelse/genaktivering, historiske hold, profilgenbrug, auditrollback og samtidige fremmøde-/fjernelseskald med separate forbindelser. De eksisterende økonomi-, tidszone-, kapacitets-, fremmøde- og pakketests fortsætter.

Fuld målserverinstallation, menuvisning og browser-/rolleflow skal fortsat udføres som [issue #3](https://github.com/smoeberg/Dolibarr_tms/issues/3). Modulets private ID'er skal kontrolleres for lokale konflikter før produktion.

Officielt datagrundlag: [Dolibarr 24.0.2 standard user-skema](https://github.com/Dolibarr/dolibarr/blob/24.0.2/htdocs/install/mysql/tables/llx_user.sql).
