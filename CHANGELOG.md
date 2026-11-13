# Changelog

What each version of CattoPHP brought, newest first.

## 1.0.0 · 13 November 2026

### Upgrading from 0.3

What a project on 0.3.0 does or needs to know. Run `composer update rrcatto/cattophp`, then `bin/console cattophp:migrate`
(`--dry-run` lists what it would apply).

**Breaking changes**

- The extension interfaces no longer carry a `TAG` constant (`SettingsProvider`, `PermissionProvider`, `SchemaStep`,
  `ComponentSet`, `DrawingCheck`, `ActionWords`, `DetailWords`, `SubjectLinks`, `ForgetsAccounts`, `ScheduledTask`,
  `SetupStepProvider`, `CattoMailEventHandler` and `SitemapSource`), nor `PublicPageProvider` its `PAGES_TAG`: one class
  implementing two of them could not load ("inherits both ... ::TAG ..., which is ambiguous"), and static analysis did
  not say so. Every tag is a constant of `CattoPHP\ServiceTags`, one for each extension point, each with what it finds
  (`ServiceTags::SCHEDULED_TASK`, `SETTINGS`, `PERMISSIONS`, `SCHEMA_STEP`, `COMPONENT_SET`, `DRAWING_CHECK`,
  `ACTION_WORDS`, `DETAIL_WORDS`, `SUBJECT_LINKS`, `FORGETS_ACCOUNTS`, `SETUP_STEPS`, `CATTOMAIL_EVENT_HANDLER`,
  `SITEMAP_SOURCES`, `PUBLIC_PAGES`), and `ServiceTags::EXTENSION_POINTS` maps each interface to its tag. The tags' names
  are unchanged (`cattophp.scheduled_task` and the rest), so a project that tags by the name in YAML changes nothing; one
  that wrote `ScheduledTask::TAG` (or another interface's) in PHP writes `ServiceTags::SCHEDULED_TASK`. A class may now
  implement several extension interfaces, such as a `DetailWords` and a `SubjectLinks` for the same records.
- `Search\PageHead`'s constructor no longer takes `EnvironmentValues` (the third argument): the language comes from
  `Installation::language()`. A project that builds `PageHead` itself drops that argument; with the bundle's own service
  nothing changes.
- Interfaces that gained methods, which a project's own implementation must add:
  - `Activity\ActivityStore`: `count()`, `actions()` and `forgetAccount(string $accountId, string $name, array $words = []): int`,
    and `recent()` takes a last `int $offset = 0`;
  - `Access\RoleStore::forgetAccount(string $accountId): int`, `revokeUnlessLast(string $accountId, string $roleKey): bool` (the
    role taken away unless the account is its last holder, as one locked step) and `lockHolders(string $roleKey): array`
    (the holders, locked until the transaction around the call ends);
  - `Scheduler\RunStore::tried(string $key, DateTimeImmutable $at, bool $ok, string $summary): void` (a trial run, kept apart),
    and each record of `all()` gains `last_trial_at` (?string), `last_trial_ok` (?bool) and `last_trial_summary` (string);
  - `Links\LinkStore::forgetAccount(string $accountId, ?string $email): int`;
  - `Sessions\SessionStore::forgetAccount(string $accountId): int`;
  - `Settings\SettingsStore::forgetAccount(string $accountId): int`;
  - `Setup\SetupMarkStore::forget(string $account): int`;
  - `Security\TwoStep\TwoStepStore::forget(string $accountId): bool` now returns whether there was a key (it returned
    nothing).
- `forget()` returns how many records it changed, where it returned nothing: `Mailing\Campaigns`, `Copies`, `Forwards`,
  `MailingLists`, `Subscriptions` and `Suppressions`. New `forget()` or `forgetAccount()` on `Protection\SiteLog`,
  `Flags`, `Lockouts`, `RefusedEmails`, `Telegram\TelegramAccounts`, `TelegramLog`, `TelegramState`, `Api\ApiTokens`,
  `Signup\SignupAttempts`, `Identity\IdentityChecks`, `Search\PageOverrides` and `Inbox\InboxStore`.
- Renamed: `disconnect()` is `dropConnection()` on `Telegram\TelegramSetup`, `TelegramConnections`, `TelegramAccounts`,
  `Mailing\CattoMail\CattoMailConnection` and `CattoMailClient`. The Telegram bot's saved flag `disconnected` is
  `connection_dropped` (also the key of `TelegramSetup::view()`); a `disconnected` already saved is not read, so a bot
  dropped before shows as not dropped until it is dropped again.
- Renamed activity actions, for a project's own filters, reports or `ActionWords`: `telegram.disconnected` is
  `telegram.connection_dropped` and `mailing.cattomail_disconnected` is `mailing.cattomail_connection_dropped`. Entries
  already in the log keep the old names.
- Constructors that gained arguments: `Document\ValueFormatter` (optional `Installation` third), `Identity\IdentitySettings`
  (optional `Settings` last), `Scheduler\ScheduleRunCommand` (environment and `PhpTimeZone`), `SignIn\SignInController`
  (optional `SignInSessions`, then optional `Http\AfterResponse` last) and `SignIn\SignInUserProvider` (optional
  `SignInSessions`), `Admin\ActivityController` (optional `$links`, the project's `SubjectLinks`, last),
  `Account\AccountRecords` (optional `Installation` last), `Account\Records\MailingRecords` (optional `CattoMailEvents`
  last) and `Scheduler\Scheduler` (`bool $development = false` last: a project that makes the scheduler itself passes true
  only on a development site, or a run as if at another moment is refused). `Scheduler\Scheduler::run()` takes `$stopping` and `$at`;
  `SignIn\SignInLink` takes a `joining` address; `Identity\IdentityConnection` takes `ServiceConnections`. The bundle's own
  services are wired already; only a project that makes these itself changes anything.
- `Identity\IdentitySettings` reads Didit's keys through Settings, which gives `.env`'s value until a different one is
  saved. A project with its own settings group named `identity` renames it.
- The default locale is `en_GB` where it was `en_ZA` (`Installation::LOCALE`, `StructuredData::language()`), and the
  locale is read through Settings, never from the process's environment. A project that wants another gives it under
  `defaults` or in `.env` (`APP_LOCALE=en_ZA`).
- A project's own class implementing `SchemaStep` is now tagged by autoconfiguration and applied by `cattophp:migrate`
  with CattoPHP's. One that is already run by another tool leaves the interface off or has the tool stop running it.
- `Http\TwigErrorPageDrawer` draws `@CattoPHP/error.html.twig` in the theme's frame; a project's own error template
  keeps working, and one written for its theme (filling `page_body`) is still drawn if the theme itself fails.
- `Redactor` keeps a whole number under a name that counts (a plural, `_count` or `_left`) instead of masking it.
- `cattophp.css` no longer holds an application's classes (flash cards and their player, Share this page, the checkout's
  steps and promo code, the question editor's options, a content tree's section names, a category tree's rows), nor the
  rule that forces a table cell's blocks onto one line. A project that used them keeps them in its own stylesheet.
- `Ui\Components` compares an item's keys as a set, in any order, and a refusal names the item and the key. A
  `form.tree-select` option's `icon` is checked as a feature card's is (an SVG picture free of scripts and outside links)
  and anything else refused.
- `cattophp:schedule:run --job … --now …` is a trial, kept apart from the task's runs (`RunStore::tried()`): it no longer
  changes the task's last start or success or how often it ran. It is refused unless the kernel is in debug and its
  environment is neither `production` nor Symfony's `prod`, and whenever `APP_ENV` says production; `--now` given empty
  is refused instead of running the task for real. A project's checks that tried a task on a kernel not in debug boot
  one in debug. `Scheduler\TaskStatus::tasks()` gains `last_trial`, `trial_summary` and `trial_failed`.
- `Settings::get()` gives a choice that `.env` writes in another case as the choice's own key (`AUTH_TWO_STEP=everyone` is
  `EVERYONE`, `APP_SITE_MODE=LIVE` is `live`) and a time zone as PHP lists it (`africa/johannesburg` is
  `Africa/Johannesburg`); `checked()` takes a choice in any case and gives its key, and keeps a value in force that is no
  choice when it is sent back unchanged.
- Administration > Settings: each Reset is a form of its own after the group's form (`form#setting-<name>-reset`,
  `form#settings-<group>-reset`), its button beside its field naming it with `form=` and asking to be confirmed; a
  project's checks that pressed `button[formaction=".../reset/NAME"]` submit that form.
- Sign-in links are asked for once the response has gone (`Http\AfterResponse` on `kernel.terminate`): a project's tests
  through Symfony's KernelBrowser see the email as before; one that handles a request without terminating the kernel
  calls `AfterResponse::run()`.
- `Http\TypedForm` never keeps a field whose name `Security\Redactor` takes for a secret's (a token, an API key, a private
  or signing key, a DSN, a cookie, an authorisation, beside a password, a secret and a code).
- Forgetting an account changes more than before (see the Fixed entries): an address a module keeps becomes
  `AccountRecords::FORGOTTEN_EMAIL`, a person's chat in the Telegram log is left empty, and so on.
- Without a database: one two-step file (`sign_in.two_step_file` without `{account}`) says whose key it holds (`account`,
  a hash of the account's id) from its next change; the file activity log is changed under a lock file of its own,
  `activity.log.lock` beside it.

**New schema steps** (`cattophp:migrate` applies them, only for the modules named):

- `202611130300-scheduled-job-trials` (scheduler): `cattophp_scheduled_jobs` gains `last_trial_at`, `last_trial_ok` and
  `last_trial_summary`, each task's last trial kept apart from its runs.
- `202611130100-identity-connection` (identity): `cattophp_identity_connection`, which `202611130200-connections` then
  moves into `cattophp_connections` as `didit` and drops.
- `202611130200-connections` (identity or payments, once when both are on): `cattophp_connections`.

**New settings** (Settings, with `.env` giving the starting value and Reset going back to it):

- `APP_LOCALE`, "Language and region", default `en_GB`, in the installation group beside `APP_TIMEZONE`.
- `DIDIT_API_KEY` (secret), `DIDIT_WORKFLOW_ID` and `DIDIT_WEBHOOK_SECRET` (secret) are now settings of the group
  `identity`, "Identity verification" (identity module): the secrets are stored encrypted, and saved values override
  `.env`. `Settings::clear()` saves one empty on purpose, so a dropped connection stays dropped.
- `APP_TIMEZONE` now also sets PHP's own time zone (see below).

**New optional configuration**

- `cattophp.sign_in.sessions` (default `false`): keep each sign-in among those a person can see and end. Leave it off
  where the project keeps its sign-ins itself.
- A project's own `SchemaStep`, `ActionWords`, `DetailWords`, `SubjectLinks` and `ForgetsAccounts` services are found by
  autoconfiguration; nothing is to be tagged by hand.
- `cattophp:schedule:run --job <key> --now "<moment>"` (development sites only).
- `cattophp:icons:search <words>` (`--limit`, 30 unless given): the icon library's icons that match the words, by name
  and by Lucide's tags, best first, each with its tags; it exits 1 when none match.

**New routes files** a project may import (each is optional):

- `@CattoPHPBundle/config/routes/admin.php`: Administration's Settings, Roles and Activity log (`/admin/settings`,
  `/admin/roles`, `/admin/activity`, route names `cattophp_admin_*`), for a project with Symfony's session and sign-in.
- `@CattoPHPBundle/config/routes/account.php`: a person's Sign-in and security (`/account/security`, route names
  `cattophp_account_*`), for a project whose people sign in through CattoPHP's pages.

**Packages:** none new, and none dropped. `composer.json` requires what 0.3.0 did (`bacon/bacon-qr-code` BSD-2-Clause,
`doctrine/dbal` MIT, `twig/twig` BSD-3-Clause, `psr/*` MIT and the Symfony 8.1 components MIT) and suggests the same
optional ones for the modules that use them (`dompdf/dompdf` LGPL-2.1, `omnipay/common` MIT, `symfony/http-client` MIT,
`geocoder-php/geoip2-provider` MIT); it gains only `archive.exclude`. The icon library is Lucide 1.72.0 (ISC), with none
removed.

**What a person sees**

- The stock pages (signing in, the error page) extend `@CattoPHP/page/stock.html.twig`, so in a project with themes they
  wear the theme with CattoPHP's components; sign-in pages are a section head, a field and an action each, and a
  notice for a note.
- Sign-in and security (`account.php`) lists where a person is signed in as cards (with `sign_in.sessions`), and a form
  refused shows why beside each field where a page draws them (`typed_errors_for`).
- Times follow the installation's time zone everywhere: PHP's own zone is set from `APP_TIMEZONE` as every request and
  command starts, so scheduled tasks, document dates and sending hours that ran in PHP's default zone now run in it.
- `<html lang>` of the plain frame follows `APP_LOCALE`, and amounts in documents follow it (`en_GB` by default).
- The activity log reads in words and a deleted account shows as "A deleted account". What an entry is about links to
  its record where the project's `Activity\SubjectLinks` gives an address.
- A list's search box (`data.dataset`'s `search`) looks like the form's fields (`.cl-input`) wherever the theme paints
  neither, as in the starter's theme; a theme or project that paints its fields by type, or the box itself, keeps its
  look, since CattoPHP's rule counts for nothing against it.
- A feature-card choice (`form.picture-choice`) refused shows why beside it, as a field does (`error`).
- Administration's New role page and a role's page space their permissions as a form's fields: the choices are inside
  the form's `form.grid` (`columns: 1`), so the first no longer sits against the field above it, and the role's name and
  key fields are one to a row.

**On a project's servers:** run `cattophp:migrate` after updating (needed only with the scheduler, identity or payments
module).
Restart a long-running `cattophp:schedule:run --watch` worker, which now stops on SIGTERM and SIGINT. Nothing else:
no new `.env` keys are required.

- Fixed (review of the code since 0.3.0, 1): the Settings page put another value in place of one whose spelling matched
  no option when its group was saved for any other reason: `.env`'s `AUTH_TWO_STEP=everyone` (two-step required of
  everyone) was saved as CHOICE, `AUTH_SIGNIN=everyone` as ADMIN, `APP_SITE_MODE=LIVE` as demo, and a time zone not in
  the list (`Asia/Calcutta`) as the first, because no option was selected and the browser sends the first. A choice in
  force is selected whatever its case (`Settings` reads `.env`'s choice as the choice's own key, as its readers always
  have), and a value in force that is no option comes first, selected, said to be so ("staging (as .env gives it, not
  one of these)"; an older time zone name "(as .env gives it)"), and is kept when it is sent back unchanged.
- Fixed (2): Enter in any field of a settings group reset a saved setting (an encrypted secret among them) and threw
  away what was typed, since each Reset was the form's first submit button. Each Reset, and Reset every setting here, is
  a form of its own after the group's, its button beside its field naming it with `form=`, and asks to be confirmed
  ("Put Name back to .env's value? What is saved here goes."); the Save form's one submit button is Save.
- Fixed (3): two administrators forgetting each other at once, or taking the Administrator role from each other, could
  both be allowed and leave none, the last-administrator check being made before anything was locked.
  `AccountRecords::forget()` counts with the role's holders locked until its transaction ends (`RoleStore::lockHolders()`)
  and joins a transaction already open, so it belongs inside the project's own deleting transaction, before its row
  goes; `Roles::revoke()` of ADMIN takes it away as one locked step (`RoleStore::revokeUnlessLast()`).
- Fixed (4): forgetting counted administrators by the role alone, though signing in counts the project's flag too
  (`SignInAccount::administrator`, as `SignInRules::isAdministrator()`): a flagged administrator could delete their own
  account and the last of them be forgotten, while a holder of the role was refused as the last beside one. It counts
  both, among the role's holders the project still finds (a role row of an account deleted since counts for nothing) and
  the account of the installation's administrator's address.
- Fixed (5): Sign-in and security took codes without limit, so someone at a browser left signed in could guess the code
  to turn two-step off or make new recovery codes. Each wrong code there is in the activity log (`two_step.wrong_code`,
  with what it was for, "Wrong code typed on Sign-in and security"), counted for the account across its browsers
  (`Http\Throttle`), and the fifth in 15 minutes (`SecurityController::WRONG_CODES_ALLOWED`, `WRONG_CODES_WINDOW`) ends
  that sign-in, as signing out does, the sign-in page saying "Too many wrong codes were typed on Sign-in and security, so
  you were signed out. Sign in again to carry on." (`SecurityController::SIGNED_OUT`). Two-step stays on.
- Fixed (6): activity entries that named a forgotten account only in what they said kept naming it: an identity check's
  (by the system, its id under `account`, Didit's session, a reason), Telegram's (`account_id`, the username), a setting
  someone else saved holding its address in a list, a value in a list or deeper down, and every entry kept its network
  address. Forgetting now changes every entry that names the account anywhere: its id where it is a detail's whole value
  (under a key naming an account, `ActivityEntry::ACCOUNT_KEY`, at any depth, or anywhere for an id that is not a bare
  number), and each word naming it wherever it stands in a detail's words, whatever its case, never inside a longer word
  or address (`ActivityEntry::wordPatterns()`); such entries keep no network address. The words are its address and
  name, and what each module knows it by, given through `ForgetsAccounts` as `words` (Telegram's username with and
  without its @, its identity checks' sessions); a project gives its own the same way (an earlier address). The
  database finds the entries that may name it and changes each as `ActivityEntry::forgetting()` says.
- Fixed (7): what the modules keep kept naming a forgotten account: its mailing copies their address (and their
  personal content, and links that still opened them), its Inbox messages their sender's name, address, account
  reference and network address, the Telegram log the chat and the username of messages sent to its chat or username
  without its account, and catto-mail's events its address. A copy now has the address
  `AccountRecords::FORGOTTEN_EMAIL` (`deleted-account@forgotten.invalid`, in its transport's words too), no content of
  its own and a new token nobody holds; a message to the site from its account or its address has no account or
  reference, an empty name, the forgotten address and no network address or browser, and so do its received messages; a
  Telegram message to its account, chat or username names nobody, a person's chat left empty (null is the owner's own);
  catto-mail's events give the forgotten address. `TelegramLog::forget()` takes the chat and username,
  `InboxStore::forgetAccount()` the address, `CattoMailEvents::forget($email)` is new, and `MailingRecords` takes the
  events.
- Fixed (8): `--now` counted as the task's real run (its last start, success and how often it ran), so a daily task
  tried at 09:00 skipped its real run, an interval task was put back, and `TaskStatus` hid an outage; the changelog said
  the opposite. A trial is kept apart (above, and schema step `202611130300-scheduled-job-trials`).
- Fixed (9): `APP_TIMEZONE` named by an older name PHP still knows (`Asia/Calcutta`, which worked in 0.3.0, `Etc/UTC`,
  `Etc/GMT-2`, `US/Eastern`, `GB`, `Europe/Kiev`) was taken for no time zone, so times were in UTC. Every name PHP knows
  counts, in any case (`Installation::timeZoneName()`), for the installation, PHP's own time zone and the Settings page.
- Fixed (10): `--now`'s guard was the kernel's environment alone, so Symfony's `prod`, or a command wired without it,
  let it run in production, `Scheduler::run()` had none, and `--now=""` ran the task for real. The scheduler itself
  refuses a trial unless the bundle says this is a development site (the kernel in debug, not production or prod) and
  whenever the process's `APP_ENV` says production; `--now` given empty is refused.
- Fixed (11): without a database, rewriting the file activity log truncated it before writing it again, so a write that
  failed (a full disk) lost the whole log, and forgetting an account deleted its two-step key before anything else, so a
  failure part of the way left a half-forgotten account signing in without it. The log is rewritten to a file beside it
  that takes its place only once written whole (`FileActivityStore`, removing old entries too), every change made under
  `activity.log.lock`; the two-step key is forgotten last, and `forget()` run again carries on where it stopped.
- Fixed (12): `IdentitySettings::value()` took any error for "no identity group" and gave `.env`'s Didit key, so a saved
  key that could no longer be read (APP_KEY changed) or a database fault brought back a dropped connection or a replaced
  secret. It falls back to `.env` only where Settings has no such setting; anything else is an error.
- Fixed (13): saving a group with a saved secret that could no longer be read, left blank, was an error page; the group
  could be saved only by typing the secret again. Left blank, it is kept as it is, still said to be unreadable.
- Fixed (14): an account of the installation's administrator's address that the project did not flag could join, use
  its link and then be refused signing in, every link after that too, with sign-in limited to administrators.
  `SignInRules::maySignIn()` lets the administrator's address sign in, as `mayJoin()` lets it join.
- Fixed (15): with one two-step file (`sign_in.two_step_file` without `{account}`), forgetting any account deleted the
  one person's key, and setting one up for another account replaced it. The file says whose key it holds (`account`, a
  hash of the id); another account finds none there, forgetting another leaves it, and setting one up for another is
  refused with how to keep one per account. A file kept before it said whose is the person's, and forgotten for nobody.
- Fixed (16): setting two-step up on Sign-in and security did not check it was still off, so a key left in the session
  could replace the key there was without a current code. It says "Two-step sign-in is already on." instead.
- Fixed (17): a refused form kept a field named like a token, an API key or a DSN to draw it again where the controller
  did not name it a secret; `TypedForm` keeps no field whose name `Security\Redactor` takes for a secret's.
- Fixed (18): a sign-in email was sent while the page waited, only for an address with an account or one that may join,
  so how long the page took told which addresses exist. The link is asked for once the answer has gone, for every
  address alike (`Http\AfterResponse`, run on `kernel.terminate`, the request put back on the stack so the activity log
  still has where it came from; a piece that fails is written to the log and the rest run).
- Fixed: a `form.tree-select` option's `icon`, drawn inline, was not checked (an older gap from 0.3.0): it is refused
  unless it is an SVG picture free of scripts and outside links, naming the option.
- Added: `Http\FieldsRefused`, a form refused for what is wrong with its fields, each with why in plain words by the
  field's name: what a service throws when it checks a whole form and finds one thing or several wrong
  (`throw new FieldsRefused(['name' => 'Type the tool\'s name.', 'category' => 'Choose its category.'])`). An
  `InvalidArgumentException`, not final, so a project may extend it; `errors()` gives the map (`''` for the form as a
  whole, which no field shows), `error($name)` one field's why or null, and its message is every reason in one sentence
  unless one is given as the second argument (`new FieldsRefused($errors, 'See why beside each field.')`); a reason
  with no words is dropped. `TypedForm::keep($request, errors: $refused)` and `TypedForm::kept()` take it as they take
  a map, keeping each field's why for its own field (the `''` one is not kept), and so does `Http\StockPages::refused()`.
  `Settings\SettingsRefused` is one now (it extends it), unchanged otherwise.
- Added: `form.picture-choice`'s `error`, why the choice was refused, drawn as `form.field` draws one
  (`<div class="cl-ui-field-error" role="alert">`) under the legend and the help, above the cards; the fieldset is
  `aria-describedby` the help and the error and every radio or checkbox `aria-invalid="true"`. The ids are `<id>-help`
  and `<id>-error`, or, without `id`, the field's name made an id (`condition-error`, `slide[build]` as
  `slide-build-error`, a list of ticks by its `list`); ticks named by their values (`multiple` without `list`) need an
  `id` for it, and say so. Without an error the markup is byte for byte as before. `.cl-ui-picture-choice>.cl-ui-field-error`
  keeps it half a line from the cards.
- Added: `Activity\SubjectLinks` (tag `cattophp.subject_links`, by autoconfiguration): `href(string $type, string $id):
  ?string`, the address of the record an activity entry is about, or null for none (a type it does not know, a record
  deleted since). The Activity log page links "About" to it, escaped, only when it is a path on the site (one leading
  `/`) or an `http`/`https` address; anything else, and null, leaves it in words as before. It is asked for every
  entry about something, CattoPHP's own subjects (account, setting, role) too, and the first service with an address
  gives it. `Admin\ActivityController` takes the services as an optional last argument, `$links`.
- Fixed: a list's search box (`data.dataset`'s `search`, `.dataset-search input[type=search]`) was drawn with the
  browser's own border beside the form's `.cl-input` fields wherever the theme painted only `.cl-input`, as the
  starter's does. `cattophp.css` gives it `.cl-input`'s border, corners, padding, colours, focus ring and placeholder
  inside `:where()`, so the rule counts for nothing against any other: a theme or project that paints its fields by
  type (`input[type=search]`) or the box itself keeps its look. CattoLMS's search boxes measured the same before and
  after, every computed style and box, at 1440 and 390.
- Changed: an item's keys are compared as a set, in any order: a feature card's (`form.picture-choice`: `value`,
  `label`, one of `image`, `icon` or `drawing`, then `note` and `disabled` if wanted), a tab's (`layout.tabs`: `key`,
  `label`, `href`) and a tree option's (`form.tree-select`: `value`, `label`, `depth`, `path`, then `icon` and `children`
  if wanted). A refusal says which item and which key: "option 2 has colour, which it does not take", "option 1 lacks
  label", "option 1 has image and icon, where it takes one of them". `value, label, note, icon` was refused before.
- Added: `cattophp:schedule:run --job <key> --now "<moment>"` runs the named tasks as if it were that moment, on a
  development site only: a date and time in the site's time zone (`2026-12-01 09:00`; a date alone is its midnight) or a
  moment counted from the scheduler's clock (`+3 days`, `tomorrow 09:00`). The task's `run()` is given that moment, and
  the trial is kept apart from the task's runs (`RunStore::tried()`: when, at the real time, whether it worked, and what
  it said, starting "As at 2026-12-01 09:00: "), which it leaves as they were, so the schedule is not moved and an
  outage is not hidden; `TaskStatus::tasks()` shows it apart (`last_trial`, `trial_summary`, `trial_failed`). Refused,
  each in a plain sentence: anywhere but a development site ("A task is run as if it were another moment only on a
  development site, and this site's APP_ENV is production. Try it on a development site.", `Scheduler::TRIALS_REFUSED`:
  the kernel not in debug, its environment production or Symfony's prod, or `APP_ENV` production, in `Scheduler::run()`
  itself too, `Scheduler::trialRefused()`), without `--job`, with `--watch`, given empty, and a moment that is not one.
  `Scheduler::run()` takes `$at` (named tasks only), `Scheduler::moment()` reads a typed moment, and `Scheduler::zone()`
  is the zone it reads times of day in; `ScheduleRunCommand` is given the kernel's environment, and the bundle tells
  the scheduler whether this is a development site (`$development`: the kernel in debug, not in production).
- Added: PHP's own time zone follows the installation's (`APP_TIMEZONE`, a setting): `Installation\PhpTimeZone` sets it
  as every request starts (`kernel.request`, priority 8192, before the lockout gate), as every console command starts
  (`console.command`), and before each run of `cattophp:schedule:run`, once a minute under `--watch`, so a time zone
  changed on the Settings page applies without a restart. It was PHP's own default, so a task due at 03:15 ran at 03:15
  in PHP's zone, and a document's dates, the mailing's sending hours and the Telegram and catto-mail times were in it
  too. A time zone PHP does not know, or a setting that cannot be read, leaves PHP's zone as it was:
  `Installation::chosenTimeZone()` (null then) beside `timeZone()` (UTC then, as before). A project that sets the zone
  itself as it starts reads the same setting, so the two agree.
- Added: the activity log's details in words, `Activity\ActivityDetails` (a service, `lines($action, $details)`,
  `label()`, `value()`, `sentence()`): a key turned into words (`due_at` is "Due at", a value `needs_repair` is "Needs
  repair"), an ISO 8601 date in words ("1 December 2026") and an ISO 8601 time in the installation's time zone and in
  words ("7 November 2026 at 07:01"), true and false as yes and no, a list of words on one line, anything else as JSON,
  a long value cut short. A project words its own with `Activity\DetailWords` (tag `cattophp.detail_words`, by
  autoconfiguration; `label(string $action, string $key): ?string`, `value(string $action, string $key, mixed $value):
  ?string`, null for the log's own wording), beside `ActionWords`, which is unchanged. The Activity log page uses it
  (it showed `due at: 2026-11-07T07:01:56+02:00`), and names a project's own subject by its type in words ("Loan
  sd4jr2uh", was "loan sd4jr2uh"). `Admin\ActivityController` takes an optional `ActivityDetails`.
- Added: why each field of a refused form was refused waits with what was typed, for the form that sent it:
  `TypedForm::keep($request, $secret, $errors)` (`errors` by the field's name), `TypedForm::errors($request, $action)` and
  the Twig function `typed_errors_for(action)`, taken and forgotten with the values; `form.field`'s `error` draws it
  under its field with `aria-describedby` and `aria-invalid` on the control (as it always could). A form refused without
  errors is kept exactly as before.
- Added: `data.table`'s `stack` (false unless given): below 768 pixels each row is a card of label and value, the label
  from each cell's `data-label` (the template writes it beside the column heading it repeats), the value wrapped where
  the table cut it short, an empty cell left out and the caption beneath; the head row is hidden from sight only and the
  label's alternative text is empty, so a screen reader still reads the table it is; no script. `data-stack="narrow"` on
  the table's box; a stacked table is fitted (`fitTableColumns`) only when it is a table again. A table without `stack`
  is drawn byte for byte as before. CattoPHP's Activity log, Roles and a role's holders use it; the Activity log's What,
  About and Details wrap (`cell-text`).
- Added: `Foundation\ReadableCode`: `make(int $length = 8)`, a code of lower-case letters and digits without the ones
  that look alike (no 0 or o, no 1, l or i; `ALPHABET`, 31 characters, `CHARACTER` for a pattern), from `random_int`, 4
  to 64 characters; `read(string $typed, ?int $length = 8)` takes one back as typed (capitals, spaces and hyphens) or
  null. Not unique: a project keeps a unique index and makes another when one is taken.
- Added: Administration's Settings, Roles and Activity log pages (`Admin\SettingsController`, `RolesController`,
  `ActivityController`, the page model `Admin\SettingsPage`, the names of the people they mention `Admin\AccountNames`),
  in the routes file a project imports, `config/routes/admin.php` (`/admin/settings`, `/admin/roles`, `/admin/activity`,
  route names `cattophp_admin_*`), behind CattoPHP's own permissions (PLATFORM.SETTINGS.VIEW and SYSTEM.SETTINGS.MANAGE,
  PLATFORM.ROLE.VIEW and SYSTEM.ROLE.MANAGE, PLATFORM.ACTIVITY.VIEW), templates at `@CattoPHP/admin/`. Settings: every
  group of the catalogue, each setting saying where its value comes from (saved here, when and by whom, overriding
  .env; .env; its default) and what Reset goes back to, a Reset to .env beside each one saved and for the group (each a
  form of its own after the group's, asking to be confirmed, so Enter in a field saves and never resets), one Save a
  group, a choice in force shown selected whatever its case and a value that is no option shown selected as it stands, a saved secret as asterisks in its placeholder (left as they are, the field sends nothing and the secret is
  kept; typed out, they are no new secret either) with "Saved, encrypted and never shown. Leave the asterisks to keep
  it, or type a new one.", a refused form back as typed with why beside each field and never a secret, and someone who
  may only look told why there are no buttons. Roles: every role, what it allows as feature cards, who holds it, given
  by an email address, taken away, made and deleted. Activity log: newest first, a page at a time, filtered by any action
  it holds, each in words, refreshing itself every 15 seconds.
- Added: a person's Sign-in and security page, `Account\SecurityController` (`config/routes/account.php`,
  `/account/security`, route names `cattophp_account_*`, templates at `@CattoPHP/account/`): two-step sign-in by their own
  choice wherever it is not required of them (AUTH_TWO_STEP CHOICE, or ADMINS for anyone but an administrator): set it up
  with the QR code, turn it off with a current code or a recovery code, new recovery codes with a current code; and, with
  `sign_in.sessions`, where they are signed in with End and End all others.
- Added: `sign_in.sessions` (off unless a project switches it on) and `SignIn\SignInSessions`: each sign-in through
  CattoPHP's sign-in pages kept among CattoPHP's sign-ins (`cattophp_sessions`, by the hash of a token in the visitor's
  session, where from as a keyed fingerprint, the browser, when it began, was last seen and runs out), checked on every
  request by `SignInUserProvider` (one ended, or run out, signs that browser out at its next page), ended at signing out,
  and listed on the account page. A project that keeps its sign-ins itself through the store leaves it off, so nothing
  is kept twice. Activity: `sign_in.session_ended`, `sign_in.sessions_ended`.
- Added: joining, `SignIn\JoiningAccounts`, beside `SignInAccounts` for a project where people join by signing in for
  the first time: an address with no account that may join (`SignInRules::mayJoin()`: `APP_ADMIN_EMAIL` always, anyone
  with `AUTH_SIGNIN=EVERYONE`, else the listed addresses) is sent a link like anyone (`SignInLinks::createJoining()`,
  purpose `sign_in_join`, the address alone), and `join()` makes the account only when the link is used; nothing else is
  kept before then, the activity log records `sign_in.joining_link_sent` naming nobody (never a made-up subject), and the
  sign-in page says the same to every address. `cattophp:sign-in-link` gives a joining address a link too. `SignInLink`
  gains a `joining` address; `SignInRules` gains `twoStepRequired()` and `isAdministratorAddress()`.
- Changed: the stock pages (signing in and the error page) extend `@CattoPHP/page/stock.html.twig`, so in a project with
  themes they wear the theme, with CattoPHP's page head and the components, where they drew CattoPHP's plain frame unless
  the project replaced all six; without themes they extend `cattophp.layout` as before. A project's own replacements
  keep working unchanged. The plain frame `@CattoPHP/layout.html.twig` draws block page_body inside block body.
- Fixed: the error page's last resort, drawn when the theme itself fails, is given `layout` (the plain frame), so a
  project's own error template written for its theme (extending `layout`, filling page_body) still arrives
  (`TwigErrorPageDrawer::PLAIN_FRAME`).
- Added: `Http\StockPages`, what CattoPHP's own pages do alike: drawn in the project's look, what the reader's last form
  did said at the top (Symfony's flash bag under CattoPHP's own names, `cattophp_done`, `cattophp_refused`,
  `cattophp_info`, so a project's messages never show twice), the token and permission checks, done, refused (the form
  kept as typed, never a secret, why each field was refused) and expired.
- Added: `data-two-step-key` on the element holding the two-step key's text, on the sign-in code page and the account
  page alike, so a project's checks find it in any theme.
- Added: `Activity\ActionWords`, a tagged interface for what the log's actions mean in words, with CattoPHP's own
  (`FoundationActionWords`); a project adds its own by implementing it.
- Changed: `ActivityStore::recent()` takes an offset, and `count()` and `actions()` (every action present) are added to
  the stores and to `ActivityLog`, so a page lists the log a page at a time and filters it by what it holds. A file's log
  is read back from its newest 2 MB, as before.
- Fixed: `TwoStep::setUp()`, a recovery code used and new recovery codes are recorded as the person's own doing, never
  the system's (at sign-in nobody is signed in yet): the actor given, else whoever is signed in as that account, else the
  account by its id. Each takes an optional `Actor`, which the sign-in pages give by the account's name.
- Fixed: `Redactor` masked the recovery codes' count (`recovery_codes => 8`): a whole number under a name that counts (a
  plural, or one ending `_count` or `_left`) is kept; a code is masked as before, even a number.
- Changed: `TypedForm::keep($request, $secret)` is told the form's other secret fields by name (as the Settings page does
  with each setting that is a secret, whatever it is called), which are never kept.
- Changed: `APP_LOCALE` is a setting of the installation group, beside the time zone, read through Settings
  (`Installation::locale()`, `en_GB` unless it says otherwise): `Document\ValueFormatter` (given the installation) and
  `Search\PageHead::language()` read it there, never from the process's environment, and a locale that is not one is
  `en_GB` (it was `en_ZA`; `Search\StructuredData::language()` falls back to `en-GB` the same way). `ValueFormatter`'s
  constructor takes the `Installation` as a third, optional argument. `PageHead`'s constructor no longer takes `EnvironmentValues`. A project whose own locale is
  another gives it under `defaults`.
- Fixed: a notice's icon sits beside its words: `.cl-ui-notice` lays out from the start (it spread icon and words to
  opposite ends), with a dismissable notice's close button kept at the far end (`.cl-ui-notice>.cl-ui-close`).
- Changed: `cattophp.css` no longer forces a table cell's block onto one line with the first (`.cl-ui-table td>div`,
  `td>p`, `td>span.muted`, `td>.small` inline, a dot before a muted one): a project that wants it keeps the rule itself.
- Changed: `cattophp.css` holds no application's classes: the flash cards and their player, Share this page, the
  checkout's steps and promo code, the question editor's options, a content tree's section names and a category tree's
  rows are an application's own, in its stylesheet.
- Changed: the schema runner, `SchemaStep` and `cattophp:migrate` say what they do: a project's own steps (its classes
  implementing `SchemaStep`, tagged by autoconfiguration) are applied with CattoPHP's, in the order of their ids.
- Fixed: `cattophp:schedule:run` stops when told to: it implements Symfony's `SignalableCommandInterface` for SIGTERM and
  SIGINT, finishes the task under way, starts no other (`Scheduler::run()` takes a `$stopping` check) and ends at once,
  waiting or not, so a container whose first process it is stops in a moment. It listens only where PHP can catch signals
  (`ScheduleRunCommand::signalsSupported()`: the constants defined and `pcntl_signal` allowed, as Symfony's console asks).
- Changed: the README's configuration example names every key (`defaults`, `permissions_policy`, `html_layout`, the
  icon subjects, `error_pages.drawer`, the network lookup and ASN database, each `task_priority` and `http_client`,
  protection's `visitors`, `gate_listener`, `forms` and `list_only_forms`, `mailing.campaign_project` and the rest), says
  that `APP_ENV` comes from the process's environment (a server's or container's value over `.env`), that a permission
  has two to four parts and one starting `SYSTEM.` is for administrators alone, and no longer calls mailing a first part.

- Updated: the icon library is Lucide 1.72.0 (ISC, as before; `resources/icons/lucide.json` built from the `lucide-static`
  1.72.0 package in the same form, which rebuilds 1.70.0's file byte for byte): 34 more icons (2,356), none removed, so
  no icon a project has stored is lost; 19 redrawn (the question mark of the help and question icons, and `route`) and
  the search words of 404 brought up to date.
- Fixed: the Inbox's mailbox showed as connected when Check the connection had just failed, and before it had ever been
  tried. `InboxMailbox::check()` signs in and records the outcome as the minute's read does, `detailsSaved($before)`
  takes the state back to not checked when the mailbox's details change, and `status()` gives `state`: `none`,
  `unchecked`, `connected` or `failed` (with when it was last tried and what went wrong). The minute's read reads only
  a connected mailbox: details never checked, or a mailbox that refused, are not tried again every minute (a mail
  server's protection bans an address that keeps failing to sign in) until Check the connection works.
- Changed: Didit's keys (`DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`) are a settings group,
  `Identity\IdentitySettingGroup`: `.env` gives their starting values and Settings overrides them with a Reset, the
  secrets encrypted (they came from `.env` alone). `IdentitySettings` reads them through Settings.
- Added: `Identity\IdentityConnection`, the connection to Didit as a settings page controls it: `check()` asks Didit for
  the application's workflows (`DiditClient::workflows()`, `GET /v3/workflows/`, a pure read) and records whether the
  key and the workflow were accepted; `status()` (`none`, `unchecked`, `connected`, `failed`, with the workflow's name);
  `keysSaved($before)`; `registerWebhook()` (`DiditClient::registerWebhook()`, `POST /v3/webhook/destinations/`, keeping
  the signing secret Didit gives); `drop()` and `resetToEnv()`. Schema step `202611130100-identity-connection`.
- Added: `Settings::clear()`, an empty value saved on purpose, so a dropped connection stays dropped where `.env` holds
  keys, until new ones are saved or `reset()`.
- Added: one table for every connection to an outside service, `cattophp_connections` (schema step
  `202611130200-connections`, given to identity verification and to payments, either of which may be switched on alone,
  and once when both are; Didit's row moves in from `cattophp_identity_connection` as `didit`, and that table is
  dropped). `Settings\ServiceConnections` keeps each connection's last real try by its name: when, whether it worked,
  what went wrong, what it learnt, and a keyed fingerprint of the details it was made with, so details changed since, on
  the Settings page or in `.env`, are not checked yet. Its states are `none`, `unchecked`, `failed` and `connected`.
  Kept by `Settings\DbalConnectionStore`, or `MemoryConnectionStore` without a database.
- Changed: `Identity\IdentityConnection` keeps where it stands among the connections (`didit`) instead of in a table of
  its own, and its constructor takes `ServiceConnections` in place of the database connection. A key changed after the
  check, even in `.env`, is not checked yet without `keysSaved()`.
- Added: `Payments\GatewayConnections`, a payment gateway's connection as a settings page controls it: `check($gateway,
  $ask)` is refused while a key the gateway needs has no value, then asks the provider through the project's own
  question (Omnipay has none every gateway answers), which answers null when the keys are accepted, else the provider's
  words, and records the outcome; `status()`, `connected()`, `drop()` (every key forgotten, `.env`'s too) and
  `resetToEnv()`.
- Changed: `Modules::schemaSteps()` gives a step two modules share once.
- Changed: "Drop connection" is the words for ending a connection: Telegram's refusals for an account connected
  elsewhere say "Drop its connection there first" and "Drop that connection first" (they said Disconnect).

- Changed: a dropped connection is named so (Drop connection, never Disconnect): the activity
  entries `telegram.connection_dropped` (was `telegram.disconnected`) and `mailing.cattomail_connection_dropped` (was
  `mailing.cattomail_disconnected`), and the Telegram bot's flag and `TelegramSetup::view()`'s key `connection_dropped`
  (was `disconnected`; `TelegramBot` saves it as `connection_dropped`, so a `disconnected` already saved is no longer read),
  and the methods `dropConnection()` on `TelegramSetup`, `TelegramConnections`, `TelegramAccounts`, `CattoMailConnection`
  and `CattoMailClient` (were `disconnect()`).

- Fixed: an installed CattoPHP holds only the package's own files. `.gitattributes` (`export-ignore`, which GitHub's
  release zips follow and so does a Composer path repository's mirror) and `archive.exclude` in `composer.json` (for
  `composer archive`) leave out everything but `bin/`, `config/`, `public/`, `resources/`, `src/`, `templates/`,
  `composer.json`, `README.md`, `CHANGELOG.md`, the guide and `LICENSE`; the tests, `phpunit.xml.dist`, `phpstan.neon`,
  `.gitignore` and `.gitattributes` stay in the repository. A project taking CattoPHP from a folder got all of it, 204
  MB with CattoPHP's own `vendor/` and `var/`; it gets 4 MB.
- Added: `Http\StockPages::PAGES`, the names CattoPHP's own pages are drawn as, each with where, which a project's
  `Theme\PageProject` is given (`family()`, `prepare()`, `model()`) and puts in one of its families (README, "Its own
  pages"): `sign-in` (every step of signing in, `SignIn\SignInController`, `@CattoPHP/sign_in/`), `error` (every error
  page, `Http\TwigErrorPageDrawer`), `admin-settings`, `admin-roles`, `admin-role-new`, `admin-role` and
  `admin-activity` (Administration's pages, `Admin\`), and `account-security`, `account-two-step` and
  `account-recovery-codes` (Sign-in and security, `Account\SecurityController`).
- Changed: Sign-in and security lists where the person is signed in as cards (`data.card-grid` of `data.card`): the
  browser as the card's title with "This browser" beside it, when it began, was last seen and runs out, and End or "Sign
  out to end it" at its foot; three to a row on a wide screen and one on a phone, every word in full. The table cut its
  Browser column short, so the badge never showed at 1440, and cut every column at 390.
- Added: `data.card` takes `picture: 'none'`, for a record a picture would not help (a sign-in): its words keep to the
  start, where a card with a round or square picture centres them.
- Fixed: two forms in one card touched (Make new recovery codes sat on the label of the field to turn two-step sign-in
  off), and so did Cannot scan it? and the field after it. `.cl-card-form` (several forms in one card: the next starts
  the space below the last one's actions that the actions keep above their rule) and `.cl-disclosure` (a details element
  in a card's flow, the space between two fields below it), on Sign-in and security's two forms and on Cannot scan it?
  on the two-step set-up page and the sign-in code page. A page asks for them by the class, so no other page's forms
  move.
- Fixed: the plain frame (`@CattoPHP/layout.html.twig`) says the pages are in the installation's language, `<html lang>`
  from `APP_LOCALE` (`en_ZA` as `en-ZA`), where it always said `en-GB`. `Installation::language()` gives the locale as
  BCP 47, the region in capitals (`cattophp.installation.language` in a template); `Search\PageHead::language()` gives
  it too.
- Added: forgetting an account, the one call a project makes when it deletes one, inside the transaction that deletes
  its own row and before that row goes (a transaction already open is joined): `Account\AccountRecords::forget($account,
  $by)`. Its roles, two-step key and recovery codes, links (the joining link
  made for its address among them), sign-ins and each module's records that are its alone go; the records that stay stop
  naming it: the activity log keeps every entry, by "A deleted account" (`AccountRecords::FORGOTTEN`) and nobody's id
  where it did something, about nobody where it was the subject, and anything in what any entry says that names it said
  so too; a setting it saved, and whatever a module keeps of who did what, no longer say who; an address a module keeps
  becomes `AccountRecords::FORGOTTEN_EMAIL` (`deleted-account@forgotten.invalid`), a name or username empty and a network
  address gone (see the Fixed entries for each). Each module switched on
  forgets its own through its stores' forget methods (`Account\Records\`: the setup checklist's marks; site protection's
  Site Log, flags, lockouts and refused addresses; Telegram's connected accounts, messages and bot values; API tokens;
  sign-up attempts; identity checks and retries; the Inbox's messages, replies, lessons and blocked senders; mailing's
  answers, forwards, copies, lists, campaigns and addresses never emailed; search engines' page words), as a database
  trigger might. A project forgets its own records the same way, with a service implementing
  `Account\ForgetsAccounts` (found by autoconfiguration). In one transaction where the records are in the database. It
  refuses the last administrator and an administrator's own account at their own hand, as taking the Administrator role
  away refuses (`AccessRefused`), counting administrators by the role and by the project's flag
  (`SignInAccount::administrator`) among the role's holders it still finds and the installation's administrator's
  account, the holders locked until the transaction ends, unless told `lastAdministrator: true`, for an account made only
  to be removed again (a check's own administrator on a database that had none). It returns how many records of each kind went and stopped
  naming it, and records `account.forgotten` naming nobody it forgot. The project's accounts reach it by a closure, so
  the project's own accounts service may take it.
- Changed, for forgetting an account: `ActivityStore`, `RoleStore`, `LinkStore`, `SessionStore` and `SettingsStore` gain
  `forgetAccount()`, and `Setup\SetupMarkStore` gains `forget()`; `TwoStepStore::forget()` says whether there was a key
  (it said nothing) and never reads it, so a key in a form the site cannot read is forgotten all the same. The modules'
  stores gain `forget()` (`Protection\SiteLog`, `Flags`, `Lockouts`, `RefusedEmails`, `Telegram\TelegramAccounts`,
  `TelegramLog`, `TelegramState`, `Api\ApiTokens`, `Signup\SignupAttempts`, `Identity\IdentityChecks`,
  `Search\PageOverrides`) or `forgetAccount()` (`Inbox\InboxStore`), and the mailing parts' `forget()` say how many
  (they said nothing). A project with its own implementation of one of these interfaces adds the method. `ActivityEntry`
  gains `forgetting()` and `detailsForgetting()`.
- Changed: the Activity log page names an entry with nobody's id by the name it keeps, so an account forgotten is "A
  deleted account", where every such entry said "The system", and an entry about an account forgotten is about "A
  deleted account". `account.forgotten` has its words.
- Changed (third trial, 3): every service tag is a constant of `CattoPHP\ServiceTags`, and no extension interface
  declares a constant, so one class may implement several (a trial project's one class wording its details and linking
  its subjects would not load: "inherits both CattoPHP\Activity\DetailWords::TAG and CattoPHP\Activity\SubjectLinks::TAG,
  which is ambiguous"). `ExtensionPointsKernelTest` loads one class implementing all fourteen and finds it under every
  tag, and fails if an extension interface gains a constant. Of two interfaces, only `ScheduledTask::label()` and
  `DetailWords::label($action, $key)` share a method name: a class that is both answers both with one method.
- Added (third trial, 11): `cattophp:icons:search <words>` (`Ui\IconSearchCommand`), so an icon's name is found, not
  guessed (a trial project guessed `ladder`, which Lucide lacks): each icon that matches every word, by its name or its
  tags, best first, with its tags beside it (`drill  power, bit, head, hole, diy, toolbox, build, construction`;
  `borrow` finds `hand-coins` by its tag), as `IconLibrary::search()` ranks them; `--limit` (30); exit 1 and plain words
  when nothing matches. `IconLibrary::tags($name)` gives an icon's tags.
- Added (the plan's tooling, never built until now): `Ui\Contract\AddressContract`, the contract "no row numbers in
  addresses", for a project's contract tests: `AddressContract::rowNumbers(RouteCollection $routes, array $allowed = [])`
  refuses, in words, every route parameter that takes a database row number (named `id` or ending in `_id`, or held to
  digits alone, `\d+`, `[0-9]{1,9}`), over every route the router holds (the project's and the ones it imports);
  parameters from `_` (`_format`) and Symfony's own routes (named from `_`) are left alone. A route that must keep one
  for now is allowed by `"<route name> <parameter>" => 'why'`; an allowance without a reason, or one no route needs any
  longer, is refused too, so the list only shrinks. `isRowNumber($parameter, $requirement)` and
  `onlyDigits($requirement)` are its rule (ported from cattolms's `RowIdRouteContractTest`). CattoPHP's own routes pass
  (`AddressContractKernelTest`: a role by its key, a sign-in link by its token).
- Fixed: the New role page and a role's page drew their permission choices after the form's `form.grid`, where nothing
  spaces a choice from the field above it (`.cl-ui-field+.cl-ui-picture-choice--cards` holds only for a choice right
  after a field): the first sat flush against the description's help. The choices are inside the grid, `columns: 1`, the
  pattern the guide gives for a form that mixes fields and feature cards. No rule of `cattophp.css` changed.

## 0.3.0 · 12 November 2026

The modules: everything a project may need beyond the foundations, each switched on in `config/packages/cattophp.yaml`
when the project wants it and costing nothing when it does not. The scheduler with the log archive, the setup checklist,
site protection, Telegram, search engines and AI, documents and PDFs, an API with signed webhooks, the network lookup,
the sign-up check, identity verification, the Inbox, mailing (sending through a mail server or catto-mail, lists and
consent, campaigns and the sender, the links in a copy, Forward to a friend, the figures), and countries with the South
Africa pack. A project now says which modules it needs: it starts only when they have what they need, told plainly what
is missing, and `cattophp:migrate` makes the tables of the modules it switches on (`cattophp:modules` lists them).
Nothing changes for a project that switches none on; one with a database runs `cattophp:migrate` after updating.

- **The scheduler** (`CattoPHP\Scheduler`, switched on by `cattophp.scheduler`): a project's background work as tasks, each
  a service implementing `ScheduledTask` (its key, name, what it does, how often or at what time of day, when it counts
  as stopped, and `run()`, which says what it did or throws). `cattophp:schedule:run` runs what is due, once (a cron line
  every minute) or once a minute with `--watch` (a worker), and `--job` runs named tasks now; `scheduler.command_alias`
  gives it the name a project's cron line or worker already uses. Each task's last start, finish, success, failure,
  summary and error and how often it ran are kept (`cattophp_scheduled_jobs`, a new schema step, or a file without a
  database); one task failing never stops the rest. `TaskStatus` gives each task's last runs in words and whether it has
  stopped, for a project's own pages. A time of day is the site's: a task due at midnight runs once just after it, or
  when a scheduler stopped then starts again.
- **The log archive** (`scheduler.log_archive`, with the folder the logs are in): just after midnight every log there
  that holds anything moves into `archive/<name>-<day>.log.gz` and starts afresh with its permissions, and archives older
  than `LOG_ARCHIVES_KEPT_FOR` (two weeks unless `.env` or Settings says, at least a day) are deleted.
- **The setup checklist** (`CattoPHP\Setup`, switched on by `cattophp.setup`, with the database): the steps a new
  installation needs, in order, each supplied by a project's `SetupStepProvider` service as a `SetupStep` (its group,
  title, what it is for, where it stands, the page where it is done, and an optional button of the project's own). A
  step is checked automatically, needs you, or both: the site finds it done, or a person marks it done or skips it for
  now and can open it again. `SetupChecklist` gives each step with whether it is done and can be marked, the progress
  and the next step (a skipped one is passed over); a mark is kept with when, by whom and an optional note
  (`cattophp_setup_steps`, a new schema step) and in the activity log. What a step says it found, and a note, are shown
  with their secrets hidden.
- **`Security\Redactor`**: hides secrets in values (a value whose name says it is a password, key, token or other
  secret) and in text (the password in an address such as `smtp://user:password@host`, a secret given by name such as
  `password=…`, a bearer token, a private key). The activity log cleans every detail with it, so a secret written in a
  detail's text is now hidden as well as one named as a secret.
- **Site protection** (`CattoPHP\Protection`, switched on by `cattophp.protection`, with the database): the Site Log
  (every request written as it ends: who, the browser, the country and network, the page with its secrets masked, the
  answer, where the visitor came from, what a form guard found; `RequestRecorder`, `PathMask`, `Arrival`), flagged
  addresses (`Flags`), time-outs and lockouts of an address (an IPv6 one as its /64), a range or a network owner
  (`Lockouts`, `LockoutTarget`, `LockoutService`: lock out, lift, mark not a problem, never your own address or the Never
  lock out list), the lockout gate before anything else (`LockoutGate`, a request listener or called before a project's
  own session), the rules every minute as the scheduled task `site_protection` (`ProtectionRules`: eight rules, time-outs
  that double and turn into a lockout, administrators, signed-in people, private addresses and proved search engines
  spared; `SearchEngineProof`), the form guard on public forms (`FormGuard`, `BotCheck`'s signals and a refused list of
  email addresses and domains, `RefusedEmails`), the two-file link to a firewall on the server (`ServerLink`), the
  `SITE_*` settings (`ProtectionSettings`, `ProtectionSettingGroup`) and retention (`ProtectionRetention`). A project gives
  an address's country and network through `NetworkLookup` and who is signed in through `VisitorAccounts`. Four tables in
  a new schema step: `cattophp_site_requests`, `cattophp_site_flags`, `cattophp_site_lockouts`,
  `cattophp_site_refused_emails`, a person as their account id.
- **Telegram** (`CattoPHP\Telegram`, switched on by `cattophp.telegram`, with the database and symfony/http-client): a
  bot connected by its key (encrypted, never shown back) and the owner's username, found by the chat they write from
  (`TelegramBot`, `TelegramSetup`: connect, test, the project's own choices, disconnect); messages to the owner kept in
  the transaction of what they are about and sent within a minute by the scheduled task `owner_messages`, a fault never
  harming what they are about (`OwnerMessages`, `OwnerMessageSender`); messages to a person sent at once, a sign-in
  message held until the page has answered, each kept in the Telegram log without its secrets (`TelegramMessenger`,
  `TelegramDeliveryListener`, `TelegramLog`); people's Telegram accounts connected by a link that works once and the
  bot's /start (`TelegramAccounts`, `TelegramConnections`); what people send the bot read from a saved offset, one
  reader at a time (`TelegramInbox`). The Bot API client scrubs the key from anything it says (`TelegramClient`,
  `TelegramFailed`). Three tables in a new schema step: `cattophp_telegram_state`, `cattophp_telegram_messages`,
  `cattophp_telegram_accounts`, a person as their account id.
- **Search engines and AI** (`CattoPHP\Search`, switched on by `cattophp.search`, with the database): what AI crawlers may
  do and the Google Search Console and Bing Webmaster Tools codes as settings (`SearchSettings`, `SearchSettingGroup`:
  `SEARCH_AI_CRAWLERS`, `SEARCH_GOOGLE_CODE`, `SEARCH_BING_CODE`; a code taken bare or from its whole meta tag), the
  dated list of AI crawlers with what each company says it is for (`AiCrawlers`), `robots.txt` with the project's private
  paths and a group for each AI crawler (`RobotsTxt`), the sitemap from the project's `SitemapSource` services in priority
  order, an index past 50,000 addresses and kept an hour, none on a demo site (`Sitemap`), `/llms.txt` from the project's
  `LlmsTxtContent` (`LlmsTxt`), share pictures drawn from SVG at 1200 by 630 and stored by their cover's hash
  (`SharePictures`, with `Foundation\SvgRaster` for rsvg-convert), structured data any site has and its rules
  (`StructuredData`: Organization, WebSite, FAQPage, BreadcrumbList, ItemList, a rating, an organisation named elsewhere,
  a workload in ISO 8601), plain words and excerpts (`PlainText`), and every public page's head (`PageHead`: its
  description, canonical address, Open Graph and X's card with the site's own picture through `DefaultSharePicture`, a
  search's results not indexed, ", page N"), over the project's public pages (`PublicPageProvider`, `PublicPage`,
  `PublicPages`) and an administrator's own title and description for each (`PageOverrides`, `cattophp_search_pages`, a
  new schema step).
- **Documents and PDFs** (`CattoPHP\Document`, switched on by `cattophp.documents`, with dompdf/dompdf): a template
  language that is not a programming language (`{{ group.name }}`, `{{#each}}`, `{{#if}}…{{else}}`; `TemplateParser`),
  checked against the placeholders a document type allows with every problem said at once, a close name suggested
  (`TemplateValidator`, `PlaceholderSet`, `PlaceholderDefinition`, `CollectionDefinition`, `PlaceholderKind`), the HTML
  and CSS a template may hold (`HtmlPolicy`, `CssPolicy`: nothing executable or fetched, pictures embedded), each value
  written by its kind and escaped, money in the installation's locale and dates in its time zone (`ValueFormatter`),
  page settings from a controlled list (`PageSettings`), one renderer for previews, documents and PDFs that refuses data
  that does not fit (`DocumentRenderer`, `DocumentDataException`, `TemplateValidationException`), a project's stored
  pictures embedded as they are drawn (`DocumentImages`), the rendered document with the template version that made it
  (`RenderedDocument`), and PDFs through Dompdf with the project's fonts, a page of the site set out for print, and where
  chosen boxes were laid out (`PdfRenderer`). dompdf/dompdf is a development requirement and a suggestion.
- **An API** (`CattoPHP\Api`, switched on by `cattophp.api`, with the database): tokens issued to an account with a name,
  the project's scopes and an optional lifetime, shown once and kept only as their SHA-256, listed and revoked, each use
  noted (`ApiTokens`, `cattophp_api_tokens`, a new schema step); whether an account may use the API (`ApiAccounts`); the
  identity a request acts for with its roles, permissions and scopes (`ApiIdentity`) and the scope and permission checks
  for code that is not a route (`ApiAccess`); the Bearer authenticator that is also the API firewall's entry point
  (`ApiTokenAuthenticator`, `ApiUser`), the scope voter beside the permission voter (`ApiScopeVoter`), and one JSON error
  document for refusals and for exceptions under the API's path (`ApiProblem`, `ApiExceptionListener`).
- **Signed webhooks** (`CattoPHP\Webhooks\WebhookSignature`): the header a sender makes ("t=<time>,v1=<HMAC-SHA256 of
  time.body>") and the receiver checks, within five minutes, with secrets that can be rotated.
- **The network lookup** (`CattoPHP\Network`, configured by `cattophp.network`): what an address says about a visitor,
  looked up on the server unless a project names a web service: its country and place through Geocoder PHP (`GeoIp`:
  its GeoIP2 provider over a MaxMind country or city database, or another Geocoder provider named by `network.geocoder`,
  such as a chain that falls back from one to the next), its network from the GeoLite2 ASN database, and whether the published lists name it a Tor exit, a VPN network or a hosting network,
  or an email domain a throwaway one (`ReputationLists`, fetched into a folder, never at a visitor's request), together
  `GeoIpNetworkLookup`, which can be made before a project's container. `NetworkLookup`, `NetworkFacts` and
  `NoNetworkLookup` move here from `Protection` (site protection's `protection.network_lookup` is now `network.lookup`),
  and the facts say each list's answer beside the one kind the Site Log shows. geocoder-php/geoip2-provider is a
  development requirement and a suggestion.
- **The sign-up check** (`CattoPHP\Signup`, switched on by `cattophp.signup`, with the database): each registration
  judged when its form is sent (the address's country and network, the email domain, the browser, the time the form was
  open by a signed stamp, the browser's time zone, a hidden field, earlier sign-ups from the address), scored and banded
  with each signal in words (`SignupRisk`, `RiskAssessment`), a throwaway address refused, and recorded with its outcome
  (`SignupChecks`, `SignupAttempts`, `cattophp_signup_attempts`, a new schema step); the allowed and blocked countries as
  settings (`SignupCountries`, `SignupSettingGroup`); whether a domain receives mail (`MailDomainCheck`); and the daily
  task that fetches the lists (`SignupListsTask`).
- **Identity verification** (`CattoPHP\Identity`, switched on by `cattophp.identity`, with the database): a check on
  Didit's hosted page started and its result taken by webhook or on the person's return (`DiditClient`, `DiditSignature`
  with the signatures of a rotated secret, `IdentityDecision`), at most three declined tries until an administrator allows
  another, revocation with a reason, every event in the activity log without the document number or date of birth, a live
  site never taking a sandbox result (`IdentityVerification`, `IdentitySettings`); the checks, the webhook events handled
  and the tries allowed again kept (`IdentityChecks`, three tables in a new schema step); what Didit is told of an account
  (`IdentityAccounts`) and what a project does when one is approved (`IdentityApproved`, only from `identity.minimum_age`).
- **The Inbox** (`CattoPHP\Inbox`, switched on by `cattophp.inbox`, with the database): a contact form's message kept as a
  conversation with its reference (MSG-000043, the prefix configurable) and judged by the spam check when it arrives (the
  fixed signs, `SpamSigns`, and a Bayesian word filter, `SpamFilter`, whose say grows with what it has learnt), then filed
  in the Inbox or in Spam without the sender being told; Mark as spam and Not spam teaching the filter, blocked senders,
  the Trash with Restore and Delete forever, drafts, the daily clean-up after the keeping settings (`Inbox`,
  `InboxStore`, six tables in a new schema step); replies in plain text or HTML with signatures, files and the account's
  own documents, threaded by Message-ID and the reference in the subject (`InboxReplies`, `InboxHtml`, with
  `InboxDocuments` and `ReplySender` for a project's documents and mail); answers read from a mailbox over IMAP every
  minute and filed into their conversations (`InboxMailbox`, `ImapMailbox`, `MimeMessage`, the `inbox_mailbox` task);
  and the Inbox settings (`InboxSettings`, `InboxSettingGroup`, INBOX_*).
- **Countries and the South Africa pack** (`Country\Countries`, `SouthAfrica\IdNumber`, `SouthAfrica\Provinces`): every
  ISO 3166-1 country with its name and a drop-down list with a project's own countries first; South African ID numbers
  checked as well formed, masked and fingerprinted (HMAC-SHA256 of `sa-id:` and the digits with the project's key, the
  form cattolms keeps); the nine provinces. Taken from cattolms.
- **Mailing, part 4: the figures** (`Mailing\MailingFigures`): for a period, the copies handed on and what became of them,
  by kind and by campaign (of the kinds a project counts), forwards, the people who joined or left a list, the most
  clicked links and the copies in each hour, day, week or month; for an overview, subscribers and who joined lately, the
  lists with someone on them by kind, campaigns sent lately, bounces and complaints since a moment, the lists most joined
  and the campaigns scheduled.
- **Mailing, part 3, second half: the links in a copy and Forward to a friend** (`Mailing\CopyLinks`, `Forwards`): the
  opening picture and tracked links (recorded only as the project's rules say, `SendingRules::$trackOpens`/`$trackClicks`),
  a sent copy found by its token, whether a copy has stopped its list; a copy forwarded to at most five friends, ten a day
  per person, never to the person's own address, an address never emailed or the same friend twice, the friend's copy
  drawn by the project (`CampaignProject::drawForFriend`) and sent through the site's mail, `mailing.forwarded` in the
  activity log, and the friend's "Never email me". Table `cattophp_mailing_forwards` (schema step
  `202611122340-mailing-forwards`).
- **Mailing, part 3, first half: campaigns, copies and the sender** (`Mailing\Campaigns`, `Copies`, `Links`, `CopyOutcomes`,
  `CampaignSender`, `CampaignProject`, `DrawnCopy`, `SendingRules`, `CattoMail\CopyEvents`): campaigns with their kind,
  words, content in the project's form, lists, status and figures; each person's copy with its token, reasons, status,
  opens and clicks; the links in a campaign and who clicked them; the sender (audience taken when a campaign starts, the
  sending hours, the hourly limit and gentle first days, copies skipped when they should not go, a pause above the bounce
  or complaint rates unless overridden, a refusal pausing with why, a busy transport waiting, catto-mail's outcomes read
  back); what becomes of a copy (forward only; a hard bounce or a complaint never emailed again, a complaint stopping
  every list, an unsubscribe link stopping the lists that brought the copy or all); catto-mail's events applied to copies
  by CattoPHP itself. Tables `cattophp_mailing_campaigns`, `cattophp_mailing_copies`, `cattophp_mailing_links`,
  `cattophp_mailing_link_clicks` (schema step `202611122330-mailing-campaigns`, with a trigger making an address never
  emailed forget a deleted copy).
- **Mailing, part 2: lists and consent** (`Mailing\MailingLists`, `Subscriptions`, `Consents`, `Suppressions`): a list for
  each kind and subject a project offers to follow, made at the first yes (a no to a list nobody joined makes none), and an
  administrator's own lists (a name, words, shown or not, a join link, open or closed, a sender name and reply-to); each
  person's answer (subscribed, unsubscribed, or pending for a list confirmed by link) with where it was given, and every
  change kept; joining, stopping one list or every list, recorded in the activity log as `mailing.subscribed`,
  `mailing.unsubscribed` and `mailing.unsubscribed_all`; the addresses never emailed (a hard bounce, a complaint, by hand)
  with why, the copy that caused it and who added it. Tables `cattophp_mailing_lists`, `cattophp_mailing_subscriptions`,
  `cattophp_mailing_subscription_events`, `cattophp_mailing_suppressions` (schema step `202611122320-mailing-lists`).
- **Mailing, part 1: sending** (`Mailing\`, `Mailing\CattoMail\`, the `mailing` module): Settings > Mailing (`MAILING_*`,
  `CATTOMAIL_*`, the secrets encrypted, each checked), a mail server transport whose copies carry List-Unsubscribe,
  List-Unsubscribe-Post, List-Id and Feedback-ID (a project may say where made-up addresses are delivered,
  `RecipientAddresses`), a catto-mail transport (a send job per list, copies 500 at a time with their references,
  idempotency keys, outcomes read back), catto-mail's client (busy answers asked again, a used-up quota with when it
  resets, refusals with catto-mail's words, status and problem type), its connection (PKCE connect, the sending domain,
  check, disconnect, forget, test) and its webhook (`CattoMailWebhook::answer()`; each event kept once in
  `cattophp_cattomail_events` and given to the project's `CattoMailEventHandler` services). Tables
  `cattophp_cattomail_connection` and `cattophp_cattomail_events` (schema step `202611122310-cattomail`). Taken from
  cattolms, decided with ctnlist's catto-mail code beside it.
- **Choosing modules** (`Module\Modules`, `Module\Module`): every optional module declares in one place what it is, whether
  it keeps records in the database, the modules it needs beside it and those it works better with, the packages it needs,
  and the schema steps that make its tables. When a project starts, what its modules need is checked and every problem is
  said at once with what to do; `cattophp:migrate` makes only the foundations' tables and those of the modules switched on
  (a module switched on later gets its tables then; one switched off keeps its data); `cattophp:modules` lists every
  module, whether it is on, what it needs and its tables. Each module is proved to start alone with its own tables
  (`ModulesKernelTest`). Site protection now needs the scheduler switched on.
- **A form's time stamp** (`Http\FormStamp`): when a public form was opened, signed with the application key, and the
  seconds it was open when it is sent; the sign-up check and the contact form share it.
- The code check names the file of Symfony's container functions (`scanFiles`), which no autoloader names.
- Fixed: a page's layout measured the memory in use to decide whether it had room, but PHP's limit applies to the memory
  taken from the system, which stays taken after a large piece of work; it measures that now (`HtmlLayout`), so a large
  page is served as it came rather than running out of memory. The test suite runs with 512M.
- Updated: doctrine/dbal 4.5.1 in the development lock (a patch release; nothing CattoPHP uses changed).
- A list search's help line stays under its field when a project lays the search out as a column.

## 0.2.5 · 12 November 2026

For a project drawing its pages in a theme: links that change the page in place and say whether what they open is open,
a live line that holds still while something is open to read, tables that may keep their natural widths, cards named by
their headings, and CattoPHP's own sign-in and error pages drawn in the project's theme. A list search's help line sits
under its field. Nothing changes for a project that uses none of these, and nothing to migrate: a project with its own
replacements of the sign-in or error pages that extend a base of their own keeps them as they are.

- Added: `action.link` can change the page in place (`in_place: true`: the in-place attributes a GET form carries, aimed at the
  link's own address) and say whether what it opens is open (`expanded`, as `action.button` does).
- Added: the live line holds still while the reader has something open to read: anything marked `data-live-hold` in the page.
  It then says so in the page's own words (`live_held`, optional), and it is drawn again after a swap settles and on
  Back and Forward.
- Added: `data.table`'s `fit` (true unless set): `fit: false` keeps a table's natural column widths, scrolling sideways
  in its box on a narrow screen, where the script would otherwise share the width out among its columns.
- Added: `PageRenderer::renderTemplate()`, which draws a template that is not one of the project's pages in the theme,
  as the page it is named as. CattoPHP's sign-in pages and its own error page (`TwigErrorPageDrawer`) use it when the
  project draws its pages with the page renderer (`theme.pages`), so a project's replacements of them under
  `templates/bundles/CattoPHPBundle/` may extend `layout`. Should the theme fail, the error page is drawn alone.
- Fixed: a list search's help line sits right under its field, with space below it before the list's own controls (it
  sat nearer the controls below, so it read as their label).
- Added: a card named by its heading, for screen readers: `layout.surface`'s `labelledby` (its `aria-labelledby`) and
  `layout.section-head`'s `heading_id` (its heading's id), each an element's id or nothing.

## 0.2.4 · 11 November 2026

The interface system and themes: the component registry with its 34 components, the page frame, lists, pages that
change in place and live pages, trees, the icon library, the components' styles and scripts, and the theme system with
the page renderer; the payments module through Omnipay; and a command that installs a project's themes. The components
are there for any template that uses them; the theme system and payments are off until a project turns them on (the
`theme` and `payments` settings). Nothing changes for a project on 0.2.3 that uses neither, and nothing to migrate,
apart from an email that names its own From address, which now keeps it. A project drawing its pages in a theme copies
CattoPHP's browser files with
`vendor/bin/cattophp-assets <web root>` after each install and runs `bin/console cattophp:themes:install` once its code
is in place.

- Added: `bin/console cattophp:themes:install` (`Theme\InstallThemesCommand`) installs every theme a project ships and
  publishes its browser files into the web root, as an installation or update does; `--force` replaces an installed release
  of the same version during development. Registered when the theme system is on.
- Changed: `Mail\Mailer::send()` keeps a From address the email already names (a reply sent from a project's own
  mailbox); every other email still comes from the settings' sender.
- Added: the component registry (`Ui\Components`) and its 34 components at `@CattoPHP/ui/`: layout (surface, section
  grid, section head, toolbar, breadcrumb, tabs), actions (link, button, group, row actions), forms (grid, field,
  actions, choice, feature-card choice, tree picker, compact action), data (table, dataset with its pager above and
  below and its search across every record, figures, card grid and card, lists, key-value list, person), feedback
  (badge, notice, empty state, progress), overlays (window, accordion section) and the icon. Templates draw them with
  `ui()`, `ui_template()`, `ui_props()` and `ui_field_attrs()` (`Twig\UiExtension`); a property is data, never a
  class, an attribute or rendered markup. A project adds its own components (`Ui\ComponentSet`, the service tag
  `cattophp.ui_component_set`) and the drawings it allows as a feature card's picture (`Ui\DrawingCheck`,
  `cattophp.ui_drawing`); `ui.icon_sprite` and `ui.public_root` name its icon sprite. An accordion's `workspace` is a
  word the project's script finds it by (`data-<word>-section`), and a button's `hook` likewise (`data-<hook>`).
- Added: checks a project runs over its own sources in its tests (`Ui\Contract\`): `TemplateContract` (each
  component's classes written only by its own template, and a table, a window or a progress bar only by its component;
  every button and button-link naming an icon; every icon named present in the sprite; a project adds its own classes'
  owners) and `SourceAudit` (no script writing component markup instead of cloning a prototype; no theme rule setting a
  component's layout, the project's own functional classes included; Stimulus actions whole).
- Added: the page frame at `@CattoPHP/page/`: the page head (`head.html.twig`: the page's only h1 with its kicker, lead,
  picture, badges and actions, set by the page as `ph_*` values), the identity band under it (`identity.html.twig`: who is
  signed in with their account reference, or the project's greeting and ways in for a reader signed out, from
  `identity_*` values) and a section's actions (`section-actions.html.twig`). The Twig function `icon_sprite()`.
- Added: lists: `Ui\Pagination` (a list's page from untrusted input, the approved sizes, the window of numbered pages),
  `Ui\Pager::payload()` (the pager's data, every link keeping the list's filters and size), `Ui\ListView` (a list as a
  table or a grid of cards, the choice remembered), and the templates for the view switch, a grid's sort and a sortable
  column heading at `@CattoPHP/ui/data/`.
- Added: pages that change in place and live pages: `@CattoPHP/page/in-place-attrs.html.twig` (what a GET form or link
  carries to fetch and morph the page's main area, the address following), `@CattoPHP/page/live-status.html.twig` (a
  live page's refresh every 15 or 30 seconds, with Pause) and `Http\LiveRefresh` (the refresh marked as no visit).
- Added: `Ui\TreeArrangement` and `Ui\TreeMoveRefused`: ordered tree moves with a depth limit (place, move, where a row
  may go), a moved row taking its whole subtree, never into itself; a refusal is a reason code each tree words itself.
- Added: `Ui\IconLibrary`, Lucide 1.70.0's line icons (ISC, `resources/icons/` with its licence) with their keywords: a
  search by words, the icons that fit a name (a project's subjects first: `ui.icon_subjects`, and words its names use that
  say nothing, `ui.icon_name_stop_words`) and a check that a chosen icon is the library's. The Twig function `lucide()`.
- Added: the components on the page, `public/css/cattophp.css`: the functional styling of every component, the page head,
  identity band and page sections, lists with their pager and search, trees, notices, fields and lookups, live status and
  the Bootstrap 6 safety net, built from the theme's `--cl-*` tokens; loaded after Bootstrap's reboot, grid and utilities
  and before the project's own stylesheet and its theme. Copied with the script by `bin/cattophp-assets`.
- Added: the components in the browser, `public/js/cattophp.js`: windows, menus and the narrow-screen navigation,
  messages that dismiss themselves, confirmations, the tree picker (`window.CattoTreeSelect`, which a project extends),
  feature cards that send their form, lookups, tables that fit their columns, the pager that fits its row, figures that fit
  their cards, a row's tooltip, hex colours, live pages that hold back while the reader is busy, forms that act on a change;
  a project's own script reaches `window.CattoPHP` (`ready`, `within`, `keepScrollerPlace`). `bin/cattophp-assets` (a
  Composer binary) copies CattoPHP's browser files into a project's web root under `cattophp/`; a project runs it after
  every install and update (`"post-install-cmd": ["@php vendor/bin/cattophp-assets public"]`).
- Added: the theme system (`Theme\`, on with the `theme` settings). `ThemeManager`: themes found in their folders (the
  project's own in its code, `theme.bundled`, the default, `theme.default`, read from there and never copied; installed
  ones in `theme.installed` as `<slug>-v<version>`, each release immutable), a package checked and staged
  (`theme.staging`) then installed, activated, previewed by someone who may (`resolve()`) and uninstalled, a child theme
  laid over its one exact parent, the browser files copied into `theme.public_root`'s `themes/` with fingerprinted
  addresses, and each change in the activity log. `PackageValidator` (theme package 4.0, template API 2.0: the manifest,
  a standalone theme's base with its blocks and the page's styles and scripts, a non-empty theme.css, page-family wrappers
  of the families the project names, `theme.page_families`, safe paths and file types, size limits; the format's name is
  the project's, `theme.package_format`), `Publisher`, `Templates` (Twig's `@theme`, a child's folders before its
  parent's, and a family's wrapper or the base), `Palettes` (five-colour palettes, darkest to lightest, each colour's
  readable foreground, the stylesheet they make). Which theme is active is kept by the project (`ThemeChoice`, the
  service `theme.choice`; `MemoryThemeChoice` for one run), and an optional index of the installed themes too
  (`ThemeRegistry`, `theme.registry`).
- Added: `Theme\PageRenderer`, a project's page drawn in its theme: the theme (with a preview for someone who may), the
  family's wrapper or the base or a layout of the project's own, the data checked (names a template can use; text,
  numbers, true or false and lists only), the theme's part of the view model and the project's, a fragment for htmx
  with the project's values, and the page as served (the project's generator tag first in the head, then laid out by
  `HtmlLayout`; on a development site `X-Served-Html: as-built` gets it as Twig drew it). The project answers through
  `Theme\PageProject` (the service `theme.pages`).
- Added: the payments module (`Payments\`, on with the `payments` settings): a project's payment gateways through Omnipay,
  each named in `payments.gateways` with its Omnipay gateway, its label and its parameters, each parameter held by a
  setting (named as in .env; a secret stored encrypted; optional or not), one settings group a gateway
  (`payment_<name>`), so the Settings page overrides .env with a Reset. `PaymentGateways::gateway()` gives the Omnipay
  gateway with its parameters and the current request (which a webhook's notification reads); `configured()` and
  `missing()` say whether it has what it needs. Omnipay (`omnipay/common`) is suggested, not required: a project that
  takes payments requires it with its gateway package.
- Added: `Ui\UploadedSvg`, an uploaded SVG checked to be a picture only and made fit for the page (an icon in the
  theme's colour, its styles and ids kept to itself); `Foundation\AccountReference`, account references with a check
  character, and the Twig filters `account_reference` and `share` (a percentage as a bar's length).

## 0.2.3 · 9 November 2026

A served page whose source can be read: every element holding others on lines of its own, indented by level, with
nothing a browser draws changed. Nothing to migrate. A project that wants it calls `HtmlLayout::document()` on the page
it has drawn; the new settings `html_layout.verbatim_classes` and `html_layout.flex_classes` are optional.

- Added: `Http\HtmlLayout`, which lays out a served page so its source can be read: every element that holds a block on
  lines of its own, one tab a level, with whitespace changed only where a browser draws nothing differently (a run of
  whitespace becomes another, and whitespace is added only beside or inside a block by HTML's default, or between the
  children of a container whose whitespace is never drawn); pre, textarea, script and style, a drawing's text and the
  project's authored HTML are served byte for byte; structured data is laid out; a page that does not nest cleanly is
  served as it came. A project calls `document()` on a whole page it has drawn; `html_layout.verbatim_classes` names the
  classes of elements holding its authored HTML, and the Twig filter `authored` marks authored HTML elsewhere while a
  page is being drawn for the layout (`begin()`, `end()`). `html_layout.flex_classes` names the classes the project's CSS
  always lays out as flex or grid, whose children (when they are elements and no words of their own) each go on a line of
  their own, since whitespace between and around a flex or grid container's children is never drawn.
  A page is laid out only when PHP's memory limit leaves room for it (about 28 bytes for each byte of the page); one
  without that room is served as it came, since running out of memory cannot be caught.

## 0.2.2 · 9 November 2026

A fix for counting visitors: a program drawing a shared link's preview is not a person. Nothing to migrate and no
setting changes.

- Fixed: WhatsApp's link-preview fetcher (`WhatsApp/2.x`), which names no bot, was read as a person's browser, so every
  preview of a page shared on WhatsApp counted as a visitor; it is now a crawler, labelled Link preview: WhatsApp, as are
  Iframely and Embedly.

## 0.2.1 · 9 November 2026

What a large application needed to keep its own look and a strict content policy: its error pages drawn in its own theme,
a page of its own allowed one more source, the browser's permissions and framing as it needs them, and its own email
design. Nothing to migrate: no schema step and no setting changes. A project that builds `Http\ErrorPageListener`
itself passes it an `Http\ErrorPageDrawer` (`Http\TwigErrorPageDrawer` is CattoPHP's own); the bundle does that for
every other project. The default Permissions-Policy no longer names interest-cohort.

- Added: a project draws the error page in its own look (`Http\ErrorPageDrawer`, `error_pages.drawer`): CattoPHP
  decides what it says (`Http\ErrorPage`: the kind, the words, the request's reference) and the project how it looks;
  CattoPHP's template is the default drawer (`Http\TwigErrorPageDrawer`).
- Added: `ContentSecurityPolicy::allow()`, one more source for one directive on the current page only (such as
  `form-action https:` on the page whose form leads to another site).
- Changed: the default Permissions-Policy no longer names interest-cohort, which browsers no longer know and Chrome
  reports as an error in every page's console.
- Added: `security_headers.permissions_policy`, a project's own (such as `microphone=(self)` for a page that records).
- Changed: X-Frame-Options follows the policy's frame-ancestors (SAMEORIGIN for `'self'`, for a project that shows its
  own pages in frames; none for anything else), and a response that sets X-Content-Type-Options, X-Frame-Options,
  Referrer-Policy or Permissions-Policy itself keeps what it set, as it already kept its own Content-Security-Policy.
- Changed: the mail transport is built again when the mail server's address changes in Settings, so a long-running
  worker uses the new one from its next email.
- Changed: a demo site's notice already in an email (a project's own email design may place it) is not added again,
  as the subject's [DEMO] already was not.

## 0.2.0 · 9 November 2026

What a large application needed to stand on CattoPHP: one that runs its own PHP sessions, keeps its sign-ins in the
database, has links of many purposes and roles of the owner's own. A project updating from 0.1 runs
`bin/console cattophp:migrate` (or its own migration that runs CattoPHP's steps): four new steps add the activity
lookups, the database's guard on SYSTEM permissions, the links that work once (taking in the sign-in links) and the
sign-ins kept in the database. A store of a project's own implements the new methods of `ActivityStore` and
`RoleStore`; `SignInLinkStore` is gone. An installation without a database keeps its links in `links.json` and its
sign-ins in `sessions.json` in its folder.

- Added: sign-ins kept in the database (`Sessions\SessionStore`, in the database, a file or memory; schema step
  `202611091007-sessions`): each by its cookie's hash, with the account and the address it signed in with, where it came
  from and in which browser, when it began, was last seen and runs out, and when it was ended; an account's sign-ins
  listed and ended one by one or all at once. CattoPHP's own sign-in pages do not use them yet.
- Added: links that work once, of every purpose (`Links\LinkStore`, in the database, a file or memory; schema step
  `202611091006-links`): each kept only as a hash, with what it is for, the address and the account it is for (a
  registration has no account yet), what it carries and where it was asked for from, so requests are counted for an
  address and for a place; one used once only even by two attempts at the same moment, a run-out or used one kept a week.
  Changed: sign-in links are the purpose `sign_in` among them; `SignInLinkStore` and its stores are gone, and an
  installation without a database keeps its links in `links.json` in its folder (a link in the old folder, which lasts
  minutes, is no longer read).
- Added: `SignInRules::mayUse()`, whether an address may sign in or register before it has an account, with
  `limited()`, `isListed()` and `addresses()`; `maySignIn()` is it for an account. Changed: a listed entry that is not an
  email address is left out, and an address listed twice is listed once.
- Added: `TwoStep::setUpAt()`, when an account's key was set up (every store keeps it; a file kept before has none),
  and `TwoStep::confirm()`, which says whether the app's code or a recovery code worked (`check()` is it as yes or no).
- Added: `Roles::rename()`, a role's key changed with its permissions and holders (`RoleStore::renameRole()`, which a
  store of a project's own now needs too); `Roles::refresh()`, for a process that lives on after a change elsewhere; a
  role's description, before and after, in the activity log when it is made or changed.
- Added: schema step `202611091005-access-boundary`: the database refuses a SYSTEM permission kept for any role.
- Added: `ActivityLog::removeBefore()`, for a log kept only so long, in every store (`ActivityStore::removeBefore()`,
  which a store of a project's own now needs too); the file store rewrites itself in place under the lock every entry
  is added under, so nothing added meanwhile is lost.
- Added: schema step `202611091004-activity-lookups`: one person's activity and one kind of activity are read newest
  first from their own index.
- A project that keeps Symfony's session off, because it runs its own PHP sessions, can load CattoPHP: its form
  protection is switched on only when the session is on, and a form token without one says what it needs instead of
  the container failing to build.
- A project's own template function, filter or global with a name CattoPHP also uses (such as `typed_for`) takes the
  place of CattoPHP's, instead of being silently replaced by it.
- A refused form's values show once, on the next page drawn: the page's form takes them, and whatever that page did
  not use is forgotten once it is sent (`TypedFormListener`), so nothing typed comes back on a later visit.
  `TypedForm::peek()` reads them without taking them. They wait in Symfony's session, or in the PHP session a project
  runs itself (`$_SESSION`) when Symfony's is off.
- `Settings::set()` saves one setting on its own, for a page that changes one value of a group.
- What another process saved is seen within `Settings::FRESH_FOR` seconds (5), so a scheduler or a worker that runs
  for hours follows the Settings page; `Settings::refresh()` reads afresh at once.
- Changed: a setting that may be blank (a list of addresses, an organisation) can be saved blank, and that blank
  overrides `.env` until Reset, as every other saved value does; a setting that cannot be blank (a number, a length of
  time, a choice) still takes its default when left blank.
- A project's own defaults for any setting (`cattophp.defaults`), used when `.env` and the Settings page give nothing;
  an unknown name is refused.
- Fixed: a field called `code` (a course's or a promotion's code) is kept when its form is refused. Sign-in codes,
  passwords, secrets and form tokens (`_token`, `csrf`) are still never kept.
- Changed: what CattoPHP gives every template is under one name of its own, `cattophp` (`cattophp.installation`,
  `cattophp.site_mode`, `cattophp.layout`), in place of the globals `installation`, `site_mode` and `cattophp_layout`,
  which took names a project's own templates may use. A template extending CattoPHP's layout writes
  `{% extends cattophp.layout %}`.
- `Schema\FoundationSteps`: CattoPHP's own schema steps in one list, for a project whose own migration tool runs them
  (a Phinx migration) without starting the application.

## 0.1.0 · 9 November 2026

The first version. Requires PHP 8.5.12 or later and Symfony 8.1. A project installs it from GitHub
(`"repositories": [{"type": "vcs", "url": "https://github.com/rrcatto/cattophp"}]`, `"rrcatto/cattophp": "^0.1"`),
registers the bundle and runs `bin/console cattophp:migrate` (README).

- The bundle (`CattoPHPBundle`, configured as `cattophp`): its records in PostgreSQL through Doctrine DBAL, or in
  files for a page without a database; whether the site is a demo or live; the layout its pages extend; starting
  values in place of `.env`'s; sign-in, security headers and error pages, each with its options.
- `Schema\SchemaRunner` and `bin/console cattophp:migrate` (`--dry-run`): CattoPHP's own tables, each step once, in
  order, in its own transaction, under a lock.
- `Settings\Settings`: the catalogue of what the Settings page may change (`SettingsProvider`), `.env` overridden
  with a Reset, a group saved together or not at all, every kind of value checked in plain words, secrets encrypted,
  blank secrets kept, every change in the activity log.
- `Installation\Installation`: the installation's name, organisation, address, administrator and time zone.
- `Runtime\RunMode` (`APP_ENV`, development unless it says production) and `Runtime\SiteMode` (`APP_SITE_MODE`, demo
  unless it says live, with the site's own words for a demo site).
- `Activity\ActivityLog`: every change with its actor, address and request reference, secrets masked, in the
  database or a file.
- `Access\`: permissions named `AREA.THING.ACTION` (`PermissionProvider`), roles, ADMIN holding every permission,
  `SYSTEM.` permissions for administrators alone, nobody taking ADMIN from themselves or from the last one, a voter.
- `SignIn\`: the emailed link (once, short-lived, a hash only, opening it uses nothing), who may sign in, limits on
  asking, two-step sign-in set up with a QR code and checked under a lock so a code works once, recovery codes, a
  sign-in that ends when its time is up or the account may no longer sign in, the pages and their routes, and
  `cattophp:sign-in-link` and `cattophp:two-step-reset`. On Symfony's security system.
- `Http\`: the error page in plain words with a request reference, the Content-Security-Policy with nonces and the
  other security headers, the form token, a refused form back as typed, the bot check and rate limits.
- `Mail\Mailer`: the configured transport, made by Symfony's own factory; the sender from Settings; a demo site's
  emails marked; `cattophp:test-email`.
- The Twig functions `csp_nonce()`, `form_token()` and `typed_for()`, the filter `local_time`, and the globals
  `installation`, `site_mode` and `cattophp_layout`.
- Building blocks: `Foundation\Duration` (lengths of time written one way: `3y`, `4w2d`, `1h30m`, `90s`),
  `Foundation\Base32`, `Security\SecretCipher` (secrets encrypted before they are stored, AES-256-GCM),
  `Security\KeyedHash`, and two-step sign-in's one-time codes (RFC 6238), recovery codes kept only as keyed hashes
  and the QR code drawn on the server.
- Packages: bacon/bacon-qr-code (BSD-2-Clause); Symfony's framework, security, Twig, mailer, rate limiter, console and
  clock components, Twig, Doctrine DBAL, psr/log and psr/clock (MIT).
