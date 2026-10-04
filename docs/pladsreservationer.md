# Navngivne pladsreservationer – Training 0.6.0

Dette er reservations- og kapacitetsgrundlaget til det kommende online checkout samt et internt koordinatorværktøj. Der er endnu ingen offentlig reservation, ordreoprettelse, prisaftale eller betalingskontrol. En koordinator kan godkende en reservation administrativt; det betyder bekræftet tilmelding, ikke betalt tilmelding.

## Registrering og standarddata

På holdkortet findes **Pladsreservationer**. Søg blandt standardkontakter, markér deltagere og reservér dem i 15 minutter. Hver deltager skal være en aktiv, autoriseret standardkontakt. Ingen unavngivne pladser og ingen kopier af navn/e-mail oprettes.

| Data | Ejer / registrering |
| --- | --- |
| Kursusidentitet og salgsdata | Standard `Product` af typen service |
| Deltageridentitet | Standard `Contact` (`socpeople`) |
| Reservation, antal, frist, status | `training_seat_hold`, skrevet af EnrollmentService |
| Reservationens deltagere | `training_seat_member` med standardkontakt-FK |
| Bekræftet deltagelse | Eksisterende learner/enrollment; medlemmet får FK til tilmeldingen ved konvertering |
| Betaling, køber, aftalepris, ordre | Ikke registreret af denne prototype; kommende økonomi-/checkoutleverance |

Reservationen opretter medlemsrelationer til standardkontakter. Den opretter først learner/tilmelding ved godkendelse. Arkitekturens senere `reserved`-tilmeldinger og checkoutrelationer er derfor ikke implementeret endnu. Medlemsrelationen bevarer den navngivne gruppesammensætning og forbindes med den eksisterende tilmelding ved konvertering. Persondata læses fra standardobjekterne og følger deres adgangsregler.

Adgang kræver intern bruger, standardservice- og kursusadgang, session/read, enrollment/read samt standardkontaktadgang. Oprettelse, godkendelse og frigivelse kræver også enrollment/write og adgang til alle berørte kontakter. Underviserens egne-fremmøde-rettigheder giver ingen reservationsadgang. Der tilføjes ingen nye rettigheds-ID'er.

## Kapacitetsregel

`ledig = kapacitet − confirmed-tilmeldinger − qty på aktive, ikke udløbne reservationer`.

Aktiv betyder `status='active' AND expires_utc > beslutningstid`. Ved præcis frist regnes reservationen som udløbet. Bekræftede tilmeldinger og reservationer summeres separat uden join, der multiplicerer rækker. Udløb frigiver plads, selv om ingen baggrundsjob kører. Antal bekræftede, reserverede og ledige vises særskilt på holdkort og reservationsside. Visningen er vejledende; hver skrivehandling genlæser under lås.

Alle kapacitetsændringer bruger samme `session FOR UPDATE`-mutex: administrativ tilmelding/afmelding, kapacitetsændring, reservation, frigivelse og konvertering. Beslutningstid hentes fra MySQL `UTC_TIMESTAMP()` efter holdlåsning. Kapacitet læses med aktuelle locking reads; der bruges ikke en gammel COUNT-snapshot. Flere kontaktlåse tages i stigende standardkontakt-ID-orden.

- En deltager kan ikke både være confirmed og få en ny reservation på samme hold.
- En aktiv reservation for samme deltager blokerer anden reservation og enkeltvis administrativ bekræftelse. Brug reservationens samlede godkendelse eller frigiv den først.
- Reservation af en gruppe lykkes helt eller afvises helt.
- Gyldig konvertering trækker eget hold ud af H og lægger de nye tilmeldinger i E i samme transaktion.
- Konvertering efter udløb kræver ny kapacitetskontrol uden fradrag af den gamle reservation. Et lukket hold kan ikke godkendes.
- Hvis en anden aktiv reservation eller bekræftelse nu tilhører samme deltager, afvises den gamle gruppes godkendelse. Den overtager ikke andre aftaler.
- Fremmøde, fakturaer og priser ændres ikke af reservation eller frigivelse.

## Status, gentagelse og historik

| Handling | Fra | Til / virkning |
| --- | --- | --- |
| Reservér | Åbent hold og ledig kapacitet | active, frist og navngivne medlemmer |
| Tiden når fristen | active | Vises som expired; stored status forbliver active, pladsforbrug er nul |
| Godkend | active, også udløbet, med åben session og plads | converted + alle tilmeldinger confirmed |
| Frigiv | active, også udløbet eller lukket session | released; ingen pladsforbrug |
| Gentag godkendelse | converted | Samme tilmeldings-ID'er; ingen ny booking eller audit |
| Gentag frigivelse | released | Ingen ændring |

En godkendt reservation kan ikke frigives: afmeld de enkelte tilmeldinger med eksisterende afmeldingsfunktion. En gammel godkendelsesretry genopliver ikke en senere afmeldt deltager. En ny reservation med ny nøgle kan derimod eksplicit genbekræfte den eksisterende afmeldte tilmelding.

Oprettelse bruger en idempotensnøgle pr. entity + hold og hash af sorteret deltagerliste + varighed. Gentagelse med samme input returnerer samme reservation uden forlænget frist, også efter udløb/frigivelse/godkendelse. Ændret input med samme nøgle afvises. Nøglen er intern anmodningsidentitet, ikke en offentlig adgangstoken eller en implementeret checkout-ID.

UI-fristen er fast 15 minutter. Det interne serviceinterface accepterer 1–60 minutter og 1–100 forskellige standardkontakt-ID'er. Browserens liste viser højst 50 søgeresultater ad gangen; den er ikke en offentlig checkoutformular. Alle handlinger kræver Dolibarr-session og CSRF-kontrol. Godkendelse/frigivelse kræver begrundelse. Audit, medlemsrelationer, tilmeldinger og statusændring committes samlet eller rulles tilbage samlet. Historik viser aktør, tid, hændelse og begrundelse; konverteringsaudit registrerer om den skete efter udløb.

## Installation og kontrol

Upload `dist/module_training-0.6.0.zip` via standardinstallationen. Ved opgradering: backup, upload, deaktivér/genaktivér Training. To nye tabeller oprettes additivt; ingen eksisterende kolonner eller standardtabeller ændres. Deaktivering bevarer reservationer og historik. ID-konflikter og målserverens GUI-kontrol skal fortsat verificeres, se issue #3 og installationskontrol.md.

CI tester MySQL-skema med ikke-standardpræfiks, gentagen installation, idempotens, udløb uden job, senere godkendelse, kontakt-/entity-/rettighedsafvisning, atomisk rollback ved auditfejl samt samtidige, uafhængige PHP/MySQL-forbindelser. Kapløb omfatter sidste plads mod adminbooking, to grupper om tre pladser, gentagen nøgle, kapacitetsreduktion, konvertering versus admin og konvertering versus frigivelse. Den officielle Dolibarr-installation bruger den nye ZIP og tester reservation → godkendelse → fremmøde samt reservationssidens HTTP-visning samt faktisk formularindsendelse, dublet-submit og gruppegodkendelse.

## Resterende før online drift

Checkout skal tilføje verificeret identitets-/kundematch, køber/betalerroller, serverberegnet og accepteret prissnapshot, standardordre/fakturalinjer, offentlig adgangs- og misbrugsbeskyttelse, bookingpolitik samt idempotent outbox og afstemning. Betalingsadapteren skal verificere betaling; en success-URL må ikke kalde den administrative godkendelse. Betaling modtaget efter udløb skal registreres korrekt, selv om kapacitetskontrollen afviser plads. Det betalingsforløb er ikke bygget i 0.6.0.
