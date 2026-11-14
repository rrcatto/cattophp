# The CattoPHP Modules 1.0

**Version 1.0** · 14 November 2026, 05:55 SAST · Authored by Claude Opus 5.5

The optional modules of CattoPHP: what each one does, how it is switched on and configured, its settings and the parts
a project uses. It belongs with the CattoPHP Guide (`GUIDE-v1.0.md`, beside it), which is read first: the guide gives
the rules every project follows, how to start a project and the foundations, the interface system and the themes the
modules build on, and this document is given with it when a project uses a module.

It is the guide's section 8, the optional modules, moved into a document of its own so that each stays under 150,000
characters; its sections keep the guide's numbers, 8.1 to 8.16, so that "8.9" means Mailing wherever it is named. The
guide refers to them as "Modules 8.9" and similar; here "the guide's 5.3" and similar name a section of the guide.
Like the guide, it describes CattoPHP as its code stands for version 1.0.1 (0.3.0 and the changes since, in its
CHANGELOG); where it and the code disagree, the code is right and this document is corrected. Newer versions replace it
in CattoPHP's repository as `MODULES-v<version>.md`.

## Contents

8.1 Choosing modules · 8.2 The scheduler · 8.3 The setup checklist · 8.4 The network lookup · 8.5 Site protection ·
8.6 The sign-up check · 8.7 Identity verification · 8.8 The Inbox · 8.9 Mailing · 8.10 Telegram · 8.11 An API and
signed webhooks · 8.12 Documents and PDFs · 8.13 Search engines and AI · 8.14 Payments · 8.15 Connections to outside
services · 8.16 The Campaign Marketing Manager

## 8. The optional modules

### 8.1 Choosing modules

The foundations are always there; each optional module is off until `enabled: true` under its name in
`config/packages/cattophp.yaml`. A module off costs nothing: no services, settings, tasks or tables. The list is
`Module\Modules::all()`:

| Module (`cattophp.<key>`) | Needs | Works better with |
|---|---|---|
| The scheduler (`scheduler`) | | |
| The setup checklist (`setup`) | the database | |
| Site protection (`protection`) | the database, the scheduler | |
| Telegram (`telegram`) | the database, `symfony/http-client` | the scheduler (the owner's messages each minute) |
| An API (`api`) | the database | |
| Documents and PDFs (`documents`) | `dompdf/dompdf` | |
| Search engines and AI (`search`) | the database | |
| The sign-up check (`signup`) | the database | the scheduler (the lists every day) |
| Identity verification (`identity`) | the database, `symfony/http-client` | |
| The Inbox (`inbox`) | the database | the scheduler (the mailbox's answers each minute) |
| Mailing (`mailing`) | the database, `symfony/http-client` | |
| Payments (`payments`) | `omnipay/common` and the gateway's package | |
| The Campaign Marketing Manager (`marketing`) | the database | the scheduler (the nightly tidy-up) |
| Themes (`theme`) | | |

At start, what the modules switched on need is checked, every problem said at once with what to do.
`bin/console cattophp:modules` lists every module, whether it is on, what it needs and whether that is there, and its
tables, each made or not; it fails while a problem remains. Switch a module on, run `cattophp:modules`, then
`cattophp:migrate`. A module's task is registered only with the scheduler on, placed by `<module>.task_priority`. Some
modules leave a part to the project's task (a daily clean-up, the mailing sender each minute), as their sections say.

### 8.2 The scheduler

Runs a project's tasks (`Scheduler\ScheduledTask` services, the guide's 4.7) when due, recording each run. A task never run is due;
one with a time of day (`runsAt()`, `H:i` in the site's zone, `Scheduler::zone()`) once that time has passed since it
last started, so a scheduler stopped then runs it on starting again; any other when `interval()` seconds have passed
since it last started. One failing never stops the rest. Each task's last start, finish, success, failure, summary and
error are kept (`cattophp_scheduled_jobs`, else a file). A task's place is its tag priority (`#[AsTaggedItem(priority:
10)]`).

```yaml
scheduler:
  enabled: true
  command_alias: ~  # another name for cattophp:schedule:run, such as one a cron line already runs
  log_archive:
    enabled: false  # with folder: the nightly log archive
    folder: ~  # such as '%kernel.project_dir%/var/log'
    first: application.log  # the main log, listed first
```

- `bin/console cattophp:schedule:run` runs what is due once (cron every minute), or each minute with `--watch` (a
  worker); `--job <key>` runs named tasks now. Told to stop (SIGTERM, SIGINT), it finishes the task under way, starts no
  other and ends, where PHP can catch signals (`ScheduleRunCommand::signalsSupported()`: pcntl loaded, `pcntl_signal`
  allowed); without pcntl a stop ends it mid-task. The starter suggests `ext-pcntl`, its kit's image installs it.
- **Another moment, on a development site:** `--job <key> --now "2026-12-01 09:00"` (the site's zone; a date alone, its
  midnight) or `--now "+3 days"` (from the scheduler's clock) gives `run()` that moment: a trial, its summary starting
  "As at 2026-12-01 09:00: ", kept apart (`RunStore::tried()`: `last_trial_at`, `last_trial_ok`, `last_trial_summary`;
  schema step `202611130300-scheduled-job-trials`), the runs untouched. Refused without `--job`, with `--watch`, for an
  empty `--now` or no moment, and by `Scheduler::run()` (`TRIALS_REFUSED`, `trialRefused()`) off a development site
  (kernel in debug, environment neither production nor `prod`: the constructor's last `bool $development = false`) and
  whenever `APP_ENV` is production, saying so.
  `Scheduler::run(?array $only = null, ?Closure $stopping = null, ?DateTimeImmutable $at = null)`, `moment($typed)`.
- `Scheduler\TaskStatus`: `tasks()` (each task's last runs in words, overdue past `overdueAfter()` or not, its last
  trial apart: `last_trial`, `trial_summary`, `trial_failed`), `lastSucceeded()`, `isOverdue()`, `words()`, for a
  dashboard or setup step warning of a stopped task.
- **The log archive:** the `log_archive` task, just after midnight, moves each `.log` holding anything into
  `archive/<name>-<day>.log.gz`, starting it afresh, and deletes archives older than `LOG_ARCHIVES_KEPT_FOR` (group "Log
  archives"; 2w unless set, a day at least).

### 8.3 The setup checklist

`setup: { enabled: true }`, with the database: each step a new installation needs, in order, from the project's
`Setup\SetupStepProvider` services (`steps(SetupMarks $marks)`, by tag priority). A `Setup\SetupStep` has a key, group,
title, purpose, where it stands, `checked` (true, false or null: whether the site finds it done), `needsYou` (a person
marks it), its page (`href`, `link`), an optional button. A checked step is done while the site finds it so, one that
needs you once marked (or skipped, and reopened); a step can be both. `Setup\SetupChecklist`: `steps()`, `progress()`
(with the next step), `markDone($key, $actor, $note)`, `skip()`, `reopen()`; marks are kept (`cattophp_setup_steps`) and
logged; what a step found is shown, secrets hidden.

### 8.4 The network lookup

Not a module: what an address says of a visitor, for site protection, the sign-up check and the Inbox.

```yaml
network:
  country_database: '/var/lib/GeoIP/GeoLite2-City.mmdb'  # needs geocoder-php/geoip2-provider
  asn_database: ~  # GeoLite2-ASN.mmdb beside it by default: the network's owner
  geocoder: ~  # another Geocoder provider (a web service, a chain), in place of GeoIP2
  lists_folder: '%kernel.project_dir%/var/network-lists'  # Tor, VPN, hosting and throwaway email lists
  lookup: ~  # a project's own Network\NetworkLookup instead
```

`Network\GeoIp` gives an address's place and country through Geocoder PHP, never breaking a page;
`Network\ReputationLists` keeps the Tor exit list (daily), X4BNet's VPN and hosting networks (MIT, weekly) and throwaway
email domains (CC0, weekly), fetched by task `signup_lists`, never on a request; `NetworkLookup::facts($address)` gives
the country and the network's number, name and kind (home, hosting, vpn, tor, private). Without a database or provider
only private addresses are known. Geocoder is the chosen abstraction (providers swapped and chained by configuration),
never bypassed.

### 8.5 Site protection

The Site Log of every request (written as it ends; no query string; secrets masked); flags, time-outs and lockouts (an
address, an IPv6 /64, a range or a network owner), the lockout gate first of all, eight rules each minute (task
`site_protection`), a form guard with a refused list of addresses and domains; a two-file link to a server firewall.

```yaml
protection:
  enabled: true
  secret_paths: ['/join/']  # prefixes whose next part is a secret, masked in the Site Log
  firewall_folder: '%kernel.project_dir%/var/server-firewall'
  visitors: ~  # Protection\VisitorAccounts; CattoPHP's sign-in by default
  administrator_permission: PLATFORM.SETTINGS.VIEW  # whose addresses the rules spare
  gate_listener: true
  record_requests: true
  forms: { sign_in: Sign in, register: Register, contact: Contact }
  list_only_forms: [register]  # refused only by the list
```

**Settings** (group "Site protection"), each rule `on|off,count,within,flag|timeout,for`: `SITE_RULE_MISSING` (missing
pages, `on,20,10m,timeout,1h`), `SITE_RULE_FLOOD` (`on,300,1m,timeout,10m`), `SITE_RULE_LINKS` (links for many
addresses, `on,5,15m,timeout,1h`), `SITE_RULE_REGISTER` (`on,3,1h,flag,1h`), `SITE_RULE_TOKENS` (bad form tokens,
`on,10,10m,timeout,1h`), `SITE_RULE_TRAP` (the hidden field, `on,1,10m,timeout,1d`), `SITE_RULE_SCRIPT`, `SITE_RULE_OLD`
(a script, an outdated browser, `on,1,10m,flag,1h`). Then `SITE_TIMEOUT_DOUBLES` (on), `SITE_TIMEOUT_LONGEST` (1w),
`SITE_LOCKOUT_AFTER` (`3,1w,30d`), `SITE_SPARE_ADMINISTRATORS`, `SITE_SPARE_SIGNED_IN` (flagged, never timed out),
`SITE_SPARE_SEARCH_ENGINES` (proved ones), `SITE_NEVER_LOCK_OUT`, `SITE_LOG_KEPT_FOR` (120d), `SITE_FLAGS_KEPT_FOR`
(1y), `SITE_FIGURES_LEAVE_OUT_ADMINISTRATORS`, `SITE_FIREWALL_LINK` (off); private addresses always spared.

**The parts a project uses.**

- `Protection\LockoutGate`: a timed-out or locked-out visitor gets a bare "This site is not available." first (never a
  webhook). A project starting its own session first sets `gate_listener: false`, calling
  `LockoutGate::beforeTheContainer($connection, $neverLockOut)->refuses(...)`.
- `Protection\FormGuard::refuses($form, $fields, $email)` on a public form (a bot, a refused address): the usual answer,
  nothing done.
- `Protection\LockoutService`: `lockOut($target, $length, $why, $firewall, $actor)`, `lift()`, `markNotAProblem()`;
  nobody locks out their own address or network owner, or one on Never lock out.
- `Protection\SiteLog`, `Flags`, `Lockouts`, `RefusedEmails`: records the project's pages read;
  `Protection\ProtectionRetention::run($now)`, for its daily clean-up.
- `Protection\ServerLink`: the site appends ban and lift requests to `requests.log` and reads the server's `view.json`;
  what runs as root is on the server, never the site.

### 8.6 The sign-up check

`signup: { enabled: true }`, with the database (the lists with the scheduler and `network.lists_folder`). A registration
is judged when sent, each signal scored in words (`Signup\SignupRisk`): a blocked country 60, outside the allowed list
30; Tor 50, VPN 30, hosting network 30; a domain receiving no mail 40, a random-looking address 10; no browser or a
script 40; sent within 3 seconds 30; a time zone outside the address's country 15; each other sign-up from the address
that day 20 (60 at most); a made-up looking name 20; the hidden field 100. Low is under 25, medium under 60. A throwaway
address is refused ("Use an email address you keep."); a blocked country or a high score holds the registration, the
person never told why, a hold meaning what the project says; every attempt is recorded.

- Settings (group "Sign-up checks"): `SIGNUP_ALLOWED_COUNTRIES`, `SIGNUP_BLOCKED_COUNTRIES` (ISO codes).
- The form carries `SignupChecks::stamp()` in `signup_opened`, the browser's time zone in `signup_time_zone` and the
  hidden field `website`. `SignupChecks::check($form, $email, $refusal, $homeCountry, $details)` judges and records;
  `complete($attemptId, $account, $held)`; `Signup\SignupAttempts` for the project's page. The address is `REMOTE_ADDR`,
  never a forwarded one.

### 8.7 Identity verification

A check on Didit's hosted page (ID and a live selfie there, nothing uploaded here), its result by signed webhook (each
event once) and on the person's return; an approval passed on unless under `minimum_age`; a decline told why, 3 tries
before an administrator allows another; revoking with a reason. Kept: status, verified name, document type, number and
country, date of birth, never an image. A live site takes no sandbox result; keyless, it is unavailable, never skipped.

```yaml
identity:
  enabled: true
  return_path: /account/identity/return  # the project's routes
  webhook_path: /webhooks/didit
  accounts: ~  # Identity\IdentityAccounts: what Didit is told of an account (its id by default)
  approved: ~  # Identity\IdentityApproved: what happens once approved
  minimum_age: ~
```

- Settings (a connection, the guide's 2.10): `DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`;
  `Identity\IdentitySettings` takes `.env`'s only where the catalogue lacks the setting.
- `Identity\IdentityVerification`: `start($account)`, `returned()`, `webhook($rawBody, $headers)`, `isVerified()`,
  `state()`, `history()`, `revoke()`, `allowAnotherTry()`, `inReview()`; `Identity\IdentityConnection`: `check()` (reads
  the application's workflows only), `status()`, `keysSaved($before)`, `registerWebhook()`, `drop()`, `resetToEnv()`.

### 8.8 The Inbox

A contact form's messages as conversations (`MSG-` and a number), judged on arrival by fixed signs and a Bayesian word
filter learning from what is marked, filed in the Inbox or Spam (the sender never told); Mark as spam, Not spam, block a
sender, Trash, Restore, Delete forever; replies in plain text or HTML with a signature, drafts, files, the account's
documents, threaded so answers come back, read over IMAP each minute (`inbox_mailbox`) when a mailbox is set; each
decision logged.

```yaml
inbox:
  enabled: true
  reference_prefix: MSG-
  files_folder: '%kernel.project_dir%/var'
  documents: ~  # Inbox\InboxDocuments: an account's documents a reply may attach
  sender: ~  # Inbox\ReplySender: CattoPHP's mailer by default
```

- Settings (group "Inbox"): `INBOX_TRASH_KEPT_FOR`, `INBOX_SPAM_KEPT_FOR`, `INBOX_KEPT_FOR`, `INBOX_SPAM_LINE`,
  `INBOX_HOLD_BLOCKED_COUNTRIES`, `INBOX_LEARN`, `INBOX_NEVER_SPAM`, the sender (`INBOX_FROM_NAME`, `_FROM_ADDRESS`,
  `_REPLY_TO`), replies (`INBOX_REPLY_FORMAT`, `_SIGNATURE_TEXT`, `_SIGNATURE_HTML`), notices (`INBOX_NOTIFY_EMAIL`,
  `_NOTIFY_TELEGRAM`, `_ACKNOWLEDGE`), the mailbox (`INBOX_IMAP_HOST`, `_PORT`, `_SECURITY`, `_USER`, `_PASSWORD`).
- The form carries `Inbox::stamp()` in `contact_opened`; the controller calls `Inbox::keep($message)`, then `arrived()`.
  `markSpam()`, `notSpam()`, `blockSender()`, `trash()`, `restore()`, `deleteForever()`, `emptyFolder()`, `counts()`,
  `cleanUp($now)` for the daily clean-up; `Inbox\InboxReplies` writes and sends replies. `Inbox\InboxMailbox` is a
  connection (the guide's 2.10): `check()`, `status()`, `detailsSaved($before)`, `forget()`; only a connected mailbox is read, one
  that refused waiting for Check the connection.

### 8.9 Mailing

For a site sending news to account holders who agreed:

- **Settings > Mailing:** the sender, the links' address, the transport: a mail server (each copy with
  `List-Unsubscribe`, one-click `List-Unsubscribe-Post`, `List-Id`, `Feedback-ID`) or catto-mail, a smarthost with an
  HTTP API (a send job per list, each request idempotent).
- **catto-mail's connection** (`Mailing\CattoMail\CattoMailConnection`): Connect by its approval page (OAuth 2, PKCE),
  key and webhook secret saved encrypted; Check the connection, Drop connection, test email; news waits while the
  sending domain cannot send or the quota is used. Its webhook (`CattoMailWebhook::answer($signature, $body)`) keeps
  each signed event once for the project's `CattoMail\CattoMailEventHandler` services (tag
  `cattophp.cattomail_event_handler`).
- **Lists and consent** (`Mailing\Consents`, `MailingLists`, `Subscriptions`): a list per subject offered (made at the
  first yes), an administrator's (`own`: name, join link, open or closed, own sender); each answer with where given and
  its history: `subscribe()`, `unsubscribe()`, `joinList()`, `unsubscribeList()`, `unsubscribeAll()`; **addresses never
  emailed:** `Mailing\Suppressions`.
- **Campaigns and copies** (`Mailing\Campaigns`, `Copies`, `Links`), sent a little each run of
  `Mailing\CampaignSender::run()`, which the project's task calls each minute: within the sending hours and hourly
  limit, a first day doubling, a copy skipped for an address never emailed or a person who left every list that brought
  it, a campaign pausing itself above the bounce or complaint rate, outcomes read back.
- **The links in a copy** (`Mailing\CopyLinks`), **Forward to a friend** (`Mailing\Forwards`: five friends a copy, ten a
  day, on no list, "Never email me"), **the figures** (`Mailing\MailingFigures`) serve the project's pages.

```yaml
mailing:
  enabled: true
  project: example  # in Feedback-ID headers and catto-mail's job references
  callback_path: /mailing/cattomail/callback
  webhook_path: /webhooks/cattomail
  campaign_project: ~  # Mailing\CampaignProject; without it campaigns are kept but not sent
  recipient_addresses: ~  # Mailing\RecipientAddresses: where a mail server delivers made-up addresses
```

- Settings (group "Mailing"): `MAILING_FROM_ADDRESS`, `MAILING_FROM_NAME`, `MAILING_LINK_BASE`, `MAILING_TRANSPORT`,
  `MAILING_DSN`, `CATTOMAIL_API_BASE_URL`, `CATTOMAIL_API_KEY`, `CATTOMAIL_WEBHOOK_SECRET`,
  `CATTOMAIL_WEBHOOK_SECRET_PREVIOUS` (DSN, key and secrets encrypted).
- **The project's side** (`Mailing\CampaignProject`): `audience($campaign)`, `sender()` (`SenderIdentity`),
  `draw($campaign, $copy)` (`DrawnCopy`: subject, HTML, text, unsubscribe link, list name), `drawForFriend()`,
  `waitsForMore()`, `rules()` (`Mailing\SendingRules`: 300 an hour, 50 the first day, a pause over 2% bounces or 0.1%
  complaints after 20 copies, sending hours, whether opens and clicks are recorded).
- **The discipline:** a reputation is kept by consent only and the stops, pacing and pauses above, not more sending
  addresses.

### 8.10 Telegram

A bot connected at a settings page (key encrypted, never shown back; the owner's username and chat; no phone number or
`.env` value). Messages to the owner are queued in the transaction of what happened, sent within a minute
(`owner_messages`), so Telegram being down holds nothing up; to people (a sign-in code) at once; people connect through
the bot's `/start` by a 10-minute single-use link, what they send read by `getUpdates` only while they may sign in with
Telegram. No secret logged; one bot, one site.

```yaml
telegram:
  enabled: true
  choices: [on_sale]  # the project's own choices of what messages the owner, each on until turned off
```

`Telegram\OwnerMessages::queue($reference, $kind, $words)` (once a reference; nothing unless connected);
`Telegram\TelegramSetup` (`view()`, `connect()`, `saveChoices()`, `sendTest()`, `dropConnection()`);
`Telegram\TelegramConnections` (a person's `start()`, `view()`, `test()`, `dropConnection()`);
`Telegram\TelegramMessenger::send()`. What a message says, and when, is the project's.

### 8.11 An API and signed webhooks

Tokens issued to accounts with a name, scopes and optional lifetime, shown once, kept as SHA-256, accepted while
unrevoked, unexpired and the account may use the API, each use noted; a Bearer authenticator and entry point, a scope
voter, one JSON error document, `{"error": {"status", "code", "message"}}`, never cached.

```yaml
api:
  enabled: true
  path: /api/
  token_prefix: app_  # 'cat_' by default
  scopes: { 'notes:read': 'Read notes.' }
  all_scopes: ~
  accounts: ~  # Api\ApiAccounts: whether an account may use the API
```

```yaml
# security.yaml, before main:
firewalls:
  api:
    pattern: ^/api/
    stateless: true
    provider: cattophp
    custom_authenticators: [CattoPHP\Api\ApiTokenAuthenticator]
    entry_point: CattoPHP\Api\ApiTokenAuthenticator
access_control:
  - { path: ^/api, roles: PUBLIC_ACCESS }  # the authenticator refuses a request without a good token
```

`Api\ApiTokens`: `create($accountId, $name, $scopes, $ttlSeconds, $actor)`, `tokensOf()`, `revoke()` (both logged). A
route asks `#[IsGranted('notes:read')]` and `#[IsGranted('NOTE.VIEW')]`, `Api\ApiAccess` the same elsewhere;
`Api\ApiProblem::response()` draws the error document. **Signed webhooks** (`Webhooks\WebhookSignature`, always there):
`t=<time>,v1=<hex>`, the HMAC-SHA256 of `<t>.<body>`; `sign()`, `valid()` (refusing one over 300 seconds off; several
secrets accepted, for rotating).

### 8.12 Documents and PDFs

Templates in a controlled language (`{{ group.name }}`, `{{#each items}}...{{/each}}`,
`{{#if name}}...{{else}}...{{/if}}`), checked against the type's placeholders; nothing executable or fetched (no script,
event handler, style element, remote picture or `@import`; pictures `data:` or `asset:<sha256>`); each value written by
kind, escaped; one renderer for previews, documents and PDFs (Dompdf, remote resources, PHP and JavaScript off). An
invalid template or ill-fitting data is an error, never a blank.

```yaml
documents:
    enabled: true  # everything below is optional
  fonts: ''  # a folder of the project's fonts; without it Dompdf's DejaVu Sans is used
  families: {}  # 'Plex Sans': PlexSans reads PlexSans-Regular.ttf, -Bold, -Italic, -BoldItalic there
  font_cache: '%kernel.project_dir%/var/dompdf-fonts'
  images: ~  # Document\DocumentImages: stored pictures by asset:<sha256>
```

A document end to end, a note's slip:

```php
use CattoPHP\Document\{DocumentRenderer, PageSettings, PdfRenderer, PlaceholderDefinition as P, PlaceholderKind as K, PlaceholderSet};

final class NoteSlip
{
  private const HTML = '<h1>{{ note.title }}</h1><p>Written {{ note.written }} by {{ author.name }}.</p>'
    . '{{#if note.body}}<p>{{ note.body }}</p>{{/if}}';
  private const CSS = 'body { font-size: 11pt; } h1 { font-size: 18pt; }';

  public function __construct(private readonly DocumentRenderer $renderer, private readonly PdfRenderer $pdf) {}

  public function pdf(array $note): string
  {
    $set = new PlaceholderSet([
      new P('note.title', 'The title', K::Text, true),
      new P('note.written', 'When it was written', K::Date, true),
      new P('author.name', 'Who wrote it', K::Text, true),
      new P('note.body', 'What it says', K::Multiline),
    ]);
    $document = $this->renderer->render('note-slip', 'Note slip', $set, self::HTML, self::CSS,
      new PageSettings('A5', 'portrait', 15, 15, 15, 15),
      ['note.title' => $note['title'], 'note.written' => new \DateTimeImmutable($note['written_at']),
       'author.name' => $note['author'], 'note.body' => $note['body']]);

    return $this->pdf->render($document);  // the PDF's bytes
  }
}
```

A controller answers with those bytes as `application/pdf`, `HeaderUtils::makeDisposition(DISPOSITION_ATTACHMENT, ...)`.

- **The placeholder set** (`Document\PlaceholderSet($scalars, $collections)`):
  `PlaceholderDefinition($name, $description, $kind, $required)`, named `group.name`. Kinds: `Text`, `Multiline` (breaks
  kept), `Money` (`['minor' => 120000, 'currency' => 'ZAR']`, never a float), `Date` (a `DateTimeInterface` or
  `YYYY-MM-DD`), `Address` (a list of lines), `Url` (http or https), `Integer`, `Percent` (a string, `"84.50"`). A
  repeating block is a `CollectionDefinition($name, $alias, $description, $fields)`, fields named by the alias
  (`item.title`), its data `$data[$name]`, a list of arrays by field (`['title' => ...]`).
- **The data** is keyed by each placeholder's dotted name; a required one missing or a value of the wrong kind is an
  error (`DocumentDataException`, each problem in words). An optional one may be null or left out, drawing nothing,
  `{{#if}}` false (as for blank text or zero money).
- **Page settings** (`Document\PageSettings($size, $orientation, $top, $right, $bottom, $left)`): A4, A5, Letter or
  Legal, portrait or landscape, margins 0 to 50 whole mm.
- **The result:** `render()` gives a `RenderedDocument` (`html`, `body`, `settings`; keep its `reference()` with an
  issued document); `PdfRenderer::render()` the PDF's bytes, `renderPage($html, $size, $orientation)` a page set out for
  print, `renderWithLayout()` whether a box overflowed.
- Money in the installation's locale (`APP_LOCALE`, `en_GB` by default), dates as "6 October 2026" in its time zone
  (the guide's 5.4). A project keeps its document types, placeholders, templates and pictures; the owner edits a design in one form
  with a live preview, never raw HTML, each save a new version.

### 8.13 Search engines and AI

`robots.txt` with the private paths and reasons and a group per AI crawler as a setting chooses (never shutting out
search engines); the sitemap from the project's sources (an index past 50,000 addresses; none on a demo); `/llms.txt`;
share pictures drawn from SVG at 1200 by 630 by `rsvg-convert`; schema.org structured data; each public page's head:
description, canonical address (only its page number past the first), Open Graph and X's card, search results unindexed,
", page N" after a later page's title, an administrator's own title and description per page.

```yaml
search:
  enabled: true
  private_paths:
    - { why: 'Signed-in pages.', paths: [/account/, /admin/] }
  share_pictures: '%kernel.project_dir%/var/share-pictures'
  rsvg_convert: ~
  llms_txt: ~  # Search\LlmsTxtContent; without one there is no llms.txt
  site_picture: ~  # Search\DefaultSharePicture
  cache: cache.app  # a rendered sitemap is kept an hour
```

- Settings (group "Search engines and AI"): `SEARCH_AI_CRAWLERS` (`all`; `search`, AI search in and training kept
  out; `none`), `SEARCH_GOOGLE_CODE`, `SEARCH_BING_CODE`.
- Extension points: `Search\SitemapSource` (tag `cattophp.sitemap_sources`: `count()`, `entries($limit, $offset)`),
  `Search\PublicPageProvider` (tag `cattophp.public_pages`), `LlmsTxtContent` (`name()`, `summary()`, `notes()`,
  `sections($base)`), `DefaultSharePicture` (`url($base)`, `alt()`, `type()`).
- The project's routes serve `Search\Sitemap::document()` and `page($n)`, `RobotsTxt::document()`,
  `LlmsTxt::document()`, `SharePictures::bytes($svg)` (made when a cover is saved).
  `Search\PageHead::complete($page, $meta, $path, $query, $title)` gives the head, its language from the locale (the guide's 5.4; no
  `EnvironmentValues` since 1.0). `Search\StructuredData` builds nodes: nothing the page does not show, nothing on an
  unindexed page, a rating only from approved reviews. A demo site is never indexed; redirects for changed public
  addresses wait till a site is live.

### 8.14 Payments

Payment gateways through Omnipay, keys as settings, each connection from its last real check, naming no order or
product. The project requires `omnipay/common`, the gateway's package (such as `rrcatto/omnipay-stitch-express`) and an
HTTP client Omnipay can discover.

```yaml
payments:
  enabled: true
  gateways:
    card:  # the project's name for it; its settings group is payment_card
      omnipay: StitchExpress  # a short name or a class
      label: Card payments
      parameters:
        clientId: { name: CARD_CLIENT_ID, label: Client ID }
        clientSecret: { name: CARD_CLIENT_SECRET, label: Client secret, secret: true }
        webhookSecret: { name: CARD_WEBHOOK_SECRET, label: Webhook secret, secret: true, optional: true }
```

`Payments\PaymentGateways`: `gateway('card')` (parameters from Settings and the request), `configured()`, `missing()`,
`parameters()`. `Payments\GatewayConnections` (the guide's 2.10): `check($gateway, $ask)` (refused while a key has no value; `$ask`,
the project's question to the provider, gives null when the keys are accepted, else its words), `status()`,
`connected()`, `drop()` (every key forgotten, `.env`'s too), `resetToEnv()`.

### 8.15 Connections to outside services

`Settings\ServiceConnections` keeps each connection's last real try by name (`didit`, a gateway's): when, whether it
worked, what went wrong, what it learnt, a keyed fingerprint of the details (so details changed since, on the page or in
`.env`, count as unchecked). States: `none`, `unchecked`, `failed`, `connected`.
`record($name, $details, $connected, $error, $learnt)`, `status()`, `connected()`, `forget()`; kept in
`cattophp_connections`. A project's own connection uses it too, its page as the guide's 2.10 says.

### 8.16 The Campaign Marketing Manager

Campaigns with their adverts, publications (each place one is published, with its own tracking link) and spend, and
their clicks, unique visitors and costs by campaign, channel, publication, advert, place, country and day. Switch it on
(`marketing: {enabled: true, project: <service id>}`; `files_folder` keeps advert pictures, never served;
`link_prefix`, `c`, the links' first part), run `cattophp:migrate`, import both route files:

```yaml
cattophp_marketing_links: { resource: '@CattoPHPBundle/config/routes/marketing_links.php' }
cattophp_marketing: { resource: '@CattoPHPBundle/config/routes/marketing.php' }
```

The first is the public tracking links, `/c/{campaign}` (with `?src=` or UTM tags) and `/c/{campaign}/{publication}`,
each recording the touch and forwarding: access control lets everyone open them (`{ path: '^/c/', roles:
PUBLIC_ACCESS }`). The second is the pages, under `/marketing`.

- **`Marketing\MarketingProject`** gives the project's part (`NoProject` without one): `offersHeading()`,
  `offerKinds()`, `offers()` and `offer($type, $reference)` (what a campaign may promote, each an `Offer`: label,
  address, price, "was" price, on sale or not, public or not, and its own slug, where its landing page's address starts), `account($id)`, `organisation($reference)` (a buying
  company), `accountsMatching($search)` (its own search of its people, as account ids), `publicationSource($type,
  $reference)` (the record a publication was made from, such as a mailing message), `saleHref()`, `promotions()`,
  `promotionHref()`, `money($minor)`, `currency()`.
- **Settings** (group "Campaign Marketing Manager", `MARKETING_*`): how long a visitor is remembered, a visit lasts, a
  sale counts and for which campaign, IP addresses and histories are kept; Do Not Track; the largest advert picture.
- **Permissions:** `MARKETING.VIEW` (see it), `MARKETING.CAMPAIGN.MANAGE` (change campaigns, adverts, publications,
  spend), `MARKETING.PEOPLE.VIEW` (who visitors and customers are), `MARKETING.SETTINGS.MANAGE` (settings, channels).
- **The pages** (`Marketing\Pages\`, through `StockPages`, the guide's 7.5; a `PageProject` puts them in its admin family):
  `marketing-overview` (`/marketing`), `marketing-campaigns`, `marketing-campaign-new`, `marketing-campaign-edit`,
  `marketing-campaign` (`/marketing/campaigns/{code}`: everything about one, each tracking link ready to copy with its
  QR code), `marketing-publication-new`, `marketing-publication`, `marketing-advert-new`, `marketing-advert`,
  `marketing-visitors` and `marketing-visitor` (`/marketing/visitors`, `/{reference}`), `marketing-sales` and
  `marketing-sale` (`/marketing/sales`, `/{reference}`, a connected site's `/{site}/{reference}`), `marketing-settings`
  (with the channels); CSV at `/marketing/campaigns.csv`, `/marketing/figures.csv`, `/marketing/visitors.csv`,
  `/marketing/visitors/{reference}/journey.csv`, `/marketing/sales.csv` and `/marketing/sales-lines.csv`. Their icons
  are in the README.
- **The menu:** `Marketing\MarketingMenu`, section `marketing` ("Marketing", `nav-marketing`): Overview, Campaigns,
  Landing pages, Visitors (`MARKETING.PEOPLE.VIEW`), Sales, Settings, each behind its permission.

**Landing pages.** A campaign's page is drawn at its tracking link's own address, `/c/{campaign}` (a publication's
link too, when it has no destination of its own), in the same answer, `noindex`; the click is the touch and the visit
a `landing_opened` event. An offer's page is at `/{landing_prefix}/{slug}` while it and its offer are public. Each has
a list of sections, the hero first; every published state is kept as a version. Configuration: `landing_prefix`
(`landing`), `landing_project` (a `LandingProject` service id) and `landing_builtin_templates` (true). Import the third
route file, and let everyone open `^/(c|landing)/`:

```yaml
cattophp_marketing_landing: { resource: '@CattoPHPBundle/config/routes/marketing_landing.php' }
```

It serves the offer pages and, under both kinds, `/picture/{ref}` (only a picture the page names) and `/go/{press}`
(a GET button: recorded, then 302 to what the section holds).

- **`Landing\SectionKind`** (tag `cattophp.marketing_landing_section`): a project's own sections beside CattoPHP's
  eleven (hero, words, picture, video, offers, audience, outcomes, benefits, countdown, faq, cta): key, label, `once()`,
  `appliesTo()`, `defaults()`, `normalise()` (plain text through `LandingCopy`; a `CopyRefused` names the field),
  `missing()`, `summary()`, `form()` and `formTemplate()` (its fields, which may include
  `@CattoPHP/marketing/landing/forms/heading`, `rows` and `picture-field`), `view()` and `template()`. An offer is stored
  as `type:reference`, a picture as `{source, ref}`.
- **`Landing\LandingProject`** (`GenericLanding` without one): `card()` (an `OfferCard`: picture, badges, prices,
  `OfferAction`s, `links` for the hero's second button, `about` for the words a hero without its own shows, and its
  form's placeholder; the short `line` otherwise), `purchase()` (its own buy box, a `ProjectPart`), `page()` (what
  its frame reads: `page_meta`, menu mark) and `access()` (a `LandingAccess` for an offer's page: see, edit, publish,
  upload, saved templates). A POST action's handler calls `LandingPresses::accepted($request, $offer, $action)`; the
  form carries `cattophp_landing` and `cattophp_return`.
- **`Landing\PictureSource`** (tag `cattophp.marketing_landing_picture_source`; CattoPHP's `upload` and `offer`) and
  **`Landing\TemplateProvider`** (tag `cattophp.marketing_landing_templates`; `BuiltInTemplates` gives Several offers and
  A special with an end date).
- **Events:** `LandingPageChanged` (each change, also in the activity log as `marketing.landing_page.*`, subject
  `marketing_landing_page`), `LandingPageOpened`, `LandingOfferPressed`. When an offer's code changes or it goes, the
  project calls `LandingPages::offerRenamed($type, $from, $to)` or `offerRemoved($type, $reference)`.
- **Settings:** `MARKETING_LANDING_RECENT` (30d, the list's visits) and `MARKETING_LANDING_PICTURE_CACHED` (1h).
- **The pages:** `marketing-landing-pages` (`/marketing/landing-pages`, with the saved templates), `marketing-landing-new`
  (`/new`) and `marketing-landing-edit` (`/{reference}`, one page: the sections, the open section's form
  (`?section=<reference>`) and its preview, templates, settings, Earlier versions; `/preview`, `/versions/v<n>`); the
  public page and every preview are `landing-page`. Campaign pages need `MARKETING.VIEW` to see and
  `MARKETING.CAMPAIGN.MANAGE` to change; offer pages answer to `LandingProject::access()`. Every action is a form; with
  htmx the part it changed comes back in place. The campaign's page gains a Landing page card and its visits and
  buttons pressed.
- **Mounting the editor elsewhere**, as a project does for its offers' pages under its own address and access rule:
  `LandingEditor::routes($routes, 'shop_landing', '/shop/{reference}/landing', [ShopLanding::class, 'answer'],
  ['reference' => '[a-z0-9-]+'], make: true)` adds every route, named `shop_landing_<action>`, each giving the action
  as `$action`; the controller answers `$editor->answer($request, new EditorPlace('shop_landing', ['reference' =>
  $reference], $access, $page, 'product', $reference, backHref: '/shop'), $action)`, `$page` being
  `LandingPages::forOffer()` or null (the editor then offers to make it).

**The journey, sales and connected sites: what they rest on.** The schema step `202611141200-marketing-sales` (run
`cattophp:migrate`) adds sales with their lines and money (paid, each refund, each chargeback), connected sites with the
reports to and from them, a reference for each visitor (their address and, until they are known, their name: "Visitor
7k2m9q4d") and for each new touch, and the sale an event belongs to. Configuration: `returns` (true: notice a followed
visitor coming back) and `returns_excluded` (the starts of paths where it is never noticed, such as `/admin`). Settings:
`MARKETING_PROMOTION_COUNTS` (`no_link`: a campaign's promotion code gets it a sale only when the customer followed no
counted link; or `always`), `MARKETING_SITE_TOLERANCE` (5m), `MARKETING_SITE_RETRY_LONGEST` (1h) and
`MARKETING_SITE_REPORTS_KEPT` (30d). A project describes an order as a `Sales\SaleReport` with its `Sales\SaleLine`s
(offer type and reference, label, kind's label, quantity, price, "was" price, discount, wallet credit, and the offers a
bundle held), the same whole description each time; nothing is refused as it is built, `problems()` says what is wrong,
and a report with any problem is refused with the reason in the log. A visitor is tied only to the first account it
meets (`Visitors::identify()`), and `Events::record()` takes a `Journey\EventInput`.

**The journey: what a project calls, and when.** `Marketing\Journey` records only a followed reader's actions (a person
not asking not to be tracked, whose cookie names a visitor or whose account a visitor is tied to), each with their
current campaign; a reader who never came by a campaign leaves nothing. Every call takes an optional request and account
(the current request and the signed-in reader otherwise), returns nothing and never throws:

- `viewed($offer)` when an offer's own page is opened (never a preview or the project's own editing);
- `favourited($offer, $on)` and `basketChanged($offer, $added, $quantity)` after each change a person made that the
  project accepted (never its own merging of a guest's basket at sign-in); `taken($offer)` when a free offer is taken
  without an order; `subscribed($listLabel)` when a person agrees to a list (the label names no person);
- `carry()` when a registration form is sent: keep the reference it gives (or null) with the pending registration, and
  give it back to `registered($account, $carried)` when the emailed link is opened and the account exists (before any
  hold for approval: it is still a registration);
- `signedIn($account)` at each sign-in.

Each recorded event is announced as a `Journey\JourneyRecorded`. Coming back is noticed by CattoPHP itself on the pages a
followed visitor opens (`Journey\VisitorReturns`); give `returns_excluded` the paths that are not public pages.
`Journey\PersonJourneys::of($visitor)` (or `ofAccount()`) reads one person's journey for a page.

**Sales: what a project calls, and when.** `Marketing\Sales\Sales` keeps every order the project reports, whether or not
a campaign brought it. Call it inside the project's own transaction, with the order's whole `SaleReport` each time:

- `placed($report)` when an order is placed (in the buyer's request, so their browser's visitor and country are kept);
- `paid($report, $at, $how)` at the one place an order becomes paid: it also writes a sale whose placing was lost, and
  fixes which campaign gets it, counted back from `$at`;
- `failed($reference, $attempt, $why, $at)` for a payment that failed, in words; `cancelled($reference, $at)` for an
  unpaid order cancelled (calling `placed()` again puts it back to waiting);
- `refunded($reference, 'refund:<id>', [$lineKey => $amount], $at, $how)` for each refund, and `chargedBack($reference,
  'chargeback:<id>', $amount, $at)` for each chargeback (shared over the lines when none are given).

Each is idempotent by the reference and the key, so report again whatever may have been lost: a daily check that
reports every paid order `has($reference, 'paid')` does not know, and every refund `hasMovement($reference, $key)` does
not, keeps every sale visible. A call never stops a purchase: a report with problems is refused and logged, and a fault
rolls back to its own savepoint, is logged and gives null, the project's work committed. A sale counts for the first or
last counted click of the buyer's visitors within `MARKETING_SALE_COUNTS_WITHIN` (one at a time, as
`MARKETING_SALE_COUNTS_FOR` says), or for the campaign whose promotion code it used as `MARKETING_PROMOTION_COUNTS`
says; the pages read `list()`, `totals()`, `offers()`, `stateCounts()`, `latest()` and `forCampaign()` through a
`Sales\SaleFilter`.

**A mailing message as a publication** (mailing on as well). When a message's details are saved with a campaign chosen,
call `Mail\MailingPublications::tie($message, $campaignCode, $place, $actor)`, and `untie($message, $actor)` when it is
set to none. In the click link's controller, after `Mailing\CopyLinks::clicked()`, call `Mail\MailedLinks::followed($token,
$url, $request)` and set the cookie of the `TrackedOpening` it gives, if any, on the redirect; in the opening picture's,
after `CopyLinks::opened()`, call `MailedLinks::opened($token)`. Never call them from the unsubscribe link, its one-click
POST, the web copy or Forward to a friend. `MarketingProject::publicationSource()` names the message a publication
stands for.

**The journey and sales: what the pages show.** They read only what the project reported, so they need nothing more of
it than the calls above and `MarketingProject`'s `account()` (a person's name, reference and page), `organisation()`,
`accountsMatching()` (Visitors and Sales find a person by name, email address or account reference through it, the
module never holding a name), `saleHref()` (Open the order) and `publicationSource()`.

- **Visitors** (`MARKETING.PEOPLE.VIEW`): one row for each person, all the visitors tied to their account together, and
  one for each visitor not known yet; a visitor made anonymous is never listed and their page is not found. Each row's
  state is a paying customer (a paid sale), an order waiting for payment, registered or not registered; its visits are
  counted clicks and comings back. Filters: the period last seen (30 days to start), the state, a campaign, a country
  and the search; totals of visitors, known people and paying customers; the CSV holds the rows and totals shown.
- **One visitor:** the journey oldest first, from every device; for each paid sale its last and first touch, both
  within the counting time as it was, and which the reports count now; who they are (the IP address is never shown:
  "IP addresses are kept for 30 days", worded from `MARKETING_IP_KEPT`); the five latest other visitors. Forget this
  visitor's journey (`MARKETING.CAMPAIGN.MANAGE` too) makes every visitor of the person anonymous and takes their account
  off their events and sales; the clicks and money stay in every total, naming nobody (their sales share one random mark,
  so they still count as one paying customer), and the activity log says `marketing.visitor.forgotten`.
- **Sales** (`MARKETING.VIEW`): every sale reported, with or without a campaign; customers are named only to a reader
  with `MARKETING.PEOPLE.VIEW` (else "A customer", in the CSV too). Filters: the period (a paid sale by when it was paid,
  any other by when it was placed; 30 days to start), the state (paid to start; waiting for payment, cancelled, all), "A
  sale counts for" (the first or the last touch, the setting's to start, changing only what that page shows), Where
  sold (with a connected site), the campaign (any, none or one), its publication, the channel, an offer, the country and
  an order's reference. The totals are `Sales::totals()` of the same `SaleFilter`, never added up from the rows shown:
  gross revenue counts only paid sales, waiting ones are shown apart, refunds and chargebacks are those of the sales
  listed whenever they were made, and a sale in another currency than the site's is listed in its own and left out,
  the note naming it. `/marketing/sales.csv` holds the rows shown (money as 4150.00, empty for a sale not paid, with its
  currency and what it is to pay) and the totals; `/marketing/sales-lines.csv` one row for each line.
- **One sale:** its facts, lines (a bundle's offers in small text), money as it moved, both touches with how each was
  found and which counts now, and for a sale made here what was reported of it to connected sites and whether it was
  delivered; a connected site's sale is marked "Sold on <site>" and has no Open the order.
- **A campaign's page** gains Registrations, Paying customers, Net revenue and Back for every R1 (one unit as the
  project writes money: net revenue for each one spent, "No spend" when nothing was), each publication's paid sales and net revenue, each advert's net revenue and
  clicks, each offer's views and favourites (by people who came by it within `MARKETING_SALE_COUNTS_WITHIN` before),
  units sold and net revenue, and the offers its sales held that it does not promote; Customers and their orders (the
  ten newest, how many in each state, See all its sales, Download as CSV); all under "A sale counts for".
- **The overview** gains Latest sales: the ten paid since the start of the day `MARKETING_LATEST_SALES` ago (30 days to
  start), with All sales opening the Sales page for the same days.

**Connected sites: one campaign across two sites.** When a campaign's links lead to another site of yours (one sends the
email, the other sells), connect them: import `@CattoPHPBundle/config/routes/marketing_sites.php` on both, let everyone
reach `^/webhooks/` in the access control, keep the scheduler on, and have `symfony/http-client`. Then on each site,
Marketing > Settings > Connected sites, Connect a site with the other's name, its `https://` address and one shared
secret (Make a secret on one, paste it on the other), and press Check the connection: the card says Connected. A link
whose destination is on the other site then forwards each person's click with a signed reference; the other site records
the arrival as a click of the same campaign (made for it if it has none, "Its links are on <site>") and reports back,
every minute, the registrations, sales, refunds and chargebacks that click brought, with codes, amounts and lines only,
never a person. The sending site shows those sales as its campaign's, marked as sold on the other site and never in its
own takings; the selling site shows them as its own sales, their touch an arrival from the other site. A secret changed on
one side keeps the old one accepted until the other has the new one; a refused arrival or report, a site not reached and
clocks too far apart are named on the card, never to the sender, and waiting reports are tried again until
`MARKETING_SITE_REPORTS_KEPT`, then offered with Send them again. The first wait between tries is `MARKETING_SITE_RETRY_FIRST`
(1m), each wait after it twice as long up to `MARKETING_SITE_RETRY_LONGEST`.
