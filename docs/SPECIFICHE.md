# Orio — specifiche

Gestionale di cassa per **orio.bucatoprofumato.com**. Ispirato a emmycasa, ma ridotto all'essenziale:
vendita al banco, incasso tramite cassa automatica VNE, scontrino fiscale, fattura su Fattura24.

Stack: Laravel 12, PHP 8.2 (`config.platform.php = 8.2.0` in composer.json), sviluppo locale su `orio.test` (Valet).

---

## 1. Flusso principale (vendita al banco)

1. L'operatore apre una **nuova vendita** e aggiunge le righe (descrizione, quantità, prezzo IVA inclusa, aliquota IVA).
2. Sceglie il metodo di pagamento:
   - **Contanti → cassa VNE**: orio invia l'importo alla cassa automatica e attende l'esito (vedi §3).
   - **Carta → VNE** (solo se il modello lo supporta, `credit_card: 1`), oppure POS esterno segnato a mano.
3. A pagamento **completato**:
   - **scontrino** → stampa del documento commerciale sulla stampante fiscale RT (vedi §4);
   - **fattura** → il cliente viene identificato tramite P.IVA (vedi §5) e la fattura parte su Fattura24 (vedi §2).
4. La vendita viene chiusa e registrata con: esito VNE, numero scontrino oppure numero e docId della fattura.

Stati di una vendita: `bozza` → `in_pagamento` → `pagata` → `scontrinata` / `fatturata`; in alternativa `annullata`.

Schermate (interfaccia minima, pensata per il touch: le postazioni cassa sono **iPad**):
- **Cassa**: righe, totale, pulsanti grandi "Contanti", "Carta", "Scontrino", "Fattura".
- **Vendite**: elenco con filtri per data e stato, ristampa, PDF della fattura, annullo o reso.
- **Chiusura giornata**: totali del giorno confrontati con la chiusura cassa VNE (tipo 60).
- **Impostazioni**: IP della cassa VNE, IP della stampante RT, chiave e sezionale Fattura24, credenziali OpenAPI, utenti/operatori.

## 2. Fatturazione — Fattura24

- **Account**: la chiave API di **Le Essenze di Elda**, salvata in `.env` come `FATTURA24_API_KEY`.
- **Numerazione propria**: un sezionale dedicato su Fattura24 (es. "ORIO"), da creare nel pannello Fattura24.
  L'id del sezionale va in `.env` (`FATTURA24_ID_NUMERATOR`) e viene inviato in ogni documento con il tag `IdNumerator`.
  Così le fatture orio non si mescolano alla numerazione principale di Elda.
- **Endpoint**: base `https://www.app.fattura24.com/api/v0.3/` (lo stesso di emmycasa). Si usano:
  - `TestKey`: verifica della chiave;
  - `SaveDocument`: invio dell'XML;
  - `GetFile`: download del PDF;
  - `GetNumerator`: elenco dei sezionali, per scegliere quello giusto dalle Impostazioni.
- **Servizio**: `App\Services\Fattura24Service` portato da emmycasa, con la chiave letta solo da config, senza chiave per utente.
- **Documento**: `DocumentType` `FE` (fattura elettronica) per i clienti con P.IVA. Prezzi scorporati dall'IVA come in emmycasa (`netUnitPrice` / `totalsFromGross`). IVA per riga.
- **Da emmycasa** si riprendono anche:
  - il limite di 99 righe per documento;
  - il log dell'XML inviato;
  - il salvataggio di `fattura24_doc_id` e `fattura24_doc_number` sulla vendita.

## 3. Cassa automatica VNE (protocollo Automatic Cash v3.05)

Riferimento: `~/lavori/kiro/protocollo comunicazione vneretail/`.
Simulatore: `~/lavori/kiro/AC_SIM/AC_SIM.exe` (Windows, protocollo AC 2.10). Si usa per sviluppo e test senza la macchina reale (vedi §7).

- **Trasporto**: `POST https://<IP_MACCHINA>/selfcashapi/` con corpo JSON. Gli importi sono **in centesimi**.
- **Timeout**: se non arriva risposta entro 5 secondi, la richiesta va ripetuta.
- **Esito**: ogni risposta contiene `req_status` (1 = ACK, 2 = NACK). In caso di NACK, `mess` riporta il codice d'errore dell'Appendice I (100–120), da mostrare all'operatore con un messaggio chiaro.

### Comandi usati da orio

| Uso | tipo | Note |
|---|---|---|
| Richiesta pagamento | 1 | `importo`, `opName` = utente orio (login automatico), `refundable: 1`, `credit_card: 0/1`. Restituisce `id` transazione |
| Polling pagamento | 2 | ogni ~1 s finché `payment_status` = 1; poi si legge `payment_details.status` |
| Annullamento | 3 | `tipo_annullamento`: 1 = accetta il parziale, 2 = restituisci il denaro |
| Pagamenti pendenti | 5 | all'avvio della cassa, per recuperare pagamenti rimasti in sospeso |
| Stato macchina | 20 | riquadro con livelli di banconote e monete e relativi alert |
| Chiusura cassa | 60 | chiusura giornata (`total_in`, `total_out`, `total_payments`, `cash_income`) |
| Rimborso + polling | 65 / 66 | resi in contanti di pagamenti `completed` o `partial` |
| Lista pagamenti | 71 | riconciliazione tra le vendite orio e i pagamenti VNE |
| Prelievo / refill | 10–13, 30–33 | fase 2, si fanno anche dal touch della macchina |

### Esiti del pagamento (`payment_details.status`)

- `completed` → vendita pagata, si procede con lo scontrino o la fattura.
- `returned` / `deleted` → vendita torna in bozza.
- `partial` → l'operatore decide se annullare o incassare la differenza con un altro metodo.
- `notCompleted` → la macchina non riesce a dare tutto il resto. Avviso con l'importo non erogato, calcolato come `inserted − (amount + rest)`. Le soluzioni sono un refill oppure l'annullamento con accettazione del parziale.
- `inserted = -2` → pagamento con carta.

Ogni chiamata e risposta VNE viene salvata nella tabella `vne_transactions` per audit e riconciliazione.

## 4. Scontrino fiscale (stampante RT)

> **Stampante in negozio (11/2026): WyCash 600 con stampante fiscale Axon Micrelec DADO RT, firmware G100.**
> Driver `axon` (`FISCAL_PRINTER_DRIVER=axon`, `FISCAL_PRINTER_HOST=<IP>`): il server prepara i comandi
> SF20 (`app/Services/FiscalPrinter/AxonG100.php`), il browser del tablet li manda alla stampante con le
> API WEB G100 (`GET http://IP/_io?cmd=4&js=1&pkt=…`, stato con `cmd=0`) in `resources/js/fiscal/axon.js`.
> Numero scontrino = `ej.lastdoc` (chiusura-documento) letto dopo la stampa. Reparti e numeri di pagamento
> in `FISCAL_PRINTER_DEPARTMENTS` / `FISCAL_PRINTER_PAYMENT_*`, da verificare dalla pagina **Stampante**
> (solo admin). In locale c'è un simulatore: `FISCAL_PRINTER_HOST=orio.test/dev/axon`.
> Il protocollo (AP.esse, "Protocollo di comunicazione RT G100 v6.0") è riservato e non è nel repository.
> Quanto segue sulle Epson resta valido solo per il driver `epson`.

> ⚠️ **Differenza importante rispetto a emmycasa**: lì `printReceipt` stampa un ticket **non fiscale** via Epson ePOS (`addText`). Per orio serve un vero **documento commerciale** emesso da una stampante RT.

- **Stampanti consigliate**: **Epson FP-81 II RT** o **Epson FP-90 III RT**, pilotate via FPMate (`printerFiscalReceipt` con `printRecItem`, `printRecTotal` e la forma di pagamento: contanti o elettronico). Sono gli unici modelli Epson con stampa HTTPS da browser documentata e usata in produzione da gestionali web (es. Spiagge.it).
- **Altre marche**:
  - **Custom** (K3 F RT, Q3X F RT, Kube X F RT): hanno un Web Service XML via browser, ma non risulta documentato il supporto HTTPS. Con una Custom serve il bridge locale (§7, opzione B).
  - **RCH PRINT! RT**: webservice HTTPS solo con modulo Wi-Fi e solo sui modelli standalone.
- **Reso e annullo**: documento commerciale di reso o annullo che richiama il numero dell'originale.
- **Stampanti**: anagrafica stampanti ripresa da emmycasa (`printers`: nome, tipo, IP, porta).

### Stampa in HTTPS

Orio è servito in HTTPS (`https://orio.bucatoprofumato.com`), quindi il browser **blocca** ogni chiamata `http://` verso la stampante (mixed content). Emmycasa oggi usa `ePosDev.connect(ip, 8008, …)` in http e funziona solo perché gira su `http://emmycasa.test`: per orio la stampa deve passare in HTTPS.

- **Connessione**: FPMate su `https://<IP_RT>/cgi-bin/fpmate.cgi` (supporto HTTPS ufficiale nella guida Epson ePOS Fiscal Print). Per le stampanti non fiscali con SDK ePOS si usa la porta `8043` (HTTPS/WSS) invece di `8008`, con fallback sulla `443`.
- **Attivazione su FP-81 II**: dalla tastiera della stampante, codice `34`, poi "Contante" fino a `WEB SERVER: SSL`, poi riavvio. Sulla FP-90 III si può anche caricare un certificato sulla stampante e installarlo sui PC, invece di accettare quello self-signed.
- **Certificato**: la stampante usa un certificato self-signed. Su ogni postazione va aperto una volta `https://<IP_RT>` e accettato il certificato, oppure va installato come attendibile. Se la stampante rigenera il certificato (reset o aggiornamento firmware), l'operazione va ripetuta.
- **Permesso di Chrome**: con il permesso "Local Network Access", Chrome chiede conferma quando un sito pubblico contatta un indirizzo della rete locale. Si concede una volta per sito, oppure si preimposta con una policy aziendale. La richiesta non va lanciata al caricamento della pagina ma solo su un'azione dell'operatore, ad esempio il pulsante "Stampa".
- **Pagina di diagnostica**: in Impostazioni, una pagina "Test stampante" prova la connessione HTTPS e, se fallisce, mostra il link `https://<IP_RT>` per accettare il certificato. La stessa cosa vale per la VNE (§3), già in HTTPS con certificato self-signed.
- **Fallback**: se certificati o permessi diventano ingestibili, si passa al bridge locale (§7, opzione B), che parla con stampante e VNE al posto del browser.

## 5. Dati società da P.IVA — OpenAPI

- **Servizio**: `App\Services\OpenApiService` portato da emmycasa. Endpoint `company.openapi.com/IT-start/{piva}`, token OAuth in cache per 30 giorni, rigenerazione automatica in caso di `401 Wrong Token`.
- **Credenziali**: le stesse di emmycasa (`OPENAPI_ENV`, `OPENAPI_EMAIL`, `OPENAPI_PASSWORD`).
- **Flusso in fattura**: si inserisce la P.IVA. Prima si cerca tra i clienti già salvati (`checkVat`), poi si chiama OpenAPI (`fetchVatData`). Si precompilano ragione sociale, indirizzo, CAP, città e provincia, poi l'operatore conferma e il cliente viene salvato.
- **Pulizia rispetto a emmycasa**: rimuovere il log del token in chiaro.

## 6. Modello dati (prima bozza)

- `users`: operatori; lo username viene passato come `opName` a VNE.
- `customers`: ragione sociale, P.IVA, CF, indirizzo, SDI/PEC.
- `sales`: stato, totale, metodo di pagamento, `vne_transaction_id`, `receipt_number`, `fattura24_doc_id`, `fattura24_doc_number`, `customer_id`.
- `sale_items`: descrizione, quantità, prezzo lordo, aliquota IVA.
- `vne_transactions`: id VNE, tipo, richiesta e risposta in JSON, stato.
- `printers`, `settings`.

## 7. Architettura di rete — soluzioni da testare

**Postazioni cassa: iPad.** Su iPad tutti i browser, Chrome compreso, usano il motore di Safari (WebKit) e i certificati di sistema. Non si possono installare servizi locali, quindi un ponte su `127.0.0.1` è escluso.

Sia la cassa VNE sia la stampante RT sono raggiungibili **solo nella LAN del negozio**: il server di orio (online) non le vede. A parlarci può essere solo qualcosa che sta nel negozio: il browser dell'iPad oppure un dispositivo ponte.

### Cosa sappiamo già

- **Le regole del browser** (§4, "Stampa in HTTPS"):
  - da una pagina HTTPS le chiamate `http://` vengono bloccate (mixed content);
  - le chiamate `https://` verso IP locali richiedono un certificato attendibile **con l'IP nel SAN**, gli header CORS e, su Chrome desktop, il permesso "Local Network Access".
- **Il simulatore VNE** (`~/lavori/kiro/AC_SIM`, programma Windows):
  - espone il webservice con un `BaseHTTPServer` Python che gestisce **solo POST**, senza `OPTIONS` e senza header `Access-Control-*`;
  - il certificato (`server.pem`) è self-signed "VNE DEMO", **scaduto il 25/03/2021** e **senza SAN**.

  Se la macchina reale si comporta come il simulatore, il browser **non può parlare direttamente con la VNE**, né in HTTP né in HTTPS: la richiesta JSON scatena una preflight `OPTIONS` che fallisce, e senza CORS la risposta (con l'`id` della transazione) non è leggibile da JavaScript. Da confermare sulla macchina reale.

### Soluzioni

| # | Soluzione | Stampante RT | Cassa VNE | Configurazione iPad | Note |
|---|---|---|---|---|---|
| S1 | **Orio in HTTP** + chiamate dirette dal browser | ✅ già provato (emmycasa: ePOS porta 8008 in http) | ❌ (CORS, vedi sopra) | nessuna | password e dati in chiaro; Safari mostra "Non sicuro"; utile come riferimento o in LAN |
| S2 | **HTTPS diretto + eccezione** accettata a mano | ⚠️ da provare | ❌ | aprire `https://IP` e accettare | solo per i test: l'eccezione non è permanente |
| S3 | **HTTPS diretto + CA propria** (profilo su iPad, certificato con SAN caricato sulla RT) | ⚠️ da provare (FP-90 III permette il caricamento, FP-81 II da verificare) | ❌ | profilo + "Attendibilità certificati" (~3 min per iPad) | certificato RT ≤ 825 giorni, CA 10 anni |
| S4 | **Ponte in negozio** (mini-PC / Raspberry) con connessione **in uscita** verso orio | ✅ (http o https, certificati ignorati) | ✅ (nessun vincolo del browser) | nessuna | un dispositivo in più, sempre acceso; indicatore "ponte collegato" in orio |

**Come funziona S4**: il ponte si collega a `orio.bucatoprofumato.com` tramite WebSocket (Laravel Reverb) o polling ogni ~1 s e resta in attesa di comandi. L'iPad chiede a orio "stampa" o "incassa", orio passa il comando al ponte, il ponte lo esegue in LAN su RT o VNE e rimanda l'esito. Gli iPad parlano solo con orio, come con un sito normale: niente certificati, CORS o permessi di rete locale. Il ponte funziona con qualsiasi marca di stampante (anche Custom).

**Ibrido S3 + S4**: stampante diretta dall'iPad (S3) e VNE tramite ponte (S4). Ha senso solo se si vuole che la stampa funzioni anche a ponte spento; altrimenti conviene far passare tutto dal ponte.

**Consiglio**: **S4**. Per la VNE è l'unica strada che funziona se la macchina reale è come il simulatore, e a quel punto conviene far passare anche la stampante dal ponte.

### Piano di test

1. **Simulatore VNE (S4, S1)**:
   - avviare `AC_SIM.exe` su un PC Windows (o una VM) della LAN e annotare IP e porta;
   - provare pagamento (1), polling (2), annullamento (3), stato (20) e chiusura (60) con `curl -k` e poi dal prototipo del ponte;
   - dalla pagina di orio, provare una `fetch` diretta per confermare il blocco CORS.
2. **Stampante RT (S1, S2, S3)**, appena è disponibile, da un iPad:
   - S1: orio di prova in http + fpmate in http;
   - S2: SSL attivo sulla RT + eccezione accettata;
   - S3: verificare se il certificato Epson ha l'IP nel SAN; caricare un certificato della CA propria e installare il profilo sull'iPad.
3. **Cassa VNE reale**: ripetere il punto 1 e verificare se, a differenza del simulatore, risponde a `OPTIONS` e invia gli header CORS.
4. **Ponte (S4)**: prototipo con un piccolo servizio (PHP CLI o Python) su un Mac o Raspberry. Test di latenza iPad → orio → ponte → RT/VNE, di riconnessione automatica e di comportamento a ponte spento.

**Opzione scartata**: orio installato in locale nel negozio. Perde l'accesso da remoto e complica backup e deploy, e il ponte S4 dà gli stessi vantaggi senza questi problemi.

## 8. Domande aperte

1. **Chiave Fattura24 di Le Essenze di Elda**: in locale non l'ho trovata, va aggiunta al `.env`. Il sezionale "ORIO" va creato su Fattura24.
2. **Stampante fiscale**: è già in negozio? Se sì, marca e modello. Se va acquistata, scegliere Epson FP-81 II RT o FP-90 III RT (§4).
3. **Cassa VNE**: modello (Virtuo, 4Pay, sPay…), IP in LAN, se accetta carte, e se l'installazione richiede `authKey`.
4. **Prodotti**: catalogo con prezzi fissi (da dove arriva? Bucato Profumato o TeaERP?) oppure righe libere? Serve il magazzino?
5. **Hosting**: dove pubblicare orio.bucatoprofumato.com (Cloudways come gli altri?). Architettura: scelta dopo i test del §7; S4 è quella consigliata.
6. **Resi**: in contanti tramite VNE (tipo 65) con documento di reso RT. Servono anche le note di credito su Fattura24?
7. **Ponte**: quale dispositivo usare (mini-PC già presente in negozio? Raspberry da acquistare?) e su quale PC Windows far girare il simulatore AC_SIM.
