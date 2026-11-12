# Changelog

What each version of CattoPHP brought, newest first.

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
