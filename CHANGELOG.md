# Changelog

What each version of CattoPHP brought, newest first.

## 1.0.1 · 14 November 2026

**Breaking change**

- Two-step sign-in is each person's choice by default. `AUTH_TWO_STEP` keeps its three choices, `CHOICE` (each person's
  choice), `ADMINS` (required of administrators) and `EVERYONE` (required of everyone), but when `.env` gives nothing, or
  a value that is none of the three, it is now `CHOICE` (it was `ADMINS`): the setting's own default in
  `Settings\FoundationSettings` is `CHOICE`, and `SignIn\SignInRules::twoStepRequired()` reads any other value as
  `CHOICE`, requiring it of nobody. A choice saved on the Settings page is kept, and its Reset goes back to `.env`'s
  value, else `CHOICE`. Administrators are no longer asked to set up two-step sign-in at their next sign-in unless the
  setting says so; they set it up on their Sign-in and security page when they choose.

**On a project's servers:** a project relying on the old default (administrators made to set up two-step sign-in, with
no `AUTH_TWO_STEP` in `.env`) sets `AUTH_TWO_STEP=ADMINS` in `.env`, or `ADMINS` in its `cattophp.defaults`, or saves
Required of administrators at Settings > Sign-in. Nothing else changes: no schema step, no new `.env` key.

**Added**

- The Settings page saves on an installation without a database. With `storage.type: files`, what it saves is kept in
  `settings.json` in the storage folder (`Settings\FileSettingsStore`, in place of `MemorySettingsStore(false)`),
  readable by the application's account alone (folder 0700, file 0600), written whole to a file beside it and put in
  place under `settings.json.lock`, a secret encrypted as in the database; `Settings::canSave()` is true, Reset works
  and each change is in the activity log. A file that cannot be read never stops the application: every setting has
  its starting value, the fault is logged once, `Settings::readProblem()` and the page say so (the new
  `Settings\SettingsStoreProblem`), and the next save puts the file aside (`settings.json.damaged-<when>`) and starts
  afresh; a save that cannot be written is refused in words. Until something is saved there is no file and every value
  is as before. A `storage.folder` inside `ui.public_root` is refused when the container is built.
- Settings held fixed: `cattophp.settings.fixed` (shown on the Settings page with their values, saying they are fixed)
  and `cattophp.settings.hidden` (left off it), each a list of settings by name (`APP_URL`) or groups by key
  (`sign_in`), and `Settings\FixedSettingsProvider` (tag `cattophp.fixed_settings`, `ServiceTags::FIXED_SETTINGS`;
  `fixedSettings()`, `hiddenSettings()`) for a project that decides in code; gathered by `Settings\FixedSettings`. A
  fixed setting keeps its configuration's value whatever was saved for it before, and `Settings::save()`, `set()`,
  `clear()`, `reset()` and `resetGroup()` refuse it ("... is fixed in this installation's configuration and cannot be
  changed here."), so a request made by hand changes nothing; `isFixed()`, `isHidden()`, `groupIsFixed()`. On the page
  a fixed field cannot be changed and has no Reset, a group fixed whole no Save; a hidden setting is not drawn and a
  group hidden whole answers not found.
- Facts on the Settings page: a `Settings\FactsProvider` (tag `cattophp.settings_facts`, `ServiceTags::SETTINGS_FACTS`;
  `settingsFacts()`) gives `Settings\FactGroup`s (key, heading, explanation, lines) of `Settings\Fact`s (label, value),
  drawn after the settings, each a section `#facts-<key>` with a key-value list, never a form.
- A longest length of time: `Setting`'s `longestTime` (in Duration's notation, beside `shortest`), refused on save
  ("How long a sign-in lasts must be at most 365 days."), a starting value beyond it read as the longest with the page
  saying so (`Settings::isCutToLongest()`), its bounds in the field's help. Checked when a setting is made. Settings
  without one are unaffected. `AUTH_SESSION_TTL` takes from 5 minutes to 365 days.
- `SignIn\SignInLength` and `SignIn\SignInLengthStorageFactory`: how long a sign-in lasts governs the browser's session
  (below, Changed); `sign_in.session_lifetime` (true by default) switches the session part off.

- Two components, `data.column-chart` (amounts over time as columns, one for each slice of a period: `label`, `columns`
  of `label`, `value` and optionally `words`, `href`, `named`; about six named beneath, half of them on a phone; the
  same figures in a table under Show the figures; `empty` when every column is nothing) and `data.bar-list` (things
  compared by one figure: `label`, `rows` of `label`, `value` and optionally `words`, `href`, `note`; bars against the
  largest or against `max`). Lengths are `cl-share-N` classes, never a style; every figure is in words, to a screen
  reader too; the bars show with the system's own colours forced. Their classes are in
  `Ui\Contract\TemplateContract::OWNERS`, so a page drawing its own copy is found. The column chart comes from
  CattoLMS's money-in and emails-sent charts, the bar list from its distribution chart. No theme needs a change.
- Two components, `data.rating` (stars out of five, the mean to one decimal and how many reviews it comes from:
  `count`, `average` from 0 to 5, `compact`, and `empty`, said when there are none, `''` drawing nothing; the same as one
  sentence for a screen reader, "Rated 4.6 out of 5 from 128 reviews") and `feedback.countdown` (to `ends_at`, a Unix
  time, by the server's clock, `now` 0 being the clock now: `words`, the time left from `largest`, week or day, down to
  the seconds, then `until` with the moment in the site's time zone; the time left is drawn hidden, and `cattophp.js`
  shows it and keeps it ticking from the server's time, so a page without JavaScript says only the moment, never a count
  that has stopped; nothing once the moment has passed, and at zero the script takes it away, or the element around it
  marked `data-cl-countdown-scope`; `role="timer"` with `aria-live="off"`). Their classes (`cl-rating-summary`,
  `cl-ui-countdown`) and `role="timer"` are in `Ui\Contract\TemplateContract`, so a page drawing its own copy is found.
  The rating comes from CattoLMS's rating summary, with the same markup and `cl-rating-*` classes; the countdown from
  its site notification's.
- A list put in order by hand: `data-cl-sortable` in `cattophp.js`, with `@CattoPHP/page/sortable-lines.html.twig` (the
  keys' help, the status line and the error line), `sortable-handle.html.twig` and `sortable-moves.html.twig` (Move to
  top, Move up, Move down, Move to bottom, each a form posting `index`, the row's place after the move from 0, `order`,
  the rows' keys as shown, and `_token`, so the list is put in order without JavaScript or a pointer). With JavaScript a
  row is dragged by its handle, moved by the arrow keys, Home and End on it, or by its buttons, without a page load: the
  move shows at once and the server's answer (the list alone or the whole page, found by the list's id, its
  `data-error` holding a refusal) is swapped in; when the request fails the list as it was comes back. The event
  `cattophp:sortable-saved` follows each move. From CattoLMS's flat use of its sortable tree. The buttons name the icons
  `action-move-top`, `action-up`, `action-down` and `action-move-bottom`, the handle `action-drag`: a project's sprite
  needs them.
- `Ui\HalfRows::rows($blocks)`: blocks put in rows by their width, two half blocks one after the other side by side
  and any other block alone (from CattoLMS's `Support\HalfRows`, with its test).
- The layout of landing pages and their editor in `cattophp.css`, from CattoLMS's: `cl-landing-*` (the hero, the
  sections in rows of halves, checklists, benefits, questions and answers, the call to action; the editor's fixed hero
  row, address, repeated rows, a section's width and the drawing of the page beside the list), `cl-page-sketch` (a cell
  for each section, `data-accent` marking those that sell) and `cl-width-choice` (Half and Full width as two small
  feature cards side by side however narrow). A theme paints them.
- `Http\CsvDownload`, a streamed response for Download as CSV: `new CsvDownload('campaigns', $header, $rows, ?$day)`,
  rows any iterable written as they come; UTF-8 with a byte-order mark; `Content-Disposition: attachment` named
  `campaigns-2026-11-13.csv` (the name made safe, the day added); `Cache-Control: no-store`; a cell of words starting
  with `=`, `+`, `-`, `@`, a tab or a carriage return written after an apostrophe so a spreadsheet never runs it, numbers
  left as numbers, true and false as Yes and No.
- A module's pages join a project's menu: `Theme\MenuProvider` (tag `cattophp.menu`, `ServiceTags::MENU`,
  autoconfigured) gives `menuSections()`, each a `Theme\MenuSection` (key, label, icon, entries) of `Theme\MenuEntry`s
  (`MenuEntry::route()` or `MenuEntry::address()`, each with the permission it needs, if any, and an optional icon).
  `Theme\Menus::sections()` (always wired, nothing to do without a provider) gives, for the person signed in, each
  section with an entry they may open, as plain arrays (`key`, `label`, `icon`, `current`, `entries` of `label`, `href`,
  `icon`, `current`), in tag priority order, the entry closest to the page's path current; a later section with the same
  key adds its entries to the first. A `PageProject::model()` adds them to its navbar as drop-downs (guide, 7.4).
- A new optional module, the Campaign Marketing Manager (`cattophp.marketing`, needs the database; its pages below): campaigns (`Marketing\Campaigns`: the list with its status filter, search and sort; make, change, copy,
  activate, pause, archive, restore, delete only one never published nor opened; the offers each promotes; its history
  from the activity log), their adverts with a picture checked as a JPEG, PNG or WebP by what it holds (`Adverts`,
  `Pictures`), publications with a code suggested from the channel (`Publications`: `fb1`, `fb2`), other spend
  (`Spend`), the channels (`Channels`, twelve to start), the visitors and each opening of a tracking link (`Visitors`,
  `Touches`), the tracking links themselves (`TrackingLinks` and the public `Marketing\Pages\TrackingLinkController`,
  routes in `config/routes/marketing_links.php`: `/c/{campaign}` with `?src=` and UTM tags, and
  `/c/{campaign}/{publication}`), and the figures (`CampaignFigures`: clicks, unique visitors, repeat visits, openings
  set aside, spend, in total and by campaign, channel, publication, advert, place, country and day, and what needs
  attention). A link records the touch and forwards with 302; only a person gets a visitor and a first-party cookie
  holding a random token; a link preview or bot is recorded and set aside; a repeat within the same visit is not a new
  click; a browser asking not to be tracked is counted with its country alone; a paused or ended campaign still
  forwards; a failure while recording never stops the visitor. What a project sells and how it names people, orders,
  promotions and money comes in through `Marketing\MarketingProject` (`marketing.project`; `Marketing\NoProject`
  otherwise). Settings (group "Campaign Marketing Manager"): `MARKETING_VISITOR_REMEMBERED`,
  `MARKETING_SALE_COUNTS_WITHIN`, `MARKETING_SALE_COUNTS_FOR`, `MARKETING_SAME_VISIT`, `MARKETING_IP_KEPT`,
  `MARKETING_HISTORY_KEPT`, `MARKETING_LATEST_SALES`, `MARKETING_ATTENTION_ENDING`, `MARKETING_ATTENTION_NO_CLICKS`,
  `MARKETING_HONOUR_DO_NOT_TRACK`, `MARKETING_PICTURE_LARGEST`. Permissions `MARKETING.VIEW`, `MARKETING.CAMPAIGN.MANAGE`,
  `MARKETING.PEOPLE.VIEW`, `MARKETING.SETTINGS.MANAGE`. With the scheduler on, the nightly `marketing_tidy_up` removes
  old IP addresses and makes old visitors anonymous. Forgetting an account leaves its visitors naming nobody. Schema
  step `202611132100-marketing-campaigns` (`cattophp:migrate`); a project's access control lets everyone open `^/c/`.
- The Campaign Marketing Manager's ready-made pages (`Marketing\Pages\`, routes `cattophp_marketing_*` under `/marketing` in
  `config/routes/marketing.php`, templates `@CattoPHP/marketing/`, on the stock frame through `Http\StockPages`): the
  overview (`/marketing`: what needs attention; for a period chosen by feature cards or two dates, clicks, unique
  visitors, repeat visits, link previews set aside and spend; a comparison by campaign, channel, publication, advert or
  place, sortable, with Download as CSV; clicks over time; countries; refreshing every 30 seconds), the campaigns
  (status as feature cards, search, sortable headings, pages, CSV), a new and a changed campaign (offers and promotions as
  feature cards), one campaign (its head facts as badges; Activate, Pause, Edit, Copy, Archive, Restore, Delete; figures;
  its own tracking link; its publications, each link ready to copy with its QR code; adverts as cards; what it promotes;
  details; budget and costs, added and removed; history), a publication (channel and advert as feature cards; once saved
  its link in three forms, its QR code shown and downloaded as `<campaign>-<publication>-qr.svg`; retire, restore,
  delete), an advert (picture upload, shown only with `MARKETING.VIEW`; retire, restore, delete) and the settings (the
  module's group saved as Administration > Settings saves one, and the channels: add, change, Up and Down, retire,
  restore, delete). Page names `marketing-overview`, `marketing-campaigns`, `marketing-campaign-new`,
  `marketing-campaign-edit`, `marketing-campaign`, `marketing-publication-new`, `marketing-publication`,
  `marketing-advert-new`, `marketing-advert`, `marketing-settings` (`StockPages::PAGES`). `Marketing\MarketingMenu`
  (section `marketing`, "Marketing", icon `nav-marketing`: Overview, Campaigns, Settings, each behind its permission,
  nothing without the routes), `Marketing\MarketingSubjectLinks` (the activity log links a campaign) and
  `Marketing\MarketingDetailWords` (money, statuses, kinds and channels in words). A project imports the route file
  beside the tracking links'. A campaign's or a publication's code can no longer be `new`.
- The Campaign Marketing Manager's pages in a project's own tables: a further line in a table cell (a
  publication's day and code, a campaign's code, each line of a change in the history) is a `small` or `span` of class
  `cl-marketing-line`, and the tracking link's copy field is drawn in spans, so a project whose tables keep a cell's
  blocks on one line still shows them as lines; what can be done to the record a publication's or an advert's form
  changes (retire, restore, delete) is a row of its own under the form (`cl-marketing-record-actions`); Add an advert has
  room under it; honouring Do Not Track is two feature cards on Marketing > Settings ("Honour it", "Count it like any
  other"), never a tick box.
- Landing pages for the Campaign Marketing Manager, the core (`Marketing\Landing\`; schema step
  `202611140010-marketing-landing`): a campaign's page, drawn at the campaign's own address `/c/{campaign}`, and an
  offer's page at `/landing/{slug}` (`cattophp.marketing.landing_prefix`), one each at most (`LandingPages`: made as a
  draft from a template, its settings, the publish checks, publish, unpublish, delete only one never public, the list
  with its filter, search and recent visits, `offerRenamed()` and `offerRemoved()` followed everywhere). A page is a flat
  list of sections, the hero first and full width (`LandingSections`: add, hidden until its first save; save, checked
  against its kind; show, hide, half or full width, shown always, while the campaign runs or while it is not; remove;
  move by index or step, an out-of-date order refused; each change one transaction under the page's row lock). Eleven
  kinds (`Kinds\`: hero, words, picture, video, offers, who it is for, outcomes, benefits, countdown, questions and
  answers, call to action) and a project's own through `SectionKind` (tag `cattophp.marketing_landing_section`); copy is
  plain text by `LandingCopy` (from CattoLMS, a refusal naming its field as a `CopyRefused`). Every published state is
  kept and never changed (`LandingVersions`; the database refuses a change), and an earlier one can be used again.
  Templates: CattoPHP's two (`BuiltInTemplates`: Several offers, A special with an end date;
  `cattophp.marketing.landing_builtin_templates`), a project's (`TemplateProvider`, tag
  `cattophp.marketing_landing_templates`) and those saved from a page, without its offers (`LandingTemplates`). What a
  page does while its campaign is not running: keep it, keep it with a note, or send visitors elsewhere (`NotRunning`).
  Pictures come from sources (`PictureSource`, tag `cattophp.marketing_landing_picture_source`: CattoPHP's `upload` and
  `offer`). How an offer looks and is bought on a page, who may edit an offer's page and what the frame needs come from
  the project (`LandingProject`, `cattophp.marketing.landing_project`; `GenericLanding` otherwise; `OfferCard`,
  `OfferAction`, `CardOptions`, `ProjectPart`, `LandingAccess`). The journey's events table (`Marketing\Events`,
  `Marketing\Journey`: a landing page opened, a person's only, and an offer's button pressed, `LandingPresses::accepted()`
  after the project's own handler accepted it), announced as `Landing\Events\LandingPageOpened` and
  `LandingOfferPressed`; every change announced as `LandingPageChanged` and in the activity log
  (`marketing.landing_page.*`, subject `marketing_landing_page`). Two settings: `MARKETING_LANDING_RECENT` (30d, the
  list's visits) and `MARKETING_LANDING_PICTURE_CACHED` (1h). No new permission. A campaign's copy takes its page as a
  draft; a campaign whose page has been public is archived, never deleted. Forgetting an account reaches the events and
  the pages' records.
- `Marketing\Offer` may give its own `slug` (the name in its own page's address, last and optional): an offer's landing
  page made without an address given starts there (`LandingPages::createForOffer()`), and from the offer's label only
  when it has none, so a project's page for a product keeps the product's own address name.
- `Marketing\Landing\OfferCard` may give `about` (last and optional): the words a hero left without words of its own
  shows under its headline, such as a product's whole description (`heroWords()`: `about`, else the card's `line`, which
  an offers section keeps short). The hero's form shows the same words as its supporting text's placeholder, so the
  editor sees what the page will show when the field is left empty.
- Landing pages for visitors (`Marketing\Landing\LandingViewBuilder`, one drawing for the public page, the editor's
  preview, a kept version and one section alone: `forVisitor()`, `preview()`, `version()`, `sectionPreview()`, `data()`,
  `draw()`, `drawSection()`, `press()`; the page `landing-page`, template `@CattoPHP/marketing/landing/page.html.twig`
  with `hero`, `section`, `paragraphs`, `offer-card`, `action` and `sections/<kind>` for the eleven kinds, CattoLMS's
  DOM and `cl-landing-*` classes, the hero the page's only `h1`). A campaign's tracking link `/c/{campaign}` (and a
  publication's with no destination of its own) draws the campaign's published page in the same answer, with
  `no-store` and `X-Robots-Tag: noindex`, its canonical address `/c/{campaign}` whatever link was opened; the click is
  the touch as before and the visit a `landing_opened` event against the same touch, a person's only, so a link
  preview gets the page and its Open Graph tags with its touch set aside and no visit. While the campaign is not
  running the page is kept, kept with a note above the offers, or forwards (302) where its setting says; a draft page
  or a draft campaign's forwards as the link always did. An offer's page at `/landing/{slug}` is drawn while it and its
  offer are public, else the site's ordinary not found; its visit gives no cookie. New route file
  `config/routes/marketing_landing.php` (a project imports it beside `marketing_links.php` and lets everyone open
  `^/landing/`): the offer's page, and under it and under `/c/{campaign}` the page's pictures (`/picture/{ref}`: only a
  picture the page names; publicly cached for `MARKETING_LANDING_PICTURE_CACHED` while public, `private, no-store`
  for its editors before then; nosniff and a sandboxing policy of its own) and its buttons (`/go/{press}`: the press
  recorded, then 302 only to what the section holds). Every GET button goes through `/go/`; every POST button posts the
  project's fields with `cattophp_landing` and `cattophp_return`. A video is framed (`youtube-nocookie.com`,
  `player.vimeo.com`) only when the site's Content-Security-Policy `frame-src` names its host, else it is a link. In a
  preview every button is disabled ("In a preview the buttons do nothing") and the page is `noindex, nofollow` with no
  canonical address. The head (`page_meta`: title, description, canonical, robots, og, twitter_card) is the page's own
  words, else the hero's, the share picture the one chosen, else the hero's, else the offer's;
  `LandingProject::page()` adds to it or replaces it. A call to action with no way to buy from the page leads to the
  offer's own page. Its buttons name `action-view`, `action-favourite`, `action-buy`, `action-request`,
  `action-continue`, `action-orders` and `action-sign-in` when the project gives no icon, and `action-jump-down`,
  `action-preview`, `action-play` and `action-back`. `LandingView` gains `notice`, `back` and `presses`.
  `GenericLanding`'s View names `action-view` (it named `eye`, not a sprite symbol). Its layout rules
  (`cl-landing-offers`, `cl-landing-offer-*`, `cl-landing-figure`, `cl-landing-video`, `cl-landing-badges`,
  `cl-landing-hero-inner`, `cl-landing-cta-actions`) are in `cattophp.css`.
- Landing pages' editor (`Marketing\Pages\LandingEditor`, `config/routes/marketing.php`; the menu's Marketing drop-down
  gains Landing pages). `/marketing/landing-pages` (`marketing-landing-pages`): every page with what it is for, its
  address, status, sections, last change and visits over `MARKETING_LANDING_RECENT`, narrowed by feature cards, searched,
  sorted and paged, and the saved templates, each deleted there; `/new` (`marketing-landing-new`): a page for a campaign
  or an offer without one, from a template chosen as feature cards. A page's editor, `/{reference}`
  (`marketing-landing-edit`, route `cattophp_marketing_landing_edit`), is one page holding everything: the head (status,
  what it is for and the address as badges; View or Preview, Publish or Unpublish, Delete while never public), what
  stops it being published, the sections (the hero fixed at the top, the others put in order by drag, keyboard or menu
  with the sortable list, each with its width as two feature cards, Edit, Show or Hide, Remove, and their Hidden, Not
  saved yet, Needs content and Left off the page badges; Add a section as feature cards, a kind held once marked
  "already on the page"; the drawing of the page), the open section's form (`?section=<reference>`) with its preview
  drawn by `LandingViewBuilder`, and on a campaign's page when each section is shown; Start from a template and Save as
  a template; the page's settings (an offer page's address, title, description, share picture, a campaign page's
  not-running choice as feature cards); Earlier versions with View (`/versions/v<n>`) and Use this version again; the
  preview (`/preview`). Each kind's fields are `@CattoPHP/marketing/landing/forms/<key>.html.twig`, with shared parts a
  project's kinds may include (`heading`, `rows`, `picture-field`: a picture posted as `<field>[source]` and
  `<field>[refs][<source>]`, an upload as `upload[<field>]` with `upload_title[<field>]`, kept by the source that takes
  uploads). Every action is a form that works without JavaScript, answering with a redirect and a notice (a refusal back
  as typed with why beside each field); with htmx it answers the part it belongs to (the list, the open section, the
  settings) and what else it changed as `<hx-partial>`s, a refusal as typed with 422 and a stale move with 409. Campaign
  pages answer to `MARKETING.VIEW` and `MARKETING.CAMPAIGN.MANAGE`, offer pages to `LandingProject::access()`. A project
  mounts the editor under its own address with `LandingEditor::routes($routes, $name, $path, $controller,
  $requirements, $make)` and answers each route with `LandingEditor::answer($request, EditorPlace, $action)`, its
  `EditorPlace` holding the page (or the offer to make one for), the routes' parameters, what the reader may do
  (`LandingAccess`), and the head's small line and back link. The campaign's page gains a Landing page card (status,
  address, visits and buttons pressed, Edit and View, or Make the landing page from a template), the figures Landing
  page visits and Buttons pressed, and "Its landing page, /c/{campaign}" for where its links lead while it is
  published. A call to action's form posts the part of the page as `target_section` and the path as `target_address`
  (`CtaKind` reads either, or `target`). The editor names `action-drag`, `action-hide`, `action-move-bottom`,
  `action-move-top`, `action-preview`, `action-publish`, `action-restore` and `action-view` (README).
- The plainest page frame (`@CattoPHP/layout.html.twig`) prints a page's head when the page gives `page_meta`.
- `Marketing\Offer` has `public` (whether visitors may see it at all), beside `available`.
- `Marketing\TrackingLinks::recordOpening()` gives what an opening recorded (`TrackedOpening`: the touch, the visitor,
  the publication, the cookie), so a page drawn in the same answer records its visit against them; `record()` still
  gives the cookie.
- `Marketing\Pictures` replaces `AdvertPictures` (and `Picture` replaces `AdvertPicture`): adverts and landing pages keep
  their pictures in `marketing/pictures/` under the module's files folder, still reading those kept in
  `marketing/adverts/`, and a picture is removed only when no advert, page, section, kept version or saved template names
  it.
- `Marketing\Campaigns::offerRenamed()`.
- `Ui\QrCode`: a QR code of any text, `svg($text, $label, $class = 'cl-qr-code')` for a page (named for a screen reader,
  no inline style) and `document($text, $title)` for a file of its own with the XML declaration.
  `Security\TwoStep\QrPicture` uses it; its output is unchanged.
- A link to copy, `@CattoPHP/marketing/copy-field.html.twig`: a read-only field to select without JavaScript and a Copy
  button `cattophp.js` shows where the browser can copy in one press (`data-cl-copy`, the field named by
  `aria-controls`).
- The stock frame takes `kicker` (a line above the title) and a `stock_badges` block (the head's facts as badges); a
  sortable heading takes `end` to sit over numbers set right.
- `Admin\SettingsPage::group()`, `save()`, `resetGroup()`, `reset()` and `inGroup()`, and the group's form as
  `@CattoPHP/admin/settings-group.html.twig`, so a module's own page saves its group as Administration > Settings does
  (a choice given `cards` is drawn as feature cards). Administration > Settings draws and saves the same as before.

- The Campaign Marketing Manager's journey and sales, what they rest on (step 3, its foundations): the schema step
  `202611141200-marketing-sales` (sales, one for each order a project reports or a connected site reports, unique by
  site and reference, with their lines and their money's movements, each kept once by its own key, and checks that
  refuse an amount below nothing, refunds and chargebacks over what was paid, and a paid sale without its moment;
  connected sites, removed ones kept so the records naming them keep their name, with their reports out and in;
  visitors given a reference, those already there too; touches a reference for each new one and an arrival's
  publication code; campaigns the connected site an arrival made one for; events the sale they belong to). The values
  the next parts build on: `Sales\SaleReport` and `Sales\SaleLine` (how a project describes an order; never refused as
  they are made, `problems()` says what is wrong, such as lines that do not add up to what is to be paid), `Sales\Sale`
  (with what it is shown as: waiting for payment, paid, partly refunded, refunded, charged back, cancelled),
  `Sales\SaleMovement`, `Sales\SaleFilter`, `Sales\ReportedSale` (a connected site's report about a sale, its body
  written and read), `Sales\SaleChanged`, `Sites\Site`, `Journey\JourneyRecorded` and `Journey\EventInput`.
  `Visitors::byReference()`, `ofAccount()`, `seen()` and `anonymise()` (one person's visitors); `Touches::byReference()`,
  `byId()` and `forVisitors()`; `Campaigns::makeForArrival()`; `Events::KINDS` with the journey's and a sale's kinds,
  `byKey()`, `latest()`, `forPerson()`, `forSale()` and `anonymiseVisitors()`. Four settings: `MARKETING_PROMOTION_COUNTS`
  (whether a campaign's promotion code gets it a sale only when the customer followed no link, the starting value, or
  always), `MARKETING_SITE_TOLERANCE` (5m), `MARKETING_SITE_RETRY_LONGEST` (1h) and `MARKETING_SITE_REPORTS_KEPT` (30d);
  `MARKETING_SALE_COUNTS_WITHIN`'s help says a change counts for sales paid from then on. Configuration
  `cattophp.marketing.returns` (true) and `returns_excluded` (the starts of paths). The activity log's words for
  `marketing.site.*`, `marketing.visitor.forgotten` and `marketing.publication.tied` and `.untied`, and its links to a
  connected site (Marketing > Settings, Connected sites) and a visitor (their journey's page). Forgetting an account
  unnames the connected sites it added.
- The Campaign Marketing Manager's visitor journey (step 3). `Marketing\Journey` takes what a visitor does from the
  project: `viewed($offer)`, `favourited($offer, $on)`, `basketChanged($offer, $added, $quantity)`, `taken($offer)`,
  `subscribed($listLabel)`, `carry()` (the visitor's reference to keep with a pending registration), `registered($account,
  $carried)` and `signedIn($account)`, each with an optional request (the current one otherwise) and account (the
  signed-in reader's otherwise), so a service with no request of its own can call it; each returns nothing and never
  throws. Only a followed reader's actions are recorded (`Journey\Readers` and `Journey\Reader`, worked out once for each
  request: a person, not asking not to be tracked, whose cookie names a visitor or whose account a visitor is tied to),
  each with their current campaign (the latest counted click of their visitors within `MARKETING_SALE_COUNTS_WITHIN`),
  and announced as a `Journey\JourneyRecorded`. A view, a favourite's state and a sign-in are not recorded again within
  `MARKETING_SAME_VISIT`; a free offer, each list and a registration once (by their keys). A registration ties to the
  account both the visitor carried from the browser where the form was filled in and this browser's, each only while
  it has none, and is recorded only when one of them is the account's. `Journey\VisitorReturns` notices a followed
  visitor coming back (`came_back`, from where) on the pages they open after a quiet visit, and moves their last seen
  on; it is on while `cattophp.marketing.returns` is true, and never under the tracking links, `/marketing`,
  `/webhooks/` or `returns_excluded`. `Journey\PersonJourneys::of($visitor)` reads one person's journey
  (`Journey\PersonJourney`): all their visitors together, their clicks, events and money movements in time order as
  `Journey\JourneyLine`s with their words and badges (First touch and Last touch from each paid sale's fixed
  attribution, Registration, Failed, Sale), their figures (visits, campaigns, orders, paid), their state (Paying
  customer, Order waiting for payment, Registered, Not registered), devices ("A phone, then a computer") and countries;
  `ofAccount($account)` too. `Marketing\Events::byId()`.
- A mailing message counted for a campaign (only with mailing on as well). `Marketing\Mail\MailingPublications`:
  `tie($message, $campaignCode, $place, $actor)` makes or changes the message's publication (channel email, or other
  while email is retired; the next `em<N>`; its place the message's lists by name unless one is given; published the
  day the message started sending), moving it from another campaign; `untie($message)` deletes it while nothing has
  opened its links and retires it otherwise (tying again puts it back); `forMessage()` and `refresh()`. Each tie and
  untie is in the campaign's history (`marketing.publication.tied`, `.untied`). `Marketing\Mail\MailedLinks`:
  `followed($token, $url, $request)`, called from the project's click link after `Mailing\CopyLinks::clicked()`, records
  a link to a page on this site as the campaign's touch (source email, the link's own `utm_` values, the link's address
  as where it led; no link in the email is rewritten), ties the copy's person to the visitor and gives the cookie for
  the redirect; `opened($token)`, from the opening picture, records `email_opened` once for each copy, naming its
  account and no visitor. A link elsewhere, a message counted for no campaign, a copy without an account, and what the
  mail project's rules say is not tracked record nothing. The unsubscribe link, the web copy and Forward to a friend are
  untouched and call neither.
- The Campaign Marketing Manager's sales (step 3): every order the project reports, whether or not a campaign brought
  it, and which campaign gets it. `Marketing\Sales\Sales`: `placed($report, $request)` (waiting, with the placing
  browser's visitor and country when it is followed, and a provisional first and last touch), `paid($report, $at,
  $how)` (writes the sale if its placing was lost, fixes its attribution counted back from the payment, adds the paid
  movement), `failed($reference, $attempt, $why, $at)`, `cancelled($reference, $at, $why)` (from waiting only; an order
  placed again is waiting again), `refunded($reference, $key, $lines, $at, $how)` and `chargedBack($reference, $key,
  $amount, $at, $lines, $how)` (without lines, shared over what each line has left by largest remainder), each refusing
  more than was paid line by line. Every call is idempotent: by the sale's reference, the movement's key and the
  journey event's key (`order_created:<ref>`, `paid:<ref>`, `payment_failed:<ref>:<attempt>`,
  `order_cancelled:<ref>:<n>`, `refunded:<ref>:<key>`, `charged_back:<ref>:<key>`), so a project may report again what it
  is not sure was kept (`has()`, `hasMovement()`). A report with problems is refused with the reason in the log; each
  write runs in its own savepoint, so a fault is rolled back, logged and gives null while the project's own work is
  committed; `Sales\SaleChanged` is announced inside it. The journey's events of a sale are kept only for a person the
  module follows. `reported($site, $report)` keeps a connected site's sale for this site's click (by site), each report
  once. Reads: `get()`, `byId()`, `list()` (sorted, paged, with lines), `count()`, `stateCounts()`, `totals()`
  (`Sales\SaleTotals`: gross, discounts, wallet credit, refunds, chargebacks, net, paying customers, average, waiting
  and cancelled; other currencies left out with a note), `offers()` (`Sales\OfferSales`: each offer's orders, units and
  money, a bundle never its offers), `latest()`, `forCampaign()`, `forAccounts()`, `forPerson()` and `currency()`, all
  narrowed by a `Sales\SaleFilter` and counted for the first or last touch as `MARKETING_SALE_COUNTS_FOR` says, so no
  sale counts for two campaigns. `Sales\SaleAttribution::for()` (`Sales\Attribution`): the buyer's visitors' and the
  placing browser's counted clicks within `MARKETING_SALE_COUNTS_WITHIN`, the first and the last; a campaign's
  promotion code counting as `MARKETING_PROMOTION_COUNTS` says (`no_link`: only with no counted link; `always`: over
  another campaign's link), the sale keeping both the links' touches and the promotion's campaign; of several carrying
  one code, the one running, else the one that started most recently, never a draft. `forgetAccount()` and
  `forgetAccountsOfAnonymousVisitors()`; forgetting an account (`Account\Records\MarketingRecords`) takes it off its
  sales, which stay with their money.
- One visitor's Paid figure says how the sale was paid as a sentence reads it, keeping an abbreviation's capitals ("By EFT",
  "By card"): `Marketing\Pages\MarketingPages::inSentence()`.
- A badge in a stacked table's cell (a table drawn with `stack: true`, on a narrow screen) wraps like the rest of the
  value, so a long one ("Sold through it, not promoted") no longer runs past its card and makes the page scroll sideways.
- The Marketing menu's Visitors and Sales name their own icons, `nav-visitors` and `nav-sales`; a project whose sprite
  lacks them draws the section's, as before.
- A forgotten customer is still one paying customer: forgetting an account (`Sales::forgetAccount()`,
  `forgetAccountsOfAnonymousVisitors()`, `Account\Records\MarketingRecords`, all through `Sales::unname()`) gives that
  person's sales one random mark together in place of the account, made at that moment and used only by
  `Sales::totals()` to count them once; it names nobody and leads to no account. Before, a forgotten person with two
  paid sales counted as two paying customers. Schema step `202611141500-marketing-forgotten-customers` (the sales'
  `forgotten_customer`); a project that applies CattoPHP's steps through its own migrations needs one that applies it.
- Connected sites (step 3): one campaign across two sites, the one that sends it seeing the registrations and sales it
  brought on the one that sells. `Marketing\Sites\Sites` keeps them (add with a name, an `https://` address and a shared
  secret, stored encrypted by `Security\SecretCipher`; change; a new secret, the previous one kept and accepted beside it
  until a message from the other site checks with the new one; remove, refused while reports wait unless they are
  dropped, kept so its records keep its name, connected again under its old key; `sign()` and `verify()` with
  `Webhooks\WebhookSignature` within `MARKETING_SITE_TOLERANCE`, a header with more than one time refused). A tracking
  link whose destination is on a connected site forwards a person's click with `cattophp_from` (base64url JSON: this
  site's address, the campaign's code and name, the publication's code and channel, the click's reference) and
  `cattophp_sig` (`Sites\SiteForward`); a preview, a bot, a site whose secret cannot be read and an opening not recorded
  forward plainly. `Sites\ArrivalListener` (kernel.request, before the router) checks an arrival, finds or makes its
  campaign (`Campaigns::makeForArrival()`), records the touch once for its click through `TrackingLinks::recordOpening()`
  (which takes the other site, the click's reference and the publication's code there), gives the cookie, and always
  answers 302 to the address without the two values; a refused one records nothing and only the site's card says why.
  `Sites\SiteReports` queues a report to the site a reader's current click came from on a registration or a
  subscription (`JourneyRecorded`), and on each change to a sale whose first or last touch came from one (`SaleChanged`:
  `order_placed`, `order_cancelled`, `sale_paid`, `sale_refunded`, `sale_charged_back`), in the same transaction under a
  savepoint, once by its key; codes, amounts and lines only, never a person, an account or an IP address. The scheduled
  task `marketing_site_reports` (`Sites\MarketingSiteReportsTask`, every minute) sends each site's due reports, 50 to a
  signed POST, tries again after `MARKETING_SITE_RETRY_FIRST` (new, 1m to start, shortest 10s) and twice as long each
  time up to `MARKETING_SITE_RETRY_LONGEST`, names the problem (a signature not accepted, clocks too far apart from the
  answer's `Date`, a site not reached), gives up after `MARKETING_SITE_REPORTS_KEPT` and removes old reports.
  `Sites\ReportReceiver` and `Marketing\Pages\SiteReportsController` (routes in the new
  `config/routes/marketing_sites.php`: `POST /webhooks/marketing`, `GET /webhooks/marketing/check`) answer an unknown
  sender, a bad signature or a stale time with a bare 401, skip a report received before, keep one naming an unknown
  click, record an event for the click's visitor and campaign and hand a sale to `Sales\Sales::reported()`. Marketing >
  Settings gains the Connected sites card (`#connected-sites`; `templates/marketing/settings-sites.html.twig`): each
  site's state in words (Connected, Not connected with why, Not checked yet), the secret as asterisks, the clocks, its
  reports and the last refusal with its reason; Connect a site, Make a secret (shown once, to copy), Check the
  connection, Change its name or address, Change the shared secret, Send them again and Drop connection (routes under
  `/marketing/settings/sites`). Reports need `symfony/http-client`; without it nothing is sent and the card says so.
  `Touches::byOrigin()`.
- The Campaign Marketing Manager's Visitors and Sales pages (step 3; `Marketing\Pages\VisitorsController`,
  `SalesController`, routes in `config/routes/marketing.php`, both in the Marketing menu between Landing pages and
  Settings). **Visitors** (`marketing-visitors`, `/marketing/visitors`, needs `MARKETING.PEOPLE.VIEW`): one row for each
  person with all their visitors together and one for each visitor not known yet (`Pages\VisitorList`), with their
  state, visits, campaigns, country, devices and first and last seen; the period they were last seen in, their state as
  feature cards, a campaign, a country and a search by name, email address, account reference
  (`MarketingProject::accountsMatching()`) or a visitor's reference; totals of visitors, known people and paying
  customers; `/marketing/visitors.csv`. **One visitor** (`marketing-visitor`, `/marketing/visitors/{reference}`): their
  journey oldest first (`Journey\PersonJourneys`) with `/journey.csv`, which campaign gets each paid sale, who they are
  (no IP address ever shown), the other visitors, and Forget this visitor's journey (confirmed; also needs
  `MARKETING.CAMPAIGN.MANAGE`; every visitor of the person made anonymous, their events and sales no longer naming them,
  the clicks and money kept; logged as `marketing.visitor.forgotten`). **Sales** (`marketing-sales`, `/marketing/sales`,
  `MARKETING.VIEW`; a customer named only with `MARKETING.PEOPLE.VIEW`, else "A customer"): every sale with or without a
  campaign, with the period, its state (paid to start), "A sale counts for" (first or last touch, from the setting) and,
  with a connected site, Where sold as feature cards, the campaign (any, none or one), publication, channel, offer and
  country, a search by order reference, the totals of the same filter (`Sales::totals()`: sales, paying customers, gross,
  discounts, wallet credit, refunds, chargebacks, net and the average order, what waits for payment apart, a sale in
  another currency left out and named), and two downloads, `/marketing/sales.csv` (the rows and totals shown) and
  `/marketing/sales-lines.csv` (their lines). **One sale** (`marketing-sale`, `/marketing/sales/{reference}`, a connected
  site's at `/marketing/sales/{site}/{reference}`): its facts, lines, money as it moved, both touches and which counts now,
  and what was reported of it to connected sites, with Open the order and Open the customer's journey. A campaign's page
  gains Registrations, Paying customers, Net revenue and Back for every R1, each publication's paid sales and net
  revenue, each advert's net revenue and clicks, each offer's views, favourites, units sold and net revenue (and the
  offers sold through it it does not promote), Customers and their orders with See all its sales, and the same "A sale
  counts for" cards; a publication made from a mailing message says so, linked by `MarketingProject::publicationSource()`.
  The overview gains Latest sales (paid since the start of the day `MARKETING_LATEST_SALES` ago, ten, with All sales).
  `Http\StockPages::PAGES` names the four pages; they draw `action-view` and `action-view-all` too.

**On a project's servers:** to use connected sites, import `@CattoPHPBundle/config/routes/marketing_sites.php`, let
everyone reach `^/webhooks/` in the access control, keep the scheduler on, and have `symfony/http-client`; then each
site's administrator connects the other in Marketing > Settings with the same secret.

**Changed**

- A sign-in now lasts `AUTH_SESSION_TTL` without a visit, counted from the person's last visit, not from signing in:
  coming back within it keeps them signed in, and they are signed out once they have stayed away that long; closing the
  browser no longer signs them out, and there is no overall limit. Each visit that counts moves three things on
  together (`SignIn\SignInLength`, at the response): the sign-in's end in the session (`SignInFlow::LAST_VISIT` and
  `SIGNED_IN_UNTIL`; `signedIn()` takes the end, `visited()` moves it, `endsAt()` reads it), the stored session's
  lifetime and the session cookie's expiry. What counts is decided in one place, `SignInLength::countsAsVisit()`: every
  request the signed-in person's browser makes, a page's own refresh included, so a page left open that refreshes itself
  keeps them signed in while it stays open. A shorter setting applies at once, from the last visit; a longer one from
  the next visit. The end is moved only once it would move by a hundredth of the length or more, at most an hour
  (`SignInLength::moveEvery()`), so most requests write nothing and send no cookie: a page refreshing every 15 seconds
  writes once an hour with a long sign-in. A sign-in from before the update counts from signing in until its next visit.
  With `sign_in.session_lifetime`, each request's session storage is given the length as `gc_maxlifetime` and no
  cookie lifetime (decorating `session.storage.factory`, over `framework.yaml`'s): until someone signs in, the session
  cookie ends when the browser is closed; at signing in it is sent again lasting the sign-in's length; signing out clears
  it. The cookie keeps the flags Symfony gives it (`SignIn\SessionCookieOptionsPass` hands it framework.session's options). `SignInController` takes
  `SignInLength` as its last argument. `AUTH_SESSION_TTL`'s help says "after this long without a visit".
- With `sign_in.sessions`, a kept sign-in's last seen and runs out move with each visit that counts
  (`SignInSessions::visited()`), and `holds()` writes nothing (`SEEN_EVERY` is no longer used); the Sign-in and security
  page's "Runs out" is when it would end if the person stayed away, a shorter setting brought in at once
  (`SignInSessions::endsAt()`), and a sign-in past that is no longer listed or held.
- The Settings page (`Admin\SettingsController`, `SettingsPage`, `admin/settings.html.twig`,
  `admin/settings-group.html.twig`) draws fixed settings, hides hidden ones, adds the project's facts and says when saved
  settings could not be read; a Reset that is refused says so rather than failing. `SettingsPage` takes the facts
  providers as its last argument and gains `shows()` and `facts()`.
- `Marketing\TouchAgent` reads the email link scanners and mail apps' fetchers that name themselves (a mail server's
  link scanner, Microsoft Office's link check, a mail app's picture fetcher) as bots, however much their description
  reads as a browser's, so their openings of a message's links are never a click.

- The Campaign Marketing Manager's wiring is its own, `Marketing\MarketingWiring`, out of `CattoPHPBundle` and unchanged,
  with a file for each of its parts to add its services to (`Journey\JourneyWiring`, `Mail\MailWiring`,
  `Sales\SalesWiring`, `Sites\SitesWiring`, `Pages\PagesWiring`).
- `Marketing\MarketingProject` gains `organisation($reference)` (a buying company's name, reference and page),
  `accountsMatching($search, $limit)` (the project's own search of its people, as account ids, for finding a customer by
  name, email address or reference) and `publicationSource($type, $reference)` (the name and page of the project's
  record a publication was made from, such as a mailing message). A project's own implementation adds the three;
  `Marketing\NoProject` answers nothing.
- `Marketing\Visitors::identify()` ties a visitor to an account only while it has none, and says whether it did: a
  browser two people use keeps the first person it was tied to (it used to take the latest).
- `Marketing\Events::record()` takes a `Journey\EventInput`, which also names the event's sale; the arguments one by one
  are still taken. A repeated idempotency key changes nothing and leaves a caller's transaction usable (it used to be
  a refused insert, which ends a PostgreSQL transaction).
- Each new visitor and touch is given its reference without a refused insert on the rare clash, for the same reason.
- The guide's section on the optional modules is now its own document, `MODULES-v1.0.md`, beside the guide.

**On a project's servers:** take `cookie_lifetime` and `gc_maxlifetime` out of `framework.session` in `framework.yaml`
(the setting now wins; keep `cookie_secure` and `cookie_samesite`), and keep sessions in the project's own folder
(`handler_id: session.handler.native_file`, `save_path: '%kernel.project_dir%/var/sessions'`), not PHP's shared one,
which some systems clean by `php.ini`'s 24 minutes.
An installation with files and a Settings page needs nothing more: its saved settings appear in the storage folder at
the first save. No schema step, no new `.env` key.

**Fixed**

- A connected site's failed check reads as one sentence ("Example Shop is not connected: the other site did not accept
  this site's signature."), without a capital after the colon.
- The landing page editor's preview of the open section (`LandingViewBuilder::sectionPreview()`, a `LandingView` whose
  new `sectionOnly` is true) is drawn as a part of the editor's page: no preview notice, no page head unless the section
  is the hero, and the hero's headline a paragraph rather than a second `h1`. The Earlier versions table's actions take
  the row's whole width when it stacks on a phone, so Use this version again no longer stands out of it.
- A landing page's hero badges (`cl-landing-badges`) are as far apart, and as far above the headline, as the
  utilities they replaced drew them on Bootstrap 6 (`gap-3`, `mb-5`: 0.5rem and 1rem, not 1rem and 3rem), so a page's
  head is the height it was on a phone.
- `feedback.progress` drew its bar full whatever its value in a project whose own stylesheet did not size it: CattoPHP's
  stylesheet now gives `.cl-ui-progress-bar.cl-share` its width.
- A landing page's visit and an offer's button press (`Marketing\Journey::opened()`, `pressed()`) named the signed-in
  reader's account even when their browser asked not to be tracked, with `MARKETING_HONOUR_DO_NOT_TRACK` on: such a
  visit or press is now counted with its country and names no visitor and no account, as step 1's rule says.

**The guide** says how to use the three additions (5.1, 5.12, 6.2, 7.4), and no longer describes requiring `^0.3` or
the starter taking CattoPHP from a folder beside it, which stopped being true with 1.0.0.
It also corrects 5.2 (with files, what the Settings page saves is kept in the storage folder, no longer in `.env`
alone), documents settings held fixed, the Settings page's facts and a longest length of time (2.4, 5.3, 5.11), and
says how long a sign-in lasts without a visit, that every request is one, and what wins over `framework.yaml` (5.6, 5.8).

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
