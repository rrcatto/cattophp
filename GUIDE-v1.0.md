# The CattoPHP Guide 1.0

**Version 1.0** · 13 November 2026, 12:43 SAST · Authored by Claude Opus 5.5

The guide to building a web application on CattoPHP: one document, given whole to a developer or an AI assistant at
the start of a project, saying what CattoPHP provides, the rules every project on it follows, how to start a project
from the starter and how to use each part. A project is started from this guide and the starter, never from a study of
another project's code: if something a project needs is not here, the guide is what gets fixed.

It describes CattoPHP as its code stands for version 1.0 (0.3.0 and the changes since, in its CHANGELOG) and the
starter 1.0.0. Where the guide and the code disagree, the code is right and the guide is corrected. It is kept under
150,000 characters, so it can always be given whole; newer versions replace it in CattoPHP's repository as
`GUIDE-v<version>.md`.

## Contents

1 What CattoPHP is · 2 The standing rules · 3 Starting a project · 4 Building on it · 5 The foundations · 6 The
interface system · 7 Themes · 8 The optional modules · 9 The checks · 10 Deploying · 11 Releasing

## 1. What CattoPHP is

### 1.1 Three parts

CattoPHP is a layer on Symfony, which already does routing, requests, dependency injection and the console well, not
a new framework. Its three parts:

1. **The package**, `rrcatto/cattophp` (namespace `CattoPHP\`, MIT): a Symfony bundle of the foundations every web
   application needs, the interface system its pages are drawn with, and optional modules switched on in
   `config/packages/cattophp.yaml`.
2. **The starter**, `rrcatto/cattophp-starter`: one command makes an application with the foundations working, a theme
   of its own, the scheduler with an example task, a Podman deploy kit, an install script and checks (3.4).
3. **This guide**: the rules and how to use each part.

### 1.2 The stack

- PHP 8.5.12 or later (it fixes two remote code execution flaws), with mbstring, OpenSSL and pdo_pgsql.
- Symfony 8.1 (framework, security, Twig, console, mailer); Twig with strict variables, a missing one an error, never an
  empty string.
- PostgreSQL 13 or later through Doctrine DBAL 4, which also serves a project with the Doctrine ORM (the starter has
  DBAL alone, one service).
- In the browser: htmx 4.0.1, Bootstrap 6.0.0-alpha.1's reboot, grid and utilities (no Bootstrap script), CattoPHP's
  stylesheet and script, and the theme's; no npm build.

### 1.3 What CattoPHP gives

The foundations, always there (section 5); the interface system, 34 components from one registry with the page frame,
lists, trees, icons and the theme system (6, 7); thirteen optional modules, each off until switched on (the list in
8.1); and in the starter the tooling: the Podman kit, the install script, `composer qa`, the browser checks and the gate
(9, 10).

### 1.4 What stays in each application

CattoPHP never names an application's concepts (a course, an order, a customer). The application's own stays there,
reaching CattoPHP only through an extension point (an interface with a service tag): its records, tables, repositories
and rules; pages, menus and words; accounts (`SignIn\SignInAccounts`); settings, permissions, tasks, setup steps and
components; document types, sitemap sources and campaigns; its look, as a theme.

### 1.5 Versions

CattoPHP has its own versions and changelog. A project requires one (`"rrcatto/cattophp": "^0.3"`, `^1.0` from 1.0.0),
so a change in CattoPHP reaches it only when it updates. Until CattoPHP is on Packagist, a project takes it from GitHub
at a version tag:

```json
"repositories": [{"type": "vcs", "url": "https://github.com/rrcatto/cattophp"}],
"require": {"rrcatto/cattophp": "^0.3"}
```

While both change together, a project may take CattoPHP from a folder beside it (a Composer path repository, mirrored,
`symlink: false`, as `dev-main`) on a branch of its own, as the starter does from `../cattophp` until CattoPHP 1.0, then
`^1.0` from GitHub (its CHANGELOG says so). With `"reference": "config"` the lock names it by a hash of its
`composer.json` and options, alike for any copy, so `composer update --lock` leaves it;
`composer reinstall rrcatto/cattophp` takes the folder's code, its install scripts publishing the browser files and
theme again. That is for development, never a release. Either way a project gets only the package's files (`bin`,
`config`, `public`, `resources`, `src`, `templates`, `composer.json`, `README.md`, `CHANGELOG.md`, the guide,
`LICENSE`), `.gitattributes` and `composer.json`'s `archive.exclude` leaving out the rest.

## 2. The standing rules

Every project on CattoPHP adopts these rules, through its `CLAUDE.md` (3.6), for every assistant and developer. A rule
holds for every page and part: a fault found in one place is fixed everywhere the pattern is, in the same change, with a
check so it cannot come back.

### 2.1 Nothing is done without the owner's word

The project's owner decides. Never without their word for that thing at that time: committing, tagging, pushing,
publishing a release or listing on Packagist; choosing a version; changing a server they have not named for the task;
removing, replacing or going around a Composer dependency (flag one that seems redundant and ask); changing `APP_ENV`
(2.5).

What the owner says while your questions are open is information: check and report, change nothing unasked. Background
about their own affairs is context, never a task. Ask only about genuine choices, in plain words with an example and A
or B with a recommendation, never slipping in a decision. Something nobody asked for that contradicts what a feature
obviously means is a defect: say so once, say where it came from, fix it.

### 2.2 The repository is public

CattoPHP's repository is public and MIT, as a project's usually is. Only the project's own files are committed:

- **Committed:** what the hook below allows: the code folders (`public/` without what Composer copies in, `resources/`
  for fonts and a document's assets), `composer.json`, `composer.lock` (an application's, never a library's),
  `.env.example`, `README.md`, `CHANGELOG.md`, `LICENSE`, `.gitignore`, `phpunit.xml.dist`, `phpstan.neon`.
- **Never committed:** `.env` and anything with a secret; `vendor/`, `var/` and what Composer or the console copies in
  (`public/cattophp/`, `public/themes/`); runtime data, caches, logs; the workspace's files: `CLAUDE.md`, `AGENTS.md`,
  plans, maps, reports, notes, `old/`.
- Files are added by name, never with `git add -A` or `git add .`. The workspace's own files go in `.git/info/exclude`;
  a pre-commit hook refuses anything outside the allowed list:

  ```sh
  #!/bin/sh
  # .git/hooks/pre-commit: only the project's own files may be committed.
  allowed='^(\.gitignore|\.env\.example|LICENSE|README\.md|CHANGELOG\.md|composer\.(json|lock)|phpunit\.xml\.dist|phpstan\.neon|(bin|config|deploy|install|public|resources|src|templates|tests|themes)/.+)$'
  refused=$(git diff --cached --name-only --diff-filter=ACMR | grep -Ev "$allowed")
  if [ -n "$refused" ]; then
      echo "REFUSED: not the project's own files:"
      echo "$refused" | sed 's/^/  /'
      exit 1
  fi
  ```

- Before each push, list what the commits hold (`git show --stat`, `git ls-files`), reading anything new outside the
  code folders (11.2).
- **Only generic content:** no person, business, domain, host, server address, workstation path or anything of an
  installation in the code, tests or documents (the owner's name only in `LICENSE`, `composer.json`'s authors, a theme's
  author line); examples use `example.com` and made-up names; an installation names itself through its settings. Commits
  are signed with the owner's no-reply address.

### 2.3 Versions and release notes

- A project's version is `composer.json`'s `version`; `CHANGELOG.md`, newest first, gets every change as made under
  `## Unreleased (1.2.0)`, which becomes `## 1.2.0 · 14 December 2026` on release. The release check (part of
  `composer qa`) fails when `README.md` or `CHANGELOG.md` does not name the version.
- Release notes are complete (11.1).
- A version is written with its package ("CattoPHP 0.3.0"); a dependency "requires CattoPHP ^1.0", never "runs 1.0".
- A theme's new version raises its third number (1.0.4 to 1.0.5) unless the owner chooses otherwise.
- A minor dependency update goes out with the next release, never alone; a security fix is raised with the owner at
  once.

### 2.4 Settings override `.env`

- `.env` gives each setting its starting value; the Settings page overrides any the catalogue allows, says where the
  value in force comes from (saved here, `.env` or the default) and has a Reset back to `.env`. Never a `.env` value
  that wins, or a list Settings cannot remove an entry from: nobody can tell such values by reading `.env`.
- `APP_ENV`, `APP_KEY` and the database's details are not settings; they stay in `.env`. The catalogue never offers a
  secret's value, a database, a host the code depends on or a safety switch.
- A saved secret is encrypted (`Security\SecretCipher`, AES-256-GCM from `APP_KEY`; a new `APP_KEY` loses them all),
  never written into a page. Every change is logged, a secret's without its values.
- A length of time a person types is written one way: `30m`, `1d`, `1h30m`, `5y` (5.15).

### 2.5 `APP_ENV` is development or production, and only the owner changes it

- `APP_ENV` is `production` only when it says production (either case), else `development`; debugging follows it; there
  is no `APP_DEBUG`.
- No installer, wizard, script, page or setting changes it: an installer keeps a site's value or offers `development`;
  only the owner sets `production` (10.3).
- Whether a site is real is `APP_SITE_MODE`: anything but `live` is demo, so no typing mistake makes a site live; a demo
  site says so everywhere (5.5).

### 2.6 Mail

Mailpit catches mail on a development machine, nowhere else. A server sends everything, test emails too, so they prove
what they say, through its configured transport: its own mail server (such as Postfix accepting mail from 127.0.0.1),
another SMTP server, or a smarthost. The starter's wizard refuses Mailpit.

### 2.7 Pages

- **Every page works without JavaScript**, as plain HTML; htmx 4 (and Stimulus, where a project adds it) only enhance.
  A form that changes something is a POST ending in a 303 redirect to a page.
- **htmx 4.** Its event names (`htmx:after:swap`); it swaps 4xx answers; content a script inserts goes to
  `htmx.process()` (it has no MutationObserver).
- **A strict Content-Security-Policy.** Scripts only from files on the site with the page's nonce (`nonce="{{
  csp_nonce() }}"`); no inline script, event-handler attribute (`onclick=`), inline style (`style=`, `<style>`) or
  `eval` in any template. A size from data is a class or an SVG attribute (`|share`).
- **One page construction** (6.3): the page head (the only `h1`, with the page's actions), the identity band, sections
  of cards, one width.
- **Components, not copies.** Cards, tables, fields, windows, notices and lists are drawn by their components (`ui()`),
  never a page's copy; a new reusable job is a project component, a variant goes into the component and its contract
  check. Scripts clone what the server drew, never writing component markup.
- **Themes paint, Core lays out.** Functional classes keep the `cl-` prefix; a theme paints them through the `--cl-`
  tokens, never changing their structure or layout.
- **In place by default; live pages refresh themselves.** A control changing what a page shows changes only its main
  area, the address following; live data refreshes itself, with Pause (6.5).
- **Forms:** one action row, the primary action first; every button names an icon; choices are feature cards
  (`form.picture-choice`), a tick's words saying what leaving it unticked does; a colour is a hex code typed with a
  swatch beside it, never a colour picker.
- **Sibling sections, same controls**: a List or Grid switch or a pager laid out alike in each section.
- **One page about one thing**: an Administration page about a record shows all that is pertinent (facts in the head as
  badges, its lists, its activity), its actions opening and returning there.
- **Everything recorded is shown**: a log or trail kept for the owner has a page with filters, search, sort, pages.
- **The owner manages from the web app**: every owner task has a page; console commands are for scripts, tests and
  emergencies, no page telling the owner to run one.
- **The served page is readable** (`Http\HtmlLayout`: a block a line, a tab a level); judge it, not the template.

### 2.8 Words

- What a person reads is plain British English with hyphens and straight quotes: no em dash, an en dash only between the
  two numbers of a range, as CattoPHP's pager writes "1–25 of 30" (its search box says "Searching…"). No internal names,
  table names or code words on a page or in a question to the owner.
- An error says what happened and what to do next. Someone else's record is not found, never "it belongs to someone
  else"; one who manages it is taken to it in Administration.
- A visitor held or flagged by a security rule learns nothing of why; a bot caught by a trap gets the usual answer,
  nothing sent; each attempt is recorded for the owner. A refusal gives its reason only where that reveals nothing of
  the defences ("Use an email address you keep").
- A length of time is in words ("1 hour 30 minutes"), never abbreviated ("mins") or zero-padded; times are in the
  installation's zone.
- The owner's terms are used exactly; never invent a role, a thing or a name.

### 2.9 Addresses name things

Public pages by name (a slug); signed-in pages by a record's recognisable code (an account reference, a role's key, a
`Foundation\ReadableCode`); a random public id where the public could reach something private; never a guessable row
number, which stays in the code (9.3).

### 2.10 Forms communicate state

- A form shows the state it holds. A saved secret shows `************` with "Leave the asterisks to keep it, or type
  a new one", never an empty field (saying nothing is saved) or dots; CattoPHP's Settings page puts them in the
  placeholder, the value empty, so a group saved untouched keeps the secret (5.11).
- A connection to an outside service exists only once a real try made it. Until then (never tried, or failed) it is a
  form with Check the connection and what went wrong, no Drop connection, nothing retrying in the background. Connected,
  the details are text with a Connected badge and Drop connection, which forgets them (`.env`'s too, until a Reset);
  details changed since count as unchecked (8.15).
- A refused form comes back as typed, once, with why and what to do beside each field it blames (5.12).

### 2.11 Documents

- Workspace documents (plans, reports, notes) and saved versions of files (exports, backups) are named
  `YYYYMMDD-HHMM-name`, the newest in force, older ones moved to `old/` unchanged. `README.md`, `CHANGELOG.md`,
  `CLAUDE.md` and `AGENTS.md` keep their names; a public versioned document carries its version (`GUIDE-v1.0.md`).
- Each opens with its version, date and time (SAST) and author ("**Version 1.2** · 14 December 2026, 09:30 SAST ·
  Authored by ..."), bumped for a change of substance; a rewrite keeps the header, saying which version it replaces.
- Each stays under 150,000 characters, to be given whole: what stops being true is changed or taken out, history
  archived.
- **The plan**, `YYYYMMDD-HHMM-<project>-plan.md` beside the code: what the application is for and what is to be built,
  in order, each step with its checks; the newest in force (`CLAUDE.md` sends assistants to it first), older in `old/`.
  Never committed: `.git/info/exclude` holds `/CLAUDE.md`, `/AGENTS.md`, `/*-plan.md` and `/old/`.

### 2.12 Checks

- **Checks write only real records:** nothing a real person could not have produced, made through a person's workflows
  (the services the pages call), never inserted rows or random bulk data.
- **Realistic and unique:** believable names, never repeated, without digits or codes.
- **Checks remove what they make:** a run leaves a shared database as found, deleting an account as the application does
  (`AccountRecords::forget()`, 5.6); checks emptying a database get their own (`DB_TEST_NAME`).
- **A check measures what a person sees:** one that passed with a fault visible is changed, proved to fail on the old
  code.
- **The right checks at the right time** (9.6), each judged by its exit code; a changed page looked at desktop and phone
  width.

### 2.13 How the code is written

- Thin controllers, services owning the rules, repositories the SQL; constructor injection, no service locator.
- HTML drawn on the server; no frontend framework or build step unless the owner agrees.
- A value class under `src/` is excluded in `config/services.yaml` in the same change, or the container fails.
- A settings provider never depends on `Settings`, or the catalogue builds itself endlessly.
- A schema change is a step of its own (5.14); a released step never changes; no server schema changes by hand.
- Configuration is never read by `parse_ini_file()` or `parse_ini_string()`.
- Every feature does what its name obviously means, visibly, with its obvious siblings (a person told at each step
  involving them).

### 2.14 Dependencies

When building work starts and before each release: `composer audit`, `composer outdated --direct`, the bundled browser
files (htmx, Bootstrap, the icon library) against their registries (the starter's `composer deps`, `bin/deps`, reports
all but the icons: exit 0 clear, 1 an advisory, 2 advisories unread; it needs the network; the gate skips it); tell the
owner what is pending (package, installed, available, why it matters). A new version is confirmed at its own source
(php.net, Packagist, npm's `latest`, its GitHub releases), never by container tags, which carry release candidates; a
download is checked by its integrity hash.

## 3. Starting a project

### 3.1 What a development machine needs

- PHP 8.5.12 or later on the command line (1.2).
- Composer 2 and Git.
- PostgreSQL 13 or later, with two databases: the site's, and one the tests empty and fill.
- Mailpit (or another mail catcher) listening for SMTP, catching every email the site sends on this machine.

### 3.2 The one command

```sh
composer create-project rrcatto/cattophp-starter my-app \
    --repository='{"type":"vcs","url":"https://github.com/rrcatto/cattophp-starter"}'
```

`--repository` is needed until the starter is on Packagist, then dropped. Before its first tag, the command takes a
folder of it instead: `--repository='{"type":"path","url":"<folder>","options":{"symlink":false}}'`, `<folder>` a path
from where the command runs. Until CattoPHP 1.0 the starter takes it from `../cattophp`, so the project is made beside
`cattophp/`; from 1.0 from GitHub, `^1.0`, anywhere.

Composer then:

1. installs the packages, CattoPHP among them (until CattoPHP 1.0 from `../cattophp` beside the project, 1.5; then
   `^1.0` from GitHub);
2. makes `.env` from `.env.example` with a new random `APP_KEY` (64 hex characters), readable by its owner alone
   (`bin/make-env`; an existing `.env` never touched, no other value changed, `APP_ENV` included);
3. copies CattoPHP's browser files into `public/cattophp/` (`vendor/bin/cattophp-assets public`);
4. installs the starter's theme, publishing its browser files into `public/themes/` (`bin/console
   cattophp:themes:install`).

Steps 2 to 4 run again after each Composer install and update.

### 3.3 The first steps

1. **The databases:** two PostgreSQL databases and a user, in `.env`: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`,
   `DB_PASSWORD`, `DB_TEST_NAME` (blank: `DB_NAME` with `_test`; emptied by the tests, so never the site's).
2. **The installation,** in `.env`: `APP_NAME` (quoted with a space: `APP_NAME="Example Notes"`), `APP_URL`
   (`http://127.0.0.1:8000`), `APP_ADMIN_EMAIL`, `APP_TIMEZONE`, `MAILER_DSN` (Mailpit: `smtp://127.0.0.1:1025`),
   `MAIL_FROM_ADDRESS`; leave `APP_ENV=development`.
3. **The tables:** `bin/console cattophp:migrate` (`--dry-run` lists what it would do), then
   `bin/console cattophp:modules`, ending "Every module switched on has what it needs."
4. **Serve it:** `php -S 127.0.0.1:8000 -t public`, or a web server with `public/` as root, running only `index.php`.
5. **Sign in** at `/sign-in` with the administrator's address; the link arrives in Mailpit (or
   `bin/console cattophp:sign-in-link admin@example.com` prints one). The account, made when the link is used, has the
   Administrator role and sets up two-step (`AUTH_TWO_STEP=ADMINS`): scan the QR code, type the code, keep the eight
   recovery codes shown once.
6. **The scheduler:** `bin/console cattophp:schedule:run` every minute from cron, or `--watch` under a supervisor.
7. **The checks:** `composer qa` (9) passes before anything is called done.

### 3.4 What the starter holds

```text
bin/  console, make-env, release-check, gate and gate-support (9.5), deps (2.14)
config/packages/  cattophp.yaml (foundations, theme and scheduler on; every module listed, off), framework.yaml
  (the session in files; Symfony's mailer null), security.yaml, twig.yaml (strict; templates/ is @platform)
config/services.yaml  every class in src/ a service, autowired; the one DBAL connection; the browser checks' names
deploy/podman/, install/  the deploy kit and the install script (section 10)
public/  index.php, css/app.css (the application's own), img/icons.svg (the sprite), vendor/ (Bootstrap, htmx)
src/Account/  the accounts behind CattoPHP's sign-in (joining, deleting, references; 6.4)
src/Activity/  the example task and the words for the application's own actions (ApplicationActionWords)
src/Controller/  Home, Your account, Administration (linking CattoPHP's pages) and its Accounts, PageController
src/Schema/  the application's schema steps
src/Settings/  the application's own settings group
src/View/  StarterPages (the PageProject, with every page's family in FAMILIES), Icons
templates/  page.html.twig (every page's frame), pages/ (home, account, admin, admin-accounts, admin-account), partials/
themes/starter/  the starter's theme, plain and meant to be replaced
tests/  Application, Contracts and Deploy suites; Browser (9.4), with checks/ and cleanup.php
```

The rest is CattoPHP's, imported in `config/routes.yaml`, drawn in the theme, no template overridden: signing in, the
error page, Settings, Roles and the Activity log (5.11), Sign-in and security (5.8, sessions on). The menu: Home, Your
account, Sign-in and security, and Administration for those it allows; an Administration page of the application's own
goes into `StarterPages::ADMINISTRATION`, which the menu and the Administration page read.

### 3.5 Making it the project's own

In one change, `composer qa` passing after it (as the starter's README, "Making it your own", lists); the kit and the
install script, each fetched alone onto a server, name the application themselves.

1. `git init`, the pre-commit hook and `.git/info/exclude` (2.2), nothing committed until the owner says so;
   `CLAUDE.md`, `AGENTS.md` (templates below) and the first plan (2.11) beside the code, never committed. `git mv` moves
   only added files: the renames below are plain `mv`.
2. `composer.json`: `name` (`example/notes`), `description`, `version`, `authors`; then `composer update --lock`, which
   only rewrites the lock's hash (CattoPHP from `../cattophp` is named by its `composer.json`, 1.5). `README.md` becomes
   the project's (what it is, how to install it, its settings), `CHANGELOG.md` starts at `## Unreleased (0.1.0)`,
   `LICENSE` names its holder. `cattophp.defaults.APP_NAME` is the name when neither `.env` nor Settings gives one.
3. The theme: `mv themes/starter themes/<slug>`; its `theme.json` gets a `name` and `slug` of its own (the project's or
   the theme's), `version` 1.0.0, today's `created_at`, the project's `author`, a `description`; set
   `theme.default: <slug>`, delete `public/themes/starter-v1.0.0/` and run `bin/console cattophp:themes:install`
   (section 7).
4. The kit: `mv deploy/podman/starter-podman.sh deploy/podman/notes-podman.sh`, `APP="notes"` and
   `REPO="https://github.com/example/notes.git"` at its top, the same two lines in `install/install.sh` (the deploy
   checks fail until they agree). `APP` (a small letter, then small letters, digits, hyphens) names folders, pod,
   service and image; for `tool-library` the wizard offers the database `tool_library_<username>`. Then the examples in
   `deploy/podman/README.md` and `install/README.md`: the script's name, raw GitHub addresses,
   `/usr/local/lib/php/starter/<username>`, `starter_<username>`, the `APP=` and `REPO=` block under "A project of its
   own" and the kit test's `/tmp/kit-test/starter/`.
5. Optionally `App\View\StarterPages` (named in `theme.pages`, the two controllers and `TemplateContractTest.php`; each
   new page's family in `FAMILIES`) and the tests' installation in `phpunit.xml.dist` (`APP_NAME`, `MAIL_FROM_ADDRESS`).
6. The home page becomes the project's own (a title equal to the name is written once); `.env.example` gains its
   settings under "The application's own".
7. Then `grep -rniI starter --exclude-dir=vendor --exclude-dir=var .` and change what is left but comments in `tests/`
   on what came from the starter and `EnvWizardTest`'s `--app=starter` fixture.

The starter's tests find the theme, name and home heading themselves and check only its own task, tables, role and
modules: they pass unchanged (9.1).

### 3.6 The project's `CLAUDE.md`

Copy it whole, replace each `<...>`, keep only what is true: the standing rules, the project's decisions added under
"The project's own" as made, each with the owner's words and the date.

````markdown
# CLAUDE.md

Working rules for <Project>, for Claude Code and any other assistant. Before changing code, read the newest
`*-plan.md`, then `AGENTS.md` (its latest checkpoint is current), then CattoPHP's guide,
`vendor/rrcatto/cattophp/GUIDE-v<n.n>.md` (the rules every project follows, and how to use CattoPHP). Keep this file
under 150,000 characters; change or remove what stops being true, history in `old/`.

## Where things are

- The code: this folder, a Git repository on `<branch>`, version <x.y.z> (`composer.json`), requiring CattoPHP `<^x.y>`;
  a change CattoPHP should make is made there, released at the owner's word, taken by updating.
- Modules on in `config/packages/cattophp.yaml`: <the scheduler, ...>.
- The development site: `<http://127.0.0.1:8000>`, databases `<name>` and `<name_test>`, mail in Mailpit.

## Nothing without the owner's word

- Commit, tag, push, release, list on Packagist, choose a version or change a server only at the owner's word, in that
  turn. Commits are signed `<owner> <<no-reply address>>`. Never remove or replace a Composer dependency unasked.
- What the owner says while your questions are open is information: check, report, change nothing unasked.
- Ask only about genuine choices, in plain words, an example, A or B; a restriction nobody asked for is a defect.

## The repository is public

- Only the project's own files are committed, by name (never `git add -A`), as the pre-commit hook allows (CattoPHP's
  guide, 2.2); never `.env`, a secret, `vendor/`, `var/`, `public/cattophp/`, `public/themes/`, runtime data, caches
  or logs. This file, `AGENTS.md`, plans, reports, notes and `old/` stay out (`.git/info/exclude`). Before a push, read
  what the commits hold (`git show --stat`).
- Only generic content and placeholders: no person (the owner's name only in `LICENSE`, `composer.json`), business,
  domain, host, server address or workstation path; examples use `example.com` and made-up names.

## Versions and release notes

- Every change goes under `## Unreleased (x.y.z)` as made (the release check fails when `README.md` or `CHANGELOG.md`
  does not name `composer.json`'s version); release notes complete, checked against the diff before tagging (CattoPHP's
  guide, 11.1); a version always written with its package ("<Project> 1.2.0", "CattoPHP 0.3.0").

## How it is built

- PHP 8.5.12+, Symfony 8.1, strict Twig, PostgreSQL through Doctrine DBAL, CattoPHP; thin controllers, services own the
  rules, repositories the SQL. Settings over `.env` with a Reset, secrets encrypted; `APP_ENV` the owner's alone;
  `APP_SITE_MODE` demo or live; Mailpit on a development machine only.
- Pages work without JavaScript (htmx 4 enhancing) under a strict Content-Security-Policy, built from CattoPHP's
  components (page head, identity band, cards), the theme painting `cl-` classes, never laying them out; in place by
  default, live pages refreshing with Pause. Forms: a saved secret shows `************`; a connection exists once a real
  try made it; choices are feature cards; colours hex codes; every button names an icon.
- Words: plain British English, hyphens, straight quotes, no em dash, an en dash only in a range; errors say what
  happened and what to do; another's record is not found; a held visitor learns nothing.
- Addresses by name in public, by a recognisable code signed in, never a row number. A schema step in `src/Schema/` per
  change, never changed once released.
- The owner manages everything from the web app. The rest of the standing rules: CattoPHP's guide, section 2.

## The project's own

- <What the application is for, in two sentences.>
- <Its records and their rules.>
- <Each decision the owner makes, with their words and the date.>

## Checks

- `composer qa` (the tests on `DB_TEST_NAME`, never the site's database, the contract and deploy checks, PHPStan level
  8, the release check), and `bin/gate` before a release; while building, the checks relevant to the change. Judge a
  check by its exit code; look at a changed page at desktop and phone width.
- Checks write only real records, made through the workflows, with realistic unique names, removing what they make.
- `composer audit` and `composer outdated --direct` when building work starts and before a release; tell the owner.

## Documents

- Named `YYYYMMDD-HHMM-name.md`, the newest in force, older ones in `old/`; each opens with its version, date and time
  (SAST) and author, bumped for a change of substance, and stays under 150,000 characters.
````

### 3.7 The project's `AGENTS.md`

````markdown
# AGENTS.md

Where <Project>'s work stands, newest last (rules in `CLAUDE.md`, the plan the newest `*-plan.md`). Each checkpoint: a
dated heading, what was done, how it was checked, what was decided and by whom (the owner's words), what is next; the
latest alone is current. Under 150,000 characters: older checkpoints go to `old/AGENTS-ARCHIVE.md`.

## <YYYY-MM-DD>: the project begins

- Made from the CattoPHP starter <1.0.0> (`composer create-project rrcatto/cattophp-starter <folder>`), requiring
  CattoPHP <^x.y>.
- Renamed: `composer.json` (`<vendor/name>`, version <x.y.z>), the theme (`themes/<slug>/`), the kit
  (`deploy/podman/<app>-podman.sh`), the installer.
- Databases `<name>` and `<name_test>`; `cattophp:migrate` applied <N> steps; `cattophp:modules` OK.
- `composer qa` passes: <N> tests, the contract and deploy checks, PHPStan level 8, the release check.
- Signed in as the administrator through Mailpit; two-step set up.
- Nothing committed yet. Next: <the first piece of the application's own>.
````

## 4. Building on it

How a project adds its own pages, records, settings, permissions, background work and icons, as the starter does; 5 to 8
describe the parts.

### 4.1 The kernel and the environment

`public/index.php` and `bin/console` call `Kernel::loadEnvironment()` (Dotenv, never overriding the process's values),
then `new Kernel(RunMode::environment(), RunMode::debug())`. Every class in `src/` is an autowired, autoconfigured
service, tagged when it implements one of CattoPHP's interfaces (value classes under `exclude`). The database is one
`Doctrine\DBAL\Connection` from `DB_*`, connecting at the first query, shared by CattoPHP and the repositories.

### 4.2 A page

A page is a controller action and a template:

```php
namespace App\Controller;

use Symfony\Component\HttpFoundation\{Request, Response};
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class NotesController extends PageController
{
  #[Route('/notes', name: 'notes', methods: ['GET'])]
  #[IsGranted('NOTE.VIEW')]
  public function list(Request $request, \App\Note\Notes $notes): Response
  {
    return $this->page($request, 'notes', [
      'title' => 'Notes',
      'lead' => 'Every note, newest first.',
      'notes' => $notes->listed(),  // a list of arrays of words and numbers, never objects
    ]);
  }
}
```

The starter's `PageController` gives every page the same ways: `page($request, '<page>', $data)` draws
`templates/pages/<page>.html.twig` in the theme with the notices waiting (data of text, numbers, true or false, null,
lists of them, never an object: 7.4); `tokenIsValid()`, `expired()`; `done($message, $route)` and
`refused($request, $message, $route, $parameters, $fragment, $errors)`, each a 303 redirect with a notice, the second
keeping what was typed and why each field was refused (`$errors` a map by field or a `FieldsRefused`, 4.3; a reason
under `''` is the notice, `$message` following only when fields are blamed too); `reader()` (the `SignInUser`),
`actor()` (as the activity log names it).

The template extends the project's frame, filling `page_main` (and `page_actions`, the page's actions):

```twig
{% extends '@platform/page.html.twig' %}
{% block page_actions %}{{ ui('action.link', {label: 'New note', href: path('note_new'), icon: 'action-add', variant: 'primary'}) }}{% endblock %}
{% block page_main %}
<section class="cl-page-section">
{% embed ui_template('layout.surface') with {props: ui_props('layout.surface', {labelledby: 'notes-title'})} %}{% block surface_body %}
{{ ui('layout.section-head', {heading: 'Your notes', heading_id: 'notes-title'}) }}
{% if notes %}
{% embed ui_template('data.table') with {props: ui_props('data.table', {caption: 'Every note, newest first'})} %}
{% block header %}<tr><th scope="col">Title</th><th scope="col">Written</th></tr>{% endblock %}
{% block body %}
{% for note in notes %}
<tr><td><a href="{{ note.href }}">{{ note.title }}</a></td><td>{{ note.written }}</td></tr>
{% endfor %}
{% endblock %}
{% endembed %}
{% else %}
{{ ui('feedback.empty', {heading: 'No notes yet', summary: 'Press New note to write the first.'}) }}
{% endif %}
{% endblock %}{% endembed %}
</section>
{% endblock %}
```

`templates/page.html.twig` draws CattoPHP's page head (from `title`, `lead`, `page_actions`), identity band, notices,
then `page_main`.

### 4.3 A form

The form posts to its own route, carries the token, and draws each field with `form.field`; a refused form comes back
as typed (`TypedForm`), with why beside the field it blames:

```php
#[Route('/notes/new', name: 'note_new', methods: ['GET'])]
#[IsGranted('NOTE.EDIT')]
public function new(Request $request): Response
{
  $typed = \CattoPHP\Http\TypedForm::take($request, $this->generateUrl('note_make'));

  return $this->page($request, 'note-new', [
    'title' => 'New note',
    'typed' => ['title' => (string) ($typed['title'] ?? ''), 'body' => (string) ($typed['body'] ?? '')],
    'errors' => \CattoPHP\Http\TypedForm::errors($request, $this->generateUrl('note_make')),  // by field name
  ]);
}

#[Route('/notes', name: 'note_make', methods: ['POST'])]
#[IsGranted('NOTE.EDIT')]
public function make(Request $request, \App\Note\Notes $notes): Response
{
  if (!$this->tokenIsValid($request)) {
    return $this->expired($request, 'note_new');
  }
  try {
    $code = $notes->write((string) $request->request->get('title', ''), (string) $request->request->get('body', ''), $this->actor());
  } catch (\CattoPHP\Http\FieldsRefused $refused) {
    return $this->refused($request, 'The note was not saved: see why beside each field.', 'note_new', errors: $refused);
  }

  return $this->done('The note is saved.', 'note', ['code' => $code]);
}
```

```twig
<form method="post" action="{{ path('note_make') }}">
<input type="hidden" name="_token" value="{{ form_token() }}">
{% embed ui_template('form.grid') with {props: ui_props('form.grid', {})} %}{% block body %}
{% embed ui_template('form.field') with {props: ui_props('form.field', {label: 'Title', for: 'note-title', required: true, error: errors.title|default('')})} %}{% block control %}<input class="cl-input" id="note-title" type="text" name="title" value="{{ typed.title }}" maxlength="120" required {{ ui_field_attrs(props) }}>{% endblock %}{% endembed %}
{% embed ui_template('form.field') with {props: ui_props('form.field', {label: 'What it says', for: 'note-body', full_width: true, error: errors.body|default('')})} %}{% block control %}<textarea class="cl-input" id="note-body" name="body" rows="6" {{ ui_field_attrs(props) }}>{{ typed.body }}</textarea>{% endblock %}{% endembed %}
{% endblock %}{% endembed %}
{% embed ui_template('form.actions') with {props: ui_props('form.actions', {})} %}{% block primary_actions %}{{ ui('action.button', {label: 'Save the note', type: 'submit', icon: 'action-save', variant: 'primary'}) }}{% endblock %}{% endembed %}
</form>
```

The service checks the whole form and throws `Http\FieldsRefused` with each field's why in plain words (`new
FieldsRefused(['title' => 'Type a title.', 'body' => 'Keep it under 5,000 characters.'])`); the controller never decides
a business rule. `refused(..., errors: $refused)` (4.2) keeps the errors with the values; `form.field`'s `error` draws
one under its field, `ui_field_attrs(props)` giving the control `aria-describedby` and `aria-invalid`; a template may
read them with `typed_errors_for(path('note_make'))`. A feature-card choice takes `error` too (6.2) and goes inside the
`form.grid` (`columns: 1` to span it), spaced by its gap, not after it, as on CattoPHP's role pages. The starter's
example is its account name form (`Accounts::rename()` throws one).

### 4.4 Records

A table is a schema step in `src/Schema/`, autoconfigured, applied by `cattophp:migrate` with CattoPHP's own, all in
the order of their ids:

```php
namespace App\Schema;

final class Step202612011030Notes extends \CattoPHP\Schema\SqlStep
{
  public function id(): string { return '202612011030-notes'; }
  public function describe(): string { return 'The application\'s notes'; }

  protected function statements(): array
  {
    return [
      'CREATE TABLE notes (
        id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
        code text NOT NULL UNIQUE CHECK (code ~ \'^[a-hjkmnp-z2-9]{8}$\'),
        account_id bigint NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
        title text NOT NULL CHECK (title <> \'\' AND length(title) <= 120),
        body text NOT NULL,
        written_at timestamptz NOT NULL
      )',
      'CREATE INDEX notes_account ON notes (account_id, written_at DESC)',
    ];
  }
}
```

The id starts with the date and time the step was written (`YYYYMMDDHHMM-what-it-does`, the class
`StepYYYYMMDDHHMMName`). Each step runs in its own transaction, a failure leaving nothing; a released step never
changes, a later change being a new step for a database holding data. The repository takes the
`Doctrine\DBAL\Connection` and writes SQL with parameters (`src/Account/Accounts.php`); a record a public page names has
a slug, one a signed-in page names a code (2.9) from `Foundation\ReadableCode::make()` (5.15), made again when the
unique index refuses one. A project may keep its tables with another tool (Phinx, Doctrine Migrations), never touching
CattoPHP's (5.14).

### 4.5 Settings of the project's own

```php
namespace App\Settings;

use CattoPHP\Foundation\Duration;
use CattoPHP\Settings\{Setting, SettingGroup, SettingsProvider, SettingType};
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(priority: -100)]  // after CattoPHP's groups on the page
final class NoteSettings implements SettingsProvider
{
  public function settingGroups(): iterable
  {
    yield new SettingGroup('notes', 'Notes', 'How long notes are kept, and who they are shared with.', [
      new Setting('NOTES_KEPT_FOR', 'Kept for', SettingType::Duration, '1y', Duration::HOW, shortest: '1d'),
      new Setting('NOTES_SHARED_WITH', 'Shared with', SettingType::Paragraph, optional: true,
        help: 'Email addresses, separated by commas.'),
    ]);
  }
}
```

The value in force is `$settings->get('NOTES_KEPT_FOR')` (or `int()`, `bool()`, `duration()`) on
`CattoPHP\Settings\Settings`. Each new setting goes into `.env.example` with a line saying what it is; a provider never
takes `Settings` (2.13); 5.3 lists types and arguments.

### 4.6 Permissions of the project's own

```php
namespace App\Note;

use CattoPHP\Access\{Permission, PermissionProvider};

final class NotePermissions implements PermissionProvider
{
  public function permissions(): iterable
  {
    yield new Permission('NOTE.VIEW', 'Notes', 'Read the notes.');
    yield new Permission('NOTE.EDIT', 'Notes', 'Write and change notes.');
    yield new Permission('SYSTEM.NOTE.PURGE', 'Notes', 'Remove every note at once.');
  }
}
```

A route asks for a permission with `#[IsGranted('NOTE.VIEW')]`, a template with `is_granted('NOTE.VIEW')`, a service
with `Roles::may($accountId, 'NOTE.VIEW')`; code never asks for a role, which grants permissions on the Roles page. A
`SYSTEM.` key is administrators' alone.

**What every signed-in person may do asks for no permission** (no role is everyone's). A page every member may open
relies on sign-in alone, which the starter's `access_control` requires (`^/` needs `ROLE_USER`, everyone signed in
holding it); a route outside it asks `#[IsGranted('IS_AUTHENTICATED')]`. Each person still sees only their own records.

**A role the application needs from the start** (an administrator only gives it) is made by a schema step of its own
calling `Roles::save()`: a service taking `Roles` in its constructor, applied once per database in its transaction on
the same connection, recording `access.role_made`; the role is then the administrators' to change or delete. Its
permissions come from the catalogue (never `SYSTEM.`), its id sorting after CattoPHP's roles step (any date from
2026-11-10):

```php
final class Step202612011100EditorRole implements \CattoPHP\Schema\SchemaStep
{
  public function __construct(private readonly \CattoPHP\Access\Roles $roles) {}
  public function id(): string { return '202612011100-editor-role'; }
  public function describe(): string { return 'The Editor role'; }
  public function apply(\Doctrine\DBAL\Connection $connection): void
  {
    $this->roles->save('EDITOR', 'Editor', 'Writes and changes notes.', ['NOTE.VIEW', 'NOTE.EDIT'], \CattoPHP\Activity\Actor::system());
  }
}
```

### 4.7 Background work

```php
namespace App\Note;

use CattoPHP\Scheduler\ScheduledTask;
use DateTimeImmutable;

final class NoteCleanupTask implements ScheduledTask
{
  public function __construct(private readonly Notes $notes, private readonly \CattoPHP\Settings\Settings $settings) {}

  public function key(): string { return 'note_cleanup'; }
  public function label(): string { return 'Note clean-up'; }
  public function description(): string { return 'Every night: removes notes older than Settings keeps them for.'; }
  public function interval(): int { return 86400; }
  public function runsAt(): ?string { return '02:30'; }  // H:i in the site's time zone, or null
  public function overdueAfter(): int { return 2 * 86400; }

  public function run(DateTimeImmutable $now): string
  {
    $kept = $this->settings->duration('NOTES_KEPT_FOR');
    $removed = $this->notes->removeBefore($kept->before($now));

    return sprintf('Removed %d %s older than %s.', $removed, $removed === 1 ? 'note' : 'notes', $kept->words());
  }
}
```

Found as a `ScheduledTask`, it returns what it did in words, or throws; each run is recorded (8.2).
`--job note_cleanup --now "+3 days"` tries it as at another moment on a development site, kept apart from its runs.

### 4.8 Icons

A page names an icon by meaning: `action-` for a button's action, `nav-` for where a menu entry goes, `page-` for a
pager's control. The starter's `src/View/Icons.php` maps each name to a Lucide icon:

```php
public const SYMBOLS = [
  'action-add' => 'plus',
  'action-save' => 'save',
  'nav-notes' => 'notebook-pen',
  // ...
];
```

Find a name with `bin/console cattophp:icons:search <words>... [--limit=30]` (icons matching every word, best first,
with their tags, `IconLibrary::tags()`; exit 1 for none; `ladder` gives `waves-ladder`, `door-stairwell`), add a line,
run `bin/console app:icons` (rewriting `public/img/icons.svg`); the contract checks fail while a template names an icon
the sprite lacks, or the sprite is not as the command writes it.

### 4.9 Emails of the project's own

An email is a Twig template with blocks `subject`, `text` and optionally `html`, sent by CattoPHP's mailer:

```twig
{# templates/email/note_shared.txt.twig #}
{% block subject %}A note was shared with you{% endblock %}
{% block text %}
{{ sender }} shared the note "{{ title }}" with you on {{ installation.name() }}.

Open it: {{ link }}
{% endblock %}
```

```php
$mailer->sendTemplate('anna.smith@example.com', 'email/note_shared.txt.twig', ['sender' => $name, 'title' => $title, 'link' => $url]);
```

Words alone are `.txt.twig` (nothing escaped as HTML); with an `html` block, `.html.twig`, its `text` block writing
`|raw` where a value may hold an ampersand. A failure throws `Mail\MailNotSent`, in plain words.

## 5. The foundations

### 5.1 Using CattoPHP in a project, and its configuration

The starter does all this; a project adopting CattoPHP later does it by hand: require it (1.5); run
`@php vendor/bin/cattophp-assets public` in `post-install-cmd` and `post-update-cmd`; register
`CattoPHP\CattoPHPBundle::class => ['all' => true]` in `config/bundles.php`; configure `config/packages/cattophp.yaml`
(below) and `security.yaml` (5.6); import the sign-in pages in `config/routes.yaml` (`cattophp_sign_in: { resource:
'@CattoPHPBundle/config/routes/sign_in.php' }`); start the kernel as 4.1 shows; run `bin/console cattophp:migrate`; then
replace its copy of each foundation with CattoPHP's, one at a time, checks passing after each, and put its look in a
theme.

Every key of `config/packages/cattophp.yaml`, with its default. Only `application_key` is required; a module's keys
matter only once it is on (`enabled: true`).

```yaml
cattophp:
  application_key: '%env(APP_KEY)%'  # required: secrets encrypted and codes hashed with it
  storage:
    type: database  # or files, for a page without a database
    connection: doctrine.dbal.default_connection  # the DBAL connection's service id
    folder: ~  # with files: readable by the site's account alone
  site_mode: true  # demo or live; false: never a demo
  layout: '@CattoPHP/layout.html.twig'  # what CattoPHP's pages extend without themes
  starting_values: {}  # by name, in place of .env's (a fixed value, another name); Settings overrides
  defaults: {}  # by name, when .env and Settings give nothing: APP_LOCALE: fr_FR
  sign_in:  # on unless enabled: false
    accounts: CattoPHP\SignIn\OwnerOnlyAccounts  # the application's accounts
    after: /  # where a person goes after signing in
    recovery_codes: true
    two_step_file: ~  # with files: the key's file, {account} for one per account
    sessions: false  # sign-ins a person can see and end (5.8)
  security_headers:  # on unless enabled: false
    policy: {}  # Content-Security-Policy directives replaced or added
    referrer_policy: strict-origin-when-cross-origin
    permissions_policy: 'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
    no_index: false  # true: search engines kept away, demo or live
    no_store: false  # true: browsers keep no copy
  html_layout: { verbatim_classes: [], flex_classes: [] }  # 5.12
  ui:
    icon_sprite: /img/icons.svg  # the sprite the icon component draws from
    public_root: '%kernel.project_dir%/public'
    icon_subjects: {}  # word: [library icons] (6.7)
    icon_name_stop_words: []
  error_pages:  # on unless enabled: false
    skip_paths: []  # prefixes whose errors the project answers itself, such as /api/
    drawer: CattoPHP\Http\TwigErrorPageDrawer  # an Http\ErrorPageDrawer
  network: { ... }  # the network lookup (8.4)
  theme: { enabled: false, ... }  # section 7; the scheduler and every other module in section 8
```

Three Twig namespaces: `@CattoPHP` (CattoPHP's templates), `@platform` (the project's own; the starter maps
`templates/` to it in `twig.yaml`) and `@theme` (the theme a request is drawn in).

**Service tags** (`CattoPHP\ServiceTags`): a service is tagged for each extension interface it implements
(`ServiceTags::EXTENSION_POINTS`), so a class may implement several; no interface has a `TAG` (`PublicPageProvider` no
`PAGES_TAG`). One wired by hand names the constant, `->tag(ServiceTags::SCHEDULED_TASK, ['priority' => 5])`, or the YAML
string: `SETTINGS`, `PERMISSIONS`, `SCHEMA_STEP`, `COMPONENT_SET`, `DRAWING_CHECK`, `ACTION_WORDS`, `DETAIL_WORDS`,
`SUBJECT_LINKS`, `FORGETS_ACCOUNTS`, `SCHEDULED_TASK`, `SETUP_STEPS`, `CATTOMAIL_EVENT_HANDLER`, `SITEMAP_SOURCES`,
`PUBLIC_PAGES`. A `ScheduledTask` that is also a `DetailWords` declares
`label(string $action = '', string $key = ''): string`, `''` for a detail meaning none of its own.

### 5.2 Where the records are kept

`storage.type: database` keeps CattoPHP's records (settings, the activity log, roles, links, sign-ins, two-step keys,
the schema runner's record, the modules' tables) in PostgreSQL through the DBAL connection; `files` is for a page
without one: links, sign-ins, the two-step key and the activity log in files in `storage.folder`, readable by the site's
account alone (one two-step file, without `{account}`, records whose key it holds; the activity log is rewritten to a
file beside it, renamed in, under `activity.log.lock`); settings stay in `.env`, nobody holds a role (administrators are
whom the application says), no module keeping records can be on, and there is no `cattophp:migrate`. Forgetting an
account changes each file whole, the two-step key last; `forget()` run again finishes.

### 5.3 Settings

`Settings\Settings` gives each value: what Settings saved, else the project's `starting_values` (if not empty), else
`.env`, else the project's `defaults`, else the setting's default, never writing `.env`. Saving what `.env` (or the
default) gives, or Reset, removes the override so a later `.env` change applies. An optional setting can be saved blank,
overriding `.env`; one that cannot be blank takes its default; a secret left blank keeps the one there is; overrides are
reread after 5 seconds.

**The API.** `get()` (a choice or time zone canonical, `.env`'s read in any case, `everyone` being `EVERYONE`; static
`choiceKey(Setting, string): ?string`), `int()`, `bool()`, `duration()`; `source()` (`settings`, `env`, `default`),
`savedBy()`; `save($groupKey, $input, $actor)` (a group, nothing if any is refused: `Settings\SettingsRefused`, why by
name), `set()` (one), `reset()`, `resetGroup()`; `clear($name)` (empty on purpose, so a dropped connection stays dropped
though `.env` holds its keys, until Reset); `secretProblem()` (a stored secret unreadable after a changed `APP_KEY`);
`canSave()` (false where settings live in `.env` alone).

**The catalogue.** `Settings\SettingsCatalogue` gathers every `SettingGroup` from the `Settings\SettingsProvider`
services (tag `cattophp.settings`, autoconfigured; higher tag priority first). A group has a key in small letters, a
label, a description, its settings. A `Setting` has:

| Argument | Meaning |
|---|---|
| `name` | as in `.env`, in capitals (`NOTES_KEPT_FOR`) |
| `label`, `help` | the words on the page |
| `type` | `Text` (one line), `Paragraph`, `Email`, `Address` (http or https; `httpsOnly: true`), `Number` (`min`, `max`), `Switch` (true or false), `Duration` (`shortest`), `Choice` (`choices`: value => words), `TimeZone`, `MailTransport` |
| `default` | used when `.env` and the project give nothing |
| `optional` | may be blank |
| `secret` | stored encrypted, never shown |
| `longest` | the most characters (300 by default; a paragraph 5,000, an address or mail transport 2,000) |

**CattoPHP's own settings** (each a line in the starter's `.env.example` too):

| Group | Settings |
|---|---|
| This installation | `APP_NAME`, `APP_ORGANISATION` (optional), `APP_URL`, `APP_ADMIN_EMAIL`, `APP_TIMEZONE` (UTC), `APP_LOCALE` (en_GB) |
| Demo or live (with `site_mode`) | `APP_SITE_MODE` (demo or live; demo), `APP_DEMO_HEADING` ("Demo mode"), `APP_DEMO_NOTICE`, `APP_DEMO_EMAIL_NOTICE` |
| Mail | `MAILER_DSN` (secret), `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (the installation's name when blank), `MAIL_REPLY_TO` |
| Sign-in (with `sign_in`) | `AUTH_SIGNIN` (ADMIN or EVERYONE; ADMIN), `AUTH_SIGNIN_ALLOWED_EMAILS`, `AUTH_TWO_STEP` (CHOICE, ADMINS or EVERYONE; ADMINS), `AUTH_MAGIC_LINK_TTL` (30m), `AUTH_SESSION_TTL` (1d), `AUTH_REQUEST_LIMIT_EMAIL` (5), `AUTH_REQUEST_LIMIT_IP` (20), `AUTH_REQUEST_WINDOW` (15m) |

Each module adds its group (8); `APP_ENV`, `APP_KEY` and the database are not settings (2.4).

**The page** is 5.11. Every change is logged (`settings.changed`, `settings.reset`), a secret's without its values.

### 5.4 The installation

`Installation\Installation` is the installation, each a setting: `name()`, `organisation()`, `address()` (`APP_URL`),
`addressOf($path)`, `domain()`, `administratorEmail()`, `timeZone()` (`APP_TIMEZONE`, UTC when unknown;
`chosenTimeZone()` is null then; static `timeZoneName(string): ?string` takes every name PHP knows, older ones
(`Asia/Calcutta`, `Etc/UTC`, `US/Eastern`) too, any case), `localTime($at)`, `locale()` (`APP_LOCALE`, "Language and
region": how amounts are written and the pages' stated language, `en_GB` unless another is named; a project with another
gives it under `defaults`) and `language()` (it as BCP 47, `en-ZA`, for `<html lang>`: `cattophp.installation.language`
in a template, as in the plain frame). `Installation\PhpTimeZone` sets PHP's zone from it at each request
(`kernel.request`, priority 8192), console command and scheduler run, so a zone changed in Settings applies without a
restart; one PHP does not know leaves PHP's as it was. Every time a person reads is in its zone: `{{ at|local_time }}`
("9 November 2026, 14:05"), `|local_time('j M Y, H:i')` for another format. Nothing of an installation is in the code:
it names itself by these settings, `cattophp.installation` in templates.

### 5.5 Run mode and site mode

- `Runtime\RunMode::environment()` answers as 2.5 says, `RunMode::debug()` following, from the process's environment
  (`.env` beneath it) before the database: no Settings page changes it.
- `Runtime\SiteMode` (`APP_SITE_MODE`, Settings overriding; `cattophp.site_mode` in templates): `isDemo()`, `mode()`,
  `heading()`, `notice()`, `emailNotice()`. A demo site shows `heading()` and `notice()` on every page, puts `[DEMO] `
  before each email's subject and `emailNotice()` first, sends `noindex, nofollow`; with `site_mode: false` a project is
  never one.

### 5.6 Sign-in

**How it works.** A person gives their email address at `/sign-in`; the page says the same whatever happens, and the
link is asked for after the response for every address (`Http\AfterResponse`, on `kernel.terminate`;
`SignInController`'s optional last argument), so neither answer nor its time tells who has an account: an address that
may sign in is emailed a link, any other gets nothing. An address or network address asks only so often
(`AUTH_REQUEST_LIMIT_EMAIL`, `AUTH_REQUEST_LIMIT_IP` per `AUTH_REQUEST_WINDOW`). The link works once for
`AUTH_MAGIC_LINK_TTL`, kept as a hash; opening it shows a button, so a mail scanner uses nothing; pressing it leads to a
one-time code where two-step is needed (set up by QR code first; checked under a lock, working once). Symfony's
`Security::login()` finishes, renewing the session, firing its events; then `sign_in.after` or the page asked for. A
sign-in ends when the account, loaded each request, is gone or disabled, may no longer sign in, or has lasted
`AUTH_SESSION_TTL`.

**Who may sign in** (`SignIn\SignInRules`): an administrator always (`maySignIn($account)` counts `APP_ADMIN_EMAIL`'s
account, flagged or not); everyone when `AUTH_SIGNIN` is `EVERYONE`; else only the addresses in
`AUTH_SIGNIN_ALLOWED_EMAILS` (separated by spaces, lines, commas or semicolons). `mayUse($email, $administrator)`, and
`mayJoin($email)` for an address with no account yet. `mustUseTwoStep()`, `twoStepRequired($administrator)`: `EVERYONE`
requires two-step of everyone, `CHOICE` leaves it to each, anything else (`ADMINS`) requires it of administrators.

**The application's accounts** come from a `SignIn\SignInAccounts` service named in `sign_in.accounts`:
`forEmail($email)`, `forId($id)`, each a `SignInAccount(id, email, name, administrator)` or null (none, or disabled).
`SignIn\OwnerOnlyAccounts` (the default) is one account, the administrator's. The person signed in is a
`SignIn\SignInUser` (`id`, `email`, `name`, `administrator`) with `ROLE_USER`, and `ROLE_ADMIN` for an administrator.

**Deleting an account:** one call inside the project's deleting transaction (joined), before its row goes,
`Account\AccountRecords::forget(string $account, ?Actor $by = null, bool $lastAdministrator = false)`. Its roles,
two-step key and recovery codes, links (its joining link too), sign-ins and each module's records that are its alone go.
Activity entries stay, by "A deleted account" (`AccountRecords::FORGOTTEN`) where it acted, about nobody where it was
the subject, its id where it is a detail's whole value and every word naming the person (any case, nested, in lists or
longer text, never inside a longer word) replaced, a changed entry losing its network address; settings it saved stop
saying who; `account.forgotten` is logged naming nobody. Mailing copies get `deleted-account@forgotten.invalid`
(`AccountRecords::FORGOTTEN_EMAIL`), no content of their own, a new token; Inbox messages lose name, address, reference,
network address and browser; the Telegram log names nobody; catto-mail's events give the forgotten address. What a
message says and the addresses never emailed stay, by design; an earlier address goes only if given as `words`. Refused
(`AccessRefused`): the last administrator, and an administrator's own account at their own hand, unless
`lastAdministrator: true` (a check's own); administrators count by the ADMIN role or `SignInAccount::administrator`
among accounts the project still finds, plus `APP_ADMIN_EMAIL`'s, holders locked meanwhile. It returns how many records
of each kind went or stopped naming it. A project's own records: an `Account\ForgetsAccounts` service (tag
`cattophp.forgets_accounts`, `forgetAccount(string $account, ?string $email): array`, optionally with `words`, what
else names the account). A store a project implements adds `forgetAccount()` on `ActivityStore` (`$accountId, $name,
$words`), `RoleStore` (and `revokeUnlessLast()`, `lockHolders()`), `LinkStore`, `SessionStore`, `SettingsStore`;
`SetupMarkStore::forget()`; `TwoStepStore::forget()` saying whether there was a key. The starter's
`App\Account\Accounts::delete($id, $by, $lastAdministrator)` calls `forget()` and deletes the row in one transaction.

**`security.yaml`** (the starter's):

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
    - { path: ^/$, roles: PUBLIC_ACCESS }  # the home page, open to everyone
    - { path: ^/, roles: ROLE_USER }
```

The authenticator reads no request; CattoPHP's pages decide each step. The entry point sends someone to the sign-in
page, which brings them back; an htmx request gets 401 with `HX-Redirect`, which htmx 4 follows.

**The pages** (`@CattoPHPBundle/config/routes/sign_in.php`): `/sign-in` (GET `cattophp_sign_in`, POST `..._send`),
`/sign-in/sent` (`..._sent`), `/sign-in/link/{token}` (GET `..._link` shows the button, POST `..._link_use` uses it),
`/sign-in/code` (GET `..._code`, POST `..._code_check`), `/sign-in/recovery-codes` (`..._recovery_codes`, shown once)
and POST `/sign-out` (`cattophp_sign_out`). Templates:
`@CattoPHP/sign_in/{start,sent,link,code,recovery_codes}.html.twig` and `@CattoPHP/email/sign_in_link.txt.twig`,
replaceable under `templates/bundles/CattoPHPBundle/`, themed as they are (7.5); the two-step key's text carries
`data-two-step-key` (code and account pages) for checks in any theme.

**Two-step sign-in** (`Security\TwoStep\TwoStep`, RFC 6238): `isSetUp()`, `setUp($accountId, $secret, $code)` (the first
right code proves the phone holds the key, only then kept; it returns the recovery codes to show once),
`confirm($accountId, $typed)` (`code`, `recovery` or null, each used up), `recoveryCodesLeft()`, `renewRecoveryCodes()`,
`reset($accountId, $actor)`. Eight recovery codes (`XXXX-XXXX`, no look-alikes), each working once, kept as keyed hashes
(off with `sign_in.recovery_codes: false`). The QR code is server-drawn SVG, no inline style (`QrPicture::svg()`).
Setting up, using a recovery code and renewing are logged as the person's doing, even at sign-in: the `Actor` given,
else whoever is signed in as that account, else its id.

**Commands:** `cattophp:sign-in-link someone@example.com` prints a sign-in link when email is not arriving (two-step
still asked); `cattophp:two-step-reset someone@example.com` restarts two-step for someone without phone or codes when
nobody else can.

**Checks:** the starter's `tests/Application/SignInTest.php`, `JoiningTest.php` sign in through the pages.

### 5.7 Joining: an account made at the first sign-in

People may join by signing in the first time: a link goes to an address that may join (`APP_ADMIN_EMAIL` always; anyone
with `AUTH_SIGNIN=EVERYONE`; else the listed addresses), and the account is made only when the link is used, proving the
address is the person's. Nothing but the link is kept before; the sign-in page says the same to all.

- **In CattoPHP:** the service in `sign_in.accounts` implements `SignIn\JoiningAccounts` beside `SignInAccounts`,
  switching joining on. Its one method, `join(string $email): ?SignInAccount`, makes the account (logged as the project
  logs one) or returns the address's existing one (two links used at once make one). CattoPHP checks
  `SignInRules::mayJoin($email)` first and sends a link of its own purpose (`sign_in_join`) holding only the address,
  logging `sign_in.joining_link_sent` naming nobody. `cattophp:sign-in-link` prints a joining link for one too.
- **In the starter:** `App\Account\Accounts` implements `JoiningAccounts`; its `join()` makes the account, records
  `account.created`, gives the administrator's address the Administrator role.

### 5.8 Sign-in and security: two-step by choice, and sign-ins a person can see and end

A person's Sign-in and security page (`Account\SecurityController`), for people signing in through CattoPHP's pages with
Symfony's session, imported:

```yaml
cattophp_account:
  resource: '@CattoPHPBundle/config/routes/account.php'
```

- **Two-step by choice**, where Settings does not require it (`AUTH_TWO_STEP=CHOICE`, or `ADMINS` for
  non-administrators): set up by QR code (`/account/security/two-step`; while on, "Two-step sign-in is already on."),
  off with a current or recovery code (`.../two-step/off`), new recovery codes with a current code
  (`.../recovery-codes`). A wrong code is logged (`two_step.wrong_code`, `for` "turning two-step sign-in off" or "new
  recovery codes", `signed_out: true` on the last), counted per account (`Http\Throttle`); the 5th in 15 minutes
  (`WRONG_CODES_ALLOWED`, `WRONG_CODES_WINDOW`) ends that sign-in: "Too many wrong codes were typed on Sign-in and
  security, so you were signed out. Sign in again to carry on." The page is `/account/security` (routes
  `cattophp_account_*`; templates `@CattoPHP/account/{security,two-step,recovery-codes}.html.twig`).
- **Sign-ins a person can see and end** (`sign_in.sessions: true`, off by default): each sign-in through CattoPHP's
  pages kept (`cattophp_sessions`) by a hash of a token in the session, with the account and address, the browser, a
  keyed fingerprint of the network address (never the address), when it began, was last seen, runs out. Checked on each
  request, an ended or expired sign-in signs that browser out; signing out ends it. Listed as cards (`#sign-ins
  .cl-ui-card`, three a row wide, "This browser" a badge in `.cl-ui-card-title`), End and End all others
  (`sign_in.session_ended`, `sign_in.sessions_ended`). A project keeping sign-ins through its own
  `Sessions\SessionStore` leaves it off; the starter has it on, Your account linking here.

### 5.9 Roles and permissions

**Permissions** are named resource first, action last, in capitals, in two to four parts (`PLATFORM.SETTINGS.VIEW`,
`NOTE.EDIT`). Code asks for a permission, never a role; one outside the catalogue is never granted; a `SYSTEM.` key is
administrators' alone. They come from `Access\PermissionProvider` services (tag `cattophp.permissions`):
`new Permission($key, $group, $description)`.

CattoPHP's own:

| Permission | Allows |
|---|---|
| `PLATFORM.SETTINGS.VIEW` | see the settings and where each value comes from (never a secret's) |
| `SYSTEM.SETTINGS.MANAGE` | change and reset the settings |
| `PLATFORM.ROLE.VIEW` | see the roles, what each grants, who holds them |
| `SYSTEM.ROLE.MANAGE` | make, change and delete roles, give and take them away |
| `PLATFORM.ACTIVITY.VIEW` | read the activity log |

**Roles** (`Access\Roles`): `all()`, `get($key)`, `rolesOf($accountId)`, `isAdministrator()`, `permissionsOf()`,
`may($accountId, $permission)`, `holders($roleKey)`, `save($key, $name, $description, $permissions, $actor)`,
`rename()`, `delete()`, `grant($accountId, $roleKey, $actor)`, `revoke()` (ADMIN taken away in one locked step). `ADMIN`
(`Roles::ADMIN`) holds every permission, now and later; it is never deleted, nobody takes it from themselves, the last
holder keeps it. A refused change throws `Access\AccessRefused`; each change is logged.

**The voter** (`Access\PermissionVoter`) grants a catalogue permission to a signed-in account whose roles hold it, every
permission to an administrator, so `#[IsGranted('NOTE.VIEW')]` and `is_granted('NOTE.VIEW')` work everywhere; a menu
shows only what the reader may open.

### 5.10 The activity log

`Activity\ActivityLog::record($action, $details, $subjectType, $subjectId, $actor)` records a change: when, the action
(`area.what_happened` in small letters: `note.written`), who (the actor given, else the person signed in, else the
system), what about, its details, the address it came from, the request's reference. No secret is written: a detail
named as one (a password, token, API key...) is kept as `••••`, as is a secret in text (a mail server address's
password), by `Security\Redactor`. A whole number under a counting name (a plural, `recovery_codes`, or ending `_count`,
`_left`) is kept.

- `Activity\Actor(?string $id, string $name)`, or `Actor::system()` for a task or command:
  `$log->record('note.written', ['title' => $title], 'note', $code, new Actor($reader->id, $reader->name));`
- An entry (`Activity\ActivityEntry`) has `at`, `action`, `actorId`, `actorName`, `subjectType`, `subjectId`, `details`
  (an array), `ipAddress`, `requestReference`:
  `foreach ($log->recent(20, 'note.written', 'note', $code) as $entry) { $entry->at; $entry->details['title']; }`
- `recent($limit, $action, $subjectType, $subjectId, $actorId, $offset)` reads the newest first, narrowed by what is
  given, from `$offset` (a page); `count(...)` counts alike; `actions()` lists the actions held, for a filter.
- `removeBefore($moment)` removes older entries (the starter's nightly `activity_cleanup` task, after
  `ACTIVITY_KEPT_FOR`, 5y).
- Entries are added, and changed only to forget an account (5.6). Without a database the log is a file of JSON lines
  (`Activity\FileActivityStore`), its newest 2 MB read back.
- A user class a project gives Symfony instead of `SignInUser` names itself in the log through `Activity\NamedAccount`.
- **Details in words** (`Activity\ActivityDetails`, for the Activity log page): a key in words (`due_at` "Due at", a
  value `needs_repair` "Needs repair"), an ISO date as "1 December 2026", an ISO time as "7 November 2026 at 07:01" in
  the installation's zone, true and false as yes and no, a list on one line with commas, anything else JSON, each cut at
  160 characters; the page names a subject by its type in words ("Note k7qxm2pa"). A project words its own by an
  `Activity\DetailWords` service (tag `cattophp.detail_words`): `label(string $action, string $key): ?string` and
  `value(string $action, string $key, mixed $value): ?string`, null for the default.
- **Subjects linked:** an `Activity\SubjectLinks` service (tag `cattophp.subject_links`) answers
  `href(string $type, string $id): ?string` (`/notes/k7qxm2pa`, or null) for each entry's subject, CattoPHP's too; the
  first address wins; only a path starting with one `/`, or http(s), is linked, escaped.

### 5.11 The ready-made Administration pages

Settings, Roles and Activity log pages for every project (`CattoPHP\Admin\`), in its look (7.5), behind CattoPHP's
permissions (5.9), switched on by a project with Symfony's session and sign-in importing the routes
(`cattophp_admin_*`):

```yaml
cattophp_admin:
  resource: '@CattoPHPBundle/config/routes/admin.php'
```

- **Settings** (`/admin/settings`): each group, each setting saying where its value comes from (saved here, when, by
  whom; `.env`; its default) and what Reset goes back to; one Save a group (Enter in a field saves it); a Reset beside
  each saved one and "Reset every setting here", each its own form after the group's (`form#setting-<name>-reset`,
  `form#settings-<group>-reset`; the button beside its field by `form=`), confirmed ("Put {Label} back to .env's value?
  What is saved here goes.", or "its default"); a value in force that is no option drawn first, selected, kept when sent
  back unchanged ("{value} (as .env gives it, not one of these)", or "as saved here"; an older zone name PHP knows drops
  "not one of these"); a saved secret as 2.10 says, one no longer readable kept when left blank; a refused form back as
  typed, why beside each field; a reader who may only look told why there are no buttons.
- **Roles** (`/admin/roles`, `/new`, `/{key}`): every role, what it allows (permissions as feature cards), who holds it;
  a role given by email address (only to an existing account: a person signs in once first, as the starter's Accounts
  page shows), taken away, made, changed, deleted.
- **Activity log** (`/admin/activity`): paged, filtered by the actions present, refreshing every 15 seconds with Pause,
  details in words, subjects linked (5.10); its tables and the Roles pages' stack on a phone. Each action's words come
  from `Activity\ActionWords` services (tag `cattophp.action_words`: `words()`, `settings.changed` => "Setting
  changed"), CattoPHP's and the project's; one without shows as named.
- Templates: `@CattoPHP/admin/{settings,roles,role,role-new,activity}.html.twig`. Like the account page they go through
  `Http\StockPages`: the token and permission checks, every POST answered by a redirect and a notice under CattoPHP's
  flash keys (`cattophp_done`, `cattophp_refused`, `cattophp_info`, so a project's never show twice), a refused form
  kept as typed, never a secret.
- A project with richer pages of its own does not import the file; the starter does, `StarterPages::ADMINISTRATION`
  linking them.

### 5.12 HTTP

**Error pages** (`Http\ErrorPageListener`): the kind of error chooses the title ("Not found", "Not allowed", "Something
went wrong", "Not done") and what to do next, status and request reference in small print; a server error's reason shows
only in development, logged. JSON requests and `error_pages.skip_paths` are the project's. The page is
`@CattoPHP/error.html.twig` (an `Http\ErrorPage`: `kind`, `title`, `status`, `message`, `reference`, `retryUrl`); with
themes, the page named `error`, in CattoPHP's plain frame if the theme itself fails (`TwigErrorPageDrawer::PLAIN_FRAME`,
given as `layout`, so a project's template still arrives), or a project's `Http\ErrorPageDrawer` (`error_pages.drawer`).

**Security headers** (`Http\SecurityHeadersListener`), every answer: the Content-Security-Policy, `nosniff`, no framing,
the referrer and permissions policies, HSTS over HTTPS, no `X-Powered-By`, `X-Request-Reference`,
`X-Robots-Tag: noindex, nofollow` on a demo site (always with `no_index`), `no-store` with `no_store`.

**The Content-Security-Policy** (`Http\ContentSecurityPolicy`): `default-src 'none'; script-src 'self' 'nonce-...';
style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'self';
base-uri 'none'; frame-ancestors 'none'; object-src 'none'`. A project changes a directive in `security_headers.policy`,
a controller adds a source for one answer (`allow($directive, $source)`); a route showing HTML a person wrote sets
`_csp: authored` (inline styles, https pictures; scripts nonce-only). The head carries `<meta name="htmx-config"
content="includeIndicatorCSS:false">`, htmx's indicator style being inline.

**The form token** (`Http\FormToken`): every form changing something carries
`<input type="hidden" name="_token" value="{{ form_token() }}">`, checked first with `isValid($request)`.

**A refused form comes back as typed** (`Http\TypedForm`): sending the person back, the controller calls
`keep(Request $request, array $secret = [], array|FieldsRefused $errors = [])`, `$errors` why each field was refused, by
name (`kept()` builds what it stores; `StockPages::refused()` takes such `$errors`); the next page takes them
(`take($request, $action)`, `errors($request, $action)`, or `typed_for()`, `typed_errors_for()` in a template), only in
the form that sent them, unused ones forgotten once it is sent. Never kept: the form token (`_token`, `csrf`), a field
whose name `Security\Redactor::SECRET_NAMES` matches (password, passphrase, secret, token, API, private or signing key,
DSN, cookie, authorisation, one-time or recovery code: a field to keep is named otherwise), and those in `$secret` (the
Settings page names each secret setting). A window (`overlay.modal`) whose form was refused reopens (`open: true`).
**`Http\FieldsRefused`** (an extendable `InvalidArgumentException`), thrown by a service for a whole form:
`__construct(array $errors, string $message = '')`, `errors()` (by field), `error($name)`; why under `''` is the whole
form's, not kept; the message, unless given, joins the reasons.

**The bot check** (`Http\BotCheck`): a public form that sends something carries the trap, hidden from people and screen
readers, `<div class="cl-trap" aria-hidden="true"><label>Website <input name="website" tabindex="-1"
autocomplete="off"></label></div>`; `BotCheck::signal($request)` says why it looks like a bot (the trap filled,
`X-Suspect-Network` from the server in front, no browser, an outdated one, a script) or null; the controller then
answers as usual, sending nothing. A private or loopback address is never refused.

**Also:** `Http\FormStamp` (`stamp()` in a hidden field, signed with `APP_KEY`; `secondsOpen()` when sent, people slower
than scripts); `Http\Throttle::allow($action, $keys, $limit, Duration $window)` (a sliding-window limit counted by hash,
for what sends mail for a visitor); `Http\RequestReference` (each request's reference, `K7QX-M2PA`, without 0, O, 1 or
I); `Http\LiveRefresh` (6.5).

**The served page's layout** (`Http\HtmlLayout`): every page laid out, a block a line, a tab a level, whitespace changed
only where no browser draws it differently; `pre`, `textarea`, `script`, `style`, `|authored` HTML and
`html_layout.verbatim_classes` served byte for byte; `html_layout.flex_classes` names the project's flex and grid
classes. In development `X-Served-Html: as-built` gets the page as Twig drew it.

### 5.13 Mail

`Mail\Mailer` sends through `MAILER_DSN` (Settings overriding), made by Symfony's factory so events, the profiler and
test assertions see every email, from `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` (`MAIL_REPLY_TO` if set): `send(Email)`,
`sendTemplate($to, $template, $context)` (4.9), `sendTest($to)`. A demo site's emails: 5.5.

- `bin/console cattophp:test-email someone@example.com` sends a test email through the configured mail server.
- The starter sets Symfony's own mailer to `null://null`, never sending, for tests to read (9.2).

### 5.14 The schema runner

`Schema\SchemaRunner` applies the steps not yet applied, by id, each in its own transaction, recorded
(`cattophp_schema_steps`), under a database lock so none is applied twice: the foundations', the modules' switched on,
and the project's own (classes implementing `Schema\SchemaStep`, tag `cattophp.schema_step`: `id()`, `describe()`,
`apply(Connection)`; `Schema\SqlStep` lists SQL statements, `statements()`).

- `bin/console cattophp:migrate` applies them; `--dry-run` lists what it would apply.
- A module switched on later gets its tables at the next migrate; one switched off keeps its tables and data.
- For a project's own migration tool: `Schema\FoundationSteps::all()` (every step class),
  `Module\Modules::schemaSteps($on)` (the listed modules').

### 5.15 Building blocks

- **Lengths of time** (`Foundation\Duration`): a whole number and letter per unit, largest first, no spaces (`y`, `w`,
  `d`, `h`, `m` minutes never months, `s`): `4w2d`, `1h30m`, `90s`. Static `parse($text)` (throws
  `InvalidArgumentException` with `Duration::HOW`, the sentence a field shows), `isValid()`, `ofSeconds()`; then
  `words()` ("1 hour 30 minutes"), `lengthInSeconds()` (a year 365 days), `after($at)`, `before($at)` (a calendar year
  from a date), `->text` as typed.
- **Account references** (`Foundation\AccountReference`): 1 to 3 capitals, the date made, a rising number of 4+ digits
  and a check character (ISO/IEC 7064 MOD 37,36, catching every single mistype and swap of neighbours); stored
  `EX202612010001K`, shown `EX-20261201-0001-K` (`|account_reference`), typed with or without hyphens, any case:
  `format()`, `parse()`, `isValid()`.
- **Secrets and hashes:** `Security\SecretCipher` (`encrypt()`, `decrypt()`, AES-256-GCM from `APP_KEY`);
  `Security\KeyedHash` (`of($purpose, $message)`, `matches()`, HMAC-SHA256 for what is recognised, never kept readable);
  `Security\Redactor` (`text()`, `data()`: secrets by name, in addresses, bearer tokens, private keys);
  `Foundation\Base32` (RFC 4648, forgiving typing).
- **Readable codes** (`Foundation\ReadableCode`): `make(int $length = 8)` (4 to 64 characters from
  `abcdefghjkmnpqrstuvwxyz23456789`, by `random_int`), `read(string $typed, ?int $length = 8): ?string` (what was typed,
  capitals, spaces and hyphens forgiven, or null). Not unique: keep a unique index.
- **SVG as PNG** (`Foundation\SvgRaster`): `rsvg-convert` (`librsvg2-bin` on Debian, Ubuntu), the SVG on standard input,
  loading no file, stopped when too slow.
- **Countries** (`Country\Countries`): each ISO 3166-1 code with its British English name (from intl if installed);
  `code($value)` checks one sent, `options('GB')` gives a drop-down, A to Z, the project's own first.
- **South Africa** (`SouthAfrica\IdNumber`, `Provinces`): an ID number checked as well formed (13 digits, a real date of
  birth, citizen or permanent resident, Luhn checksum), masked for pages, fingerprinted with the project's key; the nine
  provinces.

### 5.16 Links that work once

`Links\LinkStore` keeps links that work once, of any purpose (a sign-in, a registration, confirming an address,
connecting Telegram, a project's own), as a hash only, with the address and account (either may be unknown), what it
carries and where it was asked from: `add()`, `usable()` (locked), `use()`, `countForEmail()`, `countFrom()`,
`forgetOld()`.

### 5.17 In templates

Functions: `csp_nonce()`, `form_token()`, `typed_for(action)`, `typed_errors_for(action)` (5.12), `ui()`,
`ui_template()`, `ui_props()`, `ui_field_attrs()` (6.2), `icon_sprite()` (the sprite's fingerprinted address, for a
drawing naming a symbol), `lucide(name)` (6.7). Filters: `|local_time(format)` (5.4), `|account_reference` (5.15),
`|share` (a bar's length as classes, `cl-share cl-share-37`), `|authored` (5.12). Globals: `cattophp.installation`,
`.site_mode`, `.layout`.

## 6. The interface system

### 6.1 The browser files

`vendor/bin/cattophp-assets public` copies CattoPHP's browser files into `public/cattophp/` after each install and
update. Every page loads, in order, Bootstrap 6's `bootstrap-reboot.min.css`, `bootstrap-grid.min.css` and
`bootstrap-utilities.min.css` (no Bootstrap script), `cattophp/css/cattophp.css` (the components, from the theme's
`--cl-` tokens), the application's stylesheet, the theme's; then, each with the nonce and `defer`, htmx 4
(`htmx.min.js`), `cattophp/js/cattophp.js`, the application's scripts, the theme's.

The starter lists them in `App\View\StarterPages::STYLES` and `SCRIPTS`, each address fingerprinted by its contents.
`cattophp.js` only makes server-drawn pages easier to use (windows, menus, confirmations, the tree picker, tables sizing
their columns, the pager fitting its row, hex colours with their swatch, live pages); a project's script reaches its
helpers through `window.CattoPHP` (`ready`, `within`, `keepScrollerPlace`).

### 6.2 The components

One registry (`Ui\Components`) maps each component to its template and properties: data (words, numbers, a choice from a
fixed list, true or false, lists), never a class, attributes or markup; variable HTML goes in a slot. An unknown,
missing required or out-of-list property is an error, as is an item's key out of place (keys in any order; the refusal
names item and key: "option 1 lacks label"). `{{ ui('feedback.badge', {label: 'Live', tone: 'success'}) }}` draws one;
`{% embed ui_template('layout.surface') with {props: ui_props('layout.surface', {...})} %}` draws one, filling its
slots; `ui_field_attrs(props)` on a `form.field`'s control ties it to its help and error.

CattoPHP's 34 components (templates in `@CattoPHP/ui/`), their main properties and slots:

| Component | For |
|---|---|
| `layout.surface` | a card: `variant` standard or compact, `tone`, `labelledby`, `sticky`; slot `surface_body` |
| `layout.section-grid` | cards side by side: `columns` auto, 1 to 4 or `main-side` |
| `layout.section-head` | a card's head: `heading`, `heading_id`, `summary`, `eyebrow`, `heading_level`; slot `section_actions` |
| `layout.toolbar`, `layout.breadcrumb`, `layout.tabs` | controls above a list; the trail (`items`); tabs (`items` of `key`, `label`, `href`; `current`) |
| `action.link` | a link as a button: `label`, `href`, `icon`, `variant` (primary, secondary, quiet, danger), `size`, `in_place`, `modal`, `confirm`, `new_window` |
| `action.button` | a button: `label`, `type`, `icon`, `variant`, `name`, `value`, `form`, `confirm`, `modal`, `disabled`, `hook` |
| `action.group`, `action.row` | buttons together (slot `body`); a row's actions as buttons or a menu |
| `form.grid` | fields: `columns` auto, 1, 2 or wide; slot `body` |
| `form.field` | one field: `label`, `for`, `required`, `help`, `error`, `full_width`, `span`; slot `control` |
| `form.actions` | the one action row: slots `primary_actions`, `secondary_actions` |
| `form.picture-choice` | feature cards: `name`, `legend`, `items` (each `value`, `label`, one of `icon` (SVG from `lucide()`), `image` or `drawing`, and optional `note`, `disabled`), `selected`, `help`, `error` (under the legend, `role="alert"`, each control `aria-invalid`; ticks with `multiple`, no `list`, then need `id`), `id`, `size`, `columns` auto, 1 or 4, `form`, `multiple`, `list`, `tick_value`, `required`, `legend_hidden` |
| `form.choice`, `form.tree-select`, `form.compact-action` | a plain choice; a tree's (`name`, `items` of `value`, `label`, `depth`, `path`, optional `icon`, checked as a feature card's, `children`; `selected`); one control with its button |
| `data.table` | the only `<table>`: `caption`, `density`, `fit` (false: meant to scroll), `stack` (true: below 768 px each row a card, labels from each cell's `data-label`, empty cells left out); slots `header`, `body`, `footer`, `empty` |
| `data.dataset` | a list, with its search and pager if any (6.4): `name`, `heading`, `summary`, `search`, `pagination`; slots `toolbar`, `results` |
| `data.stat-grid`, `data.stat-card` | figures: `columns`; a card's `value`, `label`, `note`, `href`, `icon`, `size` (small for money, large for the page's figure) |
| `data.card-grid`, `data.card` | 4 or 5 cards to a row; `title`, `href`, `picture` round, square, wide or none (words at the start) |
| `data.list`, `data.list-item`, `data.key-value-list` | plain lists; facts as label and value (`items`) |
| `data.person` | a person linked to their page: `name`, `href`, `reference`, `email`, `full` (the page's own person) |
| `feedback.badge` | a short state: `label`, `tone` (neutral, info, success, warning, danger, permanent) |
| `feedback.notice` | `tone` (info, success, warning, danger, neutral), `heading`, `icon`, `solid`, `flash`; slot `body` |
| `feedback.empty`, `feedback.progress` | an empty list's words (`heading`, `summary`); a bar (`value`, `min`, `max`, `label`) |
| `overlay.modal` | a window: `id`, `title`, `size` (normal, wide, page), `action`, `method`, `upload`, `open`; slots `modal_body`, `modal_actions` |
| `overlay.accordion-section` | opened and closed by hand: `heading`, `summary`, `open`, `icon`, `eyebrow`, `load_url` |
| `icon` | a sprite icon: `name`, `label`, `decorative` |

**`confirm`** (`action.button`, `action.link`) is the question `cattophp.js` asks by the browser's own
`window.confirm()` when the control is pressed, Cancel stopping it; `<form data-confirm-submit="...">` asks before any
button sends it. Without JavaScript nothing is asked. A browser check answers with
`page.once('dialog', dialog => dialog.accept())` before the click (Playwright otherwise dismisses it).

**A project's own components:** a `Ui\ComponentSet` service (tag `cattophp.ui_component_set`) registers components by
name (never CattoPHP's), each with its full template name, its properties' defaults (null when required), optionally the
values a property may take; `sharedContext()` names page values its components read (a form token); `check()` checks
what only the project knows; `ui()` draws them. A `Ui\DrawingCheck` (tag `cattophp.ui_drawing`) says which of the
project's drawings a feature card may show; without one, none.

**An uploaded SVG** (`Ui\UploadedSvg::fromUpload()`, `validate()`) is well formed with nothing that runs (no script,
event attribute, embedded document, outside link or document type with content). An icon site's file is made fit: its
plain document type line and comments dropped, `<style>` and ids scoped to it, a one-colour icon (up to 20,000 bytes) in
`currentColor` with no fixed size; a picture may be 200,000 bytes.

### 6.3 The page frame

Every page is built one way:

```text
page head (@CattoPHP/page/head.html.twig): the only h1, the kicker, the lead, the page's actions
identity band (@CattoPHP/page/identity.html.twig): who is signed in, or the way in
section.cl-page-section: one per region of the page
  layout.surface: one card per job; two or more in a layout.section-grid
    layout.section-head: the card's own heading, summary and actions
    search and filters, the pager, a notice, the table or list, the pager
```

- The page head reads `ph_title`, `ph_lead`, `ph_actions` (captured `ui()` actions), `ph_action_href` with
  `ph_action_label`, `ph_action_icon` (a sub-page's one secondary link), `ph_hero`, `ph_media` (a logo before the
  title), `ph_badges`, set from the page's data by the starter's `templates/page.html.twig`.
- The identity band reads `identity_name`, `identity_email`, `identity_image`, `identity_roles`, `identity_status`,
  `identity_profile_href`, or signed out `identity_welcome`, `identity_invitation`, `identity_actions`;
  `identity_placement: 'none'` leaves it out (the sign-in and error pages).
- `@CattoPHP/page/section-actions.html.twig` draws a section's actions, in the page head or a card's.
- One standard width, notices, head and band as wide as the cards; words on a surface, never the background.
- Spacing by class: `cl-card-form` on each of several forms in one card (each starting below the last one's actions),
  `cl-disclosure` on a `<details>` in a card's flow.

### 6.4 Lists

A searched, paged list: count, make the page from untrusted input, then query with its offset. The starter's is
Administration > Accounts (`admin_accounts` `/admin/accounts`, `admin_account` `/admin/accounts/{reference}`,
`ACCOUNT.VIEW`): `accounts_q` over name, email or reference, 25 a page, newest first, by `Accounts::count()`,
`listed()`, `findByReference()` (`Account::$reference`, schema step `202611131500-account-references`), role names
linking to Roles for a `PLATFORM.ROLE.VIEW` reader.

```php
#[Route('/notes', name: 'notes', methods: ['GET'])]
public function list(Request $request, Notes $notes): Response
{
  $search = trim((string) $request->query->get('notes_q', ''));  // the search box's field: <name>_q
  $total = $notes->count($search);
  $page = Pagination::create($request->query->get('notes_page'), $request->query->get('notes_page_size'), $total, 25);
  $base = $this->generateUrl('notes');

  return $this->page($request, 'notes', [
    'title' => 'Notes',
    'notes' => $notes->rows($page->pageSize, $page->offset, $search),  // LIMIT :limit OFFSET :offset
    'searched' => $search,
    'search' => ['ds_name' => 'notes', 'ds_label' => 'Search the notes', 'ds_action' => $base, 'ds_value' => $search,
      'ds_push_url' => true],
    'pager' => Pager::payload($page, 'notes', $base, 'Notes', ['notes_q' => $search]),
  ]);
}
```

```twig
{% embed ui_template('data.dataset') with {props: ui_props('data.dataset', {name: 'notes', search: search, pagination: pager})} %}
{% block results %}
{% if notes %}{% embed ui_template('data.table') with {props: ui_props('data.table', {caption: 'Every note'})} %}...{% endembed %}
{% else %}{{ ui('feedback.empty', {heading: searched ? 'No note matches' : 'No notes yet', summary: '...'}) }}{% endif %}
{% endblock %}
{% endembed %}
```

- **`Ui\Pagination::create($page, $pageSize, $total, $default)`** gives `pageSize`, `offset`, `page`, `totalPages`,
  `from`, `to`. Only the approved sizes (25, 50, 100, 150, 250, or a list's own through `within()`) reach a query, so a
  tampered address cannot widen a page; one past the end is clamped.
- **`Ui\Pager::payload($pagination, $list, $base, $label, $filters)`** is the pager's data (`$list` the dataset's
  `name`, `$label` the list in words, naming the pager to a screen reader: "Notes pagination"): first, previous,
  numbered, next and last links, the jump and the rows drop-down, each keeping `$filters` and the size, in `<list>_page`
  and `<list>_page_size`; a row above and below the results ("26–50 of 100 · Pg 2 of 4").
- `search` and `pagination` are optional: a list without a search box leaves out `search`, one never paged
  `pagination`.
- **`search`** is `ds_name` (the dataset's name), `ds_label`, `ds_action` (the list's address), `ds_value` (what was
  searched), and optional `ds_push_url` (the address follows), `ds_help` (the line under the box), `ds_hidden` (other
  fields the list depends on, by name), `ds_include` (another part of the page whose fields go too), `ds_swap`
  (`innerMorph` where two lists share a page), `ds_morph_skip`. Search covers the whole list. The box's field look,
  `:where(.dataset-search input[type=search])`, yields to any rule of the theme's.
- **In place, and without JavaScript:** the box asks 300 ms after typing, as do the pager's links and jump, through
  htmx, taking `#<name>-results` from the answer into `#<name>-region`; the controller always answers with the whole
  page. Without JavaScript the box is a GET form with a Search button, the pager plain links and a form.
- **Table or grid** (`Ui\ListView`): the reader's choice in the address (`<list>_view`), else a cookie
  (`cl_view_<list>`), else the list's default; plain links switch.
- A person is named with `data.person`, linked to their page. A table wider than a phone has `stack: true` (6.2).

### 6.5 In place and live

- **In place:** a GET form or link changing what a page shows carries
  `{% include '@CattoPHP/page/in-place-attrs.html.twig' with {in_place_action: path('notes')} only %}`; `#cl-main` is
  fetched again and morphed (focus, scroll and typing kept, the address following, Back working); without JavaScript the
  form loads the page.
- **Live:** `{% include '@CattoPHP/page/live-status.html.twig' with {live_seconds: 15} %}` (15 for what is happening, 30
  for figures) refreshes the page in place with Pause (only with JavaScript), holding back while the reader reads, types
  or has something marked `data-live-hold` open (`live_held` says so). Its request carries `X-Live-Refresh` (the
  controller calls `LiveRefresh::notAVisit($request)`); a form holding typing has `hx-morph-skip`.

### 6.6 Trees

`Ui\TreeArrangement($maxDepth)` arranges a tree readers drag, move by keyboard or by menu without JavaScript:
`place($rows, $ids, $parentId, $index)`, `move($rows, $ids, $direction)` (top, up, down, bottom, out), `destinations()`,
`assertComplete()`, `levelled()`, `heights()`. Rows are `{id, parent}` in tree order; each gives the whole new tree or
throws `Ui\TreeMoveRefused` with a reason code; a row moves with its subtree, never into itself. No database: the
project validates the change, saves it under a lock, words a refusal.

### 6.7 Icons

`Ui\IconLibrary` is Lucide's line icons (ISC, `resources/icons/`), SVG in the theme's colour: `has()`, `svg()`,
`search($query)`, `suggestionsFor($name)` (those fitting a name), `chosen()` (one a form sent, checked),
`bestFor($name, $fallback)`; `ui.icon_subjects` adds the project's subjects and icons; `lucide('name')` draws one
inline. Pages name symbols in the project's sprite (`ui.icon_sprite`) by meaning (4.8).

## 7. Themes

### 7.1 Core lays out, the theme paints

CattoPHP's components and frame structure every page; the theme paints it (colour, type, borders, corners, shadows,
decoration) through the `--cl-` tokens (`--cl-primary`, `--cl-ink`, `--cl-surface`, `--cl-border`, `--cl-radius` and the
rest the starter's `theme.css` sets). A rule on a component's class (`cl-ui-*`), the pager's, the dataset search's or
the project's functional classes may not set `display`, `position`, `inset`, `top`, `right`, `bottom`, `left`,
`z-index`, `float`, `clear`, `overflow`, `flex`, `grid`, `align-*`, `justify-*`, `place-*`, `order`, `width`, `height`
(and their minimums and maximums), `visibility`, `white-space`, `box-sizing` or `touch-action`. A section head's
decoration may sit absolutely (`::before` or `::after`, `content: ""`, `position: absolute`, `pointer-events: none`);
`Ui\Contract\SourceAudit::themeGeometry()` enforces it (9.3).

### 7.2 The theme package

A theme is files only, in a folder named by its slug (`themes/<slug>/`) or its ZIP (package 4.0, template API 2.0):

```text
theme.json   the manifest
base.html.twig   the frame, for a standalone theme
pages/<family>.html.twig   optional: a wrapper for a page family the project names (theme.page_families)
public/css/theme.css   required, not empty
public/css/*.css, public/js/*.js, public/img/..., fonts   optional, each listed in theme.json
```

**`theme.json`:**

```json
{
  "format": "cattophp-theme", "schema_version": "4.0", "template_api": "2.0",
  "theme": {"name": "Example Harbour", "slug": "example-harbour", "version": "1.0.0", "author": "Example Notes",
    "created_at": "2026-12-01T10:30:00+02:00", "description": "White cards on pale blue.", "color_scheme": "light"},
  "parent": null, "styles": [], "scripts": [], "external": {"styles": [], "scripts": []}
}
```

- `format` is the project's package format (`theme.package_format`, default `cattophp-theme`).
- `theme`: `name`, `slug` (small letters and hyphens), `version` (1.0.0), `author`, `created_at` (ISO) and
  `description` required; `color_scheme` light (default) or dark, written on `<html>` as `data-bs-theme`.
- `parent`: null, or `{"name", "version"}` naming one release; a child inherits from it alone (its templates searched
  first, its files over the parent's) and is never a parent.
- `styles`, `scripts`: extra files under `public/`, never `css/theme.css` (always loaded); `external`: HTTPS only.
- `palettes` (optional): named palettes of five hex colours; Core draws the switcher, never the theme.

**`base.html.twig`** declares `{% block page_content %}` (which a page-family wrapper overrides) holding
`{% block page_body %}` (where the page renders); loads `platform.styles` and `platform.scripts` (scripts with
`nonce="{{ csp_nonce() }}"`), then `theme.styles` and `theme.scripts`; and holds the frame (header, menu, demo notice,
`main#cl-main.cl-main`, footer). No PHP in any template; the example is `themes/starter/base.html.twig`.

**A page-family wrapper** (`pages/admin.html.twig`, for a family in `theme.page_families`) extends
`'@theme/base.html.twig'` and draws `{{ block('page_body') }}` inside what it adds (an administration frame).

**Limits:** 2 to 500 files, each at most 10 MB, 50 MB in all; only text (`twig`, `html`, `css`, `js`, `json`, `txt`,
`md`), pictures and fonts; one enclosing folder at most. `Theme\PackageValidator` checks a ZIP (`inspect()`) or folder
(`validateInstalledDirectory()`) before anything is installed.

### 7.3 Installing, choosing and previewing

Switched on with `theme.enabled: true`:

```yaml
theme:
  enabled: true
  default: example-harbour  # the bundled theme a site is drawn in until another is chosen ('default')
  package_format: cattophp-theme
  page_families: [admin]  # the families a theme may wrap
  bundled: '%kernel.project_dir%/themes'  # the project's own themes, in its code
  installed: '%kernel.project_dir%/var/themes'
  staging: '%kernel.project_dir%/var/theme-imports'
  public_root: '%kernel.project_dir%/public'  # each theme's browser files go into its themes/ folder
  choice: CattoPHP\Theme\MemoryThemeChoice  # where the active theme is kept (Theme\ThemeChoice)
  registry: ~  # an optional index of installed themes (Theme\ThemeRegistry)
  pages: App\View\Pages  # the project's pages, for the page renderer (Theme\PageProject)
```

- `bin/console cattophp:themes:install` installs the bundled themes, publishing their browser files into
  `public/themes/<slug>-v<version>/` (the starter runs it after each Composer install and update; `--force` replaces a
  release of the same version in development).
- `Theme\ThemeManager`: `themes()`, `theme($key)`, `activeTheme()`, `activate($key, $actor)`, `stageImport($zip)` then
  `importStaged($token, $actor)` (an upload checked, shown, installed), `delete()`. Releases sit side by side as
  `<slug>-v<version>`, immutable; the bundled default is read from the code; changes are logged.
- **Which theme is active** is the project's to keep (`Theme\ThemeChoice`: `chosen()`, `choose($key, $actor)`);
  `MemoryThemeChoice` keeps it for one run, so a site without its own keeps the default. `Theme\ThemeRegistry`, an
  optional index, is rebuilt from the folders, which always count.
- **Preview:** a reader the project allows (`PageProject::mayPreview()`) sees a page in another installed theme by
  `?theme_preview=<key>`.

### 7.4 The page renderer and `PageProject`

`Theme\PageRenderer` draws every page alike: the theme (active, or one the reader may preview) as Twig's `@theme`; the
data checked (template-safe names; text, numbers, true or false, null, lists, no object); the view model, the theme's
(`theme`, `theme_name`, `theme_asset_url`, `color_scheme`, `theme_preview_active`) then the project's
(`PageProject::model()`); `@platform/pages/<page>.html.twig` extending `layout` (the project's for the family, else the
theme's wrapper, else its base); served with the generator tag first in the head, laid out by `Http\HtmlLayout`.
`render($page, $data)` draws a page, `renderTemplate($page, $template, $data)` another template as the page named
(CattoPHP's own pages), `renderFragment($template, $data)` a bare piece for htmx without the theme (it may read
`scalars()`).

A project tells the renderer about its pages through a `Theme\PageProject` service (`theme.pages`):

```php
interface PageProject
{
  public function family(string $page): string;  // a theme may wrap a family with pages/<family>.html.twig
  public function mayPreview(array $data): bool;  // whether this reader may see it in another theme
  public function scalars(): array;  // values every template may read, a page or a piece
  public function prepare(string $page, string $family, array $data): array;  // pieces drawn before the page
  public function model(string $page, string $family, array $data, array $view): array;  // everything a page reads
  public function layout(string $family): ?string;  // the project's own layout for a family, else null
  public function generator(): string;  // "Example Notes 1.2.0", first in every page's head
}
```

The starter's `App\View\StarterPages` is the example: `FAMILIES` gives each page, its own and CattoPHP's (7.5), its
family, `admin` or `content` (one unlisted is `admin` if named `admin-...`); `scalars()` gives `site`; `model()` adds
the page's `title`, `lead`, `notices` and `current`, `site` (name, organisation, demo, the demo notice), `nav` (the
menu, each entry's `label`, `href`, `current`), `platform.styles` and `platform.scripts` (fingerprinted addresses) and
the identity band's values; `generator()` says "CattoPHP v0.3.0, rrcatto/cattophp-starter v1.0.0" from the installed
versions. Change the menu; keep the shape.

### 7.5 CattoPHP's own pages in the theme

CattoPHP's own pages (signing in, the error page, Administration's Settings, Roles and Activity log, Sign-in and
security) extend `@CattoPHP/page/stock.html.twig`, filling block `page_body` when themed, else `cattophp.layout`'s
`body`. With `theme.pages` set they are drawn by the page renderer as pages named `sign-in`, `error`, `admin-`
`settings`, `roles`, `role`, `role-new` and `activity`, and `account-` `security`, `two-step` and `recovery-codes`
(`Http\StockPages::PAGES`; `PageProject::family()`, `prepare()` and `model()` receive them), in the theme's frame with
CattoPHP's page head, identity band, notices and the page's rows. A page on the stock frame fills `stock_main` (actions,
if any, in `stock_actions`), its data giving `title`, `lead`, `cattophp_notices`. A project replaces one only to change
it, at `templates/bundles/CattoPHPBundle/` (`error`, `sign_in/*`, `admin/*`, `account/*`); replacements made for 0.3.0
still work; the starter needs none. The project's sprite must hold the icons they draw: `action-` `add`, `back`,
`disable`, `done`, `email`, `enable`, `filter`, `generate`, `grant`, `open`, `remove`, `reset`, `revoke` and `save`, and
the pager's and the live refresh's.

### 7.6 Making a theme of the project's own

Rename the starter's theme (3.5); change the frame in `base.html.twig`, keeping the two blocks, `platform.styles`,
`platform.scripts`, the nonce on every script and `main#cl-main`; paint in `public/css/theme.css` (the project's frame
classes, laid out by `public/css/app.css`, painted like Core's); look at every page at desktop and phone width; run
`composer qa`.

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
| Themes (`theme`) | | |

At start, what the modules switched on need is checked, every problem said at once with what to do.
`bin/console cattophp:modules` lists every module, whether it is on, what it needs and whether that is there, and its
tables, each made or not; it fails while a problem remains. Switch a module on, run `cattophp:modules`, then
`cattophp:migrate`. A module's task is registered only with the scheduler on, placed by `<module>.task_priority`. Some
modules leave a part to the project's task (a daily clean-up, the mailing sender each minute), as their sections say.

### 8.2 The scheduler

Runs a project's tasks (`Scheduler\ScheduledTask` services, 4.7) when due, recording each run. A task never run is due;
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
  whenever `APP_ENV` is production: "A task is run as if it were another moment only on a development site, and this
  site's APP_ENV is production. Try it on a development site."
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

- Settings (a connection, 2.10): `DIDIT_API_KEY`, `DIDIT_WORKFLOW_ID`, `DIDIT_WEBHOOK_SECRET`;
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
  connection (2.10): `check()`, `status()`, `detailsSaved($before)`, `forget()`; only a connected mailbox is read, one
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
  (5.4). A project keeps its document types, placeholders, templates and pictures; the owner edits a design in one form
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
  `Search\PageHead::complete($page, $meta, $path, $query, $title)` gives the head, its language from the locale (5.4; no
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
`parameters()`. `Payments\GatewayConnections` (2.10): `check($gateway, $ask)` (refused while a key has no value; `$ask`,
the project's question to the provider, gives null when the keys are accepted, else its words), `status()`,
`connected()`, `drop()` (every key forgotten, `.env`'s too), `resetToEnv()`.

### 8.15 Connections to outside services

`Settings\ServiceConnections` keeps each connection's last real try by name (`didit`, a gateway's): when, whether it
worked, what went wrong, what it learnt, a keyed fingerprint of the details (so details changed since, on the page or in
`.env`, count as unchecked). States: `none`, `unchecked`, `failed`, `connected`.
`record($name, $details, $connected, $error, $learnt)`, `status()`, `connected()`, `forget()`; kept in
`cattophp_connections`. A project's own connection uses it too, its page as 2.10 says.

## 9. The checks

### 9.1 `composer qa`

Every check but the browser's, in one command:

| Script | What it runs |
|---|---|
| `composer test` | PHPUnit's Application suite (on the test database): the kernel, the schema, the pages, signing in and joining, Your account's name form, Administration, Sign-in and security |
| `composer contracts` | the Contracts suite: CattoPHP's contract checks of the project's templates, theme, scripts, words, addresses |
| `composer deploy-checks` | the Deploy suite: the settings wizard, the kit's file names, what keeps a site safe |
| `composer analyse` | PHPStan at level 8 over `src`, `tests` and `install` |
| `composer release-check` | `README.md` and `CHANGELOG.md` name `composer.json`'s version |

Any PHPUnit warning, notice or deprecation fails a run; set `COMPOSER_PROCESS_TIMEOUT=0` for a long one; read PHPUnit's
own output. The starter's tests survive a rename: they read the theme's published stylesheet and the site's name by
`ApplicationTestCase::themeStylesheet()` and `appName()` (from `phpunit.xml.dist`), `PagesTest` reads the home page's
one `h1` (its title that heading and the name), `pages.json` pins none for `/`, and `KernelTest` and `MigrateTest` check
only that the starter's own task, tables, role and modules are there.

### 9.2 The tests and their database

The tests boot their own kernels as `test`, never from `.env` (`phpunit.xml.dist` forces `APP_ENV=test`, an example
installation, `MAILER_DSN=null://null`), on `DB_TEST_NAME`, never the site's database. Every class has its tests; a
page's goes through the page, records made by the services it calls; mail is read with `getMailerMessages()`.

### 9.3 The contract checks

Rules about every use of a component are checked from the source, which a page test reaches only where it draws; the
starter's `tests/Contracts/` runs them, and a project keeps them and adds its own:

- **One owner for each component's markup** (`Ui\Contract\TemplateContract::duplicates($templates +
  TemplateContract::cattophpTemplates())`): a component's classes only in its own template, `<table>`, `role="dialog"`
  and `role="progressbar"` only by their components.
- **An icon on every button** (`withoutIcon()`, `menusWithoutIcon()`); **every icon named in the sprite**
  (`namedIcons()`, `missingSymbols($named, $sprite)`), the sprite as `app:icons` writes it.
- **The theme paints, never lays out** (`Ui\Contract\SourceAudit::themeGeometry($css, $file, $projectClasses)`, 7.1);
  each theme folder, named by its slug, passes `Theme\PackageValidator::validateInstalledDirectory()`.
- **Scripts draw no component markup** (`SourceAudit::javascript()`); **Stimulus actions are whole**
  (`SourceAudit::stimulusActions()`).
- **No row number in an address** (`Ui\Contract\AddressContract::rowNumbers(RouteCollection $routes, array $allowed =
  [])`): a route parameter named `id` or `*_id`, or digits-only, is refused; an allowance (`'<route name> <parameter>'
  => 'reason'`) without a reason, or needed no more, is reported. The starter's `AddressContractTest` allows none.
- **The strict Content-Security-Policy holds** (2.7) in every template.
- **The words** (`WordsTest`): the project's own files (`src`, `templates`, `themes`, `config`, `tests`, `bin`,
  `deploy`, `install`, `README.md`, `CHANGELOG.md`, `.env.example`, `composer.json`, `public/css/app.css`,
  `public/index.php`) hold no em dash, curly quote or en dash but between two digits (a range, "1–25"), every email
  address in them at `example.com`, `example.org` or `example.net` (or `users.noreply.github.com`).

### 9.4 The browser checks

Chromium, through Playwright, opens every page signed out and as the administrator at 1440 and 390 pixels, failing on
anything a person would see go wrong (steps: `tests/Browser/README.md`). Playwright is installed once outside the
project (`npm install playwright`, `npx playwright install chromium`, `PLAYWRIGHT_MODULE` at its
`node_modules/playwright`): no Node dependency.

- `tests/Browser/every-page.cjs` follows every link from `pages.json`'s addresses, signed out and as the administrator
  (a `cattophp:sign-in-link` link, then two-step set up from the key shown). Each page answers 200 (or what `pages.json`
  expects), has no console or page error, Content-Security-Policy refusal or failed request, never scrolls sideways,
  cuts no table cell short on a phone (`stack: true`, 6.2; a stacked cell without `data-label` fails, a `fit: false`
  table is left alone), has every picture and icon, is served laid out by `HtmlLayout`, has one `h1`, answers the same
  with JavaScript off.
- **The crawl sees an empty database**, so no link reaches a page showing records: a project's own checks make them
  through the pages and look.
- `pages.json` adds what links miss and what a page must show: `start`, `skip` (patterns never opened), `perPattern`,
  `pages` (each `address`, `who`, `status`, `landsOn`, `heading`, `contains`, `selectors`).
- **A project's own checks**, `tests/Browser/checks/<name>.cjs`, each also run alone (`bin/gate <name>`, `node`): the
  starter's `account-name.cjs` (a name refused, why beside the field; a new one saved, the old put back) and
  `member.cjs` (a member who joins by signing in is refused the Activity log until the administrator gives her a new
  role reading it). `tests/Browser/harness.cjs` gives settings, `signIn(browser, list)` (the administrator),
  `signInAs(browser, email, list)` (anyone, in a context of its own; returns `{state, statePath, context, page}`),
  `made(kind, id)` (a record the check made, noted once made), `signOut(page)`, `cleanup(statePath, cookies)`, `php()`,
  `settle()`, `inspect()`, `layoutFaults()`. In a `finally` a check undoes through the pages what it changed, signs out,
  calls `harness.cleanup()`.
- **The clean-up hook**, `tests/Browser/cleanup.php`, returns
  `static function (ContainerInterface $container, array $made): array`: each `{kind, id}` noted, last first, removed
  through the application's services (never by hand in the database), a line of words each; an unknown kind throws.
  Services are reached by public names in `config/services.yaml` (the starter's `Accounts`, `browser_checks.settings`,
  `browser_checks.roles`), a project making its own public alike. A record no page removes is often a missing feature.
- Settings, from the environment, then `.env`: `BROWSER_BASE_URL` (`APP_URL`), `BROWSER_ADMIN_EMAIL`
  (`APP_ADMIN_EMAIL`), `BROWSER_WHO`, `BROWSER_WIDTHS`, `BROWSER_PHP`, `BROWSER_PAGES_FILE`, `BROWSER_OUTPUT`
  (`var/browser`, `results.json`). Against a running site: `node tests/Browser/every-page.cjs`, signed out only
  (`BROWSER_WHO=visitor`) once the administrator has two-step.
- `tests/Browser/fixture.php`: `start <email>` lists the address in `AUTH_SIGNIN_ALLOWED_EMAILS` through Settings when
  `SignInRules::mayJoin()` says no (the gate's site). `cleanup` runs the hook, then each sign-in, last first (the listed
  addresses put back exactly, then an account the check made deleted through `Accounts::delete()`,
  `lastAdministrator: true`, or for an existing one only its link, sign-ins and log entries, by SQL in one transaction,
  so the log names no check address), then the sessions. A project keeping accounts elsewhere changes `ACCOUNTS` at its
  top, deleting through `AccountRecords::forget()`.
- A check is judged by its exit code, a line per failure: `FAIL <who> <width> <address>: <what is wrong>`.

### 9.5 The gate

`bin/gate` runs every check in under a minute (the starter's in about 16 seconds), judged by exit codes. Once, install
Playwright (`PLAYWRIGHT_MODULE`) and let the `.env` database user make databases (`ALTER ROLE <user> CREATEDB`). The
gate checks what it needs (node, Playwright, PHP 8.5.12+, Composer, that right), makes two databases, runs `composer qa`
on one and, beside it, the crawl on the other (every table made, served by PHP's web server on a free 127.0.0.1 port as
the tests' installation), then the project's checks by name, one after another, each step `check-<name>` into
`<run>/checks/<name>/`, and drops both. `bin/gate [--no-crawl] [check ...]` (`--help`) runs only the checks named
(`lending`, `lending.cjs`, a path) on the browser database alone, no `composer qa`, after a short crawl (step
`crawl-short`: start pages and those `pages.json` names, links unfollowed, 1440 only) unless `--no-crawl`. The run's
folder (`GATE_OUTPUT`, else `var/gate/<date and time>`) holds `summary.txt` (each step's exit code, seconds) and
`<step>.log`; it exits 0 only if every step did. Where PHP 8.5 is not the machine's `php`, `PHP_RUN` goes before every
PHP and Composer command, such as a container sharing the project's folder and network:

```sh
PHP_RUN='podman run --rm -i --init --network host --userns=keep-id -v "$PWD:$PWD" -w "$PWD" -e HOME=/tmp example/php:8.5' bin/gate
```

`GATE_OUTPUT` then lies in the shared folders. The gate runs only `composer qa` through Composer, so the project's
folder is its one mount; a `composer install` there with CattoPHP from `../cattophp` (1.5) also needs
`-v "$PWD/../cattophp:$PWD/../cattophp"`. A release is made only if the gate passes.

### 9.6 While building, and before a release

While building: the tests of what changed (`vendor/bin/phpunit --filter NoteTest`), PHPStan when PHP changed, Twig lint
when templates did (`bin/console lint:twig templates themes`), contract checks it could break, its pages' browser
checks. Before a release: the whole gate from a cleared cache, and the dependency checks (2.14).

## 10. Deploying

The starter has two ways onto a server, both generic (naming no server, asking the installer every choice an owner
makes) and a clean first installation; each README (`deploy/podman/README.md`, `install/README.md`) gives the exact
steps as seen.

| | The Podman kit (`deploy/podman/`) | The install script (`install/`) |
|---|---|---|
| Runs as | the site's Linux user, in a rootless Podman pod | the server's PHP-FPM, as the site's account |
| Suits | a server hosting other sites behind its web server | a server for the site |
| Updates | `update`, keeping two releases, and `back` | none: each run is a clean installation |
| The scheduler | a container running `cattophp:schedule:run --watch` | cron each minute |

Both ask demo or live and walk through every setting in `.env.example` (`install/env-wizard.php`): each checked by its
kind, asked again with what to write when refused; secrets typed hidden; `APP_ENV` kept, or `development` for a new site
(never production); `APP_KEY` kept or made; Mailpit refused; the file in the example's order, readable only by the site
(and the install script's PHP-FPM group). `--answers FILE` gives settings in advance as `KEY=value` lines, each still
checked. A database holding tables is emptied only once the username is typed.

### 10.1 The rootless Podman kit

A pod of three containers behind the server's own web server, which keeps ports 80 and 443 and the certificate: `web`
(unprivileged nginx, pinned: serves the release's `public/`, passes pages to PHP, gzips text with
`gzip_http_version 1.0`, has browsers keep `css`, `js`, `img`, `themes`, `cattophp`, `vendor` 30 days, runs only
`index.php`), `php` (PHP-FPM from `Containerfile`, PHP pinned) and `scheduler` (the same image, `--watch`). PHP runs as
the site's user, the code read-only, so a break-in cannot change it or reach the user's keys, cron or other sites.
PostgreSQL and mail stay on the server, whose `127.0.0.1` the pod reaches at `10.0.2.2` (slirp4netns).

**The server needs** systemd, Podman 4.4+, `slirp4netns`, Git, curl; PostgreSQL 13+ on 127.0.0.1 with password sign-ins;
a mail server; a web server with the site's certificate.

**The steps**, for an application `notes` run by account `notes`:

1. As root: `adduser notes`; `bash root-prepare.sh notes` (the user ids rootless containers need, linger); the site's
   PostgreSQL role and database (`CREATE ROLE notes LOGIN PASSWORD '...'; CREATE DATABASE notes OWNER notes ENCODING
   'UTF8' TEMPLATE template0;`); and in the site's HTTPS server block, in place of its PHP handling:

   ```nginx
   proxy_read_timeout 180s;
   client_max_body_size 64M;
   location ^~ /.well-known/ { try_files $uri =404; }
   location / {
       proxy_pass http://127.0.0.1:8081;
       proxy_set_header Host $host;
       proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
       proxy_set_header X-Forwarded-Proto $scheme;
   }
   ```

   A control panel rewriting the block may drop the `X-Forwarded-For` line: put it back.
2. As the site's user: `bash notes-podman.sh install --port 8081`: the newest tag copied, the image built, packages
   installed without the development ones, the settings asked (`10.0.2.2` offered for `DB_HOST`, `smtp://10.0.2.2:25`
   for `MAILER_DSN`), the database checked, the install scripts, `cattophp:migrate`, the cache and `cattophp:modules`
   run, the pod started till the site answers. Until mail works,
   `bash notes-podman.sh console cattophp:sign-in-link <address>` prints a link.
3. Each update: the release tagged (at the owner's word), the script fetched again, then
   `bash notes-podman.sh update --version 1.0.1`: the new release built beside the running one (`vendor` hard-linked
   to the running release's, its own cache through `APP_CACHE_DIR`), its install scripts and `cattophp:migrate` run,
   switched, restarted and checked; it and the one before kept, older ones deleted with their images and caches. `back`
   runs the release before again (the database as the newer release left it).

**Commands:** `install`, `update` (`--version X.Y.Z` or `--source <folder, .zip or .tar.gz>`), `back`, `status`,
`logs web|php|scheduler`, `console <command>`, `restart`, `stop`, `start`. **Options:** `--port`, `--version`,
`--source` (the files Git would commit, never `vendor/`, `var/` or `.env`), `--mode demo|live`, `--answers`,
`--empty-database`, `--pod NAME`, `--no-service` (`podman kube play`, not a systemd user service, off a server),
`--repo URL`; `SITE_HOME` moves the site's folder.

**Where everything is:** `~/.env` (mode 600), `~/<app>/releases/<version>` and `~/<app>/current`, `~/<app>/pod/`,
`~/var/` (sessions, installed themes, logs, each release's cache), `~/.config/containers/systemd/<app>.kube`; back up
`~/.env`, `~/var` and the database. The release's `public/` is read-only: uploads live under `var/`, served by a
controller. A PHP security release or a module's extension (intl) goes into `Containerfile`, then release and update.

### 10.2 The install script

`bash install.sh notes`, as root, on a server with PHP 8.5.12+ (command line and PHP-FPM, `composer.json`'s extensions;
less is refused, saying what), Composer, Git, PostgreSQL 13+, cron, a mail server, a web server. It copies the release
to `/usr/local/lib/php/<app>/<username>/releases/<version>`, owned by root, `.env` and `var` linked to the site's home;
installs the packages; writes `/home/<username>/.env` (keeping an older one as `.env.before-<version>`); makes or
empties the database; runs the install scripts, `cattophp:migrate`, `cache:clear`, `cattophp:modules` as the site's
account; points `current` at it; writes `/etc/cron.d/<app>-<username>`. The web server serves `current/public` through
PHP-FPM as the site's account, giving PHP `$realpath_root` so a release is never half seen, refusing every `.php` but
`index.php`. Commands: `cd .../current && runuser -u <username> -- php bin/console <command>`; options `--version`,
`--source`, `--mode`, `--answers`, `--php-fpm-user`, `--repo`.

### 10.3 After an install or update

- `APP_ENV` is what it was (`development` for a new site): say so.
- `cattophp:modules` passes and the scheduler runs.
- A test email goes through the site's real transport (`cattophp:test-email`).
- The site answers at its address, signed out and in.

## 11. Releasing

Nothing here is done without the owner's word (2.1). When the owner says to push, it is done at once: the gate takes
minutes, and a push asked for is never held for hours.

### 11.1 Release notes are complete

Before tagging, tick off against the CHANGELOG the release's added and removed files, its `.env.example` and
`composer.json` changes and the development log since the last tag; its notes name every change a person would notice;
whether a clean installation is needed or what an update does; new, renamed or removed `.env` settings; new and removed
packages with licences; removed pages, routes or commands; changed legal texts and third parties a page talks to; each
installation kind's update steps; the CattoPHP version required. `README.md` names the version as current;
`## Unreleased (x.y.z)` becomes `## x.y.z · <date>`.

### 11.2 The privacy audit

Before any commit and push, 2.2's checks: `git diff --cached --name-only` shows only the project's files, nothing names
an installation (a person, an address, a secret too).

### 11.3 Tags and releases

- The version is the owner's choice; `composer.json`, the README and the CHANGELOG agree. An annotated tag `vX.Y.Z` on
  the release commit is pushed with it, a GitHub release made with the source:

  ```sh
  git archive --format=zip --prefix=<name>-vX.Y.Z/ -o <scratch folder>/<name>-vX.Y.Z.zip vX.Y.Z
  gh release create vX.Y.Z <scratch folder>/<name>-vX.Y.Z.zip --verify-tag --latest
  ```

- The deploy kit installs and updates from these tags, each with `composer.json` and `composer.lock`.
- Packagist, or any registry, only at the owner's word; until then a package comes from GitHub (1.5).

### 11.4 Updating CattoPHP in a project

Read CattoPHP's CHANGELOG for every version since the project's (its "Upgrading from" notes: breaking changes, new
schema steps); raise the requirement in `composer.json` for a new minor or major version,
`composer update rrcatto/cattophp`; run `cattophp:migrate`, `cattophp:modules`, `composer qa` and the browser checks;
record it under the project's Unreleased with the CattoPHP version required.

A change the project needs in CattoPHP is made there, released at the owner's word and taken by updating, never
patched in the project's `vendor/` or copied into it.
