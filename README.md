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
"require": {"rrcatto/cattophp": "^0.2"}
```

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
- **Mail** (`Mail\Mailer`): through the configured transport (`MAILER_DSN` and the `MAIL_*` sender settings), a demo
  site's emails marked; `cattophp:test-email`.
- **Building blocks**: lengths of time (`Foundation\Duration`: `30m`, `1d`, `1h30m`), Base32, the secret cipher and
  keyed hashes (`Security\`).

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
