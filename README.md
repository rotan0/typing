# Morse Typing Tutor

## Multilingual procedural drills

Open **Language drills** from practice or the lesson studio. This is a separate
native-script typing mode; the original Morse lessons, editor, sharing and
scoring remain unchanged. Choose a practice language independently of the UI
language, a keyboard layout, difficulty 1-5, and optional space-separated custom
words. Preferences and generated sessions belong only to the signed-in account.

- Eleven original starter word lists live in `vocabulary/*.json`, alongside
  localized drill labels and a small set of English meaning hints. These are
  beginner vocabulary drills, **not grammatical sentence generators or a
  comprehensive language course**. Custom words are learner-supplied, not
  automatically translated or certified as belonging to the target language.
- Each lesson is generated locally on the PHP server: ten lines of 4-8 words,
  sampled from the filtered vocabulary. Hundreds of distinct combinations are
  possible without AI or an external dictionary. There is no package download
  or API call during generation. Offline means running the downloaded PHP/MySQL
  tutor locally, not a browser-only site or service-worker cache.
- US QWERTY, French AZERTY, German QWERTZ, Arabic 101 and Hindi InScript have
  explicit cumulative character tiers in `vocabulary/layouts.json`. These are
  learning inventories, not physical keystroke detection or hardware remapping;
  shifted characters and combining marks are taught in later tiers. AZERTY's
  first tier deliberately adds `a` and `e` to the home row so it can form words.
  German noun capitalization naturally appears at later restricted tiers.
  **Unrestricted / IME** is explicit and is recommended for Russian, unsupported
  layouts, or any target-language letters absent from the selected layout.
  An incompatible choice fails visibly if fewer than three words fit; the
  engine never silently widens the allowed character set.
- Difficulty increases allowed word length (6/9/14/24/40 Unicode code points)
  and words per line (4/5/6/7/8), as well as the cumulative keyboard inventory.
  Words preserve native script and case. Spaces, case and combining marks count
  in exact, position-based scoring. WPM is normalized Unicode code points / 5 /
  elapsed minutes, not language-specific word segmentation or byte counts.
  Live accuracy measures the typed prefix; final accuracy also penalizes
  missing text and extra characters. Editing is allowed; this is final-text
  accuracy, not an uncorrected-keystroke statistic.
- IME composition is left intact and cannot be submitted mid-composition.
  The timer starts at the first input/composition event; live speed updates
  while the learner pauses. Every submitted line receives server-computed
  accuracy/WPM and is saved privately. On failure the input stays available for
  retry. Reload resumes after the last saved line, not unsent keystrokes.
- After ten lines, advancement requires **at least 95% aggregate accuracy**,
  weighted by character counts, with no minimum speed. The learner explicitly
  chooses the next level; the highest level offers repetition instead.
  Repetition emphasizes up to 20 missed target characters in alternating words,
  without bypassing the current layout or level. Difficulty can also be selected
  directly when starting a lesson; this is an adaptive tutor, not an exam lock.
- The latest session and ten recent summaries are shown; all saved sessions
  remain in the account database. Multiple tabs cannot count the same line
  twice or modify another learner's session. Durations come from the browser;
  results are learning feedback, not independently certified test scores.

**Optional AI coach:** the coaching link prepares an editable question in the
existing private studio with language, layout, progress, a sample line and weak
characters. Opening it does not send an AI request or create a conversation.
Select/create a conversation, select a model, review the question and tick the
existing send-consent checkbox before asking. The assistant explains vocabulary
and typing technique; it does not control progression or inject generated text
into drills. Existing model limits and private conversation persistence apply.
Morse lesson drafts still accept only Morse-compatible A-Z/digits/spaces.

Personal ZIPs now include vocabulary, preferences, sessions and per-line results.
Restore revalidates vocabulary/key restrictions and recomputes scores. Legacy
version-1 profiles without drills still import; live gateway settings and other
users' data remain excluded. The existing 4 MiB profile limit applies, with at
most 1000 drill sessions per restore; oversized exports fail explicitly rather
than producing an unrestorable backup.

### Upgrade an existing hosted tutor

1. Back up the database and files, then upload the updated tutor sources,
   including `.htaccess`, `vocabulary`, CSS/JS, and the bundled GIF logo. Preserve
   `config/database.local.php`, `config/installed.lock`, `config/assistant.local.php`
   and existing local data. `.htaccess` now permits GIF assets and routes the
   drill page/API while keeping source and vocabulary folders private.
2. Run the protected installer's **Upgrade existing account/lesson database**
   action to add `drill_preferences` and `drill_sessions`. For remote access,
   recreate the setup-token hash file as described under shared hosting below,
   authorize the installer and delete that key after upgrading. Do not remove
   the installation lock or reinstall an existing database.
   CLI administrators can instead run `php scripts/migrate.php`.
3. For multilingual AI coaching, replace only the VPS gateway's `server.cjs`
   with this release's version and restart `typing-gateway`. This updates its
   server-owned tutoring prompt; preserve the approved models and provider keys.
   No gateway change is needed for offline generation or drill scoring.
4. Hard-refresh the tutor, open `drills.php`, complete a line, reload, and confirm
   it resumes. Test personal export/restore before relying on a download backup.

Validation:

```sh
php tests/drills.php
node --test tests/drills.test.cjs tests/app.test.cjs tests/studio.test.cjs tests/gateway.test.cjs tests/translate.test.js
php tests/smoke.php
php tests/integration.php
```

The integration test requires local MySQL and creates/removes isolated test
databases. `TEST_APACHE_ROOT` optionally exercises real Apache `/typing` routes.

## Appearance and typing focus

The tutor, studio, account and setup pages share a midnight theme with local
monospace fonts and a bundled Equal Page logo (no remote font or logo requests).
Practice shows muted correct/incorrect character feedback and a smooth guide
caret that follows the input selection; the normal textarea remains accessible
for typing, editing and pasting. Caret and interface transitions are disabled
when reduced motion is requested. The layout supports narrow screens and right-to-left UI languages;
practice text stays left-to-right. Submitted accuracy and elapsed milliseconds
continue to come from the existing results API.

For an existing hosted installation, upload the updated source files and
`assets/logo.gif`, preserving live configuration and user data, then hard-refresh
the browser. No database migration or gateway change is required for the theme.

## Windows setup

1. Double-click `install.bat`. The Windows wizard detects an existing XAMPP
   installation (including PHP and MySQL). You can specify a custom XAMPP folder.
   A PHP installation on PATH with an already-running database on port 3306 is
   also detected.
2. If missing, the wizard automatically downloads the official Windows x64
   XAMPP 8.2.12 installer over HTTPS, verifies Apache Friends' published SHA-1
   checksum, and opens its native installation wizard. Approve the administrator
   prompt if requested, keep **PHP and MySQL** selected, and finish installation.
   Downloads are cached only when their checksum matches. A failed download,
   checksum mismatch, cancellation, or failed install is reported and can be
   retried. Internet access is needed only for missing runtimes and Google Translate.
3. Click **Finish**. MySQL is started on localhost if port 3306 is not already in
   use. The PHP server is started on `http://127.0.0.1:8080`, exposing only
   `public` and the bundled CSS/JS assets. The browser wizard guides you through
   requirements, database credentials, and installation.
4. For a fresh local XAMPP installation the default database login is `root`
   with an empty password. Use your existing credentials for an existing server.
   The database user needs CREATE DATABASE, CREATE TABLE, SELECT, INSERT and UPDATE
   permissions (CREATE DATABASE is unnecessary for a pre-created shared-host database).
   The wizard creates missing tables and five starter lessons.
   Existing lesson IDs and progress are preserved when lessons are rebuilt.

Use `start.bat` on subsequent runs. Close the PHP server console to stop the
website; MySQL stays running for reuse. Stop it using the XAMPP control panel
when no other applications need it. Subsequent launches respect the saved database
host and port; remote servers must already be running. If port 8080 is occupied, use:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -STA -File .\scripts\setup.ps1 -LaunchOnly -Port 8081
```

The wizard writes an application-specific PHP configuration and runtime
detection cache to `.runtime`; it does not modify the shared XAMPP `php.ini`.
Database settings are stored in `config/database.local.php`, outside the web root,
and successful setup creates `config/installed.lock`. Both are Git-ignored.
Local setup and maintenance use CSRF-protected forms; remote setup requires a
separate site-owner setup token as described below. To deliberately reconfigure, back up your database, stop the
app, and remove **only** `config/installed.lock`, then reopen the installer.

Accounts now use registration/login and password hashing; automatic demo-user
login has been removed. An old `demo` row with a plain-text placeholder cannot
authenticate. Existing unowned lessons remain shared starter content on upgrade.
The pinned XAMPP bundle is an older upstream development release; do not expose it
to a network. Use maintained PHP/MySQL releases, HTTPS, database backups, and
host-level request/upload limits for public deployment. The official bundle and checksum are published on
[Apache Friends](https://www.apachefriends.org/download.html).

## Accounts, personal lessons and design notes

After setup, register an account. Usernames contain 3-64 ASCII letters, digits or
underscores; passwords are 12-72 bytes. Password hashes never appear in personal
exports. Login/registration are limited to 20 validly-shaped attempts per IP per
15-minute window, stored in the database. There is no password recovery workflow.
Sessions use HttpOnly/SameSite cookies, HTTPS-only cookies on HTTPS, and separate
installation-specific names. Logout invalidates the current session identity.

Open **Personal lesson studio** to create/edit your own lessons and save private
design conversations/notes. Morse practice supports A-Z, digits and spaces.
Notes and lesson content persist in MySQL across sessions. Conversations are
always private. Stored imported assistant messages are treated as untrusted
plain text, not executable HTML or instructions.

Lessons are private by default. The owner can explicitly publish/unpublish a
lesson; other registered users can list and practice published lessons but cannot
edit them. Publishing never exposes the author's conversations or progress.
Unpublishing prevents future access; it cannot recall copies someone already made.

### Free Chat integration status

The hosted Free Chat model-list endpoints returned HTTP 401 during inspection.
The supplied Free Chat source confirmed those routes require login. The tutor
now supports a separate, approved-model gateway for **existing local Ollama and
hosted-provider models**, without changing Free Chat's login protections or
requiring users to supply provider credentials.

The gateway must first be installed by the VPS administrator. See the separate
assistant-install package's `gateway/README.md` for exact VPS/shared-host steps.
The regular personal/source export deliberately excludes gateway files and live
assistant configuration. Until `config/assistant.local.php` is installed on the
canonical HTTPS hosted tutor, the ask button remains disabled and the endpoint
returns an explicit unavailable status; no private messages are sent.

On the configured hosted tutor, choose an approved model, select a private design
conversation, type a relevant question and explicitly consent to sending its
recent messages to the VPS/selected provider. The exchange is saved only after a
successful reply. You can copy suggested practice text into a new lesson draft,
review it, then save/publish yourself. Assistant replies are untrusted plain text,
not commands, code or automatic permission to change lessons.

There are account/IP quotas, bounded context/output, a per-account inference lock,
gateway concurrency limits and explicit failures. Public hosted-provider access
can incur costs: administrator-approved models and provider budgets are required.
There is no fallback that silently charges a different provider.
No Free Chat state file, protected session or authentication bypass is used.
Downloaded tutors do not access hosted models, but retain saved assistant replies.

## Personal source download, restore and Git

Use **Download my tutor ZIP** in the studio. The archive contains:

- An explicit allowlist of runnable application source, bundled languages,
  installer, scripts and tests.
- `data/profile.json`: your own lessons, private conversations/messages, practice
  progress and attempts, plus starter lessons needed for restore.
- `data/published-lessons.json`: only your explicitly published lesson text,
  descriptions and difficulty, suitable for deliberate Git sharing.

There are no account records/password hashes, host database credentials, setup
tokens, installed lock, runtime downloads/cache, Free Chat configuration, or other
authors' lessons/private data. Practice records referring to another author's
lessons are excluded too, to avoid copying their content; the profile records
their excluded count. Profiles must fit the 4 MiB restore limit, with at most
1000 lessons, 500 conversations, 2000 messages per conversation, 2000 progress
records and 10000 attempts. Oversize exports fail explicitly rather than
silently truncating a backup.

**Keep the complete ZIP private**: it contains your conversation history. ZIPs,
private profile JSON, local credentials, setup keys and runtime state are
Git-ignored. Do not force-add them. If you decide to publish a repository, review
`data/published-lessons.json` first; unpublish a lesson and download a fresh ZIP
if its text should not be in that file.

To restore a download:

1. Extract the ZIP and run `install.bat` to set up a new local database.
2. Register a new local account with a new password (old account credentials are
   deliberately not copied).
3. In the studio, click **Restore bundled private profile (local only)**.
   Alternatively select `data/profile.json` with **Restore profile JSON**.
4. Your lessons, conversations and progress are imported into that account in one
   transaction, with new local IDs. Re-importing the same profile is rejected.
   Imported personal lessons are private until explicitly published on that host.

The already-published lesson collection is installed as shared starter content
on a fresh setup. Thus a Git clone can keep your intentionally published lessons
without copying your account or private chats. Do not upload `data/profile.json`
when hosting the project; only the intended owner should possess that file.
For an entirely private local copy, remove `data/published-lessons.json` **before**
initial setup. Otherwise those previously published texts remain shared starter
copies, separate from the private lessons restored into your new account.

## Hosting at /typing and database upgrades

Apache with `mod_rewrite`, `mod_dir` and `.htaccess` overrides is supported.
Upload the project contents, including the root `.htaccess`, into the host's
`typing` directory. This routes pages through `public`, serves only bundled
CSS/JS assets, disables directory listing, and denies HTTP access to `config`,
`data`, `scripts`, `sql`, tests, dotfiles, logs, archives and batch files.
Do not deploy the whole source tree to a host that ignores these access rules.
Other web servers need equivalent routing and deny rules, or an isolated
`public` document root with a separate CSS/JS asset mapping.

Use HTTPS. Verify `/typing/config/database.local.php` and
`/typing/data/profile.json` return 403/404 **before** using real data.
Links, fetches, form actions and cookies support both `/` and `/typing`.
The browser setup requires PHP 7.4+ with PDO MySQL, JSON, sessions and ZipArchive;
use a currently maintained PHP release on your host. On Windows, the prerequisite
wizard enables ZIP in its application-private PHP configuration automatically.

For remote initial setup, create a long random setup token locally and upload
**only its SHA-256 hex hash** as `config/setup.key`. Use a secret manager or a
local hash utility; do not commit either value or place the clear token in a URL.
Visit `/typing/installer.php` and enter the clear token in its authorization form.
Create your database in the shared-host control panel first, then use its supplied
host/name/user/password in the browser wizard. The wizard won't try to create an
already-existing database. Delete `config/setup.key` when done to lock remote setup.
Never delete live database settings or the install lock during a normal upload.

For an existing installation, back up the database and run:

```powershell
C:\xampp\php\php.exe .\scripts\migrate.php
```

If the shared host has no CLI, use a temporary setup token as above and the
**Upgrade existing account/lesson database** button in the installer; remove the
key again afterward. Migration is repeatable, adds ownership/account tables,
and preserves existing lessons and progress. There is no administrator account
created automatically. Maintenance remains local-only; use the CLI on a server.

To create a clean source-only deployment package (no personal data):

```powershell
C:\xampp\php\php.exe -d extension=zip .\scripts\package.php
```

This writes `typing-deploy.zip` and refuses to overwrite existing archives.
If ZIP is already enabled in PHP, omit `-d extension=zip`.
The script's allowlist excludes all live credentials, private data and local
runtime state. Deploy using your own configured upload connection; the current
session did **not** deploy to either public domain.

For the separate administrator-only VPS gateway + updated tutor install bundle:

```powershell
C:\xampp\php\php.exe -d extension=zip .\scripts\package_assistant.php
```

This produces `typing-assistant-connect.zip`. Its `gateway/README.md` contains
the step-by-step install process. Unlike personal exports, this administrator
package intentionally contains the gateway source and sample configuration,
but never local/live credentials or Free Chat archives.

## Languages and Google Translate backup

English plus Spanish, French, German, Italian, Portuguese, Dutch, Polish, Russian,
Arabic, and Hindi are bundled under `lang`. The native language selector is
available on the tutor, maintenance page, and browser installer, and remembers
the selection in the session. Arabic uses right-to-left layout; Morse input and
the keyboard stay left-to-right and are excluded from machine translation.
Missing translation keys fall back to English and are logged.

The Google Translate backup panel and direct Google Translate link are always
visible on these pages. Its optional website widget loads automatically from
Google when online. This is the only external application script; there are no
Python, Composer, npm or CDN UI dependencies. Google may restrict the widget
for a website, and it cannot be guaranteed on localhost or with content blockers.
If offline, blocked, or unavailable, a visible status explains how to use bundled
languages or paste text into Google Translate. Native catalogs continue working.
The Windows wizard and browser wizard instructions are in English; catalog-based
headings and controls use the selected locale.

Google Translate is an online service: loading it sends requests to Google and
using translation may send page text to Google. No translation API key is needed.
Google's own widget may load additional Google resources.

## Lessons and maintenance

The maintenance page rebuilds the five default lessons using built-in PHP/PDO,
without deleting custom lessons or progress. The previous Python generator has
been replaced; no Python runtime, connector package, or Python database config
is needed. To rebuild from the command line after setup:

```powershell
C:\xampp\php\php.exe .\scripts\generate_lessons.php
```

Use `-c .\.runtime\php.ini` if your shared PHP configuration lacks PDO MySQL.
`clean_repo.bat` only removes the cached installer and push log; it preserves local
configuration, database backups and setup state. `git_push_progress.bat` runs
synchronously, respects the current upstream branch, never force-pushes, and
reports failures. Initialize a Git repository and configure an upstream before use.

## Validation

Run the dependency-free checks (no database required):

```powershell
C:\xampp\php\php.exe .\tests\smoke.php
node .\tests\translate.test.js
node --test .\tests\studio.test.cjs
node --test .\tests\gateway.test.cjs
```

With an existing local XAMPP installation, the Windows runtime detection and
private PHP configuration can be tested without installing or starting software:

```powershell
powershell.exe -NoProfile -File .\tests\setup.test.ps1
```

With a running MySQL server and the ZIP extension, the integration checks test
the browser wizard, schema migration, accounts, multiuser isolation, explicit
publishing, private ZIP contents, transactional restore, lesson submission and
maintenance. They also extract the downloaded source, install it into another
temporary database, register a fresh account, and restore the bundled profile.
Use `php -d extension=zip .\tests\integration.php` when ZIP is not already enabled.
Tests use temporary application copies and uniquely named test databases.
In the source-only/personal tutor download, gateway tests/source are deliberately
not included; run gateway tests from the assistant install package instead.
Both are removed afterward; existing application settings/data are untouched.
It defaults to local root with an empty password; set `TEST_DB_HOST`,
`TEST_DB_PORT`, `TEST_DB_USER`, and `TEST_DB_PASSWORD` for another test server.
On Windows with XAMPP Apache, setting `TEST_APACHE_ROOT=C:\xampp\apache` runs the
same integration suite under a temporary isolated Apache instance at `/typing`
and verifies actual `.htaccess` routing/denials. It does not modify the installed
Apache configuration or restart any existing service.
