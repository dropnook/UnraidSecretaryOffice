# Support page — setup

*Deutsch zuerst, [English below](#english).*

The support page is one Cloudflare Worker (`worker.js`). It shows the tip page that the office's
tip jar opens (`<OFFICE_SUPPORT_URL>?id=<server-id>&lang=<code>`), takes tips through PayPal and
gives the supporter key right after the payment. This folder is **not** part of the plugin
(`plugin/build.sh` doesn't pick it up).

---

## Deutsch

### Was die Seite tut

* Sie zeigt die Trinkgeldkasse in fünf Sprachen (de/en/it/fr/es) — die Sprache kommt vom Sekretariat
  (`lang=`) oder vom Browser.
* **PayPal:** Betrag (1–1000, USD/EUR/CHF) und optional ein Name fürs Dankesschild. Nach der Zahlung
  erstellt der Worker sofort den Unterstützer-Schlüssel und zeigt ihn an. Kein Mail, keine Handarbeit.
* **Andere Wege** (nur wenn eingerichtet): Banküberweisung und Bitcoin (Lightning und/oder On-Chain,
  mit QR-Code). Die kann niemand automatisch bestätigen — dort gilt Vertrauen: «Ich hab's geschickt —
  Schlüssel zeigen» gibt den Schlüssel sofort. Der Schlüssel schaltet ja nichts frei.
* **Schlüssel verloren?** Dieselbe Seite mit derselben Server-ID zeigt ihn wieder.
* Gespeichert wird nur: pro Server-ID der neueste Schlüssel `{key, date, via}`, und pro PayPal-Bestellung
  für 3 Tage `{Server-ID, Betrag, Währung, Name, Datum}`. **Nichts über die zahlende Person**
  (keine E-Mail, kein Name von PayPal, keine Adresse). Das Log enthält keine Namen und keine Anfragen.

Kosten: Cloudflare Workers **Free** reicht (100 000 Anfragen/Tag; KV 1000 Schreibvorgänge/Tag — das sind
rund 300 PayPal-Trinkgelder oder 1000 Vertrauens-Schlüssel pro Tag).

### 1. PayPal-Geschäftskonto

1. Auf [paypal.com/ch/business](https://www.paypal.com/ch/business) ein **Geschäftskonto** eröffnen
   (kostenlos) oder dein Privatkonto upgraden. Bestätigen (E-Mail, Bankkonto), sonst bleiben Zahlungen
   hängen.
2. **Tarif für Kleinbeträge (Micropayments)** beim PayPal-Kundendienst beantragen. Achtung: Er gilt
   dann für *alle* Zahlungen des Kontos — billiger für kleine Trinkgelder (ein paar Franken), teurer für
   grössere. Schau die aktuellen Gebühren für die Schweiz an, bevor du wechselst.
3. **Fremdwährungen annehmen:** *Einstellungen → Zahlungen → Zahlungen blockieren / Zahlungseingang* —
   Zahlungen in Währungen, die du nicht hast, **annehmen** (und umrechnen), oder EUR- und USD-Guthaben
   hinzufügen. Sonst bleibt eine USD- oder EUR-Zahlung «ausstehend», und der Schlüssel erscheint erst,
   wenn du sie von Hand annimmst (die Seite bietet dann «Nochmals prüfen»).

### 2. PayPal-App (zuerst Sandbox)

1. [developer.paypal.com](https://developer.paypal.com) → mit dem Geschäftskonto anmelden.
2. **Apps & Credentials** → Reiter **Sandbox** → **Create App** (Typ *Merchant*, Name z. B.
   `uso-support`). **Client ID** und **Secret** kopieren.
3. **Testing Tools → Sandbox Accounts:** Es gibt schon ein Sandbox-*Business*- und ein
   *Personal*-Konto. Beim Personal-Konto (der Testkäufer) unter *⋯ → View/Edit account* E-Mail und
   Passwort nachsehen. Bei Bedarf ein weiteres Personal-Konto (Land Schweiz) anlegen.

### 3. Der Signierschlüssel

Liegt schon auf deinem Mac: `~/.config/uso-supporter/supporter-private-pkcs8.pem` (PKCS#8, «BEGIN PRIVATE
KEY»). Er gehört **nie** ins Repository, in ein Mail oder einen Chat.

* Prüfen, dass er zum öffentlichen Schlüssel des Sekretariats passt:
  ```
  openssl pkey -in ~/.config/uso-supporter/supporter-private-pkcs8.pem -pubout
  ```
  muss genau den `PUBLIC KEY` aus `worker.js` (und `src/supporter.php`) ausgeben. (Der Worker prüft das
  auch selbst und nimmt ohne passenden Schlüssel keine Zahlung an.)
* Fehlt die PKCS#8-Datei: `openssl pkcs8 -topk8 -nocrypt -in supporter-private.pem -out supporter-private-pkcs8.pem`
* In die Zwischenablage: `pbcopy < ~/.config/uso-supporter/supporter-private-pkcs8.pem` — nach dem
  Einfügen wieder leeren: `pbcopy < /dev/null`.

### 4. Den Worker anlegen (Dashboard — am einfachsten)

1. [dash.cloudflare.com](https://dash.cloudflare.com) → **Workers & Pages** → **Create** → **Worker**
   (Vorlage «Hello World») → Name **`uso-support`** → **Deploy**.
2. **Edit code** → alles löschen, den ganzen Inhalt von `support-worker/worker.js` einfügen → **Deploy**.
3. **KV:** **Storage & Databases → KV** → **Create** → Name `uso-support`. Dann beim Worker
   **Settings → Bindings → Add → KV namespace**: Variablenname **`SUPPORT_KV`**, den Namespace
   `uso-support` wählen → speichern.
4. **Settings → Variables and Secrets → Add** (Typ *Text* oder *Secret* wie angegeben):

   | Name | Typ | Wert |
   |---|---|---|
   | `PAYPAL_ENV` | Text | `sandbox` |
   | `PAYPAL_CLIENT_ID` | Text | Client ID der Sandbox-App |
   | `PAYPAL_CLIENT_SECRET` | **Secret** | Secret der Sandbox-App |
   | `SUPPORTER_KEY` | **Secret** | der ganze Inhalt der PEM-Datei, mit den BEGIN/END-Zeilen |

   Freiwillig — leer oder weggelassen heisst: nicht angezeigt:

   | Name | Typ | Wert |
   |---|---|---|
   | `BANK_IBAN` | Text | deine IBAN (Leerzeichen egal; die Prüfziffer wird geprüft) |
   | `BANK_HOLDER` | Text | Kontoinhaber (Pflicht, wenn eine IBAN gesetzt ist) |
   | `BANK_BIC` | Text | BIC, freiwillig |
   | `BANK_NAME` | Text | Bank und Ort, freiwillig |
   | `BTC_LIGHTNING` | Text | Lightning-Adresse, `name@domain` |
   | `BTC_ADDRESS` | Text | On-Chain-Adresse (`bc1…`, auch `1…`/`3…`) |
   | `UNRAID_REFERRAL_URL` | Text | dein Unraid-Affiliate-Link (siehe 4c) |

   → **Deploy**. (Später neuen Code einfügen behält diese Einstellungen.)

### 4b. Oder mit wrangler (statt 4)

Braucht Node (`brew install node`). Im Ordner `support-worker/`:
```
npx wrangler login
npx wrangler kv namespace create SUPPORT_KV        # die ausgegebene id in wrangler.toml eintragen
# PAYPAL_CLIENT_ID (und BANK_…/BTC_…/UNRAID_REFERRAL_URL nach Wunsch) in wrangler.toml unter [vars] eintragen
npx wrangler secret put PAYPAL_CLIENT_SECRET
npx wrangler secret put SUPPORTER_KEY < ~/.config/uso-supporter/supporter-private-pkcs8.pem
npx wrangler deploy
```
Nur mit wrangler gibt es zusätzlich Cloudflares Rate-Limiter (`[[ratelimits]]` in `wrangler.toml`).
Nimm *einen* Weg: `wrangler deploy` überschreibt die Text-Variablen aus dem Dashboard mit denen aus
`wrangler.toml`.

### 4c. Unraid-Affiliate-Link (freiwillig)

Lime Technology zahlt über das Programm **«Unraid Ambassadors»** eine Provision für Lizenzen, die über
deinen Link gekauft werden — für die Käufer kostet es nicht mehr.

1. Selbst beim Ambassadors-Programm anmelden (auf unraid.net) und deinen persönlichen Link kopieren.
2. Beim Worker `UNRAID_REFERRAL_URL` = dieser Link → **Deploy**.

Der Worker nimmt nur `https://` auf `unraid.net` oder einer Subdomain davon an (kein Benutzer, kein Port);
alles andere wird nicht angezeigt und steht unter `problems` in `/api/health`. Auf der Seite erscheint ein
kleiner, ruhiger Abschnitt nach den Wegen fürs Trinkgeld — klar als **Affiliate-Link** beschriftet, öffnet
in einem neuen Tab (`rel="sponsored"`); die Seite selbst zählt keine Klicks. Ins Sekretariat (Plugin)
kommt der Link nicht. Leer lassen = kein Abschnitt.

### 5. Prüfen

`https://uso-support.<dein-konto>.workers.dev/api/health` öffnen. Erwartet:
```
{"ok":true,"env":"sandbox","methods":{"paypal":true,"bank":…,"lightning":…,"onchain":…},"referral":…,"problems":[]}
```
Steht etwas unter `problems`, ist genau diese Einstellung falsch oder fehlt (Werte zeigt die Seite nie).

### 6. Eigene Adresse (freiwillig)

Worker → **Settings → Domains & Routes → Add → Custom domain**, z. B. `support.tobel.me` (die Domain
muss bei Cloudflare liegen). Vorteil neben der schöneren Adresse: Die Rate-Limits zählen dann pro
Rechenzentrum über den Cache von Cloudflare; auf `*.workers.dev` nur pro Worker-Instanz.

### 7. Sandbox-Test von Anfang bis Ende

1. Im Sekretariat ☕ beim Teamchef → die Server-ID abschreiben — oder eine erfundene nehmen, z. B.
   `0000-1111-2222-3333`.
2. `https://…/?id=<ID>&lang=de` öffnen: Betrag 1, Name eintippen, den PayPal-Knopf drücken, mit dem
   Sandbox-*Personal*-Konto anmelden, zahlen.
3. Der Schlüssel erscheint gross mit «Schlüssel kopieren». Mit deiner echten ID: im Sekretariat
   ☕ → «Schlüssel eingeben…» → einfügen → «☕ Danke, <Name>». (Ein Sandbox-Schlüssel ist ein echter
   Schlüssel — er schaltet ja nichts frei.)
4. Dieselbe Adresse nochmals öffnen: «Dieser Server hat schon einen Unterstützer-Schlüssel…».
5. Auch mit Karte testen (Knopf «Debit- oder Kreditkarte»; Testkarten erzeugt PayPal unter
   *Testing Tools → Credit Card Generator*), und in einer anderen Währung.
6. Im Sandbox-*Business*-Konto (sandbox.paypal.com) die Zahlung ansehen: Beschreibung «Tip for the
   Unraid Secretary Office», Rechnungs-/Custom-ID = Server-ID.
7. Wenn eingerichtet: Banküberweisung/Bitcoin anschauen (QR-Codes mit dem Handy-Wallet scannen, ohne
   zu senden) und «Ich hab's geschickt — Schlüssel zeigen» drücken.

### 8. Live schalten

1. developer.paypal.com → **Apps & Credentials** → Reiter **Live** → **Create App** → Live-Client-ID und
   -Secret kopieren.
2. Beim Worker: `PAYPAL_ENV` = `live`, `PAYPAL_CLIENT_ID` = Live-ID, `PAYPAL_CLIENT_SECRET` = Live-Secret
   → **Deploy**.
3. `/api/health` zeigt `"env":"live"`. Ein echtes Trinkgeld von 1 (mit einem anderen Konto oder einer
   Karte — an dich selbst zahlen geht nicht) prüft alles.

### 9. Im Sekretariat

`OFFICE_SUPPORT_URL` in `src/bootstrap.php` auf die Adresse des Workers setzen, z. B.
`https://uso-support.<dein-konto>.workers.dev/` oder `https://support.tobel.me/` — das macht der
Koordinator in einem Release. Das Sekretariat hängt `?id=…&lang=…` selbst an.

### Gut zu wissen

* **Logs** (Worker → Logs) enthalten nur kurze Notizen (z. B. «PayPal capture: HTTP 422 …
  debug_id=…»), nie Namen, Beträge oder Anfragen.
* **PayPal-Secret erneuern:** neues Secret in der App erzeugen, `PAYPAL_CLIENT_SECRET` ersetzen.
* **Falls der private Schlüssel je wegkommt:** Wer ihn hat, kann Dankeschön-Schlüssel machen — mehr
  nicht. Austauschen hiesse: neues Schlüsselpaar, neuer öffentlicher Schlüssel in `worker.js` und
  `src/supporter.php` (Release), neues `SUPPORTER_KEY`; alte Schlüssel gelten dann in neuen Versionen
  nicht mehr.
* Testen ohne Cloudflare/PayPal: `node test.mjs` (Node 22, z. B. auf nostromo) — alles mit
  Wegwerf-Schlüssel und einem nachgebauten PayPal.

---

<a id="english"></a>
## English

### What the page does

* It shows the tip jar in five languages (en/de/it/fr/es) — the language comes from the office
  (`lang=`) or from the browser.
* **PayPal:** an amount (1–1000, USD/EUR/CHF) and, optionally, a name for the thank-you plate. Right
  after the payment the worker makes the supporter key and shows it. No e-mail, no manual work.
* **Other ways** (only when set up): bank transfer and Bitcoin (Lightning and/or on-chain, with a QR
  code). Nobody can confirm those automatically, so they work on trust: «I've sent it — show my key»
  gives the key at once. The key unlocks nothing anyway.
* **Lost the key?** The same page with the same server ID shows it again.
* Stored: per server ID the newest key `{key, date, via}`, and per PayPal order for 3 days
  `{server ID, amount, currency, name, date}`. **Nothing about the payer** (no e-mail, no name from
  PayPal, no address). The log holds no names and no requests.

Cost: Cloudflare Workers **Free** is enough (100,000 requests/day; KV 1,000 writes/day — about 300
PayPal tips or 1,000 trust keys a day).

### 1. PayPal business account

1. Open a **business account** at [paypal.com/ch/business](https://www.paypal.com/ch/business) (free)
   or upgrade your personal one. Confirm it (e-mail, bank account), or payments get stuck.
2. Ask PayPal's customer service for the **micropayment rate**. It then applies to *all* the account's
   payments — cheaper for small tips (a few francs), dearer for larger ones. Check the current fees for
   Switzerland before switching.
3. **Accept other currencies:** *Settings → Payments → Block payments / receiving preferences* —
   **accept** (and convert) payments in currencies you don't hold, or add EUR and USD balances.
   Otherwise a USD or EUR payment stays «pending» and the key appears only after you accept it by hand
   (the page offers «Check again» meanwhile).

### 2. PayPal app (sandbox first)

1. [developer.paypal.com](https://developer.paypal.com) → log in with the business account.
2. **Apps & Credentials** → tab **Sandbox** → **Create App** (type *Merchant*, name e.g.
   `uso-support`). Copy **Client ID** and **Secret**.
3. **Testing Tools → Sandbox Accounts:** a sandbox *business* and *personal* account already exist. For
   the personal one (the test buyer) see e-mail and password under *⋯ → View/Edit account*. Add another
   personal account (country Switzerland) if you like.

### 3. The signing key

Already on your Mac: `~/.config/uso-supporter/supporter-private-pkcs8.pem` (PKCS#8, «BEGIN PRIVATE
KEY»). It **never** goes into the repository, a mail or a chat.

* Check it matches the office's public key:
  ```
  openssl pkey -in ~/.config/uso-supporter/supporter-private-pkcs8.pem -pubout
  ```
  must print exactly the `PUBLIC KEY` in `worker.js` (and `src/supporter.php`). (The worker checks this
  itself, too, and takes no payment without a matching key.)
* No PKCS#8 file? `openssl pkcs8 -topk8 -nocrypt -in supporter-private.pem -out supporter-private-pkcs8.pem`
* To the clipboard: `pbcopy < ~/.config/uso-supporter/supporter-private-pkcs8.pem` — clear it after
  pasting: `pbcopy < /dev/null`.

### 4. Create the worker (dashboard — the easy way)

1. [dash.cloudflare.com](https://dash.cloudflare.com) → **Workers & Pages** → **Create** → **Worker**
   («Hello World» template) → name **`uso-support`** → **Deploy**.
2. **Edit code** → delete everything, paste all of `support-worker/worker.js` → **Deploy**.
3. **KV:** **Storage & Databases → KV** → **Create** → name `uso-support`. Then at the worker
   **Settings → Bindings → Add → KV namespace**: variable name **`SUPPORT_KV`**, namespace
   `uso-support` → save.
4. **Settings → Variables and Secrets → Add** (type *Text* or *Secret* as given):

   | Name | Type | Value |
   |---|---|---|
   | `PAYPAL_ENV` | Text | `sandbox` |
   | `PAYPAL_CLIENT_ID` | Text | the sandbox app's client ID |
   | `PAYPAL_CLIENT_SECRET` | **Secret** | the sandbox app's secret |
   | `SUPPORTER_KEY` | **Secret** | the whole PEM file, with its BEGIN/END lines |

   Optional — empty or left out means: not shown:

   | Name | Type | Value |
   |---|---|---|
   | `BANK_IBAN` | Text | your IBAN (spaces don't matter; its check digits are checked) |
   | `BANK_HOLDER` | Text | the account holder (needed once an IBAN is set) |
   | `BANK_BIC` | Text | BIC, optional |
   | `BANK_NAME` | Text | bank and town, optional |
   | `BTC_LIGHTNING` | Text | a Lightning address, `name@domain` |
   | `BTC_ADDRESS` | Text | an on-chain address (`bc1…`, also `1…`/`3…`) |
   | `UNRAID_REFERRAL_URL` | Text | your Unraid affiliate link (see 4c) |

   → **Deploy**. (Pasting new code later keeps these settings.)

### 4b. Or with wrangler (instead of 4)

Needs Node (`brew install node`). In `support-worker/`:
```
npx wrangler login
npx wrangler kv namespace create SUPPORT_KV        # put the printed id into wrangler.toml
# put PAYPAL_CLIENT_ID (and BANK_…/BTC_…/UNRAID_REFERRAL_URL if you like) into wrangler.toml under [vars]
npx wrangler secret put PAYPAL_CLIENT_SECRET
npx wrangler secret put SUPPORTER_KEY < ~/.config/uso-supporter/supporter-private-pkcs8.pem
npx wrangler deploy
```
Only wrangler adds Cloudflare's rate limiter on top (`[[ratelimits]]` in `wrangler.toml`). Pick *one*
way: `wrangler deploy` replaces the dashboard's text variables with those in `wrangler.toml`.

### 4c. Unraid affiliate link (optional)

Through its **«Unraid Ambassadors»** program Lime Technology pays a commission for licences bought through
your link — the buyer pays nothing extra.

1. Join the Ambassadors program yourself (on unraid.net) and copy your personal link.
2. At the worker: `UNRAID_REFERRAL_URL` = that link → **Deploy**.

The worker takes only `https://` on `unraid.net` or a subdomain of it (no user, no port); anything else is
not shown and appears under `problems` in `/api/health`. The page shows a small, quiet section after the
ways to give — clearly labelled **affiliate link**, opening in a new tab (`rel="sponsored"`); the page
itself counts no clicks. The link never goes into the office (the plugin). Empty = no section.

### 5. Check

Open `https://uso-support.<your-account>.workers.dev/api/health`. Expected:
```
{"ok":true,"env":"sandbox","methods":{"paypal":true,"bank":…,"lightning":…,"onchain":…},"referral":…,"problems":[]}
```
Anything under `problems` is exactly the setting that is wrong or missing (the page never shows values).

### 6. Your own address (optional)

Worker → **Settings → Domains & Routes → Add → Custom domain**, e.g. `support.tobel.me` (the domain must
be on Cloudflare). Besides the nicer address, the rate limits then count per data centre through
Cloudflare's cache; on `*.workers.dev` only per worker instance.

### 7. Sandbox test, end to end

1. In the office: ☕ at the team lead → note the server ID — or make one up, e.g. `0000-1111-2222-3333`.
2. Open `https://…/?id=<ID>&lang=en`: amount 1, type a name, press the PayPal button, log in with the
   sandbox *personal* account, pay.
3. The key appears, big, with «Copy key». With your real ID: in the office ☕ → «Enter key…» → paste →
   «☕ Thank you, <name>». (A sandbox key is a real key — it unlocks nothing anyway.)
4. Open the same address again: «This server already has a supporter key…».
5. Test a card, too (button «Debit or Credit Card»; PayPal makes test cards under *Testing Tools →
   Credit Card Generator*), and another currency.
6. In the sandbox *business* account (sandbox.paypal.com) look at the payment: description «Tip for the
   Unraid Secretary Office», custom ID = the server ID.
7. If set up: look at bank transfer/Bitcoin (scan the QR codes with a phone wallet without sending) and
   press «I've sent it — show my key».

### 8. Go live

1. developer.paypal.com → **Apps & Credentials** → tab **Live** → **Create App** → copy the live client
   ID and secret.
2. At the worker: `PAYPAL_ENV` = `live`, `PAYPAL_CLIENT_ID` = the live ID, `PAYPAL_CLIENT_SECRET` = the
   live secret → **Deploy**.
3. `/api/health` shows `"env":"live"`. One real tip of 1 (from another account or a card — paying
   yourself doesn't work) checks everything.

### 9. In the office

Set `OFFICE_SUPPORT_URL` in `src/bootstrap.php` to the worker's address, e.g.
`https://uso-support.<your-account>.workers.dev/` or `https://support.tobel.me/` — the coordinator does
that in a release. The office appends `?id=…&lang=…` itself.

### Good to know

* **Logs** (worker → Logs) hold short notes only (e.g. «PayPal capture: HTTP 422 … debug_id=…»), never
  names, amounts or requests.
* **New PayPal secret:** make one in the app, replace `PAYPAL_CLIENT_SECRET`.
* **Should the private key ever leak:** whoever has it can make thank-you keys — nothing more. Replacing
  it means a new key pair, the new public key in `worker.js` and `src/supporter.php` (a release), a new
  `SUPPORTER_KEY`; old keys then stop counting in new versions.
* Testing without Cloudflare/PayPal: `node test.mjs` (Node 22, e.g. on nostromo) — all with a throw-away
  key and a fake PayPal.
