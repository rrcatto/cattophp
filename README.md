# CattoPHP

A Symfony bundle with the foundations of a web application: sign-in by emailed link with two-step codes, settings
that override `.env`, the installation's details, roles and permissions, an activity log, error pages in plain words,
strict security headers, form protection and mail. It is the shared foundation of the Catto projects
([cattolms](https://github.com/rrcatto/cattolms), [ctnlist](https://github.com/rrcatto/ctnlist) and
[catto-mail](https://github.com/rrcatto/catto-mail) among them), each drawing its pages in its own look.

What each version holds is in [CHANGELOG.md](CHANGELOG.md).

## Requirements

- PHP 8.5.12 or later (8.5.12 fixes two remote code execution flaws), with mbstring and OpenSSL.
- Symfony 8.1, with its security bundle and Twig.
- PostgreSQL, through Doctrine DBAL, for the records CattoPHP keeps; or, for a page without a database, files in a
  folder only the site's own account can read.

## Using it in a project

Require it from GitHub in the project's `composer.json`:

```json
"repositories": [{"type": "vcs", "url": "https://github.com/rrcatto/cattophp"}],
"require": {"rrcatto/cattophp": "^0.3"}
```

Copy its browser files into the web root after every install and update, in the project's `composer.json`:

```json
"scripts": {
    "post-install-cmd": ["@php vendor/bin/cattophp-assets public"],
    "post-update-cmd": ["@php vendor/bin/cattophp-assets public"]
}
```

and load `cattophp/css/cattophp.css` on every page after Bootstrap's reboot, grid and utilities and before the project's
own stylesheet, and `cattophp/js/cattophp.js` after htmx and before the project's own scripts.

Register the bundle in `config/bundles.php` beside Symfony's framework, security and Twig bundles:

```php
CattoPHP\CattoPHPBundle::class => ['all' => true],
```

Configure it in `config/packages/cattophp.yaml`:

```yaml
cattophp:
    application_key: '%env(APP_KEY)%'           # secrets are encrypted and codes hashed with it
    storage:
        type: database                          # or files, with folder: for a page without a database
        connection: doctrine.dbal.default_connection
    site_mode: true                             # demo or live; false for a project without the distinction
    layout: base.html.twig                      # the template CattoPHP's pages extend (block body)
    starting_values: {}                         # values in place of .env's, by name: APP_NAME: Example
    sign_in:
        accounts: App\Security\Accounts         # the application's accounts (CattoPHP\SignIn\SignInAccounts)
        after: /                                # where a person goes after signing in
        recovery_codes: true
    security_headers:
        policy: {}                              # Content-Security-Policy directives replaced or added
        referrer_policy: strict-origin-when-cross-origin
        no_index: false                         # true asks search engines to keep away, demo or live
        no_store: false                         # true asks browsers to keep no copy of any page
    error_pages:
        skip_paths: [/api/]                     # paths whose errors the project answers itself
    ui:
        icon_sprite: /img/icons.svg             # the icon sprite whose symbols the icon component draws
        public_root: '%kernel.project_dir%/public'  # where the sprite is found for its fingerprint
    payments:                                   # only for a project that takes payments (omnipay/common)
        gateways:
            stitch:
                omnipay: StitchExpress          # the Omnipay gateway: a short name or a class
                label: Card payments (Stitch)
                parameters:
                    clientId: { name: STITCH_CLIENT_ID, label: Client ID }
                    clientSecret: { name: STITCH_CLIENT_SECRET, label: Client secret, secret: true }
    scheduler:                                  # only for a project with background work
        enabled: true
        command_alias: 'schedule:run'           # another name for cattophp:schedule:run, optional
        log_archive: { enabled: true, folder: '%kernel.project_dir%/var/log' }
    network:                                    # what an address says about a visitor (needs geocoder-php/geoip2-provider)
        country_database: '/var/lib/GeoIP/GeoLite2-City.mmdb'  # its country and place through Geocoder; GeoLite2-ASN.mmdb beside it for its network
        geocoder: App\Geo\Provider              # another Geocoder provider (Geocoder\Provider\Provider), such as a chain, optional
        lists_folder: '%kernel.project_dir%/var/network-lists'  # the Tor, VPN, hosting and throwaway email lists, optional
    protection:                                 # only for a public site (needs the database)
        enabled: true
        secret_paths: ['/join/']                # paths whose next part is always a secret, masked in the Site Log
        firewall_folder: '%kernel.project_dir%/var/server-firewall'
    inbox:                                      # only for a site with a contact form (needs the database)
        enabled: true
        files_folder: '%kernel.project_dir%/var' # replies' files, each under inbox/<its public id>/
        documents: App\Inbox\Invoices            # an account's documents a reply may attach (CattoPHP\Inbox\InboxDocuments), optional
    mailing:                                    # only for a site that sends news (needs the database and symfony/http-client)
        enabled: true
        project: example                        # in Feedback-ID headers and catto-mail's job references
        recipient_addresses: App\Mail\TestInboxes # where a mail server delivers made-up addresses (CattoPHP\Mailing\RecipientAddresses), optional
    signup:                                     # only for a site where people register (needs the database)
        enabled: true
    identity:                                   # only for a site that must know who people are (needs the database and symfony/http-client)
        enabled: true
        return_path: /account/identity/return   # the project's route a person comes back to from Didit's page
        webhook_path: /webhooks/didit           # the project's route Didit's webhooks arrive at
        accounts: App\Identity\References       # what Didit is told of an account (CattoPHP\Identity\IdentityAccounts), optional
        approved: App\Identity\WhenApproved     # what happens when one is approved (CattoPHP\Identity\IdentityApproved), optional
        minimum_age: 18                         # an approval is passed on only from this age, optional
    telegram:                                   # only for a project with a Telegram bot (needs the database and symfony/http-client)
        enabled: true
        choices: [on_sale]                      # the project's own choices of what sends the owner a message
    api:                                        # only for a project with an API (needs the database)
        enabled: true
        token_prefix: app_                      # what a token starts with
        scopes: { 'notes:read': 'Read notes.' } # each scope the project offers and what it allows
        accounts: App\Api\Accounts              # whether an account may use the API (CattoPHP\Api\ApiAccounts), optional
    documents:                                  # only for a project that draws documents and PDFs (needs dompdf/dompdf)
        enabled: true
        fonts: '%kernel.project_dir%/resources/fonts'
        families: { 'Plex Sans': PlexSans }     # each family in four faces: PlexSans-Regular.ttf, -Bold, -Italic, -BoldItalic
        images: App\Document\StoredPictures      # stored pictures named asset:<sha256> (CattoPHP\Document\DocumentImages), optional
    search:                                     # only for a site with public pages (needs the database)
        enabled: true
        private_paths:                          # the paths robots.txt asks crawlers to leave alone, each group with its reason
            - { why: 'Signed-in pages.', paths: [/account/, /admin/] }
        share_pictures: '%kernel.project_dir%/var/share-pictures'
        llms_txt: App\Search\SiteSummary        # what /llms.txt says (CattoPHP\Search\LlmsTxtContent), optional
        site_picture: App\Search\SitePicture    # the picture a page without its own is shared with, optional
    setup:                                      # only for a project with a setup checklist (needs the database)
        enabled: true
    theme:                                      # only for a project with themes
        default: example                        # the slug of the bundled theme a site starts on
        bundled: '%kernel.project_dir%/themes'  # the project's own themes, in its code
        installed: '%kernel.project_dir%/var/themes'
        staging: '%kernel.project_dir%/var/theme-imports'
        public_root: '%kernel.project_dir%/public'  # each theme's browser files go into its themes/ folder
        page_families: [admin]                  # the families a theme may wrap with pages/<family>.html.twig
        choice: App\Theme\StoredChoice          # where the active theme is kept (CattoPHP\Theme\ThemeChoice)
        pages: App\Theme\Pages                  # the project's pages, for PageRenderer (CattoPHP\Theme\PageProject)
```

Sign-in runs on Symfony's security system. In `config/packages/security.yaml`:

```yaml
security:
    providers:
        cattophp: { id: CattoPHP\SignIn\SignInUserProvider }
    firewalls:
        main:
            lazy: true
            provider: cattophp
            custom_authenticators: [CattoPHP\SignIn\SignInAuthenticator]
            entry_point: CattoPHP\SignIn\SignInEntryPoint
    access_control:
        - { path: ^/sign-in, roles: PUBLIC_ACCESS }
        - { path: ^/, roles: ROLE_USER }
```

Import the sign-in pages in `config/routes.yaml`:

```yaml
cattophp_sign_in:
    resource: '@CattoPHPBundle/config/routes/sign_in.php'
```

Then make CattoPHP's tables: `bin/console cattophp:migrate` (`--dry-run` lists what it would do first). It keeps
its own tables, whatever the project uses for its own.

### Choosing modules

CattoPHP is one package: the foundations (settings, the activity log, roles and permissions, sign-in, mail, error pages,
security headers, the components) are always there, and each optional module is off until the project switches it on with
`enabled: true` under its name in `config/packages/cattophp.yaml`. A module that is off costs nothing: no services, no
settings, no tasks and no tables. `cattophp:migrate` makes the foundations' tables and those of the modules switched on;
a module switched on later gets its tables at the next migrate, and one switched off keeps its tables and their data.

When the project starts, what the modules switched on need is checked, and every problem is said at once, plainly, with
what to do: a module that keeps records needs `storage.type: database`; site protection needs the scheduler (its rules run
every minute); Telegram and identity verification need `symfony/http-client`, documents `dompdf/dompdf`, payments
`omnipay/common`, and the network lookup `geocoder-php/geoip2-provider`; mailing needs the database and
`symfony/http-client`. `bin/console cattophp:modules` lists every module,
whether it is on, what it needs and whether that is there, and its tables with whether each is made; it also says what a
module would do with another switched on (the Inbox reads answers from a mailbox with the scheduler). The list is
`Module\Modules`, and a project whose own migration tool makes the tables takes `Module\Modules::schemaSteps()` for its
modules (or `Schema\FoundationSteps::all()` for every step).

The front controller and the console start the kernel in the run mode `APP_ENV` gives, which is development unless it
says production: `new Kernel(RunMode::environment(), RunMode::debug())`.

## What it gives

- **Settings** (`Settings\Settings`): every value the Settings page may change, named as in `.env`. `.env` gives the
  starting value, what the Settings page saves overrides it until Reset, a secret is stored encrypted and never shown,
  and every change is in the activity log. A module or the application adds its own by a `Settings\SettingsProvider`.
- **The installation** (`Installation\Installation`): `APP_NAME`, `APP_ORGANISATION`, `APP_URL`, `APP_ADMIN_EMAIL`
  and `APP_TIMEZONE`; every time shown in its time zone (`|local_time` in a template). Templates reach it, demo or
  live and the layout as `cattophp.installation`, `cattophp.site_mode` and `cattophp.layout`.
- **Run mode and site mode** (`Runtime\RunMode`, `Runtime\SiteMode`): `APP_ENV`, development or production, read only
  from `.env`; `APP_SITE_MODE`, demo or live, which a demo site says on every page and in every email.
- **Sign-in** (`SignIn\`): an emailed link that works once and is kept only as a hash; who may sign in
  (`AUTH_SIGNIN`, `AUTH_SIGNIN_ALLOWED_EMAILS`); two-step sign-in with a QR code drawn on the server and recovery
  codes (`AUTH_TWO_STEP`); limits on asking for links (`AUTH_REQUEST_*`); links that work once of any purpose, such as
  a registration before there is an account (`Links\LinkStore`); sign-ins kept in the database, which a person can list
  and end (`Sessions\SessionStore`); how long a link and a sign-in last
  (`AUTH_MAGIC_LINK_TTL`, `AUTH_SESSION_TTL`); `cattophp:sign-in-link` and `cattophp:two-step-reset` for when email or
  a phone is lost. The application says who its accounts are with a `SignIn\SignInAccounts`;
  `SignIn\OwnerOnlyAccounts` is a page with one owner.
- **Roles and permissions** (`Access\`): permissions named `AREA.THING.ACTION`, from `Access\PermissionProvider`s;
  ADMIN holds every one; a voter, so `is_granted('PLATFORM.SETTINGS.VIEW')` works everywhere.
- **The activity log** (`Activity\ActivityLog`): every change, who made it, from where and with the request's
  reference, never a secret; kept as long as the project keeps it (`removeBefore()`).
- **HTTP**: error pages in plain words with a reference for the log; the Content-Security-Policy with a nonce
  (`csp_nonce()`) and the other security headers; the form token (`form_token()`); a refused form back as typed
  (`TypedForm`, `typed_for()`); the bot check on public forms (`BotCheck`); rate limits (`Throttle`).
- **The served page's layout** (`Http\HtmlLayout`): a whole page laid out so its source can be read, every element
  holding a block on lines of its own, one tab a level, whitespace changed only where a browser draws nothing
  differently; authored HTML is served byte for byte (`html_layout.verbatim_classes`, the Twig filter `authored`).
- **The components** (`Ui\Components`): cards, tables, fields, windows, notices, badges, figures, feature-card
  choices, a tree picker, lists with their pager and search, and more, each one template at `@CattoPHP/ui/` with its
  properties bounded to data (words, numbers, a choice from a fixed list; never a class, an attribute or rendered
  markup), so no page invents its own. A page draws one with `ui('feedback.notice', {tone: 'success'})`, or embeds one
  to fill its slots with `ui_template()` and `ui_props()`; `ui_field_attrs()` ties a field's control to its help and
  error. A project adds components of its own with a `Ui\ComponentSet`, and allows its own drawings as a feature
  card's picture with a `Ui\DrawingCheck`. An uploaded SVG is checked and made fit for the page by `Ui\UploadedSvg`. The page frame is at `@CattoPHP/page/` (the
  page head, the identity band and a section's actions), and lists have their page (`Ui\Pagination`), their pager's
  data (`Ui\Pager`) and their table or grid view (`Ui\ListView`). Pages change in place
  (`@CattoPHP/page/in-place-attrs.html.twig`) and live pages refresh themselves with Pause
  (`@CattoPHP/page/live-status.html.twig`, `Http\LiveRefresh`); trees are arranged by `Ui\TreeArrangement`; icons come from
  `Ui\IconLibrary` (Lucide, ISC; `lucide('name')` in a template), given the project's subjects. A project's tests keep the
  rules with `Ui\Contract\TemplateContract` (one owner for each component's markup, an icon on every button, every
  icon in the sprite) and `Ui\Contract\SourceAudit` (scripts and theme stylesheets leave the components to them).
- **Themes** (`Theme\`, turned on by the `theme` settings): a project's look as a theme package (a manifest, a base
  template, its stylesheet, optional wrappers for page families, optional five-colour palettes), checked before it is
  installed (`PackageValidator`), installed side by side by version, activated, previewed and uninstalled
  (`ThemeManager`), a child theme over its one exact parent, the browser files copied into the web root with fingerprinted
  addresses. `Theme\PageRenderer` draws a project's page in its theme and serves it laid out; the project tells it about
  its pages with a `Theme\PageProject` (`theme.pages`), keeps which theme is active with a `Theme\ThemeChoice`
  (`theme.choice`) and may index the installed themes with a `Theme\ThemeRegistry` (`theme.registry`); `bin/console cattophp:themes:install`
  installs the themes a project ships and publishes their browser files, once its code is in place.
- **Payments** (`Payments\`, turned on by the `payments` settings): payment gateways through
  [Omnipay](https://omnipay.thephpleague.com), each one's keys kept as settings (.env's starting values, the Settings
  page over them, secrets encrypted); `PaymentGateways::gateway('name')` gives the Omnipay gateway ready to use. The
  project requires `omnipay/common` and its gateway's package (such as `rrcatto/omnipay-stitch-express`).
- **The scheduler** (`Scheduler\`, turned on by the `scheduler` settings): a project's background work as services that
  implement `Scheduler\ScheduledTask` (a key, a name, what it does, how often or at what time of day, when it counts as
  stopped, and `run()`, which says what it did). `bin/console cattophp:schedule:run` runs what is due: from cron every
  minute, or once a minute with `--watch` under a supervisor; `--job <key>` runs a task now. `command_alias` gives the
  command another name. Each task's last runs are kept, and `Scheduler\TaskStatus` says them in words and whether a
  task has stopped. With `log_archive` and the logs' folder, every log there is archived just after midnight and old
  archives deleted after `LOG_ARCHIVES_KEPT_FOR` (two weeks unless set). A task's place in the list is its tag's
  priority (`#[AsTaggedItem(priority: …)]`).
- **Site protection** (`Protection\`, turned on by the `protection` settings, with the database): the Site Log of every
  request (secrets masked), flagged addresses, time-outs and lockouts (an address, a range or a network owner), the
  lockout gate before anything else, eight rules every minute as the `site_protection` task, a form guard for public
  forms with a refused list of email addresses and domains, and a two-file link to a firewall on the server. The
  `SITE_*` settings say what one address may do. An address's network comes from the network lookup below (or a
  project's own `Network\NetworkLookup`) and, with a project's own sign-in, who is signed in from
  `Protection\VisitorAccounts`; its own pages read the records.
- **The network lookup** (`Network\`, set by the `network` settings): an address's country and place through Geocoder PHP
  (`Network\GeoIp`: its GeoIP2 provider over a MaxMind database, or any Geocoder provider the project names), its network from the GeoLite2 ASN database, and whether the published lists name it a Tor
  exit, a VPN network or a hosting network (`Network\ReputationLists`, fetched into a folder by the `signup_lists` task),
  all of it on the server (`Network\GeoIpNetworkLookup`). Without a database, only private addresses are known.
- **The Inbox** (`Inbox\`, turned on by the `inbox` settings, with the database): a project's contact form keeps each
  message as a conversation (`Inbox\Inbox::keep()`, then `arrived()` for the spam check, the form carrying `stamp()`), which
  goes to the Inbox or to Spam; the owner marks spam and not spam (the word filter learns), blocks senders, uses the Trash
  and drafts, and replies (`Inbox\InboxReplies`, threaded so answers come back into the conversation); with a mailbox set,
  answers are read over IMAP every minute (`inbox_mailbox`). The project's pages read the folders with the people they
  name, and say what a message is about.
- **Mailing** (`Mailing\`, turned on by the `mailing` settings, with the database and symfony/http-client), its first part:
  where news is sent from and through (Settings > Mailing: the sender, the address of a copy's links, a mail server or
  catto-mail; `MAILING_*` and `CATTOMAIL_*` in .env), the two transports (`Mailing\MailingTransports::current()`: a mail
  server, each copy carrying List-Unsubscribe, one-click List-Unsubscribe-Post, List-Id and Feedback-ID; or catto-mail, one
  send job per list with each copy's reference, every request idempotent), and catto-mail's side: the connection
  (`Mailing\CattoMail\CattoMailConnection`: Connect by catto-mail's approval page with PKCE, the sending domain asked
  about until it can send, the check, Disconnect, Forget, a test email; news waits while the domain cannot send or the
  quota is used up), and the webhook (`CattoMailWebhook::answer()` for the project's route: signed, each event kept once
  and given to the project's `CattoMail\CattoMailEventHandler` services by type). Lists and consent: a list for each
  subject a project offers to follow (a kind and a subject, made at the first yes) and lists an administrator makes (kind
  `own`: a name, a join link, open or closed, its own sender); each person's answer with where it was given and every
  change in its history (`Mailing\Consents`, `Subscriptions`), and the addresses never emailed (`Mailing\Suppressions`).
  What a subject is called is the project's. Campaigns and each person's copy (`Mailing\Campaigns`, `Copies`, `Links`),
  sent a little each run by `Mailing\CampaignSender` within the sending hours, an hourly limit and a gentle first day that
  doubles, pausing itself above the bounce or complaint rates, skipping an address never emailed or a person who stopped
  every list that brought it, and reading back what catto-mail did with each copy (`CopyOutcomes`: a hard bounce or a
  complaint never emailed again, a complaint stopping every list). The project gives the sender its side through
  `mailing.campaign_project` (`Mailing\CampaignProject`): who a campaign goes to when it starts, who it comes from, how a
  copy is drawn (`DrawnCopy`, in the project's own design) and how a forwarded copy is drawn for the friend, whether a
  campaign waits for more, and its `SendingRules`. The links in a copy (`Mailing\CopyLinks`: the opening picture and
  tracked links recorded as the rules say, a copy that was sent found for its web copy, whether a copy has stopped its
  list) and Forward to a friend (`Mailing\Forwards`: five friends a copy, ten a day, the friend's copy through the site's
  own mail, "Never email me") serve the project's own routes. `Mailing\MailingFigures` gives the figures of an overview and
  reports: a period's copies and what became of them, by kind and by campaign, forwards, people who joined or left a list,
  the most clicked links, copies per hour, day, week or month, subscribers, bounces, the lists most joined.
- **Countries and the South Africa pack** (`Country\`, `SouthAfrica\`, always there, no switch, no tables): every country by
  its ISO code with its British English name, a code checked as a form sends it, and every country for a drop-down A to Z
  with a project's own first (`Country\Countries::options('ZA')`); a South African ID number checked as well formed (13
  digits, a real date of birth, citizen or permanent resident, the checksum), masked for pages and fingerprinted with the
  project's key (`SouthAfrica\IdNumber`); the nine provinces (`SouthAfrica\Provinces`). How old someone must be is the
  project's rule.
- **The sign-up check** (`Signup\`, turned on by the `signup` settings, with the database): a registration form judged when
  it is sent (`Signup\SignupChecks::check()`, the form carrying `stamp()` and a hidden field), scored with each signal in
  words, a throwaway address refused, every attempt recorded with its outcome (`Signup\SignupAttempts`), and the allowed
  and blocked countries as settings. What a hold means is the project's.
- **Identity verification** (`Identity\`, turned on by the `identity` settings, with the database and symfony/http-client):
  a check on Didit's hosted page, its keys in .env (`DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`), its
  result by webhook or when the person returns (`Identity\IdentityVerification`), three declined tries before an
  administrator allows another, revocation with a reason, and a live site never taking a result from Didit's sandbox. The
  project's routes start a check, take the person back and receive the webhook; only the status, the verified name, the
  document's type, number and country and the date of birth are kept, never an image.
- **Telegram** (`Telegram\`, turned on by the `telegram` settings, with the database and symfony/http-client): a bot
  connected at a settings page, messages to the owner queued where something happens and sent within a minute,
  messages to people (such as a sign-in code) sent at once, a Telegram log that never keeps a secret, people's Telegram
  accounts connected by the bot's /start, and what people send the bot read without a webhook. The project says what a
  message to the owner says and when (`Telegram\OwnerMessages::queue()`).
- **An API** (`Api\`, turned on by the `api` settings, with the database): tokens issued to accounts with the project's
  scopes and kept only as their SHA-256, a Bearer authenticator for the project's API firewall (`Api\ApiTokenAuthenticator`,
  its `security.yaml` naming it), a scope voter beside the permission voter (`#[IsGranted('notes:read')]` and
  `#[IsGranted('NOTE.VIEW')]` on a route), and one JSON error document. `Webhooks\WebhookSignature` signs a webhook and
  checks one.
- **Documents and PDFs** (`Document\`, turned on by the `documents` settings, with dompdf/dompdf): templates in a
  controlled language (`{{ group.name }}`, `{{#each}}`, `{{#if}}…{{else}}`) checked against the placeholders a document
  type allows, with nothing executable or fetched; each value written by its kind and escaped; one renderer for previews,
  documents and PDFs (`Document\DocumentRenderer`); and PDFs through Dompdf with the project's own fonts
  (`Document\PdfRenderer`). A project keeps its document types, their placeholders and its templates.
- **Search engines and AI** (`Search\`, turned on by the `search` settings, with the database): `robots.txt` with the
  project's private paths and a group for every AI crawler as a setting chooses (all in, AI search in with training kept
  out, or none), the sitemap from the project's sources (`Search\SitemapSource`, an index past 50,000 addresses, none on
  a demo site), `/llms.txt` from the project's words (`Search\LlmsTxtContent`), share pictures drawn from SVG at 1200 by
  630 by rsvg-convert, schema.org structured data any site has (the business, the site, a FAQ, the trail, a list) with
  the rules every node keeps, and what every public page's head needs: its description, canonical address, Open Graph
  and X's card, a search's results kept out of the index, and an administrator's own title and description for each of
  the project's public pages (`Search\PublicPageProvider`). The Google and Bing verification codes are settings too. The
  project's routes serve them (`Search\Sitemap`, `RobotsTxt`, `LlmsTxt`, `SharePictures`).
- **The setup checklist** (`Setup\`, turned on by the `setup` settings, with the database): every step a new
  installation needs, in order, from the project's services that implement `Setup\SetupStepProvider`. A step
  (`Setup\SetupStep`) is checked automatically (the site finds it done or not) or needs you (a person marks it done, or
  skips it for now), or both. `Setup\SetupChecklist` gives the steps with whether each is done, the progress and the
  next step, and keeps people's marks (`cattophp_setup_steps`) in the activity log too. What a step says it found is
  shown with its secrets hidden.
- **Mail** (`Mail\Mailer`): through the configured transport (`MAILER_DSN` and the `MAIL_*` sender settings), a demo
  site's emails marked; `cattophp:test-email`.
- **Building blocks**: lengths of time (`Foundation\Duration`: `30m`, `1d`, `1h30m`), account references with a check
  character (`Foundation\AccountReference`, `|account_reference`), Base32, the secret cipher and keyed hashes
  (`Security\`), and `Security\Redactor`, which hides the secrets in values and text (a password in an address, a
  secret given by name, a bearer token, a private key), as the activity log does with every detail.

Its pages (`templates/`) are plain; a project draws them in its own look by putting its own at
`templates/bundles/CattoPHPBundle/` (`error.html.twig`, `sign_in/*.html.twig`).

## Developing it

```sh
composer install
composer check    # every test (any warning or deprecation fails) and the static analysis at level 8
```

The database tests need a PostgreSQL database kept for them alone, named by `CATTOPHP_TEST_DATABASE_URL`: they
remove everything in its public schema. A throwaway one with Podman:

```sh
podman pod create --name cattophp-test --userns=keep-id
podman run -d --pod cattophp-test --name cattophp-test-postgres --user "$(id -u):$(id -g)" \
    -e POSTGRES_USER=cattophp -e POSTGRES_PASSWORD=cattophp-test -e POSTGRES_DB=cattophp_test -e PGDATA=/tmp/pgdata \
    docker.io/library/postgres:18.6-trixie
podman run --rm --pod cattophp-test -v "$PWD":/app -w /app \
    -e CATTOPHP_TEST_DATABASE_URL=pdo-pgsql://cattophp:cattophp-test@127.0.0.1:5432/cattophp_test \
    <a PHP 8.5.12 image with pdo_pgsql> composer check
podman pod rm -f cattophp-test
```

`composer test:unit` runs the tests that need no database.

## Licence

MIT: see [LICENSE](LICENSE). The QR code library, bacon/bacon-qr-code, is BSD-2-Clause; Symfony, Twig and Doctrine
DBAL are MIT.
