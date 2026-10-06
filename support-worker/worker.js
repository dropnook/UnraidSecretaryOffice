/*
 * Unraid Secretary Office — the support page (one Cloudflare Worker, module syntax, no build step).
 *
 * The office is free and complete. A tip is welcome; as a thank-you the tipper gets a supporter
 * key that unlocks nothing: it only stops the office's reminders and shows «☕ Thank you, <name>».
 * Keys are issued here, automatically, right after PayPal confirms the payment.
 *
 * Routes
 *   GET  /?id=<server-id>&lang=<en|de|it|fr|es>   the page (the office's tip jar opens it)
 *   POST /api/order    {id?, amount, currency, name?, lang?}  → {ok, orderID}   (a PayPal order, intent CAPTURE)
 *   POST /api/capture  {orderID}                              → {ok, key|null, date, name}
 *   POST /api/honour   {id, via, name?, lang?}                → {ok, key, date, name}
 *                      bank transfer and Bitcoin can't be confirmed automatically: «I've sent it —
 *                      show my key» is taken on trust (the key unlocks nothing, it only says thanks)
 *   GET  /api/key?id=<server-id>                              → {ok, key, date, name, via}  («lost key»)
 *   GET  /api/health                                          → {ok, env, methods, problems: [<setting>…]}
 *
 * Settings (Cloudflare: Worker → Settings → Variables and Secrets / Bindings; see SETUP.md)
 *   PAYPAL_ENV            var     sandbox | live (API base follows it; the SDK follows the client id)
 *   PAYPAL_CLIENT_ID      var     the REST app's client id (public, the page needs it)
 *   PAYPAL_CLIENT_SECRET  secret  the REST app's secret
 *   SUPPORTER_KEY         secret  the signing key, EC P-256, PKCS#8 PEM («BEGIN PRIVATE KEY»;
 *                                 a SEC1 «BEGIN EC PRIVATE KEY» is accepted too)
 *   SUPPORT_KV            KV namespace binding
 *   BANK_IBAN, BANK_HOLDER, BANK_BIC, BANK_NAME   vars, optional — a bank transfer is offered when
 *                                 IBAN and holder are set (and valid)
 *   BTC_LIGHTNING         var, optional — a Lightning address (name@domain), shown with a QR code
 *   BTC_ADDRESS           var, optional — an on-chain address (bc1… / 1… / 3…), shown with a QR code
 *   UNRAID_REFERRAL_URL   var, optional — Benj's Unraid Ambassadors (affiliate) link: https on
 *                                 unraid.net or a subdomain only; shown, clearly labelled, after the
 *                                 ways to give
 *   RATE_LIMITER          optional rate limiting binding (wrangler.toml only)
 *   SUPPORTER_PUBLIC_KEY  tests only — replaces the office's public key below. Leave it unset.
 *
 * KV records — nothing about the payer is ever stored (no e-mail, no PayPal name, no address):
 *   order:<PayPal order id>  {"id": <server id>|null, "amount": "5.00", "currency": "EUR",
 *                             "name": <plate name>|null, "created": <ISO time>, "pending"?: true,
 *                             "done"?: true, "paid"?: "YYYY-MM-DD"}   — expires after 3 days
 *   key:<server id>          {"key": "USO1…", "date": "YYYY-MM-DD", "via": "paypal"|"bank"|"bitcoin"}
 *                            — the newest key wins
 *
 * Key format (src/supporter.php in the office checks it offline, byte for byte):
 *   USO1.<b64url(payload)>.<b64url(signature)>   b64url = base64url without padding
 *   payload   = UTF-8 compact JSON in exactly this order: {"v":1,"id":"XXXX-XXXX-XXXX-XXXX","name":"…","date":"YYYY-MM-DD"}
 *   signature = ECDSA P-256 / SHA-256 over the ASCII text "USO1.<b64url(payload)>", DER-encoded
 *               (WebCrypto signs as raw r||s, IEEE P1363 — converted to DER below)
 *
 * The private key comes only as the secret SUPPORTER_KEY. Before an order is created, the worker
 * checks once per isolate that it matches the office's public key — so nobody pays for a key the
 * office would refuse. Request bodies are never logged.
 */

const OFFICE_PUBLIC_KEY = `-----BEGIN PUBLIC KEY-----
MFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE2p2CR6VnacA3h+TcgYMrVyFczVf0
GBylBXhHiYTCsHOyt5mz2eycn2jnR0K0LeucZ5jyPLRt4gKwCwWTdxzOug==
-----END PUBLIC KEY-----`;

const LANGS = ['en', 'de', 'it', 'fr', 'es'];
const PAYPAL_LOCALE = { en: 'en_US', de: 'de_DE', it: 'it_IT', fr: 'fr_FR', es: 'es_ES' };
const CURRENCIES = ['USD', 'EUR', 'CHF'];
const AMOUNT_MIN_CENTS = 100;            // 1 — below that the fees would eat the tip
const AMOUNT_MAX_CENTS = 100000;         // 1000
const NAME_MAX = 60;                     // characters (code points), like the office
const ID_RE = /^[0-9A-F]{4}(?:-[0-9A-F]{4}){3}$/;
const ORDER_ID_RE = /^[A-Za-z0-9-]{8,64}$/;
const NAME_RE = /^[^\p{C}\p{Zl}\p{Zp}]+$/u;   // printable: no control, format, surrogate, private-use, unassigned; no line/paragraph separators
const ORDER_TTL = 3 * 86400;             // seconds an order record lives in KV
const BODY_MAX = 2048;                   // bytes of a JSON request
const PAYPAL_TIMEOUT = 15000;            // ms
const DESCRIPTION = 'Tip for the Unraid Secretary Office';
const BRAND = 'Unraid Secretary Office';
// per bucket: [requests, seconds] — per client IP, except honour_id (per server ID) and honour_all
// (everyone, per isolate/data centre). Light: a person never comes near them.
const LIMITS = {
  lookup: [30, 600], order: [12, 600], capture: [30, 600],
  honour: [6, 3600], honour_id: [4, 3600], honour_all: [120, 3600],
};
const VIAS = ['paypal', 'bank', 'bitcoin'];
const EURO = new Set('AD AT BE BG CY DE EE ES FI FR GR HR IE IT LT LU LV MC ME MT NL PT SI SK SM VA XK'.split(' '));

const TEXT = {
  en: {
    title: 'Tip jar · Unraid Secretary Office',
    h1: 'Tip jar',
    intro: 'Thank you for using the Unraid Secretary Office! It is free and complete, and it stays that way — nothing is locked, today or later.',
    ask: 'If the team does a good job for you, a tip of any amount is welcome — say 5, a coffee for the team.',
    credit: 'Part of the tips goes to {helmi}, who wrote the tools Jack Emby works with — EmbyCache and «Consolidate folders», the media gather.',
    shelter: 'If the tips ever exceed what our work costs, we give the rest to animal shelters that urgently need financial support.',
    key_explain: 'As a thank-you you get a supporter key. It unlocks nothing — there is nothing to unlock. It only stops the office\'s reminders and puts a small «☕ Thank you» with your name at the team lead.',
    key_title: 'The thank-you',
    paypal_title: 'Tip with PayPal',
    other_title: 'Other ways to give',
    honour_text: "Bank transfers and Bitcoin can't be confirmed here automatically, so this works on trust: once you've sent your tip, click the button and your key appears right away. It unlocks nothing anyway — it only says thank you.",
    honour_button: "I've sent it — show my key",
    bank_title: 'Bank transfer',
    bank_holder: 'Account holder',
    bank_iban: 'IBAN',
    bank_bic: 'BIC',
    bank_name: 'Bank',
    bank_note: 'A Swiss account. From outside Switzerland and the SEPA area, a transfer may cost you fees.',
    btc_title: 'Bitcoin',
    btc_lightning: 'Lightning',
    btc_lightning_note: 'Lightning suits small amounts — it is fast and costs almost nothing.',
    btc_onchain: 'On-chain',
    btc_onchain_note: 'On-chain is worth it for larger amounts; the network fee does not depend on the amount.',
    qr: 'QR code: {what}',
    copy_short: 'Copy',
    amount: 'Amount',
    currency: 'Currency',
    amount_hint: 'From {min} to {max} — below {min} the fees would eat the tip.',
    name: 'Name on the thank-you plate',
    name_hint: 'Optional, up to 60 characters; it becomes part of the key. Empty: «{default}».',
    default_name: 'Supporter',
    server: 'For this server',
    server_hint: 'The ID comes from your office\'s tip jar — the key works on this server only.',
    no_id: 'For a supporter key, open this page from the tip jar in your office (☕ at the team lead) — it brings your server\'s ID along. A tip without it is just as welcome.',
    bad_id: 'The server ID in this link isn\'t valid. For a supporter key, open this page again from the tip jar in your office (☕ at the team lead) — a tip without it is just as welcome.',
    not_set_up: 'The tip jar isn\'t set up yet — please come back later.',
    sdk_failed: 'PayPal could not be loaded. A content blocker? Allow paypal.com and reload the page.',
    working: 'One moment …',
    cancelled: 'Cancelled — nothing was paid.',
    thanks_title: 'Thank you!',
    thanks_key: 'Thank you, {name}! Here is your supporter key:',
    thanks_nokey: 'Thank you! Your tip has arrived — the whole team is glad of it.',
    key_how: 'In the office: ☕ at the team lead → «Enter key…», paste it, done. Lost it? Open this page from the tip jar again — it shows the key.',
    copy: 'Copy key',
    copied: 'Copied!',
    have_title: 'Your supporter key',
    have_text: 'This server already has a supporter key — for «{name}», from {date}. Here it is again:',
    have_more: 'Another tip is still welcome; the newest key then takes this one\'s place.',
    pending: 'PayPal is still processing your payment. Once it is through, your key appears here — check again in a little while.',
    check_again: 'Check again',
    privacy: 'Payments go through PayPal. This page keeps only the server ID, the name you typed and the date — nothing PayPal knows about you.',
    free: 'The Unraid Secretary Office is free software (GPL-3.0).',
    languages: 'Language',
    err_bad_amount: 'Please enter an amount from {min} to {max}, e.g. 5 or 5.50.',
    err_bad_name: 'The name may have up to 60 characters, without control characters.',
    err_bad_currency: 'Please choose USD, EUR or CHF.',
    err_rate_limited: 'Too many tries in a short time — please wait a few minutes.',
    err_server_config: 'The tip jar isn\'t set up completely — please try again later. Nothing was charged.',
    err_not_completed: 'PayPal didn\'t complete this payment, so there is no key for it.',
    err_mismatch: 'PayPal\'s answer doesn\'t match this order, so no key was made.',
    err_unknown_order: 'This order is unknown here (or too old).',
    err_bad_id: "The server ID isn't valid — open this page from the tip jar in your office.",
    err_bad_via: 'Please choose how you sent your tip.',
    referral_text: 'Buying or upgrading an Unraid licence? This affiliate link supports the office at no extra cost to you.',
    referral_link: 'Unraid licences on unraid.net',
    referral_tag: 'Affiliate link',
    referral_note: 'unraid.net sees that you came through this link — that is how the commission works. This page itself counts nothing.',
    err_generic: 'Something went wrong. If PayPal charged you, click «Check again» in a moment — your key appears as soon as the payment is confirmed.',
  },
  de: {
    title: 'Trinkgeldkasse · Unraid Secretary Office',
    h1: 'Trinkgeldkasse',
    intro: 'Danke, dass du das Unraid Secretary Office benutzt! Es ist kostenlos und vollständig, und das bleibt so — nichts ist gesperrt, weder heute noch später.',
    ask: 'Wenn das Team gute Arbeit für dich leistet, freut es sich über ein Trinkgeld in beliebiger Höhe — sagen wir 5, ein Kaffee fürs Team.',
    credit: 'Ein Teil des Trinkgelds geht an {helmi} — er hat die Werkzeuge geschrieben, mit denen Jack Emby arbeitet: EmbyCache und «Ordner zusammenführen».',
    shelter: 'Übersteigen die Trinkgelder den Aufwand für unsere Arbeit, spenden wir den Rest an Tierheime, die finanzielle Unterstützung dringend brauchen.',
    key_explain: 'Als Dankeschön bekommst du einen Unterstützer-Schlüssel. Er schaltet nichts frei — es gibt nichts freizuschalten. Er beendet nur die Erinnerungen des Sekretariats und stellt ein kleines «☕ Danke» mit deinem Namen zum Teamchef.',
    key_title: 'Das Dankeschön',
    paypal_title: 'Trinkgeld mit PayPal',
    other_title: 'Andere Wege für ein Trinkgeld',
    honour_text: 'Überweisungen und Bitcoin kann diese Seite nicht selbst bestätigen, darum gilt hier Vertrauen: Hast du dein Trinkgeld geschickt, klick auf den Knopf, und dein Schlüssel erscheint sofort. Er schaltet ohnehin nichts frei — er sagt nur Danke.',
    honour_button: "Ich hab's geschickt — Schlüssel zeigen",
    bank_title: 'Banküberweisung',
    bank_holder: 'Kontoinhaber',
    bank_iban: 'IBAN',
    bank_bic: 'BIC',
    bank_name: 'Bank',
    bank_note: 'Ein Schweizer Konto. Von ausserhalb der Schweiz und des SEPA-Raums kann eine Überweisung Gebühren kosten.',
    btc_title: 'Bitcoin',
    btc_lightning: 'Lightning',
    btc_lightning_note: 'Lightning eignet sich für kleine Beträge — schnell und fast ohne Gebühren.',
    btc_onchain: 'On-Chain',
    btc_onchain_note: 'On-Chain lohnt sich für grössere Beträge; die Netzwerkgebühr hängt nicht vom Betrag ab.',
    qr: 'QR-Code: {what}',
    copy_short: 'Kopieren',
    amount: 'Betrag',
    currency: 'Währung',
    amount_hint: 'Von {min} bis {max} — unter {min} fressen die Gebühren das Trinkgeld auf.',
    name: 'Name auf dem Dankesschild',
    name_hint: 'Freiwillig, bis 60 Zeichen; er wird Teil des Schlüssels. Leer: «{default}».',
    default_name: 'Supporter',
    server: 'Für diesen Server',
    server_hint: 'Die ID kommt aus der Trinkgeldkasse deines Sekretariats — der Schlüssel wirkt nur auf diesem Server.',
    no_id: 'Für einen Unterstützer-Schlüssel öffne diese Seite aus der Trinkgeldkasse in deinem Sekretariat (☕ beim Teamchef) — sie bringt die ID deines Servers mit. Ein Trinkgeld ohne ist genauso willkommen.',
    bad_id: 'Die Server-ID in diesem Link ist ungültig. Für einen Unterstützer-Schlüssel öffne diese Seite nochmals aus der Trinkgeldkasse in deinem Sekretariat (☕ beim Teamchef) — ein Trinkgeld ohne ist genauso willkommen.',
    not_set_up: 'Die Trinkgeldkasse ist noch nicht eingerichtet — bitte komm später wieder.',
    sdk_failed: 'PayPal liess sich nicht laden. Ein Inhaltsblocker? Erlaube paypal.com und lade die Seite neu.',
    working: 'Einen Moment …',
    cancelled: 'Abgebrochen — es wurde nichts bezahlt.',
    thanks_title: 'Danke!',
    thanks_key: 'Danke, {name}! Hier ist dein Unterstützer-Schlüssel:',
    thanks_nokey: 'Danke! Dein Trinkgeld ist angekommen — das ganze Team freut sich.',
    key_how: 'Im Sekretariat: ☕ beim Teamchef → «Schlüssel eingeben…», einfügen, fertig. Verloren? Öffne diese Seite wieder aus der Trinkgeldkasse — sie zeigt den Schlüssel.',
    copy: 'Schlüssel kopieren',
    copied: 'Kopiert!',
    have_title: 'Dein Unterstützer-Schlüssel',
    have_text: 'Dieser Server hat schon einen Unterstützer-Schlüssel — für «{name}», vom {date}. Hier ist er nochmals:',
    have_more: 'Ein weiteres Trinkgeld ist trotzdem willkommen; der neueste Schlüssel tritt dann an seine Stelle.',
    pending: 'PayPal bearbeitet deine Zahlung noch. Sobald sie durch ist, erscheint dein Schlüssel hier — schau in einer Weile nochmals nach.',
    check_again: 'Nochmals prüfen',
    privacy: 'Bezahlt wird über PayPal. Diese Seite behält nur die Server-ID, den Namen, den du eingegeben hast, und das Datum — nichts, was PayPal über dich weiss.',
    free: 'Das Unraid Secretary Office ist freie Software (GPL-3.0).',
    languages: 'Sprache',
    err_bad_amount: 'Bitte gib einen Betrag von {min} bis {max} ein, z. B. 5 oder 5.50.',
    err_bad_name: 'Der Name darf bis 60 Zeichen haben, ohne Steuerzeichen.',
    err_bad_currency: 'Bitte wähle USD, EUR oder CHF.',
    err_rate_limited: 'Zu viele Versuche in kurzer Zeit — bitte warte ein paar Minuten.',
    err_server_config: 'Die Trinkgeldkasse ist nicht vollständig eingerichtet — bitte versuch es später nochmals. Es wurde nichts belastet.',
    err_not_completed: 'PayPal hat diese Zahlung nicht abgeschlossen, darum gibt es keinen Schlüssel dafür.',
    err_mismatch: 'Die Antwort von PayPal passt nicht zu dieser Bestellung, darum wurde kein Schlüssel erstellt.',
    err_unknown_order: 'Diese Bestellung ist hier unbekannt (oder zu alt).',
    err_bad_id: 'Die Server-ID ist ungültig — öffne diese Seite aus der Trinkgeldkasse in deinem Sekretariat.',
    err_bad_via: 'Bitte wähle, wie du dein Trinkgeld geschickt hast.',
    referral_text: 'Kaufst du eine Unraid-Lizenz oder rüstest eine auf? Dieser Affiliate-Link unterstützt das Sekretariat, ohne dass es dich mehr kostet.',
    referral_link: 'Unraid-Lizenzen auf unraid.net',
    referral_tag: 'Affiliate-Link',
    referral_note: 'unraid.net sieht, dass du über diesen Link kommst — so entsteht die Provision. Diese Seite selbst zählt nichts.',
    err_generic: 'Etwas ist schiefgegangen. Falls PayPal dich belastet hat, klick gleich auf «Nochmals prüfen» — dein Schlüssel erscheint, sobald die Zahlung bestätigt ist.',
  },
  it: {
    title: 'Salvadanaio delle mance · Unraid Secretary Office',
    h1: 'Salvadanaio delle mance',
    intro: 'Grazie di usare l\'Unraid Secretary Office! È gratuito e completo, e resta così — niente è bloccato, né oggi né in futuro.',
    ask: 'Se il team lavora bene per te, una mancia di qualsiasi importo è benvenuta — diciamo 5, un caffè per il team.',
    credit: 'Una parte delle mance va a {helmi}, che ha scritto gli strumenti con cui lavora Jack Emby: EmbyCache e «Consolida cartelle».',
    shelter: 'Se le mance superano ciò che ci costa il lavoro, doniamo il resto a rifugi per animali che hanno urgente bisogno di sostegno economico.',
    key_explain: 'Per ringraziarti ricevi una chiave sostenitore. Non sblocca niente — non c\'è niente da sbloccare. Ferma solo i promemoria dell\'ufficio e mette un piccolo «☕ Grazie» con il tuo nome dal capoufficio.',
    key_title: 'Il ringraziamento',
    paypal_title: 'Mancia con PayPal',
    other_title: 'Altri modi per dare una mancia',
    honour_text: "Bonifici e Bitcoin non si possono confermare qui in automatico, quindi vale la fiducia: dopo aver inviato la mancia, clicca il pulsante e la tua chiave appare subito. Tanto non sblocca niente — dice solo grazie.",
    honour_button: "L'ho inviata — mostra la mia chiave",
    bank_title: 'Bonifico bancario',
    bank_holder: 'Intestatario',
    bank_iban: 'IBAN',
    bank_bic: 'BIC',
    bank_name: 'Banca',
    bank_note: "Un conto svizzero. Da fuori della Svizzera e dell'area SEPA un bonifico può costarti delle commissioni.",
    btc_title: 'Bitcoin',
    btc_lightning: 'Lightning',
    btc_lightning_note: 'Lightning è adatto a piccoli importi — veloce e quasi senza commissioni.',
    btc_onchain: 'On-chain',
    btc_onchain_note: "On-chain conviene per importi più grandi; la commissione di rete non dipende dall'importo.",
    qr: 'Codice QR: {what}',
    copy_short: 'Copia',
    amount: 'Importo',
    currency: 'Valuta',
    amount_hint: 'Da {min} a {max} — sotto {min} le commissioni si mangerebbero la mancia.',
    name: 'Nome sulla targhetta di ringraziamento',
    name_hint: 'Facoltativo, fino a 60 caratteri; diventa parte della chiave. Vuoto: «{default}».',
    default_name: 'Mecenate',
    server: 'Per questo server',
    server_hint: 'L\'ID viene dal salvadanaio del tuo ufficio — la chiave funziona solo su questo server.',
    no_id: 'Per una chiave sostenitore, apri questa pagina dal salvadanaio del tuo ufficio (☕ dal capoufficio) — porta con sé l\'ID del tuo server. Una mancia senza è altrettanto benvenuta.',
    bad_id: 'L\'ID del server in questo link non è valido. Per una chiave sostenitore, riapri questa pagina dal salvadanaio del tuo ufficio (☕ dal capoufficio) — una mancia senza è altrettanto benvenuta.',
    not_set_up: 'Il salvadanaio non è ancora pronto — torna più tardi.',
    sdk_failed: 'Non è stato possibile caricare PayPal. Un blocco dei contenuti? Consenti paypal.com e ricarica la pagina.',
    working: 'Un momento …',
    cancelled: 'Annullato — non è stato pagato niente.',
    thanks_title: 'Grazie!',
    thanks_key: 'Grazie, {name}! Ecco la tua chiave sostenitore:',
    thanks_nokey: 'Grazie! La tua mancia è arrivata — tutto il team ne è felice.',
    key_how: 'Nell\'ufficio: ☕ dal capoufficio → «Inserisci chiave…», incolla, fatto. Persa? Riapri questa pagina dal salvadanaio — mostra la chiave.',
    copy: 'Copia chiave',
    copied: 'Copiata!',
    have_title: 'La tua chiave sostenitore',
    have_text: 'Questo server ha già una chiave sostenitore — per «{name}», del {date}. Eccola di nuovo:',
    have_more: 'Un\'altra mancia è comunque benvenuta; la chiave più recente prende poi il suo posto.',
    pending: 'PayPal sta ancora elaborando il tuo pagamento. Appena è concluso, la tua chiave appare qui — ricontrolla tra un po\'.',
    check_again: 'Ricontrolla',
    privacy: 'I pagamenti passano da PayPal. Questa pagina conserva solo l\'ID del server, il nome che hai scritto e la data — niente di ciò che PayPal sa di te.',
    free: 'L\'Unraid Secretary Office è software libero (GPL-3.0).',
    languages: 'Lingua',
    err_bad_amount: 'Inserisci un importo da {min} a {max}, p. es. 5 o 5.50.',
    err_bad_name: 'Il nome può avere fino a 60 caratteri, senza caratteri di controllo.',
    err_bad_currency: 'Scegli USD, EUR o CHF.',
    err_rate_limited: 'Troppi tentativi in poco tempo — aspetta qualche minuto.',
    err_server_config: 'Il salvadanaio non è configurato del tutto — riprova più tardi. Non è stato addebitato niente.',
    err_not_completed: 'PayPal non ha completato questo pagamento, quindi non c\'è una chiave.',
    err_mismatch: 'La risposta di PayPal non corrisponde a questo ordine, quindi non è stata creata nessuna chiave.',
    err_unknown_order: 'Questo ordine qui è sconosciuto (o troppo vecchio).',
    err_bad_id: "L'ID del server non è valido — apri questa pagina dal salvadanaio del tuo ufficio.",
    err_bad_via: 'Scegli come hai inviato la mancia.',
    referral_text: 'Compri o aggiorni una licenza Unraid? Questo link di affiliazione sostiene l\'ufficio senza costi aggiuntivi per te.',
    referral_link: 'Licenze Unraid su unraid.net',
    referral_tag: 'Link di affiliazione',
    referral_note: 'unraid.net vede che arrivi da questo link — è così che nasce la commissione. Questa pagina in sé non conta niente.',
    err_generic: 'Qualcosa è andato storto. Se PayPal ti ha addebitato, clicca tra poco su «Ricontrolla» — la chiave appare appena il pagamento è confermato.',
  },
  fr: {
    title: 'Tirelire · Unraid Secretary Office',
    h1: 'Tirelire',
    intro: 'Merci d\'utiliser l\'Unraid Secretary Office ! Il est gratuit et complet, et il le reste — rien n\'est verrouillé, ni aujourd\'hui ni plus tard.',
    ask: 'Si l\'équipe fait du bon travail pour toi, un pourboire de n\'importe quel montant est le bienvenu — disons 5, un café pour l\'équipe.',
    credit: 'Une partie des pourboires va à {helmi}, qui a écrit les outils avec lesquels travaille Jack Emby : EmbyCache et « Regrouper les dossiers ».',
    shelter: 'Si les pourboires dépassent ce que nous coûte notre travail, nous donnons le reste à des refuges pour animaux qui ont un besoin urgent de soutien financier.',
    key_explain: 'En remerciement, tu reçois une clé de soutien. Elle ne débloque rien — il n\'y a rien à débloquer. Elle arrête seulement les rappels du bureau et place un petit « ☕ Merci » avec ton nom chez le chef d\'équipe.',
    key_title: 'Le remerciement',
    paypal_title: 'Pourboire avec PayPal',
    other_title: 'Autres façons de donner',
    honour_text: "Les virements et Bitcoin ne peuvent pas être confirmés ici automatiquement, alors c'est la confiance qui compte : une fois ton pourboire envoyé, clique sur le bouton et ta clé apparaît aussitôt. De toute façon elle ne débloque rien — elle dit seulement merci.",
    honour_button: "C'est envoyé — montre ma clé",
    bank_title: 'Virement bancaire',
    bank_holder: 'Titulaire du compte',
    bank_iban: 'IBAN',
    bank_bic: 'BIC',
    bank_name: 'Banque',
    bank_note: "Un compte suisse. Depuis l'extérieur de la Suisse et de la zone SEPA, un virement peut te coûter des frais.",
    btc_title: 'Bitcoin',
    btc_lightning: 'Lightning',
    btc_lightning_note: 'Lightning convient aux petits montants — rapide et presque sans frais.',
    btc_onchain: 'On-chain',
    btc_onchain_note: 'On-chain convient aux montants plus importants ; les frais du réseau ne dépendent pas du montant.',
    qr: 'Code QR : {what}',
    copy_short: 'Copier',
    amount: 'Montant',
    currency: 'Devise',
    amount_hint: 'De {min} à {max} — en dessous de {min}, les frais mangeraient le pourboire.',
    name: 'Nom sur la plaque de remerciement',
    name_hint: 'Facultatif, jusqu\'à 60 caractères ; il fait partie de la clé. Vide : « {default} ».',
    default_name: 'Mécène',
    server: 'Pour ce serveur',
    server_hint: 'L\'ID vient de la tirelire de ton bureau — la clé ne fonctionne que sur ce serveur.',
    no_id: 'Pour une clé de soutien, ouvre cette page depuis la tirelire de ton bureau (☕ chez le chef d\'équipe) — elle apporte l\'ID de ton serveur. Un pourboire sans est tout aussi bienvenu.',
    bad_id: 'L\'ID du serveur dans ce lien n\'est pas valide. Pour une clé de soutien, rouvre cette page depuis la tirelire de ton bureau (☕ chez le chef d\'équipe) — un pourboire sans est tout aussi bienvenu.',
    not_set_up: 'La tirelire n\'est pas encore prête — reviens plus tard.',
    sdk_failed: 'PayPal n\'a pas pu être chargé. Un bloqueur de contenu ? Autorise paypal.com et recharge la page.',
    working: 'Un instant …',
    cancelled: 'Annulé — rien n\'a été payé.',
    thanks_title: 'Merci !',
    thanks_key: 'Merci, {name} ! Voici ta clé de soutien :',
    thanks_nokey: 'Merci ! Ton pourboire est arrivé — toute l\'équipe s\'en réjouit.',
    key_how: 'Dans le bureau : ☕ chez le chef d\'équipe → « Saisir la clé… », colle-la, c\'est fait. Perdue ? Rouvre cette page depuis la tirelire — elle affiche la clé.',
    copy: 'Copier la clé',
    copied: 'Copiée !',
    have_title: 'Ta clé de soutien',
    have_text: 'Ce serveur a déjà une clé de soutien — pour « {name} », du {date}. La revoici :',
    have_more: 'Un autre pourboire reste le bienvenu ; la clé la plus récente prend alors sa place.',
    pending: 'PayPal traite encore ton paiement. Dès qu\'il est passé, ta clé apparaît ici — vérifie à nouveau dans un moment.',
    check_again: 'Vérifier à nouveau',
    privacy: 'Les paiements passent par PayPal. Cette page ne garde que l\'ID du serveur, le nom que tu as saisi et la date — rien de ce que PayPal sait de toi.',
    free: 'L\'Unraid Secretary Office est un logiciel libre (GPL-3.0).',
    languages: 'Langue',
    err_bad_amount: 'Saisis un montant de {min} à {max}, p. ex. 5 ou 5.50.',
    err_bad_name: 'Le nom peut avoir jusqu\'à 60 caractères, sans caractères de contrôle.',
    err_bad_currency: 'Choisis USD, EUR ou CHF.',
    err_rate_limited: 'Trop d\'essais en peu de temps — attends quelques minutes.',
    err_server_config: 'La tirelire n\'est pas entièrement configurée — réessaie plus tard. Rien n\'a été débité.',
    err_not_completed: 'PayPal n\'a pas finalisé ce paiement, il n\'y a donc pas de clé.',
    err_mismatch: 'La réponse de PayPal ne correspond pas à cette commande, aucune clé n\'a donc été créée.',
    err_unknown_order: 'Cette commande est inconnue ici (ou trop ancienne).',
    err_bad_id: "L'ID du serveur n'est pas valide — ouvre cette page depuis la tirelire de ton bureau.",
    err_bad_via: 'Choisis comment tu as envoyé ton pourboire.',
    referral_text: 'Tu achètes ou mets à niveau une licence Unraid ? Ce lien d\'affiliation soutient le bureau sans surcoût pour toi.',
    referral_link: 'Licences Unraid sur unraid.net',
    referral_tag: 'Lien d\'affiliation',
    referral_note: 'unraid.net voit que tu arrives par ce lien — c\'est ainsi que naît la commission. Cette page elle-même ne compte rien.',
    err_generic: 'Quelque chose s\'est mal passé. Si PayPal t\'a débité, clique dans un instant sur « Vérifier à nouveau » — ta clé apparaît dès que le paiement est confirmé.',
  },
  es: {
    title: 'Bote de propinas · Unraid Secretary Office',
    h1: 'Bote de propinas',
    intro: '¡Gracias por usar la Unraid Secretary Office! Es gratuita y completa, y así seguirá — nada está bloqueado, ni hoy ni más adelante.',
    ask: 'Si el equipo hace un buen trabajo para ti, una propina de cualquier importe es bienvenida — digamos 5, un café para el equipo.',
    credit: 'Una parte de las propinas es para {helmi}, que escribió las herramientas con las que trabaja Jack Emby: EmbyCache y «Agrupar carpetas».',
    shelter: 'Si las propinas superan lo que nos cuesta nuestro trabajo, donamos el resto a refugios de animales que necesitan con urgencia apoyo económico.',
    key_explain: 'Como agradecimiento recibes una clave de apoyo. No desbloquea nada — no hay nada que desbloquear. Solo detiene los recordatorios de la oficina y pone un pequeño «☕ Gracias» con tu nombre junto al jefe de equipo.',
    key_title: 'El agradecimiento',
    paypal_title: 'Propina con PayPal',
    other_title: 'Otras formas de dar',
    honour_text: 'Las transferencias y Bitcoin no se pueden confirmar aquí automáticamente, así que funciona por confianza: cuando hayas enviado tu propina, pulsa el botón y tu clave aparece al instante. De todos modos no desbloquea nada — solo da las gracias.',
    honour_button: 'Ya lo envié — mostrar mi clave',
    bank_title: 'Transferencia bancaria',
    bank_holder: 'Titular',
    bank_iban: 'IBAN',
    bank_bic: 'BIC',
    bank_name: 'Banco',
    bank_note: 'Una cuenta suiza. Desde fuera de Suiza y de la zona SEPA, una transferencia puede costarte comisiones.',
    btc_title: 'Bitcoin',
    btc_lightning: 'Lightning',
    btc_lightning_note: 'Lightning es ideal para importes pequeños — rápido y casi sin comisiones.',
    btc_onchain: 'On-chain',
    btc_onchain_note: 'On-chain compensa para importes mayores; la comisión de la red no depende del importe.',
    qr: 'Código QR: {what}',
    copy_short: 'Copiar',
    amount: 'Importe',
    currency: 'Moneda',
    amount_hint: 'De {min} a {max} — por debajo de {min} las comisiones se comerían la propina.',
    name: 'Nombre en la placa de agradecimiento',
    name_hint: 'Opcional, hasta 60 caracteres; pasa a formar parte de la clave. Vacío: «{default}».',
    default_name: 'Mecenas',
    server: 'Para este servidor',
    server_hint: 'El ID viene del bote de propinas de tu oficina — la clave solo funciona en este servidor.',
    no_id: 'Para una clave de apoyo, abre esta página desde el bote de propinas de tu oficina (☕ junto al jefe de equipo) — trae consigo el ID de tu servidor. Una propina sin él es igual de bienvenida.',
    bad_id: 'El ID del servidor de este enlace no es válido. Para una clave de apoyo, vuelve a abrir esta página desde el bote de propinas de tu oficina (☕ junto al jefe de equipo) — una propina sin él es igual de bienvenida.',
    not_set_up: 'El bote de propinas aún no está listo — vuelve más tarde.',
    sdk_failed: 'No se pudo cargar PayPal. ¿Un bloqueador de contenido? Permite paypal.com y recarga la página.',
    working: 'Un momento …',
    cancelled: 'Cancelado — no se ha pagado nada.',
    thanks_title: '¡Gracias!',
    thanks_key: '¡Gracias, {name}! Aquí tienes tu clave de apoyo:',
    thanks_nokey: '¡Gracias! Tu propina ha llegado — todo el equipo se alegra.',
    key_how: 'En la oficina: ☕ junto al jefe de equipo → «Introducir clave…», pégala y listo. ¿Perdida? Vuelve a abrir esta página desde el bote de propinas — muestra la clave.',
    copy: 'Copiar clave',
    copied: '¡Copiada!',
    have_title: 'Tu clave de apoyo',
    have_text: 'Este servidor ya tiene una clave de apoyo — para «{name}», del {date}. Aquí está de nuevo:',
    have_more: 'Otra propina sigue siendo bienvenida; la clave más reciente ocupa entonces su lugar.',
    pending: 'PayPal todavía está procesando tu pago. En cuanto se complete, tu clave aparece aquí — vuelve a comprobarlo dentro de un rato.',
    check_again: 'Comprobar de nuevo',
    privacy: 'Los pagos pasan por PayPal. Esta página solo guarda el ID del servidor, el nombre que escribiste y la fecha — nada de lo que PayPal sabe de ti.',
    free: 'La Unraid Secretary Office es software libre (GPL-3.0).',
    languages: 'Idioma',
    err_bad_amount: 'Introduce un importe de {min} a {max}, p. ej. 5 o 5.50.',
    err_bad_name: 'El nombre puede tener hasta 60 caracteres, sin caracteres de control.',
    err_bad_currency: 'Elige USD, EUR o CHF.',
    err_rate_limited: 'Demasiados intentos en poco tiempo — espera unos minutos.',
    err_server_config: 'El bote de propinas no está configurado del todo — inténtalo más tarde. No se ha cobrado nada.',
    err_not_completed: 'PayPal no completó este pago, así que no hay clave para él.',
    err_mismatch: 'La respuesta de PayPal no coincide con este pedido, así que no se creó ninguna clave.',
    err_unknown_order: 'Este pedido es desconocido aquí (o demasiado antiguo).',
    err_bad_id: 'El ID del servidor no es válido — abre esta página desde el bote de propinas de tu oficina.',
    err_bad_via: 'Elige cómo enviaste tu propina.',
    referral_text: '¿Vas a comprar o ampliar una licencia de Unraid? Este enlace de afiliado apoya a la oficina sin coste adicional para ti.',
    referral_link: 'Licencias de Unraid en unraid.net',
    referral_tag: 'Enlace de afiliado',
    referral_note: 'unraid.net ve que llegas por este enlace — así se genera la comisión. Esta página en sí no cuenta nada.',
    err_generic: 'Algo ha salido mal. Si PayPal te ha cobrado, pulsa en un momento «Comprobar de nuevo» — tu clave aparece en cuanto se confirme el pago.',
  },
};

/* ───────────────────────── entry ───────────────────────── */

export default {
  async fetch(request, env, ctx) {
    try {
      return await route(request, env, ctx);
    } catch (e) {
      if (e instanceof Fail) {
        if (e.note) console.error(`support: ${e.note}`);
        return apiError(e.status, e.code, e.headers);
      }
      console.error(`support: unexpected ${e && e.name}: ${String(e && e.message).slice(0, 200)}`);
      return apiError(500, 'internal');
    }
  },
};

/** An expected failure: HTTP status, an error code for the page, an optional note for the log (never a body) */
class Fail extends Error {
  constructor(status, code, note = '', headers = {}) {
    super(code);
    this.status = status;
    this.code = code;
    this.note = note;
    this.headers = headers;
  }
}

async function route(request, env, ctx) {
  const url = new URL(request.url);
  const method = request.method;
  switch (url.pathname) {
    case '/':
      allow(method, ['GET', 'HEAD']);
      return page(request, env, ctx, url);
    case '/api/order':
      allow(method, ['POST']);
      return createOrder(request, env, ctx);
    case '/api/capture':
      allow(method, ['POST']);
      return captureOrder(request, env, ctx);
    case '/api/honour':
      allow(method, ['POST']);
      return honour(request, env, ctx);
    case '/api/key':
      allow(method, ['GET']);
      return lookupKey(request, env, ctx, url);
    case '/api/health':
      allow(method, ['GET']);
      return health(env);
    default:
      throw new Fail(404, 'not_found');
  }
}

function allow(method, methods) {
  if (!methods.includes(method)) throw new Fail(405, 'method_not_allowed', '', { Allow: methods.join(', ') });
}

/* ───────────────────────── responses ───────────────────────── */

const BASE_HEADERS = {
  'Cache-Control': 'no-store',
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'DENY',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'X-Robots-Tag': 'noindex',
  'Strict-Transport-Security': 'max-age=31536000',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
};

function json(status, data, headers = {}) {
  return new Response(JSON.stringify(data), {
    status,
    headers: {
      ...BASE_HEADERS,
      'Content-Type': 'application/json; charset=utf-8',
      'Content-Security-Policy': "default-src 'none'; frame-ancestors 'none'",
      ...headers,
    },
  });
}

function apiError(status, code, headers = {}) {
  return json(status, { ok: false, error: code }, headers);
}

/* ───────────────────────── validation ───────────────────────── */

/** A server ID as the office makes it (upper-case hex, XXXX-XXXX-XXXX-XXXX); null for anything else */
function serverId(raw) {
  if (typeof raw !== 'string' || raw.length > 40) return null;
  const id = raw.toUpperCase();
  return ID_RE.test(id) ? id : null;
}

/** "5", "5.5", "5,50", 5 → "5.00"; null when not a plain amount within the limits */
function amountOf(raw) {
  let s = typeof raw === 'number' && Number.isFinite(raw) ? String(raw) : raw;
  if (typeof s !== 'string' || s.length > 12) return null;
  s = s.trim().replace(',', '.');
  const m = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  const cents = Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0'));
  if (cents < AMOUNT_MIN_CENTS || cents > AMOUNT_MAX_CENTS) return null;
  return centsText(cents);
}

function centsText(cents) {
  return `${Math.floor(cents / 100)}.${String(cents % 100).padStart(2, '0')}`;
}

/** PayPal's "5.00" (or "5") → 500; NaN for anything else */
function centsOf(value) {
  const m = typeof value === 'string' ? /^(\d{1,9})(?:\.(\d{1,2}))?$/.exec(value) : null;
  return m ? Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0')) : NaN;
}

/**
 * The name on the thank-you plate: trimmed, 1–60 printable characters — exactly what the office
 * accepts (officeSupporterNameOk); empty → the language's neutral word. undefined = invalid.
 */
function plateName(raw, lang) {
  if (raw === undefined || raw === null) return TEXT[lang].default_name;
  if (typeof raw !== 'string' || raw.length > 600) return undefined;
  const name = raw.normalize('NFC').trim();
  if (name === '') return TEXT[lang].default_name;
  if ([...name].length > NAME_MAX || !NAME_RE.test(name)) return undefined;
  return name;
}

function langOf(raw) {
  return typeof raw === 'string' && LANGS.includes(raw.toLowerCase()) ? raw.toLowerCase() : null;
}

/** ?lang=, else the browser's first language the page speaks, else English */
function pickLang(param, acceptLanguage) {
  const direct = langOf(param);
  if (direct) return direct;
  for (const part of String(acceptLanguage || '').split(',').slice(0, 20)) {
    const code = langOf(part.trim().split(';')[0].slice(0, 2));
    if (code) return code;
  }
  return 'en';
}

/** CHF in Switzerland, USD in the US, EUR in the euro area — else by the browser's language */
function pickCurrency(request, lang) {
  const country = String((request.cf && request.cf.country) || '').toUpperCase();
  if (country === 'CH' || country === 'LI') return 'CHF';
  if (country === 'US') return 'USD';
  if (EURO.has(country)) return 'EUR';
  const al = String(request.headers.get('accept-language') || '');
  if (/-CH\b/i.test(al)) return 'CHF';
  return lang === 'en' ? 'USD' : 'EUR';
}

/** The JSON body of a same-origin POST, only the listed fields */
async function readJson(request, fields) {
  const url = new URL(request.url);
  const origin = request.headers.get('origin');
  const site = request.headers.get('sec-fetch-site');
  if ((origin !== null && origin !== url.origin) || (site !== null && site !== 'same-origin')) {
    throw new Fail(403, 'forbidden');
  }
  if (!/^application\/json\s*(?:;|$)/i.test(request.headers.get('content-type') || '')) {
    throw new Fail(415, 'bad_request');
  }
  const length = request.headers.get('content-length');
  if (length !== null && (!/^\d{1,9}$/.test(length) || Number(length) > BODY_MAX)) throw new Fail(413, 'bad_request');
  // read at most BODY_MAX bytes, whatever the header said (HTTP/2 may send none)
  const chunks = [];
  let size = 0;
  if (request.body) {
    const reader = request.body.getReader();
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > BODY_MAX) {
        reader.cancel().catch(() => {});
        throw new Fail(413, 'bad_request');
      }
      chunks.push(value);
    }
  }
  let text;
  try {
    text = new TextDecoder('utf-8', { fatal: true }).decode(concat(...chunks));
  } catch (e) {
    throw new Fail(400, 'bad_request');
  }
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    throw new Fail(400, 'bad_request');
  }
  if (data === null || typeof data !== 'object' || Array.isArray(data)) throw new Fail(400, 'bad_request');
  for (const key of Object.keys(data)) {
    if (!fields.includes(key)) throw new Fail(400, 'bad_request');
  }
  return data;
}

/* ───────────────────────── rate limit ───────────────────────── */

const memo = new Map();   // this isolate's counters: "<bucket>:<who>:<slot>" → count

/**
 * Light rate limit per client IP (hashed, never stored as such) — or per `subject` (a server ID,
 * 'all'). Counted in this isolate's memory plus the Cache API (per data centre; on *.workers.dev
 * the Cache API does nothing, the memory still counts); the rate limiting binding, when there is
 * one (wrangler.toml), can only make it stricter.
 */
async function limited(request, env, ctx, bucket, subject = null) {
  const [max, seconds] = LIMITS[bucket];
  const ip = request.headers.get('cf-connecting-ip') || request.headers.get('x-real-ip') || 'unknown';
  const who = (await sha256hex(`uso-support-rl:${subject === null ? `ip:${ip}` : `s:${subject}`}`)).slice(0, 32);
  if (env.RATE_LIMITER && typeof env.RATE_LIMITER.limit === 'function') {
    try {
      const { success } = await env.RATE_LIMITER.limit({ key: `${bucket}:${who}` });
      if (!success) return true;
    } catch (e) {
      // the counters below decide
    }
  }
  const slot = Math.floor(Date.now() / 1000 / seconds);
  const id = `${bucket}:${who}:${slot}`;
  let count = (memo.get(id) || 0) + 1;
  memo.set(id, count);
  if (memo.size > 5000) {
    for (const k of memo.keys()) {
      if (!k.endsWith(`:${slot}`)) memo.delete(k);
    }
  }
  const cache = typeof caches !== 'undefined' && caches && caches.default;
  if (cache) {
    try {
      const key = new Request(`${new URL(request.url).origin}/__rate/${id}`);
      const hit = await cache.match(key);
      const shared = (hit ? parseInt(await hit.text(), 10) || 0 : 0) + 1;
      count = Math.max(count, shared);
      const put = cache.put(key, new Response(String(shared), { headers: { 'Cache-Control': `max-age=${seconds}` } }));
      if (ctx && typeof ctx.waitUntil === 'function') ctx.waitUntil(put.catch(() => {}));
      else await put.catch(() => {});
    } catch (e) {
      // the memory count stands
    }
  }
  return count > max;
}

async function limit(request, env, ctx, bucket, subject = null) {
  if (await limited(request, env, ctx, bucket, subject)) throw new Fail(429, 'rate_limited', '', { 'Retry-After': '120' });
}

/* ───────────────────────── crypto ───────────────────────── */

const enc = new TextEncoder();

function b64url(bytes) {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(text) {
  if (!/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) return null;
  const bin = atob(text.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (text.length % 4)) % 4));
  return Uint8Array.from(bin, (c) => c.charCodeAt(0));
}

async function sha256hex(text) {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(text)));
  return Array.from(digest, (b) => b.toString(16).padStart(2, '0')).join('');
}

function concat(...parts) {
  const out = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let at = 0;
  for (const p of parts) {
    out.set(p, at);
    at += p.length;
  }
  return out;
}

function derLength(n) {
  if (n < 0x80) return Uint8Array.of(n);
  if (n < 0x100) return Uint8Array.of(0x81, n);
  return Uint8Array.of(0x82, n >> 8, n & 0xff);
}

function der(tag, body) {
  return concat(Uint8Array.of(tag), derLength(body.length), body);
}

/** WebCrypto's ECDSA signature (raw r||s, IEEE P1363) → DER: SEQUENCE { INTEGER r, INTEGER s } */
function p1363ToDer(raw) {
  if (raw.length !== 64) throw new Error('unexpected signature length');
  const int = (bytes) => {
    let i = 0;
    while (i < bytes.length - 1 && bytes[i] === 0) i++;
    let v = bytes.slice(i);
    if (v[0] & 0x80) v = concat(Uint8Array.of(0), v);
    return der(0x02, v);
  };
  return der(0x30, concat(int(raw.slice(0, 32)), int(raw.slice(32))));
}

/** The DER inside a PEM block with one of the labels, or null */
function pemBody(pem, labels) {
  for (const label of labels) {
    const m = new RegExp(`-----BEGIN ${label}-----([A-Za-z0-9+/=\\s]+)-----END ${label}-----`).exec(pem);
    if (m) {
      try {
        return { label, der: Uint8Array.from(atob(m[1].replace(/\s+/g, '')), (c) => c.charCodeAt(0)) };
      } catch (e) {
        return null;
      }
    }
  }
  return null;
}

// AlgorithmIdentifier { id-ecPublicKey, prime256v1 }
const EC_P256_ALG = Uint8Array.of(0x30, 0x13, 0x06, 0x07, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x02, 0x01,
  0x06, 0x08, 0x2a, 0x86, 0x48, 0xce, 0x3d, 0x03, 0x01, 0x07);

/** A SEC1 ECPrivateKey (BEGIN EC PRIVATE KEY) wrapped as PKCS#8 PrivateKeyInfo, which WebCrypto imports */
function sec1ToPkcs8(sec1) {
  return der(0x30, concat(Uint8Array.of(0x02, 0x01, 0x00), EC_P256_ALG, der(0x04, sec1)));
}

let signerCache = null;   // { pem, pub, key } — key null when SUPPORTER_KEY is unusable or doesn't match

/** The signing key, once checked against the office's public key; null when it can't be used */
async function signer(env) {
  const pem = typeof env.SUPPORTER_KEY === 'string' ? env.SUPPORTER_KEY : '';
  const pub = typeof env.SUPPORTER_PUBLIC_KEY === 'string' && env.SUPPORTER_PUBLIC_KEY.trim() !== ''
    ? env.SUPPORTER_PUBLIC_KEY : OFFICE_PUBLIC_KEY;
  if (signerCache && signerCache.pem === pem && signerCache.pub === pub) return signerCache.key;
  let key = null;
  try {
    const found = pemBody(pem, ['PRIVATE KEY', 'EC PRIVATE KEY']);
    const spki = pemBody(pub, ['PUBLIC KEY']);
    if (found && spki) {
      const pkcs8 = found.label === 'EC PRIVATE KEY' ? sec1ToPkcs8(found.der) : found.der;
      const algo = { name: 'ECDSA', namedCurve: 'P-256' };
      const priv = await crypto.subtle.importKey('pkcs8', pkcs8, algo, false, ['sign']);
      const verifier = await crypto.subtle.importKey('spki', spki.der, algo, false, ['verify']);
      const probe = enc.encode(`uso-supporter-self-test:${Date.now()}`);
      const sig = await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, priv, probe);
      if (await crypto.subtle.verify({ name: 'ECDSA', hash: 'SHA-256' }, verifier, sig, probe)) key = priv;
    }
  } catch (e) {
    key = null;
  }
  signerCache = { pem, pub, key };
  return key;
}

/** A supporter key: USO1.<b64url(payload)>.<b64url(DER signature)> */
async function makeKey(env, id, name, date) {
  const key = await signer(env);
  if (!key) throw new Fail(503, 'server_config', 'SUPPORTER_KEY unusable');
  const payload = JSON.stringify({ v: 1, id, name, date });   // this key order, compact, UTF-8
  const head = `USO1.${b64url(enc.encode(payload))}`;
  const raw = new Uint8Array(await crypto.subtle.sign({ name: 'ECDSA', hash: 'SHA-256' }, key, enc.encode(head)));
  return `${head}.${b64url(p1363ToDer(raw))}`;
}

/** The name and date a stored key carries (for display); null when it can't be read */
function keyInfo(key) {
  const m = /^USO1\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/.exec(typeof key === 'string' ? key : '');
  const bytes = m && b64urlDecode(m[1]);
  if (!bytes) return null;
  try {
    const data = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
    return typeof data.name === 'string' && typeof data.date === 'string' ? { name: data.name, date: data.date } : null;
  } catch (e) {
    return null;
  }
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

/* ───────────────────────── settings ───────────────────────── */

function setting(env, name) {
  return typeof env[name] === 'string' ? env[name].trim() : '';
}

function paypalEnv(env) {
  const v = setting(env, 'PAYPAL_ENV');
  return v === 'live' || v === 'sandbox' ? v : null;
}

function clientId(env) {
  const v = setting(env, 'PAYPAL_CLIENT_ID');
  return /^[A-Za-z0-9_-]{10,200}$/.test(v) ? v : null;
}

function kv(env) {
  const store = env.SUPPORT_KV;
  return store && typeof store.get === 'function' && typeof store.put === 'function' ? store : null;
}

/** Is PayPal set up (the page shows its buttons)? */
function paypalReady(env) {
  return Boolean(paypalEnv(env) && clientId(env) && setting(env, 'PAYPAL_CLIENT_SECRET').length >= 10);
}

/** A line of text a person typed into a setting: 1–max printable characters */
function textOk(text, max) {
  return text !== '' && [...text].length <= max && NAME_RE.test(text);
}

/** IBAN with its check digits right (ISO 13616: mod 97 = 1) */
function ibanOk(iban) {
  if (!/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/.test(iban)) return false;
  let rest = 0;
  for (const c of iban.slice(4) + iban.slice(0, 4)) {
    const v = c >= 'A' ? String(c.charCodeAt(0) - 55) : c;
    for (const d of v) rest = (rest * 10 + Number(d)) % 97;
  }
  return rest === 1;
}

const BECH32 = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

function bech32Polymod(values) {
  const GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
  let chk = 1;
  for (const v of values) {
    const top = chk >>> 25;
    chk = ((chk & 0x1ffffff) << 5) ^ v;
    for (let i = 0; i < 5; i++) if ((top >>> i) & 1) chk ^= GEN[i];
  }
  return chk >>> 0;
}

function bech32HrpExpand(hrp) {
  const codes = [...hrp].map((c) => c.charCodeAt(0));
  return [...codes.map((c) => c >> 5), 0, ...codes.map((c) => c & 31)];
}

/** bech32 of bytes (LNURL, LUD-01: no length limit) — lower case */
function bech32Encode(hrp, bytes) {
  const data = [];
  let acc = 0;
  let bits = 0;
  for (const b of bytes) {
    acc = ((acc << 8) | b) & 0xfff;
    bits += 8;
    while (bits >= 5) {
      bits -= 5;
      data.push((acc >>> bits) & 31);
    }
  }
  if (bits > 0) data.push((acc << (5 - bits)) & 31);
  const mod = bech32Polymod([...bech32HrpExpand(hrp), ...data, 0, 0, 0, 0, 0, 0]) ^ 1;
  const check = [0, 1, 2, 3, 4, 5].map((i) => (mod >>> (5 * (5 - i))) & 31);
  return `${hrp}1${[...data, ...check].map((d) => BECH32[d]).join('')}`;
}

/** A Bitcoin main-net address: bech32/bech32m (bc1…, checksum checked) or base58 (1…/3…, form only) */
function bitcoinAddressOk(address) {
  if (/^[13][1-9A-HJ-NP-Za-km-z]{25,34}$/.test(address)) return true;
  const m = /^bc1([qpzry9x8gf2tvdw0s3jn54khce6mua7l]{8,87})$/.exec(address);
  if (!m) return false;
  const check = bech32Polymod([...bech32HrpExpand('bc'), ...[...m[1]].map((c) => BECH32.indexOf(c))]);
  const version = BECH32.indexOf(m[1][0]);
  return (version === 0 && check === 1) || (version >= 1 && version <= 16 && check === 0x2bc830a3);
}

/**
 * The ways to give besides PayPal, from the settings — each only when set and valid
 * (problems: the settings that are set but wrong).
 */
function methods(env) {
  const out = { bank: null, lightning: null, onchain: null, problems: [] };
  const iban = setting(env, 'BANK_IBAN').replace(/\s+/g, '').toUpperCase();
  const holder = setting(env, 'BANK_HOLDER');
  const bic = setting(env, 'BANK_BIC').replace(/\s+/g, '').toUpperCase();
  const bankName = setting(env, 'BANK_NAME');
  if (iban || holder || bic || bankName) {
    const bad = [];
    if (!ibanOk(iban)) bad.push('BANK_IBAN');
    if (!textOk(holder, 100)) bad.push('BANK_HOLDER');
    if (bic && !/^[A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?$/.test(bic)) bad.push('BANK_BIC');
    if (bankName && !textOk(bankName, 100)) bad.push('BANK_NAME');
    if (bad.length) out.problems.push(...bad);
    else out.bank = { iban, holder, bic, name: bankName };
  }
  const ln = setting(env, 'BTC_LIGHTNING').toLowerCase();
  if (ln) {
    const m = /^([a-z0-9._+-]{1,64})@((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63})$/.exec(ln);
    if (m) {
      const url = `https://${m[2]}/.well-known/lnurlp/${m[1]}`;   // LUD-16
      out.lightning = { address: ln, lnurl: bech32Encode('lnurl', enc.encode(url)).toUpperCase() };
    } else {
      out.problems.push('BTC_LIGHTNING');
    }
  }
  let onchain = setting(env, 'BTC_ADDRESS');
  if (/^bc1/i.test(onchain)) onchain = onchain.toLowerCase();
  if (onchain) {
    if (bitcoinAddressOk(onchain)) out.onchain = onchain;
    else out.problems.push('BTC_ADDRESS');
  }
  return out;
}

/**
 * The Unraid affiliate link (Lime Technology's «Unraid Ambassadors»): https only, on unraid.net
 * or a subdomain of it, no user, no port. undefined = not set; null = set but refused.
 */
function referral(env) {
  const raw = setting(env, 'UNRAID_REFERRAL_URL');
  if (raw === '') return undefined;
  if (raw.length > 500 || /\s/.test(raw)) return null;
  let url;
  try {
    url = new URL(raw);
  } catch (e) {
    return null;
  }
  const host = url.hostname;
  if (url.protocol !== 'https:' || url.username !== '' || url.password !== '' || url.port !== ''
      || !(host === 'unraid.net' || host.endsWith('.unraid.net'))) {
    return null;
  }
  return url.href;
}

/** Is this way of giving offered on the page? */
function viaOffered(env, via) {
  const m = methods(env);
  return (via === 'bank' && Boolean(m.bank)) || (via === 'bitcoin' && Boolean(m.lightning || m.onchain));
}

/** What is missing or wrong in the settings (names only, never values) */
async function problems(env) {
  const out = [];
  if (!paypalEnv(env)) out.push('PAYPAL_ENV');
  if (!clientId(env)) out.push('PAYPAL_CLIENT_ID');
  if (setting(env, 'PAYPAL_CLIENT_SECRET').length < 10) out.push('PAYPAL_CLIENT_SECRET');
  if (!kv(env)) out.push('SUPPORT_KV');
  if (!(await signer(env))) out.push('SUPPORTER_KEY');
  out.push(...methods(env).problems);
  if (referral(env) === null) out.push('UNRAID_REFERRAL_URL');
  return out;
}

async function ready(env) {
  const missing = (await problems(env)).filter((p) => !/^(?:BANK_|BTC_|UNRAID_REFERRAL_URL$)/.test(p));
  if (missing.length) throw new Fail(503, 'server_config', `not ready: ${missing.join(', ')}`);
}

async function health(env) {
  const missing = await problems(env);
  const m = methods(env);
  return json(missing.length ? 503 : 200, {
    ok: missing.length === 0,
    env: paypalEnv(env),
    methods: { paypal: paypalReady(env), bank: Boolean(m.bank), lightning: Boolean(m.lightning), onchain: Boolean(m.onchain) },
    referral: Boolean(referral(env)),
    problems: missing,
  });
}

/* ───────────────────────── PayPal ───────────────────────── */

function apiBase(env) {
  return paypalEnv(env) === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

let tokenCache = null;   // { who, token, until }

async function paypalToken(env, fresh) {
  const who = `${paypalEnv(env)}:${clientId(env)}`;
  if (!fresh && tokenCache && tokenCache.who === who && tokenCache.until > Date.now()) return tokenCache.token;
  let res;
  try {
    res = await fetch(`${apiBase(env)}/v1/oauth2/token`, {
      method: 'POST',
      headers: {
        Authorization: `Basic ${btoa(`${clientId(env)}:${setting(env, 'PAYPAL_CLIENT_SECRET')}`)}`,
        'Content-Type': 'application/x-www-form-urlencoded',
        Accept: 'application/json',
      },
      body: 'grant_type=client_credentials',
      signal: AbortSignal.timeout(PAYPAL_TIMEOUT),
    });
  } catch (e) {
    throw new Fail(502, 'paypal', 'PayPal token: unreachable');
  }
  const data = await res.json().catch(() => null);
  if (!res.ok || !data || typeof data.access_token !== 'string' || data.access_token === '') {
    throw new Fail(502, 'paypal', `PayPal token: HTTP ${res.status}`);
  }
  const seconds = Math.max(60, (Number(data.expires_in) || 600) - 120);
  tokenCache = { who, token: data.access_token, until: Date.now() + seconds * 1000 };
  return data.access_token;
}

/** One call to PayPal's REST API → { status, data } (a 401 gets a fresh token and one more try) */
async function paypal(env, method, path, body, headers = {}) {
  for (let attempt = 0; ; attempt++) {
    const token = await paypalToken(env, attempt > 0);
    let res;
    try {
      res = await fetch(`${apiBase(env)}${path}`, {
        method,
        headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json', ...headers },
        body: body === undefined ? undefined : JSON.stringify(body),
        signal: AbortSignal.timeout(PAYPAL_TIMEOUT),
      });
    } catch (e) {
      throw new Fail(502, 'paypal', `PayPal ${method} ${path.split('/').slice(0, 4).join('/')}: unreachable`);
    }
    if (res.status === 401 && attempt === 0) continue;
    const data = await res.json().catch(() => null);
    return { status: res.status, data };
  }
}

/** PayPal's error name/issue and debug id — for the log; nothing personal in them */
function paypalNote(what, r) {
  const d = r.data && typeof r.data === 'object' ? r.data : {};
  const issue = Array.isArray(d.details) && d.details[0] && typeof d.details[0].issue === 'string' ? d.details[0].issue : '';
  return `${what}: HTTP ${r.status} ${String(d.name || '').slice(0, 60)} ${issue.slice(0, 60)} debug_id=${String(d.debug_id || '').slice(0, 40)}`;
}

function paypalIssue(r) {
  const d = r.data && typeof r.data === 'object' ? r.data : {};
  return Array.isArray(d.details) && d.details[0] && typeof d.details[0].issue === 'string' ? d.details[0].issue : '';
}

/* ───────────────────────── API ───────────────────────── */

async function createOrder(request, env, ctx) {
  await limit(request, env, ctx, 'order');
  const body = await readJson(request, ['id', 'amount', 'currency', 'name', 'lang']);
  const lang = langOf(body.lang) || 'en';
  let id = null;
  if (body.id !== undefined && body.id !== null && body.id !== '') {
    id = serverId(body.id);
    if (!id) throw new Fail(400, 'bad_id');
  }
  const amount = amountOf(body.amount);
  if (!amount) throw new Fail(400, 'bad_amount');
  if (typeof body.currency !== 'string' || !CURRENCIES.includes(body.currency)) throw new Fail(400, 'bad_currency');
  const currency = body.currency;
  let name = null;
  if (id) {
    name = plateName(body.name, lang);
    if (name === undefined) throw new Fail(400, 'bad_name');
  } else if (body.name !== undefined && body.name !== null && body.name !== '') {
    if (plateName(body.name, lang) === undefined) throw new Fail(400, 'bad_name');   // checked, then dropped: no key, no name
  }
  await ready(env);

  const unit = {
    reference_id: 'tip',
    description: DESCRIPTION,
    amount: { currency_code: currency, value: amount },
  };
  if (id) unit.custom_id = id;
  const r = await paypal(env, 'POST', '/v2/checkout/orders', {
    intent: 'CAPTURE',
    purchase_units: [unit],
    application_context: { brand_name: BRAND, shipping_preference: 'NO_SHIPPING', user_action: 'PAY_NOW' },
  }, { 'PayPal-Request-Id': crypto.randomUUID(), Prefer: 'return=minimal' });
  const order = r.data;
  if ((r.status !== 201 && r.status !== 200) || !order || typeof order.id !== 'string' || !ORDER_ID_RE.test(order.id)
      || order.status !== 'CREATED') {
    throw new Fail(502, 'paypal', paypalNote('create order', r));
  }
  const record = { id, amount, currency, name, created: new Date().toISOString() };
  await kv(env).put(`order:${order.id}`, JSON.stringify(record), { expirationTtl: ORDER_TTL });
  return json(200, { ok: true, orderID: order.id });
}

async function captureOrder(request, env, ctx) {
  await limit(request, env, ctx, 'capture');
  const body = await readJson(request, ['orderID']);
  const orderID = typeof body.orderID === 'string' && ORDER_ID_RE.test(body.orderID) ? body.orderID : null;
  if (!orderID) throw new Fail(400, 'bad_order');
  const store = kv(env);
  if (!store) throw new Fail(503, 'server_config', 'not ready: SUPPORT_KV');
  const record = await store.get(`order:${orderID}`, 'json');
  if (!validRecord(record)) throw new Fail(404, 'unknown_order');   // not an order this worker created: PayPal is not asked
  await ready(env);

  if (record.done) return json(200, await paidAnswer(env, record, record.paid || today()));

  let r;
  if (record.pending) {
    r = await paypal(env, 'GET', `/v2/checkout/orders/${encodeURIComponent(orderID)}`);
  } else {
    r = await paypal(env, 'POST', `/v2/checkout/orders/${encodeURIComponent(orderID)}/capture`, {},
      { 'PayPal-Request-Id': `uso-capture-${orderID}`, Prefer: 'return=representation' });
    if (r.status === 422 && paypalIssue(r) === 'ORDER_ALREADY_CAPTURED') {
      r = await paypal(env, 'GET', `/v2/checkout/orders/${encodeURIComponent(orderID)}`);
    }
  }
  if (r.status === 422 && paypalIssue(r) === 'INSTRUMENT_DECLINED') throw new Fail(402, 'declined');
  if (r.status === 422 && paypalIssue(r) === 'ORDER_NOT_APPROVED') throw new Fail(409, 'not_completed');
  if (r.status === 404) throw new Fail(404, 'unknown_order', paypalNote('capture', r));
  if ((r.status !== 200 && r.status !== 201) || !r.data || typeof r.data !== 'object') {
    throw new Fail(502, 'paypal', paypalNote('capture', r));
  }

  let order = r.data;
  let verdict = judge(order, record, orderID);
  if (verdict === 'no_custom_id') {   // the capture's answer left it out: ask for the whole order
    const again = await paypal(env, 'GET', `/v2/checkout/orders/${encodeURIComponent(orderID)}`);
    if (again.status !== 200 || !again.data) throw new Fail(502, 'paypal', paypalNote('get order', again));
    order = again.data;
    verdict = judge(order, record, orderID);
  }
  if (verdict === 'pending') {
    if (!record.pending) {
      await store.put(`order:${orderID}`, JSON.stringify({ ...record, pending: true }), { expirationTtl: ORDER_TTL });
    }
    return json(202, { ok: false, error: 'pending' });
  }
  if (verdict === 'not_completed') throw new Fail(409, 'not_completed', `capture ${orderID}: not completed`);
  if (verdict !== 'ok') throw new Fail(409, 'mismatch', `capture ${orderID}: ${verdict}`);

  // Paid. The key (kept for «lost key»), then the order is marked done — a second capture call
  // makes the same key again from the record. The buyer gets the key even if KV is failing.
  const date = today();
  const answer = await paidAnswer(env, record, date);
  if (answer.key) await keepKey(store, record.id, { key: answer.key, date, via: 'paypal' });
  const { pending, ...rest } = record;
  try {
    await store.put(`order:${orderID}`, JSON.stringify({ ...rest, done: true, paid: date }), { expirationTtl: ORDER_TTL });
  } catch (e) {
    console.error('support: KV put failed (order done)');
  }
  return json(200, answer);
}

function validRecord(r) {
  return r !== null && typeof r === 'object' && (r.id === null || (typeof r.id === 'string' && ID_RE.test(r.id)))
    && typeof r.amount === 'string' && amountOf(r.amount) === r.amount
    && CURRENCIES.includes(r.currency)
    && (r.id === null ? r.name === null : typeof r.name === 'string' && plateName(r.name, 'en') === r.name)
    && (r.paid === undefined || (typeof r.paid === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(r.paid)));
}

/** The answer for a paid order: a key for its server ID and name — none for a tip without an ID */
async function paidAnswer(env, record, date) {
  if (!record.id) return { ok: true, key: null, date, name: null, id: null };
  const key = await makeKey(env, record.id, record.name, date);
  return { ok: true, key, date, name: record.name, id: record.id };
}

/** Keep the newest key for a server ID (best effort: the key is shown either way) */
async function keepKey(store, id, value) {
  if (!store) return false;
  try {
    await store.put(`key:${id}`, JSON.stringify(value));
    return true;
  } catch (e) {
    console.error('support: KV put failed (key)');
    return false;
  }
}

/**
 * Is this PayPal order the paid order we created? 'ok' | 'pending' | 'not_completed' |
 * 'no_custom_id' | a reason for a mismatch. Looks only at status, amount, currency and custom_id —
 * never at the payer.
 */
function judge(order, record, orderID) {
  if (order.id !== orderID) return 'other order';
  const units = order.purchase_units;
  if (!Array.isArray(units) || units.length !== 1 || !units[0] || typeof units[0] !== 'object') return 'purchase units';
  const unit = units[0];
  const captures = unit.payments && Array.isArray(unit.payments.captures) ? unit.payments.captures : [];
  if (captures.length === 0) return order.status === 'COMPLETED' ? 'no capture' : 'not_completed';
  if (captures.length !== 1 || !captures[0] || typeof captures[0] !== 'object') return 'captures';
  const capture = captures[0];
  const amountOk = (a) => a && typeof a === 'object' && a.currency_code === record.currency
    && centsOf(a.value) === centsOf(record.amount);
  if (!amountOk(capture.amount)) return 'capture amount';
  if (unit.amount !== undefined && !amountOk(unit.amount)) return 'order amount';
  const custom = typeof unit.custom_id === 'string' ? unit.custom_id
    : typeof capture.custom_id === 'string' ? capture.custom_id : undefined;
  if (record.id && custom === undefined) return 'no_custom_id';
  if ((custom || null) !== record.id) return 'custom_id';
  if (capture.status === 'PENDING' && order.status === 'COMPLETED') return 'pending';
  if (order.status !== 'COMPLETED' || capture.status !== 'COMPLETED') return 'not_completed';
  return 'ok';
}

async function storedKey(env, id) {
  const store = kv(env);
  if (!store) return null;
  const stored = await store.get(`key:${id}`, 'json');
  const info = stored && keyInfo(stored.key);
  return info ? {
    key: stored.key,
    date: typeof stored.date === 'string' ? stored.date : info.date,
    name: info.name,
    via: VIAS.includes(stored.via) ? stored.via : null,
  } : null;
}

/**
 * «I've sent it — show my key»: bank transfers and Bitcoin can't be confirmed here, so the key
 * is given on trust — it unlocks nothing. Rate-limited per IP, per server ID and overall; the
 * same request again the same day gets the key already kept (no new write).
 */
async function honour(request, env, ctx) {
  await limit(request, env, ctx, 'honour');
  const body = await readJson(request, ['id', 'via', 'name', 'lang']);
  const lang = langOf(body.lang) || 'en';
  const id = serverId(body.id);
  if (!id) throw new Fail(400, 'bad_id');
  if (typeof body.via !== 'string' || body.via === 'paypal' || !VIAS.includes(body.via) || !viaOffered(env, body.via)) {
    throw new Fail(400, 'bad_via');
  }
  const name = plateName(body.name, lang);
  if (name === undefined) throw new Fail(400, 'bad_name');
  await limit(request, env, ctx, 'honour_id', id);
  await limit(request, env, ctx, 'honour_all', 'all');
  if (!(await signer(env))) throw new Fail(503, 'server_config', 'not ready: SUPPORTER_KEY');
  const date = today();
  let have = null;
  try {
    have = await storedKey(env, id);
  } catch (e) {
    have = null;
  }
  if (have && have.via === body.via && have.name === name && have.date === date) {
    return json(200, { ok: true, key: have.key, date, name, id });
  }
  const key = await makeKey(env, id, name, date);
  await keepKey(kv(env), id, { key, date, via: body.via });
  return json(200, { ok: true, key, date, name, id });
}

async function lookupKey(request, env, ctx, url) {
  const id = serverId(url.searchParams.get('id'));
  if (!id || [...url.searchParams.keys()].some((k) => k !== 'id')) throw new Fail(400, 'bad_id');
  await limit(request, env, ctx, 'lookup');
  if (!kv(env)) throw new Fail(503, 'server_config', 'not ready: SUPPORT_KV');
  const found = await storedKey(env, id);
  if (!found) throw new Fail(404, 'not_found');
  return json(200, { ok: true, id, ...found });
}

/* ──────────── QR codes: byte mode, error correction M, versions 1–10 (≤ 213 bytes) ──────────── */

const QR_ECC = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];   // ECC codewords per block, level M
const QR_BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];          // blocks, level M
const QR_MASKS = [
  (x, y) => (x + y) % 2 === 0,
  (x, y) => y % 2 === 0,
  (x) => x % 3 === 0,
  (x, y) => (x + y) % 3 === 0,
  (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
  (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
  (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
  (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
];

function qrRawModules(ver) {
  let n = (16 * ver + 128) * ver + 64;
  if (ver >= 2) {
    const align = Math.floor(ver / 7) + 2;
    n -= (25 * align - 10) * align - 55;
    if (ver >= 7) n -= 36;
  }
  return n;
}

function qrDataCodewords(ver) {
  return Math.floor(qrRawModules(ver) / 8) - QR_ECC[ver] * QR_BLOCKS[ver];
}

function qrAlignment(ver) {
  if (ver === 1) return [];
  const count = Math.floor(ver / 7) + 2;
  const step = Math.floor((ver * 8 + count * 3 + 5) / (count * 4 - 4)) * 2;
  const out = [6];
  for (let pos = ver * 4 + 10; out.length < count; pos -= step) out.splice(1, 0, pos);
  return out;
}

function gfMul(x, y) {
  let z = 0;
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d);
    z ^= ((y >>> i) & 1) * x;
  }
  return z;
}

function rsDivisor(degree) {
  const out = new Array(degree).fill(0);
  out[degree - 1] = 1;
  let root = 1;
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < degree; j++) {
      out[j] = gfMul(out[j], root);
      if (j + 1 < degree) out[j] ^= out[j + 1];
    }
    root = gfMul(root, 0x02);
  }
  return out;
}

function rsRemainder(data, divisor) {
  const out = new Array(divisor.length).fill(0);
  for (const b of data) {
    const factor = b ^ out.shift();
    out.push(0);
    divisor.forEach((c, i) => { out[i] ^= gfMul(c, factor); });
  }
  return out;
}

/** The penalty of a masked symbol (ISO 18004 §7.8.3) — the mask with the lowest wins */
function qrPenalty(m) {
  const n = m.length;
  let score = 0;
  const line = (cell) => {
    let s = 0;
    let run = 0;
    let prev = null;
    for (let i = 0; i < n; i++) {
      if (cell(i) === prev) {
        run++;
        if (run === 5) s += 3;
        else if (run > 5) s++;
      } else {
        prev = cell(i);
        run = 1;
      }
    }
    const at = (i) => i >= 0 && i < n && cell(i);
    for (let i = 0; i + 7 <= n; i++) {
      if (at(i) && !at(i + 1) && at(i + 2) && at(i + 3) && at(i + 4) && !at(i + 5) && at(i + 6)) {
        const before = !at(i - 1) && !at(i - 2) && !at(i - 3) && !at(i - 4);
        const after = !at(i + 7) && !at(i + 8) && !at(i + 9) && !at(i + 10);
        if (before) s += 40;
        if (after) s += 40;
      }
    }
    return s;
  };
  for (let y = 0; y < n; y++) score += line((x) => m[y][x]);
  for (let x = 0; x < n; x++) score += line((y) => m[y][x]);
  for (let y = 0; y + 1 < n; y++) {
    for (let x = 0; x + 1 < n; x++) {
      const c = m[y][x];
      if (c === m[y][x + 1] && c === m[y + 1][x] && c === m[y + 1][x + 1]) score += 3;
    }
  }
  let dark = 0;
  for (const row of m) for (const c of row) if (c) dark++;
  const total = n * n;
  return score + Math.max(0, Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1) * 10;
}

/** The QR symbol of a text as rows of booleans (true = dark), null when it is too long */
function qrMatrix(text) {
  const bytes = enc.encode(text);
  let ver = 1;
  while (ver <= 10 && 4 + (ver < 10 ? 8 : 16) + bytes.length * 8 > qrDataCodewords(ver) * 8) ver++;
  if (ver > 10) return null;

  // the bit stream: byte mode, length, data, terminator, padding
  const capacity = qrDataCodewords(ver) * 8;
  const bits = [];
  const put = (value, count) => { for (let i = count - 1; i >= 0; i--) bits.push((value >>> i) & 1); };
  put(0b0100, 4);
  put(bytes.length, ver < 10 ? 8 : 16);
  for (const b of bytes) put(b, 8);
  put(0, Math.min(4, capacity - bits.length));
  put(0, (8 - (bits.length % 8)) % 8);
  for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) put(pad, 8);
  const data = [];
  for (let i = 0; i < bits.length; i += 8) data.push(bits.slice(i, i + 8).reduce((a, b) => (a << 1) | b, 0));

  // blocks with their error correction, interleaved
  const blocks = QR_BLOCKS[ver];
  const eccLen = QR_ECC[ver];
  const raw = Math.floor(qrRawModules(ver) / 8);
  const shortBlocks = blocks - (raw % blocks);
  const shortLen = Math.floor(raw / blocks);
  const divisor = rsDivisor(eccLen);
  const parts = [];
  for (let i = 0, k = 0; i < blocks; i++) {
    const dat = data.slice(k, k + shortLen - eccLen + (i < shortBlocks ? 0 : 1));
    k += dat.length;
    const ecc = rsRemainder(dat, divisor);
    if (i < shortBlocks) dat.push(0);
    parts.push(dat.concat(ecc));
  }
  const words = [];
  for (let i = 0; i < parts[0].length; i++) {
    parts.forEach((p, j) => { if (i !== shortLen - eccLen || j >= shortBlocks) words.push(p[i]); });
  }

  // function patterns
  const size = ver * 4 + 17;
  const mod = Array.from({ length: size }, () => new Array(size).fill(false));
  const fixed = Array.from({ length: size }, () => new Array(size).fill(false));
  const set = (x, y, dark) => { mod[y][x] = dark; fixed[y][x] = true; };
  for (let i = 0; i < size; i++) {
    set(6, i, i % 2 === 0);
    set(i, 6, i % 2 === 0);
  }
  for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const x = cx + dx;
        const y = cy + dy;
        const d = Math.max(Math.abs(dx), Math.abs(dy));
        if (x >= 0 && x < size && y >= 0 && y < size) set(x, y, d !== 2 && d !== 4);
      }
    }
  }
  const align = qrAlignment(ver);
  const last = align.length - 1;
  align.forEach((ax, i) => align.forEach((ay, j) => {
    if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return;
    for (let dy = -2; dy <= 2; dy++) {
      for (let dx = -2; dx <= 2; dx++) set(ax + dx, ay + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }
  }));
  const format = (mask) => {
    const d = mask;                              // level M = 0b00
    let r = d;
    for (let i = 0; i < 10; i++) r = (r << 1) ^ ((r >>> 9) * 0x537);
    const f = ((d << 10) | r) ^ 0x5412;
    const bit = (i) => ((f >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) set(8, i, bit(i));
    set(8, 7, bit(6));
    set(8, 8, bit(7));
    set(7, 8, bit(8));
    for (let i = 9; i < 15; i++) set(14 - i, 8, bit(i));
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
    set(8, size - 8, true);
  };
  format(0);
  if (ver >= 7) {
    let r = ver;
    for (let i = 0; i < 12; i++) r = (r << 1) ^ ((r >>> 11) * 0x1f25);
    const v = (ver << 12) | r;
    for (let i = 0; i < 18; i++) {
      const dark = ((v >>> i) & 1) === 1;
      const a = size - 11 + (i % 3);
      const b = Math.floor(i / 3);
      set(a, b, dark);
      set(b, a, dark);
    }
  }

  // the codewords, in the zigzag
  let i = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let v = 0; v < size; v++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j;
        const y = ((right + 1) & 2) === 0 ? size - 1 - v : v;
        if (!fixed[y][x] && i < words.length * 8) {
          mod[y][x] = ((words[i >>> 3] >>> (7 - (i & 7))) & 1) === 1;
          i++;
        }
      }
    }
  }

  const flip = (mask) => {
    for (let y = 0; y < size; y++) {
      for (let x = 0; x < size; x++) if (!fixed[y][x] && QR_MASKS[mask](x, y)) mod[y][x] = !mod[y][x];
    }
  };
  let best = 0;
  let bestScore = Infinity;
  for (let mask = 0; mask < 8; mask++) {
    flip(mask);
    format(mask);
    const score = qrPenalty(mod);
    if (score < bestScore) {
      best = mask;
      bestScore = score;
    }
    flip(mask);
  }
  flip(best);
  format(best);
  return mod;
}

const qrCache = new Map();

/** A QR code as inline SVG (dark on white, whatever the theme — scanners need the contrast) */
function qrSvg(text, label) {
  if (!qrCache.has(text)) {
    const m = qrMatrix(text);
    let path = '';
    if (m) {
      for (let y = 0; y < m.length; y++) {
        for (let x = 0; x < m.length;) {
          if (!m[y][x]) {
            x++;
            continue;
          }
          let w = 1;
          while (x + w < m.length && m[y][x + w]) w++;
          path += `M${x + 4} ${y + 4}h${w}v1h-${w}z`;
          x += w;
        }
      }
    }
    if (qrCache.size > 20) qrCache.clear();
    qrCache.set(text, m ? { size: m.length + 8, path } : null);
  }
  const q = qrCache.get(text);
  if (!q) return '';
  return `<svg class="qr" viewBox="0 0 ${q.size} ${q.size}" role="img" aria-label="${esc(label)}" shape-rendering="crispEdges" xmlns="http://www.w3.org/2000/svg"><rect width="${q.size}" height="${q.size}" fill="#fff"/><path d="${q.path}" fill="#000"/></svg>`;
}

/* ───────────────────────── the page ───────────────────────── */

function esc(s) {
  return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

/** A text with {placeholders}; values are escaped unless listed in raw */
function fill(text, vars = {}, raw = {}) {
  return esc(text).replace(/\{(\w+)\}/g, (m, k) => (k in raw ? raw[k] : k in vars ? esc(vars[k]) : m));
}

function day(date, lang) {
  try {
    return new Intl.DateTimeFormat(lang, { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(`${date}T12:00:00Z`));
  } catch (e) {
    return date;
  }
}

function nonce() {
  return b64url(crypto.getRandomValues(new Uint8Array(18)));
}

const PAYPAL_SOURCES = 'https://*.paypal.com https://*.paypalobjects.com';

function pageCsp(n) {
  return [
    "default-src 'none'",
    `script-src 'nonce-${n}' ${PAYPAL_SOURCES}`,
    `style-src 'nonce-${n}' ${PAYPAL_SOURCES}`,
    `img-src 'self' data: ${PAYPAL_SOURCES}`,
    `connect-src 'self' ${PAYPAL_SOURCES}`,
    `frame-src ${PAYPAL_SOURCES}`,
    `child-src ${PAYPAL_SOURCES}`,
    `form-action 'self' ${PAYPAL_SOURCES}`,
    "base-uri 'none'",
    "frame-ancestors 'none'",
  ].join('; ');
}

async function page(request, env, ctx, url) {
  const lang = pickLang(url.searchParams.get('lang'), request.headers.get('accept-language'));
  const t = TEXT[lang];
  const rawId = url.searchParams.get('id');
  const id = serverId(rawId);
  const idState = id ? 'ok' : rawId !== null && rawId.trim() !== '' ? 'bad' : 'none';
  let have = null;
  if (id && kv(env) && !(await limited(request, env, ctx, 'lookup'))) {
    try {
      have = await storedKey(env, id);
    } catch (e) {
      have = null;
    }
  }
  const n = nonce();
  const withPaypal = paypalReady(env);
  const ways = methods(env);
  const min = centsText(AMOUNT_MIN_CENTS).replace(/\.00$/, '');
  const max = centsText(AMOUNT_MAX_CENTS).replace(/\.00$/, '');
  const currency = pickCurrency(request, lang);
  const helmi = '<a href="https://github.com/helmi1987" rel="noopener">helmi1987</a>';

  const config = {
    clientId: withPaypal ? clientId(env) : null,
    locale: PAYPAL_LOCALE[lang],
    lang,
    id,
    min: AMOUNT_MIN_CENTS / 100,
    max: AMOUNT_MAX_CENTS / 100,
    nameMax: NAME_MAX,
    t: {
      working: t.working, cancelled: t.cancelled, sdk_failed: t.sdk_failed,
      thanks_title: t.thanks_title, thanks_key: t.thanks_key, thanks_nokey: t.thanks_nokey,
      copy: t.copy, copy_short: t.copy_short, copied: t.copied, pending: t.pending,
      err_bad_amount: t.err_bad_amount.replace(/\{min\}/g, min).replace(/\{max\}/g, max),
      err_bad_name: t.err_bad_name, err_bad_currency: t.err_bad_currency, err_rate_limited: t.err_rate_limited,
      err_server_config: t.err_server_config, err_not_completed: t.err_not_completed, err_mismatch: t.err_mismatch,
      err_unknown_order: t.err_unknown_order, err_generic: t.err_generic, err_bad_id: t.err_bad_id,
      err_bad_via: t.err_bad_via,
    },
  };
  const configJson = JSON.stringify(config).replace(/</g, '\\u003c').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');

  const langLinks = LANGS.map((l) => {
    const q = new URLSearchParams();
    if (id) q.set('id', id);
    q.set('lang', l);
    return `<a href="?${esc(q.toString())}" hreflang="${l}" lang="${l}"${l === lang ? ' aria-current="page"' : ''}>${l.toUpperCase()}</a>`;
  }).join('');

  const thanksCard = `<section class="card" id="thanks">
    <h2>${esc(t.key_title)}</h2>
    <p class="explain">${esc(t.key_explain)}</p>
    ${idState === 'ok'
    ? `<div class="server"><span class="label">${esc(t.server)}</span> <code class="id">${esc(id)}</code>
        <p class="hint">${esc(t.server_hint)}</p></div>
      <label class="field" for="name">${esc(t.name)}
        <input id="name" name="name" type="text" maxlength="160" autocomplete="off" spellcheck="false" placeholder="${esc(t.default_name)}">
      </label>
      <p class="hint">${fill(t.name_hint, { default: t.default_name })}</p>`
    : `<p class="notice warn">${esc(idState === 'bad' ? t.bad_id : t.no_id)}</p>`}
  </section>`;

  const haveCard = have
    ? `<section class="card have" id="have">
        <h2>☕ ${esc(t.have_title)}</h2>
        <p>${fill(t.have_text, { name: have.name, date: day(have.date, lang) })}</p>
        <code class="key" id="have-key">${esc(have.key)}</code>
        <button type="button" class="btn" data-copy="have-key">${esc(t.copy)}</button>
        <p class="hint">${esc(t.key_how)}</p>
        <p class="hint">${esc(t.have_more)}</p>
      </section>`
    : '';

  const chips = [3, 5, 10, 20].map((v) => `<button type="button" class="chip" data-amount="${v}" aria-pressed="${v === 5}">${v}</button>`).join('');
  const currencies = CURRENCIES.map((c) => `<option value="${c}"${c === currency ? ' selected' : ''}>${c}</option>`).join('');
  const paypalCard = withPaypal
    ? `<section class="card" id="tip">
    <h2>${esc(t.paypal_title)}</h2>
    <div class="row">
      <label class="field" for="amount">${esc(t.amount)}
        <input id="amount" name="amount" type="text" inputmode="decimal" maxlength="8" value="5" autocomplete="off">
      </label>
      <label class="field cur" for="currency">${esc(t.currency)}
        <select id="currency" name="currency">${currencies}</select>
      </label>
    </div>
    <div class="chips">${chips}</div>
    <p class="hint">${fill(t.amount_hint, { min, max })}</p>
    <p class="notice" id="status" role="status" aria-live="polite" hidden></p>
    <div id="paypal"></div>
  </section>`
    : '';

  const honourButton = (via) => (id ? `<button type="button" class="btn plain" data-honour="${via}">${esc(t.honour_button)}</button>` : '');
  const copyable = (elId, shown, copied) => `<code class="addr" id="${elId}">${esc(shown)}</code>
        <button type="button" class="btn small plain" data-copy="${elId}" data-copy-text="${esc(copied)}">${esc(t.copy_short)}</button>`;
  const bank = ways.bank;
  const bankPart = bank
    ? `<div class="method" id="bank">
      <h3>${esc(t.bank_title)}</h3>
      <dl class="details">
        <dt>${esc(t.bank_holder)}</dt><dd>${esc(bank.holder)}</dd>
        <dt>${esc(t.bank_iban)}</dt><dd>${copyable('iban', bank.iban.replace(/(.{4})(?=.)/g, '$1 '), bank.iban)}</dd>
        ${bank.bic ? `<dt>${esc(t.bank_bic)}</dt><dd><code class="addr">${esc(bank.bic)}</code></dd>` : ''}
        ${bank.name ? `<dt>${esc(t.bank_name)}</dt><dd>${esc(bank.name)}</dd>` : ''}
      </dl>
      <p class="hint">${esc(t.bank_note)}</p>
      ${honourButton('bank')}
    </div>`
    : '';
  const coin = (kind, title, note, shown, qrText) => `<div class="coin">
        ${qrSvg(qrText, t.qr.replace('{what}', title))}
        <div class="coin-text">
          <p class="label">${esc(title)}</p>
          ${copyable(`btc-${kind}`, shown, shown)}
          <p class="hint">${esc(note)}</p>
        </div>
      </div>`;
  const bitcoinPart = ways.lightning || ways.onchain
    ? `<div class="method" id="bitcoin">
      <h3>${esc(t.btc_title)}</h3>
      ${ways.lightning ? coin('lightning', t.btc_lightning, t.btc_lightning_note, ways.lightning.address, `lightning:${ways.lightning.lnurl}`) : ''}
      ${ways.onchain ? coin('onchain', t.btc_onchain, t.btc_onchain_note, ways.onchain, `bitcoin:${ways.onchain}`) : ''}
      ${honourButton('bitcoin')}
    </div>`
    : '';
  const otherCard = bankPart || bitcoinPart
    ? `<section class="card" id="other">
    <h2>${esc(t.other_title)}</h2>
    ${id ? `<p class="explain">${esc(t.honour_text)}</p>` : ''}
    ${bankPart}
    ${bitcoinPart}
    <p class="notice" id="honour-status" role="status" aria-live="polite" hidden></p>
  </section>`
    : '';
  const nothing = !paypalCard && !otherCard ? `<p class="notice warn">${esc(t.not_set_up)}</p>` : '';
  const affiliate = referral(env);
  const referralCard = affiliate
    ? `<section class="card quiet" id="referral">
    <p>${esc(t.referral_text)}</p>
    <p><a href="${esc(affiliate)}" rel="sponsored noopener noreferrer" target="_blank">${esc(t.referral_link)}</a>
      <span class="tag">${esc(t.referral_tag)}</span></p>
    <p class="hint">${esc(t.referral_note)}</p>
  </section>`
    : '';

  const html = `<!doctype html>
<html lang="${lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<meta name="color-scheme" content="light dark">
<title>${esc(t.title)}</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%E2%98%95%3C/text%3E%3C/svg%3E">
<style nonce="${n}">${CSS}</style>
</head>
<body>
<main>
  <header class="head">
    <span class="cup" aria-hidden="true">☕</span>
    <div><p class="brand">Unraid Secretary Office</p><h1>${esc(t.h1)}</h1></div>
  </header>
  <nav class="langs" aria-label="${esc(t.languages)}">${langLinks}</nav>

  <section class="card intro">
    <p>${esc(t.intro)}</p>
    <p>${esc(t.ask)}</p>
    <p class="muted">${fill(t.credit, {}, { helmi })}</p>
    <p class="muted">${esc(t.shelter)}</p>
  </section>

  <section class="card result" id="result" tabindex="-1" hidden>
    <h2>☕ <span id="result-title"></span></h2>
    <p id="result-text"></p>
    <div id="result-key" hidden>
      <code class="key" id="new-key"></code>
      <button type="button" class="btn" data-copy="new-key">${esc(t.copy)}</button>
      <p class="hint">${esc(t.key_how)}</p>
    </div>
  </section>

  <section class="card" id="pending" hidden>
    <p class="notice warn" id="pending-text"></p>
    <button type="button" class="btn plain" id="pending-check">${esc(t.check_again)}</button>
  </section>

  ${haveCard}
  ${thanksCard}
  ${paypalCard}
  ${otherCard}
  ${nothing}
  ${referralCard}

  <footer>
    <p>${esc(t.privacy)}</p>
    <p>${esc(t.free)}</p>
  </footer>
</main>
<script type="application/json" id="cfg">${configJson}</script>
<script nonce="${n}">(${client.toString()})();</script>
</body>
</html>`;

  return new Response(request.method === 'HEAD' ? null : html, {
    status: 200,
    headers: {
      ...BASE_HEADERS,
      'Content-Type': 'text/html; charset=utf-8',
      'Content-Security-Policy': pageCsp(n),
      'Cross-Origin-Opener-Policy': 'same-origin-allow-popups',
    },
  });
}

const CSS = `
:root{
  --bg:#f5f4f1; --surface:#fff; --ink:#1d1d1f; --muted:#626268; --line:#e2e0db; --field:#fbfaf8;
  --accent:#b9500c; --on-accent:#fff; --accent-soft:#fdeee2; --accent-ink:#8f3d08;
  --warn:#7a5000; --warn-soft:#fff5d9; --danger:#b42318; --danger-soft:#fdecea; --ok:#1d6b35; --ok-soft:#e7f4eb;
  --mono:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;
  color-scheme:light dark;
}
@media (prefers-color-scheme: dark){
  :root{
    --bg:#131315; --surface:#1d1d20; --ink:#ececee; --muted:#a3a3ab; --line:#34343a; --field:#17171a;
    --accent:#ff9a4d; --on-accent:#1b1008; --accent-soft:#3a2415; --accent-ink:#ffbb85;
    --warn:#ffd27a; --warn-soft:#352a12; --danger:#ff8b80; --danger-soft:#3b1a17; --ok:#7fd69a; --ok-soft:#17301f;
  }
}
*{box-sizing:border-box}
[hidden]{display:none!important}
html{-webkit-text-size-adjust:100%}
body{margin:0; background:var(--bg); color:var(--ink); font:16px/1.55 system-ui,-apple-system,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
main{max-width:580px; margin:0 auto; padding:24px 16px 36px}
a{color:var(--accent)}
.head{display:flex; gap:14px; align-items:center}
.cup{font-size:42px; line-height:1}
.brand{margin:0; font-size:12.5px; color:var(--muted); letter-spacing:.06em; text-transform:uppercase}
h1{margin:0; font-size:27px; line-height:1.2}
h2{margin:0 0 12px; font-size:18px}
h3{margin:0 0 8px; font-size:15.5px}
p{margin:0 0 10px}
.langs{display:flex; flex-wrap:wrap; gap:4px; margin:10px 0 16px; font-size:13px}
.langs a{color:var(--muted); text-decoration:none; padding:2px 8px; border-radius:6px; border:1px solid transparent}
.langs a:hover{border-color:var(--line)}
.langs a[aria-current]{background:var(--accent-soft); color:var(--accent-ink)}
.card{background:var(--surface); border:1px solid var(--line); border-radius:12px; padding:18px; margin:0 0 14px}
.card > :last-child{margin-bottom:0}
.muted{color:var(--muted); font-size:14.5px}
.row{display:flex; gap:10px}
.field{display:flex; flex-direction:column; gap:5px; font-size:14px; font-weight:600; flex:1; min-width:0; margin:0 0 4px}
.field.cur{flex:0 0 108px}
input,select{font:inherit; font-weight:400; color:var(--ink); background:var(--field); border:1px solid var(--line); border-radius:8px; padding:9px 11px; width:100%; min-width:0}
input:focus,select:focus{border-color:var(--accent); outline:none}
.chips{display:flex; flex-wrap:wrap; gap:8px; margin:8px 0 6px}
.chip{font:inherit; font-size:14px; color:var(--ink); background:transparent; border:1px solid var(--line); border-radius:999px; padding:4px 15px; cursor:pointer}
.chip[aria-pressed="true"]{border-color:var(--accent); background:var(--accent-soft); color:var(--accent-ink)}
.hint{font-size:13px; color:var(--muted); font-weight:400; margin:2px 0 14px}
.label{font-size:14px; font-weight:600}
.server{margin:4px 0 10px}
.server .hint{margin-top:4px}
.id{font:600 15px var(--mono); letter-spacing:.03em; background:var(--field); border:1px solid var(--line); border-radius:6px; padding:2px 7px; white-space:nowrap}
.explain{font-size:14.5px}
.notice{border-radius:8px; padding:10px 12px; font-size:14.5px; border-left:3px solid var(--accent); background:var(--accent-soft); color:var(--accent-ink)}
.notice.warn{border-color:var(--warn); background:var(--warn-soft); color:var(--warn)}
.notice.error{border-color:var(--danger); background:var(--danger-soft); color:var(--danger)}
.notice.ok{border-color:var(--ok); background:var(--ok-soft); color:var(--ok)}
.key{display:block; font:14px/1.5 var(--mono); overflow-wrap:anywhere; word-break:break-all; background:var(--field); border:1.5px dashed var(--accent); border-radius:8px; padding:12px 14px; margin:6px 0 12px; user-select:all; -webkit-user-select:all}
.result .key{font-size:15.5px}
.result{border-color:var(--accent)}
.btn{font:inherit; font-weight:600; font-size:15px; color:var(--on-accent); background:var(--accent); border:1px solid var(--accent); border-radius:8px; padding:9px 18px; cursor:pointer}
.btn.plain{color:var(--accent); background:transparent}
.btn.small{font-size:13px; padding:3px 10px; margin-left:4px; vertical-align:baseline}
.btn:disabled{opacity:.55; cursor:default}
.btn + .hint{margin-top:12px}
#paypal{margin-top:14px; min-height:48px}
.method{border-top:1px solid var(--line); padding-top:14px; margin-top:14px}
.method > .btn{margin-top:2px}
.details{display:grid; grid-template-columns:auto 1fr; gap:4px 14px; margin:0 0 10px; font-size:14.5px}
.details dt{color:var(--muted)}
.details dd{margin:0; min-width:0; overflow-wrap:anywhere}
.addr{font:14px var(--mono); overflow-wrap:anywhere; word-break:break-all}
#iban{word-break:normal; overflow-wrap:normal}
.coin{display:flex; gap:14px; align-items:flex-start; margin:0 0 12px}
.coin-text{min-width:0; flex:1}
.coin-text .label{margin:0 0 2px}
.qr{width:132px; height:132px; flex:none; border-radius:6px; border:1px solid var(--line)}
.card.quiet{background:transparent; font-size:14.5px}
.tag{display:inline-block; font-size:12px; line-height:1.4; color:var(--muted); border:1px solid var(--line); border-radius:999px; padding:1px 9px; margin-left:4px; white-space:nowrap}
footer{font-size:13px; color:var(--muted); text-align:center; margin-top:20px}
footer p{margin:0 0 6px}
:focus-visible{outline:2px solid var(--accent); outline-offset:2px}
@media (max-width:400px){
  .field.cur{flex-basis:96px} h1{font-size:24px} .card{padding:16px 14px}
  .coin{flex-direction:column} .qr{width:168px; height:168px}
}
`;

/*
 * The page's script — runs in the browser, never in the worker. Sent as its own source text
 * (client.toString()), so it must use nothing from this module: only the page and window.
 */
function client() {
  'use strict';
  const cfg = JSON.parse(document.getElementById('cfg').textContent);
  const T = cfg.t;
  const nonce = (document.currentScript && document.currentScript.nonce) || '';
  const $ = (id) => document.getElementById(id);
  const nameEl = $('name');
  const PENDING = 'uso-support-pending';

  const sayAt = (el, text, kind) => {
    if (!el) return;
    el.textContent = text || '';
    el.className = `notice${kind ? ` ${kind}` : ''}`;
    el.hidden = !text;
  };
  const errorText = (code) => T[`err_${code}`] || T.err_generic;

  const store = {
    get() { try { return JSON.parse(localStorage.getItem(PENDING) || 'null'); } catch (e) { return null; } },
    set(v) { try { localStorage.setItem(PENDING, JSON.stringify(v)); } catch (e) { /* private window */ } },
    clear() { try { localStorage.removeItem(PENDING); } catch (e) { /* private window */ } },
  };

  const name = () => (nameEl ? nameEl.value.normalize('NFC').trim() : '');
  const nameOk = (n) => n === '' || ([...n].length <= cfg.nameMax && /^[^\p{C}\p{Zl}\p{Zp}]+$/u.test(n));

  document.addEventListener('click', async (ev) => {
    const btn = ev.target.closest('[data-copy]');
    if (!btn) return;
    const target = $(btn.dataset.copy);
    const text = btn.dataset.copyText || target.textContent;
    const label = btn.textContent;
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      const range = document.createRange();
      range.selectNodeContents(target);
      const sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
      try { document.execCommand('copy'); } catch (e2) { /* selected at least */ }
    }
    btn.textContent = T.copied;
    setTimeout(() => { btn.textContent = label; }, 2000);
  });

  const post = async (path, body) => {
    try {
      const res = await fetch(path, {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body),
        credentials: 'same-origin', cache: 'no-store',
      });
      const data = await res.json().catch(() => null);
      return data && typeof data === 'object' ? data : { ok: false, error: 'network' };
    } catch (e) {
      return { ok: false, error: 'network' };
    }
  };

  const showResult = (r) => {
    $('result-title').textContent = T.thanks_title;
    if (r.key) {
      $('result-text').textContent = T.thanks_key.replace('{name}', r.name || '');
      $('new-key').textContent = r.key;
      $('result-key').hidden = false;
      const have = $('have');
      if (have) have.hidden = true;
    } else {
      $('result-text').textContent = T.thanks_nokey;
      $('result-key').hidden = true;
    }
    $('result').hidden = false;
    $('pending').hidden = true;
    $('result').scrollIntoView({ behavior: 'smooth', block: 'start' });
    $('result').focus({ preventScroll: true });
  };

  const showPending = (text) => {
    $('pending-text').textContent = text;
    $('pending').hidden = false;
  };

  // «I've sent it — show my key» (bank transfer, Bitcoin): on trust
  const honourStatus = $('honour-status');
  document.querySelectorAll('[data-honour]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (!nameOk(name())) {
        sayAt(honourStatus, T.err_bad_name, 'error');
        nameEl.focus();
        return;
      }
      btn.disabled = true;
      sayAt(honourStatus, T.working);
      const r = await post('/api/honour', { id: cfg.id, via: btn.dataset.honour, name: name(), lang: cfg.lang });
      btn.disabled = false;
      if (r.ok) {
        sayAt(honourStatus, '');
        showResult(r);
      } else {
        sayAt(honourStatus, errorText(r.error), 'error');
      }
    });
  });

  if (!cfg.clientId) return;

  // PayPal
  const amountEl = $('amount');
  const currencyEl = $('currency');
  const statusEl = $('status');
  const box = $('paypal');
  const say = (text, kind) => sayAt(statusEl, text, kind);
  let lastError = null;

  const amount = () => {
    const s = amountEl.value.trim().replace(',', '.');
    const m = /^(\d{1,7})(?:\.(\d{1,2}))?$/.exec(s);
    if (!m) return null;
    const v = Number(m[1]) + Number((m[2] || '').padEnd(2, '0')) / 100;
    return v >= cfg.min && v <= cfg.max ? s : null;
  };
  const check = () => {
    if (!amount()) { say(T.err_bad_amount, 'error'); amountEl.focus(); return false; }
    if (!nameOk(name())) { say(T.err_bad_name, 'error'); nameEl.focus(); return false; }
    say('');
    return true;
  };

  const syncChips = () => document.querySelectorAll('.chip').forEach((c) => c.setAttribute('aria-pressed', String(c.dataset.amount === amountEl.value.trim())));
  document.querySelectorAll('.chip').forEach((chip) => {
    chip.addEventListener('click', () => { amountEl.value = chip.dataset.amount; syncChips(); say(''); });
  });
  amountEl.addEventListener('input', syncChips);

  /** Capture (or look again at) an approved order */
  const capture = async (orderID) => {
    say(T.working);
    const r = await post('/api/capture', { orderID });
    if (r.ok) {
      store.clear();
      say('');
      showResult(r);
    } else if (r.error === 'declined') {
      say('');
    } else if (r.error === 'pending') {
      store.set({ orderID, id: cfg.id });
      say('');
      showPending(T.pending);
    } else if (['unknown_order', 'mismatch', 'not_completed', 'bad_order'].includes(r.error)) {
      store.clear();
      $('pending').hidden = true;
      say(errorText(r.error), 'error');
    } else {
      store.set({ orderID, id: cfg.id });   // perhaps paid: «Check again» tries once more
      say('');
      showPending(errorText(['network', 'paypal', 'internal'].includes(r.error) ? 'generic' : r.error));
    }
    return r;
  };

  $('pending-check').addEventListener('click', async () => {
    const p = store.get();
    if (p && typeof p.orderID === 'string') await capture(p.orderID);
    else $('pending').hidden = true;
  });
  const waiting = store.get();
  if (waiting && typeof waiting.orderID === 'string' && waiting.id === cfg.id) showPending(T.pending);

  const sdks = {};
  const sdk = (currency) => {
    if (!sdks[currency]) {
      sdks[currency] = new Promise((resolve, reject) => {
        const ns = `paypal_${currency}`;
        const q = new URLSearchParams({
          'client-id': cfg.clientId, currency, intent: 'capture', components: 'buttons',
          'disable-funding': 'paylater,venmo,credit', locale: cfg.locale,
        });
        const s = document.createElement('script');
        s.src = `https://www.paypal.com/sdk/js?${q}`;
        s.async = true;
        s.nonce = nonce;
        s.setAttribute('data-csp-nonce', nonce);
        s.setAttribute('data-namespace', ns);
        s.onload = () => (window[ns] ? resolve(window[ns]) : reject(new Error('sdk')));
        s.onerror = () => { delete sdks[currency]; reject(new Error('sdk')); };
        document.head.appendChild(s);
      });
    }
    return sdks[currency];
  };

  let shown = 0;
  const render = async () => {
    const currency = currencyEl.value;
    const turn = ++shown;
    box.textContent = '';
    let pp;
    try {
      pp = await sdk(currency);
    } catch (e) {
      if (turn === shown) say(T.sdk_failed, 'error');
      return;
    }
    if (turn !== shown) return;
    say('');
    const holder = document.createElement('div');
    box.appendChild(holder);
    pp.Buttons({
      style: { layout: 'vertical', shape: 'rect', height: 45 },
      onClick: (data, actions) => (check() ? actions.resolve() : actions.reject()),
      createOrder: async () => {
        lastError = null;
        const body = { amount: amount(), currency, lang: cfg.lang };
        if (cfg.id) { body.id = cfg.id; body.name = name(); }
        const r = await post('/api/order', body);
        if (!r.ok) { lastError = r.error; throw new Error(r.error); }
        return r.orderID;
      },
      onApprove: async (data, actions) => {
        const r = await capture(data.orderID);
        if (!r.ok && r.error === 'declined') return actions.restart();
        return undefined;
      },
      onCancel: () => say(T.cancelled),
      onError: () => say(errorText(lastError || 'generic'), 'error'),
    }).render(holder).catch(() => { if (turn === shown) say(T.sdk_failed, 'error'); });
  };
  currencyEl.addEventListener('change', render);
  render();
}
