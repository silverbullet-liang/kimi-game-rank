# Kimi Game Rank · kimi-game-rank

[English](README.md) · [简体中文](README.zh-CN.md) · [繁體中文](README.zh-TW.md) · [日本語](README.ja.md) · [한국어](README.ko.md) · **Italiano** · [Français](README.fr.md)

Una classifica a punteggio su sei dimensioni per la **community di Kimi**. PHP + MySQL, frontend senza build (HTML / CSS / JavaScript puri).

[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)

> Questo è un **progetto non ufficiale**. Non ha alcun legame con Kimi o Moonshot AI.

---

## Di cosa si tratta

Un sito che raccoglie giochi, strumenti, opere letterarie e lavori amatoriali della community, li valuta su sei dimensioni e li mette in classifica. Il punteggio è calcolato automaticamente da un algoritmo; l'amministratore può correggere manualmente singole opere.

## Funzionalità

| Modulo | Descrizione |
|---|---|
| Classifiche | 4 categorie × 4 classifiche (Generale / Voti / Leggende / Gemme nascoste), badge di posizione ed etichette di valutazione |
| Dettaglio opera | Grafico radar a sei dimensioni, dettaglio per dimensione, descrizione e commenti (risposte annidate senza limiti) |
| Chat | Chat mondiale (polling in tempo reale) + chat con l'AI (output in streaming, ricerca sul web, regole del sito iniettate) |
| Profilo | Avatar, utilizzo dell'AI, informazioni sull'IP e registro delle visite, impostazioni di aspetto |
| Accesso | Login con password / registrazione / modalità ospite, con una verifica umana a un clic in accesso e registrazione |
| Moderazione dei contenuti | Commenti, chat mondiale e immagini caricate vengono controllati automaticamente prima della pubblicazione, con canali di riserva |
| Pannello di amministrazione | Acquisizione e aggiornamento in blocco, backup del database, moderazione dei commenti, gestione dei tag, co-amministratori |
| Risoluzione intelligente dei link | Attiva per impostazione predefinita: se la pagina di un'opera è solo una pagina di reindirizzamento, viene usato l'indirizzo reale dietro di essa |
| Segnalazioni disciplinari | Segnala un utente con un clic: più motivi, durata del blocco (anche permanente), blocco della provenienza, rimozione dei suoi commenti / chat / immagini. Chi è segnalato viene inviato con 302 a una pagina di violazione con i motivi e i commenti |
| Temi | Diversi temi visivi; i caratteri tipografici sono scaricati dal browser direttamente da CDN pubbliche, senza consumare traffico del tuo server |

## Stack tecnico e requisiti

- **PHP ≥ 7.0** (la base del codice è la 7.0; non si usa sintassi della 7.1 o superiore)
- **MySQL / MariaDB** (InnoDB, `utf8mb4`)
- Frontend: sorgenti in moduli ES → singolo file compilato, **nessun runtime Node necessario** (solo in fase di build)
- Nessuna dipendenza Composer, nessun framework frontend

## Distribuzione

```bash
git clone https://github.com/silverbullet-liang/kimi-game-rank.git
cd kimi-game-rank

# 1) Configurazione principale: copia il modello e inserisci la connessione al database
cp config/config.sample.php config/config.php
$EDITOR config/config.php

# 2) Pool di chiavi API: copia il modello e aggiungi le tue chiavi
cp config/api_keys.sample.php config/api_keys.php
$EDITOR config/api_keys.php

# 3) Liste di moderazione: quelle ufficiali non sono pubbliche, parti dai campioni
cp app/data/moderation_words.sample.txt app/data/moderation_words.txt
cp app/data/moderation_allow.sample.txt app/data/moderation_allow.txt
```

Punta la root del sito alla directory principale di questo progetto e apri la homepage nel browser: **l'inizializzazione è automatica** (crea database, tabelle e chiavi). Se il database non è pronto, la pagina lo segnala chiaramente.

> Se le liste di parole mancano, il modulo di moderazione passa automaticamente a "non bloccare nulla" e il sito continua a funzionare.

## Configurazione

| File | Nel repository? | Descrizione |
|---|---|---|
| `config/config.php` | ❌ **non committare mai** | Connessioni al database (principale + amministrazione), informazioni sul sito, chiavi AES / cron, credenziali dell'amministratore |
| `config/api_keys.php` | ❌ **non committare mai** | Pool di chiavi API dei modelli (Zhipu, OpenRouter) |
| `app/data/moderation_words.txt` | ❌ **non committare mai** | Lista di moderazione (quella ufficiale non è pubblica) |
| `config/config.sample.php` | ✅ | Modello di configurazione principale |
| `config/api_keys.sample.php` | ✅ | Modello del pool di chiavi |

Le tre voci ❌ sono già elencate nel `.gitignore`. Prima di committare, esegui il controllo delle fughe di dati:

```bash
python3 tools/repo_check.py
```

Verifica tre cose: se file vietati sono finiti nell'area di staging, se nel testo compare qualche impronta di chiave, e **se sono trapelati valori di connessione al database**.

## Struttura delle directory

```
├── api/            Livello endpoint (ingressi JSON: autenticazione + CSRF + limite di frequenza)
├── app/            Livello applicativo (configurazione, database, punteggi, autenticazione, moderazione, canali AI…)
│   └── data/       Liste di moderazione (si conservano solo i file .sample)
├── assets/
│   ├── css/        Stili e temi
│   ├── docs/       Documenti interni al sito (testo delle pagine pubbliche)
│   ├── emoji/      Emoji della chat
│   └── js/
│       ├── src/    Sorgenti frontend (moduli ES, divisi per pagina)
│       └── app.js  Risultato della build (generato da src: non modificare a mano)
├── config/         Configurazione e chiavi (si committano solo i .sample)
├── docs/           Documenti di progettazione e algoritmi
├── sql/            Schema delle tabelle
├── standalone/     Pagine autonome (registro delle modifiche / pagina di reindirizzamento)
├── storage/        Dati di esecuzione (cache, log, caricamenti, backup)
└── tools/          Script di build e verifica
```

I caratteri tipografici non sono conservati nel repository: il browser li scarica direttamente da CDN pubbliche. Fonti e licenze in [FONTS.md](FONTS.md).

## Compilare il frontend

Dopo aver modificato i sorgenti sotto `assets/js/src/`, è necessario ricompilare:

```bash
python3 tools/build.py          # src/* → assets/js/app.js
```

## Controlli prima di committare

```bash
python3 tools/phpcheck.py       # controllo di sintassi e base PHP
python3 tools/jscheck.py        # controllo dei nomi duplicati nel frontend
python3 tools/repo_check.py     # controllo delle fughe (chiavi / liste / connessioni al database)
```

## Sicurezza e penetration test

Accogliamo con favore i ricercatori di sicurezza che vogliano esaminare e testare questo progetto. Per **ambito autorizzato, comportamento consentito e vietato, e modalità di segnalazione delle vulnerabilità**, leggi prima [SECURITY.md](SECURITY.md).

## Licenza

Questo progetto è distribuito con la **[GNU Affero General Public License v3.0](LICENSE)** (AGPL-3.0).

Sei libero di usarlo, modificarlo e distribuirlo, con due vincoli fondamentali:

1. **Le opere derivate devono essere rese open source con la stessa licenza**;
2. **Se offri a terzi una versione modificata come servizio di rete, devi fornire loro anche il codice sorgente completo** — è esattamente ciò che distingue la AGPL dalla GPL, ed è il motivo per cui è stata scelta.

## Contribuire

Vedi [CONTRIBUTING.md](CONTRIBUTING.md). Prima di inviare, assicurati di **non aver incluso chiavi, connessioni al database o liste di moderazione**.

## Esclusione di responsabilità

- Questo progetto non ha alcun legame con Kimi / Moonshot AI ed è un progetto non ufficiale.
- Gli utenti devono rispettare le leggi del proprio paese e i termini dei servizi di terze parti che utilizzano.
- Il codice è fornito "così com'è", senza alcuna garanzia, esplicita o implicita.
