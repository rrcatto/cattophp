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
"require": {"rrcatto/cattophp": "^1.0"}
```

What a project installs is the package alone (`bin/`, `config/`, `public/`, `resources/`, `src/`, `templates/`, the
guide, the README, the changelog and the licence): `.gitattributes` leaves the rest out of an archive of it, which
GitHub's zips and a Composer path repository's mirror follow.

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
    layout: base.html.twig                      # the template CattoPHP's pages extend without themes (block body, or page_body)
    starting_values: {}                         # values in place of .env's, by name: APP_NAME: Example
    defaults: { APP_LOCALE: en_ZA }             # the project's own defaults, used when .env and the Settings page give nothing
    sign_in:
        accounts: App\Security\Accounts         # the application's accounts (CattoPHP\SignIn\SignInAccounts, and JoiningAccounts where people join by signing in)
        after: /                                # where a person goes after signing in
        recovery_codes: true
        two_step_file: ~                        # with files: the two-step key's file, {account} for one per account (one file holds one person's)
        sessions: false                         # true keeps each sign-in for the person to see and end; off where the project keeps its own
    security_headers:
        policy: {}                              # Content-Security-Policy directives replaced or added
        referrer_policy: strict-origin-when-cross-origin
        permissions_policy: 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'  # what a page may use, such as microphone=(self)
        no_index: false                         # true asks search engines to keep away, demo or live
        no_store: false                         # true asks browsers to keep no copy of any page
    error_pages:
        skip_paths: [/api/]                     # paths whose errors the project answers itself
        drawer: CattoPHP\Http\TwigErrorPageDrawer # the service that draws an error page in the project's look (CattoPHP\Http\ErrorPageDrawer)
    html_layout:
        verbatim_classes: []                    # classes of the elements holding authored HTML, served byte for byte
        flex_classes: []                        # the project's own classes its CSS lays out as flex or grid
    ui:
        icon_sprite: /img/icons.svg             # the icon sprite whose symbols the icon component draws
        public_root: '%kernel.project_dir%/public'  # where the sprite is found for its fingerprint
        icon_subjects: { garden: [flower, sprout] }  # the subjects the project's names are about, each with its best icons, optional
        icon_name_stop_words: []                # more words of the project's names that say nothing about a picture
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
        log_archive: { enabled: true, folder: '%kernel.project_dir%/var/log', first: application.log }
    network:                                    # what an address says about a visitor (needs geocoder-php/geoip2-provider)
        country_database: '/var/lib/GeoIP/GeoLite2-City.mmdb'  # its country and place through Geocoder; GeoLite2-ASN.mmdb beside it for its network
        asn_database: ~                         # the ASN database's full path, when it is not beside the country database
        geocoder: App\Geo\Provider              # another Geocoder provider (Geocoder\Provider\Provider), such as a chain, optional
        lookup: ~                               # a project's own lookup in place of CattoPHP's (CattoPHP\Network\NetworkLookup), optional
        lists_folder: '%kernel.project_dir%/var/network-lists'  # the Tor, VPN, hosting and throwaway email lists, optional
        http_client: http_client                # the HTTP client's service id
    protection:                                 # only for a public site (needs the database)
        enabled: true
        secret_paths: ['/join/']                # paths whose next part is always a secret, masked in the Site Log
        firewall_folder: '%kernel.project_dir%/var/server-firewall'
        visitors: ~                             # who is signed in, for a project with its own sign-in (CattoPHP\Protection\VisitorAccounts)
        administrator_permission: PLATFORM.SETTINGS.VIEW  # with the default visitors: the permission that makes someone an administrator
        gate_listener: true                     # false for a project that calls the lockout gate itself, before its own session
        record_requests: true                   # write each request into the Site Log
        forms: { sign_in: Sign in, register: Register, contact: Contact }  # each guarded form's name and words
        list_only_forms: [register]             # the forms only the refused list refuses
        task_priority: 0                        # the site_protection task's place among the scheduled tasks
    inbox:                                      # only for a site with a contact form (needs the database)
        enabled: true
        reference_prefix: MSG-                  # what a conversation's reference starts with
        files_folder: '%kernel.project_dir%/var' # replies' files, each under inbox/<its public id>/
        documents: App\Inbox\Invoices            # an account's documents a reply may attach (CattoPHP\Inbox\InboxDocuments), optional
        sender: ~                               # the service that sends a reply (CattoPHP\Inbox\ReplySender); CattoPHP's mailer by default
        task_priority: 0                        # the inbox_mailbox task's place among the scheduled tasks
    mailing:                                    # only for a site that sends news (needs the database and symfony/http-client)
        enabled: true
        project: example                        # in Feedback-ID headers and catto-mail's job references
        callback_path: /mailing/cattomail/callback  # where catto-mail's connect page returns to
        webhook_path: /webhooks/cattomail       # where catto-mail sends its events
        campaign_project: App\Mail\Campaigns     # who a campaign goes to and how a copy is drawn (CattoPHP\Mailing\CampaignProject); without it campaigns are kept, not sent
        recipient_addresses: App\Mail\TestInboxes # where a mail server delivers made-up addresses (CattoPHP\Mailing\RecipientAddresses), optional
        http_client: http_client
    signup:                                     # only for a site where people register (needs the database)
        enabled: true
        task_priority: 0                        # the signup_lists task's place among the scheduled tasks
    identity:                                   # only for a site that must know who people are (needs the database and symfony/http-client)
        enabled: true
        return_path: /account/identity/return   # the project's route a person comes back to from Didit's page
        webhook_path: /webhooks/didit           # the project's route Didit's webhooks arrive at
        accounts: App\Identity\References       # what Didit is told of an account (CattoPHP\Identity\IdentityAccounts), optional
        approved: App\Identity\WhenApproved     # what happens when one is approved (CattoPHP\Identity\IdentityApproved), optional
        account_detail: account                 # the name an account goes by in the activity log's details
        minimum_age: 18                         # an approval is passed on only from this age, optional
        http_client: http_client
    telegram:                                   # only for a project with a Telegram bot (needs the database and symfony/http-client)
        enabled: true
        choices: [on_sale]                      # the project's own choices of what sends the owner a message
        task_priority: 0                        # the owner_messages task's place among the scheduled tasks
        http_client: http_client
    api:                                        # only for a project with an API (needs the database)
        enabled: true
        path: /api/                             # where the API is: its exceptions answered in its error document
        token_prefix: app_                      # what a token starts with
        scopes: { 'notes:read': 'Read notes.' } # each scope the project offers and what it allows
        all_scopes: ~                           # the scope that stands for every scope, if any
        accounts: App\Api\Accounts              # whether an account may use the API (CattoPHP\Api\ApiAccounts), optional
    documents:                                  # only for a project that draws documents and PDFs (needs dompdf/dompdf)
        enabled: true
        fonts: '%kernel.project_dir%/resources/fonts'
        families: { 'Plex Sans': PlexSans }     # each family in four faces: PlexSans-Regular.ttf, -Bold, -Italic, -BoldItalic
        font_cache: '%kernel.project_dir%/var/dompdf-fonts'  # where Dompdf keeps its font metrics
        images: App\Document\StoredPictures      # stored pictures named asset:<sha256> (CattoPHP\Document\DocumentImages), optional
    search:                                     # only for a site with public pages (needs the database)
        enabled: true
        private_paths:                          # the paths robots.txt asks crawlers to leave alone, each group with its reason
            - { why: 'Signed-in pages.', paths: [/account/, /admin/] }
        share_pictures: '%kernel.project_dir%/var/share-pictures'
        rsvg_convert: ~                         # rsvg-convert's full path; looked for in the usual places when not given
        llms_txt: App\Search\SiteSummary        # what /llms.txt says (CattoPHP\Search\LlmsTxtContent), optional
        site_picture: App\Search\SitePicture    # the picture a page without its own is shared with, optional
        cache: cache.app                        # the cache a rendered sitemap is kept in for an hour
    setup:                                      # only for a project with a setup checklist (needs the database)
        enabled: true
    theme:                                      # only for a project with themes
        default: example                        # the slug of the bundled theme a site starts on
        package_format: cattophp-theme          # the package format's name, as a theme's theme.json says it
        bundled: '%kernel.project_dir%/themes'  # the project's own themes, in its code
        installed: '%kernel.project_dir%/var/themes'
        staging: '%kernel.project_dir%/var/theme-imports'
        public_root: '%kernel.project_dir%/public'  # each theme's browser files go into its themes/ folder
        page_families: [admin]                  # the families a theme may wrap with pages/<family>.html.twig
        choice: App\Theme\StoredChoice          # where the active theme is kept (CattoPHP\Theme\ThemeChoice)
        registry: ~                             # an optional index of the installed themes (CattoPHP\Theme\ThemeRegistry)
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

Import the sign-in pages in `config/routes.yaml`, and, where the project wants them, Administration's Settings, Roles and
Activity log and a person's Sign-in and security:

```yaml
cattophp_sign_in:
    resource: '@CattoPHPBundle/config/routes/sign_in.php'
cattophp_admin:                                 # /admin/settings, /admin/roles, /admin/activity
    resource: '@CattoPHPBundle/config/routes/admin.php'
cattophp_account:                               # /account/security
    resource: '@CattoPHPBundle/config/routes/account.php'
```

Then make the tables: `bin/console cattophp:migrate` (`--dry-run` lists what it would do first). It makes CattoPHP's
tables and the project's own where the project writes them as schema steps (a class implementing `Schema\SchemaStep`,
found by autoconfiguration and applied in the order of the ids with CattoPHP's); a project may keep its own tables with
any other tool instead.

A project extends CattoPHP by implementing its interfaces (`Settings\SettingsProvider`, `Access\PermissionProvider`,
`Schema\SchemaStep`, `Scheduler\ScheduledTask`, `Activity\ActionWords`, `DetailWords` and `SubjectLinks`,
`Account\ForgetsAccounts`, `Ui\ComponentSet` and the rest): autoconfiguration gives each service the tag of every
interface it implements, so one class may implement several. The tags are constants of `CattoPHP\ServiceTags`, never of
the interfaces (`ServiceTags::EXTENSION_POINTS` lists each interface with its tag); a service wired by hand names its tag
from there, `->tag(ServiceTags::SCHEDULED_TASK, ['priority' => 5])`, or by its name in YAML (`cattophp.scheduled_task`).

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
says production: `new Kernel(RunMode::environment(), RunMode::debug())`. `RunMode` reads the process's environment, so a
value the server or the container sets wins over `.env`, which loading `.env` never overrides.

## What it gives

- **Settings** (`Settings\Settings`): every value the Settings page may change, named as in `.env`. `.env` gives the
  starting value, what the Settings page saves overrides it until Reset, a secret is stored encrypted and never shown,
  and every change is in the activity log. A module or the application adds its own by a `Settings\SettingsProvider`.
  Administration's Settings page (`Admin\SettingsController`, `config/routes/admin.php`) shows every group, each setting
  with where its value comes from in words and its Reset (a form of its own, confirmed first, so Enter in a field only
  ever saves), a saved secret as asterisks (left as they are, it is kept, even one that can no longer be read). A choice
  or a time zone `.env` writes in another case is the one it names (`AUTH_TWO_STEP=everyone` is `EVERYONE`), and a value
  in force that is no option (an older time zone name, or something unknown) is shown selected as it stands, so saving
  its group for another reason never puts another value in its place.
- **The installation** (`Installation\Installation`): `APP_NAME`, `APP_ORGANISATION`, `APP_URL`, `APP_ADMIN_EMAIL`,
  `APP_TIMEZONE` and `APP_LOCALE` (`locale()`: how amounts are written and the pages' language, `en_GB` unless it says
  otherwise; a project gives its own under `defaults`; `language()` is it as HTML's `lang` names it, `en_ZA` as `en-ZA`,
  which the plain frame and a theme's base put on `<html>`), each a setting; every time shown in its time zone
  (`|local_time` in a template). PHP's own time zone is set to the installation's as every request and every console
  command starts, and before each run of the scheduler (`Installation\PhpTimeZone`), so a task's time of day, a
  document's dates and the mailing's sending hours are the installation's. Every name PHP knows counts, in any case, the
  older ones too (`Asia/Calcutta`, `Etc/UTC`, `US/Eastern`; `Installation::timeZoneName()`); a time zone PHP does not
  know leaves PHP's as it was (`Installation::chosenTimeZone()` is then null, and `timeZone()` UTC). Templates reach it, demo or live and the layout as `cattophp.installation`,
  `cattophp.site_mode` and `cattophp.layout`.
- **Run mode and site mode** (`Runtime\RunMode`, `Runtime\SiteMode`): `APP_ENV`, development or production, read from
  the process's environment (the server's or the container's value over `.env`'s) and never from the Settings page;
  `APP_SITE_MODE`, demo or live, which a demo site says on every page and in every email.
- **Sign-in** (`SignIn\`): an emailed link that works once and is kept only as a hash; who may sign in
  (`AUTH_SIGNIN`, `AUTH_SIGNIN_ALLOWED_EMAILS`); two-step sign-in with a QR code drawn on the server and recovery
  codes (`AUTH_TWO_STEP`); limits on asking for links (`AUTH_REQUEST_*`); links that work once of any purpose, such as
  a registration before there is an account (`Links\LinkStore`); the store of sign-ins (`Sessions\SessionStore`), which a
  project with its own sign-in pages keeps its sign-ins in, and in which CattoPHP's sign-in keeps each one where the
  project switches it on (`sign_in.sessions: true`, `SignIn\SignInSessions`): checked on every request, so one ended or
  run out signs that browser out, and listed on the person's Sign-in and security page with End and End all others; how
  long a link and a sign-in last (`AUTH_MAGIC_LINK_TTL`, `AUTH_SESSION_TTL`); `cattophp:sign-in-link` and
  `cattophp:two-step-reset` for when email or a phone is lost. The application says who its accounts are with a
  `SignIn\SignInAccounts`; `SignIn\OwnerOnlyAccounts` is a page with one owner. Where people join by signing in for the
  first time, the accounts service implements `SignIn\JoiningAccounts` as well: an address with no account that may join
  (`SignInRules::mayJoin`: `APP_ADMIN_EMAIL` always, anyone with `AUTH_SIGNIN=EVERYONE`, else the listed addresses) is
  sent a link, nothing is kept for it but the link, and `join()` makes the account when the link is used; every address
  sees the same page, and the link is asked for once the answer has gone (`Http\AfterResponse`, on `kernel.terminate`),
  so how long the page takes tells nothing either. The installation's administrator's address may always sign in, as
  it may always join, whether or not the project flags its account. A person's Sign-in and security page (`Account\SecurityController`, `config/routes/account.php`,
  `/account/security`): two-step sign-in by their own choice wherever it is not required of them (set it up with the QR
  code, turn it off with a current code or a recovery code, new recovery codes with a current code; each wrong code in
  the activity log, `two_step.wrong_code`, and the fifth in 15 minutes ends that sign-in, the sign-in page saying why)
  and, with `sign_in.sessions`, where they are signed in. The element holding the two-step key's text carries `data-two-step-key`
  on the sign-in code page and the account page alike, so a project's checks find it in any theme.
- **Roles and permissions** (`Access\`): permissions named in capitals with two to four parts, such as
  `AREA.THING.ACTION`, from `Access\PermissionProvider`s; one starting `SYSTEM.` is for administrators alone, never a
  role's; ADMIN holds every one; a voter, so `is_granted('PLATFORM.SETTINGS.VIEW')` works everywhere. Administration's
  Roles page (`Admin\RolesController`): every role, what it allows as feature cards to tick, who holds it, a role given
  by an email address and taken away, new roles and deleted ones.
- **The activity log** (`Activity\ActivityLog`): every change, who made it, from where and with the request's
  reference, never a secret; read a page at a time (`recent(..., offset:)`, `count()`) with the actions it holds
  (`actions()`); kept as long as the project keeps it (`removeBefore()`). Administration's Activity log page
  (`Admin\ActivityController`) lists it newest first, a page at a time, filtered by any action it holds, refreshing
  itself every 15 seconds; each action in words from `Activity\ActionWords` services (CattoPHP's own, and a project's
  for its actions, found by autoconfiguration), and each detail in words by `Activity\ActivityDetails`: a key turned
  into words (`needs_repair` is "Needs repair"), an ISO 8601 date or time in the installation's time zone and in words
  ("7 November 2026 at 07:01"), yes or no, a list on one line, a project's subject by its type in words and its
  reference ("Loan sd4jr2uh"). A project words its own details with an `Activity\DetailWords` service (found by
  autoconfiguration): `label(string $action, string $key): ?string` and `value(string $action, string $key, mixed
  $value): ?string`, each null for the log's own wording. What an entry is about links to the record where an
  `Activity\SubjectLinks` service of the project's (found by autoconfiguration) gives its address:
  `href(string $type, string $id): ?string`, such as `/tools/k7qxm2pa` for `('tool', 'k7qxm2pa')`, or null for none;
  only a path on the site or an `http`/`https` address is linked, escaped, and the first service with one gives it. On
  a phone the log's rows are cards.
- **Forgetting an account** (`Account\AccountRecords`): the one call a project makes when it deletes an account,
  `forget($account, $by)`, inside the transaction that deletes its own row and before that row goes (a transaction
  already open is joined). What is the account's alone goes: its roles, two-step key and recovery codes, links (the
  joining link made for its address among them), sign-ins, and each module's (a Telegram connection, API tokens,
  sign-up attempts, identity checks, mailing answers and forwards). What stays stops naming it: the activity log keeps
  every entry, by "A deleted account" where it did something and about nobody where it was the subject, and anything in
  what an entry says that names it (its id, its address and name, a Telegram username, an identity check's session, at
  any depth and inside a longer text) is said so too, those entries keeping no network address; an address a module must
  keep (a mailing copy, an Inbox message) becomes `deleted-account@forgotten.invalid` (`AccountRecords::FORGOTTEN_EMAIL`),
  a name or username is left empty, a network address goes, and a copy's links stop opening it; whatever records who
  saved, decided, wrote or made something (settings, setup marks, lockouts, the Inbox's replies, campaigns) no longer
  says who. A project's own records go the same way through a service implementing `Account\ForgetsAccounts`, which
  may give `words` that also name the account (an earlier address) for the log to forget. One transaction with the
  database; without one each file is written whole or not at all, the two-step key last, and `forget()` run again
  carries on. It refuses the last administrator and an administrator's own account at their own hand, as taking the
  role away refuses, counting administrators by the role and by the project's own flag (`SignInAccount::administrator`)
  among the role's holders it still finds and the installation's administrator's account, with the holders locked so two
  at once leave one, unless told `lastAdministrator: true` for an account made only to be removed again (a check's own
  administrator on a database that had none). It returns how many records of each kind went and stopped naming the
  account.
- **HTTP**: error pages in plain words with a reference for the log; the Content-Security-Policy with a nonce
  (`csp_nonce()`) and the other security headers; the form token (`form_token()`); a refused form back as typed
  (`TypedForm`, `typed_for()`), never a secret (a field named as `Security\Redactor` names one, a token, an API key or
  a DSN among them, is never kept), the controller naming any secret field its name does not give away
  (`TypedForm::keep($request, ['STITCH_HOOK'])`), with why each field was refused beside it
  (`TypedForm::keep($request, errors: ['name' => 'A name needs at least one letter.'])`, read back by the field's name
  with `TypedForm::errors($request, $action)` or `typed_errors_for(path('…'))`, given to `form.field`'s `error`); a
  form refused for several fields at once, `Http\FieldsRefused` (an `InvalidArgumentException` a service throws with
  why for each field by its name, `new FieldsRefused(['name' => '…', 'category' => '…'])`; `errors()`, `error($name)`),
  which `TypedForm::keep($request, errors: $refused)` and `StockPages::refused()` take as they are; the bot check on
  public forms (`BotCheck`); rate limits (`Throttle`).
- **The served page's layout** (`Http\HtmlLayout`): a whole page laid out so its source can be read, every element
  holding a block on lines of its own, one tab a level, whitespace changed only where a browser draws nothing
  differently; authored HTML is served byte for byte (`html_layout.verbatim_classes`, the Twig filter `authored`).
- **The components** (`Ui\Components`): cards, tables, fields, windows, notices, badges, figures, feature-card
  choices, a tree picker, lists with their pager and search, and more, each one template at `@CattoPHP/ui/` with its
  properties bounded to data (words, numbers, a choice from a fixed list; never a class, an attribute or rendered
  markup), so no page invents its own. A page draws one with `ui('feedback.notice', {tone: 'success'})`, or embeds one
  to fill its slots with `ui_template()` and `ui_props()`; `ui_field_attrs()` ties a field's control to its help and
  error (`form.field`'s `error` is drawn under the field, the control `aria-invalid` and `aria-describedby` it). A
  list's search box (`data.dataset`'s `search`) looks like those fields wherever the theme paints neither, by a rule
  inside `:where()` that any theme's or project's own rule for it outranks. A
  table of more columns than a phone holds is drawn with `stack: true` (`data.table`), each cell naming its column in
  `data-label`: below 768 pixels each row is a card of label and value, by the stylesheet alone, the markup still the
  table a screen reader reads; `fit: false` lets a table keep its widths and scroll sideways instead. An item's keys
  (a feature card's, a tab's, a tree option's) may come in any order, and a refusal names the key that is wrong. A
  feature-card choice, `form.picture-choice`, takes `name` (the radios' field), `legend` (required), `items` (required:
  each `value`, `label`, one of `image` (a picture on the site), `icon` (an SVG) or `drawing` (a project's drawing),
  then `note` and `disabled` if wanted), `selected` (a value, or a list with `multiple`), `help`, `error` (why it was
  refused: drawn as `form.field`'s under the legend and help, the fieldset `aria-describedby` them and every control
  `aria-invalid`; ids `<id>-help` and `<id>-error`, else from the field's name), `id`, `size` (`normal` or `small`),
  `columns` (`auto`, `1` or `4`), `form` (a form elsewhere on the page), `multiple` (each card a checkbox named by its
  value), `list` (with `multiple`, every card one field, `list[]`), `tick_value` (what a named card sends, `1` unless
  given), `required` and `legend_hidden`; each component's full list is `Ui\Components::COMPONENTS`. A project adds
  components of its own with a `Ui\ComponentSet`, and allows its own drawings as a feature card's picture with a
  `Ui\DrawingCheck`. An uploaded SVG is checked and made fit for the page by `Ui\UploadedSvg`. The page frame is at `@CattoPHP/page/` (the
  page head, the identity band and a section's actions), and lists have their page (`Ui\Pagination`), their pager's
  data (`Ui\Pager`) and their table or grid view (`Ui\ListView`). Pages change in place
  (`@CattoPHP/page/in-place-attrs.html.twig`) and live pages refresh themselves with Pause
  (`@CattoPHP/page/live-status.html.twig`, `Http\LiveRefresh`); trees are arranged by `Ui\TreeArrangement`; icons come from
  `Ui\IconLibrary` (Lucide, ISC; `lucide('name')` in a template), given the project's subjects, and
  `bin/console cattophp:icons:search <words>` lists the icons that match, by name and by Lucide's tags, each with its
  tags, so a name is found before a template or a sprite names it. A project's tests keep the
  rules with `Ui\Contract\TemplateContract` (one owner for each component's markup, an icon on every button, every
  icon in the sprite), `Ui\Contract\SourceAudit` (scripts and theme stylesheets leave the components to them) and
  `Ui\Contract\AddressContract` (no row number in an address: `rowNumbers($router->getRouteCollection(), $allowed)`
  refuses a route parameter named `id` or `*_id` or held to digits alone, unless allowed by route name and parameter
  with a reason).
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
  page over them, secrets encrypted); `PaymentGateways::gateway('name')` gives the Omnipay gateway ready to use. A
  gateway is connected only once Check the connection established it (`Payments\GatewayConnections`: the project asks
  its provider, the outcome is kept for the keys it was checked with, Drop connection forgets every key, `.env`'s too).
  The project requires `omnipay/common` and its gateway's package (such as `rrcatto/omnipay-stitch-express`).
- **The scheduler** (`Scheduler\`, turned on by the `scheduler` settings): a project's background work as services that
  implement `Scheduler\ScheduledTask` (a key, a name, what it does, how often or at what time of day, when it counts as
  stopped, and `run()`, which says what it did). `bin/console cattophp:schedule:run` runs what is due: from cron every
  minute, or once a minute with `--watch` under a supervisor; `--job <key>` runs a task now, and with `--now="2026-12-01
  09:00"` (or `"+3 days"`, in the site's time zone) as if it were that moment, to try a time-based task on a development
  site: the task's `run()` is given that moment and the trial is kept apart from the task's runs, at the real time with
  "As at …" before what it said, so its schedule and whether it is overdue never move. It is refused unless the kernel
  is in debug and not in production (nor Symfony's `prod`), whenever `APP_ENV` says production, in the scheduler itself
  (`Scheduler::trialRefused()`), and with `--now` given empty. `command_alias` gives the command another name. Each
  task's last runs are kept, and `Scheduler\TaskStatus` says them in words, its last trial apart (`last_trial`,
  `trial_summary`, `trial_failed`), and whether a task has stopped. Told to stop (SIGTERM, as a container is stopped, or SIGINT), `--watch` finishes the task under way,
  starts no other and ends at once, where PHP can catch signals (pcntl with `pcntl_signal` allowed). With `log_archive`
  and the logs' folder, every log there is archived just after midnight and old
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
- **Mailing** (`Mailing\`, turned on by the `mailing` settings, with the database and symfony/http-client):
  where news is sent from and through (Settings > Mailing: the sender, the address of a copy's links, a mail server or
  catto-mail; `MAILING_*` and `CATTOMAIL_*` in .env), the two transports (`Mailing\MailingTransports::current()`: a mail
  server, each copy carrying List-Unsubscribe, one-click List-Unsubscribe-Post, List-Id and Feedback-ID; or catto-mail, one
  send job per list with each copy's reference, every request idempotent), and catto-mail's side: the connection
  (`Mailing\CattoMail\CattoMailConnection`: Connect by catto-mail's approval page with PKCE, the sending domain asked
  about until it can send, the check, Drop connection, Forget, a test email; news waits while the domain cannot send or the
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
  a check on Didit's hosted page, its keys settings over .env (`DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`)
  with the connection checked, shown and dropped from a settings page (`Identity\IdentityConnection`), its
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
  character (`Foundation\AccountReference`, `|account_reference`), short codes to read out and type back
  (`Foundation\ReadableCode::make(int $length = 8)`: lower-case letters and digits without 0, o, 1, l or i, from
  `random_int`; `read()` takes one back as typed; not unique, so a project keeps a unique index), Base32, the secret cipher and keyed hashes
  (`Security\`), and `Security\Redactor`, which hides the secrets in values and text (a password in an address, a
  secret given by name, a bearer token, a private key), as the activity log does with every detail; a whole number
  under a name that counts (`recovery_codes`, `tokens_left`) is how many there are, and is kept.

Its own pages (`templates/`: signing in, the error page, Administration's Settings, Roles and Activity log, a person's
Sign-in and security) extend `@CattoPHP/page/stock.html.twig`. In a project with themes (`theme.pages`) they wear the
theme: the theme's frame, CattoPHP's page head with the identity band, what the reader's last form did (kept under
CattoPHP's own names in Symfony's session, so never shown twice), then the page's rows, drawn with the components. In
any other they extend `cattophp.layout` and fill its block body. Should the theme fail while drawing the error page, it
is drawn in CattoPHP's plain frame, given to the template as `layout`, which also draws page_body, so a project's own
error template written for its theme still arrives. A project replaces any of them with its own at
`templates/bundles/CattoPHPBundle/` (`error.html.twig`, `sign_in/*.html.twig`, `admin/*.html.twig`, `account/*.html.twig`).
In a project with themes each is drawn as a page of the project's, by its name: the project's `Theme\PageProject` is
given the name (in `family()`, `prepare()` and `model()`) and puts the page in one of its families, which decides the
theme's wrapper (`pages/<family>.html.twig`). The names, in `Http\StockPages::PAGES` too:

- `sign-in`: every step of signing in, `/sign-in`, `/sign-in/sent`, `/sign-in/link/{token}`, `/sign-in/code` (with the QR
  code the first time) and `/sign-in/recovery-codes` (`SignIn\SignInController`, `@CattoPHP/sign_in/*.html.twig`);
- `error`: every error page (`Http\TwigErrorPageDrawer`, `@CattoPHP/error.html.twig`);
- `admin-settings`: Settings, `/admin/settings` (`Admin\SettingsController`, `@CattoPHP/admin/settings.html.twig`);
- `admin-roles`: Roles, `/admin/roles` (`Admin\RolesController`, `@CattoPHP/admin/roles.html.twig`);
- `admin-role-new`: a new role, `/admin/roles/new` (`@CattoPHP/admin/role-new.html.twig`);
- `admin-role`: one role, `/admin/roles/{key}` (`@CattoPHP/admin/role.html.twig`);
- `admin-activity`: the activity log, `/admin/activity` (`Admin\ActivityController`, `@CattoPHP/admin/activity.html.twig`);
- `account-security`: Sign-in and security, `/account/security` (`Account\SecurityController`,
  `@CattoPHP/account/security.html.twig`);
- `account-two-step`: setting two-step sign-in up, `/account/security/two-step` (`@CattoPHP/account/two-step.html.twig`);
- `account-recovery-codes`: new recovery codes, shown once, `/account/security/recovery-codes`
  (`@CattoPHP/account/recovery-codes.html.twig`).

The Administration pages and the account page need Symfony's session and sign-in. These pages draw icons the project's
sprite holds: `action-add`, `action-back`, `action-disable`, `action-done`, `action-email`, `action-enable`,
`action-filter`, `action-generate`, `action-grant`, `action-open`, `action-remove`, `action-reset`, `action-revoke` and
`action-save` (and the pager's and the live refresh's, as every list has).

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
