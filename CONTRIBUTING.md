# Arbejdsgang

Hver opgave udvikles på sin egen branch fra den seneste sunde `main`.

1. Opret `feature/<opgave>` eller `fix/<opgave>` før kodeændringer publiceres.
2. Hold ændringen samlet om én konkret leverance. Tilføj relevante tests og opdatér dokumentationen.
3. Opret en pull request mod `main` med ændret adfærd, testresultat og kendte begrænsninger.
4. Kontroller CI og review før merge. Ingen direkte commits eller automatisk merge til `main`.
5. Hold installation og afprøvning på live miljøer som særskilte opgaver.

Denne arbejdsgang er valgt af brugeren. Teknisk branch protection er ikke konfigureret som del af denne ændring.
