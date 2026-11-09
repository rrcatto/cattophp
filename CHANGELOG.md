# Changelog

What each version of CattoPHP brought, newest first.

## Unreleased

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
