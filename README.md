# RAG v Symfony: proč chatbot nad vašimi daty lže

Ukázka a měření k článku a videu **„Proč chatbot nad vašimi daty lže? RAG v Symfony od embeddingu po citaci zdroje“** ([blog.katuscak.cz](https://blog.katuscak.cz)).
Navazuje na [chatbot-s-podklady](https://github.com/MichalKatuscak/chatbot-s-podklady) (video „Chci AI chatbota“), kde hledání bylo 12 vektorů v poli v paměti.

Tady je to „doopravdy“: Symfony 8, Postgres s pgvectorem, indexace přes Messenger, český fulltext, hybridní hledání, reranker, citace ověřené kódem. Hlavně ale **měření**. Každé číslo v článku i ve videu vzniklo příkazem z tohoto repa a výsledky jsou ve složce [`vysledky/`](vysledky/).

E-shop Mlýnek (mlynek.test) je vymyšlený. Podklady ve složce [`podklady/`](podklady/) (41 dokumentů, 284 sekcí) i sadu otázek v [`otazky/sada.yaml`](otazky/sada.yaml) psali dva různí autoři: autor otázek neviděl kód hledání ani výsledky.

## Co se měří

| # | Měření | Příkaz | Výsledek |
|---|---|---|---|
| E1–E3 | Které hledání najde správný úryvek: vektor, fulltext (bez tvarů / s českým Hunspellem), hybrid přes RRF, reranker. Každá otázka s diakritikou i bez. Metriky hit@1, hit@5, MRR s 95% intervaly (bootstrap). Rozložení skóre a jestli jde podle něj poznat otázku mimo podklady. | `app:mereni:hledani` | `vysledky/hledani.json` |
| E4 | Odpovědi modelu: správně, špatně, „nevím“, vymyšlené odpovědi na otázky mimo podklady. Kontrola citací kódem (úsek existuje, citát je v úryvku doslova, čísla z odpovědi jsou v citovaném textu). Rozpad chyb: chybělo hledání, nebo model? | `app:mereni:odpovedi --model maly\|velky` | `vysledky/odpovedi-*.json` |
| E5 | Tiché ořezání v Ollamě: `/api/embed` s `truncate` true/false a `/api/chat` s dlouhým kontextem bez `num_ctx`. Přežije pravidlo ze system promptu a fakt z prvního úryvku? | `app:mereni:orezani` | `vysledky/orezani-*.json` |
| E6 | Přeindexování změněného dokumentu (kávovary 5 → 7 pracovních dnů, PPL 129 → 139 Kč): Symfony AI Store 1.0.0-BETA1 vs. deterministická ID úryvků. | `app:mereni:preindexovani`, `experimenty/symfony-ai-store/run.php` | `vysledky/preindexovani.json`, `vysledky/symfony-ai-store.json` |
| E7 | Interní dokumenty a podvržený „pokyn pro AI“ v recenzi: bez filtru, filtr v PHP až po LIMIT, filtr v SQL. | `app:mereni:opravneni` | `vysledky/opravneni-*.json` |
| E8 | Přesné hledání vs. HNSW na 10k a 100k vektorech, HNSW s filtrem (iterative scan), výměna modelů v paměti GPU. | `app:mereni:vykon` | `vysledky/vykon.json` |

## Spuštění

Potřebujete Docker a [Ollamu](https://ollama.com) nainstalovanou přímo v systému (kvůli GPU). Postgres, PHP a reranker běží v Dockeru.

**Windows (PowerShell):**

```powershell
.\mereni.ps1
```

**Linux / macOS:**

```bash
./mereni.sh
```

Skript stáhne modely (`bge-m3`, `gemma4:e2b`, `gemma4:12b`), spustí Postgres a reranker (s NVIDIA GPU přes `compose.gpu.yaml`), zaindexuje podklady přes Messenger a pustí měření ve dvou fázích: `bin/mereni hledani` (E1–E3, s rerankerem), pak reranker zastaví, aby jazykové modely měly celou paměť GPU, a `bin/mereni ostatni` (E4–E8). Reranker na CPU zvládne E1–E3 taky, jen to trvá hodiny. Jen malý model: `$env:MODELY = "maly"` / `MODELY=maly ./mereni.sh`.

Jednotlivě:

```bash
docker compose up -d db reranker
docker compose run --rm app bin/console app:schema --drop
docker compose run --rm app bin/console messenger:setup-transports
docker compose run --rm app bin/console app:index                       # dokumenty → fronta
docker compose run --rm -e WORKER_STOP_WHEN_IDLE=1 app bin/console messenger:consume async
docker compose run --rm app bin/console app:zeptej "Za jak dlouho pošlete pákový kávovar?"
```

## Jak je to postavené

```
podklady/*.md ──► MarkdownChunker ──► Indexer (Messenger handler) ──► chunk (Postgres)
                  úryvek = sekce        hash beze změny → nic          id = UUID v5 (dokument, sekce, část)
                  „Dokument › Sekce“    embedding jen pro změněné       embedding vector(1024), tsvector ×2
                                        upsert + smazání chybějících    pristup, zdroj, embedding_model

otázka ──► Search (jeden SQL dotaz, filtr oprávnění ve WHERE)
           vektor <=>  |  fulltext cs_simple / cs_lemma  |  RRF k = 60  |  reranker (llama.cpp)
       ──► AnswerService: úryvky [U1]…[U5], JSON {nevim, odpoved, citace[{usek, citat}]}
       ──► CitationVerifier: úsek existuje? citát doslova? čísla v citovaném textu?
```

- [`src/Indexing/MarkdownChunker.php`](src/Indexing/MarkdownChunker.php): úryvky podle nadpisů, deterministická ID.
- [`src/Indexing/Indexer.php`](src/Indexing/Indexer.php): invariant „v indexu je pro každý dokument právě jedna verze“.
- [`src/Search/Search.php`](src/Search/Search.php): všechny varianty hledání, RRF v SQL.
- [`docker/db/init.sql`](docker/db/init.sql): český fulltext (Hunspell + unaccent), Postgres vlastní český stemmer nemá.
- [`src/Answer/CitationVerifier.php`](src/Answer/CitationVerifier.php): kontrola citací bez dalšího modelu.

Symfony AI (1.0.0-BETA1) se tu používá jen v experimentu E6. Proč, viz článek.

## Výsledky

Měřeno 1.–2. 11. 2026 na Windows 11 s RTX 4060 8 GB (Ollama 0.40.3, bge-m3, gemma4:e2b a gemma4:12b, temperature 0, seed 42). Dva plné běhy daly stejná čísla. Podrobnosti v [`vysledky/`](vysledky/), verze v [`vysledky/meta.json`](vysledky/meta.json).

**Hledání** (91 otázek, každá s diakritikou i bez):

| Varianta | hit@5 | MRR |
|---|---|---|
| vektor bge-m3 | 0,88 | 0,80 |
| hybrid vektor + fulltext (RRF) | 0,84 | 0,70 |
| fulltext s českým Hunspellem | 0,70 | 0,48 |
| hybrid + reranker | **0,98** | **0,88** |

Hybrid byl horší než samotný vektor, český slovník fulltextu uškodil, diakritika bge-m3 nevadí. Pomohl reranker.

**Odpovědi** (91 otázek s odpovědí v podkladech, 26 mimo podklady):

| Model / hledání | správně | chyb, kde chyběl správný úryvek | mimo podklady řekl „nevím“ |
|---|---|---|---|
| gemma4:e2b / vektor | 70 | 12 z 21 | 17 z 26 |
| gemma4:12b / vektor | 79 | 11 z 12 | 23 z 26 |

**Tiché chyby:** `/api/embed` s bge-m3 zpracuje jen 2 048 tokenů, dokud nenastavíte `num_ctx` i `num_batch`; `/api/chat` bez `num_ctx` ořízne dlouhý prompt i se system promptem; Symfony AI Store 1.0.0-BETA1 po přeindexování drží starou i novou verzi dokumentu; HNSW s filtrem bez `iterative_scan` vrátí místo 10 řádků průměrně 3,9.

Vyhodnocení jsme po prvním běhu opravovali (kontrola citací, rozpoznání „nevím“, porovnání čísel). Odpovědi modelů se nezměnily, jen to, jak je kód hodnotí; přepočet dělá `app:prepocet` a každý soubor má pod klíčem `prepocet` důvod a původní souhrn.

## Licence

MIT. Podklady e-shopu Mlýnek jsou fikce.
