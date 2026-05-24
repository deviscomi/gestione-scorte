# Gestione Scorte

Plugin WordPress per la gestione rapida delle scorte WooCommerce tramite barcode scanner, pensato per punti vendita fisici ed e-commerce.

## Requisiti

| Requisito | Versione minima |
|---|---|
| WordPress | 5.8 |
| PHP | 7.4 |
| WooCommerce | 6.0 |

## Funzionalità

- Ricerca prodotti per barcode o SKU
- Supporto per meta `_barcode` e `_gtin` (plugin di feed prodotto)
- Gestione scarico (decremento) e carico (incremento) scorte
- Supporto varianti WooCommerce
- Interfaccia ottimizzata per l'uso con scanner barcode

## Aggiornamenti automatici

Il plugin include un sistema di aggiornamento automatico basato su **GitHub Releases**. I siti WordPress che hanno il plugin installato riceveranno la notifica di aggiornamento disponibile direttamente nel pannello _Plugin_ e in _Dashboard → Aggiornamenti_, esattamente come avviene per i plugin del repository ufficiale WordPress.org.

Il flusso di aggiornamento è:

```
WordPress controlla aggiornamenti (wp-cron o admin)
       ↓
Il plugin interroga l'API GitHub Releases (cache 12 ore)
       ↓
Confronta il tag dell'ultima release con la Version installata
       ↓
Se la versione remota è superiore → notifica nel pannello Plugin
       ↓
L'amministratore clicca "Aggiorna" → WordPress scarica lo .zip e installa
```

---

## Come rilasciare una nuova versione

> **Seguire esattamente questa procedura ad ogni rilascio.** Saltare anche un solo passaggio può causare malfunzionamenti del sistema di aggiornamento automatico sui siti degli utenti.

### 1. Aggiornare la versione nel codice

Aprire `gestione-scorte.php` e aggiornare **entrambe** le righe:

```php
 * Version:           1.0.1          ← header del plugin (riga ~9)
```

```php
define( 'GESTIONE_SCORTE_VERSION', '1.0.1' );   ← costante PHP (riga ~24)
```

I due valori devono essere identici.

### 2. Fare commit e push

```bash
git add gestione-scorte.php
git commit -m "chore: bump version to 1.0.1"
git push origin main
```

### 3. Creare il file .zip del plugin

Lo zip deve contenere la cartella `gestione-scorte/` come radice (struttura che WordPress si aspetta). Il metodo più semplice dalla root del repository:

```bash
# Da fuori dalla cartella del plugin
zip -r gestione-scorte-1.0.1.zip gestione-scorte/ \
  --exclude "gestione-scorte/.git/*" \
  --exclude "gestione-scorte/.claude/*" \
  --exclude "gestione-scorte/.gitignore"
```

oppure su Windows con PowerShell:

```powershell
# Dalla directory padre di gestione-scorte/
Compress-Archive -Path "gestione-scorte" -DestinationPath "gestione-scorte-1.0.1.zip"
```

> **Attenzione:** non usare il `zipball_url` generato automaticamente da GitHub come unica fonte — il nome della cartella interna (`deviscomi-gestione-scorte-{sha}/`) non corrisponde a quello atteso da WordPress. Il plugin include un hook di fallback per rinominare la cartella, ma allegare uno .zip costruito correttamente è sempre preferibile.

### 4. Creare la GitHub Release

1. Vai su [github.com/deviscomi/gestione-scorte/releases/new](https://github.com/deviscomi/gestione-scorte/releases/new)
2. **Tag:** `v1.0.1` (formato SemVer con prefisso `v` — obbligatorio)
3. **Target branch:** `main`
4. **Release title:** `Gestione Scorte v1.0.1`
5. **Description:** Scrivere il changelog della versione. Questo testo verrà mostrato nella popup "Visualizza versione X.X.X" del pannello Plugin di WordPress.
6. **Allegare il file .zip** costruito al punto 3 (`gestione-scorte-1.0.1.zip`)
7. Pubblicare la release

### 5. Verificare

Dopo circa 12 ore (scadenza della cache) i siti WordPress che hanno il plugin installato riceveranno la notifica di aggiornamento. Per forzare un controllo immediato è sufficiente cancellare il transient `gs_github_release_cache` dal database (o usare un plugin come WP Crontrol / Transient Manager).

---

## Configurazione avanzata

### GitHub Personal Access Token

Per repository privati, o se il rate limit dell'API GitHub (60 richieste/ora per IP senza autenticazione) diventa un problema, è possibile impostare un Personal Access Token nella costante:

```php
define( 'GESTIONE_SCORTE_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxx' );
```

Il token necessita solo del scope `public_repo` (o `repo` per repository privati). Si consiglia di definire questa costante nel file `wp-config.php` del sito WordPress anziché nel file del plugin, per non includerlo nel repository.

In `wp-config.php`:

```php
define( 'GESTIONE_SCORTE_GITHUB_TOKEN', 'ghp_xxxxxxxxxxxxxxxx' );
```

Il plugin rileva automaticamente se la costante è già definita e non la sovrascrive.

---

## Struttura del progetto

```
gestione-scorte/
├── gestione-scorte.php          # File principale del plugin
├── includes/
│   ├── class-gs-admin.php       # Pagina di amministrazione e asset
│   ├── class-gs-ajax.php        # Endpoint AJAX per ricerca e aggiornamento scorte
│   └── class-gs-updater.php     # Sistema aggiornamenti automatici via GitHub
└── assets/
    ├── gs-admin.css
    └── gs-admin.js
```

## Licenza

GPL-2.0+ — vedere [https://www.gnu.org/licenses/gpl-2.0.html](https://www.gnu.org/licenses/gpl-2.0.html)
